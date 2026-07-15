# Spec technique — 71 (Santé du plugin & fraîcheur des données)

> **Nature** : travail **additif ciblé** sur une méthode qui **existe déjà** (`stellantis::health()`,
> construite au MVP/09, étendue par UC12/UC19/UC54). UC71 **complète** l'agrégation avec le dernier
> résultat de commande, un lien vers la doc, et l'état du démon même arrêté. **Zéro nouvel appel
> réseau/MQTT** (AC2 par construction). Tout le code est dans `core/class/stellantis.class.php`.

## Architecture

### Fichiers touchés
- **`core/class/stellantis.class.php`** — seul fichier de production modifié :
  - `health()` (≈ l.1536) : ajout de la ligne Documentation, de la ligne « démon arrêté », de la ligne
    `last_command_result` par véhicule ; lecture d'un marqueur d'état de commande pour colorer la ligne.
  - `traiterRetourCommande()` (UC18, ≈ l.2006) : dépose un **marqueur cache non traduit** (`$interp['notify']`)
    à côté de l'écriture de `last_command_result`.
  - Nouveau helper privé `docUrl()`.
  - Nouvelle constante `CMD_STATUS_KEY`.
- **`core/i18n/{en_US,de_DE,es_ES}.json`** — traductions (étape 10, différée ; hors périmètre dev).

### Contrat core Jeedom — « À confirmer » de la spec : **RÉSOLU**
Le format attendu par le core pour `health()` est **déjà confirmé au runtime** (UC09, mémoire
`jeedom-health-page-contract`) : méthode **statique** `stellantis::health()` retournant un **tableau de
lignes** `['test','result','advice','state'=>bool]`. Le core détecte la fonctionnalité via
`method_exists($id,'health')` ; la colonne `result` est **rendue comme HTML** ; page **admin-only** ;
l'appeler ne doit **jamais** déclencher d'appel réseau (elle peut être rechargée souvent). Rien à
re-vérifier — l'implémentation existante en est la preuve.

### Contrat API Stellantis/PSA
**Aucun.** UC71 ne fait aucun appel REST ni MQTT. La collecte des états est explicitement hors périmètre
(MVP/09 fraîcheur, UC12 OTP, UC18 `last_command_result`, UC19 démon, UC54 slots). Lecture cache +
`execCmd()` de commandes info déjà peuplées + lecture locale de `plugin_info/info.json`.

## Server vs Client

**100 % serveur (PHP).** `health()` est une méthode statique appelée par le core, qui produit lui-même
le rendu de la page Santé. **Aucun** JS, HTML custom, template, ni action AJAX. Justification : la page
Santé est un contrat purement back-end du core Jeedom.

## Validation

### Serveur
- **`docUrl()`** : best-effort — `try/catch \Throwable`, `''` si `info.json` illisible / clé
  `documentation` absente / vide. `health()` **omet** la ligne Documentation si `docUrl() == ''`
  (jamais de lien cassé). `htmlspecialchars($url, ENT_QUOTES, 'UTF-8')` avant insertion dans `href`
  (défense en profondeur, coût nul).
- **`last_command_result`** : gardes `is_object($cmd)` avant `execCmd()` ; ligne émise **seulement si la
  valeur n'est pas vide** (aucune commande émise ⇒ pas de ligne, évite le bruit). Valeur **déjà**
  `aseptiser()` + `htmlspecialchars()` à l'écriture (UC18, l.2036-2046) ⇒ sûre dans la colonne HTML
  `result`, **aucun** ré-échappement.
- **`state` de `last_command_result`** : dérivé du **marqueur cache machine-readable** (non traduit),
  **jamais** du texte affiché (le texte est traduit → un test `str_starts_with('Échec')` casserait sur
  une instance en/de/es). Règle : `state = ($statut !== 'failure')`. Marqueur absent (véhicule équipé
  avant UC71) ⇒ repli `state = true` (informatif, non régressif).
- **Ligne démon** : `state = false` uniquement quand OTP actif **et** démon arrêté (problème réel :
  pilotage à distance indisponible) ; branches existantes `connected`/`retrying` (state=true) /
  `auth_failed` (state=false) inchangées.
- **Aucun input utilisateur** (page lecture seule admin) ⇒ pas de validation d'entrée.

### Client
Aucune (pas de formulaire).

## Server Actions / API

**Aucune nouvelle action AJAX.** Détail des modifications :

### 1. Helper `docUrl(): string` (privé statique, nouveau)
```php
private static function docUrl(): string {
  try {
    $info = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/plugin_info/info.json'), true);
    $tpl = (is_array($info) && isset($info['documentation'])) ? (string) $info['documentation'] : '';
    if ($tpl === '') { return ''; }
    return str_replace('#language#', (string) config::byKey('language', 'core', 'fr_FR'), $tpl);
  } catch (\Throwable $e) {
    return '';
  }
}
```
- `dirname(__DIR__, 2)` depuis `core/class/` = racine du plugin ⇒ `…/plugin_info/info.json`.
- `#language#` = placeholder natif Jeedom déjà présent dans `info.json`
  (`https://doc.jeedom.com/#language#/plugins/devicecommunication/stellantis`) → **source unique**, pas
  de duplication de l'URL/catégorie dans le code (corrige le drift signalé par l'advisor).
- `config::byKey('language','core','fr_FR')` fournit le code langue (`fr_FR`/`en_US`/`de_DE`/`es_ES`),
  aligné avec les codes doc.jeedom.com. *(À confirmer en recette : clé langue exacte — faible risque.)*

### 2. `health()` — ligne Documentation (avant la boucle véhicules)
```php
$docUrl = self::docUrl();
if ($docUrl !== '') {
  $lignes[] = array(
    'test' => __('Documentation', __FILE__),
    'result' => '<a target="_blank" rel="noopener noreferrer" href="'
      . htmlspecialchars($docUrl, ENT_QUOTES, 'UTF-8') . '">'
      . __('Consulter la documentation en ligne', __FILE__) . '</a>',
    'advice' => '',
    'state' => true,
  );
}
```

### 3. `health()` — état démon complété
Restructurer le bloc existant `if (self::deamon_info()['state'] == 'ok' && $otp == 'active')` en :
```php
if ($otp == 'active') {
  if (self::deamon_info()['state'] == 'ok') {
    // … branches existantes connected / retrying / auth_failed (INCHANGÉES) …
  } else {
    $lignes[] = array(
      'test' => __('Connexion du démon (pilotage à distance)', __FILE__), // même test que les branches ci-dessus
      'result' => __('Démon arrêté — pilotage à distance indisponible', __FILE__),
      'advice' => __('Démarrez le démon depuis la page de configuration du plugin', __FILE__),
      'state' => false,
    );
  }
}
```
Note : `deamon_info()` ne fait que lire un pidfile + `posix_getsid` ⇒ aucun appel réseau (AC2 OK).

### 4. `health()` — `last_command_result` par véhicule (dans le `foreach`, AVANT le `continue` privacy)
```php
$cmdRes = $eqLogic->getCmd('info', self::CMD_RESULT_LOGICAL_ID);
$valRes = is_object($cmdRes) ? (string) $cmdRes->execCmd() : '';
if ($valRes !== '') {
  $statut = (string) cache::byKey(self::CMD_STATUS_KEY . $eqLogic->getId())->getValue('');
  $lignes[] = array(
    'test' => $nom,
    'result' => sprintf(__('Dernière commande : %s', __FILE__), $valRes),
    'advice' => '',
    'state' => ($statut !== 'failure'),
  );
}
```
Placement **avant** le `continue` de la branche privacy : un résultat de commande n'est **pas** une
donnée de localisation → il doit s'afficher même en mode privacy.

### 5. `traiterRetourCommande()` (UC18) — marqueur d'état machine
Juste après l'écriture de `last_command_result` (étape 5, ≈ l.2075) :
```php
cache::set(self::CMD_STATUS_KEY . $eqId, $interp['notify'], 0);
```
- `$interp['notify']` ∈ `{'none','failure','token','clear'}` (calculé par `interpreterAck`, **valeur
  interne, jamais traduite**). Seul `'failure'` colore la ligne Santé en rouge.
- `cache::set(..., 0)` = lifetime 0 ⇒ jamais expiré (même convention que `DAEMON_CONN_STATE_KEY`).
- Écrit dans un chemin **déjà exécuté** à chaque ack, aucune donnée externe, aucun appel réseau.

### 6. Nouvelle constante (près de `CMD_RESULT_LOGICAL_ID`, ≈ l.2485)
```php
const CMD_STATUS_KEY = 'stellantis::last_cmd_status::'; // + eqId (cache lifetime 0) : statut machine du dernier ack (notify UC18), pour colorer la ligne Santé
```

## Dépendances

**Aucune** (ni PHP, ni pip). Aucune modification de `packages.json` / `info.json` (l'URL doc y est déjà).

## Impact i18n — chaînes FR introduites (traduction différée, étape 10)

À ajouter dans les 3 `core/i18n/*.json` sous `plugins/stellantis/core/class/stellantis.class.php` :
1. `Documentation`
2. `Consulter la documentation en ligne`
3. `Démon arrêté — pilotage à distance indisponible` *(⚠️ tiret cadratin « — » : vérifier l'UTF-8 dans le JSON)*
4. `Démarrez le démon depuis la page de configuration du plugin`
5. `Dernière commande : %s`

Réutilisées (déjà traduites) : `Connexion du démon (pilotage à distance)`, lignes auth/OTP/fraîcheur
existantes. Toutes les nouvelles chaînes sont des `__()` **littéraux** (scan statique de l'extracteur).

## Critères d'acceptation → couverture
- **AC1** (auth + démon + fraîcheur par véhicule affichés) : auth (lignes slots existantes), démon
  (branches UC19 + nouvelle ligne « arrêté »), fraîcheur (`last_update` existant), **+** dernier résultat
  de commande **+** lien doc = **couvert**.
- **AC2** (aucun appel API déclenché par l'affichage) : **couvert par construction** — uniquement
  cache + `execCmd()` + lecture fichier local `info.json` + `posix_getsid`.
