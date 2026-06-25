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
  - **Post-MVP commandes** : `pip3 paho-mqtt` **épinglé `<2.0.0`** (⚠️ 2.0 casse `RemoteClient`) (+
    `cryptography` si l'OTP l'exige). `hasOwnDeamon:true`, `hasDependency:true`, `maxDependancyInstallTime`
    réintroduit. ⚠️ **Debian 12** : pip « externally managed » → virtualenv ou `--break-system-packages`,
    `system::getCmdPython3(__CLASS__)`. Lib démon : `Mips2648/jeedom-daemon-py` (`jeedomdaemon~=1.2.0`).
- **Doc utilisateur** (FR + 3 langues, cf. UC84) : procédure d'obtention des credentials (outil externe
  type `app_decoder.py`/`psa-token-helper`), copier-coller du `code` OAuth, activation OTP, **avertissement
  ToS/risque** (API non officielle, peut casser, légalement à la charge de l'utilisateur), limites
  (fraîcheur, batterie 12 V, anti-ban).
- `info.json` : `category` cohérente (objets connectés / automatisme), `require` OS/Jeedom, langues.

## Critères d'acceptation
- [ ] L'install MVP ne tire **aucune** dépendance ; l'install post-MVP installe `paho-mqtt` proprement.
- [ ] La doc explique pas-à-pas le setup (credentials, OAuth, OTP) et **avertit** du risque ToS/instabilité.

## Notes
- Garder `packages.json` **minimal** ; n'ajouter le démon/pip que quand le dossier `10-commandes-distance`
  est réellement entamé.
