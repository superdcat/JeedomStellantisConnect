# Spec technique — post mvp 24 (réglages image & divers)

> Spec fonctionnelle : `.memory/specs/post-mvp/20-video-images/24-reglages-image.md`
> Dépend de : UC12 (`commandCatalog`), UC13 (propriétés IoT). Réfère :
> `.memory/analyse/imou-iot-things-model.md` (§ 3, § 4, § 6).

## Constat déterminant

L'auto-découverte UC13 **n'aide pas** ici : `imou::iotPropertyWidget()` **écarte tous les `bool`**
(`return null`), au motif « déjà couverts par les capability switches ». Or les 6 bools UC24
(`IPCViewFlipState`, `wideDynamic`, `breathingLight`, `smartTrack`, `TargetDetectFrame`, `playSound`)
ne sont **ni des capability switches** (`commandCatalog` = closeCamera/motionDetect/whiteLight/
linkageSiren/siren/whiteLightTimer) **ni pilotables par `setDeviceCameraStatus`** : ils se règlent
**uniquement** par `setIotDeviceProperties`. Ils sont donc invisibles aujourd'hui → c'est le travail
UC24. `osd` (struct) est **hors scope** (widget non trivial).

## Contrat API IMOU (aucun nouvel endpoint — réutilise le transport UC13)
- Écriture : `setIotDeviceProperties(productId, deviceId, properties={"<ref>": 1|0})`. La doc
  (`iot/setIotDeviceProperties.html`) **n'explicite pas l'encodage bool**, mais l'exemple de requête
  utilise des **entiers** (`"3302":2,"3301":1`) → on écrit **`1`/`0` entier** (cohérent avec le cast
  numérique de `actionIotProperty`). Réponse **sans `data`**.
- Lecture : `getIotDeviceProperties(productId, deviceId, ["<ref>",…])` batch → `properties:{<ref>:val}`.
  Le bool peut revenir en **booléen JSON** (`json_decode` → `true`/`false` PHP natif) → **normalisation
  `(int)(bool)$v` à la lecture** (⚠️ `(string)true === '1'` en PHP : le guard `is_numeric||is_string`
  laisserait passer une valeur mal typée sans normalisation explicite préalable).
- `ref` résolu par `identifier` stable via `getProductModelCached` (cache 7 j en place).

## Architecture — `core/class/imou.class.php` uniquement

Catalogue **dédié séparé** (ne touche pas le pipeline enum/int d'UC13 ; forme « on/off + info gatés »
identique à `commandCatalog`).

### 1. `imou::iotBoolCatalog()` (NOUVEAU, static)
Whitelist déclarative des 6 bools : `identifier => ['on','off','state' (logicalId),
'nameOn','nameOff','nameState' (FR)]`. logicalId préfixés **`iotsw_<id>_on|_off|_state`** (disjoints de
`iot_`/`ptz_`/`live_`). Whitelist intentionnelle (≠ auto-découverte) : évite la surcharge UI et la
capture des bools d'autres UC (HeaderDetect/aiHuman/crHuman/linkageWhiteLight…).

### 2. `imou::iotBoolSpecs($model)` (NOUVEAU, instance)
Source UNIQUE consommée par création + refresh + nettoyage. Pour chaque entrée du catalogue :
`resolveIotModelEntry($model,'properties',identifier)` ; ne retient que **type `bool`** ; calcule
`readable` (accessMode contient `r`) / `writable` (contient `w`) et le `ref`. Retourne la liste des
specs `['identifier','ref','on','off','state','nameOn','nameOff','nameState','readable','writable']`.

### 3. `imou::createIotBoolCommands($logicalIdLog, $model)` (NOUVEAU, privé)
Appelé dans `createCommands()` après `createIotStatusCommands`. **`$model===null` ⇒ no-op total**
(ni création ni suppression — anti-destruction, calque de `createIotCommands`).
- info **binary** (`state`) si `readable` : `historized`, `configuration.pollable=1`,
  `configurationOnCreate.noPoll=1`, `visibleOnCreate=false` (UC16/UC75 : masquée ⇒ exclue du poll).
- actions **on/off** (`other`) si `writable` : liées à l'info via `value`, `template=imouButton`,
  `visibleOnCreate=false`.
- **Nettoyage** (uniquement `$model!==null`) : pour chaque entrée du **catalogue** absente de
  `iotBoolSpecs` (propriété disparue / plus bool), supprimer ses `iotsw_*` si présents (mirror
  `createIotStatusCommands`). Ne touche QUE des logicalId du catalogue.

### 4. `imouCmd::execute()` (MODIF)
Nouvelle branche **avant** la branche `iot_` (et avant les switches), bloc-commentaire des préfixes
disjoints mis à jour : `iotsw_<id>_on|_off` → `actionIotBool (UC24)`. `$on = (suffixe === '_on')`.
Disjonction : `iotsw_` ne commence pas par `iot_` (4ᵉ car. `s`) ni par `ptz_`/`live_`.

### 5. `imouCmd::actionIotBool($eqLogic, $on)` (NOUVEAU, privé)
Symétrique de `actionIotProperty` mais valeur dérivée de l'intention on/off :
`identifier = getConfiguration('iotIdentifier')` ; `productId/deviceId` de l'eqLogic ; re-résolution du
`ref` via le modèle ; `setIotDeviceProperties(productId, deviceId, {ref: $on?1:0})` ; update optimiste
de `iotsw_<id>_state` (1/0). Erreur API remontée telle quelle.

### 6. `imou::refreshIotBoolProperties($deviceId, $model, $pollDisabled)` (NOUVEAU, privé)
Méthode **dédiée** (pas de fusion dans `refreshIotProperties` — responsabilité unique). Appelée dans
`refreshStates()` après `refreshIotServiceStatuses`. Même pattern : `$model===null` ⇒ skip ; **NE LÈVE
JAMAIS** (try/catch imouException + Throwable) ; construit `refToState` depuis `iotBoolSpecs` (readable
+ état présent + non `pollDisabled`) ; **un seul** `getIotDeviceProperties` batch ; boucle de lecture
avec **normalisation `is_bool($v) ? (int)$v : $v`** juste avant le guard, puis `checkAndUpdateCmd`.
Note quota : les bools étant `noPoll` par défaut, **aucun appel** tant que l'utilisateur n'a pas activé
le polling sur au moins un bool (le lot batch est vide ⇒ pas d'appel).

### 7. `imou::iotPropertyWidget()` (MODIF mineure)
Commentaire `bool → null` mis à jour : « bools exposés via `iotBoolCatalog` (UC24) ;
`commandCatalog` pour les switches `setDeviceCameraStatus` ». Aucun changement de code.

## Server vs Client
100 % back-end PHP. Aucune UI/JS/AJAX nouvelle : info binary + boutons on/off rendus par le core
(template `imouButton` déjà utilisé pour les switches).

## Validation
- **Whitelist** ⇒ zéro doublon avec `commandCatalog`, zéro fuite des bools d'autres UC.
- **Gating** model-présence + type `bool` ; `r`-only ⇒ info seule (non cliquable, masquée).
- **Anti-destruction** : `model===null` ⇒ no-op total ; nettoyage seulement si `model!==null`.
- **Idempotence** : catalogue déterministe, `creerCommande`/`getCmd` par logicalId ⇒ 0 churn au re-sync.
- **Disjonction préfixes** : `iotsw_` distinct de `iot_`/`ptz_`/`live_` (routage + purge `iot_*` UC13).
- **Écriture** entière `1/0` ; **lecture** normalisée `0/1` (bool JSON géré).
- **Quota** : ≤ 1 `getIotDeviceProperties` bool/caméra/cycle, et 0 par défaut (noPoll).
- **Robustesse cron** : `refreshIotBoolProperties` ne lève jamais (boucle cron protégée).

## Server Actions / API
- `imou::iotBoolCatalog()`, `imou::iotBoolSpecs()`, `imou::createIotBoolCommands()`,
  `imou::refreshIotBoolProperties()` (NOUVEAUX) ; `imouCmd::actionIotBool()` (NOUVEAU) ;
  `createCommands()`, `refreshStates()`, `execute()` étendus ; commentaire `iotPropertyWidget` maj.

## Dépendances
Aucune.

## Risque résiduel
`setIotDeviceProperties` sur ces bools `rw` non validé sur appareil réel — risque théorique `40999`
(comme la piste `siren` lecture seule / PTZ-IoT). À confirmer en recette ; l'erreur remonte proprement
à l'utilisateur (Jeedom l'affiche).

## Impact i18n (FR uniquement — traduction déléguée Étape 10)
18 chaînes (3 par bool) dans `iotBoolCatalog()` : « Activer/Désactiver le retournement d'image »,
« Retournement d'image (état) », idem WDR, LED, suivi intelligent, cadre de détection, son de
notification.

## Tests (recette manuelle)
(a) cam IoT exposant `smartTrack` (rw) → actions on/off + info, masquées par défaut ;
(b) activer/désactiver flip/WDR/LED/suivi → effectif (vérifiable app IMOU) ;
(c) info bool affichée après activation du polling (cron) ;
(d) cam sans la propriété → aucune commande pour ce réglage ;
(e) modèle injoignable (réseau KO) → aucune commande supprimée, postSave ne plante pas ;
(f) re-sync sans changement → idempotent (0 churn) ;
(g) bool retiré du modèle → ses `iotsw_*` supprimés (modèle dispo).
