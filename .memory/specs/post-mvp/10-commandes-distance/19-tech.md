# Spec technique — 19 (Résilience de la connexion du démon MQTT)

> Spec fonctionnelle : `19-resilience-connexion-demon.md`. Cette UC **borne** l'échec de connexion
> (backoff + arrêt après N échecs d'auth) ; elle ne **répare pas** l'auth (obtention du remote token/CID
> = UC12). Aucun nouvel appel réseau REST/MQTT métier : le seul « contrat » est le mapping des codes
> CONNACK/disconnect de `paho-mqtt` 1.6.1.

## Architecture

Deux fichiers touchés, aucune dépendance nouvelle (`paho-mqtt==1.6.1`, `random`/`time`/`socket` stdlib
déjà présents) :

- **`resources/demond/demond.py`** (cœur) — remplace l'auto-reconnexion **native** de paho par une
  **machine à états (FSM) mono-thread**, pilotée depuis la boucle principale `listen()`. Pas de nouveau
  thread, pas de verrou.
- **`core/class/stellantis.class.php`** — `handleDaemonMessage()` gère le nouvel événement `auth_failed`
  et enrichit `connected`/`disconnected` ; nouveau helper `alerterDaemonAuthFailed()` (calqué sur
  `alerterOtpRequired()`) ; cache d'état démon ; ligne `health()` ; nettoyage cycle de vie
  (`deamon_stop`/`purgeOtp`).

### Démon `demond.py` — FSM de reconnexion

Neutralisation de paho : on abandonne `connect_async()` + `loop_start()` (qui déclenchent
l'auto-reconnexion interne non bornée de paho) au profit de :
- `client.connect(host, port, keepalive)` **synchrone** (envoie le CONNECT, ne bloque **pas** jusqu'au
  CONNACK — le CONNACK est lu par le pompage `loop`),
- **pompage manuel** `client.loop(timeout=0)` à **chaque tick** de `listen()` (tick existant :
  `time.sleep(0.5)` puis `read_socket`). C'est le pattern « piloter la boucle explicitement » demandé
  par la spec ; paho ne reconnecte plus jamais tout seul.

États (`MqttBridge._state`) :

| État | Signification | Sortie |
|---|---|---|
| `IDLE` | pas de connexion souhaitée (avant 1er `connect`, ou après `disconnect`) | `connect`/`set_token` |
| `CONNECTING` | `connect()` émis, en attente du CONNACK (ou d'un échec) | on_connect rc=0 → CONNECTED ; échec / timeout → BACKOFF ou BLOCKED |
| `CONNECTED` | CONNACK rc=0 reçu | on_disconnect → BACKOFF (transitoire) ; `disconnect` → IDLE |
| `BACKOFF` | attente avant nouvelle tentative | tick avec `monotonic() ≥ _next_attempt` → CONNECTING |
| `BLOCKED` | N échecs d'auth consécutifs atteints → arrêt des tentatives (process vivant, socket à l'écoute) | `connect`/`set_token` (réarmement) uniquement |

Cadencement sur `time.monotonic()` (jamais `time.time()`, insensible aux sauts d'horloge).

Constantes (défauts spec §Détails, ajustables) :
```
BACKOFF_BASE      = 5      # s, plancher dur (jamais de reconnexion immédiate)
BACKOFF_FACTOR    = 2
BACKOFF_MAX       = 300    # s, plafond
BACKOFF_JITTER    = 0.2    # ±20 %
AUTH_FAIL_MAX     = 5      # N : échecs d'auth consécutifs avant BLOCKED
CONNECT_TIMEOUT   = 10     # s, socket.setdefaulttimeout autour de connect() (handshake TCP/TLS)
CONNACK_TIMEOUT   = 15     # s, borne de l'état CONNECTING (CONNACK jamais reçu → échec transitoire)
```
Calcul du délai de backoff (transitoire **et** auth) :
```
delai = min(BACKOFF_BASE * (BACKOFF_FACTOR ** n), BACKOFF_MAX)
delai = delai * (1 + uniform(-BACKOFF_JITTER, +BACKOFF_JITTER))
delai = max(delai, BACKOFF_BASE)     # plancher dur, même après jitter négatif
```
`n` = **0-indexé** : `n = max(attempts - 1, 0)` où `attempts` = nombre d'échecs consécutifs
(transitoires + auth confondus). **1er échec → `n=0` → délai = `BACKOFF_BASE` (5 s, le plancher)**, puis
10 s, 20 s, 40 s … plafonné à 300 s. (Choix : la 1re re-tentative attend le plancher dur, pas
`BASE·FACTOR` — évite un premier délai de 10 s inutilement long.) `random.uniform` autorisé (démon
Python, pas le script de workflow).

### Contrat = mapping codes paho (à figer)

| Callback | `rc` | Catégorie | Traitement |
|---|---|---|---|
| `on_connect` | `0` | succès | → `CONNECTED`, **reset** (`_auth_fail=0`, `_attempts=0`), notify `connected`, ré-abonnement |
| `on_connect` | `≠0` | (mémorisé) | **ne compte pas ici** : stocke `_last_connack_rc=rc` ; le broker va fermer la socket → `on_disconnect` tranche |
| `on_disconnect` | `0` | arrêt volontaire | (`disconnect()` appelé) → pas de retry |
| `on_disconnect` | `≠0`, `_last_connack_rc ∈ {4,5,1,2}` | **auth** | `_auth_fail++` ; si `≥ N` → `BLOCKED` (+notify `auth_failed`) sinon `BACKOFF` |
| `on_disconnect` | `≠0`, `_last_connack_rc == 3` | **transitoire** | `BACKOFF`, pas d'incrément |
| `on_disconnect` | `≠0`, **aucun CONNACK reçu** dans la tentative (ex. rc=7) | **auth** (broker ferme faute d'auth complète — contexte pré-UC12) | `_auth_fail++` … idem auth |
| `on_disconnect` | `≠0`, tentative avait atteint `CONNECTED` | **transitoire** (perte réseau session saine) | `BACKOFF`, pas d'incrément |

**Décision anti-double-comptage (risque A du challenge advisor)** : en MQTT 3.1.1 un CONNACK refusé
(rc 4/5) est **toujours** suivi de la fermeture de socket → `on_connect(rc≠0)` **et** `on_disconnect`
se déclenchent pour la **même** tentative. Pour ne pas incrémenter deux fois (N deviendrait ~2-3,
non déterministe), **seule `on_disconnect` clôture et compte** une tentative échouée ; `on_connect` se
contente d'agir sur `rc=0` (succès) et de **mémoriser** `rc` sinon. `_last_connack_rc` est remis à
`None` au début de chaque tentative (passage en CONNECTING).

Codes CONNACK paho : `0`=ok, `1`=protocole, `2`=client id, `3`=serveur indispo (transitoire),
`4`=bad user/password (auth), `5`=not authorized (auth). `on_disconnect` `rc=7` observé = broker ferme
la socket (pas de CONNACK) → traité **auth** dans notre contexte.

### Garde-fous FSM (risques B & C du challenge)

- **Timeout handshake (B.1)** : borne le TCP quand le broker droppe les paquets. ⚠️ **Comportement réel
  de `paho-mqtt==1.6.1` (constaté à l'implémentation)** : `socket.setdefaulttimeout()` **n'a aucun effet**
  sur `client.connect()` — paho passe un timeout **explicite** (`self._connect_timeout`, 5.0 s par défaut,
  attribut privé non documenté) à `socket.create_connection()`, qui prime toujours sur le défaut global.
  → on force donc `client._connect_timeout = CONNECT_TIMEOUT` sur chaque nouveau client (seul levier
  efficace), et on garde `socket.setdefaulttimeout()` en filet (résolution DNS selon plateforme).
  **Risque résiduel ASSUMÉ** (limite de paho 1.6.1 épinglé) : le **handshake TLS** lui-même
  (`sock.do_handshake()`) est borné par le `keepalive` MQTT (60 s), **pas** par `CONNECT_TIMEOUT` — paho
  réutilise ce paramètre comme timeout socket du handshake. On ne réduit pas le keepalive (cela
  corromprait celui négocié avec le broker) et on n'ajoute pas de thread (contrainte d'archi de l'UC).
  Un pair qui accepte le TCP mais ne répond jamais au ClientHello TLS peut donc geler le tick jusqu'à
  ~60 s (**borné, pas infini**) — scénario très étroit. C'est **la source** citée par le commentaire de
  `_start_attempt()` dans `demond.py` (renvoi vers cette section).
- **Neutralisation d'un 2e canal d'auto-reconnexion paho (constaté à l'implémentation)** :
  `mqtt.Client(...)` a par défaut `reconnect_on_failure=True`, qui déclenche une reconnexion **interne**
  à paho (distincte de `connect_async()`/`loop_start()`) sur certains CONNACK refusés (rc=1/2), en
  rappelant `self.reconnect()` **sans jamais** invoquer `on_connect` → hors contrôle de la FSM. On passe
  donc `reconnect_on_failure=False` : ces cas remontent alors par `on_disconnect` (catégorisés `auth`
  faute de CONNACK observé), sous le contrôle du compteur/backoff.
- **Timeout CONNACK (B.2)** : `client.connect()` ne bloque pas jusqu'au CONNACK. Si aucun `on_connect`
  n'arrive dans `CONNACK_TIMEOUT` (broker accepte le TCP mais reste muet), le tick détecte
  `monotonic() - _connecting_since ≥ CONNACK_TIMEOUT` → traite comme **échec transitoire**, ferme le
  client et passe en `BACKOFF` (sinon la FSM resterait coincée en CONNECTING, tick gelé avec elle).
- **Garde client périmé (C)** : `connect()` recrée un `mqtt.Client`. En tête de `_on_connect` /
  `_on_disconnect` : `if client is not self._client: return` — ignore les callbacks tardifs d'un client
  déjà remplacé (évite d'écraser l'état du nouveau client).
- **Nettoyage** : `disconnect()` ne fait plus de `loop_stop()` (vestige de `loop_start`, no-op
  trompeur) ; il ferme proprement le client courant et repasse en `IDLE`.

### Anti-spam des notifications démon→Jeedom

`_publish_state(public_state)` ne `_notify` que si l'**état public** change (dédup sur
`_last_public_state ∈ {None,'connected','retrying','blocked'}`). Événements émis (schéma) :
- `{'type': 'connected', 'rc': 0}` — à chaque passage effectif en CONNECTED.
- `{'type': 'disconnected', 'category': 'transient', 'retrying': True}` — **1er** échec transitoire
  d'une série seulement (les tentatives suivantes ne re-notifient pas).
- `{'type': 'auth_failed', 'blocked': True}` — à l'entrée en BLOCKED, **une fois**.

`_redact` conservé ; aucun token/mot de passe loggué (critère UC11). Le protocole socket
(`connect`/`subscribe`/`publish`/`set_token`/`disconnect`) est **inchangé** : `read_socket` n'est pas
touché hormis le fait que `connect`/`set_token` réarment désormais la FSM.

### Réarmement (BLOCKED → CONNECTING)

`connect(...)` et `set_token(...)` remettent `_auth_fail=0`, `_attempts=0`, `_backoff` à la base,
sortent de `BLOCKED` et planifient une **tentative immédiate**. Sources de réarmement :
- redémarrage du démon (`deamon_start` → `pushDaemonConnect` → action `connect`) ;
- rotation du remote token OTP : `syncDaemonToken()` (appelé chaque `cron()`) pousse `set_token`
  **uniquement si le marqueur du token a changé**. ⚠️ **Conséquence documentée (clarif. advisor)** :
  BLOCKED **n'est pas un cul-de-sac total** — le remote token (TTL ~890 s) est rafraîchi proactivement
  et un `set_token` est donc poussé ~toutes les ~15 min, ce qui **réarme implicitement** la FSM (auto-retry
  très espacé « détourné », conforme à l'esprit anti-ban UC72). Si le remote token lui-même est cassé,
  `otp_required` est levé et rien n'est poussé (l'alerte OTP existante prend le relais). La FSM n'a donc
  **pas** de re-tentative auto *propre* (décision spec retenue), mais le canal `set_token` en produit une
  très espacée — à ne pas confondre avec un cul-de-sac.

## Server vs Client

Sans objet (pas de front web). Répartition : logique de reconnexion **dans le démon Python**
(état MQTT bas niveau, `monotonic()`), remontée d'état + notification utilisateur **côté PHP**
(`handleDaemonMessage`, `message::add`, `health`). La frontière existante démon↔PHP (socket +
callback `jeeStellantis.php`) est réutilisée sans changement de transport.

## Validation

- **Démon (unité logique)** : la FSM est pure (fonctions de calcul du backoff + décision de catégorie
  isolées), testable en rejouant des séquences de callbacks simulées. Vérifier : croissance du délai,
  plancher ≥5 s jamais franchi, `N` échecs auth → BLOCKED **une seule fois** (pas de double comptage
  rc=4/5 → on_connect+on_disconnect), reset sur rc=0, réarmement sur `connect`/`set_token`.
- **Bout en bout** : `php -l` (via CI, pas de PHP local). Le critère « rc=7 borné avant UC12 » n'est plus
  reproductible organiquement (depuis UC12, `pushDaemonConnect` exige `hasRemoteToken()`) → **test par
  déclenchement artificiel** (remote token/host délibérément invalide) à consigner dans
  `81-validation-manuelle.md`. Échec transitoire simulable en pointant un broker injoignable.
- **Anti-régression** : le protocole socket et le format des événements existants (`connected`,
  `disconnected`, `token_expired`, `message`) restent compatibles avec `handleDaemonMessage` (on ajoute
  des champs, on ne renomme rien ; `disconnected` gagne `category`/`retrying` optionnels).

## Server Actions / API (PHP)

`core/class/stellantis.class.php` — nouvelles constantes (bloc socle démon, ~l.1074) :
```php
const DAEMON_AUTH_ALERT_KEY = 'stellantis::daemon_auth_alert'; // dédup alerte auth démon
const DAEMON_AUTH_COOLDOWN  = 3600;                            // s : throttle de l'alerte
const DAEMON_CONN_STATE_KEY = 'stellantis::daemon_conn_state'; // cache état conn démon (lifetime 0)
```

`handleDaemonMessage(array $_data)` — dispatch enrichi :
- `case 'connected'` : log info existant **+** `message::removeAll('stellantis', 'daemon_auth_failed')`
  **+** `cache::delete(DAEMON_AUTH_ALERT_KEY)` **+** `cache::set(DAEMON_CONN_STATE_KEY, 'connected', 0)`.
- `case 'disconnected'` : log info existant **+** `cache::set(DAEMON_CONN_STATE_KEY, 'retrying', 0)`
  (transitoire, déjà throttlé par le démon — pas de `message::add`).
- `case 'auth_failed'` (**nouveau**) : `cache::set(DAEMON_CONN_STATE_KEY, 'auth_failed', 0)`
  **+** `alerterDaemonAuthFailed()` **+** log warning. Jamais de levée (point d'entrée externe).

`alerterDaemonAuthFailed(): void` (privé statique, calqué sur `alerterOtpRequired()`) :
```
si cache(DAEMON_AUTH_ALERT_KEY) non vide → return           // dédup / throttle
cache::set(DAEMON_AUTH_ALERT_KEY, '1', DAEMON_AUTH_COOLDOWN)
message::removeAll('stellantis', 'daemon_auth_failed')       // au plus 1 message actif
message::add('stellantis', __('Le démon de pilotage à distance n\'a pas pu s\'authentifier auprès du broker après plusieurs tentatives. Vérifiez votre connexion et, si nécessaire, réactivez le pilotage à distance (OTP) depuis la configuration du plugin, puis redémarrez le démon.', __FILE__), '', 'daemon_auth_failed')
log::add('stellantis', 'warning', '...')
```

`health()` — nouvelle ligne (après la ligne OTP, ~l.1013), **sans appel réseau** :
- Affichée **seulement si** `deamon_info()['state'] == 'ok'` **et** OTP actif (`otpState() == 'active'`)
  — sinon = bruit, ligne omise.
- Lit `cache::byKey(DAEMON_CONN_STATE_KEY)->getValue('')` :
  - `'auth_failed'` → `state=false`, result « Authentification refusée — reconnexion suspendue »,
    advice « Réactivez le pilotage à distance (OTP) puis redémarrez le démon ».
  - `'connected'` → `state=true`, result « Connecté au broker ».
  - `'retrying'` → `state=true` (neutre), result « Reconnexion en cours ».
  - `''` (non initialisé) → `state=true`, result « État inconnu » **ou** ligne omise (au choix du dev,
    ne jamais interpréter le vide comme un échec — cf. pattern `otpState`).

Cycle de vie :
- `deamon_stop()` : ajouter `cache::delete(DAEMON_CONN_STATE_KEY)` (à côté du `cache::delete(DAEMON_TOKEN_MARKER)` existant).
- `purgeOtp()` : ajouter `message::removeAll('stellantis', 'daemon_auth_failed')`,
  `cache::delete(DAEMON_AUTH_ALERT_KEY)`, `cache::delete(DAEMON_CONN_STATE_KEY)`.

## Dépendances

Aucune. `paho-mqtt==1.6.1` (déjà épinglé, `packages.json`), stdlib Python `random`/`time`/`socket`.
Pas de nouvelle clé de configuration (défauts en constantes ; configurabilité « si simple » non retenue
au MVP de cette UC pour rester sobre).

## Chaînes UI FR introduites (traduction différée — étape 10 du workflow)

`__()` littéraux, `__FILE__` = `stellantis.class.php` :
1. `"Le démon de pilotage à distance n'a pas pu s'authentifier auprès du broker après plusieurs tentatives. Vérifiez votre connexion et, si nécessaire, réactivez le pilotage à distance (OTP) depuis la configuration du plugin, puis redémarrez le démon."`
2. `"Connexion du démon (pilotage à distance)"` (libellé `test` de la ligne health)
3. `"Authentification refusée — reconnexion suspendue"`
4. `"Connecté au broker"`
5. `"Reconnexion en cours"`
6. `"Réactivez le pilotage à distance (OTP) puis redémarrez le démon"` (advice)
7. (`"État inconnu"` seulement si le dev retient l'affichage de l'état non initialisé)

Le démon Python **n'a pas** de chaîne i18n (logs français bruts non enveloppés — convention existante).
