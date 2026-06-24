# Spec technique — mvp 05 decouverte appareils

> Spec fonctionnelle : `.memory/specs/MVP/05-decouverte-appareils.md`
> Dépend de : 03 (token, via `imouApi::callWithToken()`).

## Contrat API IMOU (vérifié dans la doc)

**Endpoint retenu : `listDeviceDetailsByPage`**
(`https://open.imoulife.com/book/en/http/device/manage/query/listDeviceDetailsByPage.html`).

Choisi plutôt que `deviceBaseList` car il renvoie **toutes les métadonnées en un seul appel**
(modèle, capacités, état, canaux) là où `deviceBaseList` ne renvoie que `deviceId` + `channels`
minimaux et imposerait des appels supplémentaires.

> ⚠️ **Écart spec interne ↔ doc** : la spec 05 citait `bindId`/`limit` comme params de pagination.
> La doc officielle confirme que ceux-ci appartiennent à `deviceBaseList` (pagination par **curseur**).
> `listDeviceDetailsByPage` utilise une pagination **par offset** : `page` + `pageSize`, avec un
> champ `count` (total). La doc fait autorité → on code l'offset.

### Requête
| Param | Type | Oblig. | Valeur |
|---|---|---|---|
| `pageSize` | int | oui | `50` (plage admise 1-50) |
| `page` | long | oui | 1, 2, … (commence à 1) |
| `token` | string | oui | injecté automatiquement par `imouApi::callWithToken()` |
| `source` | string | non | non envoyé (défaut API `bindAndShare`) |

### Réponse (`result.data`)
- `count` (int) — nombre total d'appareils du compte.
- `deviceList[]` — objets device :
  - `deviceId`, `deviceName`, `deviceModel`
  - `deviceStatus` (`online|offline|sleep|upgrading`), `deviceAbility` (CSV)
  - `channelList[]` : `channelId`, `channelName`, `channelStatus`
    (`online|offline|sleep|upgrading`), `channelAbility` (CSV), `cameraStatus` (`on|off`)

## Architecture

- **Fichier modifié (unique)** : `core/class/imou.class.php`, classe `imou`.
- **Aucun** fichier front, AJAX, i18n, dépendance.

### Constante
- `imou::DISCOVER_MAX_PAGES = 20` — garde-fou anti-boucle infinie (50 × 20 = 1000 devices,
  largement au-dessus du palier gratuit de 5).
- `imou::DISCOVER_PAGE_SIZE = 50` — taille de page (max admis par l'API).

### Méthodes
- **`public static function discoverDevices(): array`** — orchestration :
  1. Boucle `page = 1 → DISCOVER_MAX_PAGES`.
  2. Appelle `self::fetchDevicePage($page)`.
  3. Pour chaque device de la page, accumule `self::normalizeDevice($device)` (0..n entrées).
  4. **Arrêt** quand : `count(devices accumulés) >= count` (total renvoyé page 1),
     OU une page renvoie un `deviceList` vide, OU `DISCOVER_MAX_PAGES` atteint (log warning).
  5. Compte vide → retourne `[]` (pas d'erreur).
  6. Log `info` du nombre de caméras normalisées ; log `warning` si `count > 5`
     (palier gratuit développeur) — valeur castée `(int)`.
  7. `imouException` éventuelle laissée **remonter** à l'appelant (tâche 06).

- **`private static function fetchDevicePage(int $page): array`** — un appel API :
  `imouApi::callWithToken('listDeviceDetailsByPage', ['pageSize' => self::DISCOVER_PAGE_SIZE, 'page' => $page])`.
  Retourne le `data` brut (`['count' => int, 'deviceList' => array]`), avec valeurs par défaut
  sûres si champs absents.

- **`private static function normalizeDevice(array $device): array`** — mapping d'un device vers
  ses entrées canal :
  - `channelList` **absent OU vide** → `[]` + log `warning` (device sans canal ignoré).
  - Pour chaque canal :
    - `deviceId` ← `$device['deviceId']`
    - `channelId` ← `$channel['channelId']`
    - `name` ← `channelName` si non vide, sinon `deviceName` (mono-canal / NVR)
    - `model` ← `deviceModel` sinon `''` (peut être vide sans erreur)
    - `ability` ← `channelAbility` si non vide, sinon `deviceAbility` (capacités par canal)
    - `online` ← `(channelStatus ?: deviceStatus) === 'online'` (bool)

## Server vs Client
**Back-end only.** Méthode statique PHP consommée par la tâche 06 (création des eqLogic).
Aucune partie front/Client, aucun rendu HTML, aucun endpoint AJAX dans cette tâche.

## Validation
- **Parsing défensif** : `isset`/`is_array`/cast systématiques sur tous les champs issus de la
  réponse API (jamais d'accès direct à une clé non vérifiée).
- **Sécurité logs** : aucun secret/token loggué ; champs API castés/`(int)` avant log
  (cohérent avec `imouApi::sanitizeLog`).
- **Robustesse pagination** : double condition d'arrêt (total atteint + page vide) + borne dure.
- **Compte vide** : `count = 0` / `deviceList = []` → `[]` sans exception (critère d'acceptation).

## Server Actions / API
- `imou::discoverDevices(): array` — liste plate d'entrées
  `['deviceId','name','channelId','model','ability','online']`.
- `imou::fetchDevicePage(int $page): array` (privée) — `['count'=>int,'deviceList'=>array]`.
- `imou::normalizeDevice(array $device): array` (privée) — `array` d'entrées canal.
- Réutilise `imouApi::callWithToken()` (token + refresh réactif + rejeu) — **aucun** nouvel
  appel HTTP direct.

## Dépendances
Aucune (cURL + core Jeedom déjà en place).

## Impact i18n
**Aucune chaîne UI.** Méthode interne ; les logs FR ne sont pas traduits. Rien à déléguer au
`translator` (Étape 10 sans objet pour cette tâche).
