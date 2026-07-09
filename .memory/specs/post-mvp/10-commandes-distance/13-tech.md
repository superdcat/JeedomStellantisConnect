# Spec technique — post-mvp 13 (Wakeup / rafraîchissement à la demande)

> Basée sur la spec fonctionnelle `13-wakeup.md`, le contrat confirmé contre le code de référence
> `flobz/psa_car_controller` (`psa/RemoteClient.wakeup` + `psa/mqtt_request.MQTTRequest`, vérifié
> 2026-07-09) et la revue de plan (advisor `code-reviewer`, 2026-07-09). Première commande MQTT du plugin.

## Contrat API (source de vérité : code de référence)

- **wakeup = publish MQTT** sur le topic
  `psa/RemoteServices/from/cid/{CID}/VehCharge/state`
  (`MQTTRequest` : `"psa/RemoteServices/from/cid/" + customer_id + service`, service wakeup = `/VehCharge/state`).
- **Payload publié** (message COMPLET, pas seulement `{"action":"state"}` — `MQTTRequest.get_message`) :
  ```json
  {
    "access_token": "<remote_token OTP>",
    "customer_id": "<CID>",
    "correlation_id": "<uuid_hex + timestamp_ms>",
    "req_date": "2026-07-09T14:30:00Z",
    "vin": "<VIN>",
    "req_parameters": { "action": "state" }
  }
  ```
  - `req_date` : UTC, format PSA `%Y-%m-%dT%H:%M:%SZ` → `gmdate('Y-m-d\TH:i:s\Z')`.
  - `correlation_id` : `uuid4().hex` (sans tirets) + `PSA_CORRELATION_DATE_FORMAT[:-3]` (= `%Y%m%d%H%M%S%f`
    tronqué aux millisecondes) → en PHP `bin2hex(random_bytes(16)) . gmdate('YmdHis') . <3 chiffres ms>`.
    Opaque, seule l'unicité compte (il est ré-émis dans l'ack pour corrélation).
  - ⚠️ Le **remote token OTP est RÉINJECTÉ dans le payload** (`access_token`), **en plus** d'être le mot de
    passe MQTT de la connexion (`RemoteClient.mqtt_request.get_message_to_json(access_token)`).
- **Ack asynchrone** sur `to/cid/{CID}/#` (déjà souscrit, UC11) :
  `{process_date, vin, correlation_id, process_code, process_message}` — `900` accepté, `901` véhicule en
  veille, `903` transmis au véhicule. `return_code=='400'` = token expiré (déjà géré UC11/12, hors UC13).
- **Quotas / garde-fous** (analyse § 1.4) : limite serveur **~6 wakeups / 20 min au niveau COMPTE** →
  ban API persistant si abus (bloque aussi le refresh du remote token) ; **risque batterie 12 V réel**.
  Cadence sûre communautaire : 5 min en charge / 60 min en veille (l'auto-wakeup opt-in = UC73, hors UC13).

## Architecture

Tout dans `core/class/stellantis.class.php` (classes `stellantis`/`stellantisCmd` déjà chargées → aucun
risque d'autoload). Le démon (`resources/demond/demond.py`) reste **inchangé** : le `publish` générique
`{topic, payload}` suffit, et `_redact()` masque déjà `access_token` récursivement (aucune fuite).

### Constantes (section UC13)
- `WAKEUP_SERVICE = '/VehCharge/state'` — segment de service du topic.
- `WAKEUP_ACTION = 'state'` — valeur de `req_parameters.action`.
- `WAKEUP_COOLDOWN = 300` — cooldown per-véhicule (s).
- `WAKEUP_COOLDOWN_KEY = 'stellantis::wakeup_cooldown::'` — préfixe cache (+ eqId).
- `WAKEUP_PENDING_KEY = 'stellantis::wakeup_pending::'` — préfixe cache (+ eqId), flag « refresh dû après ack ».
- `WAKEUP_PENDING_TTL = 1200` — TTL du flag pending. **> `RATELIMIT_COOLDOWN` (900 s)** : si un backoff 429
  fait sortir `cron()` avant la boucle véhicule, le flag survit jusqu'à la reprise → le refresh post-ack
  n'est pas perdu silencieusement (correction review qualité `major`).
- `WAKEUP_CORR_KEY = 'stellantis::wakeup_corr::'` — préfixe cache (+ correlation_id) → eqId.
- `WAKEUP_QUOTA_KEY = 'stellantis::wakeup_quota'` — quota global COMPTE (fenêtre glissante JSON, patron
  `REFRESH_QUOTA`). `WAKEUP_QUOTA_MAX = 5`, `WAKEUP_QUOTA_FENETRE = 1200` (marge sous 6/20 min).

### Commande action
- `definitionsActions(): array` — table (miroir de `definitionsCommandes`) :
  `'wakeup' => [__('Réveiller', __FILE__), 'other']` (nom FR littéral extractible, subType `other`).
- Factorisation : helper privé `ensureCmd(string $type, string $logicalId, array $def)` mutualisant la
  plomberie `getCmd/new/set*/save`. `ensureCommand()` (info) et `ensureActionCommand()` (action) l'appellent.
- `ensureActionCommand(string $logicalId): stellantisCmd` — idempotent, `type='action'`, `subType='other'`,
  `isVisible=1`.
- `createCommands()` : ajoute `$this->ensureActionCommand('wakeup')` (apparaît à la prochaine sync,
  idempotent sur les véhicules existants).

### Publish générique (réutilisable UC14-17)
- `buildMqttRequest(string $service, array $reqParameters): array` — retourne
  `['topic'=>..., 'payload'=>..., 'correlation_id'=>...]`. Récupère CID (`getCustomerId`), remote token
  (`stellantisApi::getRemoteToken()`), VIN (`getLogicalId()`), construit correlation_id + req_date.
- `publishRemoteCommand(string $service, array $reqParameters): string` — ordre : gardes **préconditions**
  (CID non vide, démon `state=='ok'`) → **quota global compte** (`WAKEUP_QUOTA`, throw `rate_limited`) —
  ainsi un échec de précondition ne consomme pas le quota anti-ban → `syncDaemonToken()` (alignement token
  session/payload) → `buildMqttRequest()` → `sendToDaemon('publish', …)`. `sendToDaemon` retourne désormais
  un **bool** (succès d'écriture socket) : si `false` (démon crashé après le check), throw `transport` — la
  commande n'a pas été transmise, on ne pose ni cooldown ni mapping (correction review qualité `minor`).
  Retourne le `correlation_id`. (Point unique de publication des commandes MQTT.)

### wakeup()
- `wakeup(): void` (méthode d'instance sur le véhicule). Séquence :
  1. **Cooldown per-véhicule** : si `WAKEUP_COOLDOWN_KEY.eqId` présent → `throw stellantisException(…, 429,
     'rate_limited')` avec le délai restant (message actionnable FR).
  2. **Remote token** : si `!stellantisApi::hasRemoteToken()` → `alerterOtpRequired()` + `throw (…,
     'otp_required')` (cohérent avec les 3 autres call sites).
  3. `publishRemoteCommand(WAKEUP_SERVICE, ['action'=>WAKEUP_ACTION])` (gère quota global + CID + démon +
     token). Récupère le `correlation_id`.
  4. Pose le cooldown (`cache::set(WAKEUP_COOLDOWN_KEY.eqId, time(), WAKEUP_COOLDOWN)`) et le mapping
     `WAKEUP_CORR_KEY.correlation_id => eqId` (TTL 300 s).
  5. `log::add(... 'info', 'Wakeup demandé pour l'équipement #...')`.

### stellantisCmd::execute()
- `switch ($this->getLogicalId())` → `case 'wakeup': $eqLogic = $this->getEqLogic(); if (is_object($eqLogic))
  $eqLogic->wakeup();`. Les exceptions remontent au wrapper core (toast d'erreur du bouton).

### MAJ des infos après l'ack (via cron, PAS dans le callback)
- `handleDaemonMessage()` cas `'message'` : `traiterAckCommande($_data)` — si le payload est un tableau
  portant un `correlation_id` mappé (`WAKEUP_CORR_KEY`), et si `process_code != 901` (véhicule en veille →
  pas de donnée fraîche à attendre), pose `WAKEUP_PENDING_KEY.eqId` (TTL 120 s) puis efface le mapping.
  **Aucun appel REST dans le callback** (doit répondre 200 vite ; le 1er ack est souvent « 900 accepté »).
- `cron()`, boucle par véhicule : **avant** le test `isDue()`, si `WAKEUP_PENDING_KEY.eqId` présent →
  `cache::delete()` + `refreshTelemetry()` forcé (dans le try/catch par véhicule existant) + `continue`.
  Le refresh bénéficie des garde-fous 429 déjà en place ; latence ≤ 1 min après l'ack (« quelques instants »).

## Server vs Client
100 % **serveur/démon**. Aucun front custom : le bouton d'action est le widget Jeedom standard, exécuté via
`cmd::execute()` du core (pas d'AJAX plugin). Le JS de la page de config n'est pas touché.

## Validation
- **Serveur** : cooldown per-véhicule (cache) + quota global compte (fenêtre glissante, marge sous le ban
  serveur) **avant** tout publish ; pré-requis remote token (OTP) + CID + démon lancé ; `syncDaemonToken()`
  avant publish. Callback démon best-effort (jamais de throw, aucun REST). `cron()` ne déclenche **jamais**
  de wakeup (uniquement la consommation d'un flag posé par une action délibérée).
- **Secrets** : remote token jamais loggué ; masqué par `_redact()` côté démon (payload publish inclus).
  Topic MQTT reçu (donnée externe) aseptisé avant log (retrait caractères de contrôle + troncature) —
  anti log-injection (review sécurité `low`).
- **Client** : n/a (bouton standard).

**Limite connue (acceptée)** : cooldown/quota reposent sur un cycle lecture→écriture cache non atomique
(pas de primitive atomique dans le core Jeedom). Une race entre deux déclenchements quasi simultanés peut
franchir la garde d'un ou deux réveils (review sécurité `low`) — acceptable (action bouton délibérée,
concurrence improbable ; marge `WAKEUP_QUOTA_MAX=5` sous le seuil serveur ~6).

## Server Actions / API
- `stellantis::definitionsActions() : array`
- `stellantis::ensureActionCommand(string) : stellantisCmd` / `ensureCmd(string,string,array) : stellantisCmd` *(privé)*
- `stellantis::buildMqttRequest(string $service, array $reqParameters) : array`
- `stellantis::publishRemoteCommand(string $service, array $reqParameters) : string` *(retourne correlation_id ; throws rate_limited/otp_required/api_error)*
- `stellantis->wakeup() : void` *(instance ; throws rate_limited/otp_required/…)*
- `stellantisCmd::execute($_options)` — dispatch `wakeup`
- `stellantis::handleDaemonMessage()` — cas `message` étendu (flag pending, aucun REST)
- `stellantis::cron()` — consommation du flag `wakeup_pending` (refresh forcé)

## Dépendances
Aucune nouvelle (paho-mqtt / pycryptodomex / requests déjà présents). `correlation_id`/`req_date` en PHP pur
(`random_bytes`, `gmdate`) — pas de lib UUID.

## i18n (FR ; traduction différée au translator, étape 10)
Nouvelles clés UI : `Réveiller` ; messages d'erreur `wakeup()`/`publishRemoteCommand()` :
« Réveil déjà demandé récemment ; patientez %d s (protection anti-ban / batterie 12 V) »,
« Trop de réveils récents sur le compte ; patientez avant de réessayer (protection anti-ban) »,
« Pilotage à distance non activé : activez l'OTP dans la configuration du plugin »,
« Identifiant client (CID) inconnu : refaites l'activation OTP », « Démon MQTT non démarré : impossible
d'envoyer la commande de réveil ».
