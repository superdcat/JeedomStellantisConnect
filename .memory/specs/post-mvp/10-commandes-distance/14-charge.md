# 14 — Commande de charge (démarrer / arrêter)

**Domaine :** Commandes à distance · **Dépend de :** UC11, UC12 · **Concerne :** véhicules électriques/hybrides rechargeables · **Statut :** à spécifier

## Objectif / valeur
Démarrer ou arrêter la charge à distance depuis Jeedom (et depuis des scénarios : ex. charger aux heures
creuses, stopper à un seuil).

## Périmètre
- **Inclus** : commandes action `charge_start` / `charge_stop` (publish MQTT), MAJ de l'état de charge
  (UC18/MVP07), création **conditionnelle** (uniquement si motorisation rechargeable).
- **Exclu** : la **programmation** horaire/seuil (→ UC22).

## Détails techniques
- Publish MQTT (via démon) : commande de charge — payload type `{"program":{hour,minute},"type":charge_type}`
  (immédiat) selon `RemoteClient` de `psa_car_controller`. `charge_start`/`charge_stop` mappent les
  valeurs adéquates.
- Créer les commandes **seulement** si `energies[].type == Electric` (cf. data-model) ; lier à l'info
  `charging_status` pour un widget on/off propre (`generic_type ENERGY_ON_OFF`/`ENERGY_STATE`).
- ⚠️ Certains véhicules exigent la **batterie principale > ~50 %** pour accepter une commande à distance
  → remonter l'échec proprement (UC18).

## Critères d'acceptation
- [ ] Sur un VE/PHEV, « Démarrer/Arrêter la charge » publie la commande et l'état se met à jour.
- [ ] Les commandes ne sont **pas** créées sur un véhicule thermique.
- [ ] Un refus véhicule (seuil de charge, hors ligne) remonte un message clair, pas une erreur silencieuse.

## À confirmer
- Payload exact (immédiat vs programmé) et codes de retour — cf. `RemoteClient` / issue payloads
  (`stellantis-implementations-reference.md`).
