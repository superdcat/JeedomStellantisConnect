# 33 — Historique des trajets

**Domaine :** Localisation / trajets · **Dépend de :** UC31 · **Statut :** à spécifier (faisabilité à valider)

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
- [ ] Le dernier trajet (distance, durée) est calculé et lisible dans Jeedom.
- [ ] La reconstruction ne dépend pas d'un endpoint déprécié/inaccessible.

## À confirmer
- Disponibilité réelle d'un endpoint trips côté consommateur (probablement non) → confirmer l'approche
  reconstruction. Cf. `stellantis-implementations-reference.md` (psa_car_controller trips).
