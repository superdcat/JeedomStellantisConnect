# Widgets de commande Jeedom — mécanisme, tokens, multi-commandes (vérifié core, UC25)

> Source : vérification directe de la source du core (`cmd.class.php`, `cmd.class.js`, `cmd.ajax.php`)
> pendant UC25. **Corrige deux hypothèses fausses** souvent admises : `#cmd_id[logicalId]#` et
> `jeedom.cmd.byEqLogicId` **n'existent pas**. À relire avant tout nouveau widget de commande.

## 1. Déclaration & fichiers

- Un widget de commande = un fichier `core/template/<version>/cmd.<type>.<subType>.<nom>.html`,
  `<version>` ∈ `{dashboard, mobile}`. Ex. : `cmd.action.other.imouPtzPad.html`,
  `cmd.info.string.imouLive.html`, `cmd.action.other.imouButton.html`.
- Assignation côté PHP : `$cmd->setTemplate('dashboard'|'mobile', 'imou::<nom>')` (préfixe = id plugin,
  `<nom>` = suffixe du fichier). Le core résout le fichier d'après `type`/`subType` de la commande.
- **Dashboard et mobile sont deux fichiers séparés** (en pratique copies identiques chez nous → l'en-tête
  HTML rappelle de les synchroniser). **i18n : une entrée de chemin par fichier** (`plugins/imou/core/
  template/dashboard/<f>` ET `.../mobile/<f>`), même pour des chaînes identiques.

## 2. Tokens disponibles dans le HTML (remplacés par `cmd::toHtml()`)

`#id#`, `#logicalId#`, `#eqLogic_id#`, `#name#`, `#name_display#`, `#uid#`, `#version#`.
Pour une **action liée** à une info (via `setValue`) : `#value_id#`, `#state#`.

- `#uid#` = id DOM unique de l'instance → **scoper le script** : `document.querySelector('.cmd[data-cmd_uid=#uid#]')`.
- ⚠️ **Aucun token ne référence une AUTRE commande par logicalId.** `#cmd_id[ptz_down]#` & co
  **n'existent pas**. Le widget ne « voit » nativement que sa propre commande + sa commande liée.

## 3. Résoudre des commandes sœurs (widgets multi-commandes)

Besoin typique : pavé PTZ (1 widget pilote 6 `ptz_*`), lecteur live (pilote `live_get`/`live_release`/
`snapshot_get`).

- ⚠️ **`jeedom.cmd.byEqLogicId` n'existe pas** en JS (seul `refreshByEqLogic`, déprécié, pour le rafraîchissement d'affichage).
- Voie réelle : `fetch('core/ajax/cmd.ajax.php', { method:'POST', credentials:'same-origin',
  headers:{'Content-Type':'application/x-www-form-urlencoded'},
  body: new URLSearchParams({action:'byEqLogic', eqLogic_id:'#eqLogic_id#'}) })`.
  Réponse `{state:'ok', result:[ … ]}` : **toutes** les commandes de l'eqLogic (`utils::o2a(cmd::byEqLogicId(...))`),
  incluant `logicalId`, `id`, `isVisible` — **même les masquées**. Action `byEqLogic` = **utilisateur connecté** suffit (pas admin).
- Pattern robuste : résoudre la map `logicalId→id` **puis** câbler les boutons ; bouton **désactivé** si sa
  commande est absente (ex. caméra PT sans zoom → `ptz_zoom_*` non créées). L'ancre connaît son propre id
  sans réseau (`#id#`).
- **Corollaire clé : masqué ≠ non-exécutable.** `isVisible=0` retire la tuile du dashboard mais la commande
  reste dans `byEqLogic`, exécutable, et listée dans la table de commandes admin. → on masque 5 boutons PTZ
  et le pavé les pilote (UC25/UC16 : seul `ptz_up` `defaultVisible=true`, porte le widget).

## 4. Exécuter une action depuis un widget

- `jeedom.cmd.execute({ id, value?, notify?, success?, error? })` — gère **token CSRF + droits + prompts**
  (code PIN -32005 / confirmation -32006).
- `success(data)` reçoit `{state, result}` où **`result` = la valeur de retour PHP de `cmd::execute()`**.
  ⇒ Faire `return $url;` dans l'action PHP livre l'URL au widget en **un seul aller-retour** (cf.
  `actionLiveGet`/`actionSnapshotGet`). `notify:false` supprime le toast (utile pour un refresh périodique).

## 5. Auth AJAX (core 4.4+)

- Authentification par **session** (cookie) ; protection CSRF des actions **mutantes** = **forçage du POST**
  (pas de token par requête pour une simple lecture). D'où : `byEqLogic` en `fetch` brut fonctionne ;
  les **mutations** passent par `jeedom.cmd.execute` (qui ajoute le token via `getParamsAJAX`).
- NB : `core/ajax/imou.ajax.php` est **admin-only** (`isConnect('admin')` global) → **inutilisable** depuis
  un widget de dashboard (session utilisateur). Tout pilotage widget passe donc par le modèle de commandes
  + l'AJAX **core** (`byEqLogic`, `execCmd`), jamais par un endpoint AJAX plugin.

## 6. Appliquer un template sans écraser le choix utilisateur

- Poser **« si vide »** : `if ($cmd->getTemplate($version,'')==='') $cmd->setTemplate($version, ...)` à
  chaque sync. Couvre les **installs existantes** (template absent → posé au prochain re-sync) **sans**
  réécrire un widget choisi à la main. Même philosophie idempotente que `visibleOnCreate`/`configurationOnCreate`
  (cf. `creercommande-configuration-vs-configurationoncreate` en mémoire persistante).
- Bord assumé : si l'utilisateur repasse explicitement au widget « par défaut » du core (template ''), le
  nôtre est re-posé au prochain sync. Toléré (cosmétique, rare).

## 7. ⚠️ CSP Jeedom : tout média/image EXTERNE est bloqué côté navigateur → proxy same-origin obligatoire

**Constat clé (UC25, Jeedom réel)** : la CSP des pages Jeedom est `default-src 'self' file: data: blob:
filesystem:` **sans `img-src`/`media-src`/`connect-src` explicites**. Tout ce qui n'est pas listé retombe
sur `default-src 'self'` → **le navigateur bloque le chargement de TOUTE ressource externe** : `<img src>`
vers une URL cloud (cliché OSS), `<video src>`/hls.js vers un flux HLS externe, `fetch` cross-origin.
Symptôme : image cassée + texte `alt`, ou erreur console « violates Content Security Policy directive ».
La CSP est posée hors `core.inc.php`/`index.php` (probablement front/reverse-proxy) et n'a pas de réglage
admin évident. **Corollaire** : un appel `fetch`/`jeedom.cmd.execute` vers `core/ajax/*` (même-origine)
passe, mais **afficher une image/un flux d'un hôte tiers ne marche pas** sur un dashboard.

**Conséquence d'architecture** : tout widget affichant du **contenu externe** (caméra, image distante…)
doit le faire **servir par Jeedom lui-même** (origine `'self'`) via un endpoint du plugin. Le **serveur**
n'a pas de CSP (c'est une règle navigateur) → il peut récupérer la ressource externe et la relayer.

**Mise en œuvre UC25 — live-snapshot** (remplace l'ancien lecteur HLS/hls.js + snapshot-API, supprimés) :
- Widget `imouLive` **mono-mode** : une `<img>` pointant `core/ajax/imouStream.ajax.php?eqLogic_id=#eqLogic_id#`
  (même-origine → CSP OK) ; on **redemande la frame suivante dès `onload` de la précédente** (auto-cadencé,
  préchargement dans une `Image()` anti-flicker ; `onerror`→ backoff ; `MutationObserver`→ stop+release).
- Endpoint `core/ajax/imouStream.ajax.php` (séparé de `imou.ajax.php` qui est admin-only) : `isConnect()`
  **+ `$eqLogic->hasRight('r')`** (admin/user OK, restreint si droit) ; extrait **1 JPEG du flux HLS via
  ffmpeg** (`imou::grabHlsFrame`, `proc_open` en **mode tableau** = pas de shell, timeout, validation
  signature JPEG) ; `op=release` libère le binding. Verrou cache anti-concurrence (best-effort).
- **Quota** : `imou::resolveLiveHls` met l'URL HLS en cache (`LIVE_URL_TTL`=1 h) → **~0 appel API par
  frame** (un seul `bindDeviceLive` par session) ; les frames viennent du CDN de streaming.
- **Dépendance** : **ffmpeg** (`packages.json` apt) — écart assumé au « 100 % PHP sans paquet », justifié
  par la CSP (décodage serveur obligatoire) + le coût quota évité. Pas de démon (ffmpeg one-shot par frame).
- **Limite** : ~1 image / 1-3 s (latence HLS) ; le « live fluide » exigerait un ffmpeg persistant (= démon),
  exclu. Piste future « mode local » : RTSP/CGI HTTP Dahua (gratuit, sans quota) mais LAN-only + model-dependent.
