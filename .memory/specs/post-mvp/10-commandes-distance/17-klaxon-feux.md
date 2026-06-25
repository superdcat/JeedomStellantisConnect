# 17 — Klaxon & feux (localiser le véhicule)

**Domaine :** Commandes à distance · **Dépend de :** UC11, UC12 · **Statut :** à spécifier

## Objectif / valeur
Déclencher le klaxon et/ou les feux à distance pour **retrouver** le véhicule (parking) ou signaler.

## Périmètre
- **Inclus** : commandes action `horn` (N coups) et `lights` (durée), publish MQTT.
- **Exclu** : immobilisation / signalement vol (commandes sensibles, hors périmètre par défaut — à
  évaluer séparément si besoin).

## Détails techniques
- Publish MQTT (démon) : klaxon `{"nb_horn":count,"action":"activate"}` ; feux
  `{"action":"activate","duration":seconds}` (cf. `RemoteClient`, issue payloads #1199).
- Commandes action « sans état » (pas d'info liée) ; widget bouton simple.
- Réglages possibles : nombre de coups de klaxon, durée d'allumage (config commande).

## Critères d'acceptation
- [ ] « Klaxon » et « Feux » publient la commande et déclenchent l'action sur le véhicule.
- [ ] Les paramètres (nb coups / durée) sont configurables ou ont un défaut raisonnable.

## À confirmer
- Payloads exacts (issue #1199 signale des évolutions du contrat klaxon/feux).
