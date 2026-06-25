# 73 — Protection de la batterie 12 V (cadence adaptative)

**Domaine :** Supervision / robustesse · **Dépend de :** UC13 (wakeup), UC72 · **Statut :** à spécifier

## Objectif / valeur
Éviter de **vider la batterie 12 V** du véhicule (risque réel confirmé : keyless HS) en régulant
intelligemment les **wakeups** : fréquents seulement quand c'est utile (charge en cours), rares en veille.

## Périmètre
- **Inclus** : option d'auto-wakeup **opt-in** avec cadence **adaptative** selon l'état (en charge / en
  veille / en roulage), garde-fous, désactivable.
- **Exclu** : le wakeup manuel (UC13) et le rate-limiting générique (UC72).

## Détails techniques
- Cadence recommandée (communauté, cf. analyse § 1.4) : **~5 min en charge active**, **~60 min en veille**,
  jamais en dessous des limites UC72. Le polling **REST** (sans wakeup) reste, lui, fréquent et inoffensif.
- Logique : déterminer l'état via la dernière télémétrie (`charging_status`, `moving`) → choisir la cadence
  d'auto-wakeup. **Désactivé par défaut** (le wakeup auto est un choix explicite de l'utilisateur averti).
- Documenter clairement le **risque batterie 12 V** dans l'UI à l'activation.

## Critères d'acceptation
- [ ] L'auto-wakeup est **désactivé par défaut** et clairement averti (risque batterie).
- [ ] Activé, il respecte une cadence adaptative (charge vs veille) et les limites UC72.
- [ ] Le polling REST normal continue indépendamment (lecture sans réveiller la voiture).

## À confirmer
- Cadences optimales par modèle (gestion veille variable) — laisser configurable avec défauts sûrs.
