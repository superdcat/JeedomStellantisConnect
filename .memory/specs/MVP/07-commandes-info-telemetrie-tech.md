# Spec technique — MVP 07 — Commandes info de télémétrie

> Référence fonctionnelle : `07-commandes-info-telemetrie.md`. Dépend de MVP 06 (`syncVehicles()`,
> config `apiId`/`energy`). Mapping des champs : `.memory/analyse/stellantis-data-model.md` (confidence
> high, dumps réels). Plan validé le **2026-07-07** (advisor `code-reviewer` + vérif source core Jeedom).

## Contrat API (vérifié — data-model + README statut « confirmé »)
- `GET /user/vehicles/{apiId}/status` → énergie, odomètre, ouvrants, privacy, horodatages.
  - **Piège v4.15** : lire `energies[]` en priorité, **fallback** `energy[]` (les deux coexistent).
    Sous-champs identiques : `[i].type` (`Electric`/`Fuel`), `[i].level`, `[i].autonomy`,
    `[i].updated_at`, `[i].charging.status`, `[i].charging.plugged`. **PHEV** = 2 entrées ([0] Electric,
    [1] Fuel).
  - `odometer.mileage` (racine depuis v4.15). **Ne jamais passer `?extension=…`** (HTTP 400).
  - `doors_state.locked_state` (liste d'enums `Locked`/`SuperLocked`/`*Unlocked`/…).
  - `privacy.state` (`None`/`Geolocation`/`Full`) : si **≠ `None`**, position indisponible → ne pas
    appeler `/lastPosition`.
- `GET /user/vehicles/{apiId}/lastPosition` (GeoJSON) → `geometry.coordinates = [lon, lat, alt]`
  (**ordre GeoJSON, PAS [lat,lon]** : lire `[1]`=lat, `[0]`=lon), `properties.createdAt`.
- Tous via `stellantisApi::callWithToken('GET', $path)` (aucun cURL épars).

## Signatures core Jeedom (vérifiées contre `jeedom/core`, 2026-07-07)
- `eqLogic::getCmd($_type = null, $_logicalId = null, $_visible = null, $_multiple = false)` — le 3ᵉ arg
  est `$_visible` (pas `$_multiple`) → utiliser `getCmd('info', $logicalId)` (2 args). Retourne l'objet
  cmd ou `false`.
- `eqLogic::checkAndUpdateCmd($_logicalId, $_value, $_updateTime = null)` — accepte un **objet cmd** ou un
  logicalId string ; **si logicalId introuvable → retourne `false` SANS créer**. ⚠️ D'où l'ordre
  impératif : **`ensureCommand()` d'abord** (crée si absente), **puis** `checkAndUpdateCmd($cmdObjet, …)`
  en passant l'objet. `$_updateTime` (dater la collecte à la fraîcheur API) : non utilisé au MVP, réservé.

## Architecture — `core/class/stellantis.class.php` uniquement
Aucun changement AJAX/JS : les commandes info sont rendues par le core dans l'onglet « Commandes ».

### Classe `stellantis`
1. `private static function definitionsCommandes(): array` — **table unique** (source de vérité,
   isolée) `logicalId => ['nom', subType, generic_type, unité, historiser(bool)]` :
   | logicalId | nom (FR) | subType | generic_type | unité | histo |
   |---|---|---|---|---|---|
   | `battery_soc` | Batterie | numeric | BATTERY | % | oui |
   | `autonomy` | Autonomie | numeric | — | km | oui |
   | `charging_status` | État de charge | string | — | — | non |
   | `charging_plugged` | Câble branché | binary | PRESENCE | — | non |
   | `fuel_level` | Carburant | numeric | — | % | oui |
   | `mileage` | Kilométrage | numeric | — | km | oui |
   | `doors_locked` | Verrouillage | binary | — | — | non |
   | `position` | Position | string | GEOLOC | — | non |
   | `last_update` | Dernière MAJ | string | — | — | non |
2. `public function createCommands(): void` — **idempotent** (`getCmd('info', $logicalId)`), socle
   **conditionnel à la motorisation** (config `energy`) : toujours `mileage`/`doors_locked`/`position`/
   `last_update` ; Electric|Hybrid → `battery_soc`/`autonomy`/`charging_status`/`charging_plugged` ;
   Thermal|Hybrid → `fuel_level` (+ `autonomy`). Motorisation `''` inconnue → socle universel seul (le
   reste sera créé paresseusement par `ensureCommand()` au 1er `/status`). Appelle `ensureCommand()`.
3. `public static function parseStatus(array $status, ?array $position = null): array` — mapping **pur,
   défensif** `logicalId => valeur` (champ absent ⇒ clé absente, jamais d'exception) :
   - Énergie : `$energies = $status['energies'] ?? $status['energy'] ?? []` ; router par `type`
     (comparaison **insensible casse**) : `Electric` → `battery_soc`(level), `autonomy`,
     `charging_status`, `charging_plugged`(bool→0/1) ; `Fuel` → `fuel_level`(level), `autonomy` **si pas
     déjà posée** (PHEV : électrique prioritaire — scission `autonomy_fuel` = post-MVP/23).
   - `mileage` = `odometer.mileage` (racine).
   - `doors_locked` via helper `extraireVerrouillage()` : parcourir la liste `doors_state.locked_state` ;
     **règle documentée** : si un élément contient `Unlocked` (insensible casse) → `0` (déverrouillé) ;
     sinon si un élément contient `Locked` → `1` ; liste vide/absente → clé absente.
   - `position` : si `$position` fourni, `coordinates=[lon,lat]` → `"lat,lon"` (= `coords[1].','.coords[0]`).
   - `last_update` via helper `extraireFraicheur($status, $position)` : **horodatage API** — racine
     `updatedAt`/`lastUpdate` sinon le plus récent des `energies[]/energy[] .updated_at` et position
     `properties.createdAt` ; **repli sur l'heure du fetch (`date('Y-m-d H:i:s')`) UNIQUEMENT si aucun
     champ date** (log `debug`). Conforme UC09 (la donnée peut être ancienne sans wakeup).
4. `public function refreshTelemetry(): void` — 1 véhicule (`@throws stellantisException`) :
   - `apiId` vide → warning + return (pas d'appel).
   - `GET /status`. `privacy.state == None` → `GET /lastPosition` (dans try/catch : une position en
     erreur ne casse pas le reste, log `info`).
   - **Self-heal motorisation** : `energieDepuisStatus($energies)` (Electric/Fuel → `Electric`/`Thermal`/
     `Hybrid`) ; si plus précise que `configuration('energy')` et **non vide** (jamais régresser vers `''`)
     → `setConfiguration('energy', …)` + `save()` + `createCommands()`.
   - `parseStatus()` → pour chaque `logicalId => valeur` : `$cmd = ensureCommand($logicalId)` puis
     `checkAndUpdateCmd($cmd, $valeur)`.
5. `private function ensureCommand(string $logicalId): stellantisCmd` — `getCmd('info', $logicalId)` ;
   si absent, `new stellantisCmd()` avec `setEqLogic_id`/`setLogicalId`/`setName(__(nom, __FILE__))`/
   `setType('info')`/`setSubType`/`setGeneric_type`/`setUnite`/`setIsVisible(1)`/`setIsHistorized` puis
   `save()`. Nom inconnu (hors table) → exception interne (ne devrait pas arriver).
6. `private static function energieDepuisStatus(array $energies): string` — même vocabulaire que
   `energieDepuisEngine()` (UC05) : présence `Electric`→élec, `Fuel`→thermique, deux→`Hybrid`, sinon `''`.

### Intégration dans `syncVehicles()` (UC06)
Dans le `try/catch` par véhicule existant, **après `$eqLogic->save()`** (hors `postSave` → pas de
récursion) : `$eqLogic->createCommands();` puis `$eqLogic->refreshTelemetry();`. **Choix assumé** :
couple UC06/UC07 pour peupler les valeurs dès le clic « Synchroniser » (best-effort ; la boucle
périodique reste UC08). Généraliser le **message du `catch`** (« erreur lors de la synchronisation » au
lieu de « erreur de sauvegarde ») car il englobe désormais les erreurs API de `refreshTelemetry`.
⚠️ Coût : jusqu'à `1 + 2N` appels API par clic Synchroniser (N véhicules) — acceptable (déclenchement
manuel admin, cooldown 15 s). Guardrail à revoir si le parc grandit (cf. UC72/77).

## Server vs Client
100 % serveur (classe). Pas d'UI/AJAX/JS.

## Validation
- Défensif partout (`isset`/`is_array`/`is_numeric`), motorisation via config `energy` + self-heal `/status`.
- Idempotence : `getCmd('info', $logicalId)` (clé unique par eqLogic) → pas de doublon de commande.
- Robustesse : `refreshTelemetry` dans le try/catch par véhicule ; `createCommands` (sans appel API) le
  précède → les commandes existent même si `/status` échoue.
- **Self-heal non régressif** (post-review) : n'écrit `configuration('energy')` que si la motorisation
  déduite du `/status` est de **rang strictement supérieur** (`rangMotorisation()` : `'' < Electric =
  Thermal < Hybrid`) — évite une régression `Hybrid → Electric` sur un `/status` PHEV ponctuellement
  partiel, et toute bascule latérale.
- **i18n (post-review)** : les noms de commandes sont enveloppés `__('…', __FILE__)` avec une chaîne
  **littérale** directement dans `definitionsCommandes()` (jamais `__($variable)`), sinon l'extracteur
  du sous-agent `translator` ne les capterait pas. `ensureCommand()` fait `setName($nom)` (déjà traduit).
- **`charging_status` (défense en profondeur, post-review sécu)** : seule valeur texte non contrainte →
  `preg_replace('/[^A-Za-z]/', '', …)` (non-lossy pour les enums, neutralise toute injection).
- **Tests** : `parseStatus()` est pure/testable mais **aucun test unitaire** n'est ajouté — pas d'infra
  de test PHP locale sur ce plugin (cohérent avec le reste ; validation en recette + CI Jeedom).
  Limitation acceptée.

## Server Actions / API
```php
stellantis::parseStatus(array $status, ?array $position = null): array   // pur, testable
stellantis->createCommands(): void                                       // idempotent, motorisation
stellantis->refreshTelemetry(): void                                     // throws stellantisException
```

## Dépendances
Aucune (100 % PHP).
```
