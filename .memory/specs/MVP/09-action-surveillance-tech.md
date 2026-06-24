# Spec technique — mvp 09 action surveillance

> Spec fonctionnelle : `.memory/specs/MVP/09-action-surveillance.md`
> Dépend de : 07 (commandes `surveillance_on`/`surveillance_off`/`surveillance_state` déjà créées
> par `createCommands()`) et 08 (schéma d'action via `setDeviceCameraStatus`, refactoré ici).

## Contrat API IMOU
**Endpoint** : `setDeviceCameraStatus` (même que UC08 ; doc IMOU
`http/device/config/ability/setDeviceCameraStatus.html`, vérifiée le 2026-06-17). Paramètres :

| Param | Type | Requis | Note UC09 |
|---|---|---|---|
| `token` | String | oui | injecté par `imouApi::callWithToken()` |
| `deviceId` | String | oui | n° de série de l'appareil |
| `channelId` | String | non | requis car `motionDetect` est un switch de **niveau canal** ; omis si vide |
| `enableType` | String | oui | 1re lettre minuscule — ici `"motionDetect"` |
| `enable` | **Boolean** | oui | `true` = surveillance ACTIVE / `false` = inactive |

Réponse : `result.code` + `result.msg` uniquement, **aucun champ `data`** (succès = `code === '0'`,
géré par `imouApi::call()` qui retourne `array()` vide). Prérequis : `accessType=PaaS`.

**`motionDetect` confirmé capability switch** (doc IMOU `faq/feature.html`, 2026-06-17) : scope
**canal**, sens « Motion detection enable ». **Pas d'inversion** : `enable=true` ⇒ surveillance
active ⇒ `surveillance_state = 1`. C'est l'**asymétrie clé** avec `closeCamera` de UC08 (où
`enable=true` ⇒ caméra éteinte ⇒ `camera_state = 0`).

> **Écart documentaire signalé** : le doc INDEX (`.memory/external/doc/imou/INDEX.md`) listait
> `modifyDeviceAlarmStatus` pour la surveillance MVP09. La doc IMOU recommande explicitement
> `setDeviceCameraStatus` pour les appareils **PaaS** (notre prérequis) → on suit la spec UC09 et
> l'analyse interne. INDEX à corriger en capitalisation (Étape 12).

## Architecture
Un seul fichier modifié : **`core/class/imou.class.php`**, classe **`imouCmd`** uniquement
(`imou` et `imouApi` inchangées). Les commandes `surveillance_*` existent déjà (UC07).

### 1. `imouCmd::setCameraEnable($enableType, $enableValue)` — **nouveau helper privé**
Extraction du tronc commun de l'appel `setDeviceCameraStatus` (mutualisé avec UC08) :
- Lit `deviceId`/`channelId` depuis `$this->getEqLogic()` (déjà garanti `is_object` par `execute`).
- **Garde** : `deviceId` vide → `log::add('imou','error', …)` + `imouException(CODE_CONFIG)`.
- Construit `deviceId` + `enableType=$enableType` + `enable=(bool)$enableValue` ; `channelId` ajouté
  **seulement si non vide** (switch niveau canal).
- Appel `imouApi::callWithToken('setDeviceCameraStatus', $params)` dans un try/catch :
  **log error + remontée** de l'`imouException` (anti log-injection CRLF sur `deviceId` ET
  `e->getImouCode()` centralisé ici, plus dupliqué chez les appelants).
- **Périmètre strict** : NE fait PAS l'update optimiste ni le log succès — laissés aux appelants car
  la sémantique enable→état est propre à chaque `enableType` (cf. asymétrie). Commentaire JSDoc.
- Paramètre nommé `$enableValue` (pas `$enable`) pour ne pas suggérer une sémantique « actif ».

### 2. `imouCmd::actionCameraStatus($eqLogic, $closeCamera)` — **refactoré (UC08)**
- Délègue l'appel à `setCameraEnable('closeCamera', $closeCamera)`.
- Conserve son update optimiste **inversé** : `checkAndUpdateCmd('camera_state', $closeCamera ? 0 : 1)`
  + log info. `$eqLogic` reste passé (cohérence de signature, lisibilité de l'appelant `execute`).

### 3. `imouCmd::actionSurveillanceStatus($eqLogic, $enable)` — **nouvelle méthode (UC09)**
- Délègue à `setCameraEnable('motionDetect', $enable)`.
- Update optimiste **direct** : `checkAndUpdateCmd('surveillance_state', $enable ? 1 : 0)`
  — **explicitement non inversé**, commentaire pointant l'asymétrie avec `camera_state`.
- Log info (« surveillance activée/désactivée », anti-CRLF sur deviceId).

### 4. `imouCmd::execute($_options)` — routeur étendu
Ajout de deux `case` avant le `default` :
- `surveillance_on`  → `actionSurveillanceStatus($eqLogic, true)`.
- `surveillance_off` → `actionSurveillanceStatus($eqLogic, false)`.
Message du `default` : retirer la mention « UC08 » devenue trompeuse → « action non gérée
(UC future) ».

## Server vs Client
100 % back-end PHP : aucune UI, aucun JS, aucun endpoint AJAX. Déclenché par le clic sur le bouton
d'action de la page équipement, que le core route vers `imouCmd::execute()`.

## Validation
- **Serveur** :
  - Garde `is_object($eqLogic)` (déjà dans `execute`) → pas de fatal sur commande orpheline.
  - Garde `deviceId` non vide (dans `setCameraEnable`) → aucun appel cloud si config incomplète.
  - `enable` typé `bool` → contrat API (Boolean) respecté.
  - `channelId` envoyé seulement si présent (switch niveau canal).
  - Update optimiste **direct** `surveillance_state = $enable ? 1 : 0` (asymétrie vs `camera_state`).
  - Erreur API non silencieuse : log `error` + remontée d'exception (anti-CRLF centralisé).
- **Client** : N/A (aucune UI).

## Server Actions / API
- `imouCmd::execute(array $_options = array()): void` → routeur logicalId (étendu).
- `imouCmd::setCameraEnable(string $enableType, bool $enableValue): void` (privé, **nouveau**) →
  appel cloud mutualisé + guard + erreur.
- `imouCmd::actionCameraStatus(eqLogic $eqLogic, bool $closeCamera): void` (privé, **refactoré**).
- `imouCmd::actionSurveillanceStatus(eqLogic $eqLogic, bool $enable): void` (privé, **nouveau**).

## Dépendances
Aucune.

## Impact i18n (FR uniquement — traduction déléguée à l'Étape 10)
**Aucune nouvelle clé i18n.** Comme UC08, les messages d'`imouException` restent en français brut,
non enveloppés `__()` (erreurs techniques internes : deviceId manquant, commande orpheline). Le
retour métier en cas d'échec API reste le message IMOU déjà géré par `imouApi::call`.
→ L'agent `translator` (Étape 10) n'aura rien à produire pour cette UC.
