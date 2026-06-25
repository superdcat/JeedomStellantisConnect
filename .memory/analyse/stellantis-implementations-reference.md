# Implémentations de référence Stellantis/PSA — où aller chercher le contrat exact

> Le protocole consommateur PSA/Stellantis **n'a pas de doc officielle** (il est reverse-engineered).
> Quand une spec dit « endpoint/payload à confirmer », **la source de vérité est le code** des projets
> ci-dessous. Ce fichier dit **lequel ouvrir selon le besoin**. Vérifié le 2026-06-25.

## 1. `flobz/psa_car_controller` — LA référence (Python)
- Dépôt : https://github.com/flobz/psa_car_controller · DeepWiki (lecture rapide) : https://deepwiki.com/flobz/psa_car_controller
- v3.7.4 (juin 2026), actif, ~1 M pulls Docker. **Implémentation de référence du protocole complet.**
- Où lire quoi :
  | Besoin | Fichier |
  |---|---|
  | Flow OAuth2 PKCE, realms, scope, refresh | `psa_car_controller/psacc/application/psa_client.py` |
  | Extraction `client_id`/`client_secret`/certs depuis l'APK | `psa_car_controller/psa/setup/app_decoder.py` (+ APK : `github.com/flobz/psa_apk`) |
  | **MQTT** : broker, topics, auth, payloads commandes | classe `RemoteClient` (cf. DeepWiki §5.1 « MQTT Remote Client ») |
  | **Data model** statut véhicule | `psa/connected_car_api/models/*` |
  | API REST locale exposée (utile pour s'inspirer du pont) | `docs/psacc_api.md` (`/get_vehicleinfo`, `/wakeup`, `/charge_now`, `/lock_door`, `/horn`, `/lights`, `/preconditioning`) |
- Pièges connus (issues) : `#1130` ban API si wakeup ~2 min ; `#967`/`#851`/`#925` remote token expiré
  (« redo otp procedure ») ; `#733` erreurs 400 jan-2024 ; `#1140` `NoneType get_vehicles()` nov-2025 ;
  `#1199` payloads klaxon/feux.

## 2. `andreadegiovine/homeassistant-stellantis-vehicles` — intégration HA native
- https://github.com/andreadegiovine/homeassistant-stellantis-vehicles · release 2026.5.1 (mai 2026).
- Plus récente que psa_car_controller sur l'auth ; **bonne référence pour le mapping capteurs → entités**
  et la gestion des cadences/guardrails (batterie 12 V, fréquences charge/veille). Forum :
  https://community.home-assistant.io/t/.../838675

## 3. `lelas33/plugin_peugeotcars` — plugin Jeedom existant (PHP)
- https://github.com/lelas33/plugin_peugeotcars — **précédent direct** : API MyPeugeot reverse-engineered
  **en PHP** (`peugeotcars_api2.class.php`, API v3), commandes incluses. Réputé fragile.
- À miner pour : **comment faire l'API consommateur en PHP** (signatures, headers, parsing), et **quels
  écueils éviter** (robustesse). Confirme que la **lecture en PHP pur est faisable**.

## 4. Autres
- `hurzhurz/psa-token-helper`, `sanzoghenzo/psa-connected-car-client` : aides OAuth/token.
- openHAB Groupe PSA binding : https://www.openhab.org/addons/bindings/groupepsa/ (cadence 5 min, modèle Java).
- API **officielle** (contrat propre mais inaccessible particulier) — utile comme **doc de référence du
  data model et des commandes** même si on ne peut pas s'y connecter :
  https://developer.groupe-psa.io/webapi/b2b/remote/set-up/ ; https://developer.groupe-psa.io/webapi/b2c/

## Règle d'or
Lire ces sources **uniquement à la demande** (un besoin précis : payload d'une commande, nom d'un champ),
**citer** la source retenue, et si le code de référence **contredit** une spec interne → **signaler
l'écart** (le code de référence fait foi sur le contrat ; l'analyse interne sur les décisions projet).
Voir aussi `[[stellantis-api-architecture]]` (décision d'archi) et `[[stellantis-data-model]]` (champs).
