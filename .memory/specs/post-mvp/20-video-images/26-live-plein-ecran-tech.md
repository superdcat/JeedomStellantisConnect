# Spec technique — UC26 Live en plein écran

## Architecture
100 % front. Modification des deux seuls templates du widget live (UC25), à garder **identiques** :
- `core/template/dashboard/cmd.info.string.imouLive.html`
- `core/template/mobile/cmd.info.string.imouLive.html`

Aucun PHP, aucun AJAX, aucun endpoint nouveau. On réutilise l'unique boucle de frames UC25
(`nextFrame()` → `loader.onload` → `img.src`) servie same-origin par
`core/ajax/imouStream.ajax.php`. Un seul binding ffmpeg côté serveur → **zéro appel API
supplémentaire** (critère §41).

### Éléments ajoutés (dans l'IIFE existante)
- Variables de closure : `overlay=null`, `overlayImg=null`, `overlayOpen=false`, `keyHandler=null`.
- `buildOverlay()` (lazy, 1ʳᵉ ouverture) : crée un `<div class="imouLiveOverlay">`
  `position:fixed; top/left/right/bottom:0; z-index élevé; fond rgba sombre; display:flex`,
  `role="dialog" aria-modal="true"` + `aria-label` = nom caméra. Contient :
  - barre haute : nom caméra (`#name_display#`) + bouton fermer (`fa-times`,
    `aria-label="{{Fermer}}"`, classe `imouLiveOverlayClose`) ;
  - grand `<img class="imouLiveOverlayImg" object-fit:contain; max-width/height:100%>`.
  - Appendu à `document.body` (échappe aux parents `overflow:hidden`).
  - Clic fond hors image (`e.target===overlay`) → `closeOverlay()` ; bouton fermer → `closeOverlay()`.
- `openOverlay()` : masque tout autre `.imouLiveOverlay` visible (garde multi-overlay) ; build si
  besoin ; `overlay.style.display='flex'`; `overlayOpen=true`; **garde frame** :
  `if (img.style.display !== 'none') overlayImg.src = img.src` ; attache `keyHandler` (Échap) sur `document`.
- `closeOverlay()` : `overlayOpen=false`; masque overlay ; détache `keyHandler` ; `btnStart.focus()`.
- Frame partagée : dans `loader.onload`, après `img.src = loader.src`, ajouter
  `if (overlayOpen) overlayImg.src = loader.src`.
- `.imouLiveImg` : `cursor:pointer`, `title="{{Plein écran}}"`, listener `click` → `openOverlay()`.
- **Fix de cliquabilité** (`.imouLiveMsg`) : le `<div>` de message UC25 couvre tout le stage en
  `position:absolute` et interceptait le clic à la place de l'image (même vide) → ajout de
  `pointer-events:none` sur `.imouLiveMsg` pour laisser passer le clic jusqu'à l'`<img>`.

### Comportements modifiés
- **IntersectionObserver**, branche sortie viewport : `if (running && !overlayOpen) stop()`
  → tant que l'overlay est ouvert, la boucle continue même si la tuile sort de l'écran.
- **MutationObserver teardown** (tuile retirée) : `if (running) stop()` puis **`closeOverlay()`**
  (seule porte qui détache le `keyHandler`) puis `if (overlay && overlay.parentNode)
  overlay.parentNode.removeChild(overlay); overlay=null` (sinon nœud + listener orphelins).

### Priorité plein écran (sur le limiteur de concurrence UC25-tech, révision 2026-06-22)
La caméra en overlay est **prioritaire** : elle court-circuite la file FIFO du sémaphore global et capture
sa frame **immédiatement à chaque cycle**, sans consommer de voie partagée (au plus une tuile en overlay
à la fois → surcoût borné à **+1 capture**).
- `nextFrame()` : tout en haut, `if (overlayOpen) { captureFrame(); return }` → bypass de la file/voie.
- `openOverlay()` : juste après `overlayOpen = true`, bascule immédiate si on n'a pas déjà une capture en
  vol — `if (running && !hasSlot) { dequeueSelf(); clearRetry(); captureFrame() }`. Si une capture FIFO
  est déjà en vol (`hasSlot`), son `onload` basculera en priorité via `nextFrame()`.
- **Retour propre en FIFO** à la fermeture : `closeOverlay()` ne touche pas au scheduler ; le prochain
  `onload` voit `overlayOpen=false` et la tuile se réinscrit en queue de file.
- Invariant préservé : la tuile en bypass ne tient jamais de voie (`hasSlot=false`) → `SCH.active` ne
  sur/sous-compte pas ; `releaseSlot()` appelé en bypass est inoffensif (juste `pump()`).

## Server vs Client
100 % client : pur agrandissement d'images déjà servies. Aucune logique serveur, aucun nouvel
appel cloud, aucun nouveau binding (réutilise la boucle/cache UC25).

## Validation
- Robustesse : `loader.onerror` (UC25) laisse `running=true` (retry 2,5 s) → frames reprennent
  automatiquement durant l'overlay, pas d'image figée définitive.
- Pas de fuite : le `keyHandler` n'est posé qu'à l'ouverture et toujours retiré par `closeOverlay()`,
  appelée aussi par le teardown. Le nœud overlay est retiré au teardown.
- A11y : `role=dialog`, `aria-modal`, `aria-label`, focus restitué.

## Server Actions / API
Aucune.

## Dépendances
Aucune (FontAwesome `fa-times` déjà disponible dans Jeedom).

## i18n (FR, traduction différée étape 10)
- Nouvelles clés : `{{Plein écran}}`, `{{Fermer}}`.
- Réutilisées UC25 : `Aucune image`, `Flux indisponible`, `Chargement…`, `Démarrer`, `Arrêter`.
