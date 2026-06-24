# Spec technique — UC57 Niveau de batterie & réveil d'appareil dormant

> Découle de `57-batterie-reveil.md` (fonctionnel) + analyse `.memory/analyse/imou-home-assistant-comparaison.md` §3.1/§5.1.
> Contrat API recoupé sur la doc IMOU (`http/door/getDevicePowerInfo.html`, `http/door/wakeUpDevice.html`).

## Contrat API IMOU (confirmé doc)
- **`getDevicePowerInfo`** — param : `deviceId`. Réponse `data.electricitys[]`, chaque entrée :
  - `electric` (**String** "0".."100") : pourcentage principal (caméras batterie) ;
  - `litElec` / `alkElec` (**String** "0".."100") : cellules lithium / alcaline (serrures bi-cellules) ;
  - `type` (**String**) ∈ `{"battery","adapter","batteryAdapter"}` (type d'alimentation, **pas** "lithium/alcaline").
  - ⚠️ La page doc est **centrée serrures** : sur serrure bi-cellule `electric=0` par défaut → les vraies valeurs
    sont dans `litElec`/`alkElec`. Sur une **caméra** batterie, `electric` porte le %. Champs **String** → cast `(int)`.
- **`wakeUpDevice`** — params : `deviceId` + `url:"/device/wakeup"` (constante obligatoire, paramètre du corps).
  Réponse sans `data`. Méthode IMOU = `wakeUpDevice` (l'`url` n'est PAS le nom de méthode, c'est un param).
- **`DV1030`** = appareil dormant. `imouApi::call()` ne le rejoue PAS (ni transitoire ni transport) →
  l'exception remonte avec `getImouCode()==='DV1030'` → interception dédiée (réveil + 1 relecture, calque HA).

## Architecture
**Un seul fichier modifié : `core/class/imou.class.php`.** Aucune action exposée, aucun AJAX/JS/widget.
Commande **info numérique `battery`** (`%`), info-only. Réveil **purement interne** (pas de commande action « réveiller » en V1).

### Création — `createCommands()`, après le bloc IoT (`$model` mutualisé déjà chargé)
- `createBatteryCommand($logicalIdLog, $model)` :
  - applicabilité via `batteryApplicable($model)` (tri-état) ;
  - `null` → no-op (ni création ni suppression) ; `false` → suppression de `battery` si présente ;
  - `true` → `creerCommande('battery', 'Niveau de batterie', 'info', 'numeric', [...])` avec
    `historized=true`, `unite='%'`, `configuration=['pollable'=>1]`, `visibleOnCreate=true`.
    **PAS** de `configurationOnCreate['noPoll']` (invariant visible⇔pollée : visible par défaut ⇒ pollée par défaut).
  - **Gating multi-canal** : la commande n'est créée que si `channelId === '0'` (la batterie est un état
    **niveau device** ; `getDevicePowerInfo` prend un `deviceId`). Sur un device multi-canal, seul l'eqLogic
    du canal 0 expose `battery` → 1 appel/device/cycle (pas N). Si `channelId !== '0'` → suppression si présente.
- `batteryApplicable($model)` (tri-état, calqué `switchApplicable`, ordre IMPÉRATIF) :
  1. `$csv = trim(ability)` ; si `$csv === ''` → **`null`** (indéterminable, anti-destruction) ;
  2. si token `electric` présent dans le CSV → **`true`** ;
  3. CSV connu sans `Electric` → second gating IoT (court-circuit `productId`, comme `createNightVisionCommands`) :
     - non-IoT (`productId` vide) → **`false`** (capacité non supportée) ;
     - IoT + `$model === null` → **`null`** (modèle injoignable → indéterminable) ;
     - IoT + `$model` → `modelHasBatteryRef($model)` (**`true`/`false`**).
- `modelHasBatteryRef($model)` : parcourt `model.properties`, `true` si une propriété a `ref` ∈ `{'11600','106200'}`.

### Polling — `refreshStates()`, **AVANT le court-circuit skip-offline**
> ⚠️ Décision d'archi : un appareil sur batterie est **normalement dormant** (`onLine "4"=sleeping` →
> `refreshOnline()` retourne `false`). Si la batterie était lue après le skip-offline (comme les autres
> phases), elle ne serait **jamais** rafraîchie pour un dormant → l'UC perdrait son sens. Donc :
> `refreshOnline` est appelé (met à jour `online`), `pollDisabledSet()` puis `refreshBattery()` s'exécutent,
> ET SEULEMENT ENSUITE le `return` skip-offline coupe les autres phases. `readPowerInfo` réveille le dormant
> (DV1030). Coût pour les caméras non-batterie offline : `pollDisabledSet()` (1 requête DB) + `refreshBattery`
> qui no-ope immédiatement (pas d'appel cloud) — négligeable.

- `refreshBattery($deviceId, $pollDisabled)` (**pas** de `$model` : endpoint non-IoT) :
  - no-op si `getCmd(null,'battery')` absent, ou si `pollDisabled['battery']` (UC73, log debug) ;
  - `readPowerInfo($deviceId)` → `extractBatteryPercent($data)` → `checkAndUpdateCmd('battery', $pct)` si non null ;
  - **NE LÈVE JAMAIS** (catch `imouException` + `Throwable`, log warning). `imouApi::$quotaCodes` en silentCodes.
- `readPowerInfo($deviceId)` :
  - `callWithToken('getDevicePowerInfo', ['deviceId'=>$deviceId], imouApi::$quotaCodes)` ;
  - sur `imouException` code `DV1030` → `callWithToken('wakeUpDevice', ['deviceId'=>$deviceId, 'url'=>'/device/wakeup'])`
    puis **1** relecture `getDevicePowerInfo` ; si la relecture relève encore `DV1030` → log **debug** (attendu,
    réveil en cours) + retour `array()` (pas d'écrasement). **Sans `sleep`** (cron synchrone mono-thread, calque HA).
- `extractBatteryPercent($data)` :
  - 1re entrée exploitable de `electricitys[]` ; `(int)electric` prioritaire si `> 0` (borné 0-100) ;
  - sinon `min` des cellules présentes `litElec`/`alkElec` (cellule limitante, bornée) ;
  - sinon (`electric` présent = "0", pas de cellules) → `0` ; sinon `null` (rien d'exploitable → pas d'update).

## Server vs Client
100 % serveur (PHP, contexte cron + postSave). Aucune surface client : info-only, pas de widget dédié
(la commande numérique s'affiche avec le widget core standard + unité `%`).

## Validation
- **Création** : gating tri-état strict (anti-destruction sur ability/modèle indéterminé) + gating canal 0.
- **Polling** : chaque méthode ne lève jamais (cron robuste) ; cast String→int défensif ; bornage 0-100 ;
  pas d'écrasement de l'état courant sur réponse inexploitable.
- **DV1030** : réveil + 1 relecture borné (pas de boucle) ; 2e DV1030 = debug + skip ce cycle.

## Server Actions / API
- `getDevicePowerInfo(deviceId)` (lecture) — polling, `$quotaCodes` silencieux.
- `wakeUpDevice(deviceId, url:/device/wakeup)` (réveil interne) — déclenché seulement sur DV1030.
- Aucun `imouCmd::execute` ajouté (pas d'action utilisateur).

## UC77 (quota)
Aucun code spécifique : `battery` étant une info pollée (non-noPoll), `estimateCallsPerCycle()` la compte
**+1 appel/cycle** (1 par device, grâce au gating canal 0). Conforme au principe conservateur UC77
(sur-estimation volontaire ⇒ intervalle plus long ⇒ sous budget). Couplé au skip-offline existant.

## Dépendances
Aucune. PHP natif, endpoints cloud existants via `imouApi::callWithToken`.

## Hors scope (différé)
- `battery_type` (info du `type` d'alimentation) et seuil **batterie faible** (`battery_low` + config seuil) :
  optionnels, non requis par les critères d'acceptation. À reprendre dans une itération ultérieure.
- Commande action « réveiller » exposée à l'utilisateur : réveil interne suffisant en V1.

## i18n (FR ; traduction différée étape 10)
- 1 nouvelle chaîne UI : `'Niveau de batterie'` (nom de commande, enveloppée `__()` par `creerCommande`).
