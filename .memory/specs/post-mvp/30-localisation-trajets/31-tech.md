# Spec technique — 31 (Position GPS)

> Enrichissement **100 % lecture/parsing** de la position, dérivé du GeoJSON `/lastPosition` **déjà
> récupéré au cron** (MVP/07). Aucun nouvel appel réseau/MQTT, aucune dépendance, aucun AJAX, aucun champ
> de config, aucune migration. Même forme que UC21/23/24 (télémétrie dérivée, création paresseuse).

## Contrat API Stellantis/PSA (rappel — inchangé)
- `GET /user/vehicles/{apiId}/lastPosition` (GeoJSON), **déjà appelé** par `refreshTelemetry()` et gardé
  par `privacy.state == 'None'` (try/catch ⇒ `position = null` en cas d'échec, jamais d'erreur). Résultat
  passé à `parseStatus($status, $position)`. **On ne touche ni à l'appel ni au garde privacy.**
- Structure GeoJSON (data-model § 2.2, confidence high — corroborée par le code existant qui lit déjà
  `properties.createdAt`) :
  - `geometry.coordinates = [longitude, latitude, altitude?]` — ⚠️ **ordre lon,lat** (`[0]`=lon, `[1]`=lat).
  - `properties.heading` (0-360°), `.signalQuality` (0-9), `.createdAt` (RFC3339), `.type`, `.fixStatus`.
- Altitude (`coordinates[2]`) **hors périmètre** (non listée dans la spec fonctionnelle) — le garde
  `count($coords) >= 2` la tolère sans l'exploiter.

## Architecture
Un seul fichier : **`core/class/stellantis.class.php`**. `position` (MVP/07) et l'appel `/lastPosition`
restent **inchangés** ; on dérive 5 commandes info du même GeoJSON déjà en main.

### 1. `definitionsCommandes()` — +5 entrées
Table `logicalId => [nom FR __(), subType, generic_type, unité, historiser]` :

| logicalId | Nom FR (littéral `__()`) | subType | generic_type | unité | histo. |
|---|---|---|---|---|---|
| `latitude` | Latitude | numeric | `''` | `''` | false |
| `longitude` | Longitude | numeric | `''` | `''` | false |
| `heading` | Cap | numeric | `''` | `°` | false |
| `position_updated` | Position (mise à jour) | string | `''` | `''` | false |
| `gps_signal` | Qualité du signal GPS | numeric | `''` | `''` | false |

- `generic_type = ''` : Jeedom n'expose pas de type générique par axe lat/lon (seul `GEOLOC` existe, déjà
  porté par `position`) → convention projet « on ne devine pas de constante core non vérifiée ».
- Non-historisées, cohérent avec `position`/`last_update`. L'historisation d'une trace lat/lon relève des
  UC trajets (32+), pas d'UC31 — à revisiter là-bas.

### 2. `parseStatus()` — enrichir le bloc position existant
Dans le `if ($_position !== null)` déjà présent (ex-lignes ~910-917). **Deux gardes INDÉPENDANTES**
(ne pas imbriquer les champs `properties.*` sous le garde des coordonnées) :

**a. Coordonnées** (dans le garde `is_array($coords) && count($coords) >= 2 && is_numeric($coords[0]) &&
is_numeric($coords[1])` déjà utilisé pour `position`) :
- `$lat = (float) $coords[1]; $lon = (float) $coords[0];`
- `position` = `$lat . ',' . $lon` (inchangé), `latitude` = `$lat`, `longitude` = `$lon`.

**b. Propriétés** (garde séparé `properties` en tableau ; chaque champ gardé individuellement) :
- `heading` = `(float) properties.heading` **si `is_numeric`** (ne pas tester la vérité : `0` est valide).
- `gps_signal` = `(int) properties.signalQuality` **si `is_numeric`** (`0` valide).
- `position_updated` = `formaterDateApi((string) properties.createdAt)` si `createdAt` est une chaîne non
  vide ET que le helper renvoie non-null. **Horodatage propre à la position**, distinct du `last_update`
  global (AC : la position a son propre horodatage).

Contrat conservé : champ absent/non conforme ⇒ clé absente, **jamais d'exception**.

### 3. Helper pur `formaterDateApi(string): ?string` (nouveau, privé statique)
```
$ts = strtotime($_iso);
return $ts === false ? null : date('Y-m-d H:i:s', $ts);
```
Garde `!== false` obligatoire **avant** `date()` (sinon `TypeError` PHP 8 / epoch fabriquée PHP 7.4 →
exception dans `parseStatus`, qui n'a pas de try/catch par champ ⇒ ferait échouer **tout** le refresh du
véhicule). Utilisé par `position_updated`. `extraireFraicheur()` **n'est pas refactorisé** (il compare des
timestamps `int`, pas de format — point commun trop mince, churn non justifiée).

### 4. `createCommands()` — INCHANGÉ
Les 5 commandes naissent en **création paresseuse** : `parseStatus` émet la clé quand le champ est présent,
la boucle `foreach ($valeurs as $lid => $v) { $cmd = $this->ensureCommand($lid); ... }` de
`refreshTelemetry()` les crée au 1er `/status` positionné (précédent strict UC21/23/24). ⚠️ Les 5
logicalId **doivent** figurer dans `definitionsCommandes()` sinon `ensureCommand()` lève une exception
(fatal runtime, invisible à `php -l`).

> **Asymétrie eager/lazy assumée** : `position` reste eager (socle MVP/07, dans `createCommands`), les 5
> nouvelles sont lazy. Un véhicule en privacy permanente (jamais de `lastPosition` réussi) affiche
> « Position » (vide) mais jamais les 5 autres. Cohérent avec le précédent UC21 (socle eager + détail
> lazy) — à commenter en code pour ne pas passer pour un oubli.

## Server vs Client
100 % serveur (parsing PHP dans le cron). Aucun code client : les commandes info s'affichent nativement.

## Validation
- **Serveur** : parsing pur et défensif (`is_array`/`count>=2`/`is_numeric`/`is_string`/`strtotime !==
  false`) ; aucune exception ; inversion lon→lat déjà éprouvée (MVP/07).
- **Position absente / privacy / pas de fix** : `$_position === null` ⇒ bloc entier sauté ⇒ 0 clé émise
  (AC3). Coordonnées ou propriétés manquantes ⇒ seules les clés valides sont émises.
- **Client** : aucune.

## Server Actions / API
Aucune. Pas d'AJAX, pas de commande action, pas de MQTT, pas de champ de config, pas de migration
`install.php` (aucune commande existante repurposée ; `position` inchangé).

## Dépendances
Aucune.

## Impact i18n (FR uniquement — traduction différée)
5 clés `__()` neuves : `Latitude`, `Longitude`, `Cap`, `Position (mise à jour)`, `Qualité du signal GPS`.
Chaînes littérales dans `definitionsCommandes()` (extracteur statique). Ne pas toucher les `*.json`.

## Critères d'acceptation → couverture
- **AC1** (latitude/longitude/position reflètent lastPosition et se rafraîchissent au cron) : les 3 clés
  émises dans le même bloc, mises à jour à chaque passage de `refreshTelemetry`.
- **AC2** (ordre lon,lat inversé en lat,lon) : `latitude=coords[1]`, `longitude=coords[0]`, `position` déjà
  correct.
- **AC3** (position absente gérée proprement) : garde privacy + try/catch amont + gardes défensives par
  champ ⇒ aucune erreur, aucune clé fantôme.
