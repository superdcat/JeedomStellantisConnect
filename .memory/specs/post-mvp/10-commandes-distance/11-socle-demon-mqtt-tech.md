# Spec technique — post-mvp 11 (Socle démon Python MQTT)

> Contrat confirmé le **2026-07-08** contre `flobz/psa_car_controller` (`psa/RemoteClient.py`) et la doc
> Jeedom `dev/daemon_plugin`. Bascule d'architecture : `hasOwnDeamon:true`.

## Contrat externe confirmé
- **Broker** : le code de référence `RemoteClient` utilise **`mwa.mpsa.com:8885`** (TLS via
  `tls_set_context()`, `mqtt.Client(clean_session=True, protocol=MQTTv311)`).
  ⚠️ **Écart** : la spec fonctionnelle §23 cite `mw-{brand_code}-m2c.mym.awsmpsa.com:8885` par marque,
  `mwa.mpsa.com` « aussi cité (dépannage) ». Le code de référence actuel utilise `mwa.mpsa.com` → **on
  prend `mwa.mpsa.com` en défaut** (contrat réel), **host configurable** (`broker_host`) pour retomber
  sur la forme par marque si besoin. (La spec fonctionnelle est annotée de cet écart, 2026-07-08.)
- **Auth MQTT** : `username_pw_set("IMA_OAUTH_ACCESS_TOKEN", <access_token OAuth2>)` — le token OAuth2
  déjà géré au MVP, **pas** le remote token OTP (UC12).
- **Topics** : subscribe `psa/RemoteServices/to/cid/{CID}/#` **ET** `psa/RemoteServices/events/MPHRTServices/` ;
  publish `psa/RemoteServices/from/cid/{CID}/{Service}/state`.
- **CID** (`AP-ACNT…` PSA / `OV-ACNT…` Opel-Vauxhall) : source API **non figée** (`get_mqtt_customer_id()`
  côté référence) → **hors périmètre socle**, relève de UC12. Le socle lit `config customer_id` ; si vide,
  il se connecte au broker mais **diffère l'abonnement** (loggué), sans échec.
- **Ack** (UC18) : payload `{process_date,vin,correlation_id,process_code,process_message}` ;
  `return_code=='400'` = token expiré → refresh + reconnexion.

## Architecture

### Découpage : démon = TRANSPORT MQTT générique, métier en PHP
Le démon ne connaît **rien** de PSA (ni topics, ni CID, ni payloads en dur). Il expose un transport :
`connect` / `subscribe` / `publish` / `set_token` / `disconnect`. Toute la logique métier (broker par
marque, CID, topics, payloads) vit en PHP (`stellantis`). Prolonge la convention « tout HTTP REST passe
par `stellantisApi` » → « toute commande MQTT passe par le démon, la connaissance métier reste en PHP ».

### Fichiers
1. **`plugin_info/info.json`** : `hasDependency:true`, `hasOwnDeamon:true`, `maxDependancyInstallTime:30`.
2. **`plugin_info/packages.json`** : nettoyé (supprime `npm`/`yarn`/`composer`/`pre`/`post` morts +
   pyserial) → `pip3: { "paho-mqtt<2.0.0": {}, "requests": {} }` (pin range dans la clé pip). Debian 12
   (pip externally-managed) géré par le core.
3. **`resources/demond/demond.py`** : réécrit. Dispatch `read_socket()` sur `message['action']` :
   - `connect{host,port,username,password}` : (re)crée le client paho, `tls_set_context()`, `username_pw_set`,
     `connect_async` + `loop_start`.
   - `subscribe{topics:[...]}` : mémorise + s'abonne (ré-appliqué en `on_connect`).
   - `publish{topic,payload}` : `client.publish(topic, json.dumps(payload))`.
   - `set_token{password}` : met à jour `username_pw_set` et **reconnecte** (nouveau mot de passe MQTT).
   - `disconnect` : `loop_stop` + `disconnect`.
   - `on_message` : remonte `{type:'message', topic, payload}` à Jeedom via `jeedom_com`. Si le payload
     contient `return_code=='400'` (ou `process_code` équivalent) → remonte aussi `{type:'token_expired'}`.
   - `on_connect`/`on_disconnect` : remontent `{type:'connected'|'disconnected', rc}`.
   - **Rédaction** : aucun log ne doit contenir `password`/`access_token` → helper `_redact()` appliqué
     avant tout `logging.*` manipulant un payload de connexion.
4. **`resources/demond/jeedom/jeedom.py`** : retire `import serial`, `import pyudev`, la classe
   `jeedom_serial` et `find_tty_usb` (supprime deps pyserial/pyudev). **Corrige** `jeedom_socket_handler.handle()`
   pour ne PAS logger le message brut (masque le contenu sensible — log de la seule longueur/action).
5. **`core/php/jeeStellantis.php`** *(nouveau)* : point d'entrée callback démon→Jeedom.
   `require core.inc.php` → `stellantis::` (autoload) → `jeedom::apiAccess(init('apikey'),'stellantis')`
   sinon `die()` → lit `json_decode(file_get_contents('php://input'))` → `stellantis::handleDaemonMessage($data)`.
   **Tout dans un try/catch global** ; **toujours** HTTP 200 + `echo json_encode(['state'=>'ok'|'error'])`
   (le `jeedom_com` Python n'a que 3 retries → jamais de 500).
6. **`core/php/.htaccess`** : conserve `Order allow,deny / Deny from all` global + ajoute
   `<Files jeeStellantis.php>\n  Allow from all\n</Files>` (seul fichier joignable ; `stellantis.inc.php`
   reste interdit). Exception nécessaire au callback démon.
7. **`core/class/stellantis.class.php`** :
   - `deamon_info(): array` — `state` via `jeedom::getTmpFolder(__CLASS__).'/demond.pid'` + `posix_getsid`
     (nettoie le pid mort) ; `launchable` = `isConfigured()` + token authentifié
     (`stellantisApi::getTokenInfo()['authenticated']`), sinon `launchable`='nok' + `launchable_message` ;
     `log` = `__CLASS__`.
   - `deamon_start(bool $_debug=false): bool` — `deamon_stop()` d'abord ; refuse si `launchable!=ok` ;
     commande `system::getCmdPython3(__CLASS__).' '.realpath(__DIR__.'/../../resources/demond').'/demond.py'`
     + `--loglevel/--socketport/--callback/--apikey/--pid` (callback via `self::callbackUrl()`, casse
     unique) ; `exec(... >> log 2>&1 &)` ; boucle d'attente `state==ok` (≤20 s) ; puis
     `sendToDaemon(connect)` + `sendToDaemon(subscribe)`.
   - `deamon_stop(): void` — kill pid (`system::kill`) + `system::fuserk(socketport)`.
   - `sendToDaemon(string $_action, array $_params=[]): void` — `socket_create`/`connect`/`write`/`close`
     vers 127.0.0.1:socketport, ajoute `apikey` + `action` ; try/catch, **best-effort** (log warning,
     jamais fatal).
   - `handleDaemonMessage(array $_data): void` — dispatch sur `type` : `token_expired` →
     `stellantisApi::getToken(true)` (une seule fois, borné) + `sendToDaemon(set_token)` ; `connected`/
     `disconnected`/`message` → log debug/info ; **`default` silencieux** (types inconnus jusqu'à UC18).
   - `syncDaemonToken(): void` — si démon `state==ok` et token présent, pousse `set_token` **seulement si
     le token a changé** depuis le dernier push (marqueur en cache = hash court). Appelé par `cron()` après
     le priming du token, et utilisable ailleurs.
   - Helpers `brokerHost(): string` (config `broker_host` sinon `mwa.mpsa.com`), `getCustomerId(): string`
     (config `customer_id`, vide au socle), `subscribeTopics(): array`, `callbackUrl(): string`.
   - `cron()` : après le `getToken()` de priming réussi, appeler `self::syncDaemonToken()` (best-effort).
8. **`plugin_info/install.php`** : `stellantis_remove()` et début de `stellantis_update()` →
   `try { stellantis::deamon_stop(); } catch (\Throwable $e) {}` (autoload : `require core.inc.php` +
   `stellantis::`).
9. **`plugin_info/configuration.txt`(+`.php`)** : champ avancé `socketport` (label + tooltip, `{{...}}`).
   L'état/démarrage du démon est rendu par la page plugin du core (`hasOwnDeamon:true`) — pas d'UI custom.
10. **`.memory/specs/post-mvp/80-livraison/81-validation-manuelle.md`** : ajout d'une section recette
    manuelle UC11 (démarrage/arrêt, coupure réseau + reconnexion, expiration token simulée sans boucle
    infinie, vérification qu'aucun secret n'apparaît dans les logs).

## Server vs Client
100 % **serveur/démon**. Aucun front custom (gestion démon = bloc générique du core).

## Validation
- **Serveur** : `deamon_info.launchable=nok` + message si non configuré / non authentifié.
  `sendToDaemon` best-effort (jamais fatal). Callback : `apiAccess` obligatoire, try/catch, HTTP 200.
  `handleDaemonMessage` : refresh token borné (pas de boucle), `default` silencieux.
- **Démon** : vérifie `apikey` de chaque message socket (squelette) ; rédaction systématique des secrets ;
  reconnexion paho (`loop_start`) ; pas de payload/topic PSA en dur.

## Server Actions / API
- `stellantis::deamon_info()/deamon_start()/deamon_stop()` (contrat core).
- `stellantis::sendToDaemon(string,array)`, `handleDaemonMessage(array)`, `syncDaemonToken()`,
  `brokerHost()`, `getCustomerId()`, `subscribeTopics()`, `callbackUrl()`.
- Callback HTTP `POST /plugins/stellantis/core/php/jeeStellantis.php?apikey=…` (JSON body).
- Protocole socket PHP→démon : `{apikey, action, ...params}`.

## Dépendances
`paho-mqtt<2.0.0` + `requests` (pip3). Interpréteur via `system::getCmdPython3(__CLASS__)`.
Squelette démon **legacy** (`jeedom/jeedom.py`) conservé (avec correctif rédaction), **pas** de migration
vers `jeedomdaemon` pour le socle : moindre risque, aucune dépendance pip supplémentaire, cohérent avec
CLAUDE.md. (Réévaluable ultérieurement.)
