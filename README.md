# Stellantis Connect — Plugin Jeedom

Plugin **[Jeedom](https://www.jeedom.com/)** qui connecte les véhicules **Stellantis / ex-Groupe PSA**
(**Peugeot, Citroën, DS, Opel, Vauxhall**) à votre installation domotique : remontée de la
**télémétrie** (batterie/SOC, charge, autonomie, carburant, position GPS, kilométrage, état
portes/verrouillage, pression pneus, préconditionnement) et, à terme, **pilotage à distance** (réveil,
charge, préconditionnement, verrouillage, klaxon, feux).

> ⚠️ **API non officielle.** Stellantis ne propose pas d'accès développeur aux particuliers. Ce plugin
> utilise l'**API consommateur** — celle des applications mobiles MyPeugeot/MyCitroën/MyDS/MyOpel/
> MyVauxhall, rétro-documentée par la communauté (projet de référence
> [`psa_car_controller`](https://github.com/flobz/psa_car_controller)) — et non l'API officielle
> B2B/B2C, inaccessible en pratique à un particulier. Cet usage n'est pas couvert par les conditions
> d'utilisation des applications mobiles ; c'est un risque assumé et documenté, à la charge de
> l'utilisateur.

## État du projet

En développement actif. Le socle **MVP** (lecture seule, 100 % PHP, sans démon) est en cours
d'implémentation :

- ✅ Configuration du plugin (marque, `client_id`/`client_secret`, pays)
- ✅ Client HTTP REST bas niveau vers l'API consommateur
- ✅ Authentification OAuth2 (Authorization Code + PKCE) et gestion du token
- ✅ Test de connexion
- ✅ Découverte des véhicules du compte
- ⏳ Création des équipements, remontée de la télémétrie, rafraîchissement périodique (cron)

Le pilotage à distance (réveil, charge, préconditionnement, verrouillage…) est prévu **après** le MVP,
via un démon Python dédié (MQTT) — voir la feuille de route ci-dessous.

## Installation & configuration

Comme tout plugin Jeedom : dépôt sous `<jeedom>/plugins/stellantis/`, puis activation depuis
`Plugins → Gestion des plugins`. Aucune dépendance externe pour le MVP (`hasDependency: false`,
`hasOwnDeamon: false`).

La configuration nécessite d'obtenir un `client_id`/`client_secret` propres à votre marque et à votre
pays (non fournis par Stellantis) : la procédure complète — extraction depuis l'APK, connexion OAuth2,
récupération du code d'autorisation — est détaillée dans la documentation utilisateur :

- 🇫🇷 [`docs/fr_FR/index.md`](docs/fr_FR/index.md) (langue source)
- 🇬🇧 [`docs/en_US/index.md`](docs/en_US/index.md)
- 🇩🇪 [`docs/de_DE/index.md`](docs/de_DE/index.md)
- 🇪🇸 [`docs/es_ES/index.md`](docs/es_ES/index.md)

## Pour les contributeurs

- [`CLAUDE.md`](CLAUDE.md) — architecture du plugin, conventions du dépôt, contraintes Jeedom
  (autoload, chiffrement de config, i18n).
- [`.memory/specs/`](.memory/specs/README.md) — feuille de route découpée en cas d'usage (UC), MVP puis
  post-MVP par domaine (commandes à distance, énergie/charge, localisation, entretien, supervision…).
- [`.memory/analyse/`](.memory/analyse/INDEX.md) — décisions d'architecture et enseignements
  (choix de l'API consommateur, comparaison avec une dépendance à `psa_car_controller`, modèle de
  données, pièges connus).
- Pas de build local ni de lint/test en ligne de commande : la validation tourne en CI
  (`.github/workflows/work.yml`, workflows réutilisables de [`jeedom/workflows`](https://github.com/jeedom/workflows))
  sur push/PR vers `beta` et PR vers `master`. Pousser sur une branche `prettier` déclenche un
  reformatage automatique du code.

## Licence

AGPL — voir [`plugin_info/info.json`](plugin_info/info.json).
