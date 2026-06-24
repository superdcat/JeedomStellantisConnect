# 12 — Switches dynamiques selon les capacités

**Phase :** Post-MVP · **Dépend de :** 07 · **Fichiers :** `core/class/imou.class.php`

## Objectif
Créer dynamiquement les commandes supplémentaires que la caméra supporte réellement, au-delà du
socle MVP.

## Périmètre
- **Inclus** : table de mapping capacité → commandes, création conditionnée par `ability`,
  exemples : `headerDetect` (humain), `whiteLight` (projecteur), alarme sonore/sirène,
  `linkagewhitelight`, mode nuit (`NightVision`).
- **Exclu** : PTZ (tâche 13), snapshot (14), live (15).

## Détails techniques
- Définir un tableau `CAPABILITIES = [ enableType => [name, subType, generic_type, inverse?] ]`.
- À la sync/`createCommands`, ne créer une commande que si la capacité est présente dans
  `ability` (ou si `getDeviceCameraStatus` répond sans erreur pour cet `enableType`).
- Factoriser `execute()` (tâches 08/09) pour traiter tout `enableType` de façon générique :
  routage `set_<enableType>_on/off` → `setDeviceCameraStatus`.
- Étendre `cron5` pour rafraîchir l'ensemble des cmd info dérivées des capacités.

## Critères d'acceptation
- [ ] Une caméra avec projecteur expose une commande lumière ; une caméra sans ne l'expose pas.
- [ ] L'ajout d'un nouveau `enableType` se fait en une ligne de la table de mapping.

## Notes / risques
- La sémantique exacte de chaque `enableType` (et les inversions) doit être vérifiée via la doc
  « Device Capability Switch » et/ou le code source de `imouapi`.

## ⚠️ Dette à reprendre ici (post-MVP 01 — projecteur/sirène)
La feature **projecteur/sirène** (`12-projecteur-sirene.md`) a été livrée **avant** UC12 : ses commandes
sont donc créées **INCONDITIONNELLEMENT** dans `imou::createCommands()` (décision utilisateur), sans
filtrage par `ability`. Quand UC12 sera implémenté, **intégrer ces switches au catalogue déclaratif**
et les **gater par `ability`** :
- `whiteLight` (projecteur) → ability `WhiteLight` / `WLV2`. Mapping DIRECT.
- `linkageSiren` (sirène sur détection) → ability `LinkageSiren`. Mapping DIRECT.
- `siren` (sirène MANUELLE, momentané) → ability `Siren`. **Pas de commande info** (état non fiable) ;
  `enableType='siren'` vient de `imouapi`, **hors liste officielle** des capability switches → garder
  ce switch dans une catégorie « à confirmer » du catalogue.

Le catalogue déclaratif UC12 doit aussi **remplacer le doublon** actuel : la whitelist d'`enableType`
de `imouCmd::setCameraEnable()` et la liste de `createCommands()` (couplage implicite à unifier).
