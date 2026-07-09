# Spec technique — 16 (Verrouillage / déverrouillage des portes)

## Contrat API confirmé (source : `psa_car_controller/psa/RemoteClient.py`, WebFetch)

```python
def lock_door(self, vin, lock: bool):
    value = "lock" if lock else "unlock"
    msg = self.mqtt_request(vin, {"action": value}, "/Doors")
```

- Service MQTT : `/Doors` (topic = `psa/RemoteServices/from/cid/{CID}/Doors`, même enveloppe
  `MQTTRequest` que wakeup (UC13) / charge (UC14) / précond (UC15) — via `buildMqttRequest()`, inchangé).
- Payload `req_parameters` : `{"action": "lock"|"unlock"}`.
- Lecture d'état : réutilise l'info `doors_locked` (MVP07 / `parseStatus` → `extraireVerrouillage`,
  chemin `doors_state.locked_state`). **Aucun champ `failure_cause` dédié** pour le verrouillage côté
  data-model (§ 2.4) — voir « Indisponibilité » ci-dessous.

## Confirmation avant déverrouillage — mécanisme NATIF du core (source : `jeedom/core` `core/ajax/cmd.ajax.php`)

Le core intercepte l'exécution d'une commande action portant `actionConfirm=1` dans sa configuration :
```php
if ($cmd->getType() == 'action' && $cmd->getConfiguration('actionConfirm') == 1 && init('confirmAction') != 1) {
    throw new Exception(__('Cette action nécessite une confirmation', __FILE__), -32006);
}
```
Le code `-32006` déclenche côté JS core (`core/js/cmd.class.js`) un `jeeDialog.confirm(...)`
(desktop) / `confirm(...)` (mobile) natif, qui rejoue l'appel avec `confirmAction=1` si l'utilisateur
accepte. **Zéro code JS/HTML custom** : il suffit de poser `setConfiguration('actionConfirm', 1)` sur
la commande `unlock` à sa création. La chaîne « Cette action nécessite une confirmation » est traduite
par le **core**, pas par le plugin.

## Architecture — `core/class/stellantis.class.php` (aucun nouveau fichier)

- Constantes (bloc UC16, miroir UC14/UC15) :
  - `DOOR_SERVICE = '/Doors'`
  - `DOOR_DEBOUNCE = 10` (s) + `DOOR_DEBOUNCE_KEY` (cache, + eqId) — anti-boucle per-véhicule, pas de
    cooldown long (un vrai toggle lock→unlock doit passer), quota anti-ban déjà mutualisé dans
    `publishRemoteCommand()`.
- `definitionsActions()` : le tuple passe de 3 à **4 éléments** `[nom, subType, genericType,
  confirmRequis:bool]`. Les entrées existantes (`wakeup`, `charge_start/stop`, `precond_on/off`) restent
  à 3 éléments (le 4e est lu via `isset()`, défaut `false` — cohérent avec le pattern déjà en place où
  `ensureActionCommand` lit le 3e via `isset()`). Ajouts :
  - `lock`   → `[__('Verrouiller', __FILE__), 'other', '', false]`
  - `unlock` → `[__('Déverrouiller', __FILE__), 'other', '', true]`
- `ensureActionCommand()` : à la **création uniquement** (branche `getId() == ''`), si
  `!empty($definitions[$logicalId][3])`, poser `$cmd->setConfiguration('actionConfirm', 1)` **avant**
  l'unique `$cmd->save()` (pas de second write ; idempotent — pas de re-check si la commande existe déjà).
- `createCommands()` : `ensureActionCommand('lock')`/`ensureActionCommand('unlock')` créés
  **universellement** (les portes existent sur tout véhicule).
- `doorControl(bool $_verrouiller): void` (nouvelle méthode d'instance, miroir `precondControl`) :
  1. Debounce per-véhicule (`DOOR_DEBOUNCE_KEY`), posé **avant** tout appel réseau.
  2. Pré-requis OTP (`stellantisApi::hasRemoteToken()`, sinon `alerterOtpRequired()` + exception
     `otp_required`).
  3. `publishRemoteCommand(self::DOOR_SERVICE, ['action' => $_verrouiller ? 'lock' : 'unlock'])`.
  4. `cache::set(self::CMD_CORR_KEY . $correlationId, $this->getId(), self::CMD_CORR_TTL)` — réutilise
     le pipeline d'ack générique existant (**aucune** modification de `handleDaemonMessage`).
  5. `log::add('stellantis', 'info', ...)` symétrique lock/unlock.
- `stellantisCmd::execute()` : += cases `lock`/`unlock` → `$eqLogic->doorControl(true/false)`.
- **Aucun changement** à `definitionsCommandes()`/`parseStatus()` : `doors_locked` (info) existe déjà.

## Server vs Client

100 % serveur (PHP). La confirmation de déverrouillage est le **seul** aspect « client », et il est
entièrement fourni par le widget de commande core (dialog natif déclenché par `actionConfirm=1`) — rien
à écrire côté JS/HTML du plugin.

## Validation

- Confirmation `unlock` : côté core (config `actionConfirm=1`), anti-fausse-manip.
- Debounce per-véhicule (anti-boucle scénario), pré-requis OTP, quota global compte (mutualisé) : mêmes
  garde-fous que UC13/14/15.
- **Indisponibilité (thermique/équipement) — compromis documenté** : l'API MQTT `/Doors` n'expose
  **aucun `failure_cause`** dédié (≠ précond UC15). Une commande refusée par le véhicule se traduit
  seulement par `doors_locked` (info) qui ne change pas après l'ack — pas de message serveur explicite à
  remonter. On ne fait donc **pas** de garde proactive côté plugin (comportement identique à
  `precondControl`) ; la surface d'échec exploitable (statut/timeout d'ack) est renvoyée à UC18 (retour
  d'état des commandes). À documenter explicitement dans le docblock de `doorControl` pour ne pas fermer
  la porte à UC18.

## Server Actions / API

- `stellantis::doorControl(bool $_verrouiller): void` — cf. logique ci-dessus.
- `stellantisCmd::execute()` — routage `lock`/`unlock`.

## Dépendances

Aucune nouvelle dépendance (réutilise le démon MQTT UC11 + remote token UC12).
