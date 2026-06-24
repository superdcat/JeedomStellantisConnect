# UC — Panneau caméras (page dédiée dans le menu : mur de live + commandes)

**Domaine :** Vidéo / images · **Dépend de :** UC25 (widget live-snapshot + limiteur de concurrence),
UC15 (PTZ), UC11/UC12 (switches sirène/projecteur) · **Complémentaire de :** UC26 (plein écran) ·
**Statut :** 📝 à implémenter

## Objectif / valeur
Fournir une **page-panneau dédiée**, accessible depuis le **menu Jeedom** (et non une simple tuile sur le
dashboard générique), affichant un **mur de caméras** : toutes les caméras IMOU (ou une sélection) en
**grille**, chacune avec sa **vue live** et une **barre de commandes compacte** (PTZ directionnel sans zoom,
sirène, projecteur, selon l'équipement). C'est un **tableau de bord de surveillance** « tout-en-un »,
**activable depuis la config du plugin** (entrée de menu masquée par défaut).

> **Distinction importante** : il s'agit bien d'une **page** (vue plein écran ouverte depuis le menu),
> pas d'un widget de commande posé sur un dashboard. La tuile/widget live reste couverte par UC25/UC26 ;
> ici on assemble une **page plugin** qui réutilise leur mécanique.

## Périmètre
- **Inclus** :
  - Une **page plugin** (HTML, type `desktop/php/…`) listant les caméras IMOU en **grille responsive**.
  - Pour **chaque caméra**, une cellule contenant :
    - la **vue live** — réutilise intégralement la mécanique UC25 (frames JPEG same-origin via
      `imouStream.ajax.php`, **limiteur de concurrence FIFO global**, clic = **plein écran UC26 + priorité**) ;
    - une **barre de commandes compacte** :
      - **PTZ directionnel** ↑ ↓ ← → (`ptz_up`/`ptz_down`/`ptz_left`/`ptz_right`) — **sans zoom** ;
      - **Sirène** (`sirene_on`, déclenchement momentané) ;
      - **Projecteur** marche/arrêt (`projecteur_on`/`projecteur_off`).
  - **Affichage conditionnel par capacité** : un bouton n'apparaît que si la commande existe sur la caméra
    (caméra fixe → pas de flèches ; sans sirène/projecteur → boutons masqués). Résolu via les commandes
    réellement créées sur chaque eqLogic.
  - **Activation via la config du plugin** : un interrupteur (ex. « Activer la page Panneau caméras »,
    **désactivé par défaut**) qui **fait apparaître l'entrée de menu** et rend la page accessible.
  - **Contrôle d'accès** : page utilisable par un utilisateur **non-admin** ; chaque caméra n'est affichée
    que si l'utilisateur a le droit de lecture (`hasRight('r')`) sur l'eqLogic (cohérent avec UC25).
  - Rendu **desktop ET mobile** (grille qui se reflowe ; barres de boutons tactiles).
- **Exclu** :
  - Le **zoom** PTZ (panneau « cadrage simple »).
  - Tout **nouvel appel cloud / endpoint** : la page réutilise `imouStream.ajax.php` (live, UC25) et les
    commandes existantes (`ptz_*`, `sirene_*`, `projecteur_*`) via `jeedom.cmd.execute`.
  - Les réglages image (UC24), vision nocturne (UC23), projecteur **minuté** (UC14) et sirène de
    **détection** (`sirene_detection_*`).
  - L'édition/agencement libre de la grille (drag & drop, tailles) — ordre simple (par nom) en v1.

## Esquisse Jeedom
- **Page plugin** : un fichier type `desktop/php/<page>.php` (à nommer, ex. `imouPanel.php`) rendant la
  grille. Inclut le core, vérifie l'authentification, parcourt `eqLogic::byType('imou')` filtré par
  `hasRight('r')`, et génère une cellule par caméra.
- **Entrée de menu** : à brancher selon le mécanisme Jeedom retenu (cf. *À confirmer*) ; sa **visibilité
  est conditionnée** au flag de config plugin.
- **Live par cellule** : on **réutilise la mécanique du widget `imouLive`** (boucle de frames, sémaphore
  global `window.__imouLiveSched`, overlay plein écran + priorité). Idéalement le **même JS mutualisé**
  (un mur de N caméras rend le **limiteur de concurrence UC25 indispensable**).
- **Commandes** : résolution `logicalId → id` par caméra (via `byEqLogic` ou injection PHP des id à la
  génération de la page) ; exécution par `jeedom.cmd.execute({id})` (droits/CSRF gérés par le core).
- **CSP / quota** : inchangés vs UC25 (frames same-origin, ~0 appel API par image ; PTZ/sirène/projecteur =
  appels existants à l'usage). Le limiteur FIFO borne la charge ffmpeg même avec beaucoup de caméras.

## Critères d'acceptation
- [ ] La config plugin propose un interrupteur d'activation de la page (désactivée par défaut).
- [ ] Activée : une **entrée de menu** ouvre une page affichant la grille des caméras ; désactivée : pas d'entrée.
- [ ] Chaque cellule montre la vue live (avec limiteur de concurrence et clic = plein écran prioritaire).
- [ ] Les flèches PTZ (↑↓←→, **sans zoom**) pilotent la caméra ; sirène / projecteur n'apparaissent que si équipée.
- [ ] Un utilisateur non-admin ne voit que les caméras sur lesquelles il a le droit de lecture.
- [ ] Aucun appel cloud nouveau : tout passe par `imouStream.ajax.php` et les commandes existantes.
- [ ] Rendu correct desktop ET mobile (grille reflowable).
- [ ] Toutes les chaînes UI introduites sont enveloppées `{{…}}` et traduites (en_US/de_DE/es_ES).

## À confirmer
- **Mécanisme exact d'ajout d'une page au menu Jeedom** pour un plugin : page `desktop/php/` dédiée +
  comment l'inscrire/masquer dans le menu selon le flag de config (à vérifier dans la doc/le core Jeedom —
  ex. la page principale du plugin vs une page additionnelle ; rôle de `info.json`).
- **Accès** : page réservée aux utilisateurs ayant au moins une caméra en lecture ; admin-only ou non.
- **Sélection des caméras** : toutes les caméras `imou` vs une **sélection** configurable (et l'ordre).
- **Sirène** : déclenchement momentané (`sirene_on`) vs bascule on/off.
- **Projecteur** : simple on/off vs reflet de l'état (`projecteur_state`).
- **Mutualisation du JS live** avec `imouLive` (UC25/UC26) : factoriser la boucle/sémaphore/overlay dans un
  asset commun pour éviter la duplication (et la divergence) entre le widget et la page-panneau.
- **Démarrage des flux** : ne lancer le live que pour les cellules visibles (IntersectionObserver, comme
  UC25) afin de ne pas binder N caméras d'un coup à l'ouverture de la page.
