# Spec technique — post mvp 13 commandes IOT et propriétés

> Spec fonctionnelle : `.memory/specs/post-mvp/10-pilotage-avance/13-commandes-iot-et-proprietes.md`
> Dépend de : UC12 (catalogue déclaratif des switches). Réfère : `.memory/analyse/imou-iot-things-model.md`.

## Décisions (validées avec l'utilisateur)
1. **Auto-découverte filtrée** des propriétés IoT depuis `getProductModel` (et non un catalogue
   d'identifiants codé à la main) : le slider/select se crée tout seul avec les **bornes/listes lues
   du modèle**, propres à chaque caméra.
   - `enum` (`rw`) → **select** + info ; `int` (`rw`) → **slider** (min/max/step du modèle) + info ;
     `r` → **info** seule ; `w`-only → action seule.
   - **Skippés** : `bool` (déjà couverts par les capability switches de `commandCatalog()`),
     et `text`/`date`/`struct`/`array` (pas de widget simple).
2. **Libellés** : nom = champ `name` du modèle par défaut ; **surcharge FR** par une table
   `iotPropertyLabels()` pour les identifiants connus (seuls ces libellés FR sont des clés i18n `__()`).
   - **Anti-chinois (2026-06-23)** : le `name`/`desc`/unité du modèle est souvent **localisé en CJK**
     (chinois) selon la locale de l'appareil → on ne l'affiche **jamais** tel quel s'il n'est pas latin.
     Priorité de résolution d'un libellé (cf. `imou::labelIsUsable()`, rejette toute lettre non latine) :
     1. surcharge FR connue (`iotPropertyLabels`/`iotEnumValueLabels`) ;
     2. sinon `name`/`desc` du modèle **s'il est latin** (FR/EN) ;
     3. sinon repli **ASCII** : l'**`identifier`** technique pour un nom de propriété (ex. `NightMode`),
        la **valeur numérique** pour une option de select, **aucune** unité.
     Choix utilisateur : auto-découverte conservée, mais « au pire un nom technique ASCII », jamais du CJK.
   - **Périmètre : installations fraîches uniquement.** Le filtre s'applique à la **création** des
     commandes (`creerCommande` ne pose le nom qu'à la création, pour préserver la perso utilisateur) :
     une commande déjà créée en CJK n'est **pas** ré-étiquetée (pas de migration). Les `listValue`
     (options de select), elles, sont reposées à chaque sync → leurs libellés se corrigent seuls.
3. **Renommage** `switchCatalog()` → `commandCatalog()` (mécanisme switches inchangé ; l'IoT est un
   mécanisme séparé qui cohabite).

## Contrat API IMOU (confirmé doc 2026-06-17)
- `setIotDeviceProperties(productId, deviceId, properties)` : `properties` = **objet clé=`ref`** →
  valeur (`{"<ref>": <valeur>}`). **Pas de `channelId`.** Réponse **sans `data`**.
- `getIotDeviceProperties(productId, deviceId, properties)` : `properties` = **tableau de `ref`**
  (lecture **BATCH**). Réponse `{ productId, deviceId, properties:{<ref>:<valeur>}, status }`.
- `getProductModel.properties[]` : `identifier`, `ref`, `accessMode` (`r`/`w`/`rw`), `name`,
  `dataType{ type: bool|int|enum|text|date|struct|array, specs }`. enum→`specs.list[]{value,desc}` ;
  int→`specs.range[min,max]`, `step`, `unit`. ⚠️ **Casse des clés JSON ambiguë** (DataType/dataType,
  Specs/specs, List/list…) → lecture via accesseur **insensible à la casse**.
- Aucun nouvel endpoint dans `imouApi` : `callWithToken('setIotDeviceProperties'|'getIotDeviceProperties', …)`
  (la brique transport est générique). `ref` résolu via `getProductModelCached` (cache 7 j déjà en place).

## Architecture — 1 seul fichier `core/class/imou.class.php`

### Renommage
`switchCatalog()` → `commandCatalog()`. Mettre à jour les 5 sites : définition, `createCommands()`,
`refreshStates()`, `imouCmd::execute()`, `imouCmd::setCameraEnable()` (whitelist). Contenu inchangé.

### Helpers IoT (statics, NOUVEAUX)
- `imou::modelGet(array $arr, string $key)` : accès **insensible à la casse** à `$arr[$key]`
  (parade casse JSON). Ne mute jamais le tableau (pas de strtolower au stockage cache).
- `imou::resolveIotModelEntry(array $model, string $collectionKey, string $identifier): ?array` :
  cherche dans `model[services|properties]` l'entrée par `identifier` (via modelGet). Mutualise
  l'ancien `resolveIotService` (qui devient un wrapper) et la résolution de propriété.
- `imou::resolveIotService($productId,$identifier)` : conservé, délègue à `resolveIotModelEntry`.
- `imou::iotPropertyLabels(): array` : table `identifier => 'Libellé FR'` (NightMode, sensibilités…).
- `imou::iotPropertyWidget(array $property): ?array` : mappe une propriété → spec widget Jeedom, ou
  `null` si non exposable (bool/struct/array/text/date). Retour :
  `['ref','identifier','accessMode','widget'=>'select'|'slider','listValue'?,'minValue'?,'maxValue'?,'step'?,'unit'?]`.

### Driver d'auto-découverte
- `imou::getIotModelOrNull(): ?array` (instance) : `productId` vide → `null` ; sinon
  `getProductModelCached` en **try/catch** → `null` si échec/froid. `null` = **indéterminé**
  (ni création ni suppression — anti-destruction, comme CSV ability vide).
- `imou::iotCommandSpecs(array $model): array` (instance) : itère `model.properties`, applique
  `iotPropertyWidget`, et produit pour chaque propriété exposable :
  `['identifier','ref','widget','accessMode','name','stateLogicalId','actionLogicalId',
    'listValue'?,'minValue'?,'maxValue'?,'step'?,'unit'?]`.
  - `name = isset(labels[id]) ? __(labels[id], __FILE__) : (string) model name`.
  - logicalId stables : `iot_<idSanitized>_state` (info, si `accessMode` contient `r`) et
    `iot_<idSanitized>_set` (action, si contient `w`). `idSanitized = preg_replace('/[^a-zA-Z0-9_]/','')`.
  **Source unique** consommée par createCommands ET refreshStates (pas de divergence).

### `createCommands()` (extension)
Après la boucle `commandCatalog()` (switches, inchangée) → `$this->createIotCommands();` :
1. `model = getIotModelOrNull()` ; `null` → log debug + **return** (indéterminé : on ne touche à rien).
2. `specs = iotCommandSpecs($model)`.
3. **Infos d'abord** (id requis pour lien `value`) : pour chaque spec avec `stateLogicalId`,
   `creerCommande(stateLogicalId, name+' (état)', 'info', 'numeric', [...])`.
4. **Actions ensuite** : pour chaque spec avec `actionLogicalId`,
   `creerCommande(actionLogicalId, name, 'action', widget, ['value'=>stateId?, 'configuration'=>[...]])`.
   Le `ref` n'est PAS figé en config (résolu à l'exécution) mais l'`identifier` est stocké
   (`configuration.iotIdentifier`) pour le routage de `execute()`.
5. **Nettoyage** : supprimer les commandes **préfixées `iot_`** de cet eqLogic absentes de `specs`
   (propriété disparue du modèle). Ne touche QUE les `iot_*` → aucun switch ni commande tierce.
   Fait UNIQUEMENT quand le modèle est disponible (sinon on ne connaît pas l'ensemble cible).

### `creerCommande()` (extension)
Nouvelles options, **reposées à chaque passage** (données techniques, pas « création seulement ») :
- `configuration` (assoc) → `setConfiguration(k,v)` pour `listValue`/`minValue`/`maxValue`/`step`/`iotIdentifier`.
- `unite` → `setUnite()`.
Conventions Jeedom (confirmées advisor) : select → `configuration.listValue` = `"val|desc;val|desc"` ;
slider → `configuration.minValue`/`maxValue`. Valeur à l'exécution dans `$_options['select'|'slider']`.

### `imouCmd::execute()` (extension)
Avant la boucle catalogue (ou après, sans match) : si `logicalId` commence par `iot_` et finit par
`_set` → `actionIotProperty($eqLogic)` :
- `identifier = $this->getConfiguration('iotIdentifier')` ; `productId/deviceId` de l'eqLogic.
- `property = resolveIotModelEntry(getProductModelCached(productId),'properties',identifier)` →
  `ref` (re-résolu, robuste si le modèle a changé). Absent → imouException CONFIG.
- valeur : `$this->getSubType()==='slider' ? $_options['slider'] : $_options['select']`, castée
  numérique (int si entier, float sinon).
- `setIotDeviceProperties(productId, deviceId, {ref: valeur})`. Update optimiste de l'info `_state`
  (si elle existe) avec la valeur. Erreur remontée telle quelle (Jeedom l'affiche).

### `refreshStates()` (extension — 2ᵉ phase BATCH)
Après la boucle switches (inchangée) : bloc IoT en **try/catch (imouException + Throwable, ne lève
jamais)** :
- `model = getIotModelOrNull()` ; `null` → skip.
- `specs = iotCommandSpecs($model)` filtrées sur `accessMode` lisible (`r`) ET commande info présente.
- map `ref → stateLogicalId`. Si non vide : **un seul** `getIotDeviceProperties(productId,deviceId,[refs])`.
- pour chaque `ref` du retour `data.properties` : `checkAndUpdateCmd(stateLogicalId, valeur)`.

## Server vs Client
100 % back-end PHP. Aucune UI/JS/AJAX nouvelle : les widgets select/slider sont rendus par le core
à partir du `subType` + `configuration`.

## Validation
- **Anti-destruction** : modèle indisponible/froid → `null` → ni création ni suppression ; une
  commande IoT déjà créée **persiste** (jamais supprimée sur `null`). Documenté.
- **Pas de crash de `postSave()`** : tout le bloc IoT (réseau getProductModel) en try/catch.
- **Idempotence** : re-sync sans changement de modèle (caché) → 0 churn (creerCommande idempotent,
  getCmd par logicalId, nettoyage ne retire que les `iot_*` absents du modèle).
- **Pas de doublon avec les switches** : auto-découverte saute les `bool` (toutes les capability
  switches sont des bool) → aucun recoupement closeCamera/whiteLight/linkageSiren/motionDetect.
- **Bornes correctes** : min/max/step/listValue lus du modèle à chaque création → propres à la cam.
- **Quota refresh** : 1 appel `getIotDeviceProperties` batch par caméra IoT et par cycle.
- **Casse JSON** : tous les accès au modèle via `modelGet` (insensible à la casse).
- **Non-IoT** : `productId` vide → aucune commande IoT, aucune lecture batch.

## Server Actions / API
- `imou::commandCatalog()` (renommé) ; `imou::modelGet()`, `resolveIotModelEntry()`,
  `iotPropertyLabels()`, `iotPropertyWidget()`, `getIotModelOrNull()`, `iotCommandSpecs()`,
  `createIotCommands()` (NOUVEAUX) ; `resolveIotService()` (wrapper).
- `imouCmd::actionIotProperty(eqLogic): void` (NOUVEAU) ; `execute()`, `creerCommande()`,
  `refreshStates()` étendus.

## Dépendances
Aucune.

## Tests (recette manuelle — `validation-manuelle.md`)
(a) cam IoT avec propriété enum `rw` → commande **select** avec les bonnes valeurs ;
(b) cam IoT avec propriété int `rw` → **slider** respectant range/step ;
(c) propriété `r` → **info** rafraîchie au cron (batch) ;
(d) cam NON IoT (productId vide) → aucune commande IoT ;
(e) modèle injoignable (réseau coupé) → aucune commande supprimée, postSave ne plante pas ;
(f) re-sync sans changement → idempotent (0 churn) ;
(g) propriété retirée du modèle → ses commandes `iot_*` supprimées (modèle dispo).

## Impact i18n (FR uniquement — traduction déléguée Étape 10)
Nouvelles clés = les libellés FR de `iotPropertyLabels()` (les seuls enveloppés `__()`). Les noms
issus du modèle (fallback) ne sont PAS des clés i18n (chaînes runtime de l'API). Le suffixe
« (état) » des infos est concaténé au libellé → prévoir une clé pour le motif si besoin.
