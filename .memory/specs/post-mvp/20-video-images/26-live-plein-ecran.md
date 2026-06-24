# UC — Live en plein écran (clic pour agrandir)

**Domaine :** Vidéo / images · **Dépend de :** UC25 (widget live-snapshot) · **Complémentaire de :** UC22 (flux live) · **Statut :** ✅ livré (cf. `26-live-plein-ecran-tech.md`)

## Objectif / valeur
Permettre, depuis le widget live (UC25), d'**agrandir le flux en plein écran** d'un simple clic sur
l'image, pour consulter le direct en grand sans quitter le dashboard. Aujourd'hui le live n'est visible
qu'en vignette (~320×180) dans la tuile ; on veut un mode « plein écran » confortable, puis un retour à
la tuile.

## Périmètre
- **Inclus** :
  - Clic (ou tap) sur l'image live de la tuile → ouverture d'un **overlay plein écran** affichant le
    même flux, en plus grand (idéalement ratio préservé, fond sombre).
  - Fermeture de l'overlay : clic hors image, bouton de fermeture, et touche `Échap`.
  - Réutilisation de la **même source de frames** que UC25 (endpoint same-origin `imouStream.ajax.php`)
    et de la même cadence « next-after-received » → aucun appel API supplémentaire, aucun nouveau binding.
  - Disponible en **dashboard ET mobile** (sur mobile, l'overlay occupe l'écran).
  - **Priorité de la tuile en plein écran** (ajout livré) : la caméra ouverte en overlay est regardée
    activement → elle obtient sa frame **immédiatement à chaque cycle**, en court-circuitant la file
    d'attente du limiteur de concurrence multi-caméras (cf. UC25-tech, révision 2026-06-22) ; les tuiles
    de fond gardent leur round-robin.
- **Exclu** :
  - La récupération/format du flux (UC22) et le widget de base (UC25) : déjà livrés.
  - Tout contrôle PTZ/zoom dans l'overlay (resterait au pavé PTZ d'UC25) — éventuelle évolution ultérieure.
  - Le vrai plein écran natif vidéo `<video>` (on reste sur des frames JPEG façon UC25).

## Esquisse Jeedom
- **100 % front**, dans les templates `core/template/{dashboard,mobile}/cmd.info.string.imouLive.html` :
  clic sur `.imouLiveImg` → création d'un overlay (`position:fixed`, plein écran, `z-index` élevé)
  contenant un grand `<img>` alimenté par la **même boucle de frames** que la tuile (ou une boucle
  dédiée pointant le même `base` URL).
- **Pas de nouvel endpoint, pas de nouvelle méthode PHP** : on consomme l'existant
  (`plugins/imou/core/ajax/imouStream.ajax.php`, `imou::resolveLiveHls`/`grabHlsFrame`). Le binding live
  est déjà mutualisé/caché côté serveur (URL HLS en cache) → l'agrandissement n'ajoute pas de coût API.
- **Visibilité / ressources** : l'overlay étant le seul élément visible quand il est ouvert, veiller à ne
  pas faire tourner deux boucles ffmpeg en parallèle (tuile + overlay) — réutiliser/mettre en pause la
  boucle de la tuile pendant l'overlay, ou partager la même Image de préchargement.
- **Respect de la CSP** : comme UC25, l'image vient de l'origine Jeedom (proxy ffmpeg), jamais de l'hôte
  de streaming externe → compatible `default-src 'self'`.

## Critères d'acceptation
- [x] Un clic/tap sur l'image live de la tuile ouvre un overlay plein écran affichant le même direct.
- [x] L'overlay se ferme via clic hors image, bouton dédié, et touche `Échap`.
- [x] L'agrandissement n'engendre **aucun appel API** supplémentaire ni nouveau binding (réutilise UC25).
- [x] À la fermeture (et au démontage de la tuile), aucune boucle de frames orpheline ne subsiste.
- [x] Fonctionne en dashboard ET en vue mobile.
- [x] La caméra en plein écran est **prioritaire** : frame immédiate à chaque cycle, sans attendre la file.
- [x] Toutes les chaînes UI introduites sont enveloppées `{{…}}` et traduites (en_US/de_DE/es_ES).

## Décisions (résolution des « À confirmer »)
- **Une seule boucle de frames partagée** (tuile↔overlay) : dans `loader.onload`, la même frame alimente
  l'`<img>` de la tuile ET le grand `<img>` de l'overlay → aucun second binding/ffmpeg.
- **Sortie du viewport overlay ouvert** : la branche d'arrêt de l'`IntersectionObserver` est gardée par
  `!overlayOpen` → tant que l'overlay est ouvert, la boucle ne s'arrête pas même si la tuile sort de l'écran.
- **Contenus de l'overlay** : nom de la caméra + bouton fermer uniquement (pas de bouton Arrêter dupliqué,
  pas de PTZ — exclus). Start/Stop restent sur la tuile.
