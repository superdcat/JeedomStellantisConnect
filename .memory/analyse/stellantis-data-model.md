# Modèle de données — télémétrie véhicule Stellantis/PSA (`connected_car v4`)

> Référence pour décider **quelles commandes info Jeedom créer** (UC MVP/07, UC énergie/20, etc.).
> Source : `GET /user/vehicles/{id}/status` (base `api.groupe-psa.com/connectedcar/v4`) +
> `GET /user/vehicles/{id}/lastPosition`, recoupés avec les modèles de `psa_car_controller`
> (`psa/connected_car_api/models/*`) et le data model B2B officiel.
>
> ⚠️ **Fiabilité** : la **structure** (catégories énergie/position/portes/préconditionnement/odomètre)
> est confirmée ; les **noms de champs exacts** varient selon version d'API et motorisation
> (élec/hybride/thermique). **À reconfirmer contre une réponse réelle** ou contre les modèles
> `psa_car_controller` au moment de coder (cf. `.memory/external/doc/stellantis/INDEX.md`). Tout
> champ noté « (à confirmer) » doit être validé avant d'être livré comme commande.

---

## 0. Principes de mapping vers Jeedom

- **1 véhicule = 1 eqLogic**, clé `logicalId = VIN`. L'`id` API (≠ VIN) sert aux appels REST ; le VIN
  est l'identité stable Jeedom (stocké en `configuration`).
- Chaque champ utile = **1 commande info** (`logicalId` = nom du champ), historisée si pertinent
  (SOC, autonomie, km → graphes).
- Choisir le bon **`subType`** (`numeric`, `binary`, `string`) et le **`generic_type`** Jeedom pour
  des widgets propres (ex. `BATTERY` pour le SOC, `PRESENCE`/`OPENING` pour portes, `GEOLOC`…).
- **Toujours coder défensif** : champ absent (motorisation sans batterie, mode privacy) ⇒ ne pas créer
  / ne pas mettre à jour la commande, jamais d'erreur fatale.

---

## 1. Liste des véhicules — `GET /user/vehicles`

| Champ | Sens | Usage Jeedom |
|---|---|---|
| `id` | Identifiant API (≠ VIN) | clé d'appel REST (config eqLogic) |
| `vin` | VIN | **`logicalId` de l'eqLogic** |
| `brand` | Marque | affichage + choix realm |
| `label` / `_links` / `pictures` | Libellé commercial, image | nom par défaut, vignette (UC50) |
| (motorisation) | Electric / Hybrid / Thermal | conditionne les commandes à créer |

## 2. Statut — `GET /user/vehicles/{id}/status`

### 2.1 Énergie / batterie / charge (`energies[]` ou `energy[]`)
Tableau, une entrée par type d'énergie (`Electric`, `Fuel`).

| Champ (à confirmer) | Sens | Unité | Commande info suggérée | generic_type |
|---|---|---|---|---|
| `energies[].type` | Electric / Fuel | enum | (interne, route le mapping) | — |
| `energies[].level` | **niveau** (SOC % pour Electric, % carburant pour Fuel) | % | `battery_soc` / `fuel_level` | `BATTERY` |
| `energies[].autonomy` | autonomie restante | km | `autonomy_elec` / `autonomy_fuel` | — |
| `energies[].charging.plugged` | câble branché | bool | `charging_plugged` | `PRESENCE` |
| `energies[].charging.status` | InProgress / Stopped / Finished / Failure | enum | `charging_status` | — |
| `energies[].charging.chargingRate` | vitesse de charge | km/h ou kW | `charging_rate` | — |
| `energies[].charging.chargingMode` | Slow / Quick | enum | `charging_mode` | — |
| `energies[].charging.remainingTime` | temps restant (ISO 8601 `PT…`) | durée | `charging_remaining` | — |
| `energies[].charging.nextDelayedTime` | heure de charge différée programmée | ISO 8601 | `charge_next_time` | — |
| `battery` (12 V) | tension/état batterie de servitude | V / % | `battery_12v` | `BATTERY` |

### 2.2 Position — `GET /user/vehicles/{id}/lastPosition` (GeoJSON)

| Champ | Sens | Commande info |
|---|---|---|
| `geometry.coordinates` = `[lon, lat, (alt)]` | coordonnées | `position` (`subType string`, format `lat,lon` pour widget carte) |
| `properties.heading` | cap | `heading` |
| `properties.updatedAt` | horodatage GPS | `position_updated` |
| `properties.signalQuality` | qualité signal | `gps_signal` |
| `properties.type` | Acquire / Estimate | (qualité) |

### 2.3 Cinétique & odomètre
| Champ (à confirmer) | Sens | Unité | Commande info | generic_type |
|---|---|---|---|---|
| `kinetic.moving` | véhicule en mouvement | bool | `moving` | — |
| `odometer.mileage` (ou `timed.odometer.value`) | kilométrage total | km | `mileage` | — |

### 2.4 Ouvrants & verrouillage (`doors` / `openingState`)
| Champ (à confirmer) | Sens | Commande info | generic_type |
|---|---|---|---|
| état verrouillage global | Locked / Unlocked | `doors_locked` | — |
| portes (par ouvrant : avant/arrière G/D) | Open / Closed | `door_<x>` | `OPENING` |
| coffre / capot | Open / Closed | `trunk` / `hood` | `OPENING` |
| fenêtres / toit | Open / Closed | `window_<x>` | `OPENING` |

### 2.5 Préconditionnement (`preconditionning.airConditioning`)
| Champ (à confirmer) | Sens | Commande info |
|---|---|---|
| `status` | Enabled / Disabled / activé en cours | `precond_status` |
| `programs[]` | programmes (heure, jours, récurrence) | `precond_program` (string) |
| `updatedAt` | horodatage | `precond_updated` |

### 2.6 Environnement, alertes, divers
| Champ (à confirmer) | Sens | Commande info |
|---|---|---|
| `environment.air.temp` | température extérieure | `air_temp` |
| `environment.luminosity.day` | jour/nuit | `day_night` |
| `tires`/`tyrePressure` | pression pneus (par roue) | `tyre_<x>` (UC42) |
| alertes service (lave-glace, AdBlue, révision…) | warnings | `alert_<x>` (UC43) |
| `privacy` / mode « Plane » | données coupées côté véhicule | `privacy_mode` (UC75) |
| `lastUpdate` (horodatage global du statut) | fraîcheur de la donnée | `last_update` (UC09) |

---

## 3. Conséquences pour la roadmap

- **MVP/07** crée le socle d'**infos de lecture** présentes sur (presque) tous les véhicules :
  `battery_soc`/`fuel_level`, `autonomy_*`, `charging_status`/`charging_plugged`, `mileage`,
  `doors_locked`, `position`, `last_update`. Le reste (pneus, alertes, ouvrants détaillés,
  préconditionnement) est créé **conditionnellement** selon présence dans la réponse → UC dédiées.
- **Création conditionnelle par motorisation** : pas de `battery_soc` sur un thermique pur, pas de
  `fuel_level` sur un VE → s'appuyer sur `energies[].type` et sur la **présence effective** du champ,
  jamais sur une hypothèse.
- Les **commandes action** (charge, précond, portes…) ne sont **pas** ici : elles passent par MQTT
  (post-MVP, démon) — cf. `.memory/analyse/stellantis-api-architecture.md` § 1.3 et § 3.

## Sources
- `GET /status` & data model : https://developer.groupe-psa.io/webapi/b2c/overview/about/ ;
  https://developer.groupe-psa.io/connected-vehicles/about/
- Modèles de référence : `psa_car_controller` `psa/connected_car_api/models/*` —
  https://github.com/flobz/psa_car_controller ; endpoint local `/get_vehicleinfo/{VIN}` (docs/psacc_api.md)
- HA integration (mapping capteurs) : https://github.com/andreadegiovine/homeassistant-stellantis-vehicles
