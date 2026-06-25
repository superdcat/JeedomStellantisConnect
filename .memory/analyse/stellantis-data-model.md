# Modèle de données — télémétrie véhicule Stellantis/PSA (`connected_car v4`)

> Référence pour décider **quelles commandes info Jeedom créer** (UC MVP/07, énergie/20, entretien/40…).
> Source : `GET /user/vehicles/{id}/status`, `/lastPosition`, `/alerts`, `/maintenance` (base **consommateur**
> `https://api.groupe-psa.com/connectedcar/v4`), recoupé avec les **modèles `psa_car_controller`**
> (`psa/connected_car_api/models/*`), des **dumps JSON réels** (issues GitHub #1121 e-C4 sept-2025,
> #811 e-208, #393, #839), le **binding openHAB** et l'intégration HA `homeassistant-stellantis-vehicles`.
>
> ⚠️ **Fiabilité** : les chemins/enums ci-dessous viennent de dumps réels + code de référence
> (`confidence high`), mais **varient selon le millésime, la motorisation et le forfait connecté**
> (Connect Box / Connect Plus / e-Remote). **Toujours coder défensif** (champ absent ⇒ ne pas créer la
> commande, jamais d'erreur fatale) et reconfirmer au runtime sur un vrai véhicule.

---

## 0. Principes de mapping + 3 pièges majeurs

- **1 véhicule = 1 eqLogic**, clé `logicalId = VIN`. L'`id` API (≠ VIN, renvoyé par `/user/vehicles`)
  sert aux appels REST `/status`, `/lastPosition`, `/alerts`, `/maintenance`.
- ⚠️ **Piège 1 — schéma double depuis v4.15.1 (oct. 2023)** : les objets **`energy`, `preconditioning`,
  `service` sont dépréciés** au profit de **`energies`** (avec `extension.electric`), **`preconditionning`**
  (orthographe à **double n** !), `alarm`, `powertrain`, `engines`, `drivingBehavior`, `lightingSystem`.
  Les deux schémas **coexistent** dans les réponses actuelles → lire `energies[]` en priorité avec
  **fallback** `energy[]`. `odometer` et `kinetic` sont **promus à la racine** du status.
- ⚠️ **Piège 2 — `?extension=odometer`/`?extension=kinetic` renvoie HTTP 400** (`Invalid parameter:
  X-MPHSource`) depuis v4.15 (issue #678). Ne **plus** passer ces paramètres : les champs sont à la racine.
- ⚠️ **Piège 3 — orthographe `preconditionning` (double n)** dans la réponse API réelle (≠ modèle
  Swagger `preconditioning`). Utiliser la **double n** pour l'accès au tableau JSON en PHP.

---

## 1. Liste des véhicules — `GET /user/vehicles` (HAL)

`_embedded.vehicles[]` → `{ id (id API), vin, brand, label, engine/motorization, pictures, _links }`.
`id` ≠ VIN. Stocker `id` (config eqLogic `apiId`) et `vin` (`logicalId`).

## 2. Statut — `GET /user/vehicles/{id}/status`

### 2.1 Énergie / batterie / charge
| Champ (consommateur) | Sens | Unité/enum | Commande info | generic_type |
|---|---|---|---|---|
| `energy[0].type` / `energies[0].type` | type énergie | `Electric` / `Fuel` | (route le mapping) | — |
| `energy[0].level` | **SOC** (élec) ou niveau (carburant) | float % 0-100 | `battery_soc` / `fuel_level` | `BATTERY` |
| `energy[0].autonomy` | autonomie | float km | `autonomy` / `autonomy_fuel` | — |
| `energy[0].updated_at` | horodatage énergie | RFC3339 | (fraîcheur) | — |
| `energy[0].charging.status` | état charge | `Disconnected`/`InProgress`/`Finished`/`Failure`/`Stopped` | `charging_status` | — |
| `energy[0].charging.plugged` | câble branché | bool | `charging_plugged` | `PRESENCE` |
| `energy[0].charging.charging_mode` | mode | `No`/`Slow`/`Quick` | `charging_mode` | — |
| `energy[0].charging.charging_rate` | vitesse | int km/h (0-500) | `charging_rate` | — |
| `energy[0].charging.remaining_time` | temps restant | ISO 8601 `PT1H30M` | `charging_remaining` | — |
| `energy[0].charging.next_delayed_time` | charge différée programmée | RFC3339 | `charge_next_time` | — |
| `energy[0].battery.capacity` | capacité nominale | float Wh | `battery_capacity` | — |
| `energy[0].battery.health.capacity` / `.resistance` | **SOH** (santé) | float % | `battery_soh` | — |
| `energies[0].extension.electric.battery.load.capacity` / `.residual` | capacité / énergie restante (v4.15+) | int Wh | `battery_residual` | — |

> ⚠️ **SOH (`battery.health.*`) n'est renseigné QUE pendant/juste après une charge** (null sinon) →
> le mettre en cache avec horodatage (cf. UC21/24). Exemple réel e-C4 : `extension.electric.battery.load`
> = `capacity 36384 Wh / residual 21856 Wh` à 69 % SOC.
> **PHEV** : `energy[]` a **2 entrées** — `[0]=Electric` (SOC), `[1]=Fuel` (niveau).

### 2.2 Position — `GET /user/vehicles/{id}/lastPosition` (GeoJSON)
- ⚠️ `geometry.coordinates = [longitude, latitude, altitude]` — **ordre GeoJSON, PAS [lat,lon]** ! (lire `[0]`=lon, `[1]`=lat).
- `properties.heading` (0-360°), `.signalQuality` (0-9), `.type` (`Acquire`/`Estimated`), `.createdAt` (RFC3339), `.fixStatus` (`3D`).
- → `position` (`string "lat,lon"`, `GEOLOC`), `heading`, `position_updated`, `gps_signal`.

### 2.3 Cinétique & odomètre (racine depuis v4.15)
- `kinetic.moving` (bool) → `moving` ; `kinetic.speed` (km/h), `.acceleration` (m/s²), `.pace`.
- `odometer.mileage` (float km) → `mileage`.
- `ignition.type` (`Stop`/`StartUp`/`Start`/`Free`) → `ignition`.

### 2.4 Ouvrants & verrouillage (`doors_state`)
- `doors_state.locked_state` (liste) : `Locked`/`Unlocked`/`SuperLocked`/`DriverDoorUnlocked`/
  `CabinDoorsUnlocked`/`CargoDoorsLocked`/`CargoDoorsUnlocked`/`RearDoorsUnlocked`/`RearDoorsLocked` → `doors_locked`.
- `doors_state.opening[n].identifier` (`Driver`/`Passenger`/`RearLeft`/`RearRight`/`Trunk`/`RearWindow`/`RoofWindow`)
  + `.state` (`Open`/`Closed`) → `door_<id>` (`OPENING`) (UC44).

### 2.5 Préconditionnement (`preconditionning.airConditioning` — **double n**)
- `.status` (`Enabled`/`Disabled`/`Finished`/`Failure`), `.failure_cause`
  (`Defect`/`DoorOpened`/`LowBattery`/`LowFuelLevel`/`TooManyUnusedProg`/`WindowsRoofOpened`).
- `.programs[n]` : `slot` (1-4), `enabled` (bool), `start` (ISO 8601), `occurence.day` (array 7 int [Lun..Dim]).
- → `precond_status`, `precond_program`.

### 2.6 Environnement, sécurité, confidentialité, 12 V
- `environment.air.temp` (°C) → `air_temp` ; `environment.luminosity.day` (bool) → `day_night`.
- `safety.beltWarning` (`Normal`/`Omission`), `safety.eCallTriggeringRequest`.
- ⚠️ `privacy.state` (`None`/`Geolocation`/`Full`) : si **≠ `None`**, la **position est indisponible** →
  **vérifier avant** de lire `lastPosition` (cf. UC75) → `privacy_mode`.
- `battery.voltage` (V) / `battery.current` (A, souvent null) = **batterie 12 V de servitude** → `battery_12v`.
  ⚠️ Dump e-C4 = `63.5` : à confirmer (unité 0.1 V ? ou 48 V selon modèle).
- `service.type` = **motorisation** (`Electric`/`Hybrid`/`Unknown`), **PAS** un rappel d'entretien.

## 3. Autres endpoints

- **`GET /user/vehicles/{id}/alerts`** : alertes actives (`alerts[].{id, type (AlertMsgEnum), active,
  started_at, end_at}`). **AlertMsgEnum ~80 types** : **pression pneus** (`tyreUnderInflation`,
  `underInflationTyreFault`, `wheelPressureFault`, `*TyreNotMonitored`…), moteur (`engineFault`,
  `hybridSystemFault`, `alertOilPressure`), carburant (`fuelLevelAlarm`, `antipollutionFault`,
  `riskOfParticleFilterBlockage`), portes ouvertes en roulant, freinage (`alertBrakeFluid`, `absFault`),
  éclairage. → UC42/43.
  > ⚠️ **La pression pneus NUMÉRIQUE est ABSENTE de `/status`** : seules des **alertes booléennes** via
  > `/alerts` existent. UC42 doit mapper des binaires, pas des valeurs en bar.
- **`GET /user/vehicles/{id}/maintenance`** : prochaine révision / distance avant entretien → UC41.
- **`POST /user/vehicles/{id}/monitors`** : webhook push — **API B2C officielle uniquement** (inaccessible).

## 4. Conséquences pour la roadmap

- **MVP/07** crée le socle d'infos présentes partout : `battery_soc`/`fuel_level`, `autonomy`,
  `charging_status`/`charging_plugged`, `mileage`, `doors_locked`, `position`, `last_update`. Le reste
  (SOH, charge détaillée, ouvrants, préconditionnement, alertes/pneus) → UC dédiées, **création
  conditionnelle** selon `energy[].type` et présence effective.
- Isoler tout le mapping de champs dans `stellantis::parseStatus()` (un seul endroit à corriger).
- Les **commandes action** (charge, précond, portes…) passent par **MQTT** (post-MVP démon), pas ici —
  cf. `[[stellantis-api-architecture]]` § 1.3.

## Sources
- `psa_car_controller` `psa/connected_car_api/models/*`, `api_spec.md` — https://github.com/flobz/psa_car_controller
- Dumps réels : issues #1121 (e-C4 v4.15+), #811 (payload MQTT e-208), #393, #839 (SOH), #678 (extension 400), discussion #700 (changelog v4.15.1)
- openHAB GroupePSA binding (channels typés) : https://www.openhab.org/addons/bindings/groupepsa/
- HA `homeassistant-stellantis-vehicles` (`const.py`, `configs.json`) : https://github.com/andreadegiovine/homeassistant-stellantis-vehicles
