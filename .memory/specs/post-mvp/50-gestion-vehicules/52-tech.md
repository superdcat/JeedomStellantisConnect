# Spec technique — 52 — Image / vignette du modèle du véhicule

> Référence fonctionnelle : `52-image-modele.md`. Dépend d'UC51 (identité véhicule). Plan validé le
> **2026-07-14** (advisor `code-reviewer` + décisions utilisateur : périmètre = **catalogue marque +
> photo modèle best-effort** ; badges = **silhouette tintée par marque**).

## Contrat API (vérifié — AUCUN nouvel appel réseau)
Réutilise le **même** `GET /user/vehicles` déjà consommé par `discoverVehicles()` au clic
« Synchroniser ». Le champ `pictures` (`_embedded.vehicles[].pictures`) **existe** dans le modèle de
référence `psa_car_controller` (`connected_car_api/models/vehicle.py` : `pictures: list[Url]`) **mais** :
- le type `Url` y est un **stub Swagger VIDE** (`swagger_types = {}`, `attribute_map = {}`) — aucune
  propriété `href`/`url` documentée ;
- **aucune** implémentation de référence (`psa_car_controller`, HA `homeassistant-stellantis-vehicles`)
  ne **lit** `pictures`.

⇒ **shape runtime NON vérifiée** (même classe de risque que `/maintenance` et `/alerts`, cf.
`stellantis-api-endpoint-availability.md` : « présent en Swagger ≠ exploitable »). Conséquence de design :
la photo modèle est un **enrichissement best-effort à parsing défensif**, jamais fatal ; un **log `debug`
de la forme brute de `pictures`** est ajouté au discovery pour documenter la shape réelle en recette/beta
et diagnostiquer les échecs d'extraction. La **baseline fiable** (catalogue marque) couvre les 2 AC sans
dépendre de `pictures`.

## Mécanisme image eqLogic Jeedom (vérifié contre la source du core)
Corrige l'inexactitude de la spec fonctionnelle (« `setImage`/fichier plugin ») : **il n'existe AUCUNE
méthode `setImage()`**. Mécanisme réel (`jeedom/core` `eqLogic.class.php` + `core/ajax/eqLogic.ajax.php`) :
- `eqLogic::getImage()` → `getCustomImage()` : si `configuration['image::sha512']` non vide, sert
  `data/eqLogic/eqLogic{ID}-{sha512}.{type}` (relatif à la racine Jeedom) ; **sinon repli sur l'icône du
  plugin** (`plugin::getPathImgIcon()` = `plugin_info/stellantis_icon.png`, UC83).
- L'upload manuel du core fait : `setConfiguration('image::sha512', sha512(file_get_contents($f)))`,
  `setConfiguration('image::type', <extension sans point>)`, écrit `data/eqLogic/eqLogic{ID}-{sha512}.{ext}`,
  et **purge d'abord** les anciens `eqLogic{ID}-*`.
- `sha512()` est un **helper global du core** (hex) → appelé tel quel côté plugin (pas de réimplémentation).
- **CSP** : `data/eqLogic/...` ET l'icône plugin sont servies **same-origin** → aucun blocage CSP, aucune
  `<img>` externe au rendu du dashboard (satisfait AC1/AC2 par construction).

**Persistance des clés `image::*` au « Sauvegarder » du formulaire custom** : `utils::a2o()` du core
**fusionne** `configuration` clé par clé (setter `setConfiguration($k,$v)` à 2 paramètres → itère les
sous-clés soumises). Une clé de config **absente** du formulaire desktop (`image::sha512`, `image::type`,
`image::source`, `image::model_url`) est donc **préservée** au save → **AUCUN champ caché nécessaire**.
⚠️ **Écart signalé** avec la mémoire `jeedom-eqlogic-sync-persist` (« clé absente du form = effacée ») :
l'évidence core dit *merge*, pas *replace*. À confirmer en recette ; les champs readonly d'UC24/41/51
étaient de toute façon dans le formulaire pour l'affichage/l'édition, pas seulement la préservation.

## Architecture — fichiers touchés

### 1. `core/class/stellantis.class.php` — `discoverVehicles()` (~l.402-408)
- Ajouter `pictures` à la sortie (défensif, `array` uniquement) :
```php
'pictures' => (isset($brut['pictures']) && is_array($brut['pictures'])) ? $brut['pictures'] : array(),
```
- Ajouter un log **debug** de la forme brute observée (diagnostic, borné/aseptisé) :
```php
if (isset($brut['pictures'])) {
  log::add('stellantis', 'debug', 'Découverte : forme brute pictures = ' . self::aseptiser((string) json_encode($brut['pictures']), 500));
}
```

### 2. `core/class/stellantis.class.php` — `syncVehicles()` (après `$eqLogic->save();`, ~l.513)
Dans le `try` existant de la boucle, après le save (id disponible) et les compteurs `crees/majs`, avant
`createCommands()` :
```php
// UC52 : image d'équipement (photo modèle best-effort → sinon icône de marque). Best-effort, ne lève
// jamais (try/catch interne) : un échec image ne doit pas perturber la synchro (précédent suivreMaintenance).
self::assurerImageVehicule($eqLogic, $v['brand'], $v['pictures']);
```
Rappel : appelée **au sync uniquement** (pas au cron — pas de fetch d'image périodique). Les véhicules
déjà découverts reçoivent leur image au **prochain clic « Synchroniser »** (mirror UC51 ; aucune migration
`install.php`, qui interdit tout appel réseau).

### 3. `core/class/stellantis.class.php` — nouveaux helpers

#### `assurerImageVehicule(eqLogic $_eq, string $_brand, array $_pictures): void` (orchestration + IO)
Best-effort (`try { … } catch (\Throwable $e) { log debug }`). Logique :
1. **Respect de la personnalisation utilisateur** : si `image::sha512` non vide **ET** `image::source`
   ∉ {`model`,`brand`} ⇒ image posée manuellement (page core standard) ⇒ **ne rien faire** (convention
   projet « ne jamais écraser la perso utilisateur »).
2. **Photo modèle (best-effort)** : `$url = self::extraireUrlImageModele($_pictures)`.
   - Si `$url` exploitable :
     - Si `image::source==='model'` **ET** `image::model_url===$url` ⇒ **skip** (déjà à jour, aucun
       téléchargement — idempotence + self-heal au changement d'URL, cf. HAUT-3 advisor).
     - Sinon `$img = self::telechargerImageModele($url)` :
       - **succès** ⇒ `self::poserImageEqLogic($_eq, $img['body'], $img['type'])` ; poser
         `image::source='model'`, `image::model_url=$url` ; `$_eq->save()` ; **return**.
       - **échec** ⇒ log debug, on **continue** vers le repli marque.
   > Note idempotence : une URL qui échoue est **re-tentée au prochain sync** (pas de cache « known-bad ») —
   > acceptable car la synchro est **manuelle et rare** (cooldown 15 s) et cela auto-guérit les échecs
   > transitoires.
3. **Repli catalogue marque** : `$chemin = self::cheminIconeMarque($_brand)`.
   - Si `null` (marque non cataloguée) : **ne rien poser** ⇒ repli natif icône plugin (ne pas régresser
     une image `brand`/`model` déjà posée : ne toucher à rien).
   - Sinon `$contenu = @file_get_contents($chemin)` ; `$sha = sha512($contenu)` ;
     - si `image::sha512===$sha` **ET** `image::source==='brand'` ⇒ **skip** (idempotent) ;
     - sinon `self::poserImageEqLogic($_eq, $contenu, 'png')` ; poser `image::source='brand'`, effacer
       `image::model_url` (`setConfiguration('image::model_url','')`) ; `$_eq->save()`.

Marqueurs de config posés (tous préservés par a2o-merge, pas de champ formulaire) :
`image::sha512`, `image::type` (standard core), `image::source` (`model`|`brand`), `image::model_url`.

#### `extraireUrlImageModele(array $_pictures): ?string` (PUR — seul endroit du parsing `pictures`)
Parsing **défensif** de la shape non vérifiée. Pour chaque élément de `$_pictures` :
- **string** ⇒ candidat = l'élément ;
- **array** ⇒ premier scalaire string trouvé sous les clés usuelles `href`, `url`, puis
  `['_links']['self']['href']` (gardes `isset`/`is_string`).
Retient la **première** URL `https` valide (`parse_url(...PHP_URL_SCHEME)==='https'`), non vide. Retourne
`null` sinon. On ne devine **pas** de « meilleure taille » (shape inconnue). Log debug de la clé/forme
retenue (diagnostic recette).

#### `telechargerImageModele(string $_url): ?array` (IO durci — calqué sur `telechargerTuile()`)
Renvoie `['body'=>string, 'type'=>'png'|'jpg']` ou `null`. Garde-fous **identiques** à `telechargerTuile`
(cf. anti-SSRF / anti-fuite token) :
- `scheme==='https'` strict (sinon warning + null) ;
- cURL : `HTTPGET`, `RETURNTRANSFER`, `HEADER=false`, **`FOLLOWLOCATION=false`** (anti-SSRF),
  `CONNECTTIMEOUT=5`, `TIMEOUT=15`, `MAXFILESIZE=IMAGE_MODELE_TAILLE_MAX`, `USERAGENT=MAP_USER_AGENT`
  (réutilisé), `Accept: image/png,image/jpeg`, `PROTOCOLS=CURLPROTO_HTTPS` (si défini). **JAMAIS de
  header Authorization/Bearer** (l'URL vient de l'API mais est traitée comme non fiable).
- Vérifs : `corps!==false && errno===0` ; HTTP 2xx ; **content-type allow-list stricte** `image/png` OU
  `image/jpeg` (jamais `image/svg+xml` — script/markup ; ni HTML d'erreur). `type` dérivé du
  **content-type** (`image/png→'png'`, `image/jpeg→'jpg'`), **jamais** de l'extension d'URL.

#### `cheminIconeMarque(string $_brand): ?string` (PUR)
Résout l'asset marque bundlé. Retourne un **chemin absolu** existant ou `null`.
- Normalisation : `strtolower`, **suppression des accents** (⚠️ « Citroën » : `sluggifier` naïf donnerait
  `citro_n` ; normaliser via `iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$b)` avec repli `str_replace` des
  accents courants é/è/ê/ë→e, puis `preg_replace('/[^a-z0-9]/','',…)`).
- Table `const ICONES_MARQUES` : `['peugeot'=>'peugeot.png','citroen'=>'citroen.png','ds'=>'ds.png',
  'dsautomobiles'=>'ds.png','opel'=>'opel.png','vauxhall'=>'vauxhall.png']`.
- Chemin = `dirname(__DIR__, 2) . '/plugin_info/brands/' . <fichier>` (`__DIR__`=`core/class`,
  `dirname(__DIR__,2)`=racine du plugin). `return file_exists($p) ? $p : null;`.

#### `poserImageEqLogic(eqLogic $_eq, string $_binaire, string $_type): bool` (IO défensive, ne lève jamais)
Ordre **écrire-puis-purger** (jamais de fenêtre sans image ; cf. MOYEN-4 advisor) :
1. `$dir = self::dossierImageEqLogic()` (voir ci-dessous) ; si vide/inaccessible ⇒ warning + `return false`.
2. `$sha = sha512($_binaire)` ; `$fichier = 'eqLogic' . $_eq->getId() . '-' . $sha . '.' . $_type`.
3. **Écrire d'abord** : `@file_put_contents($dir.'/'.$fichier, $_binaire)` ; si `false` ⇒ warning +
   `return false`.
4. Poser `image::sha512=$sha`, `image::type=$_type` (le `save()` est fait par l'appelant).
5. **Puis purger** les anciens : `glob($dir.'/eqLogic'.$_eq->getId().'-*')` — le `-` **ancre** sur la
   borne d'ID (`eqLogic12-*` ne matche pas `eqLogic123-…`, cf. MOYEN-4) ; `@unlink` de tous **sauf**
   `$fichier`.
6. `return true`.

#### `dossierImageEqLogic(): string` (helper chemin, calqué `dossierCacheTuile()`)
`$d = dirname(__DIR__, 4) . '/data/eqLogic'` (`core/class` → racine Jeedom, up 4). Si `!is_dir($d)` ⇒
`@mkdir($d, 0775, true)` (best-effort). Retourne `$d` (l'appelant gère l'échec via `file_put_contents`
qui échouera proprement).

### 4. `core/class/stellantis.class.php` — `preRemove()` (~l.3955, actuellement vide) — HAUT-1 advisor
Purge best-effort des fichiers image du véhicule (sinon fuite disque : orphelins jamais nettoyés) :
```php
public function preRemove() {
  $dir = self::dossierImageEqLogic();
  foreach ((array) glob($dir . '/eqLogic' . $this->getId() . '-*') as $f) {
    @unlink($f);
  }
}
```
`getId()` est encore résolvable en `preRemove()`. Glob ancré sur `-` (même borne d'ID). Ne lève jamais.

### 5. `plugin_info/brands/{peugeot,citroen,ds,opel,vauxhall}.png` — assets bundlés
Badges **silhouette de voiture générique tintée par la couleur signature de la marque** + **nom de la
marque en texte** (décision utilisateur). PNG ~256×256, coins arrondis, transparence. **Aucun logo
déposé** (uniquement silhouette générique + wordmark texte). Générés via **Pillow** (script versionné
ci-dessous, précédent UC83). Lus **server-side** (copiés dans `data/eqLogic/`) ; le `.htaccess` de
`plugin_info` autorise déjà `png` en direct — sans incidence.

Couleurs signature indicatives (le dev ajuste) : Peugeot bleu nuit `#16205A`, Citroën rouge `#DA291C`,
DS anthracite/or `#1A1A1A`+`#C0A062`, Opel jaune `#F7FF14` (texte sombre), Vauxhall rouge `#E4022D`.

### 6. `docs/fr_FR/index.md` (+ miroir en/de/es à l'étape traduction) — disclaimer trademark
Ligne courte : « Les noms et couleurs de marques (Peugeot, Citroën, DS, Opel, Vauxhall) sont cités à
titre d'identification ; ce plugin n'est ni affilié ni approuvé par les constructeurs. »

### Constantes
- `const IMAGE_MODELE_TAILLE_MAX = 2097152;` (2 Mo — borne le download).
- Réutiliser `MAP_USER_AGENT` (déjà défini pour UC32) comme User-Agent du download.
- `const ICONES_MARQUES = [...]` (cf. `cheminIconeMarque`).

## Server vs Client
**100 % serveur** (parsing + IO fichier + config). L'affichage est réalisé par le core (`getImage()` sur
le widget eqLogic générique). **Aucun** nouvel AJAX, action, JS, widget, ni démon.

## Validation
- **Serveur** :
  - `pictures` non-array ⇒ `array()` (jamais d'erreur) ; élément non exploitable ⇒ ignoré.
  - URL non-HTTPS / non extractible ⇒ pas de download ⇒ repli marque.
  - download KO / content-type hors {png,jpeg} / trop gros ⇒ `null` ⇒ repli marque.
  - marque non cataloguée ⇒ pas d'image custom ⇒ repli natif icône plugin.
  - dossier `data/eqLogic` inaccessible / `file_put_contents` KO ⇒ `poserImageEqLogic` renvoie `false`,
    warning loggué, la synchro continue.
- **Idempotence** : `image::source`+`image::model_url` évitent le re-download ; `image::sha512` évite la
  réécriture de l'icône marque ; sha512 dans le nom de fichier ⇒ réécriture identique = même fichier.
- **Respect perso utilisateur** : image posée manuellement (source ∉ {model,brand}) jamais écrasée.
- **Client** : néant (aucune saisie).

## Server Actions / API
Aucune nouvelle signature publique. Modifs internes : `discoverVehicles()`, `syncVehicles()`,
`preRemove()` + helpers `assurerImageVehicule` (private static), `extraireUrlImageModele` (private static
pur), `telechargerImageModele` (private static), `cheminIconeMarque` (private static pur),
`poserImageEqLogic` (private static), `dossierImageEqLogic` (private static).

## Sécurité
- **Anti-SSRF / anti-fuite token** : download `https` strict, **pas de redirection**, taille bornée,
  content-type allow-list `png/jpeg` (pas de SVG), **jamais de Bearer**. URL issue de notre API mais
  traitée comme non fiable. **Résiduel documenté** (comme UC32) : pas d'allow-list d'hôte (le CDN d'images
  PSA est inconnu/non vérifié) ⇒ un hôte HTTPS interne pourrait être atteint, mais l'impact est borné à
  « stocker une petite image PNG/JPEG » par les gardes content-type + taille.
- **Assets marque** : contenu bundlé (de confiance). Noms de fichiers : `sha512` hex + type allow-listé +
  id entier ⇒ aucun chemin/injection.

## AC — couverture
- **AC1** (image cohérente modèle *ou* marque, sans blocage CSP) : cascade **photo modèle → icône marque
  → icône plugin**, toutes servies same-origin. ✅
- **AC2** (servie en local, aucune dépendance réseau au rendu) : image dans `data/eqLogic/` ou asset
  bundlé ; le téléchargement a lieu **une fois au sync**, jamais au rendu du dashboard. ✅

## i18n (chaînes FR introduites — traduction différée au sous-agent `translator`)
- **Aucune chaîne UI `__()` / `{{...}}` nouvelle** (feature 100 % serveur + assets). Les `log::add` restent
  en français source (non wrappés, convention projet).
- ⚠️ **Noms de marque dans les PNG** = noms propres, **jamais** `__()` (cohérent avec `brand` traité
  partout sans traduction). À ne PAS compter comme « texte UI en dur non enveloppé » (faux positif
  potentiel du sous-agent `translator` sur des pixels).
- **Disclaimer doc** (`docs/*/index.md`) : ligne FR + miroir en/de/es à l'étape 10.

## Dépendances
Aucune (PHP + cURL déjà présent ; Pillow uniquement pour **générer** les assets hors runtime, comme UC83).

## Script Pillow de génération des badges (versionné — exécuté hors runtime)
Rendu finalisé (silhouette de voiture générique de profil + wordmark texte, couleurs signature par
marque), principe supersampling ×4 + LANCZOS (comme UC83), sortie 5 PNG 256×256 dans `plugin_info/brands/`.
Ce script n'est **pas** livré dans le plugin (asset de build), il est consigné ici pour reproductibilité
(police système `arialbd.ttf`, avec repli `ImageFont.load_default()` si indisponible sur la machine de
génération — n'affecte pas le rendu déjà versionné dans le dépôt).

```python
from PIL import Image, ImageDraw, ImageFont
import os

W, H = 256, 256
SS = 4               # supersampling (antialiasing), cf. UC83
OFFY = -6            # centrage vertical du sujet (voiture) au-dessus du texte
MARGIN, RADIUS = 10, 34
FONT_PATH = "C:/Windows/Fonts/arialbd.ttf"
OUT_DIR = "plugin_info/brands"


def T(x, y): return (x * SS, (y + OFFY) * SS)
def poly(pts): return [T(x, y) for (x, y) in pts]
def circle(d, cx, cy, r, fill): d.ellipse([T(cx - r, cy - r), T(cx + r, cy + r)], fill=fill)


# Silhouette de voiture générique (profil), adaptée/redimensionnée de stellantis_icon.png (UC83).
CAR_BODY = [(40,150),(40,130),(46,118),(76,112),(92,86),(110,80),(146,80),(162,112),(192,116),(198,130),(198,150)]
WINDOW_FRONT = [(94,108),(108,84),(120,84),(120,108)]
WINDOW_REAR = [(126,84),(144,84),(154,108),(126,108)]
WHEELS = [(78,150),(160,150)]
WHEEL_R, HUB_R = 20, 8


def make_badge(name, filename, bg_top, bg_bot, car_color, glass_color, tire_color, hub_color, text_color, label):
    grad = Image.new("RGB", (W * SS, H * SS)); gd = ImageDraw.Draw(grad)
    for y in range(H * SS):
        t = y / (H * SS - 1)
        gd.line([(0, y), (W * SS, y)], fill=tuple(int(a + (b - a) * t) for a, b in zip(bg_top, bg_bot)))

    mask = Image.new("L", (W * SS, H * SS), 0)
    ImageDraw.Draw(mask).rounded_rectangle(
        [MARGIN * SS, MARGIN * SS, (W - MARGIN) * SS - 1, (H - MARGIN) * SS - 1], radius=RADIUS * SS, fill=255)

    badge = Image.new("RGBA", (W * SS, H * SS), (0, 0, 0, 0))
    badge.paste(grad, (0, 0), mask)
    d = ImageDraw.Draw(badge, "RGBA")

    d.polygon(poly(CAR_BODY), fill=car_color)
    d.polygon(poly(WINDOW_FRONT), fill=glass_color)
    d.polygon(poly(WINDOW_REAR), fill=glass_color)
    for cx, cy in WHEELS:
        circle(d, cx, cy, WHEEL_R, tire_color); circle(d, cx, cy, HUB_R, hub_color)

    try:
        font = ImageFont.truetype(FONT_PATH, 30 * SS)
    except OSError:
        font = ImageFont.load_default()
    bbox = d.textbbox((0, 0), label, font=font)
    tx = (W * SS - (bbox[2] - bbox[0])) / 2 - bbox[0]
    ty = (198 + OFFY) * SS - bbox[1]
    d.text((tx, ty), label, font=font, fill=text_color)

    out = badge.resize((W, H), Image.LANCZOS)
    os.makedirs(OUT_DIR, exist_ok=True)
    out.save(os.path.join(OUT_DIR, filename), optimize=True)

    px = out.load()
    assert out.size == (W, H) and out.mode == "RGBA"
    assert [px[0,0][3], px[W-1,0][3], px[0,H-1][3], px[W-1,H-1][3]] == [0, 0, 0, 0]


WHITE, GLASS_DARK = (245, 247, 250), (20, 28, 40)

make_badge("Peugeot", "peugeot.png", (22,32,90), (11,17,54), WHITE, GLASS_DARK, (10,12,18), (150,160,175), WHITE, "PEUGEOT")
make_badge("Citroen", "citroen.png", (224,45,34), (170,28,20), WHITE, GLASS_DARK, (15,8,8), (230,200,195), WHITE, "CITROEN")
make_badge("DS", "ds.png", (40,40,40), (18,18,18), (192,160,98), (35,32,25), (8,8,8), (192,160,98), (192,160,98), "DS")
make_badge("Opel", "opel.png", (247,255,20), (214,221,15), (26,26,30), (70,70,78), (10,10,12), (90,90,98), (26,26,30), "OPEL")
make_badge("Vauxhall", "vauxhall.png", (232,30,45), (176,15,28), WHITE, GLASS_DARK, (15,8,8), (230,200,195), WHITE, "VAUXHALL")
```
