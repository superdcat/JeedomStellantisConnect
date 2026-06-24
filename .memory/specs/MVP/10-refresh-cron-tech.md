# Spec technique — mvp 10 refresh cron

> Spec fonctionnelle : `.memory/specs/MVP/10-refresh-cron.md`
> Dépend de : 07 (cmd info `camera_state`/`surveillance_state`), 08/09 (sémantique des switches).

## Contrat API IMOU
**Endpoint** : `getDeviceCameraStatus` (doc IMOU `http/device/config/ability/getDeviceCameraStatus.html`,
vérifiée le 2026-06-17). **Un `enableType` par appel** → 2 appels par caméra.

| Param | Type | Requis | Note |
|---|---|---|---|
| `token` | String | oui | injecté par `imouApi::callWithToken()` |
| `deviceId` | String | oui | n° de série |
| `channelId` | String | non | ajouté si niveau canal (non vide) |
| `enableType` | String | oui | `"closeCamera"` puis `"motionDetect"` |

Réponse : `result.data = { enableType, status }` où **`status` est une STRING `"on"`/`"off"`**
(⚠️ **pas un booléen**). Prérequis `accessType=PaaS`.

**Mapping status → valeur cmd info** (réutilise l'asymétrie UC08/09) :
- `closeCamera` → `camera_state = (status === 'on') ? 0 : 1` *(INVERSÉ : "on" = caméra fermée = éteinte)*
- `motionDetect` → `surveillance_state = (status === 'on') ? 1 : 0` *(DIRECT)*
- `status` vide/inconnu (réponse anormale) → on **n'update pas** (évite d'écraser un état valide).

Voir [[imou-switches-setdevicecamerastatus-inversion]] et l'analyse interne
`.memory/analyse/imou-api-vs-imouapi.md` (palier 5 appareils, quota à l'appel).

## Architecture
Un seul fichier modifié : **`core/class/imou.class.php`**, classe **`imou`**.
`imouCmd`/`imouApi` inchangées. `Cron\CronExpression` est **fournie nativement par le core**
(`core/class/cron.class.php` l'utilise lui-même, lignes 111/330) → **aucune dépendance à déclarer**
dans `packages.json` (qui ne porte que les deps système/pip).

### 1. `imou::refreshStates()` *(méthode d'instance)*
Rafraîchit **un** équipement. **Ne lève JAMAIS** (commentaire explicite) → protège la boucle cron.
- `deviceId` vide → log `debug` + `return` (équipement non configuré, pas une erreur).
- Pour `closeCamera` puis `motionDetect` : `lireEtatSwitch($enableType)` (try/catch **par appel** :
  un switch non supporté/injoignable n'empêche pas l'autre) ; si `status` non vide → mappe →
  `checkAndUpdateCmd(<cmd>, <valeur>)`. Pas de récursion (checkAndUpdateCmd ne `save()` pas l'eqLogic).
- Anti log-injection CRLF sur `deviceId`/code d'erreur (pattern existant).

### 2. `imou::lireEtatSwitch($enableType)` *(méthode d'instance privée)*
- Lit `deviceId`/`channelId` de la config ; construit params (`channelId` si non vide).
- `imouApi::callWithToken('getDeviceCameraStatus', $params)` ; retourne `(string) $data['status']`
  (ou `''` si absent). Lève `imouException` (relayée au caller qui la catch).

### 3. `imou::cron5()` *(static, cadence défaut 5 min)*
- `eqLogic::byType('imou', true)` (activés uniquement) ; guard `is_object()` (pattern `syncEquipments`).
- **Ne traite que les équipements SANS `autorefresh`** (prédicat centralisé : `autorefresh` vide) →
  cadence par défaut. `refreshStates()` par équipement.

### 4. `imou::cron()` *(static, chaque minute)*
- `eqLogic::byType('imou', true)` ; guard `is_object()`.
- **Ne traite que les équipements AVEC `autorefresh` renseigné** (ensemble disjoint de cron5 → pas
  de double refresh). Évalue l'expression via le pattern core :
  `new Cron\CronExpression(checkAndFixCron($autorefresh), new Cron\FieldFactory)` ; si `isDue()` →
  `refreshStates()`. **try/catch par équipement** : une expression cron invalide log `error` + continue.

> Prédicat disjoint = présence/absence de `autorefresh` (one-liner symétrique dans les 2 crons) :
> divergence quasi-impossible, aucun double refresh.

## Server vs Client
100 % back-end PHP (hooks cron du core). Aucune UI/JS/AJAX nouvelle (le champ `autorefresh` et son
assistant cron existent déjà dans `desktop/php/imou.php`).

## Validation
- **Serveur** :
  - `byType('imou', true)` → jamais les équipements désactivés ; guard `is_object()` par itération.
  - Guard `deviceId` vide → skip (pas d'appel cloud).
  - try/catch **par appel** dans `refreshStates` → un switch en échec n'affecte pas l'autre, et
    `refreshStates` ne propage rien (boucle cron protégée).
  - try/catch **par équipement** dans `cron()` → une `autorefresh` invalide ne casse pas la boucle.
  - Mapping `status` string : inversion `closeCamera`, direct `motionDetect` ; `status` vide → no-op.
  - Budget ≤ caméras × 2 appels/cycle ; token mutualisé (cache `callWithToken`).
  - Quota 5 appareils → erreur de licence absorbée par le try/catch (loggée, boucle continue).
- **Client** : N/A.

## Server Actions / API
- `imou::cron(): void` *(static)* — refresh des équipements avec `autorefresh` dû.
- `imou::cron5(): void` *(static)* — refresh des équipements sans `autorefresh`.
- `imou::refreshStates(): void` *(instance)* — refresh d'un équipement, ne lève jamais.
- `imou::lireEtatSwitch(string $enableType): string` *(instance privée)* — lit `status` d'un switch.

## Dépendances
Aucune (à déclarer). `Cron\CronExpression` est native au core Jeedom.

## Tests (recette manuelle — `.memory/specs/post-mvp/80-livraison/81-validation-manuelle.md`)
Cas à couvrir : (1) `closeCamera status='on'` → `camera_state=0` ; (2) `closeCamera status='off'` →
`camera_state=1` ; (3) `motionDetect status='on'` → `surveillance_state=1` ; (4) une `imouException`
sur un équipement n'interrompt pas le refresh des autres ; (5) `autorefresh` invalide n'interrompt
pas `cron()`.

## Impact i18n (FR uniquement — traduction déléguée à l'Étape 10)
**Aucune nouvelle chaîne UI.** Le label `{{Auto-actualisation}}` existe déjà dans le formulaire.
Crons = backend pur ; les `log::add` sont des messages techniques FR brut (non enveloppés, cohérent
avec l'existant). → L'agent `translator` (Étape 10) n'aura rien à produire pour cette UC.
