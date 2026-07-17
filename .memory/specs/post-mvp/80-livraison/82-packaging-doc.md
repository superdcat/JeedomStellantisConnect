# 82 — Packaging & documentation utilisateur

**Domaine :** Livraison · **Dépend de :** (selon phase) · **Statut :** vivant

## Objectif / valeur
Garantir une installation propre et une **documentation utilisateur** claire — d'autant plus nécessaire ici
que le **setup est inhabituel** (OAuth PKCE manuel, extraction de credentials, OTP) et que l'usage repose
sur une API **non officielle** (risque à expliciter).

## Périmètre
- **Inclus** : `packages.json` correct par phase, `info.json` (compat, dépendances), doc
  `docs/<langue>/` couvrant : obtenir `client_id`/`client_secret`, faire l'OAuth, (post-MVP) l'OTP,
  les limites/risques.
- **Exclu** : —

## Détails techniques
- **`packages.json`** :
  - **MVP (lecture)** : **vide** (100 % PHP). `hasDependency:false`, `hasOwnDeamon:false`.
  - **Post-MVP commandes (état réel implémenté depuis UC11/UC12)** : `pip3` avec `paho-mqtt` **épinglé en
    version exacte `1.6.1`** (⚠️ la 2.0 casse le client MQTT de référence — la version se met dans la
    **valeur** `{"version":"1.6.1"}`, jamais dans la clé ni avec opérateur `<`/`>`, sous peine de bug
    d'installation — cf. `CLAUDE.md` § Support et mémoire `jeedom-packagesjson-shell-pitfall`) + `requests`
    (épinglé `2.32.3`) + `pycryptodomex` (épinglé `3.20.0`, importé `Cryptodome` par le module OTP
    vendorisé `resources/otp_vendor`, UC12 — RSA-OAEP/AES/SHA256). `hasOwnDeamon:true`, `hasDependency:true`,
    `maxDependancyInstallTime:30`. ⚠️ **Debian 12** : pip « externally managed » → virtualenv ou
    `--break-system-packages`, `system::getCmdPython3(__CLASS__)`. **Pas de dépendance `jeedomdaemon`** :
    le démon est un **squelette Jeedom classique** (`resources/demond/demond.py` + lib `resources/demond/jeedom/`,
    transport MQTT générique piloté par `stellantis::sendToDaemon()`), pas la lib tierce
    `Mips2648/jeedom-daemon-py` envisagée initialement.
- **Doc utilisateur** (FR + 3 langues, cf. UC84) : procédure d'obtention des credentials — **état réel
  livré en UC82** : Méthode 1 (recommandée) = extraction automatique **in-Jeedom** (bouton « Extraire
  automatiquement », UC61, s'exécute sur la box) ; Méthode 2 (repli) = extraction manuelle sur un
  ordinateur via `psa_car_controller`/`ApkParser` (aucun outil `app_decoder.py`/`psa-token-helper`,
  jamais implémenté, n'existe pas dans ce projet) ; copier-coller du `code` OAuth, activation OTP,
  **avertissement ToS/risque** (API non officielle, peut casser, légalement à la charge de
  l'utilisateur), limites (fraîcheur, batterie 12 V, anti-ban).
- `info.json` : `category` cohérente (objets connectés / automatisme), `require` OS/Jeedom, langues.

## Critères d'acceptation
- [ ] L'install MVP ne tire **aucune** dépendance ; l'install post-MVP installe `paho-mqtt` proprement.
- [ ] La doc explique pas-à-pas le setup (credentials, OAuth, OTP) et **avertit** du risque ToS/instabilité.

## Notes
- Garder `packages.json` **minimal** ; n'ajouter le démon/pip que quand le dossier `10-commandes-distance`
  est réellement entamé.
