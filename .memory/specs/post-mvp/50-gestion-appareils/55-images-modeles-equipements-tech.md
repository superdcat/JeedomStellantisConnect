# Spec technique — post mvp 55 (miniature d'équipement)

> **Révision (localisation des miniatures, 2026-06-24)** : la miniature ne pointe plus JAMAIS vers un
> serveur IMOU. Toute image captée (cliché live OSS / cover CDN) est **recopiée localement** sous
> `plugins/imou/data/thumb_<logicalId>.jpg` par `imou::localizeThumbnail()` (cURL → `downloadBinary()`
> avec rejeu borné sur la fenêtre d'upload OSS asynchrone 403/404), et c'est ce **chemin web local**
> (relatif, same-origin) qui est stocké dans `picUrl` et renvoyé par l'AJAX `refreshThumbnail`.
> `getImage()` valide ce chemin via `sanitizeLocalImagePath()` et y ajoute un cache-buster `?v=<mtime>`.
> Bénéfices : pas de fuite de Referer vers IMOU, pas d'image cassée à l'expiration OSS (~7 j), pas de
> blocage CSP. Migration des `picUrl` externes hérités : recopie locale **une fois** dans la branche
> *update* de `syncEquipments` (idempotente). Fichier supprimé en `postRemove()`. `data/` est gitignore.

> Décision (validée utilisateur 2026-06-19, **révisée 2026-06-19 ter — modèle FINAL**) : miniature
> d'équipement = image réelle de la caméra. **L'API IMOU n'expose AUCUN indicateur de mode de cover**
> (`listDeviceDetailsByPage` ne renvoie que `channelPicUrl`, sans `coverType`/`coverMode`/`picType` —
> vérifié doc 2026-06-19). On **abandonne l'auto-détection** : **l'utilisateur choisit la source** via un
> menu déroulant éditable, et ce choix pilote la récupération de l'image. Défaut création = cliché live.
> Récupération **immédiate** au changement de source + **aperçu** ; persistance via « Enregistrer ».

## Contrat API IMOU (confirmé doc 2026-06-19)
- **`channelPicUrl`** (String, niveau canal, `listDeviceDetailsByPage`) = **cover** du canal. Disponible à
  la découverte (UC05), URL absolue CDN/S3. **Pas de champ de mode** associé.
- **`setDeviceSnapEnhanced`** (params `deviceId`, `channelId` ; repli **`setDeviceSnap`**, max 1 capture/3 s)
  = capture un **cliché live** et renvoie **`result.data.url`** (image OSS, **valide ~7 jours**). **Appel
  facturé au quota** → usage parcimonieux. (`imouApi::callWithToken()` renvoie déjà `result.data` → lire
  `$data['url']`.)

## Architecture
Fichiers : `core/class/imou.class.php`, `core/ajax/imou.ajax.php`, `desktop/php/imou.php`, `desktop/js/imou.js`.

### Stockage (configuration eqLogic) — champs LIÉS au modèle (sauvés par « Enregistrer »)
- `imageUrl` — **override manuel** (URL personnalisée), **prioritaire**. Inchangé. Jamais touché automatiquement.
- `picUrl` — **miniature captée** (cliché live OU cover, selon la source). Posée **à la création** (serveur)
  et mise à jour **côté client** (input caché lié) lors d'un changement de source / clic « Rafraîchir ».
  **Persistée par « Enregistrer »**. **Jamais** réécrite au refresh général / re-sync / cron.
- `thumbSource` — **source choisie par l'utilisateur** : `snapshot` (défaut création) | `cover` | `icon`.
  **Éditable** (`<select>` lié). Gouverne la récupération. `icon` = icône plugin par défaut (picUrl vidé).

### `imou::getImage()` (surcharge — inchangée)
Priorité : `sanitizeImageUrl(imageUrl)` > `sanitizeImageUrl(picUrl)` > `parent::getImage()` (icône plugin).
(`sanitizeImageUrl` : http(s) « propre » uniquement, borne 2048 — déjà en place.)

### Création (`syncEquipments`, eqLogic NOUVEAU uniquement)
- `thumbSource='snapshot'` + capture d'un **cliché live** (`captureSnapshot()`) → `picUrl` (si URL non vide).
- Capture **encapsulée try/catch** : un échec (capacité absente, quota, transport) n'interrompt **pas** la
  synchro des autres caméras → on log et on continue avec `picUrl` vide (→ icône plugin).
- Équipement **existant** (branche update) : `picUrl`/`thumbSource` **non touchés**.

### Récupération d'URL (méthode classe `imou::thumbnailUrlForSource($source)`)
- **Ne sauve PAS** (la persistance est faite par « Enregistrer » côté formulaire). Renvoie l'URL assainie.
- Whitelist `$source` (`snapshot`|`cover`|`icon`, défaut `snapshot`). Selon `$source` :
  - `icon` → renvoie `''` **immédiatement, aucun appel cloud** (l'utilisateur veut l'icône plugin) ;
  - `snapshot` → `captureSnapshot(deviceId, channelId)` → `setDeviceSnapEnhanced` (repli `setDeviceSnap`),
    lit `data['url']` ;
  - `cover` → `fetchCoverUrl(deviceId, channelId)` → re-lit `channelPicUrl` via `discoverDevices()` filtré.
- **URL vide = état VALIDE** (cover inexistante / `icon`), PAS une erreur → le client videra `picUrl` (icône
  plugin). Lit `deviceId`/`channelId` de CET eqLogic (déjà persistés à la création).
- `@throws imouException` si `deviceId` manquant (snapshot/cover) ou appel cloud en échec (relayée à l'AJAX).

### AJAX `refreshThumbnail` (`core/ajax/imou.ajax.php`)
- `if (init('action') == 'refreshThumbnail')` — derrière `ajax::init()` (POST forcé) + `isConnect('admin')`.
- Lit `id` (casté `(int)`, rejet si `<= 0`), résout l'eqLogic, vérifie `getEqType_name() === 'imou'`.
- Lit `source` (whitelist stricte `snapshot`|`cover`|`icon` ; défaut `snapshot`).
- Appelle `thumbnailUrlForSource($source)`. **Ne sauve pas.** Renvoie **toujours** `ajax::success(['url' => $url])`
  (url BRUTE assainie, possiblement vide — **pas** de htmlspecialchars : insérée client via jQuery `.attr/.value`).
  **URL vide n'est plus une erreur** (le client la traite comme « icône plugin »).
- Catch LOCAL d'`imouException` (échec d'appel cloud) → `ajax::error()` avec message + code IMOU échappés (anti-XSS DOM).

### UI — `desktop/php/imou.php`
- `<select>` `eqLogicAttr` `configuration.thumbSource` **éditable** : `snapshot` (Cliché automatique (live))
  **en premier** (défaut visuel) + `cover` (Cover IMOU (photo de l'app)) + `icon` (Icône du plugin (par défaut)).
- **Bouton** « Rafraîchir la miniature » (`#bt_imouRefreshThumb`, sans `eqLogicAction` — handler custom).
- **Input caché lié** `eqLogicAttr` `configuration.picUrl` : reçoit l'URL récupérée côté client (vide = icône
  plugin) → sauvé par « Enregistrer ».
- **Aperçu** (partie droite, col-lg-6) : `<img id="imou_thumb_preview" referrerpolicy="no-referrer">` avec
  `data-default` = icône plugin (`$plugin->getPathImgIcon()`, repli défensif `imou_icon.png` via
  `method_exists`). `getImage()` de la LISTE est aussi enveloppé `htmlspecialchars` (défense en profondeur).
- Zone d'alerte `#imou_thumb_alert`.

### UI — `desktop/js/imou.js`
- `imouUpdateThumbPreview(forcedUrl?)` : calcule l'URL d'aperçu (priorité `imageUrl` > `picUrl`
  courant/`forcedUrl` > `data-default`), met à jour `#imou_thumb_preview`.
- `imouSetPicUrl(url)` : pose l'input caché `picUrl` (vide possible) + met l'aperçu à jour.
- `imouFetchThumb(source)` :
  - `icon` → `imouSetPicUrl('')` **sans AJAX** (icône plugin) + message d'info ;
  - `snapshot`/`cover` → POST `action=refreshThumbnail` (`id` + `source`). Au succès : `imouSetPicUrl(data.result.url)` ;
    **`url` vide → message d'INFO** « icône du plugin utilisée » (pas une erreur). Anti-spam (bouton/alerte).
- **Handlers** : `change` sur le `<select>` thumbSource (gardé par `imouThumbReady`) **et** clic sur
  `#bt_imouRefreshThumb` → `imouFetchThumb(valeur courante du select)`.
- **Init aperçu** : au clic sur une carte `.eqLogicDisplayCard` **et** au clic « Sauvegarder »
  (`.eqLogicAction[data-action=save]`) → court délai (≈300/600 ms) puis `imouUpdateThumbPreview()` (avec
  `imouThumbReady=false` pendant le re-peuplement). **Corrige l'aperçu qui « sautait » sur l'icône après save.**
- Garde « équipement non enregistré » (pas d'`id`) : message d'invite, pas d'appel (sauf `icon`, local).

## Server vs Client
Serveur : capture (création + AJAX de récupération) et `getImage`. Client : choix de source, déclenchement
de la récupération, aperçu, et **collecte des valeurs** (`thumbSource`/`picUrl`) sauvées par « Enregistrer ».
La persistance passe par le mécanisme standard d'enregistrement d'eqLogic du core (champs `eqLogicAttr`).

## Validation
- **Sécurité** : `getImage`/AJAX ne renvoient une URL externe que via `sanitizeImageUrl` (http(s), sans
  caractères de cassure, ≤ 2048) ; `<img>` en `referrerpolicy="no-referrer"`. Endpoint AJAX derrière
  `isConnect('admin')` + `ajax::init()` (POST forcé). `id` casté `(int)` > 0 ; eqLogic du bon type. `source`
  en **whitelist** stricte. Messages d'erreur API échappés avant insertion DOM.
- **Robustesse** : capture à la création en try/catch (ne casse pas la synchro). **URL vide → `ajax::success`
  (état valide : le client vide `picUrl` et affiche l'icône plugin)** ; seul un échec d'appel cloud lève
  `imouException` → `ajax::error` (toast). `picUrl` n'est persisté qu'au clic « Enregistrer ».
- **Quota** : capture **uniquement** création + changement de source + clic « Rafraîchir » (jamais
  cron/re-sync) ; anti-spam bouton.

## Server Actions / API
- `imou::getImage()` (inchangé), `imou::sanitizeImageUrl()` (inchangé).
- `imou::syncEquipments()` : `thumbSource='snapshot'` + `captureSnapshot()` (try/catch) **à la création seulement**.
- `imou::thumbnailUrlForSource($source)` : renvoie l'URL selon la source (snapshot/cover), **sans sauver**.
- `imou::captureSnapshot($deviceId, $channelId)` / `imou::fetchCoverUrl($deviceId, $channelId)` (helpers privés).
- AJAX `refreshThumbnail` (`core/ajax/imou.ajax.php`) : `id` + `source` → URL renvoyée au client.

## Dépendances
Aucune.

## i18n (FR — différé étape translator)
`desktop/php/imou.php` / `desktop/js/imou.js` :
`Source de la miniature` ; `Cliché automatique (live)` ; `Cover IMOU (photo de l'app)` ;
`Rafraîchir la miniature` ; `Aperçu de la miniature` ; `Récupération de l'image…` ;
`Image récupérée (cliquez sur Enregistrer pour la conserver).` ; `Échec de la récupération de l'image` ;
`Miniature introuvable : aucune image valide obtenue pour cette source` ;
`Enregistrez d'abord l'équipement, puis réessayez.` ; `Image personnalisée (URL)` + aide (déjà présents).

## Notes / risques
- **Pas d'auto-détection de mode** : l'API ne l'expose pas → c'est un choix utilisateur assumé (le menu
  déroulant). Si IMOU exposait un jour un champ de mode, on pourrait pré-sélectionner automatiquement.
- **Défaut `snapshot` à la création** : 1 appel `setDeviceSnapEnhanced` par caméra créée (une fois). Accepté.
- **URL de snapshot valide ~7 j** : acceptable (re-cliquer/recharger rafraîchit) ; pas de refresh auto (quota).
- **Délai de disponibilité du cliché live** : `setDeviceSnapEnhanced` renvoie l'URL OSS **avant** que l'image
  n'y soit réellement déposée (capture/upload asynchrone, ~1-3 s). Un `<img>` chargé immédiatement affiche
  donc l'icône « lien cassé ». Côté aperçu : **préchargement avec retry** (sonde `new Image()`, ~4×1,5 s) —
  on ne bascule l'aperçu visible qu'une fois l'image chargée ; sinon repli icône. **Pas de cache-buster**
  (invaliderait la signature OSS). (C'est pourquoi, avant ce correctif, il fallait « Sauvegarder » — donc
  attendre — pour voir l'image.)
- **Aperçu non persistant tant qu'on n'enregistre pas** : volontaire (l'utilisateur valide). La LISTE des
  équipements (via `getImage`) ne reflète l'image qu'après enregistrement.
- Migration : équipements créés avant cette révision gardent leur `picUrl` ; changer la source + Enregistrer
  réaligne ; le refresh général ne l'écrase plus.
