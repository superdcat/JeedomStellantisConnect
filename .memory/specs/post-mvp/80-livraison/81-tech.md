# Spec technique — 81 (Recette fonctionnelle manuelle)

> **Nature :** livrable **100 % documentaire**. Aucun code, aucun appel API, aucune chaîne UI, aucune
> dépendance. Le seul artefact modifié est le fichier de recette lui-même
> `.memory/specs/post-mvp/80-livraison/81-validation-manuelle.md`. Les sections « Server vs Client »,
> « Validation », « Server Actions / API », « Dépendances » du gabarit sont donc **sans objet** — on les
> conserve pour la forme, remplies « N/A + justification ».

## Architecture

- **Fichier modifié (unique) :** `.memory/specs/post-mvp/80-livraison/81-validation-manuelle.md`.
- **Objectif de cette itération :** satisfaire l'unique critère d'acceptation de l'UC81 — *« chaque UC
  livrée a au moins un scénario de recette observable »* — en **complétant** la checklist pour les **28 UC
  livrées non encore couvertes** : UC12, 13, 14, 15, 16, 17, 18, 21, 22, 24, 31, 32, 33, 34, 41, 42, 43,
  44, 51, 52, 53, 54, 61, 71, 74, 75, 76, 83.
- **UC déjà couvertes (blocs préservés tels quels) :** Auth (MVP/01-04), Token (MVP/03), Découverte
  (05-06), Télémétrie (07-08), Robustesse (09-10), Socle démon (UC11), Résilience démon (UC19),
  Carburant/hybride (UC23), Anti-ban (UC72), Batterie 12 V (UC73), Stats API (UC77).
- **UC hors périmètre (non livrées, git s'arrête à UC77+UC83) :** UC82 (packaging), UC84 (i18n formelle) —
  la recette ne couvre **que** les UC livrées.

### Réorganisation (légère, mécanique — décision validée)

Le corps `## Détails techniques — checklist` (aujourd'hui une liste plate à l'ordre ~chronologique) est
découpé en **sous-titres `###` par domaine**, calqués sur l'arborescence du `README.md` :
`Socle MVP (01→10)` · `10-commandes-distance` · `20-energie-charge` · `30-localisation-trajets` ·
`40-entretien-alertes` · `50-gestion-vehicules` · `60-configuration-avancee` ·
`70-supervision-robustesse` · `80-livraison`.

- Les **blocs existants sont déplacés MOT POUR MOT** dans leur section de domaine (diff = « déplacement
  pur », vérifiable sans relire le texte déjà validé). **Exception documentée :** le bloc UC23
  (Carburant/hybride), aujourd'hui près de la Télémétrie MVP, est déplacé en `20-energie-charge` par
  cohérence de domaine.
- Le placeholder vague *« Commandes (post-mvp 12-x) »* est **remplacé** par les blocs complets UC12→UC18.
- Les **28 nouveaux blocs** sont insérés à leur place de domaine.
- Bénéfice : un futur split de fichier (si la recette devient ingérable) devient un copier-coller par
  section, sans renumérotation.

### Nouvelle section « Conventions de ce document »

Un mini-préambule (~10 lignes) fige, pour les futurs cycles `/feature` (contexte neuf à chaque fois) :
1. **Ordre** = domaine puis n° d'UC.
2. **Gabarit de bloc** : `**Titre (MVP/NN | post-MVP NN, ajouté AAAA-MM-JJ — les K AC de \`NN-nom.md\`)**`
   puis des sous-scénarios **numérotés et observables** (étapes + résultat attendu). La **date « ajouté »
   devient obligatoire** (aujourd'hui incohérente : présente sur Token/Carburant/Socle démon/Résilience,
   absente sur Auth/Découverte/Télémétrie/Robustesse/Anti-ban/Batterie/Stats — blocs historiques non
   rétro-corrigés, la règle vaut pour les ajouts).
3. **UC doc-only (0 code)** : pointer vers un scénario existant qui couvre l'AC, **ne jamais fabriquer**
   un « à observer sur Jeedom » (cas UC53).
4. **Écart spec-vs-réel** : marquer `⚠️ Écart vs spec initiale : <prévu> non retenu — <raison>, cf.
   \`NN-tech.md\`` **et** formuler en **assertion vérifiable positive** (ex. « vérifier qu'AUCUN champ %
   n'est proposé — décision assumée, pas un bug »).
5. Rappel : ne jamais prétendre un comportement runtime validé sans l'avoir **constaté** ici.

## Server vs Client

**N/A.** Document interne (`.memory/`), pas de composant exécutable. Les scénarios *décrivent* des surfaces
déjà livrées (commandes info/action, page Santé, bandeau page plugin, centre de messages Jeedom, logs
`stellantis`/`stellantis_daemon`, widgets dashboard) sans en introduire.

## Validation

**N/A (pas de code).** La « validation » propre à ce livrable est **éditoriale** :
- **Fidélité au réel** : chaque scénario s'ancre sur le comportement **réellement implémenté** (source de
  vérité = `CLAUDE.md` + code), **pas** sur le texte *cible* des specs fonctionnelles figées avant codage.
  Divergences confirmées à refléter : UC42 (aucune commande « pression par roue / en bar » — que le binaire
  `tyre_alert`), UC22 (aucun seuil % — non supporté par le contrat MQTT), UC18 (aucun re-publish auto sur
  code 400 — message « session renouvelée, réessayez »), UC51 (aucune commande `vin`/`model` — que l'info
  string `label`), UC44 (8 commandes `door_<id>` **statiques** + `opening_alert`, pas de création « si
  présent »), UC33 (8 commandes : `moving`/`ignition`/`trip_distance`/`trip_duration`/`trip_start`/
  `trip_end` + 2 positions).
- **Couverture** : après écriture, contrôle que chaque UC livrée a ≥ 1 scénario (voir liste ci-dessus).
- **Garde anti-fuite** : `grep` du diff pour tout VIN réel / token / URL interne collés par erreur dans un
  exemple (pas une review sécu — aucun code exécutable).

## Server Actions / API

**N/A.** Aucun endpoint, aucune action AJAX, aucun topic MQTT créé ou modifié. Les scénarios *référencent*
des appels/commandes existants (ex. `/status`, `/lastPosition`, `/alerts`, `/maintenance`, service MQTT
`VehCharge`/`Doors`/…), à titre d'observable, sans les définir.

## Dépendances

**Aucune.** Pas de paquet, pas d'extension PHP, pas de modification de `packages.json`/`info.json`.

## Hors périmètre (dettes signalées, PAS traitées ici)

- **Statuts périmés des specs fonctionnelles** : ~25 fichiers `NN-*.md` livrés affichent encore
  `Statut : à spécifier`. Nettoyage **hors périmètre** de la recette (le fusionner diluerait la revue
  ligne-à-ligne de 81). → **commit séparé mécanique** ultérieur ; une **ligne NB** est ajoutée à la section
  `## Notes` de 81 pour ne pas perdre la dette.
- **i18n / traduction** : sans objet — fichier interne français, aucune clé `{{...}}`/`__()`.
