# Spec technique — 32 (Panneau carte « Mes véhicules »)

> Feature : page-panneau au menu Jeedom affichant la position des véhicules sur une **carte** (tuile
> statique) + widget carte sur dashboard. **Lecture seule**, dérive de la position déjà parsée en UC31.
> Décisions validées : tuile statique PNG via **proxy same-origin** (fournisseur
> `staticmap.openstreetmap.de`, sans clé) **+ widget dashboard**. Défense en profondeur : coordonnées +
> fraîcheur + lien OSM **toujours** affichés en texte ; la tuile est un enrichissement (repli placeholder
> si échec). Plan durci après revue advisor (arrondi coordonnées, User-Agent, cache fichier, anti-SSRF).

## Architecture

### Contexte hérité (UC31 — ne pas recréer)
- `parseStatus()` produit déjà, gardé par `privacy.state=='None'` : cmd `position` (info string `lat,lon`,
  **eager**), `latitude`/`longitude` (numériques, **lazy**), `heading`, `gps_signal`, `position_updated`
  (string horodatage formaté, propre à la position). Un véhicule en privacy permanente n'a **pas** de
  valeur `position` → le panel doit gérer `getCmd('info','position')` **null ou vide** par véhicule.
- La commande `position` est de type `info` / subType `string` / generic_type `GEOLOC`.

### Fichiers créés
1. **`desktop/php/panel.php`** — page-panneau (menu d'accueil).
   - En-tête : `require_once .../core/php/core.inc.php` + `include_file('core','authentification','php')` ;
     **`isConnect()`** (utilisateur connecté, **PAS** admin). Refus = `throw new Exception`.
   - Liste : `eqLogic::byType('stellantis')`, filtrée par véhicule sur
     `getIsEnable()` **ET** `hasRight('r')` (méthode d'instance) **ET**
     `getConfiguration('isVisiblePanel', 1)` truthy.
   - Par véhicule : nom (`getHumanName`), **tuile inline `data:` URI** (voir § data-URI), coordonnées
     texte, valeur `position_updated` (label « Position mise à jour »), lien
     `https://www.openstreetmap.org/?mlat=<lat>&mlon=<lon>#map=16/<lat>/<lon>` + `geo:<lat>,<lon>?z=16`
     en `<a target="_blank" rel="noopener noreferrer">` (« Voir sur la carte »). Si pas de position :
     bloc « Position non disponible » + « Vie privée activée ou véhicule non localisé » (pas de tuile,
     pas de lien).
   - État vide global (aucun véhicule visible) : « Aucun véhicule à afficher sur la carte ».
   - Note vie privée / tiers : « Les coordonnées sont transmises à OpenStreetMap pour afficher la carte ».
   - **Sécurité** : `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` sur tout texte injecté (noms, coords,
     dates). lat/lon revalidés numériques avant de construire les URL.

2. **`core/ajax/stellantisMap.ajax.php`** — proxy same-origin (pour le **widget** dashboard).
   - En-tête : `core.inc.php` + `authentification` + **`isConnect()`** (pas admin).
   - ⚠️ **Autoload** : n'appelle QUE `stellantis::` (jamais `stellantisApi::`/`stellantisException`
     directement depuis ce point d'entrée). Délègue tout à
     `stellantis::renderStaticMap((int) init('eqLogic_id'))` qui renvoie `['type' => 'image/png',
     'body' => <bytes>]` (jamais d'exception pour un cas attendu → toujours un PNG, placeholder au pire).
   - Émet ensuite : `header('Content-Type: '.$r['type'])`, `header('X-Content-Type-Options: nosniff')`,
     un `Cache-Control` court (ex. `max-age=300, private`), `echo $r['body']; die();`.
   - **PAS** de token CSRF requis (lecture GET ; cf. `jeedom-widgets-commandes.md` § 5).

3. **`core/template/dashboard/cmd.info.string.stellantisMap.html`** + **`core/template/mobile/…`** (2
   fichiers, à garder synchronisés) — widget sur la cmd `position`.
   - `<img>` `src="core/ajax/stellantisMap.ajax.php?eqLogic_id=#eqLogic_id#"` (**src statique, aucun
     cache-busting** timestamp → respecte le cache serveur/faible volume), `alt` = repli texte,
     `max-width:100%`.
   - Légende : `#value#` (="lat,lon") + lien « Voir sur la carte » construit en JS depuis `#value#`
     (split sur `,`, 2 parts numériques sinon masquer le lien). Script **scopé** via
     `document.querySelector('.cmd[data-cmd_uid=#uid#]')`.
   - Chaînes UI en `{{...}}`. i18n : **une entrée de chemin par fichier** (dashboard ET mobile).

### Fichiers modifiés
4. **`plugin_info/info.json`** — ajout `"display": "panel"`. **Pas** de `"mobile"` (page-panneau mobile
   hors périmètre ; le *widget* mobile est livré, mécanisme distinct). Le toggle `displayDesktopPanel`
   est **natif** (core) : rien d'autre à coder.

5. **`core/class/stellantis.class.php`** :
   - **Constantes** : `MAP_TILE_URL` (défaut `https://staticmap.openstreetmap.de/staticmap.php`),
     `MAP_ZOOM = 15`, `MAP_SIZE = '400x260'`, `MAP_CACHE_TTL = 600` (succès, s), `MAP_CACHE_TTL_ECHEC = 60`
     (négative-cache, s), `MAP_USER_AGENT` (ex. `Jeedom-Stellantis/<version> (+https://github.com/…)`).
   - **`renderStaticMap(int $eqLogicId): array`** (publique) :
     1. `eqLogic::byId($eqLogicId)` ; garde `is_object` + `getEqType_name()==='stellantis'` + `getIsEnable()`
        + **`$eqLogic->hasRight('r')`** (instance). Échec → placeholder.
     2. Lit la cmd `position` (`getCmd('info','position')`), valeur via `execCmd()` ; parse `lat,lon`
        (`is_numeric` sur 2 parts + bornes `abs(lat)<=90` / `abs(lon)<=180`). Absent/invalide → placeholder.
     3. **Arrondit lat/lon à 4 décimales** (clé de cache ET URL) → cache-hit réel malgré le jitter GPS.
     4. Cache **fichier** (voir § cache) : hit succès < TTL → retourne les octets ; hit négatif < TTL_ECHEC
        → placeholder sans refetch.
     5. Sinon `telechargerTuile(construireUrlTuile(lat, lon, MAP_ZOOM, MAP_SIZE))` ; succès → écrit le
        cache fichier + retourne ; échec → écrit un marqueur négatif + retourne `tuilePlaceholder()`.
     - **Ne lève jamais** vers l'endpoint : chaque cas attendu retombe sur le placeholder.
   - **`construireUrlTuile(float $lat, float $lon, int $zoom, string $size): string`** (PURE) : base =
     `config::byKey('map_tile_url','stellantis', self::MAP_TILE_URL)` (override optionnel), puis query
     `center=lat,lon&zoom=…&size=…&maptype=mapnik&markers=lat,lon,red-pushpin` (lat/lon déjà arrondis,
     `rawurlencode` sur les valeurs). Testable indépendamment.
   - **`telechargerTuile(string $url): ?string`** (privée) : cURL GET **en mémoire** (pas de fichier),
     `CURLOPT_RETURNTRANSFER`, **`CURLOPT_FOLLOWLOCATION=false`** (anti-SSRF sur redirection),
     `CURLOPT_PROTOCOLS=CURLPROTO_HTTPS` si dispo, garde schéma `https` avant armement,
     `CURLOPT_USERAGENT=MAP_USER_AGENT`, timeout court (connect 5 s / total 10 s), `CURLOPT_MAXFILESIZE`
     (ex. 512 Ko), **jamais** de header `Authorization: Bearer` (serveur tiers public, pas l'API
     Stellantis). Valide HTTP 2xx **et** `Content-Type` commence par `image/` (sinon `null` — ne relaie
     pas une page d'erreur HTML). Log sans coordonnées sensibles inutiles.
   - **`tuilePlaceholder(): string`** (privée) : PNG minimal (constante base64 décodée) « carte
     indisponible » ou 1×1 transparent — le panel/widget garde de toute façon coords + lien en texte.
   - **`createCommands()`** : après la boucle `ensureCommand`, si la cmd `position` existe, pose le
     template **si vide** (idempotent, `jeedom-widgets-commandes.md` § 6) :
     `if ($cmd->getTemplate('dashboard','')==='') $cmd->setTemplate('dashboard','stellantis::stellantisMap')`
     idem `'mobile'` ; un seul `save()` si modifié.
   - **Backfill `isVisiblePanel`** : helper privé partagé `assurerVisiblePanelParDefaut(eqLogic): bool`
     (retourne true si modifié) → `if ($eq->getConfiguration('isVisiblePanel','')==='')
     $eq->setConfiguration('isVisiblePanel',1)`. Appelé dans la boucle de découverte (avant le `save()`
     unique — création ET existants) et réutilisé par `install.php`.

6. **`desktop/php/stellantis.php`** — case **« Afficher sur le panneau carte »** dans « Paramètres
   spécifiques » : `<input type="checkbox" class="eqLogicAttr" data-l1key="configuration"
   data-l2key="isVisiblePanel">` + tooltip. **Obligatoire** : une clé de `configuration` absente du form
   est effacée au Sauvegarder (piège connu `jeedom-eqlogic-sync-persist.md`).

7. **`plugin_info/configuration.txt`** (miroir éditable ; puis `cp configuration.txt configuration.php`)
   — champ **optionnel** `map_tile_url` : `<input class="configKey" data-l1key="map_tile_url">` + label
   « URL du service de tuiles carte (optionnel) » + tooltip (« laisser vide pour le service par défaut »).

8. **`plugin_info/install.php`** (`stellantis_update`) — backfill idempotent, best-effort, borné à
   `eqLogic::byType('stellantis')` : `assurerVisiblePanelParDefaut()` (save si modifié) **et** pose du
   template `stellantis::stellantisMap` sur les cmd `position` existantes (dashboard+mobile, si vide) →
   couvre les installs qui ne re-synchronisent pas immédiatement.

## Server vs Client
- **Serveur** : `panel.php` (page HTML rendue serveur) + `renderStaticMap` (fetch/cache/validation
  tuile) + proxy `stellantisMap.ajax.php`. Le fetch de tuile est **serveur** (la CSP est une règle
  navigateur ; le serveur n'a pas de CSP → il relaie la ressource externe).
- **Client** : uniquement le petit JS du widget dashboard (construit le lien OSM depuis `#value#`,
  scopé `#uid#`). La page panel n'a pas de logique client nécessaire.
- **data: URI (panel) vs proxy (widget)** : `panel.php` embarque la tuile en `data:image/png;base64,…`
  (appel direct `renderStaticMap` serveur, autorisé par la CSP `data:`) → pas d'aller-retour HTTP. Le
  widget dashboard (HTML statique côté client) **doit** passer par le proxy same-origin. Les deux
  partagent le **même cache fichier**.

## Validation
- **Serveur (proxy/render)** : `eqLogic_id` cast `int` ; garde `eqType==='stellantis'` (ne lit pas un
  eqLogic d'un autre plugin) ; `getIsEnable()` + `hasRight('r')` (instance) ; lat/lon `is_numeric` +
  bornes ; réponse tuile `image/*` + HTTP 2xx ; HTTPS strict + `FOLLOWLOCATION=false` (anti-SSRF) ; UA
  identifiant ; jamais de Bearer.
- **Client** : aucune saisie utilisateur ; le widget parse `#value#` défensivement (masque le lien si
  format inattendu).

## Server Actions / API
- **Aucune commande action nouvelle**, aucun appel REST/MQTT nouveau. Uniquement de la lecture/relai.
- Endpoints : `core/ajax/stellantisMap.ajax.php?eqLogic_id=…` (GET, `isConnect()` + `hasRight('r')`,
  renvoie un PNG).
- Contrat tuile externe : `GET {map_tile_url}?center=lat,lon&zoom=15&size=400x260&maptype=mapnik&
  markers=lat,lon,red-pushpin` → `image/png`. Fournisseur communautaire, **best-effort**, à confirmer en
  recette (§ « À confirmer » de la spec fonctionnelle) ; le repli placeholder + le texte garantissent
  les critères d'acceptation même si le fournisseur est indisponible.

## Cache tuile (fichier)
- Répertoire : dossier temporaire du plugin (`jeedom::getTmpFolder('stellantis')` si dispo, sinon
  `__DIR__/../../data` créé au besoin). Fichier `maptile_<hash(base_url|lat4|lon4|zoom|size)>.png`.
- TTL succès = `MAP_CACHE_TTL` (600 s), via `filemtime`. Négative-cache = fichier marqueur
  `maptile_<hash>.fail` TTL `MAP_CACHE_TTL_ECHEC` (60 s) → sert le placeholder sans refetch.
- Nettoyage opportuniste : à l'écriture, supprimer les fichiers `maptile_*` plus vieux que le TTL
  (nombre de véhicules faible ; pas de tâche cron dédiée).

## Dépendances
- **Aucune** : cURL déjà utilisé, pas d'extension PHP, pas de pip, pas de lib JS (widget en JS natif).

## Chaînes UI FR (à envelopper `{{...}}` / `__()`, traduction déléguée étape 10)
- Panel : `Mes véhicules`, `Position non disponible`, `Vie privée activée ou véhicule non localisé`,
  `Voir sur la carte`, `Position mise à jour`, `Aucun véhicule à afficher sur la carte`,
  `Les coordonnées sont transmises à OpenStreetMap pour afficher la carte`,
  `401 - Accès non autorisé` (réutilisée).
- Form eqLogic : `Afficher sur le panneau carte` + tooltip
  `Affiche ce véhicule dans le panneau « Mes véhicules » du menu`.
- Config plugin : `URL du service de tuiles carte (optionnel)` + tooltip
  `Laisser vide pour utiliser le service de cartographie par défaut (OpenStreetMap)`.
- Widget (dashboard + mobile, une entrée i18n par fichier) : `Carte de position`, `Voir sur la carte`.
- Proxy : réponse **binaire** sur le chemin nominal (image) → aucune chaîne UI ; seul le chemin d'échec
  d'authentification enveloppe `401 - Accès non autorisé` (réutilisée), cohérent avec `stellantis.ajax.php`.

## Critères d'acceptation (rappel)
- [ ] Entrée de menu « Mes véhicules » quand l'option panneau est cochée (toggle natif `display`).
- [ ] Position de chaque véhicule visible (tuile carte + coords + lien).
- [ ] Aucun blocage CSP (tuile servie same-origin/`data:`, lien = navigation top-level).
