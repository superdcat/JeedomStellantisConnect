# Étude d'architecture : connecter des véhicules Stellantis/PSA à Jeedom

> Analyse réalisée le 2026-06-25 pour le plugin Jeedom **stellantis**.
> Objectif du plugin : remonter dans Jeedom la télémétrie des véhicules **Peugeot / Citroën / DS /
> Opel / Vauxhall** (batterie/SOC, charge, autonomie, carburant, position GPS, kilométrage, état
> portes/verrouillage, pression pneus, préconditionnement) et, à terme, **piloter à distance**
> (réveil, charge, préconditionnement, verrouillage, klaxon, feux).
> Question posée : **quelle voie d'accès à l'API** (officielle vs reverse-engineered) et **quelle
> architecture Jeedom** (PHP natif vs démon Python) ?
>
> ⚠️ **Statut des sources** : synthèse de **7 axes** de recherche web aboutis (auth OAuth2,
> commandes/MQTT, limites/viabilité, portail officiel, psa_car_controller détaillé, data-model exhaustif,
> écosystème Jeedom/HA), recoupés sur la doc officielle `developer.groupe-psa.io`, le code de
> `flobz/psa_car_controller` (+ dumps JSON réels d'issues), les intégrations HA/openHAB et les forums
> Jeedom. Les axes 4-7 se **corroborent mutuellement** avec les axes 1-3 (auth, MQTT, viabilité). Une
> **passe de vérification adversariale dédiée n'a pas été exécutée séparément** (faite par recoupement
> manuel). Les faits `confidence: high` sont sourcés ; les **noms exacts d'endpoints/champs côté API
> consommateur restent à reconfirmer au runtime** (varient selon millésime/motorisation/forfait), cf.
> `[[stellantis-data-model]]` et `.memory/external/doc/stellantis/INDEX.md`.

---

## ⚠️ Le constat qui change tout : il y a DEUX API, pas une

Contrairement à IMOU (une seule API cloud signée, simple), Stellantis/PSA expose **deux mondes
radicalement différents**, et le choix entre les deux conditionne toute l'architecture :

| | **A. API OFFICIELLE** (`developer.groupe-psa.io`) | **B. API CONSOMMATEUR** (reverse-engineered) |
|---|---|---|
| Public visé | Partenaires B2B/flottes & B2C « sur dossier » | Apps mobiles MyPeugeot/MyCitroën/… détournées |
| Auth | OAuth2 + **certificat TLS mutuel signé par Stellantis** (CA, CSR `OU=Programs Partners`) | OAuth2 **PKCE** avec `client_id/secret` **extraits de l'APK** |
| Accès réel pour un particulier | ❌ **Impossible en pratique** : process Mobilisights opaque, **jamais répondu** même à `psa_car_controller` (1 M téléchargements) | ✅ **Seule voie fonctionnelle** en 2026 |
| Transport commandes | REST POST + **webhook callback** (push HTTP) | **MQTT** (`mwa.mpsa.com:8885`) + token OTP |
| Push / Monitors | ✅ disponible (callbacks HTTP, MQTT WebPortal) **mais accréditation requise** | ❌ pas de push accessible → **polling** |
| Faisable en PHP pur | ✅ (REST + récepteur webhook) | ✅ pour la **lecture** REST ; ❌ pour les **commandes** (MQTT) |
| Légalité | ✅ contractuel | ⚠️ contraire aux ToS des apps mobiles (risque sur l'utilisateur final) |

➡️ **Décision de voie** : l'API officielle étant **inaccessible** à un particulier (confirmé : la
demande d'accès B2C de `psa_car_controller` est restée sans réponse — GitHub Stellantis issue #128),
le plugin **doit** viser l'**API consommateur** (voie B), comme le font `psa_car_controller`,
`homeassistant-stellantis-vehicles` et le plugin Jeedom existant `plugin_peugeotcars`.
La légalité (ToS) est un **risque assumé et documenté pour l'utilisateur**, pas un secret.

---

## 1. L'API consommateur (la fondation commune) — ce qu'il faut savoir

### 1.1 Authentification = DEUX couches de tokens orthogonales

C'est **le** piège conceptuel. Il y a deux systèmes de tokens **indépendants**, à gérer séparément :

**Couche 1 — OAuth2 PKCE (API REST, télémétrie) :**
- Flow **Authorization Code + PKCE** (`code_challenge_method=S256`) depuis **janvier 2023** (le grant
  `password` n'existe plus → **interaction navigateur manuelle obligatoire** au 1er setup).
- Endpoints **par marque** : `https://idpcvs.{marque.tld}/am/oauth2/{authorize|access_token|token/revoke}`.
  - Peugeot `idpcvs.peugeot.com` · Citroën `idpcvs.citroen.com` · DS `idpcvs.driveds.com` ·
    Opel `idpcvs.opel.com` · Vauxhall `idpcvs.vauxhall.co.uk`.
- **Realm** par marque (header `x-introspect-realm` sur les appels REST) :
  `clientsB2CPeugeot`, `clientsB2CCitroen` (sans tréma), `clientsB2CDS`, `clientsB2COpel`, `clientsB2CVauxhall`.
- Échange code→token : `POST .../access_token`, `Authorization: Basic base64(client_id:client_secret)`,
  `grant_type=authorization_code` + `code` + `redirect_uri`. Réponse :
  `{access_token, refresh_token, expires_in, id_token}`.
- **TTL access_token court** — **~15 min** (communauté/CLAUDE.md projet) à **~1 h** (DeepWiki) selon
  source/IdP/marque → **à mesurer** ; refresh fréquent indispensable (`grant_type=refresh_token`).
  **refresh_token ~30 j avec rotation** à chaque usage. ⚠️ **Rate-limit de référence : 6 refresh / 30 min**
  (`@rate_limit(6,1800)` dans `oauth.py` de psa_car_controller) → **à reproduire** (anti-ban). Header
  d'échange : `Authorization: Basic base64(client_id:client_secret)`.
- `scope` ≈ `"openid profile"` (+ selon impl. `VehicleState_read localisation_read`). `redirect_uri` =
  schéma custom **propre à chaque marque** (corrigé 2026-07-06, vérifié dans `constants.py` /
  `realm_info` de psa_car_controller — l'ancien regroupement « par famille » était faux) :
  Peugeot `mymap://oauth2redirect/{pays}`, Citroën `mymacsdk://…`, DS `mymdssdk://…`,
  Opel `mymopsdk://…`, Vauxhall `mymvxsdk://…`. Table implémentée : `stellantis::BRANDS` (UC01).
- ⚠️ **PKCE = spécifique à l'API CONSOMMATEUR** (celle qu'on utilise) ; l'API **officielle B2C** est
  OAuth2 Authorization Code **sans PKCE** (avec `client_secret`). Ne pas confondre les deux contrats.
- ⚠️ **Orthographe des realms à confirmer** : `clientsB2CPeugeot` (psa_car_controller) vs `clientsB2CPeugot`
  (doc portail officiel — sans 'e') ; realms OTP `OTP<Marque>`. Vérifier au runtime.
- **`client_id`/`client_secret` ne sont PAS fournis aux particuliers** : extraits de l'APK mobile
  (script `app_decoder.py` de psa_car_controller, APK sur `github.com/flobz/psa_apk`). L'APK contient
  aussi des certificats client (`public.pem`/`private.pem`) et `host_brandid_prod`, `site_code`, `culture`.

**Couche 2 — Remote token OTP (commandes MQTT, POST-MVP uniquement) :**
- **Distinct** du token OAuth2. Obtenu par **activation OTP** : SMS + **PIN 4 chiffres de l'app mobile**.
- Produit `remote_token` + `remote_refresh_token` (stockés à part).
- Serveur OTP `https://otp.mpsa.com` (device inWebo, RSA-OAEP/AES/SHA256), codes base36 4 chiffres.
  **Limite dure : 6 codes / 24 h** (générés côté serveur → chaque `get_otp_code()` est un appel réseau)
  et **20 activations SMS / compte** au total → un compte peut se **bloquer** définitivement.
- À l'expiration : « redo otp procedure » (impossible à automatiser → **alerter l'utilisateur**, ne pas
  re-tenter en boucle).
- **Endpoints confirmés (UC12, 2026-07-09, vs `RemoteClient`/`psa_client`)** : SMS = `POST
  api.groupe-psa.com/applications/cvs/v4/mobile/smsCode?client_id=…` ; remote token = `POST
  …/applications/cvs/v4/mobile/token?client_id=…` (grant `password`=code OTP, puis `refresh_token`) — les
  deux avec Bearer OAuth2 + `x-introspect-realm`. **PAS** `virtualkey/remoteaccess/token`. Réponse
  `{access_token, refresh_token}`, TTL ~890 s. Crypto OTP vendorisée (`resources/otp_vendor`).

### 1.2 API REST (lecture) — base `connected_car v4`

- Base : `https://api.groupe-psa.com/connectedcar/v4`.
- Headers : `Authorization: Bearer {access_token}` + `x-introspect-realm: {realm}` + `client_id` en query.
- Liste véhicules : `GET /user/vehicles` → `id` (id API ≠ VIN), `vin`, `brand`, `label`/`model`.
- Statut : `GET /user/vehicles/{id}/status` → tout l'état (cf. `.memory/analyse/stellantis-data-model.md`).
- Dernière position : `GET /user/vehicles/{id}/lastPosition` (GeoJSON).
- ⚠️ La voiture **ne remonte PAS spontanément** après quelques minutes moteur coupé : `GET /status`
  renvoie le **dernier état connu** (mis à jour sur événements : fin de trajet, jalons de charge…).
  Pour **forcer** une lecture fraîche → **wakeup** (= commande MQTT, couche 2, post-MVP).

### 1.3 Commandes à distance — MQTT (couche 2)

- Broker **`mw-{brand_code}-m2c.mym.awsmpsa.com:8885`** (TLS) — ex. `mw-vx-m2c` (Vauxhall),
  `mw-ap-m2c` (autres). `mwa.mpsa.com:8885` aussi cité (dépannage). Lib Python **`paho-mqtt` ≥1.5,<2.0**
  (⚠️ **paho-mqtt 2.0 casse `RemoteClient`** → épingler `<2.0.0` dans `packages.json`).
  > ⚠️ **Précision UC11 (2026-07-08)** : le **code de référence actuel** (`RemoteClient.py`,
  > `MQTT_SERVER`) utilise **`mwa.mpsa.com:8885`** pour toutes les marques, pas la forme `mw-{code}-m2c`.
  > Le socle a donc pris `mwa.mpsa.com` en défaut + hôte configurable (`broker_host`). Le mapping par
  > marque reste l'alternative documentée si une marque le nécessite. TLS via `tls_set_context()`
  > (vérif certif/hostname par défaut), MQTTv311, `clean_session`.
- Topics : publish `psa/RemoteServices/from/cid/{CID}/{ServiceType}/state` ; subscribe
  `psa/RemoteServices/to/cid/{CID}/#` + `psa/RemoteServices/events/MPHRTServices/{vin}`. `CID` = format
  `AP-ACNT…` (Peugeot/Citroën/DS) ou `OV-ACNT…` (Opel/Vauxhall).
  > ⚠️ **Correction audit UC11-16 (2026-07-10)** : le topic d'événements est
  > `.../events/MPHRTServices/{vin}` (le code de référence s'abonne **par VIN**). Un préfixe NU
  > `.../events/MPHRTServices/` (tel que noté à tort ici auparavant, et recopié dans le code UC11)
  > **ne matche AUCUNE publication** (typo-level). Côté plugin, `subscribeTopics()` utilise le wildcard
  > `.../events/MPHRTServices/#` (couvre tous les VIN du compte sans dépendre de leur liste).
- **Codes de résultat de l'ack** — ⚠️ lus sous **`return_code` OU `process_code`** selon le type/version
  de message (le code de référence maintenu — intégration HA — fait
  `data.get("return_code") or data.get("process_code")`, `return_code` prioritaire). Sémantique connue :
  `return_code` **0** = succès, **400** = token expiré **ou** service non supporté (selon `reason`
  `[authorization.denied.cvs…no.matching.service.key]`) ; `process_code` (payload
  `{process_date,vin,correlation_id,process_code,process_message}`) **900** = requête acceptée,
  **901** = véhicule en veille, **903** = transmise au véhicule ; autres (**113/300/500**) = échec.
  Côté plugin (`programmerRefreshApresAck`, corrigé audit UC11-16 2026-07-10) : lecture des DEUX champs,
  **901** ⇒ pas de refresh (veille, mapping conservé), sinon refresh REST au prochain cron. Le mapping
  fin code→sens (erreurs remontées à l'utilisateur) est le périmètre d'**UC18**.
- Auth MQTT : `username = "IMA_OAUTH_ACCESS_TOKEN"`, `password = ` **le REMOTE token OTP** (couche 2),
  le token étant **aussi** réinjecté dans le payload. Réponse `return_code='400'` ⇒ token expiré →
  refresh + re-publish.
  > ⚠️ **Correction 2026-07-09 (UC12, vs `RemoteClient.username_pw_set`)** : le mot de passe MQTT est le
  > **remote token OTP**, **PAS** l'access_token OAuth2 REST (ce que le socle UC11 poussait à tort). La
  > bascule a été faite en UC12 : `stellantis::pushDaemonConnect/syncDaemonToken/handleDaemonMessage`
  > utilisent désormais `stellantisApi::getRemoteToken()`. Sans remote token (OTP non activé) → pas de
  > connexion MQTT. Cf. `11-socle-demon-mqtt-tech.md` et `12-tech.md`.
- Modèle **asynchrone** : publish → exécution véhicule → notification `to/cid` (`return_code`). États
  intermédiaires Accepted → Waking-Up → Send → Success/Failure.
- Payloads (classe `RemoteClient`) : wakeup `{"action":"state"}` ; charge `{"program":{hour,minute},"type":…}` ;
  précond `{"asap":…,"programs":[…]}` ; portes `{"action":"lock|unlock"}` ; klaxon
  `{"nb_horn":n,"action":"activate"}` ; feux `{"action":"activate","duration":s}`.
  > ⚠️ **Précision UC13 (2026-07-09, confirmée vs `psa/mqtt_request.MQTTRequest`)** : ces payloads sont les
  > **`req_parameters`**, pas le message publié brut. Le message MQTT réellement publié est **enveloppé** :
  > `{access_token, customer_id, correlation_id, req_date, vin, req_parameters:{…}}`. `access_token` = **remote
  > token OTP réinjecté** ; `req_date` = UTC `%Y-%m-%dT%H:%M:%SZ` ; `correlation_id` = `uuid4().hex` +
  > `%Y%m%d%H%M%S%f`[:-3] (repris tel quel dans l'ack pour corréler). **Topic** = `psa/RemoteServices/from/
  > cid/{CID}` + **segment de service** : le wakeup passe par **`/VehCharge/state`** (pas un « /state »
  > générique). Implémenté côté PHP par `stellantis::buildMqttRequest()` + `publishRemoteCommand()` (point
  > unique de publication, réutilisable UC14-17). Cf. `13-tech.md`.
- **wakeup limité à 6 / 20 min** (niveau **compte**). psa_car_controller envoie un wakeup auto toutes les
  24 h pour garder la session vivante.

### 1.4 Limites & pièges à connaître (valent quelle que soit l'option Jeedom)

- **Pas de push accessible** sans accréditation B2C officielle → **polling REST** obligatoire.
- **Ban API** documenté si wakeup ~toutes les 2 min (issue #1130) → `RateLimitException` persistante qui
  **bloque aussi le refresh du remote token**. Cadence sûre communautaire : **5 min en charge, 60 min en
  veille** ; **jamais** de wakeup à chaque cron.
- **Vidage batterie 12 V réel** (confirmé HA) : wakeups trop fréquents → keyless HS. Guardrail obligatoire.
- **Mode privacy véhicule** (« Plane Mode ») : l'utilisateur peut couper data/géoloc côté voiture →
  API muette, **indépendamment du plugin** → détecter et informer plutôt que retry.
- **TTL access_token ~15 min** → refresh agressif.
- **Seuil de charge** : certaines commandes exigent batterie principale > ~50 %.
- **Instabilité backend** : régressions Stellantis non documentées en 2024-2025 (erreurs 400,
  `NoneType` sur `get_vehicles()`) → logger finement les réponses HTTP, prévoir un **mode dégradé**.
- **FCA hors périmètre** : Jeep/Fiat/Dodge/Ram/Alfa = infra différente (Uconnect/Smartcar), pas `idpcvs`.

---

## 2. Confrontation avec l'architecture Jeedom (décisif)

La lecture (REST) et l'écriture (commandes) n'ont **pas** les mêmes contraintes :

| Besoin | Transport | Faisable en PHP natif ? |
|---|---|---|
| **Télémétrie** (SOC, position, portes, km…) | OAuth2 + REST polling (`cron`) | ✅ **Oui, trivialement** (cURL + JSON, comme IMOU) |
| **Commandes** (wakeup, charge, précond, portes…) | **MQTT** persistant + OTP + reconnexion | ⚠️ **Difficile/fragile** (`php-mqtt/client` existe mais pas de persistance QoS, gestion d'état lourde) |

**Pour les commandes**, deux sous-options :
- **B1 — MQTT en PHP** (`php-mqtt/client`) : garde la philosophie « sans Python », mais un client MQTT
  long-running avec OTP/refresh/reconnexion **n'a pas de processus hôte** dans un plugin PHP (PHP-FPM est
  requête-réponse). Fragile, non éprouvé pour ce cas.
- **B2 — Démon Python MQTT** (`resources/demond`, `paho-mqtt`) : le **standard de facto communautaire**
  (psa_car_controller). Un démon long-running est **exactement** ce que réclament MQTT + OTP + reconnexion.

> 🔑 **Différence clé avec IMOU** : pour IMOU on avait tranché « **sans démon** » car l'API n'avait
> **aucun push** → le démon n'aurait rien apporté. Ici, **MQTT EST un canal persistant** (commandes +
> ack temps réel) : le démon **retrouve sa raison d'être**. C'est pourquoi la décision s'inverse pour
> la partie commandes.

---

## 3. Recommandation : architecture **hybride, PHP d'abord, démon différé**

### ✅ MVP — Lecture seule, 100 % PHP, sans démon (`hasOwnDeamon:false`)

OAuth2 PKCE + REST v4 + polling dans `cron`. C'est l'analogue de la « Solution A » d'IMOU : zéro runtime
externe, tout en PHP/cron. Délivre **l'essentiel de la valeur** (batterie, charge, autonomie, position,
portes, km dans Jeedom) et est **entièrement réalisable en PHP**. Limite assumée et documentée : on lit le
**dernier état remonté** par la voiture, **sans pouvoir forcer** un rafraîchissement (le wakeup = MQTT).

- `core/class/stellantis.class.php` : client `stellantisApi` (OAuth2 PKCE, cache token, refresh),
  `cron()`/`cron5()` → `GET /status` pour rafraîchir les commandes *info*.
- Setup interactif sur la page de config : générer l'URL d'autorisation → l'utilisateur se connecte sur
  le site de sa marque → **colle le `code`** de l'URL de redirection → échange contre tokens.
- `client_id`/`client_secret`/tokens stockés **chiffrés** (`$_encryptConfigKey` + `cache`).

### 🎯 Post-MVP — Commandes via **démon Python MQTT** (`resources/demond` réactivé)

Quand on attaque les commandes (réveil, charge, précond, portes, klaxon, feux), introduire un **démon
Python `paho-mqtt`** (passe `hasOwnDeamon:true`, `packages.json` pip `paho-mqtt`). Le démon gère la
connexion MQTT persistante, le remote token OTP, la reconnexion, et **remonte les ack** ; il dialogue
avec le PHP via le **socket Jeedom** (`jeedom_socket`/`jeedom_com`, déjà présents dans `resources/`).
`stellantisCmd::execute()` (action) → message socket → démon → publish MQTT.

> **La vraie astuce** (comme pour IMOU avec `imouapi`) : **lire le code de `flobz/psa_car_controller`**
> (modules `psacc`/`remote`, classe `RemoteClient`, `app_decoder.py`) comme **implémentation de
> référence** du protocole (endpoints exacts, payloads MQTT, gestion OTP) — puis réimplémenter
> proprement (client PHP pour la lecture, démon Python minimal pour MQTT). Ne pas dépendre du projet à
> l'exécution ; s'en servir comme spec vivante.

### Esquisse `packages.json`
- **MVP** : aucune dépendance (PHP + cURL natif). `hasDependency:false`, `hasOwnDeamon:false`.
- **Post-MVP commandes** : `pip3 paho-mqtt` (+ éventuellement `cryptography` pour l'OTP). `hasOwnDeamon:true`.

---

## 4. Sujets à trancher (laissés explicites pour la suite)

1. **Origine des `client_id`/`client_secret`** : (a) l'utilisateur les saisit (récupérés via un outil
   externe type `app_decoder.py`/`psa-token-helper`) — **recommandé** (léger, pas d'APK dans le plugin) ;
   (b) le plugin embarque l'extraction APK (lourd, `androguard`, juridiquement plus exposé). → **(a)**.
   ⚠️ **Écarté explicitement (2026-07-07)** : figer les 5 jeux de credentials en dur dans le code du
   plugin — plus exposé que (b) (republication permanente de secrets propriétaires dans l'historique
   git du projet, casse silencieuse et collective si Stellantis les fait tourner). **Nuance retenue** :
   un post-MVP **UC61** (`post-mvp/60-configuration-avancee/61-extraction-auto-credentials.md`)
   automatise (b) **sans** en garder les inconvénients « lourd/androguard » — l'extraction des
   ressources *raw* de l'APK (`parameters.json`) est faisable en **PHP pur** (`ZipArchive`, pas besoin
   d'un décodeur de ressources Android complet), et reste une extraction **à la demande, par
   installation**, jamais des valeurs figées dans le dépôt.
2. **Lecture seule vs démon dès le MVP** : on **diffère** le démon (MVP lecture pure). Alternative :
   tout-en-un avec démon dès le départ (plus puissant, mais bloque la livraison d'un MVP simple et fiable).
3. **`php-mqtt/client` (B1) vs démon Python (B2)** pour les commandes : **B2** (robustesse éprouvée).
   B1 resterait envisageable pour rester « sans Python » si le démon pose problème de packaging.
4. **Couverture multi-marques** dès le MVP (paramètre marque) vs Peugeot d'abord : viser **multi-marques
   dès le MVP** (le seul impact est une table TLD/realm), faible coût.

---

## Sources principales
- Stellantis Developer Portal — B2C overview / auth / app-registration : https://developer.groupe-psa.io/webapi/b2c/overview/about/ ; .../quickstart/enroll-users/ ; .../quickstart/app-registration/
- Stellantis Developer Portal — B2B auth & remotes : https://developer.groupe-psa.io/webapi/b2b/quickstart/authentication/ ; https://developer.groupe-psa.io/webapi/b2b/remote/set-up/
- Stellantis Developer Portal — Connected-vehicles (privacy, remotes, changelog) : https://developer.groupe-psa.io/connected-vehicles/about/ ; .../privacy/ ; https://developer.groupe-psa.io/changelog/
- Stellantis Developer Portal — MQTT WebPortal : https://developer.groupe-psa.io/webportal/v1/advanced-features/mqtt/
- psa_car_controller (flobz) — code & docs : https://github.com/flobz/psa_car_controller ; PR #754 (PKCE) ; `psa_client.py` ; `app_decoder.py` ; FAQ.md ; https://deepwiki.com/flobz/psa_car_controller/5.1-mqtt-remote-client
- Dépôt APK par marque : https://github.com/flobz/psa_apk
- Rate-limit / ban / batterie 12V : psa_car_controller issues #859, #967, #1130 ; HA forum https://community.home-assistant.io/t/stellantis-vehicles-peugeot-citroen-ds-opel-vauxhall-integration-homeassistant/838675
- Intégration HA native : https://github.com/andreadegiovine/homeassistant-stellantis-vehicles
- Plugin Jeedom existant : https://github.com/lelas33/plugin_peugeotcars ; https://community.jeedom.com/t/connecter-psa-car-controller-a-jeedom/101751
- Accès B2C inaccessible (sans réponse) : https://github.com/Stellantis/stellantis.github.io/issues/128
- Binding openHAB (polling 5 min) : https://www.openhab.org/addons/bindings/groupepsa/
- `php-mqtt/client` : https://github.com/php-mqtt/client
