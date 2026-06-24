# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Présentation

Plugin **Jeedom** (id `imou`, catégorie `security`) qui pilote des **caméras IMOU** :
allumer/éteindre, activer/désactiver la surveillance, PTZ, et à terme projecteur/sirène,
alarmes, stockage, etc.

Le pilotage se fait en appelant directement l'**IMOU Open API** (cloud), **en PHP natif**,
**sans démon et sans Python** — décision d'architecture documentée dans
`.memory/analyse/imou-api-vs-imouapi.md` (« Solution A »). L'API IMOU n'offre pas de flux push :
l'état des caméras est rafraîchi par **polling** dans le cron Jeedom.

Un plugin Jeedom **n'est pas autonome** : il s'installe dans un serveur Jeedom sous
`<jeedom>/plugins/imou/`, et tout le PHP dépend du core Jeedom, atteint via l'include relatif
`require_once __DIR__ . '/../../../../core/php/core.inc.php';`. Pas de build local ; la
validation se fait en CI (voir « Workflows / CI »).

## Feuille de route & specs

L'implémentation est découpée en petites tâches/UC, **à lire avant de coder une fonctionnalité** :

- `.memory/specs/README.md` — index, MVP, roadmap, conventions, statut de fiabilité des endpoints.
- `.memory/specs/MVP/01..10` — socle livrable en premier (ordre strict) : config → client HTTP →
  token → test → découverte → équipements → commandes → on/off → surveillance → cron.
- `.memory/specs/post-mvp/` — UC suivantes par domaine (pilotage avancé, vidéo/images, alarmes,
  stockage, gestion appareils, contrôle d'accès, supervision/robustesse, livraison).

État du code : **squelette** (classes `imou`/`imouCmd` quasi vides). La logique est à implémenter
en suivant les specs ; ne pas considérer les fichiers actuels comme une fonctionnalité finie.

## Architecture

Disposition Jeedom fixe (type MVC). Pièces principales, toutes nommées d'après l'id `imou` :

- **`core/class/imou.class.php`** — le cœur. Deux classes :
  - `imou extends eqLogic` — une instance par caméra/canal (clé `logicalId = "<deviceId>_<channelId>"`).
    Hooks de cycle de vie appelés par Jeedom : `preSave/postSave`, `preRemove/postRemove`, etc.
    Crons (`cron`, `cron5`, …) appelés par le planificateur ; `cron5()` portera le polling d'état.
    `$_encryptConfigKey` chiffre les champs sensibles.
  - `imouCmd extends cmd` — une commande (info ou action). `execute($_options)` exécute les actions
    (allumer/éteindre, surveillance, PTZ…) en appelant l'API.
- **Client API `imouApi`** (à créer dans `core/class/imou.class.php`, cf. `.memory/specs/MVP/02`,`03`) —
  **brique unique** par laquelle passent tous les appels HTTP : signature
  `md5("time:…,nonce:…,appSecret:…")`, enveloppe `system`, POST cURL, gestion du token (cache + refresh),
  mapping des erreurs. Aucune autre partie du code ne doit appeler l'API directement.
- **`desktop/php/imou.php`** — page de configuration admin (HTML), protégée par `isConnect('admin')`.
  Liste les équipements + formulaire de config. Liaison au modèle via `data-l1key`/`data-l2key`
  (ex. `data-l1key="configuration" data-l2key="deviceId"`). i18n via `{{...}}`. Se termine en
  incluant le JS du plugin puis le JS générique de page plugin **fourni par le core**
  (`include_file('core', 'plugin.template', 'js')` → `core/js/plugin.template.js`, asset du core,
  à ne pas modifier).
- **`desktop/js/imou.js`** — comportement front-end de la page (lignes de commandes, tri, helpers `jeedom.*`).
- **`core/ajax/imou.ajax.php`** — endpoint AJAX : inclut le core, vérifie `isConnect('admin')`,
  `ajax::init()`, puis aiguille sur `init('action')`. Les actions front (test de connexion,
  synchronisation des caméras…) s'ajoutent ici en branches `if (init('action') == '...')`.

- **`plugin_info/configuration.php`** — formulaire de la page de config plugin (ouverte par
  `gotoPluginConf`). Champs liés en `class="configKey" data-l1key="<clé>"` (auto-load/save core
  via `config::byKey/save(..., 'imou')`).

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
- Identifiants développeur IMOU (`appId`, `appSecret`, datacenter) stockés au **niveau plugin**
  via `config::save/byKey(..., 'imou')` ; `appSecret` chiffré, jamais loggué.
- `deviceId`/`channelId`/capacités stockés dans la `configuration` de chaque eqLogic.
- Token mis en cache via la classe `cache` de Jeedom.

Support :
- **`plugin_info/info.json`** — manifeste (id, version, `require`, OS, `category`, dépendances, langues, compat).
- **`plugin_info/install.php`** — `imou_install/update/remove()` au cycle de vie du plugin ;
  `pre_install.php` → `imou_pre_update()`.
- **`plugin_info/packages.json`** — dépendances installées par Jeedom. La cible étant 100 % PHP,
  ce fichier doit rester minimal (cf. `.memory/specs/post-mvp/80-livraison/82-packaging-doc.md`).

## Workflows / CI

CI déléguée aux workflows réutilisables de Jeedom (`jeedom/workflows`) :
- **`.github/workflows/work.yml`** — check complet du plugin sur push/PR vers `beta`, PR vers `master`.
- **`.github/workflows/prettier.yml`** — pousser sur la branche **`prettier`** déclenche un bot qui
  reformate le code et commite sur cette branche (moyen de formatage automatique).

Pas de commande de lint/test locale ; la validation tourne dans ces workflows contre un Jeedom réel.
Recette fonctionnelle manuelle : `.memory/specs/post-mvp/80-livraison/81-validation-manuelle.md`.

## Conventions

- **Français = langue source** : code, commentaires, noms de variables, messages de `log::add`
  et chaînes UI sont écrits en français. Le français est aussi la langue **par défaut** de Jeedom
  (aucun fichier `core/i18n/fr_FR.json` n'est nécessaire — la source EST la traduction française).
- Indentation 2 espaces (PHP/JS).
- Logs via `log::add('imou', 'debug'|'info'|'warning'|'error', $msg)` ; **jamais** de secret/token en clair.
- Les `.htaccess` de `core/php`, `core/class`, `core/ajax`, … interdisent l'accès web direct — les conserver.
- `docs/<langue>/` = documentation **utilisateur** du plugin (une langue par dossier) ;
  `.memory/` = analyse & specs **internes** (français uniquement, jamais traduites).
  > ℹ️ Ce dossier de connaissance interne s'appelle désormais **`.memory/`** (dossier caché), renommé
  > depuis `memory/`. Toutes les références (specs, analyse, doc externe, `/feature`, skill `dev`)
  > pointent sur `.memory/`. **Ne plus utiliser `memory/`** ni présumer son existence : lire/écrire
  > la connaissance interne sous `.memory/` (`.memory/specs/`, `.memory/analyse/`, `.memory/external/`).

## Internationalisation (i18n) — natif multilingue

Le plugin est **nativement multilingue**. Langues supportées (cf. `plugin_info/info.json` → `language`) :
**`fr_FR` (source/défaut)**, **`en_US`**, **`de_DE`**, **`es_ES`**.

Mécanisme Jeedom :
- Toute chaîne destinée à l'utilisateur est **enveloppée** : `{{Texte français}}` en HTML/JS,
  `__('Texte français', __FILE__)` en PHP. La clé est **toujours le texte source français**.
- Les traductions vivent dans `core/i18n/<langue>.json`, **un fichier par langue cible**
  (`en_US.json`, `de_DE.json`, `es_ES.json` — **pas** de `fr_FR.json`). Format :

  ```json
  {
    "plugins/imou/<chemin/relatif/fichier>": { "Texte français": "Traduction" },
    "info.json": { "Description française": "Traduction" }
  }
  ```

- La `description` de `plugin_info/info.json` est fournie pour **les 4 langues**.
- Les docs sont **dupliquées par langue** : `docs/fr_FR/`, `docs/en_US/`, `docs/de_DE/`, `docs/es_ES/`.

**Règle d'or — toute clé UI doit, *in fine*, avoir ses 3 traductions** (`en_US.json`, `de_DE.json`,
`es_ES.json`), sous le bon chemin `plugins/imou/<fichier>` : une clé **livrée** sans ses 3 traductions
est un **défaut bloquant**. *Quand* les produire dépend du contexte :
- **Dans le workflow `/feature`** : la traduction est faite **en fin de cycle par le sous-agent
  `translator`** (sur le code figé, contexte isolé). Pendant le dev, on **enveloppe** les chaînes en
  français mais on **ne touche pas** aux `*.json` — c'est volontaire (économie de contexte, pas de
  re-traduction de chaînes qui changent encore).
- **Hors de ce workflow** : ajouter/mettre à jour la clé dans les 3 fichiers dès qu'on l'introduit ou
  qu'on la modifie (sinon la traduction devient orpheline ou manquante).
