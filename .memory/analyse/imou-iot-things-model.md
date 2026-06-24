# IMOU IoT « Things » — mécanisme & capacités découvertes

> Analyse capitalisée le 2026-06-17 après résolution de la **sirène manuelle** (voir mémoire
> persistante `imou-switches-setdevicecamerastatus-inversion` et
> `.memory/specs/post-mvp/10-pilotage-avance/12-projecteur-sirene-tech.md`).
> Réfère : doc IMOU `iot/IoTThingsDataModel.html`, `iot/getProductModel.html`, `iot/iotDeviceControl.html`,
> `iot/setIotDeviceProperties.html`, `iot/getIotDeviceProperties.html`.

## 1. Le constat clé
Certaines caméras IMOU sont des **appareils IoT « Things »** : le champ **`productId`** renvoyé par
`listDeviceDetailsByPage` est alors **non vide** (vide ⇒ pas IoT). Pour ces appareils, une partie des
capacités n'est PAS pilotable par `setDeviceCameraStatus` mais par le **modèle de données IoT**.

> Exemple décisif : la **sirène manuelle**. La propriété `siren` est en **lecture seule**
> (`accessMode:"r"`, report-only) → `setDeviceCameraStatus(siren)` échoue (**40999**). Le déclenchement
> réel se fait par les **services** `SirenStart`/`SirenStop` via `iotDeviceControl`. C'est ce que
> `imou_life #61` (wontfix) n'avait pas résolu.

## 2. Les 3 dimensions du modèle (`getProductModel(productId)`)
- **`properties`** : grandeurs lisibles/réglables. `accessMode` `rw` (réglable) ou `r` (lecture seule !).
  Types : `bool`, `enum` (liste de valeurs), `int` (range/step/unit), `struct`, `array`.
- **`services`** : actions à invoquer (avec `inputData`/`outputData`). Chacune a un **`ref` numérique**.
- **`events`** : remontées (push) — base d'une future intégration d'alarmes temps réel.

## 3. Comment piloter selon le type (règle d'implémentation)
| Cible | Mécanisme | Appel |
|---|---|---|
| Propriété `bool` `rw` aussi exposée en capability switch (ex. whiteLight, linkageSiren, closeCamera) | **setDeviceCameraStatus** (déjà fait, catalogue UC12) | `setDeviceCameraStatus(enableType,…)` |
| Propriété `bool` `rw` **PAS** un capability switch (ex. flip, WDR, LED, smartTrack) | **setIotDeviceProperties** (UC24 — whitelist `iotBoolCatalog`, valeur entière `1`/`0`, lecture normalisée 0/1) | `setIotDeviceProperties(productId, deviceId, {ref:1\|0})` |
| Propriété `enum`/`int` `rw` (ex. NightMode, sensibilité) | **setIotDeviceProperties** (contrat confirmé, cf. §6) | `setIotDeviceProperties(productId, deviceId, properties)` |
| Propriété `r` (lecture seule, ex. `siren`) | **non réglable** — lecture via `getIotDeviceProperties` | — |
| **Service** (ex. SirenStart, PtzMoveFour, whiteLightStart) | **iotDeviceControl** | `iotDeviceControl(productId, deviceId, ref, content)` |

- `content` = `{"<ref_du_param>": "valeur", …}` (clés = `ref` des `inputData`). Vide ⇒ objet JSON `{}`.
- **Résoudre les `ref` dynamiquement** par `identifier` stable via `getProductModel` (les `ref`
  numériques varient selon le modèle) — cf. `imou::resolveIotService()` / `getProductModelCached()`.
- **Réponse d'un service `Get…` (CONFIRMÉ doc, UC14, 2026-06-18)** : les valeurs de sortie sont dans
  **`result.data.content.outputData`** = objet **clé = `ref` des `outputData`** (même logique que le
  `content` d'entrée). `callWithToken('iotDeviceControl')` renvoyant `result.data`, on lit donc
  `data['content']['outputData']`. Pour mapper une sortie → sa valeur, résoudre `identifier → ref` via
  le `outputData` du service dans `getProductModel`. Tronc commun écriture+lecture : `imou::callIotService()`.
  ⚠️ **Identifiant exact de certaines sorties non documenté** (ex. compte à rebours de `GetWhiteLightStatus`) :
  UC14 le résout par candidats explicites + repli sur la sortie `int`, et **logue (debug) les sorties
  réelles** quand la résolution échoue → consulter ce log sur un vrai appareil pour épingler l'identifiant.

## 4. Capacités découvertes — Cruiser 2C 3MP (`productId=QLDuttGT`, 2026-06-17)
Échantillon réutilisable pour cadrer les UC (identifiants stables ; `ref` indicatifs de CE modèle).

### Services (→ `iotDeviceControl`)
| identifier | ref | rôle | input notable |
|---|---|---|---|
| `SirenStart` / `SirenStop` | 25500 / 22200 | sirène manuelle on/off | `clientLocalTime` (start) |
| `GetSirenStatus` | 25700 | état + compte à rebours sirène | — |
| `whiteLightStart` / `whiteLightStop` | 26700 / 26800 | projecteur à **minuterie** on/off | `clientLocalTime` (start) |
| `GetWhiteLightStatus` | 26900 | état + compte à rebours projecteur | — |
| `PtzStepMoveFour` | 24200 | PTZ pas-à-pas (Left/Right/Up/Down + Zoom) | `Operator`, `Zoom` |
| `PtzMoveFour` / `PtzMoveEight` | 24300 / 22100 | PTZ continu (H/V/Zoom + durée ms) | `Horizontal/Vertical/Zoom/Duration` |
| `std_reset_ptz` | 203700 | recalage PTZ | — |
| `SetCollection`/`GetCollection`/`TurnCollection`/`RenameCollection`/`DeleteCollection` | 23200/21500/22000/22600/22300 | points de collection (presets PTZ) + ronde | divers |
| `GetLocalRecords`/`GetLocalRecordNum`/`GetLocalRecordBitmap` | 24100/29700/21900 | relecture enregistrements locaux | filtres type/temps |
| `GetStorageCapacity` / `StorageFormat` | 200300 / 20800 | info SD / formatage | — |

### Propriétés réglables notables (`rw`)
| identifier | type | rôle |
|---|---|---|
| `closeCamera` | bool | confidentialité (image coupée) — *catalogue UC12* |
| `whiteLight` | bool | projecteur on/off — *catalogue UC12* |
| `linkageSiren` | bool | sirène sur détection (armement) — *catalogue UC12* |
| `linkageWhiteLight` | bool | projecteur sur détection |
| `WhiteLightMode` | enum | projecteur :常亮(fixe)/闪烁(clignotant) |
| `fillLightSensitivity` | enum 1-5 | sensibilité lumière d'appoint |
| `NightMode` | enum 4 | vision nocturne : intelligente / pleine couleur / IR / off |
| `HeaderDetect`, `aiHuman` | bool | détection de forme humaine / IA humaine |
| `mobileDetect` | bool | détection de mouvement (≈ motionDetect) |
| `DetectionSensitivity` | enum 1-5 | sensibilité de détection |
| `crHuman`/`crCar`/`crMotionDetect`/`crSens` | bool/enum | intrusion de zone (humain/véhicule/mouvement/sensibilité) |
| `wideDynamic` | bool | WDR (grande plage dynamique) |
| `IPCViewFlipState` | bool | retournement de l'image |
| `breathingLight` | bool | LED indicateur de l'appareil |
| `smartTrack` | bool | suivi intelligent (caméras motorisées) |
| `TargetDetectFrame` | bool | cadre de détection de cible |
| `playSound` | bool | son de notification de l'appareil |
| `osd` | struct | incrustation OSD (texte/canal) |

### Propriétés lecture seule (`r`) — informatives
`siren` (état sirène), `StorageCapacity`/`StorageState` (SD), `networkingMode`/`wifi_rssi` (réseau),
`DeviceType`/`DeviceName`. → exposables en commandes **info** uniquement.

### Événements (push, `events`) — future UC alarmes temps réel
`e_videoMotion` (mouvement), `e_smartMixDetect` (type: personne/animal/véhicule/colis…),
`e_aiPerArea`/`e_areaDetect` (intrusion de zone), `e_storageAbnormal`/`e_storageEmpty`,
`e_upgradeSuccess`/`e_upgradeFail`. → nécessite le callback push (`setMessageCallback`).

## 6. Contrat des propriétés IoT — CONFIRMÉ doc (UC13, 2026-06-17)
Vérifié sur `iot/setIotDeviceProperties.html`, `iot/getIotDeviceProperties.html`,
`iot/IoTThingsDataModel.html` lors de l'implémentation de l'UC13 (`code` PHP : `imou::iotPropertyWidget`,
`iotCommandSpecs`, `refreshIotProperties`, `imouCmd::actionIotProperty`).

- **Clé = `ref`, PAS `identifier`** (comme les services) :
  - écriture : `setIotDeviceProperties(productId, deviceId, properties)` où
    `properties` = **objet** `{"<ref>": <valeur>}`. **Pas de `channelId`.** Réponse **sans `data`**.
  - lecture : `getIotDeviceProperties(productId, deviceId, properties)` où `properties` = **tableau
    de `ref`** `["<ref>", …]` → **lecture BATCH** (tous les refs d'un device en UN appel : économie
    de quota au cron). Réponse `{ productId, deviceId, properties:{<ref>:<valeur>}, status }`.
  - → toujours **résoudre le `ref` par `identifier` stable** via `getProductModel` (mêmes raisons que
    les services : le `ref` varie selon le modèle). Helper mutualisé `imou::resolveIotModelEntry`.
- **Structure d'une propriété** (`getProductModel.properties[]`) : `identifier`, `ref` (entier > 0),
  `accessMode` (`r`/`w`/`rw`), `name`, `dataType{ type, specs }`. `type` ∈ `bool|int|enum|text|date|struct|array`.
  - `enum` → `specs.list[]` de `{value, desc}` ; **les `value` sont des ENTIERS**.
  - `int`  → `specs.range[min,max]`, `specs.step`, `specs.unit`.
- ⚠️ **Casse des clés JSON AMBIGUË** dans la doc (`DataType`/`dataType`, `Specs`/`specs`,
  `List`/`Value`/`Desc`/`Range`/`Step`/`Unit`) → lire le modèle via un accesseur **insensible à la
  casse** (`imou::modelGet`), jamais en accès direct `$prop['dataType']`.
- **Mapping subType Jeedom retenu** : `enum`→**select** (`configuration.listValue = "val|desc;…"`),
  `int`→**slider** (`configuration.minValue/maxValue`, `step`), lecture seule→**info** `numeric`.
  Les `bool` sont **écartés** de l'auto-découverte enum/int. Deux cas : (a) bool aussi capability
  switch → `commandCatalog()` / `setDeviceCameraStatus` (évite le doublon) ; (b) **UC24** — bool `rw`
  qui n'est PAS un capability switch (flip, WDR, LED, smartTrack, TargetDetectFrame, playSound) →
  **whitelist** `iotBoolCatalog()` pilotée par `setIotDeviceProperties` (valeur entière `1`/`0` ; la doc
  n'explicite pas l'encodage bool mais l'exemple de requête utilise des entiers ; lecture normalisée
  `is_bool→0/1`). logicalId préfixés `iotsw_` (disjoints de `iot_`). Whitelist volontaire (≠
  auto-découverte) pour ne pas surcharger l'UI ni capter les bool relevant d'autres UC (HeaderDetect/
  aiHuman = détection humaine ; crHuman/crCar = intrusion de zone).

## 5. Implications roadmap (specs créées le 2026-06-17)
- **Pré-requis transverse** : généraliser le catalogue (UC12) aux **propriétés IoT** (enum/int via
  `setIotDeviceProperties`) et aux **types de commande non-binaires** (select/slider) →
  `10-pilotage-avance/13-commandes-iot-et-proprietes.md`.
- Nouvelles UC : projecteur à minuterie, vision nocturne, détection humaine/IA, réglages image.
- PTZ : `15-ptz-presets.md` enrichi des **services IoT** (`PtzStepMoveFour`/`PtzMoveFour`/Collection).
  ⚠️ **Mais l'UC15 a finalement tranché AUTREMENT** — voir § 7.

## 7. PTZ — décision UC15 (2026-06-18) : voie HTTP `controlMovePTZ` UNIVERSELLE (≠ services IoT)
> ⚠️ Le § 4 liste `PtzStepMoveFour`/`PtzMoveFour`/`PtzMoveEight`/`std_reset_ptz`/`Collection` comme
> voie IoT du PTZ. **L'UC15 ne les utilise PAS** : ne pas présumer que le PTZ passe par l'IoT.

- **Voie retenue** : l'endpoint HTTP **`controlMovePTZ`** (POST `/openapi/controlMovePTZ`), pour TOUS
  les appareils (IoT **et** non-IoT). Raison : les valeurs enum `Operator`/`Zoom` de `PtzStepMoveFour`
  sont **model-specific et NON documentées** (résolution `desc`-matching fragile), alors que
  `controlMovePTZ` a un **contrat figé et documenté** ; c'est l'API PTZ canonique d'IMOU.
- **Contrat `controlMovePTZ`** (confirmé doc `http/device/operate/controlMovePTZ.html`, 2026-06-18) :
  params `deviceId`, `channelId` (**REQUIS** — contrairement à `setDeviceCameraStatus` où il est
  optionnel), `operation` (**String**), `duration` (**Long, ms**). Réponse **sans `data`**.
  `operation` : `0`=haut, `1`=bas, `2`=gauche, `3`=droite, `4..7`=diagonales, `8`=zoom avant,
  `9`=zoom arrière, `10`=stop. **`duration` BORNE le mouvement** (l'API arrête seule après ce délai)
  → un nudge court (500 ms) suffit, **aucune commande `stop` n'est nécessaire**.
- **Tokens d'ability PTZ** (confirmés doc `faq/ability.html`, utiles pour les UC motorisées / presets) :
  `PT` (pan/tilt), `PTZ` (pan/tilt + zoom), `PT1` (4 directions, **sans zoom**), `PT2` (2 directions),
  `ZoomFocus` (zoom), `CollectionPoint` (**presets** — UC future), `TimedCruise` (ronde),
  `SmartTrack`/`SmartLocate` (suivi). Gating UC15 : directionnel si {PT,PTZ,PT1,PT2} ; zoom si
  {PTZ,ZoomFocus}.
- **Caveat (non levé)** : `controlMovePTZ` **non validé sur un appareil IoT** (risque théorique d'un
  verrou type 40999, comme `siren`). Si la recette sur Cruiser 2C renvoie 40999, ajouter un **repli IoT**
  `PtzStepMoveFour` en UC de suivi. Presets (`Collection*`) **hors périmètre UC15**.
