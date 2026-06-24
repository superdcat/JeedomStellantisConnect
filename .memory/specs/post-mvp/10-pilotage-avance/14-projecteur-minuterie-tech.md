# Spec technique — post mvp 14 (projecteur à minuterie)

> Spec fonctionnelle : `.memory/specs/post-mvp/10-pilotage-avance/14-projecteur-minuterie.md`
> Dépend de : UC12 (catalogue/iotService), UC13 (auto-découverte propriétés, modelGet, getIotModelOrNull).
> Réfère : `.memory/analyse/imou-iot-things-model.md` (§ 4 services Cruiser 2C, § 6 contrat IoT).

## Décisions (validées utilisateur)
1. **Countdown** : identifiant de la sortie de `GetWhiteLightStatus` non documenté (model-specific) →
   résolution par **candidats explicites** (catalogue), **repli sur la 1re sortie `int`**, et **log
   debug des sorties réelles** au poll (découverte pour épingler l'exact ensuite).
2. **WhiteLightMode** (mode fixe/clignotant) : **non recodé** — déjà auto-exposé en select par UC13
   (`iotPropertyLabels` = « Mode projecteur »).

## Contrat API (confirmé doc 2026-06-18)
- `iotDeviceControl(productId, deviceId, ref, content)` :
  - `whiteLightStart`/`whiteLightStop` : services on/off (input `clientLocalTime` = `Y-m-d H:i:s`).
  - `GetWhiteLightStatus` : sortie dans **`result.data.content.outputData`** = objet **clé = `ref`**
    des paramètres de sortie. `ref` résolu via le `outputData` du service dans `getProductModel`.
- Aucun nouvel endpoint dans `imouApi` (callWithToken générique). `ref` services + sorties résolus
  dynamiquement (cache 7 j).

## Architecture — 1 fichier `core/class/imou.class.php`

### 1. Entrée catalogue `commandCatalog()` — projecteur minuté (service-only, IoT-only)
```php
'whiteLightTimer' => [
  'on'=>'projecteur_timer_on','off'=>'projecteur_timer_off','state'=>null,
  'nameOn'=>'Allumer projecteur (minuté)','nameOff'=>'Éteindre projecteur (minuté)','nameState'=>null,
  'inverse'=>false,'ability'=>['WhiteLight','WLV2'],'poll'=>false,
  'iotService'=>['on'=>'whiteLightStart','off'=>'whiteLightStop'],'requiresProductId'=>true,
]
```
- Clé `whiteLightTimer` = identifiant **INTERNE** (jamais passé à `setDeviceCameraStatus`) ; routage
  exclusif via `iotService` (documenté). `requiresProductId=true` ⇒ non créé hors IoT
  (`switchApplicable` false). `execute()` route vers `actionIotService` (inchangé).
- Distinct du `whiteLight` persistant (libellés « (minuté) », logicalId distincts).

### 2. Factorisation lecture/écriture service IoT — `imou::callIotService` (NOUVEAU, static)
`callIotService($productId,$deviceId,$identifier,$extraContent=[]) : array` — tronc commun :
résout le service (`resolveIotService`), construit `content` (auto `clientLocalTime` si déclaré en
`inputData`, + `$extraContent`), appelle `iotDeviceControl`, **retourne** `data.content.outputData`
(array) ou `[]`. Lève `imouException` (introuvable / API). Lecture **insensible à la casse**
(`modelGet`) — robustifie aussi la sirène.
- `imouCmd::actionIotService` **refactoré** : délègue à `imou::callIotService` (écriture, ignore la
  sortie), conserve gardes productId/deviceId + log + rethrow. Comportement sirène préservé.

### 3. Lecture du compte à rebours
- `imou::iotStatusCatalog()` (NOUVEAU, static) : services « status » à poller. Entrée
  `whiteLightTimer` → `service='GetWhiteLightStatus'`, `info='projecteur_timer_restant'`,
  `name='Projecteur minuté (durée restante)'`, `unit='s'`, `ability=['WhiteLight','WLV2']`,
  `requiresProductId=true`, `countdownCandidates=['countdown','remainTime','remainingTime','time',
  'whiteLightTime','WhiteLightTime','leftTime']`.
- `imou::createIotStatusCommands($logicalIdLog,$model)` (NOUVEAU) : `$model===null` → no-op. Sinon,
  par entrée : `switchApplicable($entry)` (réutilise le gating ability+requiresProductId ; null→skip)
  ET service présent au modèle (`resolveIotModelEntry`) → crée l'info `numeric` (unité s) ; sinon
  supprime l'info si présente. Appelé depuis `createCommands()`.
- `imou::resolveCountdownRef($service,$entry,$outputData)` (NOUVEAU) : depuis `service.outputData`
  (def) → 1) candidat explicite présent dans `outputData` ; 2) repli 1re sortie `type=int` présente ;
  log debug des sorties découvertes (identifier→ref). Retourne le `ref` ou null.
- `imou::refreshIotServiceStatuses($deviceId,$model)` (NOUVEAU) : `$model===null` → return. Par entrée
  applicable + service présent : `callIotService('GetWhiteLightStatus')` → `resolveCountdownRef` →
  `checkAndUpdateCmd(info, valeur)` si numérique. **Try/catch (imouException+Throwable), ne lève jamais.**

### 4. Mutualisation du chargement modèle (advisor)
- `createCommands()` : charge `$model = getIotModelOrNull()` **une fois**, le passe à
  `createIotCommands($logicalIdLog,$model)` (signature modifiée : ne re-fetch plus) ET
  `createIotStatusCommands($logicalIdLog,$model)`.
- `refreshStates()` : après la boucle switches, charge `$model` une fois, le passe à
  `refreshIotProperties($deviceId,$model)` (signature modifiée) ET `refreshIotServiceStatuses($deviceId,$model)`.

## Server vs Client
100 % back-end PHP. Aucune UI/JS/AJAX. Widgets rendus par le core.

## Validation
- On/off minuté ≠ persistant (libellés + logicalId distincts) ; gating ability projecteur + IoT.
- Anti-destruction : `$model===null` (non-IoT / réseau KO) → ni création ni suppression ; `postSave()`
  ne plante jamais ; `refreshStates()` ne lève jamais.
- Idempotence : re-sync sans changement → 0 churn (creerCommande idempotent, getCmd par logicalId).
- Countdown : candidats puis repli int ; jamais d'écrasement si rien trouvé (info inchangée) ; log
  debug pour découverte de l'identifiant réel.
- Quota : 1 appel `GetWhiteLightStatus`/cycle/caméra projecteur-IoT (valeur grossière ~5 min ; accepté).

## Server Actions / API
- `imou::commandCatalog()` (+1 entrée) ; `imou::iotStatusCatalog()`, `callIotService()`,
  `createIotStatusCommands()`, `resolveCountdownRef()`, `refreshIotServiceStatuses()` (NOUVEAUX) ;
  `createIotCommands()`/`refreshIotProperties()` (signature +$model) ; `imouCmd::actionIotService()`
  (refactoré, délègue à callIotService).

## Dépendances
Aucune.

## Tests (recette manuelle — `validation-manuelle.md`)
(a) cam projecteur IoT → boutons « Allumer/Éteindre projecteur (minuté) » présents et distincts du
persistant ; appui = allumage puis extinction auto ; arrêt = coupe ;
(b) info « durée restante » remonte (vérifier le log debug des sorties GetWhiteLightStatus pour
confirmer l'identifiant) ;
(c) cam sans projecteur / non IoT → aucune commande minutée ni info countdown ;
(d) réseau KO → pas de suppression, pas d'exception ;
(e) re-sync → idempotent.

## Impact i18n (FR uniquement — traduction Étape 10)
3 clés : `Allumer projecteur (minuté)`, `Éteindre projecteur (minuté)`, `Projecteur minuté (durée restante)`.
