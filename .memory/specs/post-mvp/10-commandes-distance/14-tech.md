# Spec technique — post-mvp 14 (Commande de charge start/stop)

> Basée sur la spec fonctionnelle `14-charge.md`, le contrat confirmé contre le code de référence
> `flobz/psa_car_controller` (`psa/RemoteClient.charge_now`/`veh_charge_request`, `psa/constants.py`,
> `psa/connected_car_api/models/energy_charging.py`, vérifié 2026-07-09) et la revue de plan (advisor
> `code-reviewer`, 2026-07-09). Deuxième famille de commandes MQTT (après le wakeup UC13). Réutilise
> intégralement le point de publication unique `publishRemoteCommand()` et le pipeline d'ack UC13.

## Contrat API (source de vérité : code de référence)

- **charge start/stop = publish MQTT** sur le service `/VehCharge` → topic
  `psa/RemoteServices/from/cid/{CID}/VehCharge` (`RemoteClient.veh_charge_request` :
  `mqtt_request(vin, req_parameters, "/VehCharge")`). ⚠️ Distinct du wakeup UC13 (`/VehCharge/state`).
- **`req_parameters`** : `{"program": {"hour": H, "minute": M}, "type": <charge_type>}`
  (`veh_charge_request`).
- **`charge_type`** (constantes `psa/constants.py`) : `IMMEDIATE_CHARGE = "immediate"` (démarrer),
  `DELAYED_CHARGE = "delayed"` (arrêter / repasser en différé). `charge_start`→`immediate`,
  `charge_stop`→`delayed`.
- **Heure `H`/`M`** : `charge_now` lit `charging.next_delayed_time` du véhicule via `get_charge_hour` et
  **ne bascule que le `type`** (il ne reprogramme jamais la charge différée). ⚠️ **Format ambigu, tranché** :
  - le modèle swagger `energy_charging.py` déclare `next_delayed_time` (`nextDelayedTime`) comme
    **timestamp RFC3339** (`"timestamp as defined in RFC3339"`) — hérité du contrat officiel B2B ;
  - mais `common/utils.parse_hour` fait `s = s[2:]` (strip `PT`) puis split `H`/`M`/`S` → attend une
    **durée ISO8601 `PTxxHxxMxxS`** ;
  - conséquence empirique : sur un véhicule où l'API renvoie du RFC3339, `parse_hour` **échoue et renvoie
    `None`** (try/except IndexError), et `charge_now` publie `{"hour":null,"minute":null}` — **et la
    commande fonctionne quand même**. ⇒ le champ `program` n'est **pas** strictement requis pour le
    basculement immediate/delayed ; seul `type` est opérant.
  - **Décision plugin** : `parseHeureIso()` **tolère les deux formats** (durée `PT..H..M..` ET timestamp
    `..THH:MM..`), extraction best-effort. Repli `0,0` (pas de `null` en PHP : champ typé int côté payload).
- **Ack asynchrone** identique à UC13 (topic `to/cid/{CID}/#`, `{process_code, correlation_id, …}`) →
  même pipeline `programmerRefreshApresAck` (mapping corrélation → refresh REST au prochain cron).

### Écart signalé (doc/analyse interne)
`.memory/analyse/stellantis-data-model.md:61` note `next_delayed_time` en **RFC3339** — cohérent avec le
swagger, **contredit** `parse_hour` (durée). L'ambiguïté est réelle et **non tranchable sans payload réel** ;
d'où le parseur tolérant + la stratégie « refresh frais avant stop ». Pas de correction de la doc (les deux
formats sont attestés selon la version de l'API/du véhicule).

## Architecture

Tout dans `core/class/stellantis.class.php` (classes `stellantis`/`stellantisCmd` déjà chargées → aucun
risque d'autoload). Démon `resources/demond/demond.py` **inchangé** (publish générique + `_redact`).

### Renommage (pipeline ack générique — avant UC15)
Le pipeline d'ack UC13 n'est plus spécifique au wakeup (charge le réutilise, UC15-17 suivront) :
- `WAKEUP_CORR_KEY` → **`CMD_CORR_KEY`** (`stellantis::cmd_corr::` + correlation_id => eqId)
- `WAKEUP_PENDING_KEY` → **`CMD_PENDING_KEY`** (`stellantis::cmd_pending::` + eqId : refresh REST dû après ack)
- `WAKEUP_PENDING_TTL` → **`CMD_PENDING_TTL`** (1200 s, inchangé)
- **`CMD_CORR_TTL` = 300** (nouveau) : TTL du mapping corrélation→eqId, utilisé par `wakeup()` ET
  `chargeControl()` (remplace la réutilisation trompeuse de `WAKEUP_COOLDOWN` — review qualité Q-2 `minor`).
Sites impactés : constantes, `wakeup()`, `programmerRefreshApresAck()`, `cron()`. Le quota global
(`WAKEUP_QUOTA_*`, `consommerQuotaWakeup`) garde son nom (partagé, anti-ban compte) — commentaire précisé
« toute commande MQTT » (déjà le cas fonctionnellement).

### Constantes UC14
- `CHARGE_SERVICE = '/VehCharge'` — segment de service du topic (⚠️ pas `/VehCharge/state`).
- `CHARGE_TYPE_IMMEDIATE = 'immediate'` / `CHARGE_TYPE_DELAYED = 'delayed'`.
- `CHARGE_NEXT_TIME_KEY = 'stellantis::charge_next_time::'` — + eqId (cache) : `next_delayed_time` brut du
  dernier `/status` (repli si le refresh-avant-stop échoue). TTL `CHARGE_NEXT_TIME_TTL = 172800` (2 j,
  pattern privacy — **jamais en config** : effaçable au Sauvegarder du formulaire desktop/php).
- `CHARGE_DEBOUNCE = 10` — anti-boucle per-véhicule (s) : autorise un vrai toggle start→stop, bloque un
  scénario en boucle qui viderait le quota global du compte. `CHARGE_DEBOUNCE_KEY = 'stellantis::charge_debounce::'`.

### Commandes action
- `definitionsActions()` : tuple étendu `[nom FR __() littéral, subType, genericType]`. Ajouts :
  - `'charge_start' => [__('Démarrer la charge', __FILE__), 'other', '']`
  - `'charge_stop'  => [__('Arrêter la charge', __FILE__), 'other', '']`
  - `'wakeup'` reçoit un 3ᵉ champ `''` (rétro-compat).
  - `generic_type` laissé **vide** : on ne devine pas de constante non vérifiée (risque cosmétique). La
    liaison widget on/off vers l'info `charging_status` (spec §19, `ENERGY_ON_OFF`/`ENERGY_STATE`) est
    **différée** — `charging_status` est un string, pas un binaire ⇒ toggle propre non trivial (UC ultérieur).
- `ensureActionCommand()` : pose le `generic_type` si non vide (no-op tant que vide).
- `createCommands()` : `charge_start`/`charge_stop` créés **uniquement** dans le bloc `Electric || Hybrid`
  (jamais sur thermique — critère d'acceptation). `wakeup` reste universel.

### chargeControl()
- `chargeControl(bool $_demarrer): void` (méthode d'instance). Séquence (ordre durci après reviews) :
  0. **Garde motorisation** (review sécurité SEC-2 `low`) : si config `energy` ∉ {Electric, Hybrid} →
     `throw (…, 'not_configured')`. Revérification serveur (une cmd peut subsister après requalification /
     appel scénario) — aucun appel réseau vers le véhicule.
  1. **Debounce per-véhicule** (`CHARGE_DEBOUNCE_KEY.eqId`) : si trop récent → `throw (…, 429, 'rate_limited')`.
  2. **Pré-requis OTP** : `!stellantisApi::hasRemoteToken()` → `alerterOtpRequired()` + `throw (…, 'otp_required')`.
  3. **Pose le debounce AVANT tout appel réseau** (review sécurité SEC-1 `medium`) : `cache::set(
     CHARGE_DEBOUNCE_KEY.eqId, time(), CHARGE_DEBOUNCE)`. Borne les tentatives répétées quel qu'en soit le
     résultat — sans ça, un `charge_stop` rejoué en échec (démon down, quota MQTT…) enchaînerait des GET
     `/status` **non bornées** → risque 429/ban compte. (Debounce court = 10 s ⇒ pose optimiste sans nuire.)
  4. **Si stop** : `try { $this->refreshTelemetry(); } catch (\Throwable) { log warning; }` — refresh REST
     frais AVANT le publish, dans tous les cas (l'heure de charge différée peut avoir changé depuis l'app
     mobile). Best-effort : un échec ne bloque pas le stop (repli cache/`0,0`).
  5. Heure : `[$h, $m] = $_demarrer ? [0, 0] : $this->heureChargeDifferee();` (immediate : heure ignorée par
     le véhicule ⇒ `0,0` direct sans refresh) ; `type = immediate|delayed`.
  6. `$corr = $this->publishRemoteCommand(CHARGE_SERVICE, ['program'=>['hour'=>$h,'minute'=>$m], 'type'=>$type])`
     (quota global compte + CID + démon + `syncDaemonToken` + `sendToDaemon` bool).
  7. Mapping `CMD_CORR_KEY.corr => eqId` (TTL `CMD_CORR_TTL`) → pipeline ack UC13 → refresh au prochain cron.
     Log `info`. (Le debounce est déjà posé en 3.)

### heureChargeDifferee() / parseHeureIso() / extraireHeureChargeDifferee()
- `heureChargeDifferee(): array` (instance) : lit `CHARGE_NEXT_TIME_KEY.eqId` (cache), `parseHeureIso()`.
  Si vide → `log::add(…, 'warning', …)` (effet de bord potentiel : reprogrammation à 0:0) + `return [0,0]`.
- `parseHeureIso(string): array` (statique) : tolérant aux 2 formats.
  - Durée : `preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?/', ...)`.
  - Timestamp RFC3339 : `preg_match('/T(\d{2}):(\d{2})/', ...)`.
  - Clamp `hour 0..23`, `minute 0..59`. Aucun match → `[0,0]`.
  - (Note TZ : le RFC3339 est en UTC ; on extrait HH:MM tel quel — best-effort, comme la référence qui ne
    convertit pas. Sans impact pour `immediate` ; pour `delayed` c'est la préservation au mieux.)
- `extraireHeureChargeDifferee(array $_status): string` (statique) : parcourt `energiesDepuisStatus`,
  énergie `type=='electric'` (whitelist stricte : entrée sans clé `type` exclue — review qualité Q-3 `minor`),
  renvoie `charging.next_delayed_time` scalaire ou `''`. Appelée depuis `refreshTelemetry()` (après
  `parseStatus`) : si non vide → `cache::set(CHARGE_NEXT_TIME_KEY.eqId, …, CHARGE_NEXT_TIME_TTL)` ; **sinon
  `cache::delete(CHARGE_NEXT_TIME_KEY.eqId)`** (review qualité Q-1 `major` : purger quand le champ disparaît
  du `/status` frais — programmation annulée depuis l'app — pour ne pas republier une heure périmée ≤ 48 h).

### stellantisCmd::execute()
- `switch` : `case 'charge_start': $eqLogic->chargeControl(true);` / `case 'charge_stop':
  $eqLogic->chargeControl(false);`. Exceptions remontées au wrapper core (toast bouton).

### Généralisation message d'erreur
`publishRemoteCommand` : « Démon MQTT non démarré : impossible d'envoyer la commande **de réveil** » →
« … impossible d'envoyer la commande » (partagé wakeup/charge/UC15-17). ⚠️ Clé i18n modifiée → l'ancienne
devient orpheline (à gérer par le `translator`).

## Server vs Client
100 % **serveur/démon**. Aucun front custom : boutons d'action = widgets Jeedom standard via `cmd::execute()`
du core. JS/HTML de config non touchés.

## Validation
- **Création conditionnelle** : `charge_start`/`charge_stop` absents sur véhicule thermique (test critère).
- **Serveur** : debounce per-véhicule (anti-boucle) → OTP → (stop : refresh frais best-effort) → quota
  global compte (dans `publishRemoteCommand`) → `syncDaemonToken` → publish. `sendToDaemon` bool → throw
  `transport` si échec (pas de mapping/debounce fantôme — le debounce est posé APRÈS le publish réussi).
- **Anti-clobber** : le stop refresh toujours l'état avant de publier ⇒ l'heure de charge différée envoyée
  reflète la programmation réelle (même modifiée depuis l'app mobile), jamais une valeur périmée du cache.
- **Refus véhicule** (seuil de charge, hors ligne) : remonté via l'ack asynchrone (UC18) — pas de faux succès.
- **Secrets** : remote token jamais loggué ; `_redact` côté démon couvre le payload de charge.
- **Client** : n/a (bouton standard).

**Limites connues (acceptées)** : (1) debounce/quota non atomiques (pas de primitive atomique core) — race
improbable sur action bouton. (2) Format `next_delayed_time` ambigu (RFC3339/durée) → parseur tolérant +
repli `0,0` ; en l'absence totale de programmation véhicule, un `stop` pose une charge différée à minuit
(donnée fraîche ⇒ rien à écraser). (3) Refresh-avant-stop consomme du budget REST (action délibérée).

## Server Actions / API
- `stellantis::definitionsActions() : array` *(tuple étendu [nom, subType, genericType])*
- `stellantis::ensureActionCommand(string) : stellantisCmd` *(pose generic_type si non vide)*
- `stellantis::createCommands()` *(charge_start/stop conditionnels Electric|Hybrid)*
- `stellantis::extraireHeureChargeDifferee(array $status) : string` *(statique)*
- `stellantis::parseHeureIso(string) : array` *(statique ; [heure, minute])*
- `stellantis->heureChargeDifferee() : array` *(instance ; lit cache + parseHeureIso, repli [0,0])*
- `stellantis->chargeControl(bool $demarrer) : void` *(instance ; throws rate_limited/otp_required/…)*
- `stellantis->refreshTelemetry()` — capture désormais `next_delayed_time` en cache
- `stellantisCmd::execute($_options)` — dispatch `charge_start`/`charge_stop`
- `stellantis::publishRemoteCommand()` / `programmerRefreshApresAck()` / `cron()` — constantes renommées `CMD_*`

## Dépendances
Aucune nouvelle (paho-mqtt / pycryptodomex / requests déjà présents). `parseHeureIso` en PHP pur.

## i18n (FR ; traduction différée au translator, étape 10)
Nouvelles clés UI (`__()`) : `Démarrer la charge`, `Arrêter la charge`, `Commande de charge déjà envoyée à
l'instant ; patientez %d s`, `Commande de charge indisponible : véhicule non rechargeable`. Clé **modifiée**
(orpheline à retirer/mettre à jour) : `Démon MQTT non démarré : impossible d'envoyer la commande` (retrait
« de réveil »). NB : les `log::add` (warning heure inconnue, etc.) sont en français simple, **non** `__()`
(convention projet : logs non traduits) → hors périmètre translator.
