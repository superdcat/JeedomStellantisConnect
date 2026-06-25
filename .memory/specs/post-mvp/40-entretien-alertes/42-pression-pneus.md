# 42 — Pression des pneus

**Domaine :** Entretien / alertes · **Dépend de :** MVP/07 · **Statut :** à spécifier

## Objectif / valeur
Remonter la pression des pneus (par roue, ou statut TPMS) pour surveiller/alerter en cas de sous-gonflage.

## Périmètre
- **Inclus** : commandes info pression par roue (`tyre_fl`, `tyre_fr`, `tyre_rl`, `tyre_rr`) ou un statut
  global TPMS, créées **si présentes** dans `/status`.
- **Exclu** : —

## Détails techniques
- Champs `tires`/`tyrePressure` (data-model § 2.6) ; structure et unité (bar/kPa/%) à confirmer.
- Info dérivée `tyre_alert` (binary) si une roue sous un seuil, pour scénario.

## Critères d'acceptation
- [ ] Si l'API expose la pression, chaque roue (ou un statut TPMS) remonte et se rafraîchit.
- [ ] Absence du champ gérée proprement (pas de commande vide).

## À confirmer
- Présence/structure/unité réelles (souvent partiel selon modèle) — data-model.
