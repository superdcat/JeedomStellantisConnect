# Composants tiers

Ce plugin Jeedom est distribué sous **GNU GPL v3.0** (voir `LICENSE` à la racine).

## Module OTP — `resources/otp_vendor/`

Les fichiers de `resources/otp_vendor/` (`load.py`, `oaep.py`, `otp.py`, `tokenizer.py`, `__init__.py`)
sont une **copie vendorisée verbatim** du sous-paquet `psa_car_controller/psa/otp/` du projet :

- **Projet** : `flobz/psa_car_controller`
- **URL** : https://github.com/flobz/psa_car_controller
- **Licence** : GNU General Public License v3.0
- **Récupéré le** : 2026-07-09 (branche `master`)

Ce module implémente le protocole d'activation OTP inWebo utilisé par les services distants
Stellantis/PSA (serveur `https://otp.mpsa.com`) : provisionnement d'un « device » à partir d'un code
SMS + d'un code PIN, puis génération de codes OTP roulants. Il est appelé uniquement par
`resources/otp_helper.py`.

Conformément à la GPL v3, le code source complet de `psa_car_controller` reste disponible à l'URL
ci-dessus. Aucune modification n'a été apportée au code vendorisé (hors la note de provenance ajoutée en
tête de `__init__.py`, initialement vide dans l'amont).

### Dépendances Python du module (voir `plugin_info/packages.json`)
- `pycryptodomex` (importé sous le nom `Cryptodome`) — RSA-OAEP / AES / SHA256.
- `requests` — appels HTTP vers `https://otp.mpsa.com`.
