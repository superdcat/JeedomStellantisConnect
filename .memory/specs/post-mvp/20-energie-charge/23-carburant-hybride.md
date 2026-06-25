# 23 — Carburant & véhicules hybrides

**Domaine :** Énergie / charge · **Dépend de :** MVP/07 · **Statut :** à spécifier

## Objectif / valeur
Bien gérer les motorisations **thermiques** et **hybrides** : remonter niveau et autonomie carburant en
parallèle de la partie électrique, sans dupliquer ni mélanger les deux énergies.

## Périmètre
- **Inclus** : commandes info `fuel_level` / `autonomy_fuel`, et coexistence propre élec+carburant sur un
  hybride (deux entrées `energies[]`).
- **Exclu** : commandes (pas de commande de charge sur thermique).

## Détails techniques
- `energies[]` peut contenir **plusieurs** entrées (`Electric` **et** `Fuel`) sur un hybride → router
  chaque type vers ses propres commandes (`battery_soc`/`autonomy` vs `fuel_level`/`autonomy_fuel`).
- Sur thermique pur : créer uniquement `fuel_level`/`autonomy_fuel` (+ km, position, portes) ; **pas** de
  commandes EV.
- Éventuelle autonomie **totale** combinée (info dérivée) sur hybride.

## Critères d'acceptation
- [ ] Un hybride expose à la fois SOC/autonomie élec et niveau/autonomie carburant, sans collision.
- [ ] Un thermique pur n'a aucune commande EV.

## À confirmer
- Clés exactes pour le carburant (`fuel`/`Fuel`/`level`) et présence d'une autonomie combinée — data-model.
