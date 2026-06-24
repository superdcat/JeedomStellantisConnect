# UC — Rafraîchissement automatique par défaut à la création (aligné sur la visibilité)

**Domaine :** Supervision / robustesse · **Dépend de :** UC16 (commandes affichées par défaut), UC73 (exclusion du rafraîchissement `noPoll`/`pollable`) · **Statut :** à spécifier (tech)

## Objectif / valeur
Régler **automatiquement, dès la création des commandes**, le périmètre du rafraîchissement au cron pour
qu'il **ne porte que sur les commandes affichées par défaut** (UC16). Toutes les autres commandes info
pollables sont créées avec la case « **Exclure du rafraîchissement automatique** » (UC73) **déjà cochée**.
Bénéfice : consommation d'appels API minimale **par défaut** (palier gratuit, cf. UC72/UC74), sans
réglage manuel — l'utilisateur ne paie du quota que pour le peu qu'il voit.

## Décision (validée utilisateur 2026-06-19)
Polarité retenue : **on rafraîchit UNIQUEMENT les commandes affichées par défaut (UC16)** ; on **exclut**
(`noPoll=1`) toutes les autres infos pollables. (Et non l'inverse : les commandes visibles **restent**
rafraîchies, leur état est à jour.)

## Périmètre
- **Inclus** : valeur **par défaut** du flag `noPoll` (UC73) **à la création** d'une commande info
  pollable, **dérivée de sa visibilité par défaut** (UC16) :
  - commande **affichée par défaut** (UC16) → **pas** de `noPoll` (absent = pollée) ;
  - commande **masquée par défaut** → `noPoll = 1` (exclue du rafraîchissement auto).
- **Exclu** : toute modification d'une commande **existante** (le défaut est posé **une seule fois à la
  création**, **jamais** réécrasé au re-sync → le choix manuel ultérieur de l'utilisateur est préservé,
  exactement comme la visibilité UC16 et le `noPoll` manuel UC73).

## Esquisse Jeedom
- S'appuie sur l'ensemble « visible par défaut » défini par UC16 et sur le marqueur `pollable`/le flag
  `noPoll` de UC73.
- À la **création** d'une commande info pollable : poser `configuration.noPoll = 1` **si et seulement si**
  la commande n'est **pas** dans l'ensemble visible par défaut (UC16).
- ⚠️ **Point technique clé** : ce défaut doit être posé **à la création uniquement** (`!is_object($cmd)`),
  PAS à chaque passage. Or `creerCommande()` **repose** aujourd'hui les clés de `options['configuration']`
  à chaque synchro → poser `noPoll` via ce canal l'**écraserait** à chaque re-sync (le choix utilisateur
  sauterait). Il faut donc un mécanisme « configuration **à la création seulement** » dans
  `creerCommande()` (ex. option `configurationOnCreate`), **mutualisable avec la visibilité UC16** (même
  besoin : valeur initiale posée une fois, puis laissée à l'utilisateur).

## Critères d'acceptation
- [ ] À la synchro d'une **nouvelle** caméra, seules les commandes affichées par défaut (UC16) sont
      rafraîchies au cron ; les autres infos pollables existent avec « Exclure du rafraîchissement
      automatique » **coché**.
- [ ] Une commande affichée par défaut n'est **jamais** pré-exclue (son état reste à jour).
- [ ] Cocher/décocher manuellement « Exclure » survit à une **re-synchronisation** (défaut non réimposé).
- [ ] Aucune régression sur une caméra **déjà** synchronisée avant l'UC (le défaut ne s'applique qu'aux
      commandes nouvellement créées).

## À confirmer / notes
- **Articulation fine avec UC16** : la règle se fonde sur la **visibilité par défaut de la commande info
  elle-même**. La visibilité par défaut des **infos d'état** des capacités visibles est un point « À
  confirmer » d'UC16 → à trancher conjointement (une info d'état visible doit logiquement être pollée).
- **Mutualisation `configurationOnCreate`** : à introduire dans `creerCommande()` et à partager avec UC16
  (visibilité) — invariant commun « valeur initiale posée une fois, jamais réimposée ».
- Cohérent avec UC73 (sémantique `noPoll` inversée, absent = pollé) et UC74 (le gain de quota par défaut
  devient mesurable).
