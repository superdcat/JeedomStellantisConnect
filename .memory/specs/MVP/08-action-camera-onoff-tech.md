# Spec technique — mvp 08 action camera onoff

> Spec fonctionnelle : `.memory/specs/MVP/08-action-camera-onoff.md`
> Dépend de : 07 (commandes `camera_on`/`camera_off`/`camera_state` déjà créées par `createCommands()`).

## Contrat API IMOU
**Endpoint** : `setDeviceCameraStatus` (doc IMOU `http/device/config/ability/setDeviceCameraStatus.html`,
vérifiée le 2026-06-17). Paramètres :

| Param | Type | Requis | Note |
|---|---|---|---|
| `token` | String | oui | injecté automatiquement par `imouApi::callWithToken()` |
| `deviceId` | String | oui | n° de série de l'appareil |
| `channelId` | String | non | requis si l'enable est de niveau **canal** ; omis si niveau device |
| `enableType` | String | oui | 1re lettre minuscule — ici `"closeCamera"` |
| `enable` | **Boolean** | oui | `true` = actif / `false` = inactif |

Réponse : `result.code` + `result.msg` uniquement, **aucun champ `data`** (succès = `code === '0'`,
déjà géré par `imouApi::call()` qui retourne `array()` vide dans ce cas).
Prérequis : `accessType=PaaS`.

**Inversion `closeCamera`** (confirmée doc + spec UC07) : `closeCamera=true` ⇒ caméra **éteinte**.
Donc :
- `camera_on`  → `enable=false` (closeCamera désactivé ⇒ caméra allumée) ⇒ `camera_state = 1`
- `camera_off` → `enable=true`  (closeCamera activé ⇒ caméra éteinte)  ⇒ `camera_state = 0`

→ `camera_state` (1=allumée / 0=éteinte) = **inverse** du flag `closeCamera`.

## Architecture
Un seul fichier modifié : **`core/class/imou.class.php`**, classe **`imouCmd`** uniquement
(la classe `imou` et `imouApi` ne changent pas).

### 1. `imouCmd::execute($_options)` (hook du core, aujourd'hui vide)
Routeur sur `$this->getLogicalId()` :
- `camera_on`  → `actionCameraStatus($eqLogic, false)` (closeCamera=false ⇒ allumée).
- `camera_off` → `actionCameraStatus($eqLogic, true)`  (closeCamera=true ⇒ éteinte).
- `default`    → `log::add('imou','debug', …)` « action non gérée » puis `return;` explicite.
  Couvre `surveillance_on`/`surveillance_off`, dont l'exécution est livrée en **UC09** (hors
  périmètre UC08). Niveau `debug` (pas `warning`) : situation normale tant que UC09 n'est pas codé.

Garde en tête de méthode : `$eqLogic = $this->getEqLogic();` — si `!is_object($eqLogic)`
(commande orpheline, eqLogic supprimé sans purge des cmd), on lève `imouException(CODE_CONFIG)`
pour éviter un fatal sur `$eqLogic->getConfiguration(...)`.

### 2. `imouCmd::actionCameraStatus($eqLogic, $closeCamera)` (nouvelle méthode privée)
- Lit `$deviceId = (string) $eqLogic->getConfiguration('deviceId', '')` et
  `$channelId = (string) $eqLogic->getConfiguration('channelId', '')`.
- **Garde** : `deviceId` vide → `log::add('imou','error', …)` + `imouException(CODE_CONFIG)`.
- Construit les params : `deviceId`, `enableType='closeCamera'`, `enable=(bool)$closeCamera` ;
  `channelId` ajouté **seulement si non vide** (param optionnel niveau canal).
  - `enable` est passé en **vrai booléen PHP** : `json_encode` (dans `imouApi::call`) produit alors
    `true`/`false` JSON. **Premier usage** d'un booléen vers `callWithToken` → commentaire d'ancrage.
- Appel : `imouApi::callWithToken('setDeviceCameraStatus', $params)` (token + refresh réactif déjà
  portés par `callWithToken` ; le rejeu unique sur erreur token est transparent ici).
- **Succès** : update optimiste `$eqLogic->checkAndUpdateCmd('camera_state', $closeCamera ? 0 : 1)`
  pour un retour visuel immédiat (le cron UC10 reconfirmera la vérité cloud). Ne déclenche PAS de
  récursion (`checkAndUpdateCmd` ne `save()` pas l'eqLogic ; le core ne rappelle pas `postSave`).
- **Erreur API** : l'`imouException` levée par `callWithToken`/`call` **remonte telle quelle**
  (Jeedom l'affiche à l'utilisateur). Un `log::add('imou','error', …)` est posé avant remontée.

Anti log-injection : `deviceId` et `logicalId` proviennent de l'API IMOU → neutralisation CRLF
inline `str_replace(["\r","\n"], ' ', …)` avant tout log (même pattern que `normalizeDevice()`
ligne 214 et `createCommands()` ligne 424). `imouApi::sanitizeLog()` étant privée à `imouApi`,
l'inline est l'approche retenue.

## Server vs Client
Vocabulaire Next.js inadapté. Ici **100 % back-end PHP** : aucune UI, aucun JS, aucun endpoint
AJAX. Le déclenchement vient du clic utilisateur sur le bouton d'action de la page équipement, que
le core route vers `imouCmd::execute()`.

## Validation
- **Serveur** :
  - Garde `is_object($eqLogic)` → pas de fatal sur commande orpheline.
  - Garde `deviceId` non vide → aucun appel cloud si config incomplète.
  - `enable` typé `bool` → contrat API respecté (Boolean, pas string).
  - `channelId` envoyé seulement si présent (param optionnel).
  - Update optimiste cohérent avec l'inversion `closeCamera` (1=allumée/0=éteinte).
  - Erreur API non silencieuse : log `error` + remontée d'exception.
- **Client** : N/A (aucune UI).

## Server Actions / API
- `imouCmd::execute(array $_options = array()): void` → routeur logicalId.
- `imouCmd::actionCameraStatus(eqLogic $eqLogic, bool $closeCamera): void` (privé) → appel cloud +
  update optimiste.

## Dépendances
Aucune.

## Impact i18n (FR uniquement — traduction déléguée à l'Étape 10)
**Décision (option A) : aucune nouvelle clé i18n.** Les messages d'`imouException` levés par UC08
restent en **français brut, non enveloppés `__()`**, par cohérence avec l'existant : `imouApi::call`
et `imouApi::getToken` lèvent déjà leurs messages d'erreur en FR brut. Ce sont des erreurs
techniques internes (config/code anormaux : deviceId manquant, commande orpheline), pas du contenu
UI normal. Le retour métier en cas d'échec API reste le message IMOU déjà géré par `imouApi::call`.
→ L'agent `translator` (Étape 10) n'aura rien à produire pour cette UC.
