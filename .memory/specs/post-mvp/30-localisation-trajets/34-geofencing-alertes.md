# 34 — Geofencing & alertes de zone

**Domaine :** Localisation / trajets · **Dépend de :** UC31 (position) · **Statut :** implémenté (2026-07-12, Option A — zone domicile unique en config plugin — cf. `34-tech.md`)

## Objectif / valeur
Permettre des **scénarios basés sur la localisation** : le véhicule entre/sort d'une zone (domicile,
travail), déclenche une action Jeedom (ouvrir le portail, allumer le chauffage, notifier).

## Périmètre
- **Inclus** : commande info dérivée `at_home` (ou zone courante) calculée à partir de la position et de
  zone(s) configurées (centre + rayon), historisable → exploitable en scénario.
- **Exclu** : éditeur de zones cartographique avancé (saisie simple lat/lon + rayon suffit au début).

## Détails techniques
- Config (plugin ou par véhicule) : une ou plusieurs zones `{nom, lat, lon, rayon_m}`.
- Calcul de distance (haversine) entre position courante et chaque zone → info `current_zone`/`at_home`
  (binary). Mise à jour au cron (UC08).
- Laisser l'utilisateur bâtir les automatisations dans les **scénarios Jeedom** à partir de cette info.

## Critères d'acceptation
- [x] Une zone « domicile » configurée fait passer `at_home` à 1/0 selon la position (config plugin
  `home_lat`/`home_lon`/`home_radius`, calcul haversine au cron dans `suivreGeofencing`).
- [x] L'info est historisée (`at_home` binary, `generic_type=PRESENCE`, historisée) et utilisable comme
  déclencheur de scénario Jeedom natif.

## À confirmer
- ~~Tolérance/hystérésis~~ → **tranché (2026-07-12)** : **hystérésis asymétrique** (entrée = rayon, sortie
  = rayon + `HOME_HYSTERESIS_M` 50 m) → pas de clignotement au bord tant que le bruit GPS < 50 m. Rejet
  d'un point GPS aberrant isolé via `gps_signal` = amélioration future (hors AC). Cf. `34-tech.md`.
- Recette réelle : bascule 1/0 sur un vrai GPS traversant la frontière ; déclencheur de scénario.
