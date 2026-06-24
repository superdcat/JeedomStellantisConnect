# Spec technique — postmvp 01 projecteur sirene

> Spec fonctionnelle : `.memory/specs/post-mvp/10-pilotage-avance/12-projecteur-sirene.md`
> Dépend de : MVP/07 (createCommands), MVP/08-09 (helper `setCameraEnable`), MVP/10 (refreshStates).

## Contrat API IMOU
Tout via **`setDeviceCameraStatus`** (même endpoint que UC08/09), `enable` Boolean, `channelId` si non
vide, **mapping DIRECT (aucune inversion)**. `enableType` confirmés sur `faq/feature.html` +
implémentation de référence `imouapi` (analyse interne `imou-api-vs-imouapi.md`), 2026-06-17 :

| Fonction | `enableType` | Mapping | Cmd info | Pollée |
|---|---|---|---|---|
| Projecteur on/off | `whiteLight` | direct | `projecteur_state` | ✅ |
| Sirène sur détection (armement) | `linkageSiren` | direct | `sirene_detection_state` | ✅ |
| Sirène manuelle (momentané) | `siren` | direct | — (aucune) | ❌ |

Lecture (cron) : `getDeviceCameraStatus` (1 enableType/appel, `status` STRING `"on"/"off"`).

**⚠️ Sirène manuelle — RÉSOLU via IoT (2026-06-17)** :
- `setDeviceCameraStatus(enableType='siren')` **échoue (code `40999`)** : la propriété `siren` du
  things model est en **`accessMode:"r"` (lecture seule, report-only)** → on ne peut pas la SET.
  Confirmé sur la Cruiser 2C (productId `QLDuttGT`) et cohérent avec le wontfix `imou_life #61`.
- **Le vrai déclenchement passe par des SERVICES IoT** via `iotDeviceControl` (pas
  setDeviceCameraStatus) : `SirenStart` (déclencher, input `clientLocalTime`) / `SirenStop` (arrêter).
  Le `ref` numérique (ex. 25500/22200) **varie selon le modèle** → résolu dynamiquement par
  `identifier` via `getProductModel` (mis en cache 7 j). Prérequis : appareil **IoT** (`productId`
  non vide) → gating `requiresProductId`.
- **Sirène manuelle = momentané** : la caméra l'arrête seule (capability `SirenTime`). **Aucune cmd
  info** `sirene_state` (un booléen persistant non réconcilié serait faux). Juste 2 boutons.
- Implémentation : entrée catalogue `siren` avec `iotService=['on'=>'SirenStart','off'=>'SirenStop']`
  + `requiresProductId=true` ; `imou::resolveIotService()`/`getProductModelCached()` ;
  `imouCmd::actionIotService()`. Cf. `11-switches-dynamiques-tech.md` (catalogue).

## Architecture
Un seul fichier : **`core/class/imou.class.php`**. **Création inconditionnelle** des commandes
(décision utilisateur : gating par `ability` reporté à UC12 — voir mise à jour de
`11-switches-dynamiques.md`).

### 1. `imou::createCommands()` — nouvelles commandes (inconditionnelles)
Infos (binary, historisées, pollées) : `projecteur_state`, `sirene_detection_state`.
Actions (liées à leur info via `value`, sauf sirène manuelle) :
- `projecteur_on`/`projecteur_off` → liées à `projecteur_state`.
- `sirene_detection_on`/`sirene_detection_off` → liées à `sirene_detection_state`.
- `sirene_on`/`sirene_off` → **sans `value`** (momentané, pas d'état). Ordre infos avant actions conservé.

### 2. `imouCmd::execute()` — routage étendu
Nouveaux `case` → **méthode générique** `actionSwitchDirect($eqLogic, $enableType, $enable, $stateCmd)` :
- `surveillance_on/off` → `('motionDetect', …, 'surveillance_state')` *(refactor : remplace
  `actionSurveillanceStatus`, désormais supprimée)*.
- `projecteur_on/off` → `('whiteLight', …, 'projecteur_state')`.
- `sirene_detection_on/off` → `('linkageSiren', …, 'sirene_detection_state')`.
- `sirene_on/off` → `('siren', …, null)` *(pas d'update d'état)*.
- `camera_on/off` → `actionCameraStatus` **inchangé** (seul cas INVERSÉ, garde sa méthode dédiée).

### 3. `imouCmd::actionSwitchDirect($eqLogic, $enableType, $enable, $stateCmd = null)` — NOUVELLE
Règle d'architecture : **switch direct → cette méthode** ; **switch inversé → `actionCameraStatus`**.
- `setCameraEnable($eqLogic, $enableType, (bool)$enable)`.
- Si `$stateCmd !== null` → `checkAndUpdateCmd($stateCmd, $enable ? 1 : 0)` (update optimiste direct).
- Log info (anti-CRLF deviceId).
`actionSurveillanceStatus` est **supprimée** (fusionnée ici), élimine le pattern divergent.

### 4. `imouCmd::setCameraEnable()` — whitelist étendue
`array('closeCamera','motionDetect','whiteLight','siren','linkageSiren')`. Commentaire ajouté :
couplage implicite avec `createCommands` → à unifier en catalogue déclaratif (TODO UC12).

### 5. `imou::refreshStates()` — polling étendu
Ajout au tableau `$switches` (DIRECT) : `whiteLight→projecteur_state`, `linkageSiren→sirene_detection_state`.
`siren` **exclu** (momentané). Polling : **4 appels/caméra/cycle** (était 2) — impact quota noté
(palier 5 appareils = 20/5 min ; cf. UC16). Robustesse inchangée (try/catch par appel, ne lève jamais).

## Server vs Client
100 % back-end PHP. Aucune UI/JS/AJAX nouvelle (commandes auto-affichées sur la page équipement).

## Validation
- **Serveur** : whitelist `enableType` étendue (sinon « enableType non géré ») ; `enable` bool ;
  `channelId` si présent ; mapping DIRECT ; update optimiste seulement si `$stateCmd` fourni
  (sirène manuelle = `null`) ; erreurs API non silencieuses (portées par `setCameraEnable`) ;
  polling robuste (UC10). Sirène manuelle : pas d'état persistant faux.
- **Client** : N/A.

## Server Actions / API
- `imouCmd::execute()` — routeur étendu (6 nouveaux logicalId + surveillance refactoré).
- `imouCmd::actionSwitchDirect(eqLogic, string $enableType, bool $enable, ?string $stateCmd): void` — NOUVELLE.
- `imouCmd::setCameraEnable()` — whitelist étendue.
- `imou::createCommands()` — +2 infos, +6 actions.
- `imou::refreshStates()` — +2 switches pollés.
- **Supprimée** : `imouCmd::actionSurveillanceStatus()`.

## Dépendances
Aucune.

## Impact i18n (FR uniquement — traduction déléguée à l'Étape 10)
Nouvelles chaînes UI (libellés de commande, enveloppées `__()` dans `creerCommande`) :
`Projecteur (état)`, `Allumer projecteur`, `Éteindre projecteur`, `Sirène sur détection (état)`,
`Activer sirène sur détection`, `Désactiver sirène sur détection`, `Déclencher sirène`,
`Arrêter sirène`. → `translator` (Étape 10) les portera dans en_US/de_DE/es_ES.
