# 34 — Geofencing & alertes de zone

**Domaine :** Localisation / trajets · **Dépend de :** UC31 (position) · **Statut :** à spécifier

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
- [ ] Une zone « domicile » configurée fait passer `at_home` à 1/0 selon la position.
- [ ] L'info est historisée et utilisable comme déclencheur de scénario.

## À confirmer
- Tolérance/hystérésis pour éviter le clignotement aux bords de zone (qualité GPS variable).
