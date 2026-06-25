# 11 — Socle : démon Python MQTT (pilotage à distance)

**Domaine :** Commandes à distance · **Dépend de :** MVP/03 (token), MVP/06 (équipements) · **Pré-requis transverse** de tout le dossier 10 · **Statut :** à spécifier (tech)

## Objectif / valeur
Introduire l'**infrastructure démon** qui permet d'envoyer des commandes à distance. Les commandes
PSA/Stellantis transitent par **MQTT** (broker `mwa.mpsa.com:8885`, TLS) — non réalisable proprement en
PHP requête-réponse. On réactive `resources/demond` (squelette Jeedom déjà présent) avec un client MQTT
Python (`paho-mqtt`) **long-running**, piloté par le PHP via le socket Jeedom.

> ⚠️ **Bascule d'architecture** : ce dossier fait passer le plugin de « 100 % PHP sans démon » (MVP) à
> `hasOwnDeamon:true`. Justification : MQTT est un **canal persistant** (commandes + ack temps réel) qui
> redonne sa raison d'être au démon. Cf. `.memory/analyse/stellantis-api-architecture.md` §§ 2-3.

## Périmètre
- **Inclus** : démon `resources/demond/demond.py` (connexion MQTT TLS, abonnement aux topics de réponse,
  publication des commandes), pont **PHP→démon** (socket `jeedom_socket`) et **démon→Jeedom**
  (`jeedom_com` callback pour remonter les ack/états), gestion du cycle de vie (start/stop/health) via
  `stellantis::deamon_info()`/`deamon_start()`/`deamon_stop()`, `hasOwnDeamon:true`, `packages.json` pip.
- **Exclu** : l'OTP/remote token (→ UC12), chaque commande métier (→ UC13-17), le retour d'état détaillé
  (→ UC18).

## Détails techniques
- Broker `mwa.mpsa.com:8885`, MQTT v3.1.1, TLS. Auth MQTT : `username="IMA_OAUTH_ACCESS_TOKEN"`,
  `password = access_token` courant ; le token est aussi réinjecté dans le payload. Topics :
  publish `psa/RemoteServices/from/cid/{customer_id}/{commande}` ; subscribe
  `psa/RemoteServices/to/cid/{customer_id}/#` + `psa/RemoteServices/events/MPHRTServices/{vin}`.
- Le démon reçoit du PHP (socket) un message `{action, vin, params}` → publie le bon topic/payload ;
  écoute les réponses → renvoie à Jeedom via `jeedom_com` (callback) → MAJ commandes info (UC18).
- **Reconnexion** + **refresh token** : sur `return_code='400'` (token expiré) → demander un refresh
  (le PHP gère l'OAuth2 ; le démon récupère le token courant à chaque besoin) puis re-publier.
- `packages.json` : `pip3 paho-mqtt` (+ éventuellement `cryptography`). `hasOwnDeamon:true`,
  `hasDependency:true`, `maxDependancyInstallTime` réintroduit.
- **Implémentation de référence** : classe `RemoteClient` de `psa_car_controller` (broker, topics, auth,
  payloads) — cf. `.memory/analyse/stellantis-implementations-reference.md`.

## Critères d'acceptation
- [ ] Le démon démarre, se connecte au broker MQTT (TLS) et s'abonne aux topics de réponse.
- [ ] La page plugin affiche l'état du démon (lancé/arrêté/santé) via les hooks `deamon_*`.
- [ ] Un message de test PHP→démon est publié sur le bon topic ; un message de réponse remonte à Jeedom.
- [ ] Le démon survit à un refresh de token (pas de crash, re-publication après `400`).
- [ ] Aucun token/secret en clair dans les logs du démon.

## À confirmer
- Identité `customer_id`/`cid` (où l'obtenir : réponse `/user`/profil ?) ; format exact des payloads ;
  host/port broker **en 2026** (possible migration — cf. analyse, incertitudes).
- Voir compagnon `11-socle-demon-mqtt-tech.md` (à écrire au moment de l'implémentation).
