# Spec technique — 33 (Historique des trajets)

> **Domaine** : localisation/trajets · **Dépend de** : UC31 (position GPS) · **Type** : reconstruction locale
> pure (aucun appel réseau neuf). Pattern **calqué à l'identique** sur UC24 (`suivreSessionCharge` /
> `calculerRecapSession`, cf. `24-tech.md`).

## Contexte & décision

Les endpoints « trips » de l'API ont été **dépréciés côté B2B et sont inaccessibles côté consommateur**
(`stellantis-data-model.md` § 2.3 ; `stellantis-implementations-reference.md` : `psa_car_controller`
reconstruit lui-même les trajets depuis position/odomètre). → **Reconstruction 100 % locale** à partir du
`GET /status` **déjà** récupéré au cron par `refreshTelemetry()` : aucun nouvel appel REST/MQTT, aucun
risque anti-ban. **AC2 satisfait par construction.**

Signaux utilisés (racine du `/status`, v4.15+) :
- `kinetic.moving` (bool) → `moving` (0/1) — vélocité instantanée à l'instant du poll.
- `ignition.type` (`Stop`/`StartUp`/`Start`/`Free`) → `ignition` — **persiste** pendant tout le trajet
  (y compris arrêt momentané moteur tournant) : signal de détection robuste contre la fragmentation.
- `odometer.mileage` (float km) → distance (delta odomètre, exact).
- `latitude`/`longitude` (UC31, présents seulement si `privacy.state == None`) → positions départ/arrivée.

## Architecture

**Un seul fichier modifié : `core/class/stellantis.class.php`.** Aucun AJAX, widget, page, démon,
dépendance ; `packages.json` inchangé ; `createCommands()` inchangé (toutes les commandes en création
**paresseuse**, précédent UC21/24/31 — naissent au 1er `/status`/1er trajet qui les porte).

### 1. `parseStatus()` — mapping (seul endroit où vivent les chemins API)
Ajouter, défensif (`isset` + type, champ absent ⇒ clé absente, jamais d'exception) :
```
kinetic.moving  → $valeurs['moving']   = bool ? 1 : 0
ignition.type   → $valeurs['ignition'] = preg_replace('/[^A-Za-z]/','', (string)$type)   // enum assaini, comme charging_status
```
Les deux clés étant dans `$valeurs`, la boucle existante de `refreshTelemetry()`
(`foreach ($valeurs as $logicalId => $valeur) { ensureCommand(...); checkAndUpdateCmd(...); }`) crée
paresseusement les commandes `moving`/`ignition` → **elles DOIVENT être déclarées** dans
`definitionsCommandes()` (sinon `ensureCommand` lève « Commande inconnue »).

### 2. `definitionsCommandes()` — 8 nouvelles commandes info
Format `[nom FR __() littéral, subType, generic_type, unité, historiser]`. `generic_type=''` partout
(convention « on ne devine pas de constante core non vérifiée » ; **pas** de GEOLOC sur les positions de
trajet pour ne pas créer 3 commandes GEOLOC ambiguës — GEOLOC reste réservé à la position « live ` position` »).

| logicalId | nom FR | subType | unité | historisé |
|---|---|---|---|---|
| `moving` | En mouvement | binary | — | false |
| `ignition` | Contact | string | — | false |
| `trip_distance` | Distance dernier trajet | numeric | km | **true** |
| `trip_duration` | Durée dernier trajet | numeric | min | **true** |
| `trip_start` | Départ (heure) | string | — | false |
| `trip_end` | Arrivée (heure) | string | — | false |
| `trip_start_position` | Départ (position) | string | — | false |
| `trip_end_position` | Arrivée (position) | string | — | false |

> **« Historique » = historisation native** : `trip_distance`/`trip_duration` historisés ⇒ l'historique
> Jeedom de ces 2 commandes **EST** l'historique des trajets (AC1). Pas de page récap (le « éventuelle »
> de la spec fonctionnelle) : hors scope.

### 3. Constantes (près des constantes UC24)
```
const TRIP_SESSION_KEY = 'stellantis::trip_session::';  // + eqId (cache) : session en cours JSON
const TRIP_SESSION_TTL = 86400;                         // 24 h — RÉÉCRIT à chaque poll en trajet
const TRIP_IGNITION_ON = array('Start', 'StartUp');     // ignition.type => "en trajet" (insensible casse)
```

### 4. `suivreTrajet(array $_valeurs, array $_status): void`
Orchestration cache+IO, **best-effort, try/catch enveloppant, NE LÈVE JAMAIS** (log warning), appelée en
**fin** de `refreshTelemetry()` **après** `suivreSessionCharge()` (robustesse cron). 1 seule clé cache
(la présence de session encode « était en trajet » → pas de cache anti-spam type UC24 nécessaire).

Machine à états :
1. **Garde signal absent** (advisor #1) : `if (!isset($_valeurs['moving'])) { return; }` — ne rien décider
   (ni ouvrir ni fermer) quand `kinetic.moving` manque ce poll-ci (évite une clôture à tort).
2. **Prédicat « en trajet »** (advisor #2) : `$enTrajet = ((int)$_valeurs['moving'] === 1)` **OU**
   `ignition ∈ TRIP_IGNITION_ON` (comparaison insensible à la casse, seulement si `$_valeurs['ignition']`
   présent). On ne clôture que quand **les deux** signaux disent « arrêté ».
3. Lire la session courante en cache (`json_decode`, auto-guérison `null` si absente/JSON invalide).
4. **Ouverture** (`$enTrajet && session === null`) : lire `mileage` courant ;
   - si `mileage` absent → **différer** (return, pas d'ouverture — start_mileage fiable requis, mirror UC24 « SOC inconnu »).
   - sinon ouvrir `{start_ts: time(), start_mileage, start_lat, start_lon}` (lat/lon seulement si présents),
     `cache::set` (TTL).
5. **Poursuite** (`$enTrajet && session !== null`) : réécrire la session (refresh TTL ; `start_*` **figés**), return.
6. **Clôture** (`!$enTrajet && session !== null`) :
   - lire `mileage` courant. **Si absent → NE PAS clôturer** (return, session conservée, on attend un poll
     avec mileage — évite de perdre un trajet réel sur un trou transitoire d'odomètre, cf. advisor #3).
   - sinon : `calculerRecapTrajet(...)` ;
     - si recap **vide** (distance ≤ 0 ⇒ trajet-fantôme : flicker `moving`/bruit GPS à l'arrêt) →
       `cache::delete`, aucune commande écrite, `log debug`.
     - sinon → **purger la session AVANT** l'écriture (idempotence UC24 : un `!enTrajet` au poll suivant →
       session absente → no-op, pas de doublon), puis `foreach ($recap as $id => $v) { ensureCommand($id); checkAndUpdateCmd(); }`.
7. `!$enTrajet && session === null` → **no-op** silencieux.

### 5. `calculerRecapTrajet(array $_session, int $_endTs, ?float $_endMileage, ?float $_endLat, ?float $_endLon): array`
**PUR/STATIQUE** (aucun accès cache/cmd/config), testable. Contrat « clé absente » façon
`parseStatus`/`calculerRecapSession`. **Garde-fou maître (anti-fantôme)** : un trajet n'est *enregistrable*
que si `start_mileage` et `$_endMileage` sont connus **et** `distance = $_endMileage - start_mileage > 0`.
- Si **non** enregistrable → retourner `array()` (aucun trajet). *(La durée n'est donc PAS écrite pour un
  fantôme — c'est voulu : distance > 0 est le seul critère de « vrai trajet ». La perte d'une durée
  calculable sur trou d'odomètre est déjà évitée par le report de clôture en `suivreTrajet` étape 6.)*
- Si enregistrable → écrire **tous** les champs disponibles (indépendance par champ, esprit UC24) :
  - `trip_distance` = `round(distance, 1)` (km) ;
  - `trip_duration` = `(int) round(max(0, $_endTs - start_ts) / 60)` (min) ;
  - `trip_start` = `date('Y-m-d H:i:s', start_ts)`, `trip_end` = `date('Y-m-d H:i:s', $_endTs)` ;
  - `trip_start_position` = `"lat,lon"` si `start_lat`/`start_lon` en session ; `trip_end_position` = idem si `$_endLat`/`$_endLon`.

## Server vs Client
100 % serveur (PHP, cron). Aucune interaction client : pas d'AJAX, pas de widget, pas de JS. Restitution
via commandes info standard (dashboard/mobile natifs).

## Validation
- **Défensif** : tout accès JSON gardé par `isset` + test de type (`is_numeric`/`is_scalar`) ; champ absent
  ⇒ clé/commande absente, jamais d'exception (contrat `parseStatus`).
- **Robustesse cron** : `suivreTrajet` try/catch enveloppant → un véhicule/poll en erreur n'interrompt pas
  la boucle cron ni le reste du refresh.
- **Anti-fantôme** : distance ≤ 0 ⇒ aucun trajet (flicker à l'arrêt neutralisé).
- **Estimations documentées** (comme UC24) : `start/end_ts` = instant de poll ⇒ durée à ±cadence (5 min) ;
  distance légèrement sous-estimée (odomètre figé au 1er poll en trajet) ; le prédicat combiné
  `moving OU ignition` réduit fortement la fragmentation, mais un arrêt long (> cadence) reste un
  séparateur de trajets (comportement attendu « arrêt→départ »).

## Server Actions / API
Aucune. Aucun appel REST/MQTT neuf. Aucune commande action (uniquement des commandes **info** alimentées
par le cron).

## Dépendances
Aucune. `packages.json` inchangé.

## i18n (FR source — traduction différée au sous-agent `translator`, étape 10)
8 nouveaux libellés `__(..., __FILE__)` littéraux dans `definitionsCommandes()` :
`En mouvement`, `Contact`, `Distance dernier trajet`, `Durée dernier trajet`, `Départ (heure)`,
`Arrivée (heure)`, `Départ (position)`, `Arrivée (position)`.
Clé i18n : `plugins/stellantis/core/class/stellantis.class.php`. **Ne pas** toucher aux `core/i18n/*.json`
pendant le dev (français uniquement enveloppé).

## Tâches de clôture (hygiène)
- Cocher les AC et passer le statut de `33-historique-trajets.md` de « à spécifier » à « implémenté »
  (advisor #7) — fait par l'orchestrateur en fin de cycle.
