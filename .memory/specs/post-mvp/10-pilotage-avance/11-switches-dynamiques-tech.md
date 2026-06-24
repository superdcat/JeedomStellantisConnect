# Spec technique — postmvp 01 switches dynamiques (UC12)

> Spec fonctionnelle : `.memory/specs/post-mvp/10-pilotage-avance/11-switches-dynamiques.md`
> Refactor de : UC07 (createCommands), UC08/09 + post-MVP 01 (execute/setCameraEnable), UC10 (refreshStates).

## Contrat API IMOU
**Aucun nouvel appel cloud.** Refactor interne. Le gating utilise le **CSV `ability` déjà stocké**
par eqLogic à la découverte (UC05/06, champ `configuration.ability`). Identifiants `ability`
(source `imouapi` / `faq/ability.html`, 2026-06-17) : `CloseCamera`, `AlarmMD`, `WhiteLight`/`WLV2`,
`LinkageSiren`, `Siren`. Pilotage/lecture inchangés (`setDeviceCameraStatus`/`getDeviceCameraStatus`).

## Architecture
Un seul fichier : `core/class/imou.class.php`. **Catalogue déclaratif unique = source de vérité.**

### 1. `imou::switchCatalog()` (static, NOUVEAU)
Retourne un tableau clé=`enableType` → entrée :
`['on','off','state'(|null), 'nameOn','nameOff','nameState'(|null), 'inverse'(bool), 'ability'(array|null), 'poll'(bool)]`.
- **Socle** (`ability=null`, toujours créé) : `closeCamera` (inverse=true, state camera_state),
  `motionDetect` (state surveillance_state).
- **Optionnels** (gatés) : `whiteLight`→`WhiteLight`/`WLV2`, `linkageSiren`→`LinkageSiren` (states pollés) ;
  `siren`→`Siren` (state=null, poll=false, momentané ; ability `Siren` non confirmée → futur probe possible).
- `inverse` gouverne **écriture ET lecture** de façon cohérente (un switch est inversé partout ou nulle part).

### 2. `imou::switchApplicable($entry)` (instance, NOUVEAU) — gating tri-état
- `ability===null` → **true** (socle).
- sinon CSV `ability` de l'eqLogic **vide** → **null** (indéterminable : ni création ni suppression).
- sinon match **insensible à la casse** d'un flag dans le CSV (split virgule, comparaison par token) →
  **true** si présent, **false** sinon.

### 3. `imou::createCommands()` (refactor)
Piloté par le catalogue, ordre impératif **infos → actions → suppressions** :
1. Crée les **infos** applicables (`applicable===true && state!==null`), mémorise leur id.
2. Crée les **actions** applicables (liées à leur info via `value`). `applicable===false` → entrée
   ajoutée à la liste « à supprimer ». `applicable===null` → log warning, **rien**.
3. **Supprime en dernier** (`$cmd->remove()`) les entrées `false` : on/off **et** info ensemble
   (pas de lien orphelin). **Uniquement** les logicalId du catalogue. Pas de récursion (`cmd::remove`
   ne rappelle pas `eqLogic::postSave`).

### 4. `imouCmd::execute()` (refactor)
Table inverse `logicalId → (enableType, on/off)` dérivée du catalogue → un seul chemin générique
`actionSwitch($eqLogic, $enableType, $on, $entry)`. Remplace le switch hardcodé.

### 5. `imouCmd::actionSwitch()` (NOUVEAU — fusionne `actionCameraStatus` + `actionSwitchDirect`)
- `enableValue = $entry['inverse'] ? !$on : $on` (closeCamera : allumer ⇒ enable=false).
- `setCameraEnable($eqLogic, $enableType, $enableValue)`.
- update optimiste si `state!==null` : `checkAndUpdateCmd($state, $on ? 1 : 0)` (**état logique =
  intention utilisateur**, indépendant de l'inversion du flag API).
- `actionCameraStatus` et `actionSwitchDirect` **supprimées**.

### 6. `imouCmd::setCameraEnable()` — whitelist dérivée
`in_array($enableType, array_keys(imou::switchCatalog()), true)` → fin du doublon.

### 7. `imou::refreshStates()` (refactor)
Boucle sur le catalogue : poll si `poll && state!==null && switchApplicable===true`. Mapping
`valeur = (int)($entry['inverse'] ? !$actif : $actif)` (`$actif = status==='on'`). Robustesse UC10
inchangée (try/catch imouException + Throwable, ne lève jamais ; status vide → no-op).

## Server vs Client
100 % back-end PHP. Aucune UI/JS/AJAX nouvelle.

## Validation
- Socle toujours créé (ability=null), jamais supprimé → aucune régression MVP.
- Gating tri-état : `ability` vide → **ni création ni suppression** (anti-destruction sur données
  incomplètes) + log. Suppression **seulement** si flag explicitement absent d'un CSV non vide.
- Suppression EN DERNIER, actions+info ensemble, logicalId catalogue uniquement → pas de lien `value`
  cassé, pas d'oscillation.
- Idempotence : re-sync sans changement d'ability → 0 création/suppression (creerCommande idempotent,
  getCmd par logicalId).
- `closeCamera` reste inversé (écriture + lecture) après fusion des chemins.
- Whitelist dérivée du catalogue → divergence impossible.

## Server Actions / API
- `imou::switchCatalog(): array` ; `imou::switchApplicable(array $entry): ?bool`.
- `imou::createCommands()` / `imou::refreshStates()` refactorées.
- `imouCmd::execute()` refactoré ; `imouCmd::actionSwitch(eqLogic, string, bool, array): void` (NOUVEAU,
  fusion) ; `setCameraEnable()` whitelist dérivée. **Supprimées** : `actionCameraStatus`, `actionSwitchDirect`.

## Dépendances
Aucune.

## Tests (recette manuelle — `validation-manuelle.md`)
(a) ability vide → toutes commandes optionnelles ni créées ni supprimées (log) ; (b) flag présent →
commande créée ; (c) flag absent (CSV non vide) → commande absente/supprimée ; (d) re-sync sans
changement → idempotent (0 churn) ; (e) closeCamera reste inversé (clic + refresh).

## Impact i18n (FR uniquement)
**Aucune nouvelle chaîne UI** : on **migre** dans le catalogue les libellés FR déjà existants
(camera/surveillance/projecteur/sirène), déjà enveloppés et déjà traduits. → `translator` no-op.
