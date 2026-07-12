# 33 — Historique des trajets

**Domaine :** Localisation / trajets · **Dépend de :** UC31 · **Statut :** implémenté (2026-07-12, reconstruction locale — cf. `33-tech.md`)

## Objectif / valeur
Exposer les **trajets** (départ/arrivée, distance, durée, conso) à des fins de suivi/statistiques, à la
manière de `psa_car_controller` (qui reconstruit les trajets à partir des positions/odomètre).

## Périmètre
- **Inclus** : soit consommation d'un endpoint « trips » s'il est accessible, soit **reconstruction**
  locale (détection arrêt→départ via `kinetic.moving`/position/odomètre) et stockage.
- **Exclu** : cartographie détaillée du tracé (lourd ; éventuel plus tard).

## Détails techniques
- ⚠️ Les endpoints **trips** de l'API officielle B2B ont été **dépréciés** (changelog) et ne sont de
  toute façon pas accessibles côté consommateur → privilégier la **reconstruction** (comme
  `psa_car_controller`) à partir des données de position/kilométrage déjà collectées.
- Stockage léger (cache/fichier) ; restitution en commandes info (dernier trajet : distance/durée) +
  éventuelle page récap.

## Critères d'acceptation
- [x] Le dernier trajet (distance, durée) est calculé et lisible dans Jeedom (commandes info
  `trip_distance`/`trip_duration` historisées → l'historique Jeedom EST l'historique des trajets).
- [x] La reconstruction ne dépend pas d'un endpoint déprécié/inaccessible (100 % locale, à partir du
  `/status` déjà récupéré au cron : `kinetic.moving`/`ignition.type`/`odometer.mileage`/position).

## À confirmer
- ~~Disponibilité réelle d'un endpoint trips côté consommateur~~ → **tranché (2026-07-12)** : aucun
  endpoint trips accessible → **reconstruction locale** retenue (machine à états calquée sur UC24, signal
  robuste `moving OU ignition`). Cf. `33-tech.md` et `stellantis-data-model.md` § 2.3.
- Recette réelle : détection effective d'un trajet sur véhicule vivant, apparition dans l'historique,
  absence de fragmentation en usage (dépendante de la présence de `kinetic`/`ignition` selon millésime).
