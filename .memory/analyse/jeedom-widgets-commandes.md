# Widgets de commande Jeedom — mécanisme, tokens, multi-commandes (vérifié source du core)

> Connaissance **générique Jeedom** (vérifiée directement dans la source du core : `cmd.class.php`,
> `cmd.class.js`, `cmd.ajax.php`). **Corrige deux hypothèses fausses** souvent admises :
> `#cmd_id[logicalId]#` et `jeedom.cmd.byEqLogicId` **n'existent pas**. À relire avant tout nouveau
> widget de commande (ici : tuile d'état véhicule, pavé de commandes à distance, mini-carte).

## 1. Déclaration & fichiers

- Un widget de commande = un fichier `core/template/<version>/cmd.<type>.<subType>.<nom>.html`,
  `<version>` ∈ `{dashboard, mobile}`. Ex. : `cmd.info.string.stellantisMap.html`,
  `cmd.action.other.stellantisCmdPad.html`, `cmd.info.numeric.stellantisGauge.html`.
- Assignation côté PHP : `$cmd->setTemplate('dashboard'|'mobile', 'stellantis::<nom>')` (préfixe = id
  plugin, `<nom>` = suffixe du fichier). Le core résout le fichier d'après `type`/`subType` de la commande.
- **Dashboard et mobile sont deux fichiers séparés** (souvent copies identiques → l'en-tête HTML rappelle
  de les synchroniser). **i18n : une entrée de chemin par fichier** (`plugins/stellantis/core/template/
  dashboard/<f>` ET `.../mobile/<f>`), même pour des chaînes identiques.

## 2. Tokens disponibles dans le HTML (remplacés par `cmd::toHtml()`)

`#id#`, `#logicalId#`, `#eqLogic_id#`, `#name#`, `#name_display#`, `#uid#`, `#version#`.
Pour une **action liée** à une info (via `setValue`) : `#value_id#`, `#state#`.

- `#uid#` = id DOM unique de l'instance → **scoper le script** : `document.querySelector('.cmd[data-cmd_uid=#uid#]')`.
- ⚠️ **Aucun token ne référence une AUTRE commande par logicalId.** `#cmd_id[charge_now]#` & co
  **n'existent pas**. Le widget ne « voit » nativement que sa propre commande + sa commande liée.

## 3. Résoudre des commandes sœurs (widgets multi-commandes)

Besoin typique : pavé de commandes (1 widget pilote `lock`/`unlock`/`horn`/`charge_now`), tuile
véhicule (lit `battery_soc`/`autonomy`/`position` ensemble).

- ⚠️ **`jeedom.cmd.byEqLogicId` n'existe pas** en JS (seul `refreshByEqLogic`, déprécié, pour le
  rafraîchissement d'affichage).
- Voie réelle : `fetch('core/ajax/cmd.ajax.php', { method:'POST', credentials:'same-origin',
  headers:{'Content-Type':'application/x-www-form-urlencoded'},
  body: new URLSearchParams({action:'byEqLogic', eqLogic_id:'#eqLogic_id#'}) })`.
  Réponse `{state:'ok', result:[ … ]}` : **toutes** les commandes de l'eqLogic
  (`utils::o2a(cmd::byEqLogicId(...))`), incluant `logicalId`, `id`, `isVisible` — **même les masquées**.
  Action `byEqLogic` = **utilisateur connecté** suffit (pas admin).
- Pattern robuste : résoudre la map `logicalId→id` **puis** câbler les boutons ; bouton **désactivé** si
  sa commande est absente (ex. véhicule thermique sans `charge_now`). L'ancre connaît son propre id sans
  réseau (`#id#`).
- **Corollaire clé : masqué ≠ non-exécutable.** `isVisible=0` retire la tuile du dashboard mais la
  commande reste dans `byEqLogic`, exécutable, et listée dans la table admin → on masque les boutons
  unitaires et le pavé les pilote (cf. UC commandes affichées par défaut).

## 4. Exécuter une action depuis un widget

- `jeedom.cmd.execute({ id, value?, notify?, success?, error? })` — gère **token CSRF + droits +
  prompts** (code PIN -32005 / confirmation -32006).
- `success(data)` reçoit `{state, result}` où **`result` = la valeur de retour PHP de `cmd::execute()`**.
  ⇒ Faire `return $payload;` dans l'action PHP livre la donnée au widget en **un seul aller-retour**.
  `notify:false` supprime le toast (utile pour un refresh périodique d'une tuile).

## 5. Auth AJAX (core 4.4+)

- Authentification par **session** (cookie) ; protection CSRF des actions **mutantes** = **forçage du
  POST** (pas de token par requête pour une simple lecture). D'où : `byEqLogic` en `fetch` brut
  fonctionne ; les **mutations** passent par `jeedom.cmd.execute` (qui ajoute le token via `getParamsAJAX`).
- NB : `core/ajax/stellantis.ajax.php` est **admin-only** (`isConnect('admin')` global) → **inutilisable**
  depuis un widget de dashboard (session utilisateur). Tout pilotage widget passe donc par le modèle de
  commandes + l'AJAX **core** (`byEqLogic`, `execCmd`), jamais par un endpoint AJAX plugin.

## 6. Appliquer un template sans écraser le choix utilisateur

- Poser **« si vide »** : `if ($cmd->getTemplate($version,'')==='') $cmd->setTemplate($version, ...)` à
  chaque sync. Couvre les **installs existantes** (template absent → posé au prochain re-sync) **sans**
  réécrire un widget choisi à la main. Même philosophie idempotente que `visibleOnCreate`/
  `configurationOnCreate`.
- Bord assumé : si l'utilisateur repasse explicitement au widget « par défaut » du core (template ''),
  le nôtre est re-posé au prochain sync. Toléré (cosmétique, rare).

## 7. ⚠️ CSP Jeedom : tout média/image EXTERNE est bloqué côté navigateur → proxy same-origin obligatoire

**Constat clé (Jeedom réel)** : la CSP des pages Jeedom est `default-src 'self' file: data: blob:
filesystem:` **sans `img-src`/`media-src`/`connect-src` explicites**. Tout ce qui n'est pas listé retombe
sur `default-src 'self'` → **le navigateur bloque le chargement de TOUTE ressource externe** : `<img src>`
vers une tuile de carte distante (OSM/Mapbox/Google Static Maps), `fetch` cross-origin, etc.
Symptôme : image cassée + texte `alt`, ou erreur console « violates Content Security Policy directive ».
La CSP est posée hors `core.inc.php`/`index.php` (front/reverse-proxy), sans réglage admin évident.

**Conséquence d'architecture** : tout widget affichant du **contenu externe** (tuile de carte pour la
position du véhicule, image de modèle distante…) doit le faire **servir par Jeedom lui-même**
(origine `'self'`) via un endpoint du plugin. Le **serveur** n'a pas de CSP (règle navigateur) → il peut
récupérer la ressource externe et la relayer.

**Application véhicule — mini-carte de position** :
- Widget `stellantisMap` : une `<img>` pointant `core/ajax/stellantisMap.ajax.php?eqLogic_id=#eqLogic_id#`
  (même-origine → CSP OK) ; l'endpoint récupère côté serveur une tuile de carte statique centrée sur la
  position (lat/lon de la commande `position`) et la relaie.
- Endpoint `core/ajax/stellantisMap.ajax.php` (séparé de `stellantis.ajax.php` admin-only) : `isConnect()`
  **+ `$eqLogic->hasRight('r')`** (admin/user OK) ; cache court de la tuile.
- **Alternative sans réseau externe** : afficher les coordonnées en texte + un lien `geo:`/maps cliquable
  (pas de tuile) → aucun proxy, aucune dépendance, mais moins visuel. Décider selon l'UC carte.
