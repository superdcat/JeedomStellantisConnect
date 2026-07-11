# This file is part of Jeedom.
#
# Jeedom is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Jeedom is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Jeedom. If not, see <http://www.gnu.org/licenses/>.

# Démon Stellantis : transport MQTT GÉNÉRIQUE (UC11). Il ne connaît RIEN de PSA (ni topics, ni CID, ni
# payloads en dur) : le PHP (classe stellantis) lui pousse des actions (connect/subscribe/publish/
# set_token/disconnect) via le socket Jeedom, et le démon remonte les messages MQTT reçus à Jeedom via
# le callback jeedom_com. Toute la logique métier reste côté PHP.
#
# UC19 — Résilience de la connexion : la reconnexion automatique NATIVE de paho (connect_async() +
# loop_start()) est abandonnée au profit d'une machine à états (FSM) mono-thread pilotée depuis la
# boucle listen() : connect() synchrone + pompage manuel loop(timeout=0) à chaque tick, backoff
# exponentiel plafonné + jitter, et arrêt des tentatives après N échecs d'authentification consécutifs
# (état bloqué, cf. -tech UC19). Aucun nouveau thread, aucun verrou.

import logging
import sys
import os
import time
import random
import socket
import traceback
import signal
import json
import hmac
import argparse

import paho.mqtt.client as mqtt

from jeedom.jeedom import jeedom_socket, jeedom_utils, jeedom_com, JEEDOM_SOCKET_MESSAGE


# --- Clés sensibles à ne JAMAIS logguer en clair (critère d'acceptation UC11) ---
# 'apikey' : clé API Jeedom du plugin, portée par CHAQUE message reçu sur le socket local (read_socket
# logue le message complet via _redact en debug) — authentifie aussi bien le socket local que le
# callback HTTP vers Jeedom : une fuite en clair dans les logs équivaut à une fuite de secret (review UC19).
_SENSIBLE = ('password', 'access_token', 'token', 'apikey')


def _redact(obj):
    """Copie d'un dict/liste avec les valeurs sensibles masquées, pour un logging sûr."""
    if isinstance(obj, dict):
        return {k: ('***' if k in _SENSIBLE else _redact(v)) for k, v in obj.items()}
    if isinstance(obj, list):
        return [_redact(v) for v in obj]
    return obj


# --- FSM de reconnexion (UC19) : états publics de MqttBridge._state ---
STATE_IDLE = 'idle'              # pas de connexion souhaitée (avant 1er connect, ou après disconnect())
STATE_CONNECTING = 'connecting'  # connect() émis, en attente du CONNACK (ou d'un échec)
STATE_CONNECTED = 'connected'    # CONNACK rc=0 reçu
STATE_BACKOFF = 'backoff'        # attente avant nouvelle tentative
STATE_BLOCKED = 'blocked'        # N échecs d'auth consécutifs atteints : arrêt des tentatives (réarmement requis)

# Constantes de backoff/quotas (défauts du -tech UC19, non configurables au MVP de cette UC)
BACKOFF_BASE = 5      # s, plancher dur (jamais de reconnexion immédiate)
BACKOFF_FACTOR = 2
BACKOFF_MAX = 300     # s, plafond
BACKOFF_JITTER = 0.2  # ±20 %
AUTH_FAIL_MAX = 5     # N : échecs d'auth consécutifs avant passage en état bloqué
CONNECT_TIMEOUT = 10  # s : borne le handshake TCP (via client._connect_timeout, cf. _start_attempt)
CONNACK_TIMEOUT = 15  # s : borne de l'état CONNECTING (CONNACK jamais reçu → traité comme échec transitoire)

# Codes CONNACK MQTT 3.1.1 (paho on_connect rc) catégorisés pour la décision transitoire/auth :
# 0=ok, 1=version de protocole refusée, 2=identifiant client refusé, 3=serveur indisponible (transitoire),
# 4=mauvais identifiants, 5=non autorisé (4 et 5 = échec d'authentification).
_CONNACK_RC_TRANSITOIRE = 3
_CONNACK_RC_AUTH = (1, 2, 4, 5)


def calculer_delai_backoff(attempts):
    """Délai (s) avant la prochaine tentative de connexion, pour `attempts` échecs consécutifs
    (transitoires ET auth confondus, 1 = premier échec). Croissance exponentielle plafonnée + jitter,
    plancher dur BACKOFF_BASE (jamais de reconnexion immédiate même après un jitter négatif). Fonction
    pure, testable indépendamment de tout client MQTT (cf. -tech UC19 § Validation)."""
    n = max(int(attempts) - 1, 0)
    delai = min(BACKOFF_BASE * (BACKOFF_FACTOR ** n), BACKOFF_MAX)
    delai = delai * (1 + random.uniform(-BACKOFF_JITTER, BACKOFF_JITTER))
    return max(delai, BACKOFF_BASE)


def decider_categorie(last_connack_rc, was_connected):
    """Catégorise un on_disconnect (rc≠0) en 'transient' ou 'auth' selon le mapping figé dans le -tech
    UC19. `was_connected` : la tentative avait-elle atteint l'état CONNECTED avant cette déconnexion
    (perte réseau d'une session saine → transitoire, quel que soit le dernier CONNACK). `last_connack_rc` :
    dernier code CONNACK mémorisé pour la tentative en cours (None si aucun CONNACK reçu, ex. rc=7 côté
    on_disconnect — broker qui ferme la socket faute d'auth complète, traité comme auth dans ce contexte).
    Fonction pure, testable indépendamment de tout client MQTT."""
    if was_connected:
        return 'transient'
    if last_connack_rc == _CONNACK_RC_TRANSITOIRE:
        return 'transient'
    if last_connack_rc in _CONNACK_RC_AUTH or last_connack_rc is None:
        return 'auth'
    return 'transient'  # rc CONNACK non mappé : repli prudent (ne bloque pas sur un code inconnu)


class MqttBridge:
    """Client MQTT piloté par les actions reçues du PHP. État minimal : client courant, identifiants,
    topics à (ré)abonner après (re)connexion, et FSM de reconnexion (UC19, cf. constantes STATE_*
    ci-dessus) : compteurs d'échecs, échéance de backoff, dédup des notifications d'état public."""

    def __init__(self, jeedom_com):
        self._com = jeedom_com
        self._client = None
        self._host = None
        self._port = 8885
        self._username = None
        self._password = None
        self._topics = []
        # --- FSM de reconnexion (UC19) ---
        self._state = STATE_IDLE
        self._attempts = 0             # échecs consécutifs (transitoires + auth confondus) : croissance du backoff
        self._auth_fail = 0            # échecs d'auth consécutifs : seuil AUTH_FAIL_MAX → état bloqué
        self._next_attempt = None      # time.monotonic() de la prochaine tentative autorisée (état BACKOFF)
        self._connecting_since = None  # time.monotonic() de début d'attente du CONNACK (état CONNECTING)
        self._last_connack_rc = None   # rc mémorisé par on_connect, tranché par on_disconnect (anti-double-comptage)
        self._last_public_state = None  # dédup des notifications ('connected'/'retrying'/'blocked')

    # --- Callbacks PHP (remontées) ---
    def _notify(self, payload):
        # Remonte un événement à Jeedom via le callback. send_change_immediate (pas add_changes) : envoi
        # fiable par message, sans batch qui écraserait deux événements rapprochés de même clé.
        # Enveloppe {'stellantis_daemon': payload} → jeeStellantis.php déballe et dispatche.
        self._com.send_change_immediate({'stellantis_daemon': payload})

    # Notifie UNIQUEMENT si l'état public change (anti-spam UC19 : pas un événement par tentative
    # intermédiaire, seulement au changement d'état 'connected'/'retrying'/'blocked').
    def _set_public_state(self, public_state, event):
        if public_state == self._last_public_state:
            return
        self._last_public_state = public_state
        self._notify(event)

    # S'abonne à une liste de topics (ignore les vides), avec log et gestion d'erreur unifiés.
    def _subscribe_all(self, client, topics):
        for topic in topics:
            if not topic:
                continue
            try:
                client.subscribe(topic)
                logging.info('Abonnement au topic %s', topic)
            except Exception as e:
                logging.error('Erreur abonnement %s : %s', topic, e)

    # --- Callbacks paho (gardées par le client courant, risque C du -tech UC19 : ignore les callbacks
    # tardifs d'un client déjà remplacé par une nouvelle tentative, pour ne jamais écraser son état) ---
    def _on_connect(self, client, userdata, flags, rc):
        if client is not self._client:
            return
        rc = int(rc)
        if rc == 0:
            self._connecting_since = None
            self._attempts = 0
            self._auth_fail = 0
            self._state = STATE_CONNECTED
            logging.info('MQTT connecté (rc=%s)', rc)
            self._subscribe_all(client, self._topics)
            self._set_public_state('connected', {'type': 'connected', 'rc': 0})
            return
        # CONNACK refusé : mémorisé seulement. Le décompte revient à on_disconnect (anti-double-comptage,
        # risque A du -tech : un CONNACK refusé (rc 4/5) est toujours suivi de la fermeture de la socket
        # par le broker, donc on_connect ET on_disconnect se déclenchent pour la MÊME tentative).
        logging.info('MQTT CONNACK refusé (rc=%s)', rc)
        self._last_connack_rc = rc

    def _on_disconnect(self, client, userdata, rc):
        if client is not self._client:
            return
        rc = int(rc)
        if rc == 0:
            # Arrêt volontaire (disconnect()/_teardown_client() a déjà basculé self._client à None avant
            # d'appeler client.disconnect(), donc la garde ci-dessus a normalement déjà filtré ce cas) —
            # ligne défensive : pas de retry.
            return
        was_connected = (self._state == STATE_CONNECTED)
        categorie = decider_categorie(self._last_connack_rc, was_connected)
        logging.info('MQTT déconnecté (rc=%s, catégorie=%s)', rc, categorie)
        self._last_connack_rc = None
        self._connecting_since = None
        self._client = None
        self._handle_failed_attempt(categorie)

    def _on_message(self, client, userdata, msg):
        if client is not self._client:
            return
        try:
            texte = msg.payload.decode('utf-8', errors='replace')
        except Exception:
            texte = ''
        try:
            payload = json.loads(texte)
        except Exception:
            payload = texte
        logging.info('Message MQTT reçu sur %s', msg.topic)
        self._notify({'type': 'message', 'topic': msg.topic, 'payload': payload})
        # Token expiré côté broker → demander au PHP de rafraîchir puis set_token (boucle réactive UC11)
        if isinstance(payload, dict):
            code = str(payload.get('return_code', payload.get('process_code', '')))
            if code == '400':
                logging.info('Message MQTT avec code 400 (token expiré) → notification token_expired')
                self._notify({'type': 'token_expired'})

    # --- FSM de reconnexion (UC19) ---
    def _rearm(self):
        # Réarmement (nouvelle action connect/set_token, ou redémarrage du démon) : remet compteurs et
        # backoff à zéro, sort de l'état bloqué. La tentative elle-même est lancée juste après par
        # l'appelant via _start_attempt().
        self._attempts = 0
        self._auth_fail = 0
        self._next_attempt = None

    def _teardown_client(self):
        # Ferme proprement le client courant (best-effort) après avoir neutralisé la référence pour que
        # ses callbacks tardifs éventuels (déclenchés de façon réentrante par client.disconnect() lui-même)
        # soient ignorés par la garde `client is not self._client`.
        client = self._client
        self._client = None
        self._connecting_since = None
        if client is not None:
            try:
                client.disconnect()
            except Exception as e:
                logging.warning('Erreur à la fermeture du client MQTT précédent : %s', e)

    def _start_attempt(self):
        # Lance une tentative de connexion : client.connect() est synchrone pour la partie handshake
        # TCP+TLS (bornée ci-dessous, cf. CONNECT_TIMEOUT), mais NE bloque PAS jusqu'au CONNACK applicatif
        # MQTT — celui-ci est lu par le pompage manuel `tick()`/`loop(timeout=0)` (borné par CONNACK_TIMEOUT).
        if self._host is None:
            self._state = STATE_IDLE
            return
        self._last_connack_rc = None
        # reconnect_on_failure=False (paho >= 1.6) : neutralise un mécanisme d'auto-reconnexion INTERNE
        # à paho, distinct de connect_async()/loop_start() (déjà abandonnés) — sans cela, paho peut
        # silencieusement rappeler self.reconnect() en interne sur certains CONNACK refusés (rc=1/2) SANS
        # jamais invoquer on_connect, hors du contrôle de la FSM. Avec ce flag à False, ces cas remontent
        # normalement via on_disconnect (catégorisés 'auth' par décider_categorie, faute de CONNACK observé).
        client = mqtt.Client(clean_session=True, protocol=mqtt.MQTTv311, reconnect_on_failure=False)
        client.on_connect = self._on_connect
        client.on_disconnect = self._on_disconnect
        client.on_message = self._on_message
        try:
            client.tls_set_context()
        except Exception as e:
            # TLS obligatoire (port 8885) : on abandonne plutôt que de tenter une connexion non chiffrée
            logging.error('Erreur configuration TLS, connexion abandonnée : %s', e)
            self._notify({'type': 'error', 'reason': 'tls'})
            self._state = STATE_IDLE
            return
        if self._username is not None:
            client.username_pw_set(self._username, self._password)
        # Borne le handshake TCP (risque B.1 du -tech) : paho (1.6.1) n'expose PAS ce réglage via un
        # paramètre public de connect() — il utilise en interne `self._connect_timeout` (5.0s par défaut,
        # non documenté) pour `socket.create_connection(..., timeout=...)`. `socket.setdefaulttimeout()`
        # seul N'A AUCUN EFFET ici (constaté empiriquement : paho passe un timeout EXPLICITE, qui prime
        # toujours sur le défaut global) — on force donc directement cet attribut interne, seul levier
        # qui fonctionne réellement avec cette version épinglée de paho-mqtt.
        client._connect_timeout = CONNECT_TIMEOUT
        self._client = client
        self._state = STATE_CONNECTING
        self._connecting_since = time.monotonic()
        logging.info('Connexion MQTT à %s:%s (tentative)', self._host, self._port)
        # socket.setdefaulttimeout : filet de sécurité supplémentaire (résolution DNS notamment, qui peut
        # passer par le timeout global selon la plateforme) ; posé/restauré autour de l'appel synchrone,
        # mono-thread hors ce court instant. Les autres I/O du démon (callback HTTP jeedom_com) passent par
        # des timeouts explicites (requests(..., timeout=...)), donc non affectées par cette fenêtre.
        # ⚠️ Risque résiduel ASSUMÉ (limite de paho 1.6.1, épinglé — cf. -tech) : le handshake TLS
        # lui-même (`sock.do_handshake()`) est borné par `self._keepalive` (la valeur MQTT keepalive
        # passée à connect(), 60s ici), PAS par CONNECT_TIMEOUT — paho réutilise ce paramètre comme
        # timeout socket du handshake. On ne le réduit pas artificiellement (cela corromprait le
        # keepalive réellement négocié avec le broker). Un pair qui accepte le TCP mais ne répond jamais
        # au ClientHello TLS peut donc bloquer le tick jusqu'à ~60s (borné, pas infini) au lieu de 10s —
        # scénario très étroit (broker qui droppe après le SYN-ACK), sans nouveau thread ajouté (contrainte
        # d'architecture de cette UC). Écart assumé, détaillé dans 19-tech.md (§ Garde-fous FSM).
        ancien_timeout = socket.getdefaulttimeout()
        socket.setdefaulttimeout(CONNECT_TIMEOUT)
        try:
            client.connect(self._host, self._port, keepalive=60)
        except Exception as e:
            logging.error('Erreur de connexion MQTT à %s:%s : %s', self._host, self._port, e)
            self._client = None
            self._connecting_since = None
            self._handle_failed_attempt('transient')
        finally:
            socket.setdefaulttimeout(ancien_timeout)

    def _handle_failed_attempt(self, categorie):
        # Comptabilise UNE tentative échouée (jamais deux fois pour le même événement, cf. risque A) et
        # décide de la suite : BACKOFF (retry) ou BLOCKED (seuil d'échecs d'auth atteint).
        self._attempts += 1
        if categorie == 'auth':
            self._auth_fail += 1
            if self._auth_fail >= AUTH_FAIL_MAX:
                self._enter_blocked()
                return
        self._enter_backoff(categorie)

    def _enter_backoff(self, categorie):
        delai = calculer_delai_backoff(self._attempts)
        self._next_attempt = time.monotonic() + delai
        self._state = STATE_BACKOFF
        logging.info(
            'MQTT : nouvelle tentative dans %.1fs (tentative consécutive n°%d, catégorie=%s)',
            delai, self._attempts, categorie,
        )
        self._set_public_state('retrying', {'type': 'disconnected', 'category': categorie, 'retrying': True})

    def _enter_blocked(self):
        self._state = STATE_BLOCKED
        self._next_attempt = None
        logging.warning(
            'MQTT : %d échecs d\'authentification consécutifs → arrêt des tentatives (réarmement requis)',
            self._auth_fail,
        )
        self._set_public_state('blocked', {'type': 'auth_failed', 'blocked': True})

    def tick(self):
        """Pompe la boucle réseau paho (aucun thread : connect_async()/loop_start() abandonnés, UC19) et
        fait progresser la FSM (timeout CONNACK, expiration du backoff). Appelé à chaque itération de
        listen() — jamais bloquant (loop(timeout=0))."""
        if self._client is not None:
            try:
                self._client.loop(timeout=0)
            except Exception as e:
                logging.warning('Erreur de pompage de la boucle MQTT : %s', e)
        if self._state == STATE_CONNECTING and self._connecting_since is not None:
            if time.monotonic() - self._connecting_since >= CONNACK_TIMEOUT:
                # Risque B.2 du -tech : broker qui accepte le TCP/TLS mais ne répond jamais au CONNACK.
                # Sans cette borne, la FSM resterait indéfiniment en CONNECTING.
                logging.warning('MQTT : CONNACK non reçu après %ss, tentative abandonnée (traitée comme transitoire)', CONNACK_TIMEOUT)
                self._teardown_client()
                self._handle_failed_attempt('transient')
        elif self._state == STATE_BACKOFF:
            if self._next_attempt is not None and time.monotonic() >= self._next_attempt:
                self._start_attempt()

    # --- Actions PHP ---
    def connect(self, host, port, username, password):
        self._host = host
        self._port = int(port) if port else 8885
        self._username = username
        self._password = password
        self._teardown_client()
        self._rearm()
        self._start_attempt()

    def subscribe(self, topics):
        # Mémorise pour ré-abonnement en on_connect, et s'abonne tout de suite si déjà connecté
        for topic in topics:
            if topic and topic not in self._topics:
                self._topics.append(topic)
        if self._client is not None:
            self._subscribe_all(self._client, topics)

    def publish(self, topic, payload):
        if self._client is None:
            logging.warning('Publication ignorée : client MQTT non connecté')
            return
        try:
            self._client.publish(topic, json.dumps(payload))
            logging.info('Publication sur %s', topic)
        except Exception as e:
            logging.error('Erreur publication %s : %s', topic, e)

    def set_token(self, password):
        # Rotation du token OAuth2 (= mot de passe MQTT) : maj identifiants + reconnexion. Réarme
        # systématiquement la FSM (UC19) si un hôte est déjà connu, y compris depuis BACKOFF/BLOCKED — le
        # cron pousse ce set_token toutes les ~15 min (TTL du remote token), ce qui réarme implicitement
        # un état bloqué (cf. -tech UC19 § Réarmement).
        self._password = password
        if self._host is None:
            logging.info('set_token reçu mais pas de connexion configurée (sera pris en compte au prochain connect)')
            return
        logging.info('Rotation du token MQTT → reconnexion (réarmement de la FSM)')
        self._teardown_client()
        self._rearm()
        self._start_attempt()

    def disconnect(self):
        self._teardown_client()
        self._state = STATE_IDLE
        self._next_attempt = None
        self._last_public_state = None


def read_socket(bridge):
    if JEEDOM_SOCKET_MESSAGE.empty():
        return
    logging.debug('Message reçu sur le socket JEEDOM_SOCKET_MESSAGE')
    brut = JEEDOM_SOCKET_MESSAGE.get()
    try:
        message = json.loads(jeedom_utils.stripped(brut))
        if not isinstance(message, dict):
            raise ValueError('message socket non conforme (objet JSON attendu)')
    except Exception as e:
        # Un message malformé/illisible ne doit JAMAIS faire remonter d'exception jusqu'au try/except
        # racine du module (shutdown() → arrêt du démon entier = DoS local trivial, review UC19) : on
        # isole, on logue et on ignore ce message. Ne JAMAIS logguer le contenu brut (peut porter un
        # secret partiel : apikey/password/token) — seule la taille est tracée, comme déjà pratiqué dans
        # jeedom_socket_handler.handle().
        logging.warning('Message socket illisible/malformé ignoré (%d octets) : %s', len(brut) if brut else 0, e)
        return
    # Comparaison à temps constant (défense en profondeur, même si le socket est en loopback)
    if not hmac.compare_digest(str(message.get('apikey', '')), str(_apikey)):
        logging.error('Apikey invalide reçue sur le socket')
        return
    action = message.get('action', '')
    logging.debug('Action socket : %s (payload masqué : %s)', action, _redact(message))
    try:
        if action == 'connect':
            bridge.connect(message.get('host'), message.get('port'), message.get('username'), message.get('password'))
        elif action == 'subscribe':
            bridge.subscribe(message.get('topics', []))
        elif action == 'publish':
            bridge.publish(message.get('topic'), message.get('payload'))
        elif action == 'set_token':
            bridge.set_token(message.get('password'))
        elif action == 'disconnect':
            bridge.disconnect()
        else:
            logging.warning('Action socket inconnue : %s', action)
    except Exception as e:
        logging.error('Erreur de traitement de l\'action %s : %s', action, e)


def listen(bridge):
    my_jeedom_socket.open()
    try:
        while 1:
            time.sleep(0.5)
            bridge.tick()
            read_socket(bridge)
    except KeyboardInterrupt:
        shutdown()


def handler(signum=None, frame=None):
    logging.debug('Signal %i intercepté, arrêt...', int(signum))
    shutdown()


def shutdown():
    logging.debug('Arrêt du démon')
    try:
        if _bridge is not None:
            _bridge.disconnect()
    except Exception as e:
        logging.warning('Erreur à l\'arrêt du pont MQTT : %s', e)
    try:
        os.remove(_pidfile)
    except Exception as e:
        logging.warning('Erreur suppression PID : %s', e)
    try:
        my_jeedom_socket.close()
    except Exception as e:
        logging.warning('Erreur fermeture socket : %s', e)
    logging.debug('Exit 0')
    sys.stdout.flush()
    os._exit(0)


_log_level = 'error'
_socket_port = 55009
_socket_host = 'localhost'
_pidfile = '/tmp/demond.pid'
_apikey = ''
_callback = ''
_cycle = 0.3
_bridge = None

parser = argparse.ArgumentParser(description='Démon MQTT Stellantis pour le plugin Jeedom')
parser.add_argument("--loglevel", help="Niveau de log du démon", type=str)
parser.add_argument("--callback", help="URL de callback vers Jeedom", type=str)
parser.add_argument("--apikey", help="Clé d'API", type=str)
parser.add_argument("--cycle", help="Cycle d'envoi des événements", type=float)
parser.add_argument("--pid", help="Fichier PID", type=str)
parser.add_argument("--socketport", help="Port du serveur socket", type=int)
args = parser.parse_args()

if args.loglevel:
    _log_level = args.loglevel
if args.callback:
    _callback = args.callback
if args.apikey:
    _apikey = args.apikey
if args.pid:
    _pidfile = args.pid
if args.cycle:
    _cycle = float(args.cycle)
if args.socketport:
    _socket_port = args.socketport

_socket_port = int(_socket_port)

jeedom_utils.set_log_level(_log_level)

logging.info('Démarrage du démon Stellantis')
logging.info('Niveau de log : %s', _log_level)
logging.info('Port du socket : %s', _socket_port)
logging.info('Fichier PID : %s', _pidfile)

signal.signal(signal.SIGINT, handler)
signal.signal(signal.SIGTERM, handler)

try:
    jeedom_utils.write_pid(str(_pidfile))
    my_jeedom_com = jeedom_com(apikey=_apikey, url=_callback, cycle=_cycle)
    if not my_jeedom_com.test():
        logging.error('Problème de communication réseau. Vérifiez la configuration réseau de Jeedom.')
        shutdown()
    _bridge = MqttBridge(my_jeedom_com)
    my_jeedom_socket = jeedom_socket(port=_socket_port, address=_socket_host)
    listen(_bridge)
except Exception as e:
    logging.error('Erreur fatale : %s', e)
    logging.info(traceback.format_exc())
    shutdown()
