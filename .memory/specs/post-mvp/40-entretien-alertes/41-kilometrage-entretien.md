# 41 — Kilométrage & entretien

**Domaine :** Entretien / alertes · **Dépend de :** MVP/07 · **Statut :** à spécifier

## Objectif / valeur
Suivre le kilométrage et les échéances d'entretien (prochaine révision en km / délai) pour anticiper la
maintenance via Jeedom (rappels, scénarios).

## Périmètre
- **Inclus** : info `mileage` (déjà socle MVP, historisée), et si l'API les expose : `service_distance`
  (km avant révision) / `service_days` (jours avant révision), info dérivée « révision proche ».
- **Exclu** : carnet d'entretien complet.

## Détails techniques
- `odometer.mileage` (data-model § 2.3) historisé → graphe d'usage.
- Échéances de maintenance : endpoint dédié **`GET /user/vehicles/{id}/maintenance`** (confirmé) →
  prochaine révision / distance avant entretien ; disponibilité variable selon modèle/forfait → créer
  **conditionnellement**.
- Info dérivée `service_due` (binary) si `service_distance < seuil` ou `service_days < seuil` (config).

## Critères d'acceptation
- [ ] Le kilométrage est historisé et lisible.
- [ ] Si l'API fournit l'échéance de révision, elle est remontée + une alerte « révision proche » possible.

## À confirmer
- Présence et nom des champs d'échéance d'entretien dans `/status` (souvent absents côté consommateur).
