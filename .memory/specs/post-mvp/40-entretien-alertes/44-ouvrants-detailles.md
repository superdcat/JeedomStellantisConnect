# 44 — Ouvrants détaillés (portes, fenêtres, coffre, capot)

**Domaine :** Entretien / alertes · **Dépend de :** MVP/07 · **Statut :** à spécifier

## Objectif / valeur
Au-delà du verrouillage global (MVP `doors_locked`), exposer l'état **ouvert/fermé** de chaque ouvrant
(portes avant/arrière, coffre, capot, fenêtres, toit) pour la surveillance (« coffre resté ouvert »).

## Périmètre
- **Inclus** : commandes info par ouvrant (binary `OPENING`), info agrégée `opening_alert`
  (au moins un ouvrant ouvert), créées **si présentes**.
- **Exclu** : commandes d'ouverture/fermeture (non disponibles).

## Détails techniques
- Champs ouvrants (data-model § 2.4) : structure variable (tableau d'ouvrants, ou champs nommés) →
  mapping défensif. `generic_type OPENING` pour widgets propres.
- `opening_alert` (binary) = OR des ouvrants ouverts → scénario d'alerte.

## Critères d'acceptation
- [ ] Les ouvrants exposés remontent en infos `OPENING` et se rafraîchissent.
- [ ] Une info agrégée signale « un ouvrant est ouvert ».

## À confirmer
- Liste/nommage réels des ouvrants côté consommateur (partiel selon modèle).
