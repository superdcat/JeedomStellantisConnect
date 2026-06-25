# 31 — Position GPS

**Domaine :** Localisation / trajets · **Dépend de :** MVP/07 · **Statut :** à spécifier

## Objectif / valeur
Remonter et exploiter la **position** du véhicule (latitude/longitude, cap, horodatage, qualité) au-delà
de la simple chaîne `lat,lon` du MVP : commandes structurées + base pour la carte (UC32) et le geofencing
(UC34).

## Périmètre
- **Inclus** : appel `GET /user/vehicles/{id}/lastPosition` (GeoJSON), commandes info
  `latitude`, `longitude`, `position` (`lat,lon`, `GEOLOC`), `heading`, `position_updated`, `gps_signal`.
- **Exclu** : widget carte (UC32), zones (UC34).

## Détails techniques
- GeoJSON : `geometry.coordinates = [lon, lat, (alt)]` (⚠️ **ordre lon, lat**),
  `properties.{updatedAt, heading, signalQuality, type}` (cf. data-model § 2.2).
- Commande `position` au format `lat,lon` (generic_type `GEOLOC`) pour compat widgets/scénarios Jeedom.
- Fraîcheur : `position_updated` ≠ `last_update` global (la position a son propre horodatage).

## Critères d'acceptation
- [ ] `latitude`/`longitude`/`position` reflètent `lastPosition` et se rafraîchissent au cron.
- [ ] L'ordre des coordonnées GeoJSON (lon,lat) est correctement inversé en `lat,lon`.
- [ ] Position absente (privacy/pas de fix) gérée proprement (pas d'erreur).

## À confirmer
- Présence de l'altitude/heading selon modèle ; comportement en mode privacy (UC75).
