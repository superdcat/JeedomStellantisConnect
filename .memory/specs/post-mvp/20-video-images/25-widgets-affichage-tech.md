# Spec technique — post mvp 25 (widgets d'affichage)

> ## ⚠️ Révision 2026-06-22 — limiteur de concurrence multi-caméras (dashboards N tuiles live)
>
> Constat terrain : un dashboard avec **beaucoup de tuiles live** (ex. 11 caméras) lance autant de boucles
> de frames en parallèle → autant de process **ffmpeg** + `bindDeviceLive` quasi simultanés au chargement
> → **saturation serveur**. Le verrou serveur existant est **par caméra** (un ffmpeg/caméra) et ne borne
> pas le total inter-caméras.
>
> **Décision** : un **limiteur de concurrence GLOBAL, équitable (FIFO), côté client**.
> - **Sémaphore partagé** `window.__imouLiveSched = { max, active, fetched, queue:[] }` : toutes les IIFE
>   des tuiles `imouLive` coopèrent via cet objet (même principe que `_imouClose` posé sur les nœuds).
>   `active` = captures en cours ; `max` = limite ; `queue` = **file FIFO** des tuiles en attente de voie.
> - **Équité round-robin** : à la libération d'une voie (`releaseSlot → pump`), elle est attribuée à la
>   **plus ancienne** tuile en attente ; une tuile qui vient de capturer se réinscrit en **queue de file**
>   (plus de monopole par les mêmes caméras). Fonctions : `pump`/`grantCb`/`releaseSlot`/`dequeueSelf`/
>   `nextFrame`/`captureFrame`. Invariant : `active == nombre de tuiles tenant une voie (hasSlot=true)`.
> - **Limite configurable** : nouveau champ de **config plugin** `liveMaxConcurrent` (**défaut 3**, bornes
>   **1–20**), dans `plugin_info/configuration.{txt,php}` (fieldset « Performance »). Lue **côté serveur**
>   via `config::byKey('liveMaxConcurrent','imou',3)` et **exposée au widget** par un nouveau mode de
>   `imouStream.ajax.php` : **`op=config`** (placé après `isConnect()`+`hasRight('r')`, renvoie
>   `{"maxConcurrent":N}` clampé 1–20). Le widget la récupère **une seule fois** (garde `SCH.fetched`,
>   ré-armée dans le `.catch`) ; défaut 3 tant que la réponse n'est pas revenue. Un changement de config
>   nécessite un **rechargement du dashboard** (pas de re-fetch live, volontaire).
> - **Priorité plein écran** (UC26) : la tuile en overlay **court-circuite** la file (frame immédiate à
>   chaque cycle, hors sémaphore) — cf. `26-live-plein-ecran-tech.md`.
> - **Fix cliquabilité** : `pointer-events:none` sur `.imouLiveMsg` (le div message couvrait l'`<img>` et
>   interceptait le clic d'agrandissement UC26).
>
> **Renommage** : la commande info `live_url` change de libellé par défaut « URL du flux live » →
> **« Flux live »** (`createLiveCommands`). `setName` étant **création seulement** (`$isNew`), les
> équipements **déjà synchronisés conservent l'ancien nom** ; seuls les nouveaux prennent « Flux live ».
> Clé i18n mise à jour (`Flux live`) dans en_US/de_DE/es_ES (l'ancienne `URL du flux live` retirée).

> ## ⚠️ Révision 2026-06-21 (b) — pivot « live-snapshot » imposé par la CSP Jeedom
>
> Test sur Jeedom réel : la CSP `default-src 'self' file: data: blob: filesystem:` (sans `img-src`/
> `media-src`/`connect-src` explicites) **bloque TOUT chargement externe par le navigateur — images ET
> média**. Donc ni le lecteur HLS (`<video>`/hls.js), ni l'`<img>` pointant l'URL OSS du snapshot ne
> fonctionnent : le navigateur refuse l'hôte IMOU externe. Seul le **same-origin** (origine Jeedom) passe.
>
> **Décision** : le widget live devient **mono-mode « live-snapshot »**. Le serveur Jeedom (hors CSP)
> tire le flux **HLS** et en extrait des **frames JPEG via ffmpeg**, servies **same-origin** par un
> endpoint dédié. Le navigateur affiche une `<img>` pointant cet endpoint et **redemande la frame
> suivante dès réception de la précédente** (auto-cadencé, ~1 image/1-3 s vu la latence HLS).
>
> Conséquences sur la 1re version de cette spec :
> - **Supprimés** : le lecteur `<video>`/hls.js (mort sous CSP) **et le fichier vendorisé hls.light.min.js** ;
>   le mode snapshot-API du widget (quota). La bascule Snapshot/HLS disparaît (mono-mode).
> - **Ajout dépendance** : **ffmpeg** (`plugin_info/packages.json` → apt) — décodage serveur du HLS.
>   (Assume un écart à « 100 % PHP sans paquet » : justifié par la CSP + le coût quota évité.)
> - **Nouveau endpoint** `core/ajax/imouStream.ajax.php` (non-admin, `isConnect()`) : sert 1 JPEG
>   (`op` défaut) ou libère le binding (`op=release`). Sécurité : équipement `imou` validé, URL HLS
>   **jamais** d'origine utilisateur (vient de `imou::resolveLiveHls`) + `escapeshellarg` → pas
>   d'injection/SSRF ; verrou anti-concurrence ; sortie binaire stricte.
> - **Helpers PHP** mutualisés : `imou::resolveLiveHls($d,$c,$force)` (cache token+URL → ~0 appel API
>   par frame ; rebind borné), `imou::grabHlsFrame($url)` (ffmpeg 1 frame, timeout, validation signature
>   JPEG), `imou::liveReleaseCached($d,$c)`. `actionLiveGet`/`actionLiveRelease` (UC22) refactorés dessus.
> - **Quota** : un seul `bindDeviceLive` par session de visionnage (URL mise en cache `LIVE_URL_TTL`=1 h) ;
>   les frames viennent du CDN de streaming, **0 appel API par image**.
> - **Conservé** : pavé PTZ `imouPtzPad`, boutons `imouButton`, commandes snapshot UC21
>   (`snapshot_get`/`snapshot_url`, capture cloud à la demande — utiles en scénario, hors widget).
>   **⚠️ MAJ 2026-06-22 — UC21 retirée** : ces deux commandes ont depuis été **supprimées** du code
>   (cf. `21-snapshot.md`, bandeau « UC RETIRÉE »). Seul `imou::captureSnapshot()` survit, désormais
>   appelé uniquement par la miniature UC55. Les passages ci-dessous mentionnant `snapshot_*` sont
>   donc **caducs** (conservés tels quels pour l'historique de la décision UC25).
> - **Limite assumée** : ~1 img/1-3 s (latence HLS) ; le « 0,5 s fluide » exigerait un ffmpeg persistant
>   (= démon), exclu par l'archi. Le RTSP/CGI local (gratuit, sans quota) reste une piste « mode local »
>   future, mais LAN-only et model-dependent (cf. échange UC25).
>
> Le reste de la spec ci-dessous décrit la 1re version (pavé PTZ, boutons, mécanismes Jeedom vérifiés) ;
> seul le **widget live** a changé comme décrit ici.

---

> Décisions utilisateur (2026-06-21) : (1) widget live UNIQUE avec **bascule Snapshot | HLS à la volée**
> (tous les boutons intégrés) ; (2) périmètre = **pavé PTZ + widget live + boutons d'action soignés**
> (sirène + on/off caméra/surveillance/projecteur) ; (3) le mode snapshot **implémente la commande de
> capture** (livre de fait le cœur d'UC21).
>
> **Mécanismes Jeedom vérifiés dans la source du core** (corrige deux hypothèses fausses ; cf. § Architecture) :
> - `cmd::toHtml()` n'expose QUE les tokens de la commande courante (`#id#`, `#logicalId#`, `#eqLogic_id#`,
>   `#name#`, `#uid#`, `#version#`) + ceux de sa commande LIÉE (`#value_id#`, `#state#`). **Aucun token de
>   référence croisée par logicalId** (`#cmd_id[...]#` n'existe pas). `jeedom.cmd.byEqLogicId` n'existe pas non plus.
> - Résolution des commandes sœurs = AJAX core **`byEqLogic`** (`core/ajax/cmd.ajax.php`, action `byEqLogic`,
>   accessible à tout **utilisateur connecté**, renvoie via `cmd::byEqLogicId()` toutes les commandes —
>   `o2a` → incluant `logicalId`/`isVisible`, même masquées). Auth = session (core 4.4+ : pas de token CSRF
>   par requête pour une lecture ; un POST same-origin hérite du cookie). Exécution = `jeedom.cmd.execute({id})`.

## Architecture

### Vue d'ensemble
4 templates de widget Jeedom, déclinés **dashboard + mobile** (mêmes noms, HTML mutualisable) :

| Widget (nom) | Fichier | Posé sur (ancre) | Type/subType ancre | Mécanique |
|---|---|---|---|---|
| `imouPtzPad` | `cmd.action.other.imouPtzPad.html` | `ptz_up` | action/other | multi-cmd (byEqLogic → 6 `ptz_*`) |
| `imouLive` | `cmd.info.string.imouLive.html` | `live_url` | info/string | multi-cmd (byEqLogic → live_*/snapshot_*) + lecteur |
| `imouButton` | `cmd.action.other.imouButton.html` | chaque action on/off + sirène | action/other | **mono-cmd** (`#id#` seul, pas d'AJAX) |

Fichiers sous `core/template/dashboard/` ET `core/template/mobile/`. Assignation via
`$cmd->setTemplate('dashboard'|'mobile', 'imou::<nom>')`.

### Fichiers créés / modifiés
- **`core/template/dashboard/cmd.action.other.imouPtzPad.html`** (+ `mobile/`) — pavé directionnel.
- **`core/template/dashboard/cmd.info.string.imouLive.html`** (+ `mobile/`) — widget live (snapshot/HLS).
- **`core/template/dashboard/cmd.action.other.imouButton.html`** (+ `mobile/`) — bouton d'action soigné.
- **`desktop/js/hls.light.min.js`** — **hls.js vendorisé** (build « light », ~70 Ko), chargé **à la demande**
  par `imouLive` quand le mode HLS est activé (lazy, une seule fois ; non chargé pour les utilisateurs
  snapshot-only). Aucune dépendance PHP (pas de `packages.json`/démon). À documenter en 82-packaging.
- **`core/class/imou.class.php`** :
  - `creerCommande()` : nouvelle option **`template`** (array `['dashboard'=>'imou::x','mobile'=>'imou::x']`)
    posée **« si vide »** : `if ($cmd->getTemplate($v,'') === '') $cmd->setTemplate($v, ...)`. Couvre les
    installs existantes (template absent → posé) **sans** écraser un widget choisi par l'utilisateur.
    Appliquée à CHAQUE sync (pas create-only) car la garde « si vide » suffit à préserver le choix manuel.
  - `ptzCatalog()` : `defaultVisible` passe à **`false`** pour `ptz_down`/`ptz_left`/`ptz_right`/
    `ptz_zoom_in`/`ptz_zoom_out` ; seul **`ptz_up`** reste `true` (porte le pavé). Les 5 sœurs restent
    créées et pilotables (masqué ≠ non-exécutable ; la table de commandes admin les liste toutes).
  - `createPtzCommands()` : assigne `template imou::imouPtzPad` à `ptz_up`.
  - `createSwitchCommands()` : assigne `template imou::imouButton` aux actions on/off (caméra/
    surveillance/projecteur) et sirène (`sirene_on`/`sirene_off`).
  - `createLiveCommands()` : `live_url.visibleOnCreate` passe à **`true`** + template `imou::imouLive` ;
    `live_get`/`live_release` restent `visibleOnCreate=false` (pilotées par le widget).
  - **UC21** — nouvelle `createSnapshotCommands($logicalIdLog)` (appelée dans `createCommands()` après
    `createLiveCommands()`), sans gating (capacité cloud universelle, comme live) :
    - info **`snapshot_url`** (string, `visibleOnCreate=false`, non pollable, non historisée).
    - action **`snapshot_get`** (« Capturer une image », `value`→id de `snapshot_url`, `visibleOnCreate=false`).
  - `captureSnapshot()` : passe de `private static` à **`public static`** (déjà le contrat des helpers
    `liveBind`/`liveInfo`…) pour être appelable depuis `imouCmd::actionSnapshotGet`.
  - `imouCmd::execute()` : nouvelle **branche `snapshot_`** (avant le catalogue, symétrique à `live_`) →
    `actionSnapshotGet`. Préfixe `snapshot_` réservé (disjoint de `iot_`/`ptz_`/`live_`/catalogue).
  - `imouCmd::actionSnapshotGet($eqLogic)` : garde `deviceId` ; `channelId` défaut `'0'` ;
    `$url = imou::sanitizeImageUrl(imou::captureSnapshot($deviceId,$channelId))` ;
    `$eqLogic->checkAndUpdateCmd('snapshot_url', $url)` ; **retourne `$url`** (pour que
    `jeedom.cmd.execute` le remonte au widget en 1 appel) ; log info FR. Pas de save eqLogic (pas de récursion).

## Server vs Client
- **Client** (templates HTML/JS) : 100 % de la logique d'affichage et d'orchestration des boutons.
- **Serveur** : aucun nouvel endpoint AJAX plugin (l'AJAX `imou.ajax.php` est admin-only → inutilisable
  depuis un dashboard utilisateur). Tout passe par le modèle de commandes Jeedom + l'AJAX core `byEqLogic`
  (lecture) et `execCmd` (`jeedom.cmd.execute`). Côté PHP, seules s'ajoutent : la commande snapshot (UC21)
  et le câblage des templates. **Justification** : `jeedom.cmd.execute` gère droits/confirmation/token ;
  `byEqLogic` est session-authentifié et read-only → pas de surface serveur custom à sécuriser.

## Validation
- **Résolution sœurs hardenée** (PTZ, live) : le widget résout la map `logicalId→id` via `byEqLogic` AU
  CHARGEMENT, PUIS branche les clics. Bouton **désactivé** si sa commande est absente (ex. caméra PT sans
  zoom → `ptz_zoom_*` non créées → boutons zoom grisés). Aucune erreur JS si une commande manque.
- **Lecture de valeur** : après `execute(snapshot_get)`/`execute(live_get)`, le widget lit l'URL fraîche
  soit via le **retour** de l'action (snapshot_get renvoie l'URL), soit via `jeedom.cmd.execute({id})` sur
  l'info (`live_url`/`snapshot_url`) — exécuter une commande **info** renvoie sa valeur courante. *(à
  valider en dev : contrat exact du callback success de `jeedom.cmd.execute` sur une info.)*
- **URL** : `live_url`/`snapshot_url` sont déjà **assainies côté serveur** (`sanitizeImageUrl`, http(s),
  ≤2048, pas de cassure d'attribut) avant stockage → injection sûre dans `<video src>`/`<img src>`.
- **closeCamera inversé** : transparent — `imouButton` pilote les actions `camera_on`/`camera_off` (jamais
  l'API directement), l'inversion est gérée en amont (UC11). Aucune logique d'inversion dans le widget.
- **Quota snapshot** : le rafraîchissement auto du mode snapshot est **désactivé par défaut** ; activation
  explicite par l'utilisateur (toggle dans le widget) + intervalle prudent. Chaque capture = 1 appel API
  (cf. mémoire `imou-snapshot-cliche-live-data-url`) → bouton « Rafraîchir » manuel privilégié.
- **HLS** : `hls.js` chargé uniquement en mode HLS ; repli lecture native (`canPlayType` HLS, Safari/iOS) ;
  message FR « Lecture non supportée… » + bouton « Capturer une image » (bascule snapshot) si rien ne lit.

## Server Actions / API
Aucun appel cloud nouveau hors UC21. Réutilise : `controlMovePTZ` (PTZ, UC15), `bindDeviceLive`/
`getLiveStreamInfo`/`unbindLive` (live, UC22), `setDeviceSnapEnhanced`/`setDeviceSnap` (snapshot, via
`captureSnapshot` existant). `snapshot_get` = capture à la demande (jamais au cron, comme live).

## Dépendances
- **hls.js** (build light) vendorisé dans `desktop/js/` — asset JS front, **aucune** dépendance PHP/système,
  pas de modif `info.json`/`packages.json`. Chargé à la demande par le widget live (mode HLS).

## i18n (FR source — traduction en/de/es différée à l'étape translator)
Chaînes UI nouvelles (`{{...}}` dans les templates, `__()` pour les libellés de commande) :
- **PTZ** (titres/aria) : `Haut`, `Bas`, `Gauche`, `Droite`, `Zoom avant`, `Zoom arrière`.
- **Live** : `Direct (HLS)`, `Image (snapshot)`, `Démarrer`, `Arrêter`, `Rafraîchir`,
  `Rafraîchissement automatique`, `Flux indisponible`, `Chargement…`,
  `Lecture non supportée par ce navigateur`, `Aucune image`.
- **Commandes snapshot (UC21)** : `Capturer une image` (snapshot_get), `URL du cliché` (snapshot_url).
- `imouButton` : réutilise le **nom** de la commande (pas de nouvelle chaîne).

Chemins i18n des templates : `plugins/imou/core/template/dashboard/<fichier>` (et `.../mobile/...`).

## Scénarios de recette manuelle (à consigner dans 81-validation-manuelle.md)
1. Caméra PTZ complète : pavé affiché, 4 directions + zoom ± fonctionnels ; les 5 commandes sœurs masquées.
2. Caméra PT sans zoom (`PT1`/`PT2`) : pavé affiché, boutons zoom **grisés**, directions OK.
3. Widget live, mode HLS : ▶ → flux lisible (Chrome via hls.js, Safari natif) ; ⏹ → arrêt + ressource libérée.
4. Widget live, mode snapshot : « Rafraîchir » → image fraîche ; auto-refresh OFF par défaut ; activation → MAJ périodique.
5. Navigateur sans HLS et sans hls.js chargé : message FR + bascule snapshot proposée (pas d'écran noir).
6. `imouButton` : sirène/on-off rendus en boutons soignés ; clic → action exécutée (toast Jeedom).
7. Install **existante** (caméra déjà synchro avant UC25) : re-sync → templates posés sur commandes sans
   widget custom ; une commande dont l'utilisateur a déjà choisi un widget n'est PAS écrasée.
