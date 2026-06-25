# 21 — Détail batterie & charge

**Domaine :** Énergie / charge · **Dépend de :** MVP/07 (commandes info), MVP/08 (cron) · **Statut :** à spécifier

## Objectif / valeur
Enrichir la télémétrie énergie au-delà du socle MVP (SOC + autonomie) : exposer **tous** les détails de
charge utiles aux scénarios EV (vitesse de charge, temps restant, mode, heure de fin programmée, type de
prise, batterie 12 V).

## Périmètre
- **Inclus** : commandes info supplémentaires dérivées de `energies[].charging` et `battery`.
- **Exclu** : la **commande** de charge (UC14) et sa **programmation** (UC22).

## Détails techniques
- Commandes info (créées si présentes — VE/PHEV) : `charging_rate` (km/h ou kW), `charging_remaining`
  (temps restant, depuis `remainingTime` ISO `PT…` → minutes), `charging_mode` (Slow/Quick),
  `charge_next_time` (`nextDelayedTime`), `battery_12v` (tension/état). Cf. data-model § 2.1.
- Normaliser les durées ISO 8601 (`PT1H30M`) en valeurs numériques exploitables (minutes) côté `parseStatus`.

## Critères d'acceptation
- [ ] Sur un VE/PHEV branché, les infos de charge détaillées remontent et se rafraîchissent.
- [ ] Les durées ISO sont converties en valeurs numériques utilisables (graphes/scénarios).
- [ ] Aucune de ces commandes n'est créée sur un véhicule thermique pur.

## À confirmer
- Unités exactes (`chargingRate` km/h vs kW) et présence des champs selon modèle — cf. data-model.
