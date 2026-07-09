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

> **État d'avancement (2026-07-09)** : l'id a été renommé `template` → `stellantis` (classes
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
> `pushDaemonConnect/syncDaemonToken/handleDaemonMessage`). Suite = post-MVP (commandes métier UC13-17,
> retour d'état UC18, énergie/charge, localisation, entretien…). Cette note est
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
  - `stellantisCmd extends cmd` — commande (info ou action). `execute($_options)` exécute les actions
    (réveil, charge, préconditionnement, verrouillage…) — **post-MVP**, en passant par le démon MQTT.
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
- **`core/ajax/stellantis.ajax.php`** — endpoint AJAX (admin-only) : inclut le core, `isConnect('admin')`,
  `ajax::init()`, aiguillage sur `init('action')` (ex. génération de l'URL OAuth, soumission du `code`,
  test de connexion, synchronisation des véhicules) en branches `if (init('action') == '...')`.
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
  attente »). `client_secret` **chiffré**, jamais loggué.
- **Par véhicule** (`configuration` de l'eqLogic) : `id` API, `vin`, `brand`, motorisation, capacités.
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
