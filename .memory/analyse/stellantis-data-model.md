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

`_embedded.vehicles[]` → `{ id (id API), vin, brand, label, engine, pictures, createdAt, _links }`
(précisé 2026-07-06 contre `models/vehicle.py` : **pas** de champ `model`/`motorization` ; `label` =
surnom renommable côté app ; `engine` = liste de `{class: Thermic|Electric, energy: GPL|Gasoil|Petrol|
Biologic}` — `energy` absent sur Electric).
`id` ≠ VIN. Stocker `id` (config eqLogic `apiId`) et `vin` (`logicalId`).

> **Vocabulaire motorisation normalisé du plugin** (décision UC05, 2026-07-06) :
> `Electric` / `Thermal` / `Hybrid` / `''` (inconnu) — dérivé par **présence** des classes dans
> `engine[]` (comparaison insensible à la casse). L'UC07 mappe `energies[].type` du `/status`
> (`Fuel`/`Electric`) vers **ce même vocabulaire** (`Fuel → Thermal`) : une seule table de
> correspondance (`stellantis::energieDepuisEngine()` et équivalent statut), jamais deux enums
> parallèles. Le `energy` stocké à la découverte est indicatif ; le `/status` fait foi au fil de l'eau.

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
| `energy[0].charging.next_delayed_time` | charge différée programmée | RFC3339 **ou** durée `PT..H..M..S` ⚠️ | `charge_next_time` | — |
| `energy[0].battery.capacity` | capacité nominale | float Wh | `battery_capacity` | — |
| `energy[0].battery.health.capacity` / `.resistance` | **SOH** (santé) | float % | `battery_soh` | — |
| `energies[0].extension.electric.battery.load.capacity` / `.residual` | capacité / énergie restante (v4.15+) | int Wh | `battery_residual` | — |

> ⚠️ **`next_delayed_time` — format ambigu (vérifié UC14, 2026-07-09)** : le modèle swagger de
> `psa_car_controller` (`connected_car_api/models/energy_charging.py`) le **déclare RFC3339**, mais son
> propre `common/utils.parse_hour` fait `s[2:]` (strip `PT`) → attend une **durée ISO8601 `PTxxHxxMxxS`**.
> Sur un véhicule renvoyant du RFC3339, `parse_hour` **échoue et renvoie `None`** (try/except), et
> `RemoteClient.charge_now` publie alors `{"program":{"hour":null,"minute":null},"type":…}` — **et la
> commande de charge fonctionne quand même** : dans le payload MQTT `/VehCharge`, seul `type`
> (`immediate`/`delayed`) est opérant ; `program` n'est pas strictement requis pour un simple start/stop
> (il ne l'est que pour **reprogrammer** l'heure → UC22). Le plugin (UC14) parse donc les **deux** formats
> (`stellantis::parseHeureIso`) et ne s'appuie sur l'heure que pour *préserver* la programmation lors d'un
> « arrêter » (delayed). Détail : `.memory/specs/post-mvp/10-commandes-distance/14-tech.md`.

> ⚠️ **Programmation de la charge : SEULE l'heure est pilotable, PAS de seuil % (target SoC) — vérifié
> UC22, 2026-07-11 vs `psa_car_controller/psa/RemoteClient.py` master.** Reprogrammer l'heure de charge
> différée = `RemoteClient.change_charge_hour(vin, hour, minute)` → `veh_charge_request(..., DELAYED_CHARGE)`
> → **même** service/payload que `charge_stop` : `/VehCharge` `{"program":{"hour","minute"},"type":"delayed"}`,
> seule l'heure change. **AUCUN payload de charge de `RemoteClient` (`charge_now`/`veh_charge_request`/
> `change_charge_hour`) ne porte de pourcentage / SoC cible / charge-limit** : le `?percentage=` de
> l'endpoint Flask **local** de PSACC et l'entité `charge_limit` de l'intégration HA sont des fonctions
> **logicielles locales** (arrêt auto côté logiciel qui surveille le SOC), **pas** une commande véhicule.
> ⇒ un futur UC « seuil de charge » n'est **pas réalisable** via le contrat consommateur MQTT (à ne pas
> reconfondre avec le pré-requis « batterie > ~50 % » de `stellantis-api-architecture.md` § 1.4, qui est
> une condition d'acceptation côté serveur, pas un paramètre réglable). ⚠️ Effet de bord de `type:"delayed"` :
> repasser en différé **interrompt une charge immédiate en cours** (même mécanisme que `charge_stop`).
> Détail : `.memory/specs/post-mvp/20-energie-charge/22-tech.md`.

> ⚠️ **`remaining_time` = DURÉE, ≠ `next_delayed_time` = HEURE d'horloge (vérifié UC21, 2026-07-11)** :
> ne PAS parser `remaining_time` avec `parseHeureIso()` (qui clampe 0-23 h / 0-59 min pour une *heure*). Une
> **durée** peut dépasser 24 h (charge lente ⇒ `PT25H` = 1500 min, pas écrêté) → helper dédié
> `dureeIsoEnMinutes()` (jours/heures/min/sec, **sans clamp**, `null` sur non-match). `charging_remaining`
> est exposée en **minutes**. À l'inverse `charge_next_time` (UC21) réutilise `parseHeureIso()` (HH:MM) mais
> **derrière une garde de format** (`/^\s*PT\d/` ou `T\d{2}:\d{2}`) pour ne jamais fabriquer un `00:00`
> quand le champ est présent mais non parsable (contrat `parseStatus` : champ invalide ⇒ clé absente).
>
> ⚠️ **`battery.voltage` racine (§ 2.6) = 12 V de servitude, UNIVERSEL** (décision UC21, 2026-07-11) :
> `battery_12v` est mappé **sans garde de motorisation** (présent sur tout véhicule, thermique inclus),
> à la différence des 4 champs `charging.*` (VE/PHEV, branche `electric`). DISTINCT de `energy[].battery.*`
> (capacité/SOH batterie de **traction**, ci-dessous — hors périmètre UC21).
>
> ⚠️ **SOH (`battery.health.*`) n'est renseigné QUE pendant/juste après une charge** (null sinon) →
> le mettre en cache avec horodatage (cf. UC21/24). Exemple réel e-C4 : `extension.electric.battery.load`
> = `capacity 36384 Wh / residual 21856 Wh` à 69 % SOC.
> **PHEV** : `energy[]` a **2 entrées** — `[0]=Electric` (SOC), `[1]=Fuel` (niveau).
>
> ⚠️ **Autonomie scindée par énergie + pas d'autonomie combinée native (vérifié UC23, 2026-07-11)** :
> `energies[].autonomy` existe sur **chaque** entrée → l'entrée `Electric` alimente `autonomy` (autonomie
> élec, libellé « Autonomie électrique ») et l'entrée `Fuel` alimente `autonomy_fuel` (« Autonomie
> carburant ») — clés **distinctes**, plus de partage. **Aucun champ d'autonomie combinée n'existe côté
> API** : le plugin expose `autonomy_total` comme valeur **DÉRIVÉE** (somme élec + carburant dans
> `parseStatus`), seule exception au pattern « 1 champ `/status` → 1 commande », créée **paresseusement** et
> émise uniquement quand un même `/status` fournit les deux autonomies (⇒ hybride uniquement). Détail :
> `.memory/specs/post-mvp/20-energie-charge/23-tech.md`.
>
> ⚠️ **`charging.status` : les états terminaux PERSISTENT poll après poll (vérifié UC24, 2026-07-11 —
> raisonné vs code de réf. + domaine, à confirmer en recette)** : `charging.status` est renvoyé à **chaque**
> `/status` d'un VE/PHEV (branche `electric` de `parseStatus`), **jamais** momentané. `Disconnected` est
> l'**état de repos** normal d'un véhicule non branché ; `Finished` **reste** tant que le câble n'est pas
> débranché. ⇒ Toute **machine à états de charge** (détection de session UC24, future notif « charge
> terminée », etc.) doit **agir sur la TRANSITION** (mémoriser le dernier statut en cache et comparer),
> **jamais** à chaque poll — sinon log/notif spammé ~288×/j/véhicule (bug corrigé UC24 via
> `CHARGE_LAST_STATUT_KEY`). La **fin de session** = transition `InProgress → terminal`
> (`Finished`/`Stopped`/`Disconnected`/`Failure`, comparaison **insensible à la casse**) ; l'enum ne connaît
> **PAS** de valeur `Started` (⚠️ la spec fonctionnelle 24 disait à tort « Started→Finished »). L'énergie de
> session = Δ SOC% × capacité (`battery_capacity` config **autoritaire**, repli
> `energies[].extension.electric.battery.load.capacity` Wh). Détail :
> `.memory/specs/post-mvp/20-energie-charge/24-tech.md`.

### 2.2 Position — `GET /user/vehicles/{id}/lastPosition` (GeoJSON)
- ⚠️ `geometry.coordinates = [longitude, latitude, altitude]` — **ordre GeoJSON, PAS [lat,lon]** ! (lire `[0]`=lon, `[1]`=lat).
- `properties.heading` (0-360°), `.signalQuality` (0-9), `.type` (`Acquire`/`Estimated`), `.createdAt` (RFC3339), `.fixStatus` (`3D`).
- → `position` (`string "lat,lon"`, `GEOLOC`), `heading`, `position_updated`, `gps_signal`.

> ⚠️ **Posture de confidentialité des données de localisation (établie UC34, 2026-07-12)** — à réappliquer
> à tout futur UC de localisation (zones supplémentaires, lieux favoris, trajets détaillés…) :
> 1. **Clé de config contenant des coordonnées (adresse) ⇒ à chiffrer au repos** via
>    `stellantis::$_encryptConfigKey` (le core chiffre/déchiffre **automatiquement** les clés de config
>    **plugin** listées, sur `config::save/byKey`, en transparence — précédent `client_secret`, étendu à
>    `home_lat`/`home_lon` en UC34). Protège les **backups/exports** de config. NE PAS y mettre un simple
>    rayon (pas une donnée de localisation).
> 2. **Ne PAS historiser une distance dérivée d'un point fixe** (ex. `home_distance`, distance au domicile) :
>    couplée à la position (déjà exposée par UC31), une distance-à-un-point-fixe **historisée** permet de
>    **trilatérer** ce point (l'adresse) avec ≥2-3 relevés → on l'expose en valeur courante mais **sans
>    `isHistorized`**. `at_home` (binaire, sans coordonnée) reste historisable sans risque (requis pour
>    servir de déclencheur de scénario). Détail : `.memory/specs/post-mvp/30-localisation-trajets/34-tech.md`.

### 2.3 Cinétique & odomètre (racine depuis v4.15)
- `kinetic.moving` (bool) → `moving` ; `kinetic.speed` (km/h), `.acceleration` (m/s²), `.pace`.
- `odometer.mileage` (float km) → `mileage`.
- `ignition.type` (`Stop`/`StartUp`/`Start`/`Free`) → `ignition`.

> ⚠️ **Détection de trajet : `kinetic.moving` est INSTANTANÉ, `ignition.type` PERSISTE (vérifié UC33,
> 2026-07-12).** `kinetic.moving` est la vélocité **à l'instant exact du poll** → à la cadence de polling
> (défaut 5 min, lecture seule sans wakeup), un simple feu rouge/embouteillage coïncidant avec le poll
> suffit à voir `moving=false` **au milieu** d'un trajet réel → une machine à états basée sur `moving`
> **seul** fragmente les trajets. `ignition.type` reste sur un **état de conduite** (`Start`/`StartUp`)
> pendant tout le trajet, y compris arrêts momentanés moteur tournant. ⇒ **Prédicat « en trajet » robuste
> = `moving==1` OU `ignition ∈ {Start, StartUp}`** ; on ne clôt un trajet que quand **les deux** signaux
> disent « arrêté ». La **distance** vient du **delta `odometer.mileage`** (cumulatif, exact) ; les
> horodatages début/fin viennent de l'instant de poll (`time()`), donc durée à **±cadence** (estimation,
> comme la durée de session de charge UC24). Reconstruction **100 % locale** (aucun endpoint « trips »
> accessible côté consommateur, cf. § 0 et `stellantis-implementations-reference.md`) : machine à états en
> cache calquée sur UC24 `suivreSessionCharge`. Détail : `.memory/specs/post-mvp/30-localisation-trajets/33-tech.md`.

### 2.4 Ouvrants & verrouillage (`doors_state`)
- `doors_state.locked_state` (liste) : `Locked`/`Unlocked`/`SuperLocked`/`DriverDoorUnlocked`/
  `CabinDoorsUnlocked`/`CargoDoorsLocked`/`CargoDoorsUnlocked`/`RearDoorsUnlocked`/`RearDoorsLocked` → `doors_locked`.
- `doors_state.opening[n].identifier` (`Driver`/`Passenger`/`RearLeft`/`RearRight`/`Trunk`/`RearWindow`/`RoofWindow`)
  + `.state` (`Open`/`Closed`) → `door_<id>` (`OPENING`) (UC44).

> ⚠️ **Commande MQTT verrouillage (vérifié UC16, 2026-07-09, `psa_car_controller/psa/RemoteClient.py`
> `lock_door`)** : service `/Doors` (topic `psa/RemoteServices/from/cid/{CID}/Doors`, même enveloppe
> `MQTTRequest` que wakeup/charge/précond). Payload `req_parameters` = `{"action": "lock"|"unlock"}`
> (littéral, aucune donnée dynamique). **Aucun `failure_cause` dédié** côté REST `/status` : une
> indisponibilité (thermique/équipement) ou un refus se traduit seulement par `doors_locked` inchangé
> après l'ack (pas de signal serveur explicite à remonter → retour d'état fin renvoyé à UC18). Le
> déverrouillage porte une **confirmation core** (`actionConfirm=1`, cf. `jeedom-widgets-commandes.md` § 4).

### 2.5 Préconditionnement (`preconditionning.airConditioning` — **double n**)
- `.status` (`Enabled`/`Disabled`/`Finished`/`Failure`), `.failure_cause`
  (`Defect`/`DoorOpened`/`LowBattery`/`LowFuelLevel`/`TooManyUnusedProg`/`WindowsRoofOpened`).
- `.programs[n]` : `slot` (1-4), `enabled` (bool), `start` (ISO 8601), `occurence.day` (array 7 int [Lun..Dim]).

> ⚠️ **Commande MQTT précond (vérifié UC15, 2026-07-09, `psa_car_controller/psa/RemoteClient.py` +
> `constants.py`)** : service `/ThermalPrecond` (topic `psa/RemoteServices/from/cid/{CID}/ThermalPrecond`,
> même enveloppe `MQTTRequest` que wakeup/charge). Payload `req_parameters` = `{"asap":
> "activate"|"deactivate", "programs": <objet 4 créneaux>}`. Le code de référence n'envoie les
> **programmes réels** que s'il les a appris depuis les events MQTT (`psa/RemoteServices/events/...`) ;
> à défaut il retombe sur un littéral figé `DEFAULT_PRECONDITIONING_PROGRAM` (4 créneaux `on:0`,
> `hour:34`/`minute:7` sans effet). Le plugin (UC15) **ne suit pas** les events programs (hors scope,
> variante différée) et envoie donc **toujours** ce même littéral figé — comportement identique au
> défaut du code de référence, pas une improvisation (cf. `.memory/specs/post-mvp/10-commandes-distance/15-tech.md`
> § risque documenté).
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
