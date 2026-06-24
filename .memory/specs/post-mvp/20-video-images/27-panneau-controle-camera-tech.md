# Spec technique — post mvp 27 (Panneau caméras)

> UC fonctionnelle : `27-panneau-controle-camera.md`. Page-panneau « mur de caméras » accessible
> depuis le menu Jeedom, réutilisant le live UC25 et les commandes existantes (PTZ/sirène/projecteur).

## Architecture

| Fichier | Action |
|---|---|
| `plugin_info/info.json` | **modifier** — `"display": "imouPanel"` (active la page-panneau desktop) |
| `desktop/php/imouPanel.php` | **créer** — page panneau (grille de cellules caméra) |
| `desktop/js/imouPanel.js` | **créer** — live par cellule + barres de commandes + plein écran |
| `desktop/php/imou.php` | **modifier** — case « Visible sur le panneau caméras » (par caméra) |
| `core/class/imou.class.php` | **modifier** — défaut `isVisiblePanel=1` à la création + backfill au re-sync |

Pas de nouvel endpoint ni d'appel cloud : réutilisation de `core/ajax/imouStream.ajax.php` (live UC25)
et des commandes `action` existantes via `jeedom.cmd.execute`.

### Mécanisme de menu (natif core — vérifié)
`info.json "display": "imouPanel"` ⇒ le core ajoute, dans la **zone de gestion du plugin**
(`#div_plugin_panel`, à côté des réglages cron/dépendances), les cases **« Afficher le panneau
desktop / mobile »** liées aux clés de config **`displayDesktopPanel`** / `displayMobilePanel`
(source : `jeedom/core` `desktop/js/plugin.js`). **Décochées par défaut** (config absente ⇒ falsy)
⇒ entrée de menu **masquée par défaut**, le core lit ces clés pour afficher/masquer l'entrée.
→ **Aucun toggle custom à écrire** (pas de `panelEnabled`). On ne déclare que `display`.
Mobile : hors v1 (pas de champ `"mobile"`, pas de `mobile/php/`) ; le rendu mobile est assuré par
la **grille CSS responsive** de la page desktop.

## Server vs Client
- **Serveur (`imouPanel.php`)** : auth, contrôle d'accès par caméra, sélection/tri, génération du
  HTML des cellules avec **résolution des id de commandes en PHP** (injectés en `data-cmd-*`,
  uniquement si la commande existe) → le client n'a aucun appel `byEqLogic` à faire (≠ widget UC25).
- **Client (`imouPanel.js`)** : boucle de frames live, sémaphore de concurrence, IntersectionObserver,
  overlay plein écran, exécution des commandes. Aucune logique de droit côté client (le serveur ne
  rend que les caméras autorisées ; l'endpoint live re-vérifie `hasRight('r')` à chaque frame).

## Validation
- **Auth** : `isConnect()` (utilisateur connecté, **pas** admin — page utilisable au quotidien).
- **Par caméra** : n'afficher une cellule que si `getIsEnable()` **ET** `hasRight('r')` **ET**
  `(int) getConfiguration('isVisiblePanel', 1) === 1`. Tri par `getName()`.
- **Échappement** : `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` sur nom/id injectés dans le HTML.
- **Aucune entrée utilisateur** : les `eqLogic_id`/id de commandes proviennent de la base.
- **Client** : ne câbler/afficher un bouton que si son `data-cmd-*` est présent et non vide
  (jamais `jeedom.cmd.execute({id: undefined})`).

## Server Actions / API
- **Aucune.** Live : `GET plugins/imou/core/ajax/imouStream.ajax.php?eqLogic_id=<id>` (+ `&op=config`,
  `&op=release`). Commandes : `jeedom.cmd.execute({ id })` (CSRF/droits gérés par le core).

### Sélection par caméra (`isVisiblePanel`)
- **Création** (`imou::syncEquipments`, branche création) : `setConfiguration('isVisiblePanel', 1)`
  — défaut « création seulement », à côté de `thumbSource` (pré-coché ⇒ panneau utilisable d'emblée).
- **Re-sync** (branche update) : **backfill une fois** si la clé est absente
  (`if (getConfiguration('isVisiblePanel','') === '') setConfiguration('isVisiblePanel', 1)`) →
  caméras antérieures à l'UC = visibles + case cohérente ; **jamais** réécrit si déjà positionnée
  (préserve le choix utilisateur, cf. invariant re-sync).
- **Form équipement** (`imou.php`, Paramètres spécifiques) : `<input type="checkbox" class="eqLogicAttr"
  data-l1key="configuration" data-l2key="isVisiblePanel">` (auto-save core).

### Live par cellule (`imouPanel.js`)
- **Sémaphore partagé** `window.__imouLiveSched` : **auto-initialisé** par le panel (contexte page
  vierge, aucune tuile widget) — même structure que le widget (`{ max:3, active:0, fetched:false,
  queue:[] }`), flag `fetched` posé **avant** le fetch `op=config` (un seul appel config).
- Par cellule : préchargement `new Image()`, bascule anti-flicker, ré-inscription FIFO après réception
  (équité round-robin), backoff sur erreur. **IntersectionObserver** : démarre à l'entrée dans le
  viewport, arrête à la sortie (sauf overlay ouvert).
- **Plein écran** : clic sur l'image → overlay `position:fixed` (priorité = bypass FIFO, +1 capture
  bornée car au plus un overlay) ; fermeture bouton/clic-fond/Échap.
- **Anti-fuite ffmpeg sur navigation** (≠ widget : pas de retrait DOM en changement de page) :
  `window.addEventListener('pagehide', cleanup)` → arrêt des boucles + `navigator.sendBeacon(base +
  '&op=release')` par cellule active (résiste à l'annulation d'un `fetch` au déchargement).
- Commentaire en tête : « logique calquée sur `core/template/dashboard/cmd.info.string.imouLive.html`
  — toute correction de la mécanique live doit être appliquée aux deux » (divergence ⇒ extraction en
  lib commune = post-UC27).

### Commandes (barre compacte par cellule)
PTZ directionnel **sans zoom** : `ptz_up`/`ptz_down`/`ptz_left`/`ptz_right` (pavé ↑↓←→). Sirène
momentanée : `sirene_on`. Projecteur : `projecteur_on` / `projecteur_off`. Toutes type `action`,
résolues en PHP (`getCmd('action', <logicalId>)`), bouton rendu uniquement si la commande existe.

## Dépendances
Aucune (ffmpeg déjà requis par UC25 ; jeedom.js présent sur toute page desktop).

## i18n (FR — traduction différée étape 10)
Nouvelles clés : `{{Panneau caméras}}`, `{{Aucune caméra à afficher}}`, `{{Visible sur le panneau
caméras}}` (+ tooltip). Réutilisées : `{{Haut}}/{{Bas}}/{{Gauche}}/{{Droite}}`, `{{Déclencher sirène}}`,
`{{Allumer projecteur}}/{{Éteindre projecteur}}`, `{{Plein écran}}/{{Fermer}}/{{Flux indisponible}}/
{{Chargement…}}/{{Aucune image}}`. Le label de menu (« Imou ») et les cases « Afficher le panneau … »
sont gérés/traduits par le core.
