# Spec technique — 41 (Kilométrage & entretien)

> Compagnon technique de `41-kilometrage-entretien.md`. Plan validé le 2026-07-12
> (seuils **par véhicule**, cadence `/maintenance` **1×/jour à throttle différencié**).
> 100 % PHP lecture/parsing. **1 nouvel appel REST throttlé**, aucun MQTT, aucun JS/AJAX.

## Contrat API Stellantis/PSA (vérifié 2026-07-12 vs `psa_car_controller`)

**Endpoint dédié** : `GET /user/vehicles/{apiId}/maintenance` (REST OAuth2, même famille d'URI que
`/status` et `/lastPosition` — confirmé dans `docs/api/VehiclesApi.md` de `psa_car_controller`). Passe
par la brique unique `stellantisApi::callWithToken('GET', …)`.

**Réponse** — modèle `MaintenanceObj` de `connected_car_api/models/maintenance_obj.py` :

| Champ JSON | Sens | Type | Remarque |
|---|---|---|---|
| `mileageBeforeMaintenance` | distance avant révision | int (km) | orthographe **correcte** |
| `daysBeforeMaintenace` | jours avant révision | int (j) | ⚠️ **FAUTE DE FRAPPE RÉELLE de l'API** : un seul `n` (`Maintenace`) |
| `createdAt` | horodatage de la ressource | datetime | non exploité (fraîcheur globale déjà couverte par `last_update`) |

**Pièges de contrat confirmés** (source de vérité = code de référence, cf.
`stellantis-implementations-reference.md`) :

1. ⚠️ **Typo `daysBeforeMaintenace`** (un seul `n`) : présente telle quelle dans l'`attribute_map` du
   modèle de référence. `mileageBeforeMaintenance` est, lui, correctement orthographié. → le parser lit
   **les deux** orthographes du champ jours (typo **puis** forme correcte en repli, au cas où un millésime
   la corrige).
2. ⚠️ **Wrapper de réponse incertain** : le modèle `Maintenance` (racine) ne porte qu'un `_links` (HAL),
   et il n'existe **pas** de `maintenance_embedded.py` (contrairement à `alerts_embedded.py` /
   `status_embedded.py`). La position exacte de `MaintenanceObj` dans la réponse runtime n'est donc pas
   fiable → **parsing défensif multi-shape** : `_embedded.maintenance` (shape HAL probable) **puis** la
   racine du document (repli).
3. ⚠️ **Disponibilité NON garantie** : ni `psa_car_controller` ni l'intégration HA la plus maintenue
   (`homeassistant-stellantis-vehicles`) ne **lisent/exposent** réellement ce endpoint — il n'existe que
   dans le client Swagger généré. Corrobore le « souvent absents côté consommateur » de la spec
   fonctionnelle. → **best-effort, jamais fatal, création paresseuse obligatoire** (un véhicule/forfait
   qui ne l'expose pas n'obtient simplement jamais ces commandes).

Ne **pas** confondre avec l'objet `service` du `/status` (`service.type` = **motorisation**
`Electric`/`Hybrid`/`Unknown`, **pas** un rappel d'entretien — cf. `stellantis-data-model.md` § 2.6).

## Architecture

Tous les changements sont dans `core/class/stellantis.class.php` (aucune nouvelle classe/fichier → aucun
risque d'autoload) + 2 champs de formulaire dans `desktop/php/stellantis.php`.

### 1. `definitionsCommandes()` — +3 infos (création PARESSEUSE)

Jamais déclarées dans `createCommands()` (précédent UC21/23/24/31/33) : elles naissent au 1er
`/maintenance` qui les contient, via la boucle `ensureCommand()`/`checkAndUpdateCmd()` de
`suivreMaintenance()`. Libellés **littéraux** dans `__()` (extracteur i18n, piège UC07).

| logicalId | nom FR (littéral) | subType | generic | unité | historisé |
|---|---|---|---|---|---|
| `service_distance` | `Distance avant révision` | numeric | `''` | `km` | **oui** |
| `service_days` | `Jours avant révision` | numeric | `''` | `j` | **oui** |
| `service_due` | `Révision proche` | binary | `''` | `''` | **oui** |

- Historisation `service_distance`/`service_days` = **1 point/jour** (cadencé par le throttle), bon grain
  pour visualiser la décroissance (cas d'usage graphe comparable à `mileage`). **Non redondant** avec
  `mileage` : odomètre absolu vs distance *restante*.
- `service_due` **historisée** (binaire) = déclencheur de scénario (précédent `at_home` UC34). `generic_type=''`
  (convention projet : « on ne devine pas une constante core non vérifiée » ; aucun type générique
  d'entretien fiable).

### 2. `parseMaintenance(array $_maint): array` — pur, statique

Miroir de `parseStatus()` : **champ absent ⇒ clé absente, jamais d'exception**. Seul endroit portant les
chemins JSON `/maintenance`.

```
$obj = (isset($_maint['_embedded']['maintenance']) && is_array(...)) ? $_maint['_embedded']['maintenance'] : $_maint;
// service_distance : mileageBeforeMaintenance (orthographe correcte)
if (isset($obj['mileageBeforeMaintenance']) && is_numeric(...)) $valeurs['service_distance'] = (float) ...;
// service_days : daysBeforeMaintenace (TYPO) PUIS daysBeforeMaintenance (repli correct)
if (isset($obj['daysBeforeMaintenace']) && is_numeric(...))        $jours = (int) ...;
elseif (isset($obj['daysBeforeMaintenance']) && is_numeric(...))   $jours = (int) ...;
```

- **`is_numeric()` strict**, jamais une vérité (`if ($v)`) : `0` (révision due aujourd'hui) est une
  valeur valide, pas une absence (précédent UC31 `heading`/`gps_signal`).
- **Aucun clamp** : un « jours avant » **négatif** = révision **dépassée** (valide) ⇒ `service_due=1`.

### 3. `calculerRevisionProche(array $_valeurs, int $_seuilKm, int $_seuilJours): ?int` — pur, statique

Séparation calcul/IO (précédent `calculerRecapSession`/`calculerRecapTrajet`). Retourne :
- `null` si **aucun** des deux champs (`service_distance`/`service_days`) n'est présent (⇒ pas de
  `service_due` émis) ;
- sinon `1` si `service_distance <= $_seuilKm` **OU** `service_days <= $_seuilJours`, `0` sinon.

⚠️ **`<=`** (et non `<` de la spec fonctionnelle) : choix **conscient**, plus sûr à l'égalité (une
distance exactement au seuil doit déclencher). Déviation documentée ici pour la review « cohérence spec ».

### 4. `suivreMaintenance(string $_apiId): void` — best-effort, appelée EN DERNIER dans `refreshTelemetry()`

Après `suivreGeofencing()` (même convention robustesse : `try/catch` interne, **ne lève jamais**, ne doit
jamais interrompre le refresh). Throttle stocké comme **timestamp de prochaine interrogation autorisée**
(précédent `RATELIMIT_KEY`/`rateLimitRemaining` — robuste quelle que soit la sémantique d'expiration du
cache) :

```
$cle = self::MAINTENANCE_NEXT_KEY . $this->getId();
if ((int) cache::byKey($cle)->getValue(0) > time()) return;      // throttle actif
if (stellantisApi::rateLimitRemaining() > 0) return;             // cooldown 429 : réessai après, SANS poser de throttle
try {
  $maint = stellantisApi::callWithToken('GET', '/user/vehicles/' . $_apiId . '/maintenance');
  cache::set($cle, time() + self::MAINTENANCE_TTL_OK, self::MAINTENANCE_TTL_OK);     // succès : 24 h
  $valeurs = self::parseMaintenance($maint);
  if (empty($valeurs)) return;                                  // endpoint présent mais sans échéance (forfait/modèle) : rien à créer (AC2)
  $seuilKm    = (int) $this->getConfiguration('service_alert_km',   self::SERVICE_ALERT_KM_DEFAUT);
  $seuilJours = (int) $this->getConfiguration('service_alert_days', self::SERVICE_ALERT_DAYS_DEFAUT);
  $due = self::calculerRevisionProche($valeurs, $seuilKm, $seuilJours);
  if ($due !== null) $valeurs['service_due'] = $due;
  foreach ($valeurs as $logicalId => $valeur) { $cmd = $this->ensureCommand($logicalId); $this->checkAndUpdateCmd($cmd, $valeur); }
} catch (stellantisException $e) {
  // 404 = endpoint non supporté par CE véhicule/forfait → throttle LONG (évite d'interroger un endpoint
  // mort + bruit de log). Erreur transitoire (5xx/timeout/token) → throttle COURT (réessai le jour même).
  $ttl = ($e->getHttpCode() == 404) ? self::MAINTENANCE_TTL_ABSENT : self::MAINTENANCE_TTL_ERREUR;
  cache::set($cle, time() + $ttl, $ttl);
  log::add('stellantis', 'info', 'Échéances d\'entretien non récupérées (' . $e->getApiType() . ') — poursuite');
}
```

Un throttle est **toujours** posé (succès + toute branche `catch`) ⇒ aucun retry-storm ; la garde
`rateLimitRemaining()` est le **seul** cas de sortie sans throttle (le cooldown 429 est lui-même borné).
⚠️ Vérifier la manière dont `stellantisApi::call()` remonte un HTTP 404 (code via `getHttpCode()`) et
ajuster le test si le mapping diffère.

### 5. Constantes (près des autres `*_KEY`/TTL de la classe)

```
const MAINTENANCE_NEXT_KEY  = 'stellantis::maintenance_next::'; // + eqId (cache) : timestamp de prochaine interrogation /maintenance autorisée
const MAINTENANCE_TTL_OK     = 86400;   // 24 h — cadence nominale (donnée à évolution lente, anti-ban)
const MAINTENANCE_TTL_ABSENT = 604800;  // 7 j  — endpoint 404 (non supporté par ce véhicule/forfait)
const MAINTENANCE_TTL_ERREUR = 10800;   // 3 h  — erreur transitoire (réessai le jour même, sans storm)
const SERVICE_ALERT_KM_DEFAUT   = 1000; // km   — seuil « révision proche » par défaut (repli si config véhicule vide)
const SERVICE_ALERT_DAYS_DEFAUT = 30;   // j    — idem, jours
```

### 6. `desktop/php/stellantis.php` — 2 champs config PAR VÉHICULE

Précédent **exact** UC24 (`battery_capacity`/`charge_tarif`, l.198-213) : champs **éditables** (pas
readonly), `class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="…"`. ⚠️ Une clé de
config **absente** du formulaire est **effacée au Sauvegarder** (cf. `jeedom-eqlogic-sync-persist.md`) →
présence obligatoire.

- `service_alert_km`   : label `Seuil d'alerte révision (km)`,   `<input type="number" min="0" step="1">`
- `service_alert_days` : label `Seuil d'alerte révision (jours)`, `<input type="number" min="0" step="1">`

Tooltip expliquant le repli sur défaut si vide (1000 km / 30 j). **Aucune modification de
`configuration.txt`/`.php`** (les seuils sont per-véhicule, pas plugin) → pas de `cp` de sync.

## Server vs Client

100 % serveur. Les 2 champs de config sont auto-load/save par le core (`eqLogicAttr`) — aucun JS/AJAX
nouveau. Aucune commande action, aucun MQTT.

## Validation

- **Serveur** : `is_numeric` défensif, pas de clamp (négatif valide), `service_due` seulement si ≥1 champ
  présent. Best-effort/`try/catch` (robustesse cron : un véhicule en erreur n'interrompt pas la boucle).
- **Client** : aucune (champs config gérés par le core).

## Server Actions / API

Aucune. `service_due` (binary historisée) est restitué via **scénario Jeedom natif** (déclencheur sur
changement d'état — précédent `at_home` UC34) ⇒ satisfait « alerte révision proche possible » **sans**
`message::add` (pas de spam d'alerte).

## Dépendances

Aucune (aucun paquet, aucune extension PHP).

## Critères d'acceptation

- **AC1** (km historisé/lisible) : **déjà** satisfait par MVP07 (`mileage` historisé, `definitionsCommandes`).
  UC41 le vérifie/ne le régresse pas.
- **AC2** (échéance remontée + alerte possible si l'API la fournit) : `service_distance`/`service_days`/
  `service_due` créées **conditionnellement** (uniquement si l'API renvoie les données) + alerte via
  scénario natif sur `service_due`.

## Recette manuelle (à ajouter à `81-validation-manuelle.md`)

Scénario UC41 : vérifier que `mileage` s'historise ; sur un véhicule dont le forfait expose l'entretien,
vérifier l'apparition de `service_distance`/`service_days` après ≤ 1 cycle throttle, le calcul de
`service_due` selon les seuils du formulaire véhicule, et l'absence totale de ces commandes sur un
véhicule qui n'expose pas `/maintenance`.
