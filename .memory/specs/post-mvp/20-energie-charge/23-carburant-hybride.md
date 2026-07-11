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
- **Résolu (UC23, 2026-07-11)** : clés carburant = entrée `energies[].type == 'Fuel'`, niveau
  `energies[].level` → `fuel_level`, autonomie `energies[].autonomy` → `autonomy_fuel` (cf. data-model
  § 2.1). **Aucune autonomie combinée côté API** : `autonomy_total` est une valeur **dérivée** (somme élec
  + carburant) calculée dans `parseStatus`, émise/créée paresseusement seulement sur un hybride fournissant
  les deux autonomies. Détail : `23-tech.md`.

> **Implémenté (UC23, 2026-07-11)** — 100 % lecture/parsing PHP (`core/class/stellantis.class.php`) :
> scission `autonomy` (élec) / `autonomy_fuel` (carburant) dans `parseStatus`, création par motorisation
> dans `createCommands` (thermique pur → `fuel_level`/`autonomy_fuel`, aucune commande EV), dérivée
> `autonomy_total` sur hybride ; migration `install.php` masquant l'ancien `autonomy` figé des thermiques
> déjà découverts. Les 2 AC sont couverts par construction (voir `23-tech.md`), à cocher après recette
> sur véhicule réel (`81-validation-manuelle.md`).
