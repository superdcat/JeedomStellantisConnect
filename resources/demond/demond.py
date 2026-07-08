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

import logging
import sys
import os
import time
import traceback
import signal
import json
import hmac
import argparse

import paho.mqtt.client as mqtt

from jeedom.jeedom import jeedom_socket, jeedom_utils, jeedom_com, JEEDOM_SOCKET_MESSAGE


# --- Clés sensibles à ne JAMAIS logguer en clair (critère d'acceptation UC11) ---
_SENSIBLE = ('password', 'access_token', 'token')


def _redact(obj):
    """Copie d'un dict/liste avec les valeurs sensibles masquées, pour un logging sûr."""
    if isinstance(obj, dict):
        return {k: ('***' if k in _SENSIBLE else _redact(v)) for k, v in obj.items()}
    if isinstance(obj, list):
        return [_redact(v) for v in obj]
    return obj


class MqttBridge:
    """Client MQTT piloté par les actions reçues du PHP. État minimal : client courant, identifiants,
    topics à (ré)abonner après (re)connexion."""

    def __init__(self, jeedom_com):
        self._com = jeedom_com
        self._client = None
        self._host = None
        self._port = 8885
        self._username = None
        self._password = None
        self._topics = []

    # --- Callbacks PHP (remontées) ---
    def _notify(self, payload):
        # Remonte un événement à Jeedom via le callback. send_change_immediate (pas add_changes) : envoi
        # fiable par message, sans batch qui écraserait deux événements rapprochés de même clé.
        # Enveloppe {'stellantis_daemon': payload} → jeeStellantis.php déballe et dispatche.
        self._com.send_change_immediate({'stellantis_daemon': payload})

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

    # --- Callbacks paho ---
    def _on_connect(self, client, userdata, flags, rc):
        logging.info('MQTT connecté (rc=%s)', rc)
        # Ré-abonnement systématique après (re)connexion
        self._subscribe_all(client, self._topics)
        self._notify({'type': 'connected', 'rc': int(rc)})

    def _on_disconnect(self, client, userdata, rc):
        logging.info('MQTT déconnecté (rc=%s)', rc)
        self._notify({'type': 'disconnected', 'rc': int(rc)})

    def _on_message(self, client, userdata, msg):
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

    # --- Actions PHP ---
    def connect(self, host, port, username, password):
        self.disconnect()
        self._host = host
        self._port = int(port) if port else 8885
        self._username = username
        self._password = password
        client = mqtt.Client(clean_session=True, protocol=mqtt.MQTTv311)
        client.on_connect = self._on_connect
        client.on_disconnect = self._on_disconnect
        client.on_message = self._on_message
        try:
            client.tls_set_context()
        except Exception as e:
            # TLS obligatoire (port 8885) : on abandonne plutôt que de tenter une connexion non chiffrée
            logging.error('Erreur configuration TLS, connexion abandonnée : %s', e)
            self._notify({'type': 'error', 'reason': 'tls'})
            return
        if username is not None:
            client.username_pw_set(username, password)
        self._client = client
        logging.info('Connexion MQTT à %s:%s', self._host, self._port)
        client.connect_async(self._host, self._port, keepalive=60)
        client.loop_start()

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
        # Rotation du token OAuth2 (= mot de passe MQTT) : maj identifiants + reconnexion
        self._password = password
        if self._client is None or self._host is None:
            logging.info('set_token reçu mais pas de connexion active (sera pris au prochain connect)')
            return
        logging.info('Rotation du token MQTT → reconnexion')
        self.connect(self._host, self._port, self._username, password)

    def disconnect(self):
        if self._client is not None:
            try:
                self._client.loop_stop()
                self._client.disconnect()
            except Exception as e:
                logging.warning('Erreur à la déconnexion MQTT : %s', e)
            self._client = None


def read_socket(bridge):
    if JEEDOM_SOCKET_MESSAGE.empty():
        return
    logging.debug('Message reçu sur le socket JEEDOM_SOCKET_MESSAGE')
    message = json.loads(jeedom_utils.stripped(JEEDOM_SOCKET_MESSAGE.get()))
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
