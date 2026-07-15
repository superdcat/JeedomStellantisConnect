# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Présentation

Plugin **Jeedom** (id cible `stellantis`) qui connecte les **véhicules Stellantis / ex-Groupe PSA**
(**Peugeot, Citroën, DS, Opel, Vauxhall**) à Jeedom : remonter la **télémétrie** (batterie/SOC, charge,
autonomie, carburant, position GPS, kilométrage, état portes/verrouillage, pression pneus,
préconditionnement) et, à terme, **piloter à distance** (réveil, charge, préconditionnement,
verrouillage, klaxon, feux).

**Décision d'architecture structurante** (détail : `.memory/analyse/stellantis-api-architecture.md`) :
- Il existe **deux API** ; l'**officielle** (`developer.groupe-psa.io`, propre) est **inaccessible** à un
  particulier → on vise l'**API consommateur** reverse-engineered (celle des apps MyPeugeot/MyCitroën…),
  comme `psa_car_controller`. **Auth = OAuth2 Authorization Code + PKCE** par marque, **pas** un simple
  appId/secret. Il y a **deux systèmes de tokens** distincts : OAuth2 (REST, lecture) et un *remote token*
  OTP/SMS (MQTT, commandes).
- **Architecture hybride, PHP d'abord, démon différé** :
  - **MVP = lecture seule, 100 % PHP, sans démon** (`hasOwnDeamon:false`). OAuth2 + REST v4 +
    **polling** dans le cron Jeedom (l'API n'a **pas de push** accessible). C'est l'essentiel de la valeur.
  - **Commandes à distance = post-MVP, via démon Python MQTT** (`resources/demond`, `paho-mqtt`) : le
    canal MQTT persistant + OTP justifie un démon (contrairement à une API purement REST). Le **socle
    démon est en place depuis UC11** (`resources/demond` réactivé, `hasOwnDeamon:true`) et l'**OTP/remote
    token est fait (UC12)** ; les commandes métier suivent (UC13-18).

Un plugin Jeedom **n'est pas autonome** : il s'installe sous `<jeedom>/plugins/stellantis/`, et tout le
PHP dépend du core Jeedom, atteint via `require_once __DIR__ . '/../../../../core/php/core.inc.php';`.
Pas de build local ; la validation se fait en CI (voir « Workflows / CI »).

> **État d'avancement (2026-07-15)** : l'id a été renommé `template` → `stellantis` (classes
> `stellantis`/`stellantisCmd`, `info.json` id `stellantis`). **MVP lecture seule COMPLET** : UC01 à
> UC10 sont implémentées (configuration du plugin, client HTTP REST, authentification OAuth2 PKCE/token,
> test de connexion, découverte des véhicules, création/synchronisation des équipements, commandes info
> de télémétrie, rafraîchissement périodique — hook `stellantis::cron()` chaque minute, cadence par
> défaut 5 min + `autorefresh` par véhicule, sans wakeup ; état de connexion & fraîcheur —
> `stellantis::connectionState()`, page Santé `stellantis::health()`, bandeau page plugin, indicateur
> privacy par véhicule ; robustesse — rejeu token borné, backoff/cooldown anti-ban sur HTTP 429, mode
> dégradé throttlé sur auth cassée, taxonomie d'erreurs `stellantisException`). **Post-MVP : UC61**
> (extraction auto des `client_id`/`client_secret` depuis l'APK de la marque, bouton sur la page de
> config — `stellantis::extractCredentialsFromApk()`, `stellantisApi::downloadToFile()` pour le
> téléchargement ; **décompression bz2 + lecture zip déléguées au script Python
> `resources/extract_credentials.py`** — stdlib `bz2`/`zipfile`, donc **aucune extension PHP** ni
> redémarrage d'Apache). **Post-MVP : UC11** — socle du démon Python MQTT
> (`hasOwnDeamon:true`, `hasDependency:true`, `paho-mqtt==1.6.1`) : transport MQTT générique
> (`resources/demond/demond.py`), pont PHP↔démon (`stellantis::sendToDaemon()`, hooks
> `deamon_info/start/stop`), callback démon→Jeedom (`core/php/jeeStellantis.php` +
> `stellantis::handleDaemonMessage()`), propagation du token au démon (`syncDaemonToken()`).
> **Post-MVP : UC83** — icône du plugin (`plugin_info/stellantis_icon.png`, PNG 309×348 « véhicule
> connecté » générique remplaçant le placeholder du template ; script de génération Pillow versionné
> dans la spec technique). **Post-MVP : UC12** — activation OTP & **remote token** (mot de passe MQTT,
> distinct du token OAuth2 REST) : REST `applications/cvs/v4/mobile/{smsCode,token}` via `stellantisApi`
> (`requestSmsOtp`/`requestRemoteToken`/`refreshRemoteToken`/`getRemoteToken`, cache chiffré séparé),
> crypto OTP (device inWebo contre `otp.mpsa.com`, RSA/AES/SHA256) **vendorisée** de `psa_car_controller`
> (GPL-3) dans `resources/otp_vendor/`, pilotée par le **helper one-shot `resources/otp_helper.py`**
> (protocole stdin↔stdout JSON, jamais argv) via `stellantis::runOtpHelper()` (`proc_open`) ;
> orchestration `stellantis::requestOtpSms/activateOtp/renewRemoteToken` + UI 2 étapes (SMS puis
> code+PIN) sur la page de config ; garde-fous des quotas durs (6 codes/24 h en cache, 20 SMS/compte à
> vie en config) sans retry, alerte `otp_required` throttlée (page Santé + `message::add`) ;
> résolution best-effort du `customer_id` (CID) via `GET /user`. **UC12 a basculé le mot de passe MQTT
> du démon** vers le remote token (le socle UC11 poussait à tort l'access_token OAuth2 — corrigé dans
> `pushDaemonConnect/syncDaemonToken/handleDaemonMessage`). **Post-MVP : UC13** — **première commande
> MQTT : wakeup / rafraîchissement à la demande** : commande action `wakeup` (créée par `createCommands`
> via `definitionsActions`/`ensureActionCommand`), `stellantisCmd::execute()` → `stellantis->wakeup()`.
> Publication via le **point unique réutilisable** `publishRemoteCommand()`/`buildMqttRequest()` :
> message MQTTRequest complet `{access_token(remote token),customer_id,correlation_id,req_date,vin,
> req_parameters}` publié sur `psa/RemoteServices/from/cid/{CID}/VehCharge/state` (payload `{"action":
> "state"}`). **Garde-fous stricts anti-ban / batterie 12 V** : cooldown per-véhicule (5 min) + **quota
> global compte** (`consommerQuotaWakeup`, 5/20 min, marge sous le ban serveur ~6/20 min) ; **jamais** de
> wakeup au cron. MAJ des infos après l'ack : `handleDaemonMessage` (cas `message`) pose un flag
> `wakeup_pending` (corrélation via `correlation_id`, jamais d'appel REST dans le callback) consommé au
> **prochain `cron()`** (refresh forcé, garde-fous 429 réutilisés). `sendToDaemon()` renvoie désormais un
> bool (échec d'envoi → `stellantisException` transport, pas de cooldown fantôme). **Post-MVP : UC14** —
> **commande de charge (démarrer / arrêter)** : commandes action `charge_start`/`charge_stop` créées
> **uniquement sur véhicule rechargeable** (`Electric`/`Hybrid`, via `createCommands`), `execute()` →
> `stellantis->chargeControl(bool)`. Publiées via `publishRemoteCommand()` sur le service `/VehCharge`
> (payload `{"program":{"hour","minute"},"type":"immediate"|"delayed"}` ; start=`immediate`,
> stop=`delayed`) — contrat `RemoteClient.charge_now` de `psa_car_controller`. ⚠️ « Arrêter » (delayed)
> **rafraîchit d'abord le `/status` REST** (best-effort) pour ne pas reprogrammer la charge différée avec
> une heure périmée (`next_delayed_time` peut avoir changé depuis l'app mobile ; **format ambigu RFC3339 /
> durée `PT..`** → `parseHeureIso` tolérant ; cf. `stellantis-data-model.md`). Garde-fous : garde
> motorisation serveur + **debounce per-véhicule court (10 s) posé AVANT tout appel réseau** (borne les
> tentatives en échec, anti-ban) + quota global compte réutilisé de `publishRemoteCommand`. Le **pipeline
> d'ack UC13 est généralisé** (constantes renommées `WAKEUP_CORR/PENDING_KEY` → `CMD_CORR/PENDING_KEY`
> + `CMD_CORR_TTL` ; refresh d'état au prochain `cron()` après l'ack). **Post-MVP : UC15** — **commande de
> préconditionnement climatique (activer/désactiver, immédiat)** : commandes action `precond_on`/
> `precond_off` créées **universellement** (tout véhicule, y compris thermique — chauffage habitacle),
> `execute()` → `stellantis->precondControl(bool)`. Publiées via `publishRemoteCommand()` sur le service
> `/ThermalPrecond` (payload `{"asap":"activate"|"deactivate","programs":<4 créneaux>}`) — contrat
> `RemoteClient.preconditioning` de `psa_car_controller`. Les `programs` envoyés sont **toujours** le
> littéral figé par défaut (`precondProgramDefaut()`, 4 créneaux `on:0`) : le suivi des programmes réels
> (events MQTT) est hors scope UC15, et ce littéral reproduit le **même repli par défaut** que le code de
> référence applique tant qu'il n'a rien appris — cf. `stellantis-data-model.md` § 2.5 pour le détail du
> risque documenté. Nouvelle info `precond_status` (mapping `preconditionning.airConditioning.status`,
> **double n**) ; un refus véhicule (`Failure`) est loggué avec `failure_cause` (validation stricte
> batterie/branchement = UC18). Garde-fous : **aucune** garde motorisation (universel) ni batterie
> proactive côté plugin ; debounce per-véhicule court (10 s, réutilise le pipeline d'ack générique
> UC13/14 sans modification) + quota global compte réutilisé de `publishRemoteCommand`. **Post-MVP : UC16**
> — **commande de verrouillage / déverrouillage des portes** : commandes action `lock`/`unlock` créées
> **universellement** (les portes existent sur tout véhicule), `execute()` → `stellantis->doorControl(bool)`.
> Publiées via `publishRemoteCommand()` sur le service `/Doors` (payload `{"action":"lock"|"unlock"}`) —
> contrat `RemoteClient.lock_door` de `psa_car_controller`. Le **déverrouillage** porte une **confirmation
> native du core** (anti-fausse-manip) : `definitionsActions()` gagne un **4e élément optionnel**
> `confirmRequis` (tuple `[nom, subType, genericType, confirmRequis?]`, rétro-compatible via `isset`/`empty`),
> et `ensureActionCommand()` pose `setConfiguration('actionConfirm', 1)` à la création → le core lève
> `-32006` avant `execute()` et affiche un dialog natif (aucun code JS/HTML custom ; chaîne « Cette action
> nécessite une confirmation » traduite par le core). ⚠️ `actionConfirm` est un garde-fou **UI**, **pas**
> une frontière d'autorisation (scénario/apikey le contournent) — cf. `jeedom-widgets-commandes.md` § 4.
> L'info `doors_locked` (MVP07) reflète l'état après l'ack ; l'API `/Doors` n'expose **aucun `failure_cause`**
> dédié (indisponibilité thermique/équipement ⇒ `doors_locked` inchangé, retour d'état fin renvoyé à UC18).
> Garde-fous : debounce per-véhicule court (10 s) + quota global compte réutilisé de `publishRemoteCommand`.
> **Post-MVP : UC17** — **commandes klaxon & feux (retrouver le véhicule)** : commandes action `horn`/`lights`
> créées **universellement** (tout véhicule a klaxon + feux), `execute()` → `stellantis->horn()` /
> `stellantis->lights()`. Publiées via `publishRemoteCommand()` sur les services `/Horn` (payload
> `{"nb_horn":count,"action":"activate"}`) et `/Lights` (payload `{"action":"activate","duration":s}`) —
> contrats `RemoteClient.horn`/`lights` de `psa_car_controller`, confirmés **inchangés** en master (le
> contrat « évolutif » signalé par l'issue #1199 dans la spec 17 ne l'était pas). Paramètres nb-coups/durée =
> **constantes à défaut raisonnable** (`HORN_COUNT=2`, `LIGHTS_DURATION=10 s` ; configurabilité par commande
> différée). Les deux méthodes délèguent à un **helper privé partagé** `declencherSignal()` (debounce → OTP
> → pose debounce avant réseau → publish). ⚠️ **Différence assumée vs UC13-16** : commandes **« sans état »**
> (aucune télémétrie klaxon/feux à relire) → UC17 ne pose **PAS** de mapping `CMD_CORR_KEY` (le seul
> consommateur actuel — refresh REST au prochain cron — serait inutile et gaspillerait un slot de quota
> anti-ban) ; la corrélation ack→véhicule pour un retour d'état relève d'**UC18**. Garde-fous : debounce
> per-véhicule court (10 s, clés séparées klaxon/feux) + quota global compte réutilisé de
> `publishRemoteCommand`. **Post-MVP : UC18** — **retour d'état asynchrone des commandes** (transverse
> UC13-17) : le callback démon interprète les acks MQTT du topic `.../to/cid/...` et expose une info
> universelle **`last_command_result`** par véhicule. Le pipeline UC13 (`programmerRefreshApresAck`) est
> **remplacé** par `stellantis::traiterRetourCommande()` + le helper pur `interpreterAck()` : parse défensif
> du code (`return_code` prioritaire sinon `process_code` — `0`=succès, `400`=token, `900`/`903`
> intermédiaires, `901`=veille, autre=échec), **corrélation ack→véhicule par `correlation_id` puis repli
> `vin`** (le `correlation_id` n'est **pas fiable** sur les acks `return_code` — confirmé vs
> `psa_car_controller` master, cf. analyse § 1.3), MAJ de `last_command_result` sur **chaque** message (le
> terminal, résolu par `vin`, écrase l'état intermédiaire « Acceptée »), **échec jamais silencieux**
> (`message::add`, `removeAll` avant `add`), refresh REST au prochain cron **borné aux commandes corrélées
> stateful** (jamais sur le repli vin — klaxon/feux, events poussés). Le topic `events/MPHRTServices/#`
> (états poussés `charging_state`/`precond_state`) est **filtré** (préfixe topic) — hors périmètre. Sur
> code `400`, **pas de re-publish auto** (décision : token rafraîchi chaque minute → 400 rare) : on signale
> (« session renouvelée, réessayez »). Sécurité : texte externe (`reason`/`process_message`/codes) aseptisé
> (helper `aseptiser()`) **puis** `htmlspecialchars` avant tout usage UI/log ; filtre de topic sur le topic
> **brut**. Aucun appel réseau dans le callback (répond 200 vite) ; démon (`demond.py`) **inchangé** à UC18.
> **Post-MVP : UC19** — **résilience de la connexion du démon MQTT** (backoff + arrêt sur échec d'auth) :
> l'auto-reconnexion **native de paho est abandonnée** au profit d'une **machine à états mono-thread**
> (`resources/demond/demond.py`, classe `MqttBridge`) pilotée depuis `listen()` — `connect()` synchrone +
> **pompage manuel `client.loop(timeout=0)`** à chaque tick (aucun thread, aucun verrou). États
> `IDLE/CONNECTING/CONNECTED/BACKOFF/BLOCKED` : **backoff exponentiel** plafonné (base 5 s, ×2, plafond
> 300 s) + jitter ±20 %, plancher dur ≥5 s ; **compteur d'échecs d'auth** → après **N=5** consécutifs,
> passage en **BLOCKED** (arrêt des tentatives, **process vivant**, socket à l'écoute pour réarmement) ;
> distinction **transitoire vs auth** via `decider_categorie()` (rc=3 transitoire ; rc 1/2/4/5 ou `rc=7`
> sans CONNACK = auth) ; **réarmement** sur `connect`/`set_token` (reset compteur+backoff, y compris depuis
> BLOCKED — le `syncDaemonToken` du cron pousse un `set_token` ~toutes les 15 min, réarmant implicitement).
> ⚠️ **Quirks de `paho-mqtt==1.6.1` neutralisés** (constatés empiriquement, cf. `19-tech.md` § Garde-fous
> FSM) : `reconnect_on_failure=False` (2e canal d'auto-reconnexion interne sur rc=1/2, sans `on_connect`) ;
> `client._connect_timeout` forcé (`socket.setdefaulttimeout()` **sans effet** sur `connect()`) ;
> **anti-double-comptage** : un CONNACK refusé (rc 4/5) déclenchant `on_connect` **ET** `on_disconnect`,
> seul `on_disconnect` compte l'échec (`on_connect` mémorise le rc) ; garde `client is not self._client`
> contre les callbacks d'un client périmé. **Remontée d'état throttlée** : le démon ne `_notify` qu'au
> **changement d'état public** (`connected`/`retrying`/`blocked`) — pas un POST par tentative. Côté PHP,
> `handleDaemonMessage()` gère le nouvel event **`auth_failed`** (→ `alerterDaemonAuthFailed()`, alerte
> `message::add` throttlée calquée sur `alerterOtpRequired`, clé `daemon_auth_failed`), enrichit
> `connected` (efface l'alerte) / `disconnected`, maintient un **cache d'état démon** (`DAEMON_CONN_STATE_KEY`)
> lu par `health()` (ligne « Connexion du démon », **sans appel réseau**, affichée seulement si démon lancé
> + OTP actif) ; nettoyage dans `deamon_stop()`/`purgeOtp()`. **Post-MVP : UC21** — **détail batterie &
> charge** (télémétrie énergie enrichie, 100 % lecture/parsing, aucun appel réseau/MQTT nouveau) : 5
> commandes info supplémentaires mappées dans `parseStatus()` depuis le `/status` déjà récupéré au cron —
> `charging_rate` (km/h), `charging_remaining` (durée ISO `remaining_time` → **minutes** via le nouveau
> helper pur `dureeIsoEnMinutes()`, **sans clamp** car une durée peut dépasser 24 h — distinct de
> `parseHeureIso()` qui clampe une *heure*), `charging_mode`, `charge_next_time` (HH:MM via `parseHeureIso`
> derrière une **garde de format** `/^\s*PT\d/` pour ne jamais fabriquer un `00:00`). Ces 4 champs
> `charging.*` sont confinés à la branche `type=='electric'` (⇒ **jamais créés sur thermique pur**, AC3).
> `battery_12v` (racine `battery.voltage`, 12 V de servitude) est mappé **UNIVERSELLEMENT** (sans garde de
> motorisation — décision validée, cf. `stellantis-data-model.md` § 2.1/2.6), DISTINCT de `energy[].battery.*`
> (SOH/traction, hors périmètre). **Création paresseuse** : `createCommands()` **inchangé** ; les 5 commandes
> naissent au 1er `/status` qui les contient (boucle `ensureCommand` de `refreshTelemetry`). **Post-MVP :
> UC22** — **programmation de la charge** (1ʳᵉ commande action *paramétrée* du plugin) : commande action
> `charge_set_time` (subType **`message`**, saisie utilisateur au format Jeedom **`HHMM` sans séparateur**,
> ex. `2030`=20:30), créée **uniquement sur véhicule rechargeable** (`Electric`/`Hybrid`), `execute()` →
> `stellantis->chargeSetTime(string)`. Publiée via `publishRemoteCommand()` sur le **même** service
> `/VehCharge` qu'UC14 avec le payload `{"program":{"hour","minute"},"type":"delayed"}` (contrat
> `RemoteClient.change_charge_hour` de `psa_car_controller`), l'heure venant de l'utilisateur
> (reprogrammation délibérée) — **sans** refresh-avant-envoi (rien à préserver, ≠ `charge_stop`). ⚠️ Effet
> de bord assumé : `type:"delayed"` **interrompt une charge immédiate en cours** au profit du différé ;
> **pas** de confirmation native (action de routine, ≠ `unlock`). Parsing via le helper pur
> `parserHeureSaisie()` (deux branches avec/sans séparateur, rejet net via le **nouveau type d'exception
> `invalid_input`**, jamais de clamp d'une saisie utilisateur). **Seuil % (target SoC) hors périmètre** :
> confirmé non supporté par le contrat consommateur MQTT (aucun pourcentage dans les payloads
> `RemoteClient` ; cf. `stellantis-data-model.md` § 2.1). La **lecture** de la programmation
> (`charge_next_time`) était déjà en UC21. Garde-fous : garde motorisation + debounce per-véhicule
> **partagé** avec `charge_start`/`charge_stop` (même service `/VehCharge`) + quota global compte réutilisé
> de `publishRemoteCommand`. **Post-MVP : UC23** — **carburant & véhicules hybrides** (télémétrie énergie,
> 100 % lecture/parsing, aucun appel réseau/MQTT nouveau) : **scission propre de l'autonomie par énergie**
> dans `parseStatus()` — l'entrée `energies[].type=='Electric'` alimente `autonomy` (libellé renommé
> « Autonomie électrique »), l'entrée `'Fuel'` alimente la **nouvelle** info `autonomy_fuel` (« Autonomie
> carburant », clé propre : fin du partage « élec prioritaire » sur `autonomy`). Nouvelle info **dérivée**
> `autonomy_total` (« Autonomie totale » = somme élec + carburant) — **aucun champ d'autonomie combinée
> n'existe côté API** (cf. `stellantis-data-model.md` § 2.1), donc calculée dans `parseStatus` et **créée
> paresseusement** (jamais dans `createCommands`, précédent UC21), émise uniquement quand un même `/status`
> fournit les **deux** autonomies ⇒ hybride uniquement (satisfait AC1/AC2 par construction).
> `createCommands()` route désormais `autonomy_fuel` (eager, comme `fuel_level`) sur `Thermal`/`Hybrid` : un
> **thermique pur** n'obtient **aucune** commande EV (`battery_soc`/`autonomy`/`charging_*` restent
> `Electric`/`Hybrid`). **Migration** (`plugin_info/install.php` → `stellantis_update()`) : sur un thermique
> **déjà découvert**, l'ancien `autonomy` (autrefois alimenté par le carburant) se fige ⇒ **masqué**
> (`setIsVisible(0)`, best-effort, idempotent, non destructif, borné à `energy=='Thermal'`) pour éviter le
> doublon avec `autonomy_fuel`. **Post-MVP : UC24** — **suivi & statistiques de charge** (télémétrie
> énergie dérivée, 100 % lecture/parsing, aucun appel réseau/MQTT nouveau) : détection des **sessions de
> charge** par une machine à états en cache dans `refreshTelemetry()` (`suivreSessionCharge()`,
> orchestration cache+IO **séparée** du calcul pur `calculerRecapSession()`, précédent UC18). Transition
> `charging_status` **`InProgress` → statut terminal** (`Finished`/`Stopped`/`Disconnected`/`Failure` ;
> l'API ne connaît **pas** de valeur `Started`, détection insensible à la casse `estStatutTerminal()`) ⇒
> 3 commandes info **historisées, créées paresseusement** (jamais dans `createCommands`, précédent
> UC21/23) : `charge_session_energy` (kWh ≈ Δ SOC% × capacité), `charge_session_duration` (min),
> `charge_session_cost` (€) — libellés « (est.) » (estimations, AC2). Capacité = **config véhicule
> `battery_capacity` (kWh) autoritaire**, repli best-effort `energies[].extension.electric.battery.load.capacity` ;
> tarif = config `charge_tarif` (€/kWh) — 2 champs **éditables** ajoutés au formulaire desktop (sinon
> effacés au Sauvegarder). ⚠️ Les états terminaux de `charging.status` **persistent** poll après poll
> (`Disconnected` au repos, `Finished` tant que non débranché) → le log « session non comptabilisée » n'est
> émis que sur **transition** (dernier statut mémorisé en cache `CHARGE_LAST_STATUT_KEY`), jamais à chaque
> poll (~288×/j sinon ; cf. `stellantis-data-model.md` § 2.1). `health()` signale une capacité manquante ;
> idempotence par purge du cache de session **avant** écriture des commandes. **Post-MVP : UC31** —
> **position GPS structurée** (télémétrie localisation, 100 % lecture/parsing, aucun appel réseau/MQTT
> nouveau : dérivée du **même** GeoJSON `/lastPosition` déjà récupéré au cron par `refreshTelemetry`,
> gardé par `privacy.state=='None'`) : 5 commandes info **créées paresseusement** (jamais dans
> `createCommands`, précédent UC21/23/24), au-delà de la seule chaîne `position` (`lat,lon`, `GEOLOC`,
> socle MVP07 **inchangé, eager**) — `latitude`/`longitude` (⚠️ GeoJSON `geometry.coordinates=[lon,lat]`,
> ordre **lon,lat** inversé en `lat,lon`), `heading` (« Cap », °), `gps_signal` (`properties.signalQuality`
> 0-9), `position_updated` (`properties.createdAt`, **horodatage propre à la position**, distinct du
> `last_update` global). Enrichissement confiné au bloc position de `parseStatus()` avec **deux gardes
> INDÉPENDANTES** (coordonnées via `geometry` / propriétés via `properties`, jamais imbriquées) ;
> `heading`/`gps_signal` testés `is_numeric` (**`0` = valeur valide**, pas une absence). Nouveau helper pur
> `formaterDateApi()` (`strtotime` + garde `=== false` **avant** `date()` — sinon `TypeError` PHP 8 ⇒
> exception dans `parseStatus`, sans try/catch par champ ⇒ échec de **tout** le refresh du véhicule) ;
> `extraireFraicheur()` non refactorisé. ⚠️ **Asymétrie eager/lazy assumée** : un véhicule en privacy
> permanente affiche « Position » (vide) mais jamais les 5 dérivées. **Post-MVP : UC32** — **panneau
> carte « Mes véhicules » & widget carte** (100 % lecture/relai, aucun appel REST/MQTT nouveau) :
> page-panneau `desktop/php/panel.php` au menu d'accueil (`info.json "display":"panel"`, **toggle natif**
> `displayDesktopPanel`, aucun interrupteur maison) listant les véhicules `isVisiblePanel` (défaut 1,
> helper `assurerVisiblePanelParDefaut`, case dans le formulaire eqLogic) sur lesquels l'utilisateur a
> `hasRight('r')`. Position affichée via une **tuile carte statique PNG** (fournisseur
> `staticmap.openstreetmap.de`, sans clé, surchargé par la config `map_tile_url`) servie **same-origin**
> — la CSP Jeedom bloquant toute image externe directe (cf. `jeedom-widgets-commandes.md` § 7). **Deux
> canaux, une même méthode** `stellantis::renderStaticMap()` (`['type','body']`, **ne lève jamais**) : le
> **panel** (rendu serveur) embarque la tuile en **`data:` URI inline** ; le **widget dashboard**
> (`cmd.info.string.stellantisMap`, template posé sur la cmd `position` **si vide** via
> `assurerTemplatePositionParDefaut`, dashboard+mobile) passe par le **proxy**
> `core/ajax/stellantisMap.ajax.php` (`isConnect()` non-admin + `hasRight('r')` par véhicule). Fetch tuile
> **durci** (User-Agent identifiant obligatoire, HTTPS strict, `FOLLOWLOCATION=false`, timeout, taille
> bornée, `Content-Type` png/jpeg uniquement, jamais de Bearer) + **cache fichier** (coords **arrondies 4
> décimales**, négative-cache court) pour rester sous le ToS « faible volume » du fournisseur
> communautaire. **Défense en profondeur** : coordonnées + fraîcheur (`position_updated`) + lien
> OpenStreetMap/`geo:` **toujours** affichés en texte, la tuile n'étant qu'un enrichissement (repli
> placeholder). ⚠️ **Piège locale** : lat/lon formatés via `formaterCoordonnee()`
> (`number_format(…,'.','')`) — un `(string)$float` casserait l'URL sous `LC_NUMERIC=fr_FR`. **Post-MVP :
> UC33** — **historique des trajets** (télémétrie localisation dérivée, 100 % lecture/reconstruction,
> aucun appel réseau/MQTT nouveau : reconstruit à partir du **même** `/status` déjà récupéré au cron par
> `refreshTelemetry`) : les endpoints « trips » de l'API étant **dépréciés/inaccessibles** côté
> consommateur (comme `psa_car_controller`, cf. `stellantis-data-model.md` § 2.3), les trajets sont
> **reconstruits localement** par une machine à états en cache calquée à l'identique sur UC24
> (`suivreTrajet()` orchestration cache+IO, appelée en fin de `refreshTelemetry` après
> `suivreSessionCharge()` ; calcul pur séparé `calculerRecapTrajet()`). **Signal de détection robuste**
> (`stellantis-data-model.md` § 2.3) : `kinetic.moving` est **instantané** (fragmente les trajets à la
> cadence 5 min sur un simple arrêt), `ignition.type` **persiste** pendant tout le trajet ⇒ prédicat « en
> trajet » = `moving==1` **OU** `ignition ∈ {Start,StartUp}`, clôture seulement quand **les deux** disent
> « arrêté ». Nouveau mapping `parseStatus()` : `moving` (kinetic.moving 0/1) + `ignition` (ignition.type,
> enum assaini). **8 commandes info** en **création paresseuse** (jamais dans `createCommands`, précédent
> UC21/24/31), toutes `generic_type=''` (pas de GEOLOC dupliqué — réservé à la position « live ») :
> `moving`, `ignition`, et pour le **dernier trajet clos** `trip_distance` (delta `odometer.mileage`, km,
> **historisée** ⇒ l'historique Jeedom de cette commande **EST** l'historique des trajets, AC1),
> `trip_duration` (min, **historisée**), `trip_start`/`trip_end` (heures, instant de poll), positions de
> `trip_start_position`/`trip_end_position` (`"lat,lon"` via `formaterCoordonnee()`, piège locale évité).
> **Garde-fous** : garde signal `moving` absent (ne rien décider) ; ouverture **différée** si `mileage`
> absent (pas de `start_mileage` inventé, mirror UC24 « SOC inconnu ») ; clôture **reportée** si `mileage`
> absent (session conservée un poll de plus, jamais de trajet perdu sur trou d'odomètre) ; **garde
> anti-fantôme** distance ≤ 0 ⇒ aucun trajet écrit (flicker `moving`/bruit GPS à l'arrêt) ; **purge du
> cache AVANT écriture** (idempotence UC24). Estimations documentées : durée à **±cadence** (instant de
> poll) ; un arrêt > cadence reste un séparateur de trajets. **Post-MVP : UC34** — **geofencing / zone
> domicile** (télémétrie localisation dérivée, 100 % lecture/calcul, aucun appel réseau/MQTT nouveau :
> haversine sur la position UC31 déjà récupérée au cron) : une **zone domicile UNIQUE** en **config plugin**
> (`home_lat`/`home_lon`/`home_radius`, **partagée par tous les véhicules** — « le domicile » est une notion
> du foyer, pas du véhicule) produit 2 commandes info **par véhicule** en création paresseuse — `at_home`
> (binary, `generic_type=PRESENCE`, **historisée** ⇒ déclencheur de scénario, AC) et `home_distance` (m,
> **non historisée**, cf. confidentialité ci-dessous). Calcul best-effort `stellantis::suivreGeofencing()`
> (try/catch, ne lève jamais), appelé en dernier dans `refreshTelemetry()` après `suivreTrajet()` ; helper
> pur `distanceHaversineM()`. **Hystérésis asymétrique** (répond au « À confirmer » de la spec) : seuil
> d'ENTRÉE = rayon, seuil de SORTIE = rayon + `HOME_HYSTERESIS_M` (50 m), état précédent lu sur la cmd
> `at_home` **avant** écriture → pas de clignotement au bord. Garde-fous : `home_lat`/`home_lon` bloquants
> (absents ⇒ geofencing off), `home_radius` défaut 150 m + clamp si ≤ 0, **position absente ⇒ freeze**
> (privacy/pas de fix ⇒ jamais de faux « parti »). **Confidentialité durcie** (cf.
> `stellantis-data-model.md` § 2.2) : `home_lat`/`home_lon` **chiffrées au repos** (ajoutées à
> `$_encryptConfigKey`, protège backups/exports) ; `home_distance` **non historisée** (distance-au-point-fixe
> historisée + position exposée ⇒ trilatération possible de l'adresse). Restitution = scénarios Jeedom
> natifs (déclencheur sur changement de `at_home`). **Post-MVP : UC41** — **kilométrage & entretien**
> (télémétrie entretien, 100 % lecture/parsing, **1 nouvel appel REST throttlé**, aucun MQTT) : le
> kilométrage (`mileage`, socle MVP07 historisé) est **inchangé** (AC1 déjà satisfait) ; les **échéances
> d'entretien** viennent de l'**endpoint dédié `GET /user/vehicles/{id}/maintenance`** (contrat vérifié vs
> `psa_car_controller/connected_car_api` : `mileageBeforeMaintenance` + **`daysBeforeMaintenace`, faute de
> frappe RÉELLE de l'API** — un seul `n` ; parser lit aussi la forme correcte en repli ; wrapper runtime
> incertain ⇒ parsing **multi-shape** `_embedded.maintenance` puis racine). ⚠️ **Disponibilité NON
> garantie** (ni `psa_car_controller` ni l'intégration HA ne lisent réellement cet endpoint — présent
> seulement dans le client Swagger généré) ⇒ **best-effort, jamais fatal, création paresseuse** de 3
> commandes info : `service_distance` (km avant révision, historisée), `service_days` (jours, historisée),
> `service_due` (binary « révision proche », historisée = déclencheur de scénario, précédent `at_home`).
> Nouveau parser **pur** `parseMaintenance()` (seul endroit des chemins JSON `/maintenance`, `is_numeric`
> strict **sans clamp** — jours négatif = révision dépassée, valide) + helper **pur** `calculerRevisionProche()`
> (`1` si `service_distance <= seuilKm` **OU** `service_days <= seuilJours` — `<=` volontaire, ≠ `<` de la
> spec ; `null` si aucun champ ⇒ `service_due` non émis). Orchestration `suivreMaintenance()` (best-effort,
> `catch \Throwable`, ne lève jamais, appelée **en dernier** dans `refreshTelemetry()` après
> `suivreGeofencing()`) : **throttle différencié** anti-ban (timestamp de prochaine interrogation autorisée,
> précédent `RATELIMIT_KEY` — **24 h nominal**, **7 j sur HTTP 404** = endpoint mort pour ce
> véhicule/forfait, **3 h sur erreur transitoire** ; court-circuit `rateLimitRemaining()>0` sans poser de
> throttle) ; un endpoint « autre » ne s'appelle **jamais** à chaque poll de 5 min. **Seuils d'alerte PAR
> VÉHICULE** (`service_alert_km`/`service_alert_days`, config eqLogic éditable dans `desktop/php/stellantis.php`
> — précédent UC24 `battery_capacity`/`charge_tarif` ; repli sur défauts 1000 km / 30 j via helpers
> `seuilAlerteKm/Jours()`, car une clé vide ≠ absente ⇒ ne doit pas valoir 0). Restitution « révision
> proche » = **scénario Jeedom natif** sur `service_due` (pas de `message::add`). **Leçon transverse
> capitalisée** (`stellantis-data-model.md` § 3) : un endpoint des modèles Swagger de référence n'est pas
> une preuve qu'il est exploitable — vérifier qu'il est réellement **lu** par le code de référence avant d'y
> compter. **Post-MVP : UC42** — **pression des pneus / alertes TPMS** (télémétrie alertes, 100 %
> lecture/parsing, **1 nouvel appel REST throttlé** `GET /alerts`, aucun MQTT) : la **pression NUMÉRIQUE
> est absente** de l'API consommateur (confirmé) → seule une **info binaire** `tyre_alert` (historisée,
> création **paresseuse**, déclencheur de scénario natif — précédent `service_due`/`at_home`), dérivée de
> `GET /user/vehicles/{id}/alerts` (modèles `Alert`/`AlertsEmbedded`/`AlertMsgEnum`), = OR des **8 types
> pneus** de l'AlertMsgEnum (`tyreUnderInflation`/`underInflationTyreFault`/`wheelPressureFault`/
> `adjustTyrePressure`/`*TyreNotMonitored`, comparaison **insensible à la casse**). Parser **pur**
> `parseAlertes()` (seul endroit des chemins JSON `/alerts`, multi-shape `_embedded.alerts` → `alerts` →
> racine) **GÉNÉRIQUE** (renvoie la liste des types actifs, pas que pneus) + helper **pur**
> `calculerAlertePneu()` (0/1, jamais `null` ⇒ émis à **chaque** succès `/alerts`, satisfait AC3
> « absence ⇒ 0 »). Orchestration `suivreAlertes()` (best-effort, `catch \Throwable`, ne lève jamais,
> appelée **en dernier** dans `refreshTelemetry()` après `suivreMaintenance()`) : throttle différencié
> réutilisé d'UC41 — **1 h nominal** (alertes plus dynamiques que l'entretien), **7 j sur HTTP 403/404**
> (forfait/véhicule sans alertes), **3 h transitoire** ; court-circuit `rateLimitRemaining()>0`. ⚠️
> **Sémantique fail-closed** : une entrée `/alerts` sans clé `active` exploitable est **ignorée** (jamais
> comptée active) — sur un endpoint à shape runtime non vérifiée, ne jamais conclure « alerte active » par
> défaut (faux positif « crie au loup ») ; log `debug` (via `aseptiser()`) pour confirmer la vraie
> sémantique en recette. **Pas de commande par roue** (les seuls types positionnels sont
> `*TyreNotMonitored` = capteur non surveillé, ≠ sous-gonflage ; les vraies alertes de pression n'ont
> aucune position). **Inversion de dépendance UC42/UC43 assumée** (UC43 pas encore implémentée) :
> `parseAlertes()`/`suivreAlertes()` sont le **socle qu'UC43 doit ÉTENDRE** (catalogue complet des ~80
> types + `alerts_count`), jamais dupliquer — consigne inscrite dans le docblock **et**
> `43-alertes-vehicule.md`. **Leçon (`stellantis-data-model.md` § 3)** : `/alerts` **non plus** n'est lu
> par les références (comme `/maintenance`) → best-effort ; mais `AlertsEmbedded` **existe** (≠
> `maintenance_embedded` manquant) ⇒ le wrapper `_embedded.alerts` est **plus certain**. **Post-MVP :
> UC43** — **alertes véhicule (catalogue générique AdBlue/lave-glace/voyants/révision…)** (télémétrie
> alertes, 100 % lecture/parsing, **AUCUN appel réseau/MQTT neuf** : **ÉTEND** le poller `/alerts` d'UC42,
> jamais de 2ᵉ poller/cache) : `parseAlertes()`/`suivreAlertes()` réutilisés **tels quels** (fail-closed,
> insensibilité à la casse, throttle 1 h/7 j/3 h inchangés). Nouvelle info agrégée **`alerts_count`**
> (numeric historisée, création paresseuse comme `tyre_alert` — déclencheur de scénario natif « le véhicule
> a une alerte », `#alerts_count# > 0`, AC2) + **une commande binaire PAR TYPE d'alerte rencontré**
> (`alert_<slug>`, AC1) en **création DYNAMIQUE hors `definitionsCommandes()`** (catalogue AlertMsgEnum ~80
> types, variable/non figé) : helper `sluggifierTypeAlerte()` (slug `[a-z0-9_]`), `ensureAlertCommand()`
> (via `trouverOuInstancierCmd()` + **définition synthétique** `[$nom,'binary']`, nom = **libellé brut
> sécurisé** `htmlspecialchars(aseptiser($type,60))` — donnée RUNTIME, **jamais** `__()`),
> `synchroniserCommandesAlertes()` (actifs→1, **plus-actifs→0 par énumération `cmd::byEqLogicId(id,'info')`
> filtrée préfixe `alert_`** — pas un cache d'état, survit à `cache::flush()` ; commandes **persistantes**,
> « une par type rencontré »). ⚠️ **Binaires par type NON historisées** (`setIsHistorized(0)`) : `isHistorized`
> pilote le **stockage en table d'historique**, **pas** le déclenchement de scénario (event cmd) → ne pas
> gonfler l'historique pour ~80 binaires × N véhicules ; seule `alerts_count` (agrégat unique) est
> historisée. Garde-fous : **plafond anti-prolifération `ALERT_MAX_TYPES=100`** (troncature + log warning
> avant création — réponse `/alerts` anormale/compromise ne peut créer un nombre illimité de commandes
> persistantes) ; écritures de commandes **isolées** dans un `try/catch \Throwable` interne de
> `suivreAlertes()` (throttle succès posé **avant** — corrige au passage le throttle-poisoning préexistant
> UC42) ; garde reserved-ids explicite (`alerts_count`/`tyre_alert`). **Inversion de dépendance UC42/UC43
> RÉSOLUE.** **Post-MVP : UC44** — **ouvrants détaillés (portes/fenêtres/coffre/capot)** (télémétrie,
> 100 % lecture/parsing du **même** `/status`, **aucun appel réseau/MQTT neuf**) : mapping
> `doors_state.opening[n].{identifier,state}` (data-model § 2.4, enum **connu** de 7 valeurs
> `Driver/Passenger/RearLeft/RearRight/Trunk/RearWindow/RoofWindow`) via le helper **pur**
> `extraireOuvrants()` (miroir d'`extraireVerrouillage`, intégré en 1 ligne `array_merge` dans
> `parseStatus()`). ⚠️ **Approche STATIQUE** (≠ UC43 dynamique) car enum petit/connu/signifiant ⇒ 8
> commandes `door_<id>` **déclarées** dans `definitionsCommandes()` (binary `generic_type OPENING`, **non
> historisées** = états comme `doors_locked` ; `door_hood`/« Capot » déclaré **par anticipation**, non
> confirmé côté API) + agrégat **`opening_alert`** (binary `''`, **historisé** = déclencheur de scénario
> « un ouvrant ouvert », AC2). **Création paresseuse** (jamais dans `createCommands`). Garde-fous :
> constante `OPENING_IDENTIFIERS` (map identifiant→logicalId, **invariant** : toute valeur DOIT être
> déclarée dans `definitionsCommandes` sinon `ensureCommand` casse le refresh) ⇒ **jamais de logicalId
> dynamique émis** (étanche au throw) ; un identifiant inconnu est **compté dans l'agrégat** + loggué
> `debug` (aseptisé), jamais perdu ni émis ; **fail-closed** sur `state` (seul « Open » ⇒ ouvert) ; gardes
> défensives `isset/is_array/is_scalar` (`parseStatus` reste pur). **Post-MVP : UC51** — **identité du
> véhicule (VIN, marque, libellé, énergie)** (100 % local, AUCUN appel réseau : exploite le champ `label`
> déjà remonté par `discoverVehicles()`) : `syncVehicles()` **persiste** désormais la config `label`
> (autorité découverte, réécrite à chaque sync comme `brand`) ; le formulaire desktop `stellantis.php`
> l'**affiche** en readonly « **Libellé du véhicule** » (≠ « Modèle » : l'API n'a NI `model` NI
> `motorization`, `label` est un **surnom renommable dans l'app mobile** — cf. `data-model` § 1, « À
> confirmer » de la spec 51 clôturé). Ce champ readonly est **obligatoire dans le formulaire** (sinon la
> clé `label` serait effacée au Sauvegarder). Une commande info string **`label`** (universelle, eager,
> non historisée) est créée + peuplée dans `createCommands()` depuis la config (valeur **statique** —
> jamais via `parseStatus` — rafraîchie au sync/self-heal, pas à chaque cron). ⚠️ **Sécurité** : `label`
> étant le **1er texte VRAIMENT libre et externe** posé en valeur de commande info (rendu par le widget
> dashboard générique du core), sa valeur est **neutralisée** `htmlspecialchars(self::aseptiser(...))`
> avant écriture (même convention qu'UC18/UC43 ; cf. `jeedom-cmd-creation-patterns` mémoire) — la config
> reste brute (input admin peuplé en `.val()`, non interprété HTML). **Pas de commande `vin`** (décision
> produit : éviter d'exposer le VIN sur les dashboards ; il reste visible en config admin). AC2 (nom par
> défaut = marque + libellé) était **déjà** satisfait (`setName` MVP06, inchangé). **Post-MVP : UC52** —
> **image / vignette du modèle du véhicule** (image d'équipement eqLogic ; 100 % local + **1
> téléchargement best-effort au sync**, aucun MQTT, jamais d'appel au cron) : chaque véhicule reçoit une
> image **cohérente servie same-origin** (jamais d'`<img>` externe → pas de blocage CSP). ⚠️ **Aucune
> méthode `setImage()`** côté core : mécanisme réel = fichier `data/eqLogic/eqLogic{ID}-{sha512}.{type}` +
> config `image::sha512`/`image::type` (lues par `getImage()`/`getCustomImage()`), repli natif = icône du
> plugin. Cascade dans `assurerImageVehicule()` (appelée par `syncVehicles()` après `save()`, best-effort
> `\Throwable`, **jamais au cron**) : **(1) photo modèle** best-effort depuis le champ `pictures` de
> `/user/vehicles` — **shape runtime NON vérifiée** (`Url` = stub Swagger vide, jamais lu par les réfs, 3ᵉ
> instance de la leçon `/maintenance`+`/alerts`) → parsing défensif `extraireUrlImageModele()` (URL https
> sous `href`/`url`/`_links.self.href` ou chaîne directe) + **log `debug` de la forme brute** pour observer
> la vraie shape en beta + téléchargement durci `telechargerImageModele()` (calqué `telechargerTuile` UC32 :
> HTTPS strict, `FOLLOWLOCATION=false`, ≤ 2 Mo, content-type png/jpeg allow-list, **jamais de Bearer**,
> **validation contenu `getimagesizefromstring`** en défense en profondeur du Content-Type déclaré) ;
> **(2)** sinon **icône de marque** bundlée `plugin_info/brands/{peugeot,citroen,ds,opel,vauxhall}.png`
> (badges tintés générés Pillow, **pas de logo déposé** — disclaimer trademark ajouté aux `docs/*/index.md`)
> via `cheminIconeMarque()` (normalisation accents `mb_strtolower`+`iconv`, « Citroën »→`citroen`) ;
> **(3)** sinon repli natif icône plugin. **Idempotence & self-heal** : marqueurs config `image::source`
> (`model`|`brand`) + `image::model_url` évitent le re-download et permettent le self-heal au changement
> d'URL/marque ; une image **posée manuellement** par l'utilisateur (`source` ∉ {model,brand}) n'est jamais
> écrasée ; un échec **transitoire** de re-download **préserve** la photo modèle existante (pas de flicker).
> `poserImageEqLogic()` : ordre **écrire-puis-purger** + glob **ancré sur `-`** ; `preRemove()` purge les
> fichiers image du véhicule (sinon fuite disque). ⚠️ Les clés `image::*` ne sont **pas** dans le
> formulaire desktop : elles survivent au « Sauvegarder » car `utils::a2o()` **fusionne** la config clé par
> clé (nuance la règle « clé absente du form = effacée » — à confirmer en recette). Suite = post-MVP
> (UC53 multi-véhicules/comptes, UC54 multi-marques, supervision, robustesse…).
> Cette note est
> **mise à jour en fin de chaque `/feature`** (dernière étape du workflow) — elle reflète l'avancement
> réel, pas un instantané figé.

## Feuille de route & specs

L'implémentation est découpée en UC (use cases), **à lire avant de coder une fonctionnalité** :

- `.memory/specs/README.md` — index, MVP, roadmap, conventions, **statut de fiabilité** des endpoints.
- `.memory/specs/MVP/01..10` — socle livrable en premier (ordre strict, 100 % PHP/REST, lecture) :
  config → client HTTP → OAuth2/token → test → découverte véhicules → équipements → commandes info
  télémétrie → cron refresh → fraîcheur/online → robustesse.
- `.memory/specs/post-mvp/` — UC suivantes par domaine : `10-commandes-distance` (démon MQTT),
  `20-energie-charge`, `30-localisation-trajets`, `40-entretien-alertes`, `50-gestion-vehicules`,
  `70-supervision-robustesse`, `80-livraison`.

## Architecture

Disposition Jeedom fixe (type MVC). Pièces principales, toutes nommées d'après l'id `stellantis` :

- **`core/class/stellantis.class.php`** — le cœur. Plusieurs classes (1 classe ↔ 1 logique) :
  - `stellantis extends eqLogic` — **une instance par véhicule** (clé `logicalId = VIN`). Hooks de cycle
    de vie (`preSave/postSave`, `preRemove/postRemove`…) ; hook `cron()` (chaque minute) → **polling REST**
    de la télémétrie (cadence par défaut 5 min + `autorefresh` par véhicule) ; `$_encryptConfigKey` chiffre les champs sensibles.
  - `stellantisCmd extends cmd` — commande (info ou action). `execute($_options)` aiguille les actions
    (`switch` sur `logicalId`) vers la méthode métier du véhicule, en passant par le démon MQTT. **Wakeup
    (UC13)** → `stellantis->wakeup()`, **charge start/stop (UC14)** → `stellantis->chargeControl(bool)`,
    **préconditionnement (UC15)** → `stellantis->precondControl(bool)`, **verrouillage/déverrouillage
    (UC16)** → `stellantis->doorControl(bool)`, **klaxon/feux (UC17)** → `stellantis->horn()` /
    `stellantis->lights()` et **programmation de charge (UC22)** → `stellantis->chargeSetTime(string)`
    (commande action *paramétrée* `message`) implémentés. **Retour d'état async (UC18)** : le callback démon
    (`stellantis::handleDaemonMessage` → `traiterRetourCommande`) interprète les acks MQTT, alimente l'info
    `last_command_result` par véhicule et signale les échecs. Publication MQTT
    centralisée par `stellantis::publishRemoteCommand()`/`buildMqttRequest()`.
- **Client API `stellantisApi`** (définie dans `core/class/stellantis.class.php`, cf. `MVP/02`,`03`) —
  **brique unique** par laquelle passent **tous** les appels HTTP REST : OAuth2 PKCE (URL d'autorisation,
  échange du `code`, refresh), enveloppe Bearer + header `x-introspect-realm`, base
  `api.groupe-psa.com/connectedcar/v4`, parsing, mapping d'erreurs (401/invalid_grant/rate-limit).
  **Aucune autre partie du code n'appelle l'API directement.**
- **`desktop/php/stellantis.php`** — page de configuration admin (HTML), protégée par `isConnect('admin')`.
  Liste les véhicules + formulaire. Liaison au modèle via `data-l1key`/`data-l2key`. i18n via `{{...}}`.
  Se termine en incluant le JS du plugin puis le JS générique de page plugin **fourni par le core**
  (`include_file('core', 'plugin.template', 'js')` → asset du core, **à ne pas renommer/modifier**).
- **`desktop/js/stellantis.js`** — front-end (lignes de commandes, tri, helpers `jeedom.*`).
- **`desktop/php/panel.php`** — page-panneau **« Mes véhicules »** au menu d'accueil (UC32), `isConnect()`
  **non-admin** (usage quotidien). Une carte de position par véhicule visible (`isVisiblePanel` +
  `hasRight('r')`) : tuile inline `data:` URI (via `stellantis::renderStaticMap()`) + coordonnées + lien
  OpenStreetMap/`geo:`. Enregistrée par `info.json "display"` (toggle natif, cf. `jeedom-panel-page-menu.md`).
- **`core/ajax/stellantis.ajax.php`** — endpoint AJAX (admin-only) : inclut le core, `isConnect('admin')`,
  `ajax::init()`, aiguillage sur `init('action')` (ex. génération de l'URL OAuth, soumission du `code`,
  test de connexion, synchronisation des véhicules) en branches `if (init('action') == '...')`.
- **`core/ajax/stellantisMap.ajax.php`** — endpoint AJAX **non-admin** (UC32), **distinct** de
  `stellantis.ajax.php` : proxy same-origin de la tuile carte pour le **widget dashboard**. `isConnect()`
  puis délègue à `stellantis::renderStaticMap()` (garde autoload : n'appelle que `stellantis::` ; contrôle
  fin `hasRight('r')` par véhicule dans la méthode). Réponse binaire PNG.
- **`core/template/{dashboard,mobile}/cmd.info.string.stellantisMap.html`** — widget carte (UC32) posé
  sur la commande info `position` (`<img>` → proxy `stellantisMap.ajax.php`). Deux fichiers synchronisés.
- **`plugin_info/configuration.php`** — formulaire de la page de config plugin (`gotoPluginConf`).
  Champs liés en `class="configKey" data-l1key="<clé>"` (auto-load/save core via
  `config::byKey/save(..., 'stellantis')`).
- **`resources/demond/`** — **démon Python MQTT** (commandes à distance). Réutilise le squelette
  `demond.py` + lib `jeedom/` (socket `jeedom_socket` PHP→démon, `jeedom_com` démon→Jeedom). **Actif
  depuis UC11** (`hasOwnDeamon:true`) : `demond.py` = transport MQTT générique (`paho-mqtt`, TLS) piloté
  par `stellantis::sendToDaemon()` (actions `connect`/`subscribe`/`publish`/`set_token`) ; remontées
  démon→Jeedom via le callback `core/php/jeeStellantis.php` (exception `.htaccess` dédiée) →
  `stellantis::handleDaemonMessage()`. `jeedom.py` a été allégé (retrait serial/pyudev) et corrigé
  (aucun secret loggué). Toute commande MQTT passe par le démon (jamais de MQTT épars).

> ⚠️ **Accès restreint à `plugin_info/configuration.php`** — Claude Code **ne peut pas lire ni
> éditer** ce fichier via les outils Read/Edit/Write (refusé par les permissions de session).
> Une copie synchronisée **`plugin_info/configuration.txt`** sert de miroir éditable, et le `.php`
> est régénéré depuis le `.txt` via une commande bash de copie :
> - **Lecture** : toujours lire `configuration.txt` (jamais `configuration.php`).
> - **Écriture** : modifier **uniquement** `configuration.txt` (outils Edit/Write). Le `.txt` est
>   la **source de vérité éditable** du formulaire de config tant que la restriction est en place.
> - **Synchronisation** : le `.php` étant la version réellement exécutée par Jeedom, **les deux
>   fichiers doivent rester identiques**. Après **chaque** modification du `.txt`, écraser le `.php`
>   via bash (la copie remplace intégralement le fichier, pas de fusion) :
>   ```bash
>   cp plugin_info/configuration.txt plugin_info/configuration.php
>   ```
>   À refaire systématiquement à chaque changement — ne jamais laisser les deux fichiers diverger.

Configuration & secrets :
- **Par plugin** (`config::save/byKey(..., 'stellantis')`) : **marque** (détermine TLD `idpcvs.{}` +
  realm `clientsB2C…`), `client_id`, `client_secret` (extraits de l'APK — manuellement via un outil
  externe, ou automatiquement via **UC61** `Extraire automatiquement`), `redirect_uri`, `apk_url`
  (optionnel : override de l'URL de l'APK pour UC61), `broker_host`/`socketport` (optionnels : démon MQTT
  UC11 ; défauts `mwa.mpsa.com`/`55009`), `customer_id` (CID MQTT, résolu auto en UC12 via `GET /user`,
  repli saisie manuelle). **UC12 (OTP)** ajoute : `otp_device` (device OTP provisionné, **chiffré
  `utils::encrypt`**, en config pour survivre à `cache::flush`), `otp_sms_count` (compteur d'activations
  SMS **à vie** 0..20, jamais remis à 0 auto), `otp_sms_pending` (flag « SMS envoyé, activation en
  attente »). **UC32** ajoute `map_tile_url` (optionnel : override du fournisseur de tuiles carte ; défaut
  = constante `MAP_TILE_URL` = `staticmap.openstreetmap.de`). **UC34 (geofencing)** ajoute la **zone
  domicile partagée** : `home_lat`, `home_lon` (coordonnées du domicile — **chiffrées** via
  `$_encryptConfigKey`, données perso), `home_radius` (rayon m, défaut 150, non chiffré). `client_secret`
  **chiffré**, jamais loggué.
- **Par véhicule** (`configuration` de l'eqLogic) : `id` API, `vin`, `brand`, motorisation, capacités.
  **UC24** ajoute 2 champs **saisis manuellement** (formulaire desktop, éditables) : `battery_capacity`
  (capacité batterie kWh, sert à estimer l'énergie de charge) et `charge_tarif` (prix du kWh €, coût estimé).
  **UC32** ajoute `isVisiblePanel` (case du formulaire desktop, défaut 1 posé par le plugin — inclut le
  véhicule dans le panneau carte « Mes véhicules »). **UC41** ajoute 2 champs **éditables** (formulaire
  desktop) : `service_alert_km` / `service_alert_days` (seuils « révision proche » par véhicule ; repli sur
  défauts 1000 km / 30 j via `seuilAlerteKm/Jours()` si vides).
- **Tokens** OAuth2 (access/refresh) + **remote token OTP** (UC12, clé cache `stellantis::remote_token`,
  **distinct** du token OAuth2 — c'est le mot de passe MQTT, TTL ~890 s) : en cache **chiffré** (classe
  `cache`). ⚠️ `access_token` OAuth2 à durée courte (~15 min) → refresh proactif/réactif.

Support :
- **`plugin_info/info.json`** — manifeste (id, version, `require`, OS, `category`, dépendances, langues, compat).
- **`plugin_info/install.php`** — `stellantis_install/update/remove()` ; `pre_install.php` → `stellantis_pre_update()`.
- **`plugin_info/packages.json`** — dépendances (uniquement `pip3`). **Post-MVP commandes** : `pip3`
  `paho-mqtt` **épinglé en 1.6.1** (dernière 1.x ; la 2.0 casse le client MQTT de référence ; Debian 12 →
  virtualenv / `--break-system-packages`) + `requests` (épinglé) + **`pycryptodomex`** (épinglé ; importé
  `Cryptodome` par le module OTP vendorisé `resources/otp_vendor`, UC12 — RSA-OAEP/AES/SHA256).
  ⚠️⚠️ **La version se met dans la VALEUR, pas dans
  la clé** : `"paho-mqtt": {"version": "1.6.1"}`, **jamais** `"paho-mqtt==1.6.1": {}`. Le core compare la
  **clé** (nom seul) à `pip list` (clé = nom nu, ex. `paho-mqtt`) via
  `isset($installPackage[strtolower($clé)])` (`system::checkAndInstall`). Une clé contenant `==x.y.z` ne
  matche **jamais** le nom installé → le paquet est vu « à installer » en **permanence** → indicateur
  **bloqué NOK** + réinstallation forcée à chaque passe (bug observé UC11). Avec `{"version":"1.6.1"}`,
  `installPackage` construit `pip install … paho-mqtt==1.6.1` via sa branche `==` (sans `<`/`>`), donc
  **shell-safe** ET correctement matché. ⚠️ **Piège shell (distinct)** : ne jamais mettre `<`/`>` dans le
  champ `version` (ex. `"<2.0.0"`) → `installPackage` bascule sur `$package .= $version` collé non quoté →
  redirection shell (`2.0.0: No such file or directory`, paquet jamais installé). Toujours une version
  exacte sans opérateur. **Ne PAS définir `stellantis::dependancy_info()`** : dès que `packages.json`
  existe, le core calcule l'état **uniquement** depuis `checkAndInstall(packages.json)` et n'appelle
  **jamais** cette méthode statique (elle serait du code mort). Pour un contrôle *supplémentaire*
  (post-`packages.json`), le hook officiel est `additionnalDependancyCheck()` (appelé seulement si l'état
  `packages.json` est déjà `ok`). **UC61 (extraction APK)** n'a **aucune dépendance PHP** : la
  décompression bz2 + lecture zip passe par `resources/extract_credentials.py` (stdlib Python `bz2` +
  `zipfile`, réutilise l'interpréteur déjà présent pour le démon) → pas d'extension PHP, pas de
  redémarrage d'Apache. (cf. `.memory/specs/post-mvp/80-livraison/82-packaging-doc.md`).

## Workflows / CI

CI déléguée aux workflows réutilisables de Jeedom (`jeedom/workflows`) :
- **`.github/workflows/work.yml`** — check complet du plugin sur push/PR vers `beta`, PR vers `master`.
- **`.github/workflows/prettier.yml`** — pousser sur la branche **`prettier`** déclenche un bot qui
  reformate le code et commite (moyen de formatage automatique ; pas de config prettier locale).

Pas de commande de lint/test locale ; la validation tourne dans ces workflows contre un Jeedom réel.
Recette fonctionnelle manuelle : `.memory/specs/post-mvp/80-livraison/81-validation-manuelle.md`.

## Conventions

- **Français = langue source** : code, commentaires, noms de variables, messages de `log::add` et chaînes
  UI sont écrits en français (langue **par défaut** de Jeedom — pas de `fr_FR.json`).
- **Autoload Jeedom (règle critique, fatale au runtime, invisible à `php -l`)** : l'autoloader mappe
  **1 classe ↔ 1 fichier** `<NomClasse>.class.php`. Toute classe référencée depuis un **point d'entrée
  externe** (`core/ajax/*.ajax.php`, hooks cron, `desktop/php/*.php`, `install.php`) doit avoir son propre
  fichier OU transiter par `stellantis`/`stellantisCmd` (dont `stellantis.class.php` charge aussi
  `stellantisApi`/`stellantisException`). Un appel **direct** `stellantisApi::` / `stellantisException`
  depuis un point d'entrée externe = `Fatal error: Class not found`.
- **Tout appel HTTP REST passe par `stellantisApi`** ; toute commande à distance passe par le démon
  (post-MVP). Pas de cURL ni de MQTT épars.
- Indentation 2 espaces (PHP/JS).
- Logs via `log::add('stellantis', 'debug'|'info'|'warning'|'error', $msg)` ; **jamais** de
  secret/token/`client_secret`/VIN-en-clair-sensible exposé.
- **Robustesse cron** : un véhicule en erreur n'interrompt pas la boucle (try/catch par véhicule) ;
  respecter les **guardrails** anti-ban / batterie 12 V (cf. analyse § 1.4 et UC 70-supervision).
- Les `.htaccess` de `core/php`, `core/class`, `core/ajax`, `resources/`… interdisent l'accès web direct
  — les conserver.
- `docs/<langue>/` = documentation **utilisateur** ; `.memory/` = analyse & specs **internes** (français).

## Internationalisation (i18n) — natif multilingue

Le plugin est **nativement multilingue**. Langues : **`fr_FR` (source/défaut)**, **`en_US`**, **`de_DE`**,
**`es_ES`**.

- Toute chaîne UI est **enveloppée** : `{{Texte français}}` en HTML/JS, `__('Texte français', __FILE__)`
  en PHP. La clé est **toujours le texte source français**.
  - ⚠️ **Toujours une chaîne LITTÉRALE** dans `__()` — jamais `__($variable)`. L'extraction i18n (dont
    le sous-agent `translator`) est un **scan statique** : un nom stocké dans un tableau/variable puis
    passé à `__()` échappe à la traduction (constaté UC07 : les libellés de commandes doivent porter
    `__('Batterie', __FILE__)` **dans** la table de définitions, pas `__($nom)` au moment de l'usage).
- Les traductions vivent dans `core/i18n/<langue>.json`, **un fichier par langue cible**
  (`en_US.json`, `de_DE.json`, `es_ES.json` — **pas** de `fr_FR.json`). Format :
  ```json
  { "plugins/stellantis/<chemin/relatif/fichier>": { "Texte français": "Traduction" },
    "info.json": { "Description française": "Traduction" } }
  ```
- La `description` d'`info.json` est fournie pour **les 4 langues** ; docs dupliquées par langue.
- **Règle d'or** : toute clé UI **livrée** doit avoir ses **3 traductions**. *Quand* les produire :
  - **Dans `/feature`** : traduction faite **en fin de cycle par le sous-agent `translator`** (code figé,
    contexte isolé) ; pendant le dev on enveloppe en français mais on **ne touche pas** aux `*.json`.
  - **Hors workflow** : ajouter/mettre à jour la clé dans les 3 fichiers dès qu'on l'introduit.
