# Spec technique — 18 (Retour d'état asynchrone des commandes)

> Basée sur la spec fonctionnelle `18-retour-etat-async.md`, le contrat recoupé contre le code de
> référence `flobz/psa_car_controller` (`psa/RemoteClient.py` master, 2026-07-10) + l'analyse interne
> `stellantis-api-architecture.md` § 1.3, et la revue de plan (advisor `code-reviewer`, 2026-07-10 —
> bloquants B1 topic-filter / B2 codes non terminaux, points C1-C3 intégrés). Transverse à UC13-17 :
> réécrit le pipeline d'ack amorcé en UC13 (`programmerRefreshApresAck`), comme anticipé par UC17.

## Contrat API (source de vérité : code de référence + analyse § 1.3)

**Deux formes d'ack coexistent** selon la version/source, lues défensivement (aucune n'est garantie) :

| Champ | Forme `return_code` (`psa_car_controller` `to/cid`) | Forme `process_code` (variante HA) |
|---|---|---|
| Code | `return_code` : `'0'`=succès, `'400'`=token expiré, autre=échec | `process_code` : `900` acceptée, `901` en veille, `903` transmise, autres (113/300/500)=échec |
| Détail échec | `reason` | `process_message` |
| Corrélation | `vin` (⚠️ `correlation_id` **pas toujours** ré-émis dans les acks `return_code`) | `vin` + `correlation_id` |

- **Topics** (déjà souscrits, `subscribeTopics()` UC11) :
  - `psa/RemoteServices/to/cid/{CID}/#` → **acks de commande** (seuls traités par UC18).
  - `psa/RemoteServices/events/MPHRTServices/#` → **événements poussés** (états charge/précond spontanés,
    sans lien avec une commande émise ; schéma « À confirmer ») → **hors scope UC18**, log debug + ignoré.
- **`return_code` prioritaire sur `process_code`** (aligné sur le code de référence
  `data.get("return_code") or data.get("process_code")`).
- **Ordre des acks** : 900 (« Accepté ») est souvent le **premier** d'une séquence ; l'état terminal est
  `return_code 0` (succès) ou un code d'échec. 900/903/901 sont **intermédiaires** (mapping conservé).
- **Code 400** (token expiré) : le démon émet DÉJÀ, en plus du `message`, un `{type:'token_expired'}`
  (`demond.py` `_on_message`) → le refresh du remote token est géré par le cas `token_expired` existant.
  **Décision (validée utilisateur 2026-07-10)** : **PAS de re-publish automatique** de la commande — on
  la **signale** (`last_command_result` + notif « réessayez »). Justification : le remote token est
  rafraîchi proactivement chaque minute (`syncDaemonToken`), donc un 400 est rare ; un re-publish auto
  ajouterait un état global « dernière requête » + gardes anti-boucle et risquerait de rejouer la
  mauvaise commande sur un compte multi-véhicules. Réaligné dans les 4 docs internes (étape 12/13).

## Architecture

**Un seul fichier modifié : `core/class/stellantis.class.php`.** Additif, non invasif. La structure de
`CMD_CORR_KEY` reste `correlation_id => eqId` (bare int) ; le démon (`demond.py`) et `jeeStellantis.php`
sont **inchangés** (ils forwardent déjà tout message + le topic).

### 1. Nouvelle info `last_command_result`
- `definitionsCommandes()` : `'last_command_result' => array(__('Dernier résultat commande', __FILE__),
  'string', '', '', false)` (string, non historisée, pas de generic_type).
- `createCommands()` : ajoutée à la liste **universelle** (à côté de `last_update`) — tout véhicule.
- **Jamais produite par `parseStatus()`** → le refresh REST ne l'écrase pas ; alimentée uniquement par le
  callback démon. Création paresseuse via `ensureCommand('last_command_result')` dans le callback pour
  les véhicules antérieurs à UC18.
- Constante `CMD_RESULT_LOGICAL_ID = 'last_command_result'` (référencée dans createCommands + callback).

### 2. Réécriture du pipeline d'ack
`handleDaemonMessage()` cas `'message'` : le log debug du topic aseptisé reste ; l'appel
`programmerRefreshApresAck($_data)` est remplacé par `traiterRetourCommande($_data, $topicLog)` (méthode
statique privée). **`programmerRefreshApresAck` est supprimée** (son unique appelant est mis à jour).

`traiterRetourCommande(array $_data, string $_topic): void` (best-effort, jamais de levée, aucun appel
réseau REST) :
1. **Filtre topic (B1)** : si le topic ne commence pas par `psa/RemoteServices/to/cid/` → return (les
   `events/MPHRTServices` et autres sont hors périmètre). Constante `MQTT_RESP_TOPIC_PREFIX`.
2. **Parse défensif** du `payload` (array) :
   - `rc` = `return_code` si scalaire non vide, sinon `''` ; `pc` = `process_code` si scalaire non vide.
   - `corr` = `correlation_id` (scalaire) sinon `''` ; `vin` = `vin` (scalaire) sinon `''`.
   - `reason` = `reason` sinon `process_message` (scalaire), **aseptisé** (`preg_replace('/[[:cntrl:]]/','')`
     + `mb_substr(...,0,200)`) — donnée broker externe.
3. **Interprétation** via helper `interpreterAck(string $rc, string $pc, string $reason): array` retournant
   `['result'=>string, 'refresh'=>bool, 'keepMapping'=>bool, 'notify'=>'none'|'failure'|'token'|'clear',
   'known'=>bool]`. Table (chaînes `__()` **littérales** dans le helper) :

   | Condition | result | refresh | keepMapping | notify | known |
   |---|---|---|---|---|---|
   | `rc==='0'` | `Succès` | true | false | clear | true |
   | `rc==='400'` | `Session renouvelée, veuillez réessayer` | false | false | token | true |
   | `rc!==''` (autre) | `Échec : <reason>`/`Échec (code %s)` | false | false | failure | true |
   | `pc==='900'` | `Acceptée par le véhicule` | true | true | none | true |
   | `pc==='903'` | `Transmise au véhicule` | true | true | none | true |
   | `pc==='901'` | `Véhicule en veille` | false | true | none | true |
   | `pc!==''` (autre) | `Échec : <reason>`/`Échec (code %s)` | false | false | failure | true |
   | rc et pc vides | — | false | true | none | **false** |

   Si `known===false` → return (message `to/cid` sans code exploitable : ni résultat ni faux « Échec »).
4. **Résolution véhicule** : `corr` non vide → `CMD_CORR_KEY.corr` (int > 0 ⇒ `corrMapped=true`) ; sinon
   `vin` non vide → `eqLogic::byLogicalId($vin, 'stellantis')`. Rien trouvé → return. **Ignorer un eqLogic
   désactivé** (`!getIsEnable()` → return, C3) et non-`stellantis` (`instanceof`).
5. **MAJ `last_command_result`** : `$eqLogic->checkAndUpdateCmd($eqLogic->ensureCommand(
   self::CMD_RESULT_LOGICAL_ID), $result)`.
6. **Notif centre de messages** (jamais silencieux, C1/C2) — `logicalId` = `'command_failed::'.eqId` :
   - `failure` → `message::removeAll(...)` puis `message::add(..., sprintf(__('Commande à distance en
     échec pour l\'équipement #%1$d : %2$s', __FILE__), eqId, reason≠''?reason:code))` + `log::warning`.
   - `token` → `message::removeAll(...)` puis `message::add(..., __('Commande à distance : session
     renouvelée pour l\'équipement #%d, veuillez réessayer', __FILE__))` (moins alarmant).
   - `clear` (succès) → `message::removeAll('stellantis', 'command_failed::'.eqId)` (nettoie un échec
     antérieur).
   - `none` → rien.
7. **Refresh REST (inchangé fonctionnellement)** : si `corrMapped && refresh===true` →
   `cache::set(CMD_PENDING_KEY.eqId, '1', CMD_PENDING_TTL)` (consommé au prochain `cron()`, garde-fous 429
   déjà en place). Le repli `vin` (klaxon/feux, events, terminal sans corr) ne programme **jamais** de
   refresh (anti-ban). Puis, gestion du mapping : si `!keepMapping` → `cache::delete(CMD_CORR_KEY.corr)`
   (uniquement quand `corr` non vide) ; sinon conservé (expiration par TTL).

### 3. token_expired (400) — inchangé
Le cas `token_expired` de `handleDaemonMessage` (refresh remote token + `set_token`) reste tel quel. Pas
de re-publish (décision ci-dessus). Le `message` 400 associé passe par le pipeline `to/cid` → notif
« réessayez ».

## Server vs Client
100 % **serveur/démon**. Aucun JS/HTML custom : `last_command_result` (info `string`) est rendue par le
widget d'info par défaut du core. Le callback (`jeeStellantis.php`) reste un point d'entrée HTTP qui
répond toujours 200 ; `traiterRetourCommande` ne fait **que** des écritures locales (DB cmd + cache +
message center), **aucun appel réseau REST/MQTT**, jamais de levée.

## Validation
- **Serveur / callback** : parsing défensif de données broker externes NON FIABLES. Filtre de topic sur le
  topic **brut** (décision de routage, pas la version aseptisée du log — review sécu). `reason`/`process_message`
  **et** `rc`/`pc` (tout ce qui atteint un log / une valeur de commande / le centre de messages) : helper
  partagé `aseptiser()` (retrait `[[:cntrl:]]` + troncature) **puis** `htmlspecialchars(ENT_QUOTES)` — défense
  en profondeur XSS stocké, transparent pour les codes/raisons légitimes (review sécu, medium). `corr`/`vin`
  ne sont jamais affichés (clé de cache / `byLogicalId` paramétré). Corrélation `correlation_id` (précis,
  distingue deux commandes) puis repli `vin` (per-véhicule) ;
  refresh REST borné aux commandes **corrélées** (stateful) — jamais sur les push events ni le repli vin ;
  eqLogic désactivé ignoré ; notif idempotente (`removeAll` avant `add`). Aucun réseau, aucune levée
  (best-effort — le callback doit répondre 200 vite).
- **Limite connue (documentée)** : le refresh REST peut être déclenché dès l'ack « Accepté » (900), donc
  potentiellement avant l'application effective côté véhicule — l'état réel remonte de toute façon (a) via
  `last_command_result` mis à jour par le message **terminal** (résolu par `vin`, indépendant du TTL du
  mapping) et (b) via la cadence `autorefresh` normale. Non-régression vs le comportement UC13-17.
- **Client** : n/a (widget standard).

## Server Actions / API
- `stellantis::handleDaemonMessage()` — cas `'message'` : appelle `traiterRetourCommande($_data, $topicLog)`.
- `stellantis::traiterRetourCommande(array $_data, string $_topic): void` *(statique privée ; remplace
  `programmerRefreshApresAck`)*.
- `stellantis::interpreterAck(string $rc, string $pc, string $reason): array` *(statique privée ; table
  code→(result/refresh/keepMapping/notify/known), chaînes `__()` littérales)*.
- `stellantis::definitionsCommandes()` — ajout `last_command_result`.
- `stellantis::createCommands()` — `ensureCommand(self::CMD_RESULT_LOGICAL_ID)` (universel).
- Constantes : `CMD_RESULT_LOGICAL_ID = 'last_command_result'`, `MQTT_RESP_TOPIC_PREFIX =
  'psa/RemoteServices/to/cid/'`. Réutilise `CMD_CORR_KEY`/`CMD_CORR_TTL`/`CMD_PENDING_KEY`/`CMD_PENDING_TTL`.

## Dépendances
Aucune. Réutilise le socle MQTT UC11 (démon), le point de publication UC13
(`publishRemoteCommand`/`buildMqttRequest`) et le pipeline de refresh cron UC13.

## Impact i18n (FR — traduction différée étape 10)
Nouvelles clés `__()` (chaînes littérales) dans `core/class/stellantis.class.php` :
- `Dernier résultat commande`
- `Succès`
- `Acceptée par le véhicule`
- `Transmise au véhicule`
- `Véhicule en veille`
- `Session renouvelée, veuillez réessayer`
- `Échec : %s`
- `Échec (code %s)`
- `Commande à distance en échec pour l'équipement #%1$d : %2$s`
- `Commande à distance : session renouvelée pour l'équipement #%d, veuillez réessayer`
