# 01 — Configuration du plugin

**Phase :** MVP · **Dépend de :** — · **Fichiers :** page de config plugin (`plugin_info/configuration.php` via `.txt`), `core/class/stellantis.class.php`

## Objectif
Permettre à l'utilisateur de saisir les paramètres d'accès à l'API consommateur PSA/Stellantis, communs
à tous ses véhicules d'une même marque : **marque**, **`client_id`**, **`client_secret`**, **`redirect_uri`**.
Stockés au niveau **plugin** (pas véhicule).

## Périmètre
- **Inclus** : page de configuration plugin (`gotoPluginConf`), champs, persistance, chiffrement de
  `client_secret`, helper PHP de lecture de la config.
- **Exclu** : flow OAuth2 / obtention du token (→ tâche 03), test de connexion (→ tâche 04).

## Détails techniques
- Champs (config plugin via `config::save/byKey(..., 'stellantis')`) :
  - `brand` (select) : Peugeot / Citroën / DS / Opel / Vauxhall. Détermine le TLD `idpcvs.{marque}` et le
    realm `clientsB2C…` (table `stellantis::BRANDS`, possédée par `stellantis`, consommée par
    `stellantisApi` via `getApiConfig()`). Défaut : Peugeot.
  - `client_id` (texte).
  - `client_secret` (texte, **chiffré**, masqué `inputPassword`).
  - `redirect_uri` (texte) : schéma custom de la marque (ex. `mymap://oauth2redirect/fr`). Défaut par marque.
  - (optionnel) `country`/`locale` si nécessaire au flow.
- **Origine des credentials** : `client_id`/`client_secret` sont **extraits de l'APK** de l'app mobile
  par l'utilisateur — **documenter la procédure** (doc utilisateur, réécrite le 2026-07-06 : assistant
  `psa_car_controller --web-conf` qui télécharge l'APK et extrait tout seul, ou `app_decoder.py`
  manuel), le plugin ne fait pas l'extraction (cf. analyse § 4, décision (a) + `stellantis-psacc-vs-natif.md` § 6).
  ⚠️ **Credentials propres au pays/locale** : dans l'APK ils vivent sous `res/raw-{lang}-r{country}/parameters.json`
  → un compte FR et un compte GB peuvent avoir des `client_id`/`client_secret` **différents** ;
  l'utilisateur doit extraire ceux de **son** pays. Le champ `country` sert ici à construire le
  `redirect_uri` par défaut ; il doit rester **cohérent** avec le pays des credentials.
- Chiffrement : `$_encryptConfigKey` (ou hooks `preConfig_client_secret`) → `utils::encrypt`. **Jamais**
  logguer `client_secret`.
- Helper `stellantis::getApiConfig(?string $brand = null): array` → `['brand','clientId','clientSecret','country','realm','authBaseUrl','apiBaseUrl','redirectUri']` prêt à l'emploi (table marque→TLD/realm intégrée ; paramètre `$brand` prévu pour le multi-marques post-MVP, `null` = marque configurée).

## Critères d'acceptation
- [ ] Les champs sont saisissables et persistés (survivent à un rechargement / redémarrage).
- [ ] `client_secret` n'apparaît en clair ni en base (chiffré via `$_encryptConfigKey`), ni dans les
  logs, ni **affiché à l'écran** (input `type="password"`). *Décision 2026-07-06 (option (a), cf. spec
  technique) : pattern `configKey` standard Jeedom — le core recharge la valeur déchiffrée dans l'input
  password du DOM de la page config (admin-only) ; l'exigence « absent du DOM » est volontairement
  relâchée, conforme aux plugins officiels.*
- [ ] `stellantis::getApiConfig()` retourne les bons TLD/realm selon la marque choisie.
- [ ] La page ne plante pas si la config est vide (défaut propre, message « non configuré »).

## Notes / risques
- ⚠️ `plugin_info/configuration.php` est en **accès restreint** : éditer le **miroir `.txt`** puis copier
  (cf. `CLAUDE.md` § Architecture).
- Prévoir un défaut propre quand la config est vide (les autres tâches doivent gérer « non configuré »).
- Table marque→{TLD, realm, redirect_uri} : cf. `.memory/external/doc/stellantis/INDEX.md` § 2.
