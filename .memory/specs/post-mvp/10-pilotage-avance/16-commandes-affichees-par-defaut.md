# UC — Commandes affichées par défaut (visibilité à la création)

**Domaine :** Pilotage avancé · **Dépend de :** MVP/07, UC11, UC12, UC15 · **Statut :** à spécifier (tech)

## Objectif / valeur
À la **création** des commandes (synchronisation/découverte), n'afficher par défaut que les commandes
réellement utiles au quotidien, pour ne pas noyer l'utilisateur sous des dizaines de commandes
(propriétés IoT, états, réglages avancés). L'utilisateur garde la possibilité de rendre visible
n'importe quelle commande ensuite.

## Décisions (demande utilisateur)
- **Visibles par défaut** uniquement :
  - Allumer / éteindre la **caméra** (`closeCamera` → camera_on/off).
  - Activer / désactiver la **surveillance** (`motionDetect` → surveillance_on/off).
  - Faire sonner la **sirène (manuelle)** (`siren` → sirene_on/off).
  - Gérer le **PTZ** (toutes les commandes `ptz_*` : directions + zoom).
  - Allumer / éteindre le **projecteur** (`whiteLight` → projecteur_on/off).
- **Masquées par défaut** : tout le reste (sirène sur détection, projecteur minuté, propriétés IoT
  auto-découvertes, vision nocturne, réglages image, infos d'état/compte à rebours, identifiants…).

## Périmètre
- **Inclus** : choix de la visibilité **au moment de la création** de chaque commande (catalogues
  switches + PTZ + IoT) ; valeur par défaut par entrée.
- **Exclu** : toute modification de visibilité d'une commande **déjà existante** (la personnalisation
  utilisateur est préservée — la visibilité n'est posée qu'à la création, jamais réécrasée au re-sync) ;
  la suppression de commandes (hors périmètre, cf. UC12).

## Esquisse Jeedom
- Ajouter un flag **`defaultVisible`** (bool) à chaque entrée des catalogues (`commandCatalog()`,
  `ptzCatalog()`, et règle pour les commandes IoT auto-découvertes → `false` par défaut).
- `creerCommande()` pose `setIsVisible(defaultVisible ? 1 : 0)` **uniquement à la création**
  (`!is_object($cmd)`), comme le `setName`/`setIsVisible` actuel — sans toucher aux commandes
  existantes (idempotence : la visibilité reste un choix utilisateur après coup).
- État (info) associé à une capacité visible : décision à figer en tech — l'**action** est visible,
  l'**info d'état** peut l'être aussi (affichage dashboard) ou rester masquée. *À confirmer.*

## Critères d'acceptation
- [ ] À la synchro d'une nouvelle caméra, seules les commandes de la liste « visibles par défaut »
      sont cochées « Afficher » ; les autres sont créées mais masquées.
- [ ] Rendre visible/masquer une commande puis re-synchroniser **ne réécrase pas** ce choix.
- [ ] Une capacité absente de la caméra ne crée aucune commande (inchangé, cf. UC12).

## À confirmer
- Visibilité par défaut des commandes **info d'état** des capacités visibles (action seule vs action+état).
- Comportement souhaité pour le **projecteur minuté** (`whiteLightTimer`) : masqué par défaut (décision
  actuelle) ou aligné sur le projecteur persistant.
