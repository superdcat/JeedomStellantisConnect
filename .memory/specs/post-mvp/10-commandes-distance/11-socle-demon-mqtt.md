# 11 — Socle : démon Python MQTT (pilotage à distance)

**Domaine :** Commandes à distance · **Dépend de :** MVP/03 (token), MVP/06 (équipements) · **Pré-requis transverse** de tout le dossier 10 · **Statut :** à spécifier (tech)

## Objectif / valeur
Introduire l'**infrastructure démon** qui permet d'envoyer des commandes à distance. Les commandes
PSA/Stellantis transitent par **MQTT** (TLS) — non réalisable proprement en PHP requête-réponse. On
réactive `resources/demond` (squelette Jeedom déjà présent) avec un client MQTT Python (`paho-mqtt`)
**long-running**, piloté par le PHP via le socket Jeedom.

> ⚠️ **Bascule d'architecture** : ce dossier fait passer le plugin de « 100 % PHP sans démon » (MVP) à
> `hasOwnDeamon:true`. Justification : MQTT est un **canal persistant** (commandes + ack temps réel) qui
> redonne sa raison d'être au démon. Cf. `[[stellantis-api-architecture]]` §§ 2-3.

## Périmètre
- **Inclus** : démon `resources/demond/demond.py` (connexion MQTT TLS, abonnement aux topics de réponse,
  publication des commandes), pont **PHP→démon** (socket `jeedom_socket`) et **démon→Jeedom**
  (`jeedom_com` callback pour remonter les ack), cycle de vie via `stellantis::deamon_info/start/stop`,
  `hasOwnDeamon:true`, `packages.json` pip.
- **Exclu** : OTP/remote token (→ UC12), chaque commande métier (→ UC13-17), retour d'état détaillé (→ UC18).

## Détails techniques (confirmés recherche 2026-06-25)
- **Broker** : `mw-{brand_code}-m2c.mym.awsmpsa.com:8885` (TLS) — ex. `mw-vx-m2c` (Vauxhall),
  `mw-ap-m2c` (autres) ; `mwa.mpsa.com:8885` aussi cité (dépannage). MQTT v3.1.1.
  > ⚠️ **Écart relevé à l'implémentation UC11 (2026-07-08)** : le **code de référence actuel**
  > `psa_car_controller/psa/RemoteClient.py` utilise **`mwa.mpsa.com:8885`** (constante `MQTT_SERVER`),
  > pas la forme par marque. Le socle prend donc **`mwa.mpsa.com` en défaut** (contrat réel du code de
  > référence) et rend l'hôte **configurable** (`broker_host`) pour retomber sur `mw-{code}-m2c...` si
  > une marque le nécessite. Cf. `11-socle-demon-mqtt-tech.md`.
- **Lib** : **`paho-mqtt >=1.5,<2.0`** — ⚠️ **2.0 casse `RemoteClient`** (épingler `<2.0.0`).
- **Auth MQTT** : `username="IMA_OAUTH_ACCESS_TOKEN"`, `password = access_token` courant ; token aussi
  réinjecté dans le payload. Remote token OTP distinct (UC12).
- **Topics** : publish `psa/RemoteServices/from/cid/{CID}/{ServiceType}/state` ; subscribe
  `psa/RemoteServices/to/cid/{CID}/#` + `psa/RemoteServices/events/MPHRTServices/`. `CID` = `AP-ACNT…`
  (Peugeot/Citroën/DS) ou `OV-ACNT…` (Opel/Vauxhall).
- **Ack** : payload `{process_date, vin, correlation_id, process_code, process_message}` ;
  **900**=accepté, **901**=véhicule en veille, **903**=transmise (cf. UC18).
- **Pont** : PHP (socket) → `{action, vin, params}` → démon publie ; réponses → `jeedom_com` → Jeedom.
- **Reconnexion + token** : sur `return_code='400'` (token expiré) → refresh + re-publish.
- **Packaging** : `pip3 paho-mqtt<2.0.0`. ⚠️ **Debian 12** : pip « externally managed » → virtualenv ou
  `--break-system-packages` ; `system::getCmdPython3(__CLASS__)`. Lib démon recommandée :
  **`Mips2648/jeedom-daemon-py`** (`jeedomdaemon~=1.2.0`, Python 3.9+). `hasOwnDeamon:true`, `hasDependency:true`.
- **Référence** : classe `RemoteClient` de `psa_car_controller` — cf. `[[stellantis-implementations-reference]]`.

## Critères d'acceptation
- [ ] Le démon démarre, se connecte au broker (TLS) et s'abonne aux topics de réponse.
- [ ] La page plugin affiche l'état du démon (lancé/arrêté/santé) via les hooks `deamon_*`.
- [ ] Un message PHP→démon est publié sur le bon topic ; une réponse remonte à Jeedom.
- [ ] Le démon survit à un refresh de token (re-publication après `400`).
- [ ] Aucun token/secret en clair dans les logs du démon ; `paho-mqtt` épinglé `<2.0.0`.

## À confirmer
- Mapping complet `brand_code → hostname` du broker ; format exact des **payloads publiés** (les ack sont
  documentés #824, les publish moins) ; obtention du `CID` (réponse `/user` ?). Voir `11-socle-demon-mqtt-tech.md`.
