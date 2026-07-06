# Spec technique — MVP 01 — Configuration du plugin

> Référence fonctionnelle : `01-config-plugin.md`. Aucune dépendance.
> Aucun appel HTTP dans cette UC : le seul « contrat » est la table statique des marques
> (source : `.memory/external/doc/stellantis/INDEX.md` § 2 + analyse § 1.1, alignées `psa_car_controller`).

## Architecture

### `core/class/stellantis.class.php` — classe `stellantis`
- `public static $_encryptConfigKey = array('client_secret');` — mécanisme **core** vérifié dans la
  source (`config.class.php`) : `config::save(..., 'stellantis')` chiffre (`utils::encrypt`) et
  `config::byKey` déchiffre automatiquement les clés plugin listées. Aucun hook `preConfig_*` nécessaire.
- `const BRANDS` — table marque → `{tld, realm, redirect}` (**possédée par `stellantis`, consommée par
  `stellantisApi` (UC02/03) via `getApiConfig()`** — ne pas la dupliquer dans `stellantisApi`) :

  | clé | tld (`idpcvs.{tld}`) | realm | redirect_uri défaut |
  |---|---|---|---|
  | `peugeot` | `peugeot.com` | `clientsB2CPeugeot` | `mymap://oauth2redirect/{pays}` |
  | `citroen` | `citroen.com` | `clientsB2CCitroen` | `mymacsdk://oauth2redirect/{pays}` |
  | `ds` | `driveds.com` | `clientsB2CDS` | `mymdssdk://oauth2redirect/{pays}` |
  | `opel` | `opel.com` | `clientsB2COpel` | `mymopsdk://oauth2redirect/{pays}` |
  | `vauxhall` | `vauxhall.co.uk` | `clientsB2CVauxhall` | `mymvxsdk://oauth2redirect/{pays}` |

  ⚠️ Orthographe du realm Peugeot à confirmer au runtime en UC04 (`clientsB2CPeugeot` psa_car_controller
  vs `clientsB2CPeugot` portail officiel — cf. analyse § 1.1). Les `redirect_uri` par défaut sont
  **indicatifs** : la valeur réelle vient de l'APK avec les credentials, le champ config prime.
  **⚠️ Écart corrigé (2026-07-06, vérifié dans `constants.py` de psa_car_controller, `realm_info`)** :
  chaque marque a **son propre scheme** (`mymap`/`mymacsdk`/`mymdssdk`/`mymopsdk`/`mymvxsdk`), et non
  deux familles `mymap`(PCD)/`mymopsdk`(Opel-Vauxhall) comme l'indiquaient `03-token.md` § contrat et
  l'analyse § 1.1 — sources internes à corriger.
- `const API_BASE_URL = 'https://api.groupe-psa.com/connectedcar/v4'` (commun à toutes les marques).
- `public static function getApiConfig(?string $_brand = null): array` → 8 clés :
  `['brand','clientId','clientSecret','country','realm','authBaseUrl','apiBaseUrl','redirectUri']`.
  - `$_brand = null` → marque lue en config (mono-marque MVP) ; paramètre prévu pour le multi-marques
    post-MVP (UC 54) sans casser la signature.
  - Normalisation : `strtolower(trim(...))` ; marque vide → `peugeot` (silencieux) ; marque **inconnue**
    → `peugeot` + `log::add(..., 'warning', ...)` (jamais de repli muet).
  - `country` : config `country`, défaut `fr` ; `redirectUri` : config `redirect_uri` si non vide, sinon
    défaut marque avec `{pays}` → `country`.
  - `authBaseUrl = 'https://idpcvs.' . tld . '/am/oauth2'`.
- `public static function isConfigured(): bool` — `client_id` **et** `client_secret` non vides. Signal
  « non configuré » consommé par la page config (bandeau) et par les UC suivantes (02/03/04).

### `plugin_info/configuration.txt` (miroir éditable → copié vers `.php` via `cp`, cf. CLAUDE.md)
- Accès durci à `isConnect('admin')` (le `isConnect()` du template laissait la page — et le secret
  rechargé dans le DOM — accessible à tout utilisateur authentifié ; finding review sécurité 2026-07-06).
- `client_secret` : `autocomplete="new-password"` pour éviter la captation par le navigateur.
- Bandeau conditionnel `alert-warning` « non configuré » via `stellantis::isConfigured()` (autoload OK :
  `stellantis` est la classe principale, chargeable depuis ce point d'entrée externe).
- Note `alert-info` : origine des credentials (extraction APK par l'utilisateur, renvoi doc).
- Champs `configKey` : `brand` (select, 5 marques, défaut peugeot), `client_id`, `client_secret`
  (`type="password"`), `country` (défaut `fr`), `redirect_uri` (placeholder « laisser vide = défaut marque »).

### `docs/fr_FR/index.md`
Section configuration : rôle des champs + **procédure d'extraction des credentials** depuis l'APK
(`app_decoder.py` de psa_car_controller, dépôt d'APK `flobz/psa_apk`) — le plugin ne fait pas l'extraction.

## Server vs Client
100 % serveur (PHP). Le formulaire repose sur le mécanisme `configKey`/`data-l1key` du core
(auto-load/save) : **aucun JS custom, aucune action AJAX** pour cette UC.

## Validation
- Côté client : aucune (mécanisme core).
- Côté serveur : normalisation dans `getApiConfig()` (trim, lowercase, défauts propres, repli marque
  loggué). La page ne plante jamais config vide : `getApiConfig()` retourne toujours un tableau complet.

## Server Actions / API
Aucun endpoint AJAX. Signatures publiques introduites :
```php
stellantis::getApiConfig(?string $_brand = null): array  // 8 clés, défauts propres
stellantis::isConfigured(): bool
```

## Sécurité — décision `client_secret` / DOM (option (a))
Pattern Jeedom **standard** retenu : input `type="password"` en `configKey` ; `config::byKey` déchiffre
et le core recharge la valeur dans l'input (présente dans le DOM d'une page **admin-only**, masquée à
l'écran). L'AC de la spec fonctionnelle est reformulée en conséquence (« jamais affiché en clair à
l'écran ») — décision tracée ici, challengée en review sécurité. Alternative (b) écartée : champ hors
`configKey` + JS custom (hors conventions, machinerie disproportionnée pour une page admin).
Logs : `client_secret` jamais loggué (aucun log ne manipule sa valeur dans cette UC).
Les messages `log::add` sont en français **sans** enveloppe `__()` (fichiers de log non traduits ; seule
l'UI est enveloppée).

## Dépendances
Aucune. `packages.json` reste vide (MVP 100 % PHP).
