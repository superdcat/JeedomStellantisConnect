# Spec technique — 84 (Internationalisation / multilingue)

> **Nature** : UC « vivante » du domaine Livraison. Passe de **complétude & certification i18n**
> transverse. **Aucun** appel API Stellantis/PSA (REST ou MQTT), **aucune** nouvelle logique runtime.
> Contenu statique traduit uniquement.
>
> **État constaté à l'audit (2026-07-18)** : les 3 fichiers `core/i18n/{en_US,de_DE,es_ES}.json` sont
> **symétriques** (371 clés, mêmes sections) — la maintenance continue par le sous-agent `translator` a
> bien fonctionné. Les 3 critères d'acceptation sont donc **presque** satisfaits ; il reste 3 écarts
> ciblés (un gap AC3 jamais clos, un hardcodé AC1, deux clés mortes AC2).

## Architecture

Trois livrables adossés aux 3 critères d'acceptation, répartis entre `php-jeedom-dev` (code FR) et
`translator` (traduction / JSON / manifeste).

### ① AC3 — `description` d'`info.json` en 4 langues  *(propriétaire : translator, étape 10)*
- **Contrat Jeedom confirmé** (doc officielle `structure_info_json`, WebFetch 2026-07-18) : la
  `description` (et `name`) se traduit via un **objet à clés de langue DIRECTEMENT dans le manifeste
  `info.json`** — `{"fr_FR":…, "en_US":…, "de_DE":…, "es_ES":…}` — **PAS** via une section `"info.json"`
  des fichiers `core/i18n/*.json`.
  - ⚠️ **Écart doc vs `CLAUDE.md`** : la section i18n de `CLAUDE.md` donne l'exemple erroné
    `"info.json": { "Description française": "Traduction" }` (mécanisme B). La **doc officielle fait foi
    sur le contrat** → mécanisme A (dict inline). `CLAUDE.md` corrigé en étape 13.
- **État actuel** : `description = {"fr_FR": "Connecte les véhicules Stellantis / Groupe PSA …"}` — une
  seule langue. → ajouter `en_US`, `de_DE`, `es_ES`.
- **Gate de validation** : chaque langue **≥ 80 caractères** (règle market Jeedom ; `<br/>` autorisé).
- **Non touchés** (documentés pour éviter tout re-signalement d'un futur audit) :
  - `name = "Stellantis"` — nom propre, non traduit.
  - `changelog` / `changelog_beta` / `documentation` / `documentation_beta` — gabarits d'URL avec
    `#language#`, déjà génériques, aucune action i18n.
  - `link.forum` / `link.video` — placeholders (dette UC82), **hors périmètre** de cette feature.

### ② AC1 — certifier zéro chaîne UI en dur + 1 correction confirmée  *(propriétaire : php-jeedom-dev, étape 6)*
- **Correction certaine** (bug trouvé à l'audit) : `desktop/js/stellantis.js:130` —
  `placeholder="Unité"` **non enveloppé** (alors que `title="{{Unité}}"` l'est sur la même ligne).
  → remplacer par `placeholder="{{Unité}}"`. La clé `Unité` **existe déjà** dans les 3 JSON ⇒
  **aucune nouvelle chaîne FR**, aucune traduction neuve.
- **Sweep systématique** de tous les fichiers à surface UI, méthodologie élargie :
  - Fichiers : `desktop/php/{stellantis,panel}.php`, `desktop/js/stellantis.js`,
    `core/template/{dashboard,mobile}/*.html`, `plugin_info/configuration.txt`, sinks utilisateur de
    `core/class/stellantis.class.php` (`health()`, `message::add`, `sprintf` UI), `core/ajax/*.php`.
  - **Inclure** : le **HTML généré par concaténation JS** (le sweep « .html/.php seuls » aurait raté
    le bug js:130), et les patterns `confirm(` / `bootbox.confirm(` / `.showAlert(`, attributs
    `placeholder` / `title` / `alt`.
  - **Exclure** : templates `core/template/{dashboard,mobile}/cmd.action.other.templeteTemplate.html`
    (0 octet, scaffold mort) ; logs Python (`resources/demond/`, `otp_helper.py`,
    `extract_credentials.py` — jamais affichés dans l'UI Jeedom, restent FR) ; `install.php`
    (`log::add` FR uniquement) ; valeurs d'exemple techniques dans `placeholder=` (ex. `"mwa.mpsa.com"`,
    `"55009"`, `"150"`, `"fr"`) qui ne sont pas du texte UI.
  - Toute chaîne FR non enveloppée trouvée → l'envelopper (`{{…}}` HTML/JS, `__('…', __FILE__)` PHP).
  - **Contrainte fichier config** : éditer `plugin_info/configuration.txt` (source éditable) **puis**
    `cp plugin_info/configuration.txt plugin_info/configuration.php` (ne jamais éditer le `.php`).
  - **Ne PAS toucher** aux `core/i18n/*.json` (domaine du translator).
- **Livrable** : la liste des fichiers balayés + les chaînes enveloppées (attendu : la seule
  correction js:130). Certification explicite pour AC1.

### ③ AC2 — couverture 3 langues + parse + hygiène orphelines PAR SECTION  *(propriétaire : translator, étape 10)*
- Couverture déjà satisfaite (371 clés symétriques, JSON parsent). Reste la purge de **2 clés mortes**,
  **par paire (section-fichier, clé)** — jamais par texte de clé seul (un même texte FR peut
  légitimement exister dans deux sections) :
  - **Supprimer** `(plugins/stellantis/core/class/stellantis.class.php → "Connexion au compte")`
    — orpheline (le code n'a que `'Connexion au compte principal'` et
    `sprintf('Connexion au compte secondaire %s', …)`).
  - **Supprimer** `(plugins/stellantis/desktop/php/stellantis.php → "Ajouter")` — orpheline (le code
    n'utilise que `{{Ajouter une commande}}`).
  - **CONSERVER** `(plugins/stellantis/plugin_info/configuration.php → "Connexion au compte")` —
    **vivante** (légende `<legend>{{Connexion au compte}}</legend>` dans `configuration.txt:156`).
    ⚠️ Une suppression par texte de clé casserait cette traduction en en/de/es (régression silencieuse).
- Intégrer toute clé nouvellement enveloppée par le sweep ② (ici : aucune, `Unité` préexiste).
- **Auto-valider** que les 3 JSON parsent + **préserver les placeholders `sprintf`** (`%s`, `%d`,
  `%1$d`, `%2$s`…) à l'identique dans toutes les traductions.

## Server vs Client
Sans objet — contenu statique traduit (manifeste + fichiers i18n + une correction de template JS).
Aucune logique serveur/client, aucun endpoint.

## Validation
- **AC1** : sweep systématique certifiant zéro hardcodé (une seule correction : js:130). Vérif par
  grep des patterns `placeholder="[^{]`, `confirm(`, chaînes FR nues dans les concaténations JS/HTML.
- **AC2** : diff clés source-FR ↔ JSON par section = 0 manquante / 0 orpheline après purge ciblée ;
  les 3 JSON parsent (auto-validé par le translator via Python).
- **AC3** : `description` présente pour les 4 langues, chacune ≥ 80 caractères.

## Server Actions / API
Aucune.

## Dépendances
Aucune (aucun paquet, aucune extension PHP).

## Brief translator (étape 10) — points critiques
1. `info.json.description` : **éditer le dict inline** du manifeste (mécanisme A), **ne PAS** créer de
   section `"info.json"` dans les `core/i18n/*.json`.
2. Purge orphelines **par (section-fichier, clé)** — conserver `Connexion au compte` sous la section
   `configuration.php`.
3. Gate description ≥ 80 caractères par langue ; préserver les `sprintf` `%…$…`.
4. Terminologie véhicule cohérente (FR « véhicule/charge/autonomie/verrouillage » → EN/DE/ES usuels).
