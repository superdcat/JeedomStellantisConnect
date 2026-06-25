# 15 — Préconditionnement climatique (immédiat)

**Domaine :** Commandes à distance · **Dépend de :** UC11, UC12 · **Statut :** à spécifier

## Objectif / valeur
Activer/désactiver à distance la climatisation/chauffe de l'habitacle (préconditionnement), pour partir
dans un véhicule tempéré (cas d'usage domotique fort : déclenché par scénario, réveil, géoloc…).

## Périmètre
- **Inclus** : commandes action `precond_on` / `precond_off` (publish MQTT immédiat), MAJ de l'info
  `precond_status`.
- **Exclu** : la **programmation** récurrente (jours/heures) → variante avancée (peut rejoindre UC22 ou
  une UC dédiée ultérieure).

## Détails techniques
- Publish MQTT (démon) : payload type `{"asap":value,"programs":[...]}` (cf. `RemoteClient`). `precond_on`
  → activation immédiate (`asap`), `precond_off` → désactivation.
- Lier à l'info `precond_status` (MVP07/data-model) pour un widget on/off.
- ⚠️ Souvent conditionné à un **seuil de batterie** et au **branchement** sur certains modèles → remonter
  les refus proprement (UC18).

## Critères d'acceptation
- [ ] « Activer/Désactiver le préconditionnement » publie la commande ; `precond_status` reflète l'état.
- [ ] Un refus véhicule (batterie/non branché) remonte un message clair.

## À confirmer
- Structure exacte du payload `programs` (récurrence) et conditions d'acceptation par modèle.
