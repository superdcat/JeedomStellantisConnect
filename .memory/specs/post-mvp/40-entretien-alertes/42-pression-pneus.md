# 42 — Pression des pneus (alertes TPMS)

**Domaine :** Entretien / alertes · **Dépend de :** UC43 (alertes) · **Statut :** à spécifier

## Objectif / valeur
Surveiller le sous-gonflage des pneus et alerter dans Jeedom.

> ⚠️ **Correction de cadrage (recherche 2026-06-25)** : la **pression numérique des pneus est ABSENTE
> du `/status`** de l'API consommateur (confirmé sur tous les dumps JSON réels et modèles). Seules des
> **alertes booléennes** existent via `GET /user/vehicles/{id}/alerts` (AlertMsgEnum). Cette UC ne peut
> donc PAS exposer une pression en bar/PSI — uniquement des **états d'alerte**.

## Périmètre
- **Inclus** : commande info binaire `tyre_alert` (au moins un pneu en défaut), dérivée des types
  pneus de l'AlertMsgEnum ; éventuellement une info par roue si l'alerte précise la position.
- **Exclu** : pression numérique (non disponible) ; le socle de lecture des alertes (→ UC43).

## Détails techniques
- Types pneus dans AlertMsgEnum (`/alerts`) : `tyreUnderInflation`, `underInflationTyreFault`,
  `wheelPressureFault`, `adjustTyrePressure`, `frontLeftTyreNotMonitored`, `frontrightTyreNotMonitored`,
  `rearLeftTyreNotMonitored`, `rearRightTyreNotMonitored` (cf. `[[stellantis-data-model]]` § 3).
- `tyre_alert` (binary, generic_type éventuel) = OR des alertes pneus actives ; si la position est
  donnée, créer `tyre_<fl|fr|rl|rr>` binaires.

## Critères d'acceptation
- [ ] Une alerte de sous-gonflage remontée par `/alerts` met `tyre_alert` à 1 (exploitable en scénario).
- [ ] Aucune commande de « pression en bar » n'est créée (donnée inexistante côté API).
- [ ] Absence d'alerte pneus gérée proprement (info à 0, pas d'erreur).

## À confirmer
- Granularité réelle (par roue vs global) selon modèle/forfait connecté (Connect Box/Plus/e-Remote).
