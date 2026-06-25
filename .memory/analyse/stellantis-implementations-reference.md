# Implémentations de référence Stellantis/PSA — où aller chercher le contrat exact

> Le protocole consommateur PSA/Stellantis **n'a pas de doc officielle** (reverse-engineered). Quand une
> spec dit « endpoint/payload à confirmer », **la source de vérité est le code** des projets ci-dessous.
> Ce fichier dit **lequel ouvrir selon le besoin**. Vérifié le 2026-06-25 (7 axes de recherche).

## 1. `flobz/psa_car_controller` — LA référence (Python)
- https://github.com/flobz/psa_car_controller · DeepWiki : https://deepwiki.com/flobz/psa_car_controller
- **v3.7.4 (juin 2026), actif** (564★, 321 forks, plusieurs releases/mois). Archi duale :
  **`PSAClient` (REST/OAuth2)** + **`RemoteClient` (MQTT, port 8885, paho-mqtt)**, historique SQLite.
- Où lire quoi :
  | Besoin | Fichier |
  |---|---|
  | OAuth2 **PKCE**, realms, scope, refresh, **`@rate_limit(6,1800)`** | `psa/oauth.py`, `psacc/application/psa_client.py` |
  | Extraction `client_id`/`client_secret` depuis l'APK (`res/raw-{lang}-r{country}/parameters.json`) + host broker | `psa/setup/app_decoder.py` |
  | **MQTT** : broker `mw-{brand}-m2c.mym.awsmpsa.com:8885`, topics, payloads, OTP | `RemoteClient` (DeepWiki §5.1), `psa/otp.py` |
  | **Data model** statut (chemins JSON, enums) | `psa/connected_car_api/models/*` + `api_spec.md` |
  | API REST locale (modèle de pont à imiter) | `docs/psacc_api.md` |
- **Dumps JSON réels** (précieux pour le data-model) : issues #1121 (e-C4 v4.15+), #811 (payload MQTT
  e-208), #393, #839 (SOH) ; discussion #700 (changelog v4.15.1) ; issue #678 (`?extension=` cassé).
- Pièges : ban si wakeup ~2 min (#1130) ; remote token expiré « redo otp » (#967/#851/#925) ;
  **paho-mqtt 2.0 casse `RemoteClient`** → `<2.0.0`.

## 2. `andreadegiovine/homeassistant-stellantis-vehicles` — intégration HA (la plus maintenue)
- https://github.com/andreadegiovine/homeassistant-stellantis-vehicles — **252 releases, dernière mai
  2026, 240★, Python 86 %**. Préférée à l'addon psa_car_controller pour la stabilité.
- **Meilleure référence de mapping champ JSON → entité** : `const.py` (scopes, champs) et `configs.json`
  (OAuth2 par marque). Entités exposées : `battery %`, `charging` (binary), `device_tracker` (GPS),
  `mileage`, `autonomy`, `temperature`, `wakeup`, `preconditioning` (switch), `horn`, `lights`, `lock`,
  `charge_limit` → directement transposable en commandes Jeedom.

## 3. `lelas33/plugin_peugeotcars` — plugin Jeedom précédent (⚠️ ABANDONNÉ)
- https://github.com/lelas33/plugin_peugeotcars — **abandonné** (dernier tag v0.9 jan-2023 ; abandon
  confirmé sur community.jeedom.com avril-2026 ; **erreurs 401 depuis 2024** car **pas adapté au PKCE**).
- **Ne PAS le prendre comme base de code.** Mais son `changelog.md` documente des **bugs à éviter** :
  password avec caractères spéciaux (parsing shell), **race condition** sur `config.json`, « climatisation
  fantôme » après expiration token (corrigé v0.8). v0.6 utilisait psa_car_controller en **pont PHP→Python**
  pour les commandes MQTT — confirme la faisabilité de l'approche démon. **La place est libre** pour un
  plugin neuf.

## 4. Démon Jeedom (post-MVP) — bonnes pratiques confirmées
- Doc officielle : https://doc.jeedom.com/fr_FR/dev/daemon_plugin. `resources/demond/demond.py` +
  `jeedom/jeedom.py`, `hasOwnDeamon:true`, hooks **`deamon_info`/`deamon_start`/`deamon_stop`**,
  `packages.json` format `pip3`.
- **Bibliothèque moderne recommandée** : `Mips2648/jeedom-daemon-py` (`jeedomdaemon~=1.2.0`, Python 3.9+) —
  démon fonctionnel en quelques lignes. https://github.com/Mips2648/jeedom-daemon-py
- ⚠️ **Debian 12** : pip « externally managed » → utiliser un **virtualenv** ou `--break-system-packages` ;
  appeler `system::getCmdPython3(__CLASS__)` pour le bon interpréteur.
- **OAuth2 callback** (install Jeedom locale) : pas d'URL publique → URL d'autorisation affichée dans la
  page config admin, code **collé manuellement** (DevTools), soumis via `core/ajax/stellantis.ajax.php`
  (confirme le design UC MVP/03). Réf. forum : community.jeedom.com/t/.../30978.

## 5. API officielle (contrat propre, inaccessible particulier — utile comme doc de référence)
- https://developer.groupe-psa.io/webapi/b2c/ (data model, catalogue commandes, scopes) ;
  https://developer.groupe-psa.io/webapi/b2b/remote/set-up/ (payloads commandes). À lire **uniquement**
  pour comprendre le data model/les commandes, **pas** pour s'y connecter.

## Règle d'or
Lire ces sources **à la demande** (besoin précis : payload d'une commande, chemin d'un champ), **citer**
la source, et si le code de référence **contredit** une spec interne → **signaler l'écart** (le code de
référence fait foi sur le contrat ; l'analyse interne sur les décisions projet).
Voir aussi `[[stellantis-api-architecture]]` (décision d'archi) et `[[stellantis-data-model]]` (champs).
