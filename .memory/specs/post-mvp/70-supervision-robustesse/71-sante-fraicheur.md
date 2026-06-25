# 71 — Santé du plugin & fraîcheur des données

**Domaine :** Supervision / robustesse · **Dépend de :** MVP/09 · **Statut :** à spécifier

## Objectif / valeur
Une page **Santé** Jeedom claire : état d'auth (OAuth + remote token), état du démon (si présent),
fraîcheur par véhicule, dernier résultat de commande, consommation API — pour diagnostiquer en un coup d'œil.

## Périmètre
- **Inclus** : `stellantis::health()` agrégeant : authentifié ? remote token valide ? démon up ? par
  véhicule : `last_update`, en ligne/privacy, dernier `last_command_result` ; lien vers la doc.
- **Exclu** : la collecte elle-même (UC74 token, UC77 stats, MVP/09 fraîcheur).

## Détails techniques
- Implémenter le hook `health()` (tableau de lignes statut Jeedom) en consommant les états déjà calculés
  (MVP/09, UC74, UC77) — **pas** de nouvel appel API juste pour la page Santé.

## Critères d'acceptation
- [ ] La page Santé montre l'état d'auth, du démon, et la fraîcheur par véhicule.
- [ ] Aucun appel API supplémentaire n'est déclenché par l'affichage de la Santé.

## À confirmer
- Format exact attendu par le core pour `health()` (recouper la source du core / un plugin de référence).
