# Index des analyses internes — plugin Jeedom Stellantis

> **But** : rendre la connaissance interne du projet (décisions d'architecture, limites/pièges,
> apprentissages durables) **découvrable et lazy-loadable** par le workflow de dev, sans tout charger.
> L'agent lit cet index (gratuit, local), repère le fichier d'analyse utile, puis ouvre **uniquement**
> ce fichier.
>
> `.memory/analyse/` complète `.memory/specs/` (intention des UC) et la doc externe
> (`.memory/external/doc/`) : ici on consigne ce que **le projet a tranché** ou ce qu'on a **appris en
> codant** — ce que ni le code, ni git, ni `CLAUDE.md` ne disent déjà.
>
> **Maintenance** : à chaque enseignement durable (Étape 12 du workflow `/feature`), écrire dans le bon
> fichier thématique (ou en créer un) **et mettre à jour cet index** (ligne + déclencheurs § 0 + date).
> **Dernière synchro** : 2026-06-25 (création initiale, transposition depuis un plugin caméra vers le
> domaine véhicule connecté Stellantis ; recherche web de fondation consignée dans
> `stellantis-api-architecture.md`).

---

## 0. Correspondance « incertitude » → fichier d'analyse (raccourci)

| Si l'incertitude porte sur… | Fichier |
|---|---|
| **Quelle API** viser (officielle B2B inaccessible vs consommateur reverse-engineered), pourquoi | `stellantis-api-architecture.md` § « DEUX API » |
| Flow **OAuth2 PKCE** (endpoints par marque, realms, scope, échange code, refresh, TTL ~15 min) | `stellantis-api-architecture.md` § 1.1 |
| Origine des `client_id`/`client_secret` (extraits de l'APK), `redirect_uri`, setup interactif | `stellantis-api-architecture.md` § 1.1 + § 4 |
| **Deux systèmes de tokens** (OAuth2 REST vs remote token OTP/SMS pour MQTT) — ne pas confondre | `stellantis-api-architecture.md` § 1.1 |
| **Commandes à distance** : MQTT (broker `mwa.mpsa.com:8885`, topics, payloads, ack async) | `stellantis-api-architecture.md` § 1.3 |
| **PHP natif vs démon Python** : lecture en PHP, commandes MQTT → démon (inversion vs philosophie « sans démon ») | `stellantis-api-architecture.md` § 2 et § 3 |
| **Limites** : ban API (wakeup ~2 min), batterie 12 V, mode privacy, quotas OTP (6/24 h), seuil charge | `stellantis-api-architecture.md` § 1.4 |
| **Champs de télémétrie** (SOC, autonomie, charge, position, portes, km, pneus…) → quelles commandes info | `stellantis-data-model.md` |
| Création conditionnelle de commandes selon **motorisation** (élec/hybride/thermique) | `stellantis-data-model.md` § 3 |
| **Où trouver le contrat exact** (endpoint/payload non documenté) : code de référence à lire | `stellantis-implementations-reference.md` |
| Plugin Jeedom PSA **existant** (PHP) et intégrations HA/openHAB à miner | `stellantis-implementations-reference.md` |
| **Widget de commande** Jeedom (fichier `cmd.<type>.<subType>.<nom>.html`, `setTemplate`, tokens `#id#`…) | `jeedom-widgets-commandes.md` §§ 1-2 |
| Widget pilotant **plusieurs commandes** (carte + actions) ; résoudre les sœurs par `byEqLogic` | `jeedom-widgets-commandes.md` § 3 |
| Exécuter une action depuis un widget + récupérer le retour PHP ; auth/CSRF AJAX ; AJAX plugin admin-only | `jeedom-widgets-commandes.md` §§ 4-5 |
| **CSP Jeedom bloque tout média/image EXTERNE** → proxy same-origin (ex. tuile carte) | `jeedom-widgets-commandes.md` § 7 |
| Ajouter une **PAGE** au menu Jeedom (panel) ; toggle natif `displayDesktopPanel/Mobile` ; page non-admin | `jeedom-panel-page-menu.md` |

> Si aucun fichier ne couvre le sujet : ce n'est pas (encore) analysé en interne → passer à la doc
> externe (`.memory/external/doc/stellantis|jeedom/INDEX.md`), et penser à capitaliser en Étape 12.

---

## 1. Catalogue des analyses

| Fichier | Sujet | Points clés indexés |
|---|---|---|
| `stellantis-api-architecture.md` | **Décision d'architecture** : voie d'accès (API officielle inaccessible → consommateur reverse-engineered) + archi Jeedom (hybride PHP/démon). | DEUX API (officielle B2B/B2C mTLS sur dossier vs consommateur APK) ; OAuth2 **PKCE** par marque (idpcvs.{marque}, realms `clientsB2C…`, TTL ~15 min) ; **2 tokens** (OAuth2 REST + remote OTP MQTT) ; commandes **MQTT** `mwa.mpsa.com:8885` (topics/payloads/ack) ; **MVP lecture 100 % PHP sans démon**, **commandes post-MVP via démon Python `paho-mqtt`** (le push MQTT redonne sa raison d'être au démon, ≠ IMOU) ; limites (ban wakeup ~2 min, batterie 12 V, privacy, quotas OTP 6/24 h, seuil charge ~50 %) ; sujets à trancher. |
| `stellantis-data-model.md` | **Modèle de données** télémétrie (`GET /status`, `connected_car v4`) → quelles commandes info Jeedom. | 1 véhicule = 1 eqLogic (clé **VIN**) ; `energies[]` (SOC/level, autonomy, charging.status/plugged/rate/remaining), position GeoJSON, `kinetic.moving`, `odometer.mileage`, ouvrants/verrouillage, préconditionnement, environnement/pneus/alertes/privacy/lastUpdate ; **création conditionnelle selon motorisation** ; noms de champs **à reconfirmer** contre une réponse réelle. |
| `stellantis-implementations-reference.md` | **Où chercher le contrat exact** (pas de doc officielle du protocole consommateur). | `flobz/psa_car_controller` (psa_client.py=OAuth, app_decoder.py=APK, RemoteClient=MQTT, models=data) ; `homeassistant-stellantis-vehicles` (mapping capteurs, cadences) ; `lelas33/plugin_peugeotcars` (API PSA **en PHP**, écueils) ; règle « code de référence = source de vérité du contrat ». |
| `jeedom-widgets-commandes.md` | Widgets de commande Jeedom (templates dashboard/mobile), vérifié contre la source du core. | `cmd.<type>.<subType>.<nom>.html` + `setTemplate('stellantis::<nom>')` ; tokens (`#id#`/`#logicalId#`/`#eqLogic_id#`/`#uid#`…) ; `#cmd_id[…]#` & `jeedom.cmd.byEqLogicId` **n'existent pas** → résoudre par AJAX **`byEqLogic`** ; **masqué ≠ non-exécutable** ; `jeedom.cmd.execute` (CSRF/droits, `success.result`=retour PHP) ; AJAX plugin admin-only inutilisable au dashboard ; **§ 7 CSP : média/image externe bloqué → proxy same-origin** (ex. tuile carte véhicule). |
| `jeedom-panel-page-menu.md` | Page de plugin au **menu** Jeedom (panel) & toggle d'affichage natif (ex. carte « Mes véhicules »). | `info.json "display"`/`"mobile"` enregistre une page-panneau ; le core ajoute nativement les cases « Afficher le panneau desktop/mobile » (`displayDesktopPanel`/`displayMobilePanel`, masqué par défaut) → aucun toggle custom ; `plugin::getDisplay()` statique ; page panel = `isConnect()` non-admin + accès par eqLogic `hasRight('r')` + sélection `isVisiblePanel` ; réf. `jeedom/plugin-gsl`. |
