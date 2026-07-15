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
> **Dernière synchro** : 2026-07-15 (UC52 : `stellantis-data-model.md` § 1 — **image/vignette du modèle** :
> le champ `pictures` de `/user/vehicles` (`list[Url]`, `Url` = stub Swagger vide, jamais lu par les réfs)
> a une **shape runtime NON vérifiée** (3ᵉ instance de la leçon `/maintenance`+`/alerts`) → photo modèle
> en **best-effort/parsing défensif + log debug** pour observer la vraie forme, repli icône de marque
> bundlée. **Mécanisme d'image eqLogic** (contrat core, réutilisable) : **PAS de `setImage()`** →
> `getImage()`/`getCustomImage()` lit `image::sha512`/`image::type` et sert
> `data/eqLogic/eqLogic{ID}-{sha512}.{type}`, sinon icône plugin ; poser = écrire fichier +
> `setConfiguration` + `save`, écrire-puis-purger (glob ancré `-`), nettoyer en `preRemove` ; servi
> same-origin (pas de CSP) — détail dans `50-gestion-vehicules/52-tech.md`).
> Précédemment 2026-07-14 (UC44 : `stellantis-data-model.md` § 2.4 — **ouvrants détaillés** :
> `doors_state.opening[n].{identifier,state}` (enum connu 7 valeurs) → 8 commandes `door_<id>` (binary
> `OPENING`, non historisées) + agrégat `opening_alert` (historisé). **Approche STATIQUE** (≠ UC43
> dynamique) car enum petit/connu/labellisé ; helper pur `extraireOuvrants` (miroir `extraireVerrouillage`,
> 1 ligne dans `parseStatus`, aucun appel réseau neuf) ; invariant map `OPENING_IDENTIFIERS`↔
> `definitionsCommandes` (jamais de logicalId dynamique, étanche au throw `ensureCommand`) ; fail-closed sur
> `state` ; « capot » NON dans l'enum (déclaré par anticipation) — cf. `44-tech.md`).
> Précédemment 2026-07-14 (UC43 : `stellantis-data-model.md` § 3 — **alertes véhicule
> (catalogue générique)** : UC43 **étend** le poller `/alerts` d'UC42 (jamais dupliqué) — **1 binaire par
> type rencontré** en **création DYNAMIQUE hors table statique** `definitionsCommandes()` (`alert_<slug>`,
> nom = libellé brut sécurisé donnée runtime jamais `__()` ; remise à 0 par énumération
> `cmd::byEqLogicId(id,'info')`, commandes persistantes) + agrégat **`alerts_count`** (numeric historisée,
> scénario « ≥ 1 alerte »). ⚠️ Binaires par type **non historisées** (`isHistorized` = table d'historique,
> **pas** trigger de scénario) ; plafond `ALERT_MAX_TYPES=100` anti-prolifération — cf. `43-tech.md`).
> Précédemment 2026-07-13 (UC42 : `stellantis-data-model.md` § 3 — **pression pneus / alertes
> TPMS** : la pression NUMÉRIQUE est absente de l'API consommateur → seules des **alertes booléennes** via
> `GET /alerts` (modèles `Alert`/`AlertsEmbedded`/`AlertMsgEnum`, wrapper `_embedded.alerts`) ; contrat
> confirmé vs doc B2C + `psa_car_controller` mais **endpoint non lu par les réfs** (comme `/maintenance`)
> → best-effort/lazy/throttle **1 h**, sémantique **fail-closed** (entrée sans `active` ⇒ ignorée, pas de
> faux positif) ; nuance : `AlertsEmbedded` **existe** (≠ `maintenance_embedded`) ⇒ wrapper plus certain ;
> **pas de per-roue** ; lecteur `/alerts` autonome qu'**UC43 doit étendre** — cf. `42-tech.md`).
> Précédemment 2026-07-12 (UC41 : `stellantis-data-model.md` § 3 — **échéance d'entretien** :
> endpoint dédié `/maintenance` (champs `mileageBeforeMaintenance` + **`daysBeforeMaintenace`, faute de
> frappe réelle de l'API** — un seul `n` ; lire aussi la forme correcte en repli) ; **wrapper runtime
> incertain** (parser multi-shape `_embedded.maintenance` puis racine) ; **disponibilité NON garantie** —
> ni `psa_car_controller` ni l'intégration HA ne le lisent réellement → best-effort/création paresseuse/
> throttle différencié (24 h nominal, 7 j sur 404, 3 h transitoire) ; **leçon transverse** : un endpoint
> présent dans les modèles Swagger de référence n'est pas une preuve qu'il est exploitable — cf.
> `41-tech.md`). Précédemment 2026-07-12 (UC34 : `stellantis-data-model.md` § 2.2 — **posture de confidentialité
> des données de localisation** : (1) clé de config contenant des coordonnées (adresse domicile) → **chiffrer
> au repos** via `$_encryptConfigKey` (le core chiffre auto les clés de config **plugin** listées, précédent
> `client_secret` étendu à `home_lat`/`home_lon`) ; (2) **ne pas historiser** une distance dérivée d'un point
> fixe (`home_distance`) — couplée à la position exposée par UC31, elle permet de **trilatérer** l'adresse ;
> `at_home` binaire reste historisable — cf. `34-tech.md`). Précédemment 2026-07-12 (UC33 : `stellantis-data-model.md` § 2.3 — **détection de trajet** :
> `kinetic.moving` est **instantané** (à l'instant du poll → fragmente les trajets à la cadence 5 min sur
> un simple arrêt), `ignition.type` **persiste** pendant tout le trajet → prédicat « en trajet » robuste
> `moving==1 OU ignition ∈ {Start,StartUp}` ; distance = delta `odometer.mileage` ; durée à ±cadence ;
> reconstruction **100 % locale** (aucun endpoint trips accessible), machine à états calquée sur UC24 —
> cf. `33-tech.md`). Précédemment 2026-07-12 (UC32 : `jeedom-panel-page-menu.md` § 4 — **image externe dans un
> panel** : la page panel étant rendue **serveur**, embarquer la tuile carte en **`data:` URI inline**
> (CSP `data:` OK) évite le proxy ; le **proxy same-origin** ne reste requis que pour un **widget
> dashboard** (HTML client) ; même méthode PHP mutualisée `renderStaticMap()`, cache fichier + coords
> arrondies obligatoires — cf. `32-tech.md`). Précédemment 2026-07-11 (UC24 : `stellantis-data-model.md` § 2.1 — **suivi de sessions de
> charge** : les états terminaux de `charging.status` **persistent** poll après poll (`Disconnected` au
> repos, `Finished` non débranché) → une machine à états doit agir sur la **transition** `InProgress →
> terminal` (dernier statut en cache), jamais à chaque poll (sinon spam ~288×/j) ; **pas** de valeur
> `Started` ; énergie = Δ SOC% × capacité (`battery_capacity` config autoritaire, repli
> `extension.electric.battery.load.capacity`) — cf. `24-tech.md`). Précédemment 2026-07-11 (UC23 :
> `stellantis-data-model.md` § 2.1 — **carburant & hybrides** :
> autonomie **scindée par énergie** (`autonomy` élec / `autonomy_fuel` carburant, clés distinctes) ; **aucune
> autonomie combinée native** → `autonomy_total` = valeur **dérivée** (somme, création paresseuse, hybride
> uniquement), seule exception au pattern « 1 champ → 1 commande » ; migration masquant l'ancien `autonomy`
> figé des thermiques déjà découverts — cf. `23-tech.md`). Précédemment 2026-07-11 (UC22 :
> `stellantis-data-model.md` § 2.1 — **programmation de charge** :
> reprogrammer l'heure différée = `RemoteClient.change_charge_hour` → **même** payload que `charge_stop`
> (`/VehCharge` `{"program":{hour,minute},"type":"delayed"}`, seule l'heure change) ; **AUCUN seuil %/SoC
> cible dans le contrat MQTT consommateur** (le `?percentage=` local PSACC ≠ commande véhicule) → un UC
> « seuil de charge » n'est pas réalisable via MQTT ; `type:"delayed"` interrompt une charge immédiate en
> cours). Puis 2026-07-11 (UC21 : `stellantis-data-model.md` § 2.1 — `remaining_time` = **durée**
> (→ minutes via `dureeIsoEnMinutes`, **sans clamp**, peut dépasser 24 h) ≠ `next_delayed_time` = **heure
> d'horloge** (`parseHeureIso`, clampé, derrière garde de format `/^\s*PT\d/`) ; `battery.voltage` racine
> = 12 V de servitude **universel** (`battery_12v` sans garde motorisation), DISTINCT de `energy[].battery.*`
> traction/SOH). Précédemment 2026-07-10 (UC18 : `stellantis-api-architecture.md` § 1.3 — retour d'état async
> confirmé vs `psa/RemoteClient.py` master : psa_car_controller ne lit QUE `return_code` sur `to/cid` et
> **ne stocke aucun `correlation_id`** → celui-ci n'est **pas fiable** sur les acks `return_code`, d'où la
> corrélation **`correlation_id` puis repli `vin`** ; `events/MPHRTServices` = états poussés
> `charging_state`/`precond_state`, **pas** des résultats de commande ; 900/903 **intermédiaires** ;
> **décision : pas de re-publish auto sur 400**, on signale — cf. `18-tech.md`).
> Précédemment 2026-07-10 (UC17 : `stellantis-api-architecture.md` § 1.3 — segments de service
> MQTT klaxon **`/Horn`** `{"nb_horn":count,"action":"activate"}` et feux **`/Lights`**
> `{"action":"activate","duration":s}` confirmés vs `psa_car_controller` master ; contrat #1199 en réalité
> **INCHANGÉ** ; commandes **« sans état »** → pas de corrélation ack→véhicule côté UC17, renvoyée à UC18).
> Précédemment 2026-07-09 (UC16 : `stellantis-data-model.md` § 2.4 — commande MQTT
> verrouillage confirmée contre `psa_car_controller` : service `/Doors`, payload
> `{"action":"lock"|"unlock"}`, aucun `failure_cause` dédié ; `jeedom-widgets-commandes.md` § 4 —
> **activation serveur de la confirmation d'action** `setConfiguration('actionConfirm',1)` → garde core
> `-32006` (dialog natif), anti-fausse-manip UI et **non** une frontière d'autorisation). Précédemment
> 2026-07-09 (UC15 : `stellantis-data-model.md` § 2.5 — commande MQTT
> préconditionnement confirmée contre `psa_car_controller` : service `/ThermalPrecond`, payload
> `{"asap":"activate"|"deactivate","programs":<4 créneaux>}` ; le code de réf. n'envoie les programmes
> réels que s'il les a appris par events MQTT, sinon littéral figé `on:0` — comportement repris à
> l'identique côté plugin, le suivi des events étant hors scope UC15). Précédemment 2026-07-09
> (UC14 : `stellantis-data-model.md` — `next_delayed_time` **format
> ambigu** RFC3339 vs durée `PT..` confirmé contre `psa_car_controller` ; commande de charge MQTT service
> `/VehCharge`, payload `{"program":{hour,minute},"type":"immediate"|"delayed"}`, seul `type` opérant pour
> start/stop). Précédemment 2026-07-09 (UC13 : `stellantis-api-architecture.md` §1.3 — enveloppe MQTTRequest
> réelle du message publié `{access_token,customer_id,correlation_id,req_date,vin,req_parameters}`, service
> wakeup `/VehCharge/state`, formats de date PSA ; point unique de publication PHP `buildMqttRequest`/
> `publishRemoteCommand`). Précédemment 2026-07-09 (UC12 : corrections de contrat dans
> `stellantis-api-architecture.md` §§ 1.1/1.3 et `stellantis-psacc-vs-natif.md` §6 — endpoint remote token
> = `applications/cvs/v4/mobile/token` (pas `virtualkey/...`) ; mot de passe MQTT = **remote token OTP**,
> pas l'access_token OAuth2 ; crypto OTP vendorisée depuis psa_car_controller).
> Création initiale : 2026-06-25 (transposition depuis un plugin caméra vers le domaine véhicule
> connecté Stellantis ; recherche web de fondation consignée dans `stellantis-api-architecture.md`).

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
| **`charging.status`** : valeurs, états terminaux **persistants** (≠ momentanés), machine à états de **session de charge** (transition, pas par-poll), énergie = Δ SOC × capacité | `stellantis-data-model.md` § 2.1 |
| **Détection de trajet** : `kinetic.moving` instantané (fragmente à 5 min) vs `ignition.type` persistant → prédicat `moving OU ignition∈{Start,StartUp}` ; distance = delta odomètre ; reconstruction locale (pas d'endpoint trips) | `stellantis-data-model.md` § 2.3 |
| **Échéance d'entretien / kilométrage** : endpoint `/maintenance`, champs `mileageBeforeMaintenance` / `daysBeforeMaintenace` (**typo réelle de l'API**), disponibilité NON garantie (best-effort/lazy/throttle) ; un endpoint du client Swagger de réf. n'est pas forcément exploitable | `stellantis-data-model.md` § 3 |
| **Pression pneus / alertes véhicule** : endpoint `/alerts` (`_embedded.alerts`, `AlertMsgEnum`), pression NUMÉRIQUE absente (que des booléens), best-effort/lazy/throttle 1 h, **fail-closed** (`active` absente ⇒ ignorée), pas de per-roue ; **UC43 livré** : 1 binaire par type (création dynamique hors table statique) + `alerts_count` agrégé | `stellantis-data-model.md` § 3 |
| **Confidentialité localisation** : clé config avec coordonnées → chiffrer (`$_encryptConfigKey`, marche pour la config plugin) ; ne PAS historiser une distance-à-un-point-fixe (trilatération de l'adresse) ; `at_home` binaire OK à historiser | `stellantis-data-model.md` § 2.2 |
| Création conditionnelle de commandes selon **motorisation** (élec/hybride/thermique) | `stellantis-data-model.md` § 3 |
| **Où trouver le contrat exact** (endpoint/payload non documenté) : code de référence à lire | `stellantis-implementations-reference.md` |
| Plugin Jeedom PSA **existant** (PHP) et intégrations HA/openHAB à miner | `stellantis-implementations-reference.md` |
| **Dépendre de psa_car_controller** à l'exécution (service/pip/lib) plutôt que d'implémenter ? | `stellantis-psacc-vs-natif.md` (verdict : non — natif confirmé ; repli « connecteur » § 4.3) |
| **API REST locale de PSACC** (17 endpoints, sans auth), déploiement, deps, licence GPL-3 | `stellantis-psacc-vs-natif.md` § 1 |
| **OAuth PSA durci** (2025-2026), TTL remote token ~890 s, endpoint `virtualkey/remoteaccess/token` | `stellantis-psacc-vs-natif.md` §§ 1 et 6 |
| **Widget de commande** Jeedom (fichier `cmd.<type>.<subType>.<nom>.html`, `setTemplate`, tokens `#id#`…) | `jeedom-widgets-commandes.md` §§ 1-2 |
| Widget pilotant **plusieurs commandes** (carte + actions) ; résoudre les sœurs par `byEqLogic` | `jeedom-widgets-commandes.md` § 3 |
| Exécuter une action depuis un widget + récupérer le retour PHP ; auth/CSRF AJAX ; AJAX plugin admin-only | `jeedom-widgets-commandes.md` §§ 4-5 |
| **Confirmation avant une action sensible** (dialog anti-fausse-manip) : comment l'activer côté serveur | `jeedom-widgets-commandes.md` § 4 (`actionConfirm=1` → -32006) |
| **Commande action PARAMÉTRÉE** (saisie utilisateur : subType `message`, valeur dans `$_options['message']`) | `jeedom-widgets-commandes.md` § 4 (UC22) |
| **CSP Jeedom bloque tout média/image EXTERNE** → proxy same-origin (ex. tuile carte) | `jeedom-widgets-commandes.md` § 7 |
| Ajouter une **PAGE** au menu Jeedom (panel) ; toggle natif `displayDesktopPanel/Mobile` ; page non-admin | `jeedom-panel-page-menu.md` |
| **Afficher une image externe dans un panel** (carte…) : `data:` URI inline (panel serveur) vs proxy (widget client) | `jeedom-panel-page-menu.md` § 4 |

> Si aucun fichier ne couvre le sujet : ce n'est pas (encore) analysé en interne → passer à la doc
> externe (`.memory/external/doc/stellantis|jeedom/INDEX.md`), et penser à capitaliser en Étape 12.

---

## 1. Catalogue des analyses

| Fichier | Sujet | Points clés indexés |
|---|---|---|
| `stellantis-api-architecture.md` | **Décision d'architecture** : voie d'accès (API officielle inaccessible → consommateur reverse-engineered) + archi Jeedom (hybride PHP/démon). | DEUX API (officielle B2B/B2C mTLS sur dossier vs consommateur APK) ; OAuth2 **PKCE** par marque (idpcvs.{marque}, realms `clientsB2C…`, TTL ~15 min) ; **2 tokens** (OAuth2 REST + remote OTP MQTT) ; commandes **MQTT** `mwa.mpsa.com:8885` (topics/payloads/ack) ; **MVP lecture 100 % PHP sans démon**, **commandes post-MVP via démon Python `paho-mqtt`** (le push MQTT redonne sa raison d'être au démon, ≠ IMOU) ; limites (ban wakeup ~2 min, batterie 12 V, privacy, quotas OTP 6/24 h, seuil charge ~50 %) ; sujets à trancher. |
| `stellantis-data-model.md` | **Modèle de données** télémétrie (`GET /status`, `connected_car v4`) → quelles commandes info Jeedom. | 1 véhicule = 1 eqLogic (clé **VIN**) ; `energies[]` (SOC/level, autonomy, charging.status/plugged/rate/remaining), position GeoJSON, `kinetic.moving`, `odometer.mileage`, ouvrants/verrouillage, préconditionnement, environnement/pneus/alertes/privacy/lastUpdate ; **création conditionnelle selon motorisation** ; noms de champs **à reconfirmer** contre une réponse réelle. |
| `stellantis-implementations-reference.md` | **Où chercher le contrat exact** (pas de doc officielle du protocole consommateur). | `flobz/psa_car_controller` (psa_client.py=OAuth, app_decoder.py=APK, RemoteClient=MQTT, models=data) ; `homeassistant-stellantis-vehicles` (mapping capteurs, cadences) ; `lelas33/plugin_peugeotcars` (API PSA **en PHP**, écueils) ; règle « code de référence = source de vérité du contrat ». |
| `stellantis-psacc-vs-natif.md` | **Dépendance d'exécution à psa_car_controller (PSACC) vs implémentation native** — comparaison et verdict (2026-07-06). | Fiche PSACC à jour (Flask/Dash port 5000, API locale 17 endpoints GET **sans auth**, SQLite, pip/Docker/add-on HA, deps lourdes Python ≥3.11, GPL-3, v3.7.5 activement maintenu) ; 3 variantes de dépendance (B1 service externe, B2 packagé pip, B3 lib dans notre démon) comparées sur 9 axes ; précédent `plugin_peugeotcars` (pont PSACC, abandonné) ; **verdict : natif confirmé**, PSACC = spec vivante + canari, repli « connecteur B1 optionnel » à réévaluer à l'UC11 ; faits neufs → specs : OAuth durci (#779, notre design MVP/03 = la procédure de secours PSACC), remote token TTL ~890 s + `virtualkey/remoteaccess/token` (UC12), pin `paho-mqtt <2.0` confirmé (UC11/82). |
| `jeedom-widgets-commandes.md` | Widgets de commande Jeedom (templates dashboard/mobile), vérifié contre la source du core. | `cmd.<type>.<subType>.<nom>.html` + `setTemplate('stellantis::<nom>')` ; tokens (`#id#`/`#logicalId#`/`#eqLogic_id#`/`#uid#`…) ; `#cmd_id[…]#` & `jeedom.cmd.byEqLogicId` **n'existent pas** → résoudre par AJAX **`byEqLogic`** ; **masqué ≠ non-exécutable** ; `jeedom.cmd.execute` (CSRF/droits, `success.result`=retour PHP) ; AJAX plugin admin-only inutilisable au dashboard ; **§ 7 CSP : média/image externe bloqué → proxy same-origin** (ex. tuile carte véhicule). |
| `jeedom-panel-page-menu.md` | Page de plugin au **menu** Jeedom (panel) & toggle d'affichage natif (ex. carte « Mes véhicules »). | `info.json "display"`/`"mobile"` enregistre une page-panneau ; le core ajoute nativement les cases « Afficher le panneau desktop/mobile » (`displayDesktopPanel`/`displayMobilePanel`, masqué par défaut) → aucun toggle custom ; `plugin::getDisplay()` statique ; page panel = `isConnect()` non-admin + accès par eqLogic `hasRight('r')` + sélection `isVisiblePanel` ; **image externe : `data:` URI inline en panel serveur vs proxy same-origin en widget client (UC32)** ; réf. `jeedom/plugin-gsl`. |
