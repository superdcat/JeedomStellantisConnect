# 05 — Découverte des véhicules

**Phase :** MVP · **Dépend de :** 03 · **Fichiers :** `core/class/stellantis.class.php`

## Objectif
Récupérer la liste des véhicules du compte et leurs métadonnées (id API, VIN, marque, modèle,
motorisation) pour pouvoir créer les équipements.

## Périmètre
- **Inclus** : appel `GET /user/vehicles`, normalisation en tableau PHP exploitable (gestion pagination
  si présente).
- **Exclu** : création des eqLogic (→ 06), commandes info (→ 07).

## Détails techniques
- Méthode `stellantis::discoverVehicles(): array` retournant une liste de :
  `['id','vin','brand','label','model','energy']` (motorisation : Electric / Hybrid / Thermal si dispo).
- Endpoint : `GET /user/vehicles` via `stellantisApi::callWithToken`. Gérer la **pagination** si l'API
  pagine (`_links`/`offset`) et concaténer.
- `id` (id API ≠ VIN) **conservé** : nécessaire pour les appels `/status`/`/lastPosition`. `vin` =
  identité stable (futur `logicalId`).
- La **motorisation** (déduite de la réponse, ou plus tard de `/status` `energies[].type`) conditionnera
  les commandes créées en 07 (pas de SOC sur thermique, etc.).

## Critères d'acceptation
- [ ] `stellantis::discoverVehicles()` renvoie tous les véhicules du compte (pagination incluse).
- [ ] Chaque entrée contient au minimum `id`, `vin`, `brand` (+ `label`/`model` si fournis).
- [ ] Gère proprement un compte sans véhicule (tableau vide, pas d'erreur).

## Notes / risques
- Champs exacts (`label` vs `model`, présence de la motorisation dans `/vehicles`) à **confirmer** contre
  une réponse réelle / `psa_car_controller` (`models/*`) — cf. `.memory/analyse/stellantis-data-model.md`.
- Le mode privacy véhicule peut masquer certains véhicules/données (cf. UC75).
