# Spec technique — post-mvp 61 (Extraction auto des identifiants depuis l'APK)

> Contrat externe **vérifié le 2026-07-08** contre `flobz/psa_apk` (contenu du dépôt +
> `.gitattributes`) et `flobz/psa_car_controller` (`psa/setup/apk_parser.py`).

## Contrat externe confirmé
- **Téléchargement** : `GET https://github.com/flobz/psa_apk/raw/main/{fichier}.apk.bz2`
  (302 → `raw.githubusercontent.com`, suivre les redirections). Fichiers :
  `mypeugeot` / `mycitroen` / `myds` / `myopel` / `myvauxhall` (`.apk.bz2`).
  `.gitattributes` ne met en LFS que `*.apk` → les `*.apk.bz2` sont des **blobs git normaux**,
  le `raw/…` renvoie le vrai binaire (~100 Mo).
- **Décompression** `.bz2` : stream wrapper `compress.bzip2://` via `copy()` (pas de chargement
  mémoire). Requiert `ext-bz2`.
- **Parsing** (requiert `ext-zip`) :
  - `res/raw/cultures.json` → `cultures[PAYS]["languages"][0]` = chaîne culture (ex. `fr_FR`).
    `apk_parser.py` : `cultures[country_code]["languages"][0]` puis `culture.split("_")`.
  - dossier construit `res/raw-{lang}-r{COUNTRY}/parameters.json`
    (`apk_parser.py` : `"res/raw-{}-r{}/parameters.json".format(language, country)`),
    ex. `res/raw-fr-rFR/parameters.json`.
  - champs : `client_id = cvsClientId`, `client_secret = cvsSecret`.
- **Casse du code pays** : `cultures.json` est indexé en MAJUSCULES (`FR`) alors que la config
  stocke `country` en minuscule (`fr`) → `strtoupper()` avant lookup, avec repli défensif
  (essai `FR` puis tel quel) et message dédié si le pays est absent de `cultures.json`.

## Architecture

### `core/class/stellantis.class.php`
- **`const BRANDS`** : ajouter `'apkFile'` par marque (`mypeugeot`…`myvauxhall`).
- **`const APK_BASE_URL = 'https://github.com/flobz/psa_apk/raw/main/'`**.
- **`stellantis::extractCredentialsFromApk(?string $_brand, ?string $_country): array`**
  (statique) — orchestration, retourne la structure **uniforme**
  `['ok'=>bool, 'client_id'=>string, 'client_secret'=>string, 'message'=>string]`
  (mappe toutes les erreurs en interne, **jamais de levée**, comme `testConnection`/`syncVehicles`).
  Étapes :
  1. Valider `$_brand` (`strtolower/trim`, repli défaut, **`array_key_exists(self::BRANDS)`**) et
     `$_country` (`strtolower/trim`, défaut `fr`). **Découplé** des credentials sauvegardés
     (ne lit pas `getApiConfig`).
  2. Vérifier `extension_loaded('zip')` **et** `extension_loaded('bz2')` → sinon `ok=false` +
     message actionnable renvoyant vers la procédure manuelle (`docs/`).
  3. Cooldown serveur `stellantis::apk_cooldown` (30 s) — anti double-clic contournable.
  4. Chemins temp via `tempnam(sys_get_temp_dir(), 'stellantis_apk_')` (bz2 + apk) — pas de nom
     fixe par marque (pas de collision concurrente).
  5. `stellantisApi::downloadToFile($url, $bz2Path)` (URL = override config `apk_url` si non vide,
     sinon `APK_BASE_URL . apkFile . '.apk.bz2'`).
  6. Décompression : `copy('compress.bzip2://' . $bz2Path, $apkPath)` testé (`=== false`) ;
     `filesize($apkPath)` vérifié (> 0 et sous un plafond de sécurité, ex. 300 Mo).
  7. Parsing délégué au helper pur `parseApkCredentials($apkPath, $country)`.
  8. `ok=true` **seulement si** `client_id` ET `client_secret` sont scalaires non vides
     (sinon échec « succès partiel refusé »).
  9. **`finally`** : `unlink` du bz2 et de l'apk, chacun gardé par `file_exists()`.
- **`stellantis::parseApkCredentials(string $_apkPath, string $_country): array`** (statique, pur —
  testable sur fixture) — ouvre l'APK (`ZipArchive::open()` → **code entier**, pas d'exception :
  tester `!== true`), lit `cultures.json` (garde-fou `statName()['size']` ≤ 1 Mo, `json_decode` +
  `json_last_error`), résout la culture (`strtoupper` pays + repli), lit `parameters.json`
  (même garde-fou), extrait `cvsClientId`/`cvsSecret`. Retourne
  `['ok', 'client_id', 'client_secret', 'message']`. Chaque échec (open, entrée absente, JSON
  invalide, pays inconnu, champs absents) → `ok=false` + message dédié. **Jamais** de succès partiel.

### `stellantisApi::downloadToFile(string $_url, string $_destPath): void`
- Validation `parse_url()` : scheme **`https` uniquement** (défense en profondeur avant `curl_init`).
- cURL vers fichier : `CURLOPT_FILE` (flux, pas `RETURNTRANSFER`), `FOLLOWLOCATION=true`,
  `CURLOPT_PROTOCOLS`/`CURLOPT_REDIR_PROTOCOLS` = `CURLPROTO_HTTP|CURLPROTO_HTTPS` (anti-SSRF sur
  redirection), `CURLOPT_MAXFILESIZE` (~200 Mo), timeout 300 s, `CONNECTTIMEOUT` 15 s,
  `LOW_SPEED_LIMIT`/`LOW_SPEED_TIME` (abandon d'un transfert bloqué).
- Lève `stellantisException('...', 0, 'transport')` sur erreur cURL / HTTP non-2xx.
- **Commentaire explicite** : ne JAMAIS porter le header `Authorization: Bearer` (URL GitHub
  publique) — barrière contre un futur refactor mutualisant les headers.
- Logs redactés (jamais de query complète, jamais de secret).

### `core/ajax/stellantis.ajax.php`
- Branche `extractCredentials` placée **avant** le garde `isConfigured()` (on extrait justement les
  credentials manquants ; `stellantis::` appelé en premier → autoload OK).
- `set_time_limit(300)` pour cette branche (téléchargement ~100 Mo).
- Lit `init('brand')` / `init('country')` transmis par le formulaire → **pas besoin de sauvegarder
  d'abord**. Une seule marque (celle du formulaire), jamais de boucle sur `BRANDS`.
- `ajax::success(stellantis::extractCredentialsFromApk(...))`.

### `plugin_info/configuration.txt` (+ copie `.php`)
- Bouton « Extraire automatiquement » dans le fieldset Connexion (près de Client ID/Secret).
- `bootbox.confirm` (avertissement APK tiers / ToS) avant lancement ; alerte de progression
  « Téléchargement… (~100 Mo) » pendant l'appel AJAX.
- Sur succès : pré-remplir `client_id`/`client_secret` (**sans save silencieux**) + alerte invitant
  à vérifier puis sauvegarder. Sur échec : alerte danger avec le message + rappel procédure manuelle.
- Champ avancé optionnel `apk_url` (override URL complète, laissé vide par défaut) + tooltip.

## Server vs Client
100 % **serveur** (téléchargement, `ZipArchive`, `bz2`, filesystem — impossible côté navigateur).
Client = confirmation (`bootbox`), déclenchement AJAX, pré-remplissage. Le pré-remplissage ne
sauvegarde pas : l'admin valide via le bouton Sauvegarder habituel du core.

## Validation
- **Client** : `bootbox.confirm` obligatoire ; garde si aucune marque sélectionnée.
- **Serveur** : `isConnect('admin')` (ajax) ; `$_brand` validé contre `BRANDS` ;
  `extension_loaded` ; cooldown ; `parse_url` https-only ; `MAXFILESIZE` + `filesize` ;
  garde-fou taille des entrées zip ; chaque échec → `ok=false` + message actionnable,
  jamais de crash ni d'état partiel.

## Server Actions / API
- `stellantis::extractCredentialsFromApk(?string, ?string): array` → `['ok','client_id','client_secret','message']`
- `stellantis::parseApkCredentials(string, string): array` → idem (pur, testable)
- `stellantisApi::downloadToFile(string, string): void` (throws `stellantisException` transport)
- AJAX `action=extractCredentials` (`brand`, `country`)

## Durcissements post-review (sécurité + qualité)
- **SSRF** : `downloadToFile` restreint `CURLOPT_PROTOCOLS`/`REDIR_PROTOCOLS` au **seul HTTPS**
  (pas de rétrogradation HTTP sur redirection, ni `file://`/`gopher://`), en plus du contrôle
  `parse_url` https-only initial.
- **Zip-bomb** : rejet si `ZipArchive::numFiles > 100000` (répertoire central aberrant), en plus du
  garde-fou par entrée (`statName()['size']` ≤ `APK_ENTREE_MAX`).
- **Orphelins temp** : `purgerApkOrphelins()` balaie les `stellantis_apk_*` > 1 h au début de chaque
  extraction (auto-guérison si une requête est tuée avant le `finally`).
- **Cooldown** porté à **120 s** (durée réaliste d'un téléchargement ~100 Mo) pour éviter un 2e
  téléchargement concurrent.
- **Cohérence flux** : `apk_url` transmis via l'AJAX (comme `brand`/`country`) → aucun champ ne
  nécessite de sauvegarde préalable ; repli config puis défaut.
- **DRY** : structure d'échec factorée dans `stellantis::echecApk()` ; décompression extraite dans
  `decompresserBz2Borne()`.
- Supply-chain (dépendance à `flobz/psa_apk` sur branche `main`, sans checksum) : **accepté par
  conception** (cf. spec § Notes/risques — extraction à la demande, URL configurable, bandeau ToS au
  clic) ; non figé en SHA pour ne pas perdre la fraîcheur des APK.

## Dépendances
`ext-zip` / `ext-bz2` sont **packagées via `packages.json` (clé `apt` : `php-zip`, `php-bz2`)** et
installées avec le reste des dépendances du plugin. Sur Debian (systèmes Jeedom), les paquets
d'extension PHP embarquent un déclencheur dpkg qui recharge php-fpm/apache → l'extension devient
active sans manip manuelle. La vérification `extension_loaded('zip')`/`('bz2')` **au clic** est
conservée comme garde-fou (repli manuel documenté) au cas où l'installation des dépendances
n'aurait pas été jouée ou aurait échoué — le reste du plugin fonctionne sans.

## Chaînes i18n FR introduites (traduction différée — étape 10 translator)
Toutes en chaîne **littérale** (`{{...}}` / `__('...', __FILE__)`) :
- UI : `Extraire automatiquement` ; tooltip bouton ; texte d'aide APK ; texte `bootbox.confirm` ;
  « Téléchargement de l'application mobile en cours (~100 Mo), veuillez patienter… » ; label +
  tooltip `apk_url`.
- PHP : extensions manquantes ; marque inconnue ; échec téléchargement ; échec décompression ;
  `cultures.json` introuvable ; pays absent de la liste des cultures de l'APK ;
  `parameters.json` introuvable ; identifiants absents de l'APK ; succès
  « Identifiants extraits : vérifiez puis sauvegardez la configuration ».
