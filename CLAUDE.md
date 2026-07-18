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

> **État d'avancement (2026-07-17)** : l'id a été renommé `template` → `stellantis` (classes
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
> clé (nuance la règle « clé absente du form = effacée » — à confirmer en recette). **Post-MVP : UC53** —
> **multi-véhicules & multi-comptes** : **UC de CADRAGE, livrable 100 % documentaire, AUCUN code** (décision
> utilisateur après challenge advisor). **AC1** (« sync correcte/performante à plusieurs véhicules ») est
> **déjà satisfaite au MVP** — documenté explicitement dans `53-tech.md` : quotas durs **GLOBAUX au compte**
> (`WAKEUP_QUOTA`/refresh, jamais par véhicule), mutualisation du token (`getToken()` 1×/passe cron),
> `try/catch` par véhicule, backoff 429 global ; **limites assumées** (`syncVehicles()` synchrone borné par
> le cooldown 15 s ; pagination `/user/vehicles` non gérée, loggée depuis UC05) ; l'**anti-rafale proactif
> du polling** (spread de phase des refresh en cadence défaut `*/5`) est **déplacé en UC72**. **AC2**
> (besoin multi-comptes) **tranché & capitalisé** (`stellantis-api-architecture.md` § 4.5) : 1 plugin Jeedom
> = 1 config + 1 token global + 1 OTP → **1 compte/1 marque** aujourd'hui (la table `BRANDS` rend la marque
> *sélectionnable*, **pas simultanée** — corrige la sur-affirmation § 4.4). Design **UC54** = namespacing par
> **identifiant de compte générique** (marque = attribut du compte, clé cache `TOKEN_CACHE_KEY::<accountId>`,
> cron prime le token 1× par compte distinct) → couvre le multi-marques **et** laisse ouvert « 2 comptes
> même marque » (choix produit diffé­rable, pas fatalité technique — le refus par clé-marque était
> circulaire) ; `54-multi-marques.md` recablée en conséquence (écart assumé). **Post-MVP : UC54** —
> **multi-marques & multi-comptes (SLICE LECTURE SEULE)** : un foyer peut désormais rattacher des
> véhicules à **plusieurs comptes/marques** dans la même instance. **Slots de comptes FIXES**
> (`stellantis::MAX_ACCOUNTS=3`) : **slot 1 = la config globale ACTUELLE** (clés `client_id`/
> `client_secret`/`brand`/`country`/`redirect_uri` NON suffixées, cache token actuel) ⇒ **zéro migration**,
> installs mono-compte et véhicules existants inchangés ; slots 2..N = clés **suffixées** `_2`/`_3`
> (`client_secret_2`/`_3` ajoutés à `$_encryptConfigKey`). ⚠️ **Slots fixes imposés par une contrainte
> core** : `preConfig_<clé>` est un **nom de méthode FIXE** (pas d'itération dynamique) ⇒ pas de table
> `accounts[id]` dynamique (cf. mémoire `jeedom-encrypt-config-key`). Helpers : `configKeyForSlot($base,
> $slot)` (nommage config), `stellantisApi::cacheKeyForSlot($base,$slot)` (nommage cache — **retourne la
> clé NON suffixée si slot≤1**, rétro-compat), `slotsConfigures()` (slots 1..MAX ayant client_id+secret),
> `accountSlotDe(eqLogic)` (résolution défensive du slot d'un véhicule, hors bornes→1, source unique).
> **Routage par compte** : chaque eqLogic porte `accountSlot` (défaut 1) ; `getApiConfig(int $slot)` +
> threading `$slot` (défaut 1) à travers la façade LECTURE+OAuth de `stellantisApi` (`call`,
> `callWithToken`, `getToken`, `refreshToken`, `requestToken`, `storeTokenResponse`, `readTokenCache`,
> `getTokenInfo`, `purgeTokenCache`, `buildAuthUrl`, `exchangeCode`, `rateLimitRemaining`,
> `enterRateLimitCooldown`, quota refresh). **Cloisonnement par slot** des 6 clés cache **niveau-compte**
> (`TOKEN_CACHE_KEY`, `OAUTH_PENDING_KEY`, `REFRESH_QUOTA_KEY`, `RATELIMIT_KEY`, `LINK_ERROR_KEY`,
> `degraded_warn`) ⇒ un 429/quota/échec d'auth d'un compte **ne gèle jamais** un autre (les clés déjà
> suffixées par `eqId` et les clés OTP/démon restent intactes). `syncVehicles()` boucle sur
> `slotsConfigures()`, tague chaque véhicule de son `accountSlot`, **désactivation FILTRÉE par slot** et
> **jamais** sur un compte en échec de découverte / non parcouru. `cron()` **restructuré** : priming du
> token **1× par slot** (try/catch + `continue` par slot, plus de `return` global), `syncDaemonToken()`
> **slot 1 seulement**, un véhicule d'un slot indisponible/non configuré est **sauté** (jamais désactivé).
> `connectionState()` = agrégat pire-état des comptes (via `connectionStateForSlot`) ; `health()` = 1 ligne
> par compte configuré. Hooks `preConfig_client_id_2/_3`, `preConfig_brand_2/_3` (purge du token **du slot
> concerné**, jamais l'OTP). **UI** (`configuration.txt`→`cp .php`) : section « **Compte principal
> (pilotage à distance)** » (slot 1, inchangée) + sections repliables « **Compte secondaire N (lecture
> seule)** » (rendues **seulement si slot 1 configuré**), OAuth 2 étapes + extraction APK + test par slot
> (JS générique par délégation + `data-slot`), actions AJAX paramétrées par `slot` (borné 1..MAX). eqLogic :
> champ **hidden `accountSlot`** (`eqLogicAttr`, re-soumis à chaque save — ne pas parier sur `a2o()` pour
> une clé de routage) + `accountSlotLabel` readonly affiché. ⚠️ **Limite assumée** : pilotage à distance
> (commandes/OTP/MQTT) sur le **compte principal (slot 1) UNIQUEMENT** — le démon MQTT reste
> **mono-connexion** (`demond.py` inchangé). `createCommands()` ne crée les commandes **action** que si
> `accountSlot==1` ; **garde runtime** dans `stellantisCmd::execute()` (commande action + `accountSlot!=1`
> ⇒ refus « lecture seule »). Le vrai multi-comptes pour les commandes (démon multi-connexions) = futur UC.
> **Post-MVP : UC71** — **page Santé & fraîcheur complétée** (supervision, 100 % lecture cache/local,
> **AUCUN appel réseau** — AC2 par construction) : `stellantis::health()` (socle MVP/09, déjà étendu par
> UC12/UC19/UC54) est **complétée** de 3 lignes — (1) **lien Documentation** (ancre HTML construite via le
> nouveau helper `docUrl()` qui lit `plugin_info/info.json` et remplace `#language#`, **source unique**,
> `htmlspecialchars`+`rel="noopener noreferrer"`, ligne omise si illisible) ; (2) **état démon complété** —
> le cas « OTP actif MAIS démon arrêté » (auparavant silencieux) est signalé `state=false` (branches UC19
> `connected`/`retrying`/`auth_failed` inchangées) ; (3) **dernier résultat de commande par véhicule**
> (`last_command_result`, UC18) affiché **avant** le `continue` privacy (un résultat de commande n'est pas
> une donnée de localisation). ⚠️ Le `state` (couleur vert/rouge) de cette ligne est dérivé d'un
> **marqueur cache machine NON traduit** — `CMD_STATUS_KEY.$eqId` = `$interp['notify']` posé par
> `traiterRetourCommande` à côté de la valeur affichée (try/catch DÉDIÉ) — **jamais** du texte affiché
> (traduit ⇒ un test sur « Échec » casserait en en/de/es). `result`/`advice` sont rendus **HTML** par le
> core (valeur `last_command_result` déjà `aseptiser`+`htmlspecialchars` à l'écriture UC18 ⇒ sûre ; rendu
> visuel du lien à confirmer en recette). Le « format attendu par le core pour `health()` » (À confirmer
> de la spec) est **résolu** depuis UC09 (cf. mémoire `jeedom-health-page-contract`).
> **Post-MVP : UC72** — **rate-limiting & anti-ban** (supervision/robustesse, 100 % local, **AUCUN appel
> réseau/MQTT neuf** : ne fait que *borner/tracer* les appels existants) : l'audit a confirmé que la
> quasi-totalité du périmètre anti-ban est **déjà en place** (cooldown 429 global **par compte** fixe
> 900 s respecté par cron ET commandes ; wakeup cooldown 300 s + quota 5/20 min = **AC3** ; debounce
> commandes 10 s + quota global ; quota refresh 5/30 min). **Deux ajouts ciblés dans
> `core/class/stellantis.class.php`** : (1) **anti-rafale du polling** (déplacé depuis UC53) — à la
> cadence par défaut, la flotte tombait due à la **même minute** (0/5/10…) ⇒ rafale ; désormais **gate de
> phase déterministe** `((int) date('i')) % CRON_DEFAUT_STEP !== eqId % CRON_DEFAUT_STEP ⇒ continue`
> (nouvelle const `CRON_DEFAUT_STEP=5`), `$minuteActuelle` **figée 1×/passe** (hoist hors boucle : un
> `/status` lent ne doit pas faire franchir la minute). **Choix assumé vs l'expression cron `%d-59/5`
> suggérée par la spec** : gate modulo (invariant fuseau, testable par lecture) car `php` absent en local
> ⇒ syntaxe cron `A-B/step` non éprouvée (cf. mémoire `jeedom-cron-autorefresh`). `autorefresh`
> personnalisé = **opt-out intégral** (branche `isDue()` inchangée). Offset 0 (`eqId%5==0`) garde le
> planning `*/5` exact ; offsets 1-4 décalés (⚠️ `last_update` observable :00/:05→:01/:06… — à signaler au
> changelog). (2) **Alerte utilisateur sur 429** (**AC2 « avec alerte »**) — nouveau helper
> `public static alerterRateLimit(int $_slot)` (calqué `alerterOtpRequired`/`alerterDaemonAuthFailed`, tag
> **suffixé par slot** `rate_limited_<slot>`, `removeAll`+`add` **sans throttle-key** car
> **edge-triggered** par le cooldown lui-même), appelée depuis le **point unique** `enterRateLimitCooldown()`
> (best-effort try/catch) qui voit **tout** 429 (cron/commandes/OTP), effacée dans la branche succès du
> priming. **Backoff exponentiel volontairement écarté** (décision de cadrage, cf. `72-tech.md`) : seuils
> de ban non documentés (calibrage = supposition), piège de reset identifié (`getToken()` = cache-hit ne
> prouve pas la joignabilité), ROI faible vs quotas existants ; l'alerte fournit désormais la télémétrie
> pour concevoir un vrai backoff plus tard, sur preuve. Chemins de rafale **résiduels documentés comme
> assumés** (hors scope) : `syncVehicles()` (refresh dos-à-dos, cooldown 15 s anti-double-clic seul) et la
> consommation `CMD_PENDING` du cron (bornée par WAKEUP_QUOTA/debounce).
> **Post-MVP : UC73** — **protection de la batterie 12 V / réveil automatique adaptatif** (supervision/
> robustesse, 100 % local, **AUCUN appel réseau/MQTT neuf** : ne fait que *déclencher, sous conditions,*
> le wakeup MQTT existant UC13 en lisant la télémétrie déjà stockée) : c'est **l'exception OPT-IN à la
> règle historique « jamais de wakeup au cron »**, strictement encadrée. Auto-wakeup **désactivé par
> défaut** (config véhicule `auto_wakeup`, **AC1**) et **réservé au compte principal (slot 1)** — pilotage
> à distance mono-compte (UC54). **Cadence adaptative** selon l'état LOCAL du véhicule (**AC2**) via le
> helper **pur** `cadenceAutoWakeupSecondes(chargingStatus, moving, chargeMin, idleMin): ?int` : en
> **roulage** (`moving==1`) ⇒ `null` (déjà réveillé, REST frais, aucun wakeup) ; **en charge**
> (`strcasecmp charging_status 'InProgress'`) ⇒ cadence de charge (défaut 5 min) ; **sinon** (veille) ⇒
> cadence de veille (défaut 60 min). **Clamp `[AUTO_WAKEUP_MIN_PLANCHER = self::WAKEUP_COOLDOWN/60 (=5),
> AUTO_WAKEUP_IDLE_MAX_MIN = 1440]`** min ⇒ « jamais sous les limites UC72 » **garanti par construction**
> même sur saisie hors bornes (0/négative/énorme). Orchestrateur best-effort `declencherAutoWakeupSiDu()`
> (instance, `try/catch \Throwable` englobant, **ne lève jamais**) : gates opt-in → `accountSlotDe()==1`
> (source unique du slot, jamais de lecture brute) → lecture défensive `getCmd('info',charging_status|
> moving)->execCmd()` → cadence → **gate de cadence** (`AUTO_WAKEUP_LAST_KEY.eqId`, TTL
> `AUTO_WAKEUP_IDLE_MAX_MIN*60`) → `wakeup()` (UC13) en try/catch. **Réutilise SANS modification** les
> garde-fous anti-ban de `wakeup()`/`publishRemoteCommand()` (cooldown 300 s per-véhicule + quota GLOBAL
> compte 5/20 min) — **aucun garde-fou dupliqué**. **`AUTO_WAKEUP_LAST_KEY` posé dans TOUS les cas
> (succès ET échec)** ⇒ la cadence fait office de **backoff** (pas de rafale de tentatives chaque minute
> de cron). **Pas de pré-check `hasRemoteToken()`** : `wakeup()` gère lui-même l'alerte OTP canonique
> (`alerterOtpRequired`). Typage des `stellantisException` : `rate_limited`/`otp_required` ⇒ `debug`
> (contention/OTP attendus, non fatals) ; `not_configured`/`transport` ⇒ **warning throttlé 1×/h/véhicule**
> (gabarit `cleWarn`). **Hook `cron()`** : `if ($slot == 1) { $eqLogic->declencherAutoWakeupSiDu(); }`
> placé **après** la garde `tokenOk[$slot]` et **AVANT** le bloc `CMD_PENDING` — évalué **chaque passe**
> (indépendant de la gate de phase du polling REST, qui reste **strictement inchangé**, **AC3**). UI
> desktop (`stellantis.php`) : checkbox `auto_wakeup` (OFF par défaut) + **avertissement VISIBLE du risque
> batterie 12 V** (pas qu'un tooltip, **AC1**) + 2 cadences éditables `auto_wakeup_charge_min`/
> `auto_wakeup_idle_min` (repli défauts si vides, helpers `cadenceChargeMin/IdleMin`). ⚠️ **Limite assumée
> documentée** (`73-tech.md`) : le quota wakeup est GLOBAL au compte ⇒ plusieurs VE en charge simultanée à
> cadence 5 min saturent le plafond compte (5/20 min) ⇒ certaines passes refusées (backoff) ; choix : le
> quota UC72 EST le plafond dur (anti-ban prioritaire), pas de répartition équitable (futur UC si besoin
> confirmé en recette). Reviews croisées : sécurité **LOW**, qualité **PASS** (fidélité spec point par
> point).
> **Post-MVP : UC74** — **renouvellement & alertes de token** (supervision/robustesse, 100 % local,
> **AUCUN appel réseau/MQTT neuf** : ne fait qu'*alerter* sur un état déjà détecté) : l'audit a confirmé
> que le périmètre est **déjà en place à ~95 %** — « sans boucle d'appels » (AC1) garanti par construction
> (`refreshToken()` sur `invalid_grant` **supprime le token cache** ⇒ toute passe suivante lève
> `auth_required` **sans réseau** ; véhicules du slot **sautés** au cron via `tokenOk[$slot]=false`) ;
> **remote token expiré → `otp_required` + `alerterOtpRequired()` sans régénération OTP auto** (AC2) déjà
> livré (UC12) ; **états visibles** (bandeau `connectionState()` page plugin, `health()` page Santé, état
> OTP page config) (AC3) déjà là. **Un seul livrable code** (`core/class/stellantis.class.php`, +26 l.) :
> comblement du **`message::add` manquant** pour l'OAuth (la spec liste « log warning + **message** +
> Santé », l'OTP l'avait, pas l'OAuth) — nouvelle méthode `alerterAuthRequired(int $_slot)` (famille des
> helpers `alerter*`, `removeAll`+`add`, **tag suffixé par slot** `auth_required_<slot>` comme
> `rate_limited_<slot>`, **sans `log::add`** car le warning « Mode dégradé » est déjà émis inline par le
> cron dans la **même garde**), appelée depuis `cron()` **à l'intérieur de la garde `degraded_warn`
> existante** (throttle 1×/h/compte **réutilisé**, jamais sur transport/rate_limited ⇒ **pas de « cri au
> loup »**), effacée dans `stellantisApi::storeTokenResponse()` (**point unique de recouvrement** : ré-auth
> manuelle `exchangeCode` **et** refresh auto `refreshToken`, feedback immédiat). ⚠️ **Limite assumée
> documentée** (`74-tech.md` + `stellantis-api-architecture.md` § 4.5) : message/flags par slot
> **orphelins** si un compte secondaire est **totalement déconfiguré** (hooks `preConfig_*` ne purgent que
> le token, cron n'itère plus le slot) — **limitation structurelle préexistante symétrique** à
> `rate_limited_<slot>`/`link_error` (pas une régression UC74 ; correctif = futur nettoyage transverse
> multi-comptes, pour **tous** les tags à la fois). Reviews croisées : sécurité **RAS**, qualité **PASS**
> (1 finding *minor* = l'orphelin ci-dessus, documenté).
> **Post-MVP : UC75** — **mode privacy du véhicule (« Plane Mode »)** (supervision/robustesse, 100 %
> local, **AUCUN appel réseau/MQTT neuf** : exploite le champ `privacy.state` déjà présent dans le
> `/status` récupéré au cron) : détecter/gérer que l'utilisateur a coupé Data/Géoloc côté voiture (l'API
> devient muette **indépendamment du plugin**) — l'expliquer, pas le traiter comme une panne ni marteler
> en retry. Le **socle existait depuis le MVP** (`refreshTelemetry` lit `privacy.state`, cache
> `stellantis::privacy::<eqId>` 2 j, **skip `/lastPosition`** si `≠ None`, privacy exclu de
> `connectionState()`, ligne page Santé) ; UC75 **complète** avec 3 livrables, dans le seul
> `core/class/stellantis.class.php`. (1) **Info `privacy_mode`** (binary **historisée**, `generic_type=''`,
> déclencheur de scénario natif — précédent `at_home`/`tyre_alert`/`opening_alert`) : **création
> paresseuse** (jamais dans `createCommands`), mappée dans `parseStatus()` via un **nouveau 3ᵉ paramètre
> `$_privacy` PASSÉ par l'appelant** (jamais ré-extrait — `parseStatus` reste pur, le defaulting `'None'`
> vit dans `refreshTelemetry`, précédent `$_position`) et **mapping INCONDITIONNEL** (`strcasecmp ≠ 'None'
> ? 1 : 0`, jamais gardé par `isset`) ⇒ toujours 0/1, **retombe toujours à 0 en sortie de privacy**.
> Contrat `privacy.state` enum `None`/`Geolocation`/`Full` (data-model § 2.6 — « À confirmer » de la spec
> **clos** : champ explicite, pas d'heuristique floue « réponses vides »). (2) **Message d'aide
> edge-triggered** : nouvelle méthode best-effort `suivrePrivacy(string $_privacy)` (try/catch `\Throwable`,
> ne lève jamais) qui **centralise l'écriture du cache** (remplace le `cache::set` inline, même clé +
> nouvelle const `PRIVACY_CACHE_TTL=172800`) ET détecte la transition en lisant la valeur **précédente
> AVANT écrasement** : None→actif ⇒ `message::removeAll`+`add` (tag **par véhicule** `privacy_<eqId>`,
> texte « réactivez le partage de données côté véhicule, ce n'est pas une panne ») ; actif→None ⇒
> `removeAll` ; stable ⇒ rien. ⚠️ **Sécurité** : le nom du véhicule injecté (`$this->getName()`, dérivé du
> `label` externe renommable dans l'app mobile) est **neutralisé** `htmlspecialchars(self::aseptiser(...),
> ENT_QUOTES,'UTF-8')` avant `message::add` (convention UC18/UC51 ; finding review HIGH corrigé —
> `getName()` est une **donnée tainted**). Nettoyage `preRemove()` (message + cache) — asymétrie assumée
> vs les autres caches auto-purgés par TTL (un `message::add` orphelin est visible). (3) **Cadence de
> polling réduite** en privacy — const `CRON_PRIVACY_STEP=30` (multiple de `CRON_DEFAUT_STEP=5`, divise
> 60) : dans `cron()`, branche cadence **par défaut uniquement**, gate de phase modulo à **pas variable**
> (`$privacyActif ? 30 : 5`, `$privacyActif` lu du cache) ⇒ ~1 poll/30 min en privacy (économie
> quota/anti-ban), on continue de sonder pour **détecter la sortie** (latence ≤ 30 min). ⚠️ **Limites
> assumées** : cadence custom `autorefresh` = opt-out intégral (précédent UC72) ; auto-wakeup (UC73) &
> refresh post-ack `CMD_PENDING` (UC18) **contournent** la gate (rafale résiduelle assumée) ; possible
> re-notification sur expiration TTL/`cache::flush()` (précédent UC74). Reviews croisées : sécurité **RAS**
> (après fix HIGH), qualité **PASS** (1 nit cosmétique résiduel non bloquant : commentaire `preRemove` citant
> la valeur littérale du TTL).
> **Post-MVP : UC76** — **synchronisation sélective par véhicule** (supervision/robustesse, 100 % local,
> **AUCUN appel réseau/MQTT neuf**) : deux concepts **orthogonaux**. (1) **Inclusion dans le rafraîchissement
> auto** — nouvelle config eqLogic `syncEnabled` (checkbox « Inclure dans le rafraîchissement auto »,
> **défaut 1 / opt-out**, backfill `assurerSyncEnabledParDefaut()` miroir d'`assurerVisiblePanelParDefaut` +
> `install.php`) : `cron()` **saute** le véhicule décoché — gate `if (!getConfiguration('syncEnabled',1))
> continue;` placée **APRÈS** le bloc `CMD_PENDING` (un refresh post-commande est la conséquence d'une action
> explicite, **jamais gaté**) et **AVANT** la branche cadence ; l'auto-wakeup UC73 est **aussi** gaté (pas de
> réveil batterie 12 V inutile). L'eqLogic reste `isEnable=1` (visible, dernières valeurs conservées) et une
> ligne page Santé « Rafraîchissement automatique désactivé » (state=true, avant le bloc privacy) l'explique.
> La cadence `autorefresh` par véhicule existait **déjà** (inchangée). (2) **Véhicule disparu** —
> `syncVehicles()` **désactive** (jamais supprime) un véhicule absent de la découverte (déjà en place, filtré
> par compte UC54) et pose un **marqueur `autoDisabled`** ; à la **réapparition**, un véhicule
> **auto-désactivé** (`autoDisabled==1`) est **réactivé automatiquement** (compteur `$reactivesAuto`), tandis
> qu'une désactivation **manuelle** — ou héritée d'avant UC76, **sans marqueur** — est **respectée** (jamais
> réactivée, **pas de réactivation en masse à l'upgrade**). ⚠️ **Robustesse du marqueur** : `preSave()`
> (jusqu'ici vide) efface `autoDisabled` sur **toute bascule manuelle de `isEnable`** (formulaire OU toggle
> rapide de la liste) — comparaison `eqLogic::byId()` (**requête DB fraîche** ⇒ ancienne valeur en preSave,
> **vérifié en source core**) vs valeur courante, dans un `try/catch \Throwable` (**ne bloque JAMAIS** un
> `save()`) ; une **garde statique transitoire** `self::$synchroEnCours` (posée en **`try/finally`** autour des
> `save()` de `syncVehicles`) empêche `preSave` d'effacer le marqueur que la synchro vient elle-même de poser
> (cf. mémoire `jeedom-eqlogic-presave-change-detection`). Le marqueur `autoDisabled` = **serveur uniquement**
> (pas de champ formulaire ; survit au save via la fusion `a2o()`, dégradation fail-safe si effacé). **Sync
> manuel** (bouton) rafraîchit **tous** les véhicules découverts, y compris `syncEnabled=0` (action explicite
> ≠ polling auto). Reviews croisées : sécurité **RAS**, qualité **PASS** (findings mineurs corrigés : docblock/
> commentaire de retour, libellé UI différencié de « Auto-actualisation », renommage `$reactivesAuto`).
> **Post-MVP : UC77** — **statistiques d'appels API** (supervision/robustesse, 100 % local, **AUCUN appel
> réseau/MQTT neuf** : ne fait qu'*instrumenter/compter* les appels REST déjà émis) : compteur au **point
> unique HTTP `stellantisApi::httpRequest()`**, inséré **juste après la garde d'échec transport** (⇒ compté
> **uniquement après réponse serveur**, 2xx **ET** erreurs 4xx/5xx = signal anti-ban ; jamais sur échec
> transport). Compte **tout le trafic REST** (télémétrie `call`, OAuth `requestToken`, **OTP = face REST des
> commandes** `smsCode`/`token`). Persistance `cache` **cloisonnée par compte** (UC54, `cacheKeyForSlot`) :
> clé jour `stellantis::stats::day::AAAA-MM-JJ` → `{total, byEndpoint}` (TTL ~8 j, **auto-purge** ⇒ pas
> d'orphelin multi-comptes) + clé minute `::min::AAAA-MM-JJ HH:MM` (TTL 120 s) pour la **dérive**
> edge-triggered (`compteMinute === STATS_DERIVE_SEUIL` ⇒ **log `warning` une seule fois/min/compte** ;
> seuil **60 = ESTIMATION non calibrée** assumée façon UC72, cas de recette dans `81-validation-manuelle.md`).
> Normalisation d'endpoint pure `normaliserEndpoint()` : `parse_url(PHP_URL_PATH)` (retire host + query, dont
> le `client_id` ⇒ **jamais de secret dans le label**) + **un seul** remplacement ciblé du seul segment
> variable de l'API `#/user/vehicles/[^/]+#` → `/user/vehicles/{id}` (pas de whitelist fragile ; un futur
> endpoint dégrade proprement). `compterAppel()` **intégralement `try/catch \Throwable`** (AC3 : jamais
> d'exception vers l'appel métier) ; RMW cache **non-atomique** assumé (précédent `consommerQuotaRefresh`) ;
> cap défensif `STATS_MAX_ENDPOINTS=50` (bucket `(autres)`, précédent `ALERT_MAX_TYPES` UC43). **Restitution
> sur 2 surfaces** via `stellantis::recapStatistiquesApi()` (lecture cache seule, agrégat par
> `slotsConfigures()` → `{today:{total,byEndpoint}, total_periode, par_compte}`, appelée par `health()` **et**
> la page plugin — jamais `stellantisApi::` direct depuis un point d'entrée externe, règle autoload) : ligne
> `health()` « Appels API REST (aujourd'hui) » (total + top 3 endpoints, ventilation par compte si
> multi-comptes, `htmlspecialchars`, state informatif) + bloc `desktop/php/stellantis.php` « Consommation de
> l'API REST » (jour + 7 j + détail par endpoint). ⚠️ **Limites assumées** (`77-tech.md`) : **commandes MQTT
> hors périmètre** du compteur (publish *fire-and-forget*, ne passe pas par `stellantisApi`, aucune réponse
> serveur synchrone ⇒ libellés volontairement « **API REST** » ; volume MQTT déjà borné par quotas
> wakeup/debounce dédiés) ; **`downloadToFile()`** (APK GitHub, UC61) **non compté** (chemin cURL séparé,
> pas l'API PSA — commenté dans son docblock). Reviews croisées : sécurité **RAS**, qualité **PASS** (1
> finding minor cosmétique i18n corrigé). Alimente UC71 (Santé) & UC72 (anti-ban).
> **Post-MVP : UC81** — **recette fonctionnelle manuelle complétée** (domaine livraison, livrable **100 %
> documentaire** : aucun code, aucun appel API, aucune chaîne UI ; le seul artefact est le fichier
> `.memory/specs/post-mvp/80-livraison/81-validation-manuelle.md`, doc **vivant** repris à chaque
> `/feature`) : satisfaction de l'unique critère d'acceptation *« chaque UC livrée a au moins un scénario
> de recette observable »* — **28 scénarios ajoutés** pour les UC livrées jusque-là non couvertes (UC12-18,
> 21, 22, 24, 31-34, 41-44, 51-54, 61, 71, 74, 75, 76, 83), les blocs déjà présents (Auth, Token,
> Découverte, Télémétrie, Robustesse, UC11, UC19, UC23, UC72, UC73, UC77) **préservés mot pour mot**. La
> checklist est **réorganisée en sections `###` par domaine** (calquées sur le `README.md` des specs :
> Socle MVP → 10-commandes → 20-énergie → 30-localisation → 40-entretien → 50-gestion → 60-config →
> 70-supervision → 80-livraison) ; le placeholder vague « Commandes (12-x) » est **remplacé** par les blocs
> complets UC12→18 ; UC23 déplacé en 20-énergie. Une **section « Conventions de ce document »** en tête fige
> les règles pour les futurs cycles (ordre domaine+UC ; gabarit de bloc **avec date « ajouté »
> obligatoire** ; **UC doc-only = pointer vers l'existant, jamais fabriquer** un scénario — appliqué à UC53
> qui renvoie à Découverte 05-06 + Anti-ban 72 + UC54 ; **écart spec-vs-réel = annoter `⚠️` + assertion
> vérifiable positive**). ⚠️ **Principe directeur** : un scénario s'ancre sur le **comportement réellement
> implémenté** (code + cette note), **pas** sur le texte *cible* d'une spec figée avant codage — écarts
> reflétés fidèlement (UC42 : aucune commande « pression par roue / en bar », que le binaire `tyre_alert` ;
> UC22 : aucun seuil % ; UC18 : aucun re-publish auto sur code 400 ; UC51 : aucune commande `vin`/`model` ;
> UC44 : 8 `door_<id>` statiques + `opening_alert` ; UC33 : 8 infos trajet). **Dette signalée, hors
> périmètre** (ligne NB + `81-tech.md`) : les en-têtes « Statut » de ~25 specs fonctionnelles livrées sont
> **périmés** (« à spécifier ») → **commit séparé** mécanique ultérieur (ne pas diluer la revue de 81).
> Reviews : **sécurité sans objet** (aucun code) + garde anti-fuite (grep VIN/token) OK ; **qualité PASS**
> (2 findings minor corrigés : flag `otp_sms_pending` ≠ compteur `otp_sms_count` ; liste des blocs datés de
> `81-tech.md` rectifiée). **i18n sans objet** (fichier interne FR). Spec technique : `81-tech.md`.
> **Post-MVP : UC82** — **packaging & documentation utilisateur** (domaine livraison) : deux volets. (A)
> **Packaging VÉRIFIÉ, non modifié** — `packages.json` (**`paho-mqtt` 1.6.1** pin exact, `requests` 2.32.3,
> `pycryptodomex` 3.20.0) et `info.json` (category `devicecommunication`, `require 4.2`, os 10–12.99,
> `hasDependency/hasOwnDeamon:true`, 4 langues, compat) étaient déjà corrects (AC1 satisfait par
> construction, post-MVP) ; ⚠️ le texte de la spec fonctionnelle (`<2.0.0`, `cryptography`, `jeedomdaemon`)
> était **périmé** et a été corrigé dans `82-packaging-doc.md` (Volet D) pour refléter le réel (pin exact,
> `pycryptodomex`, démon = squelette `demond.py` + lib `jeedom/`). Dette signalée non traitée :
> `link.forum`/`link.video` d'`info.json` restent des placeholders (à remplir avant soumission store). (B)
> **Doc utilisateur `docs/<langue>/index.md` complétée** (livrable principal, AC2) : correction d'une
> **erreur factuelle livrée dans les 4 langues** (« le plugin ne télécharge/analyse aucun APK » — faux
> depuis UC61) ; **« Obtenir les identifiants » restructuré** en Méthode 1 (extraction automatique
> in-Jeedom UC61, recommandée, avec nuance box/Python/~100 Mo) / Méthode 2 (repli manuel `psa_car_controller`) ;
> **sections ajoutées** : « Avertissement — API non officielle & risques (ToS) », « Pilotage à distance —
> activation OTP » (3 étapes ancrées sur l'UI réelle + quotas 6/24 h & 20 SMS/vie + renouvellement sans SMS,
> slot 1 uniquement), « Comptes secondaires (lecture seule) », « Fonctions disponibles », « Limites & bonnes
> pratiques » (fraîcheur ~5 min sans push, batterie 12 V, anti-ban, privacy, mono-compte). ⚠️ Fait API
> capitalisé : le **remote token** a un TTL ~890 s **auto-renouvelé par le cron sans consommer de quota**
> (`syncDaemonToken→refreshRemoteToken`), tandis que le bouton **« Renouveler le jeton distant »**
> (`renewRemoteToken`) **génère un code OTP consommant 1 unité du quota 6/24 h** (distinct du quota SMS
> 20/vie). (C) **Cohérence UI `configuration.txt`** : chaînes « outil externe » (alerte + tooltips Client
> ID/Secret, comptes principal & secondaires) reformulées pour ne plus contredire le bouton « Extraire
> automatiquement » (UC61), `.php` re-synchronisé. Reviews croisées : **sécurité RAS** (guillemets français
> `« »` dans les `title="..."` → pas d'injection ; aucun secret dans la doc), **qualité PASS après
> corrections** (1 major = cycle de vie du jeton distant factuellement faux/contradictoire, corrigé ; 3
> minor corrigés). Traduction en/de/es : **3 docs réécrites section par section** (erreur APK corrigée dans
> les 3 langues) + i18n `configuration.txt` (3 clés modifiées + lacune « Tester la connexion » comblée), 3
> JSON valides. Spec technique : `82-tech.md`.
> **Post-MVP : UC84** — **internationalisation (multilingue), passe de complétude & certification**
> (domaine livraison, UC « vivante ») : audit i18n transverse (les 3 `core/i18n/*.json` étaient déjà
> **symétriques**, 371 clés — maintenance continue du sous-agent `translator` OK) + comblement des 3
> écarts résiduels. (1) **AC3 — `description` d'`info.json` en 4 langues** (gap jamais clos) : ajout
> `en_US`/`de_DE`/`es_ES` au **dict inline du manifeste** (mécanisme réel confirmé doc Jeedom
> `structure_info_json` : objet à clés de langue **dans `info.json`**, **PAS** une section `"info.json"`
> des `core/i18n/*.json` — ⚠️ **l'exemple i18n de `CLAUDE.md` était faux**, corrigé), chacune ≥ 80 car.
> (règle market). (2) **AC1 — zéro chaîne UI en dur** : sweep systématique (élargi au HTML **concaténé en
> JS** + `confirm`/`bootbox.confirm`/`showAlert` + attributs `placeholder`/`title`/`alt`) → 2 hardcodés
> enveloppés — `desktop/js/stellantis.js:130` `placeholder="{{Unité}}"` (clé préexistante) et
> `desktop/php/stellantis.php:239` `title="{{Assistant cron}}"` (**nouvelle** clé traduite) ; + clé
> `401 - Accès non autorisé` (`modal.stellantis.php`) comblée. (3) **AC2 — hygiène orphelines PAR
> (section, clé)** : suppression de 2 clés mortes (`Connexion au compte`@class, `Ajouter`@stellantis.php)
> en **conservant** `Connexion au compte`@configuration.php (**vivante** — un même texte FR peut exister
> dans 2 sections ⇒ jamais de suppression par texte global). Reviews croisées : **sécurité RAS**,
> **qualité PASS** (0 finding). Spec technique : `84-tech.md`.
> Suite = post-MVP (supervision, robustesse, livraison…).
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
  **chiffré**, jamais loggué. **UC54 (multi-comptes, LECTURE seule)** ajoute des **slots de comptes fixes**
  (`stellantis::MAX_ACCOUNTS=3`) : le **slot 1 = les clés ci-dessus (NON suffixées)** = compte principal
  (pilotage à distance) ; les slots 2..N ajoutent les clés **suffixées** `brand_N`/`client_id_N`/
  `client_secret_N`/`country_N`/`redirect_uri_N` (comptes secondaires **lecture seule**). `client_secret_2`/
  `client_secret_3` sont dans `$_encryptConfigKey`. Nommage via `configKeyForSlot($base,$slot)` (non suffixé
  si slot≤1). OTP/CID/broker/démon restent **mono-compte (slot 1)**.
- **Par véhicule** (`configuration` de l'eqLogic) : `id` API, `vin`, `brand`, motorisation, capacités.
  **UC54** ajoute `accountSlot` (slot du compte de rattachement, autorité = découverte, défaut 1 ; **champ
  hidden `eqLogicAttr`** dans le formulaire desktop pour survivre au save — clé de routage critique) +
  `accountSlotLabel` (libellé lisible readonly).
  **UC24** ajoute 2 champs **saisis manuellement** (formulaire desktop, éditables) : `battery_capacity`
  (capacité batterie kWh, sert à estimer l'énergie de charge) et `charge_tarif` (prix du kWh €, coût estimé).
  **UC32** ajoute `isVisiblePanel` (case du formulaire desktop, défaut 1 posé par le plugin — inclut le
  véhicule dans le panneau carte « Mes véhicules »). **UC41** ajoute 2 champs **éditables** (formulaire
  desktop) : `service_alert_km` / `service_alert_days` (seuils « révision proche » par véhicule ; repli sur
  défauts 1000 km / 30 j via `seuilAlerteKm/Jours()` si vides). **UC73** ajoute 3 champs **éditables**
  (formulaire desktop, opt-in réveil auto adaptatif, slot 1 uniquement) : `auto_wakeup` (case, **OFF par
  défaut**), `auto_wakeup_charge_min` / `auto_wakeup_idle_min` (cadences en charge/veille min ; repli
  défauts 5 / 60 min via `cadenceChargeMin/IdleMin()` si vides). **UC76** ajoute `syncEnabled` (case
  « Inclure dans le rafraîchissement auto », défaut 1 posé par `assurerSyncEnabledParDefaut()` — décochée =
  véhicule exclu du polling cron + auto-wakeup, mais **reste activé/visible**) + le marqueur **serveur**
  `autoDisabled` (**pas de champ formulaire** ; distingue une désactivation auto-par-le-plugin d'une
  désactivation manuelle, pour la réactivation auto à la réapparition d'un véhicule disparu).
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
  { "plugins/stellantis/<chemin/relatif/fichier>": { "Texte français": "Traduction" } }
  ```
- ⚠️ **Exception `info.json` — mécanisme DISTINCT** (confirmé doc Jeedom `structure_info_json`, UC84) :
  la `description` (et `name`) du manifeste se traduit via un **objet à clés de langue INLINE dans
  `plugin_info/info.json`** — `"description": {"fr_FR": …, "en_US": …, "de_DE": …, "es_ES": …}` — **PAS**
  via une section `"info.json"` des fichiers `core/i18n/*.json` (ne jamais en créer une). `name` =
  « Stellantis » (nom propre, non traduit). `description` fournie pour **les 4 langues**, **≥ 80
  caractères** chacune (règle market Jeedom) ; docs dupliquées par langue.
- **Règle d'or** : toute clé UI **livrée** doit avoir ses **3 traductions**. *Quand* les produire :
  - **Dans `/feature`** : traduction faite **en fin de cycle par le sous-agent `translator`** (code figé,
    contexte isolé) ; pendant le dev on enveloppe en français mais on **ne touche pas** aux `*.json`.
  - **Hors workflow** : ajouter/mettre à jour la clé dans les 3 fichiers dès qu'on l'introduit.
