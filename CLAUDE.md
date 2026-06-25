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
    canal MQTT persistant + OTP justifie un démon (contrairement à une API purement REST). On réactive
    alors `resources/` et `hasOwnDeamon:true`.

Un plugin Jeedom **n'est pas autonome** : il s'installe sous `<jeedom>/plugins/stellantis/`, et tout le
PHP dépend du core Jeedom, atteint via `require_once __DIR__ . '/../../../../core/php/core.inc.php';`.
Pas de build local ; la validation se fait en CI (voir « Workflows / CI »).

> **État du code (2026-06-25)** : le dépôt est encore le **squelette `plugin-template` de Jeedom**
> (classes `template`/`templateCmd`, `info.json` id `template`). **Rien n'est implémenté.** Première
> étape technique : renommer l'id `template` → `stellantis` (assistant `plugin_info/helperConfiguration.php`,
> ou rename manuel cohérent sur tous les fichiers/classes/hooks). Toute la suite suit les specs.

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
    de vie (`preSave/postSave`, `preRemove/postRemove`…) ; crons (`cron`/`cron5`) → **polling REST** de la
    télémétrie ; `$_encryptConfigKey` chiffre les champs sensibles.
  - `stellantisCmd extends cmd` — commande (info ou action). `execute($_options)` exécute les actions
    (réveil, charge, préconditionnement, verrouillage…) — **post-MVP**, en passant par le démon MQTT.
- **Client API `stellantisApi`** (à créer dans `core/class/stellantis.class.php`, cf. `MVP/02`,`03`) —
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
- **`resources/demond/`** — **démon Python MQTT** (post-MVP commandes uniquement). Réutilise le squelette
  `demond.py` + lib `jeedom/` (socket `jeedom_socket` PHP→démon, `jeedom_com` démon→Jeedom). MVP : dossier
  non utilisé (`hasOwnDeamon:false`).

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
  realm `clientsB2C…`), `client_id`, `client_secret` (extraits de l'APK par l'utilisateur via un outil
  externe — cf. analyse), `redirect_uri`. `client_secret` **chiffré**, jamais loggué.
- **Par véhicule** (`configuration` de l'eqLogic) : `id` API, `vin`, `brand`, motorisation, capacités.
- **Tokens** OAuth2 (access/refresh) + (post-MVP) remote token OTP : en cache **chiffré** (classe `cache`).
  ⚠️ `access_token` à durée courte (~15 min) → refresh proactif/réactif.

Support :
- **`plugin_info/info.json`** — manifeste (id, version, `require`, OS, `category`, dépendances, langues, compat).
- **`plugin_info/install.php`** — `stellantis_install/update/remove()` ; `pre_install.php` → `stellantis_pre_update()`.
- **`plugin_info/packages.json`** — dépendances. **MVP : vide** (100 % PHP). **Post-MVP commandes** :
  `pip3 paho-mqtt` (cf. `.memory/specs/post-mvp/80-livraison/82-packaging-doc.md`).

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
