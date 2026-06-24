# UC — Image des équipements (miniature de la caméra)

**Domaine :** Gestion des appareils · **Dépend de :** MVP/06, UC51 (identifiants deviceId/productId) · **Complémentaire de :** UC74 (quota) · **Statut :** spécifié (tech)

> **Décision validée (2026-06-19)** — après recherche : **aucune source automatisable de photo
> *modèle*** (UUID opaques sur `static-website.imou.com`, pas de mapping `deviceModel → image`, pas de
> bibliothèque en ligne indexée par modèle). On utilise donc l'**image réelle de la caméra** comme
> miniature d'équipement. L'idée « photo du modèle embarquée + sélecteur de modèle » est **abandonnée**.
>
> **Révision (2026-06-19 bis)** — suite à un bug constaté en usage : la miniature restait **figée sur la
> photo custom** même après être repassé en « cliché automatique » dans l'app. On était alors parti sur une
> auto-détection du mode + bouton de rafraîchissement.
>
> **Révision (2026-06-19 ter) — modèle FINAL, validé utilisateur.** Après vérification doc IMOU :
> **l'API n'expose AUCUN indicateur de mode de cover** (`listDeviceDetailsByPage` ne renvoie que
> `channelPicUrl`, sans `coverType`/`coverMode`/`picType`). L'auto-détection du mode est donc **abandonnée**.
> Décision retenue : **l'utilisateur choisit lui-même la source** dans un menu déroulant, et c'est ce choix
> qui pilote la récupération de l'image. Règles ci-dessous. Détails : `55-images-modeles-equipements-tech.md`.

## Objectif / valeur
Afficher, pour chaque caméra, une **miniature représentative** (au lieu de l'image générique du plugin),
selon une **source choisie par l'utilisateur** (cliché live *vs* cover/photo de l'app), **avec aperçu
immédiat** dans la page de l'équipement et **rafraîchissable à la demande**, sans surconsommer le quota API.

## Sources d'image IMOU (confirmées doc 2026-06-19)
- **`channelPicUrl`** (`listDeviceDetailsByPage`, niveau canal) = la **cover** du canal (la « miniature »
  telle que vue dans l'app : photo personnalisée si l'utilisateur en a posé une). Obtenue gratuitement à la
  découverte (UC05) — pas d'appel supplémentaire. **L'API ne dit PAS** si cette cover est une photo perso ou
  un cliché auto (pas de champ de mode).
- **`setDeviceSnapEnhanced`** (repli **`setDeviceSnap`**) = **capture un cliché live frais** et renvoie une
  **URL d'image directe** (`result.data.url`, OSS, valide ~7 jours). C'est le **cliché automatique** à la
  demande. **Appel facturé au quota** (cf. UC74).

## Modèle retenu (choix utilisateur, pas d'auto-détection)
- **Menu déroulant « Source de la miniature »** (éditable par l'utilisateur), 3 valeurs :
  - **`snapshot` — Cliché automatique (live)** : capture un cliché frais via `setDeviceSnapEnhanced`.
  - **`cover` — Cover IMOU (photo de l'app)** : la cover `channelPicUrl` telle que configurée dans l'app.
  - **`icon` — Icône du plugin (par défaut)** : aucune image de caméra (aucun appel cloud) → l'icône du
    plugin est utilisée. Choix explicite de l'image par défaut.
- **Défaut à la CRÉATION = `snapshot`** : à la création de l'équipement (sync créateur), on récupère
  directement un **cliché live** comme miniature initiale.
- **Changement du menu déroulant** → récupération **automatique** de l'image correspondante et **mise à jour
  immédiate de l'aperçu** (sans enregistrer encore).
- **Cas « image inexistante »** : si la source choisie ne donne **aucune image** (ex. cover personnalisée
  absente, ou choix `icon`), **ce n'est pas une erreur** → la miniature captée (`picUrl`) est **vidée** et
  c'est **l'icône du plugin** qui est affichée puis **sauvegardée** (message d'info, pas de toast d'erreur).
  Un véritable échec d'appel cloud (caméra hors-ligne, quota…) reste signalé par un message d'erreur.
- **Bouton « Rafraîchir la miniature »** → récupère la **dernière** image selon la valeur **courante** du
  menu déroulant, et met à jour l'aperçu.
- **Enregistrement explicite** : les infos récupérées (source + URL d'image, éventuellement vide) ne sont
  persistées que quand l'utilisateur clique sur **« Enregistrer »** (champs liés au modèle, sauvés par le core).
- **Aperçu** dans la partie droite de l'onglet « Équipement » : affiche l'image courante, **ou l'icône du
  plugin** si aucune image. **Persiste après « Enregistrer »** (re-calculé après le re-rendu du core, pas de
  retour à l'icône).

## Périmètre
- **Inclus** :
  - Menu déroulant **éditable** « Source de la miniature » (`snapshot` / `cover`), défaut création `snapshot`.
  - **Récupération de l'image selon la source** : `setDeviceSnapEnhanced`/`setDeviceSnap` (snapshot) ou
    `channelPicUrl` (cover).
  - **Récupération auto au changement de source** + **aperçu** en direct.
  - **Captation à la CRÉATION** (cliché live) lors du refresh global qui crée l'équipement.
  - **Bouton « Rafraîchir la miniature »** : récupère la dernière image (source courante).
  - **Aperçu** à droite (image ou icône plugin).
  - **Override manuel** : champ URL d'image personnalisée (prioritaire sur tout le reste) — déjà en place.
- **Exclu** :
  - **Aucune auto-détection du mode** (non exposé par l'API).
  - **Aucun rafraîchissement de la miniature au refresh général** (ni cron `cron`/`cron5`, ni
    re-synchronisation d'un équipement **déjà existant**) → maîtrise du quota (cf. UC74).
  - L'icône du **plugin** (UC83).

## Esquisse Jeedom
- **À la création** (sync créateur, eqLogic NOUVEAU) : `thumbSource='snapshot'` + capture d'un cliché live
  (`setDeviceSnapEnhanced`, repli `setDeviceSnap`) → `picUrl`. Échec de capture toléré (miniature vide →
  icône plugin), sans interrompre la synchro des autres caméras.
- **Re-synchro d'un équipement existant** : **ne modifie pas** la miniature (ni `picUrl` ni `thumbSource`).
- **AJAX dédié** (page équipement) : reçoit l'`id` de l'eqLogic + la **source courante** (du menu déroulant),
  récupère l'URL correspondante (`channelPicUrl` ou `setDeviceSnap*`) et la **renvoie au client** (ne sauve
  PAS côté serveur — la persistance se fait via « Enregistrer »). N'altère jamais l'override manuel.
- **Côté client** : au `change` du menu déroulant **et** au clic « Rafraîchir », appel AJAX → màj du champ
  `picUrl` (lié au modèle) + de l'aperçu. « Enregistrer » persiste `thumbSource` + `picUrl`.
- **Priorité d'affichage** (`getImage`) : URL personnalisée manuelle > miniature captée (`picUrl`) >
  icône plugin par défaut.

## Critères d'acceptation
- [ ] À la **création** d'un nouvel équipement, un **cliché live** est récupéré automatiquement (défaut `snapshot`).
- [ ] Le menu déroulant « Source » est **éditable** (3 choix : cliché live / cover / icône plugin) ; changer
      sa valeur récupère **automatiquement** l'image et **met à jour l'aperçu**.
- [ ] Choisir **« Cover »** alors qu'aucune cover personnalisée n'existe → **pas de toast d'erreur** : l'aperçu
      passe à **l'icône du plugin** et c'est elle qui est **sauvegardée** (`picUrl` vidé).
- [ ] Choisir **« Icône du plugin »** → aperçu = icône plugin, **aucun appel cloud**, `picUrl` vidé.
- [ ] Le **bouton « Rafraîchir »** récupère la dernière image selon la source **courante** du menu déroulant.
- [ ] L'**aperçu** à droite affiche l'image récupérée, ou **l'icône du plugin** si aucune image, et **ne saute
      pas** sur l'icône après un **« Enregistrer »**.
- [ ] Les nouvelles infos ne sont **persistées qu'après « Enregistrer »**.
- [ ] Le **refresh général** (cron, re-synchro d'un équipement existant) **ne modifie pas** la miniature.
- [ ] L'**URL d'image personnalisée** (override manuel) reste prioritaire et survit à tout rafraîchissement.

## Notes
- **Quota** : le défaut `snapshot` implique **1 appel `setDeviceSnapEnhanced` par caméra à la création**
  (une fois), puis 1 appel par changement de source/clic « Rafraîchir » — actions explicites. Jamais au cron
  ni au re-sync (cf. UC74). Anti-spam : bouton désactivé pendant l'appel.
- **`setDeviceSnap`** (repli) : max 1 capture / 3 s ; URL valide ~7 j (re-cliquer rafraîchit).
