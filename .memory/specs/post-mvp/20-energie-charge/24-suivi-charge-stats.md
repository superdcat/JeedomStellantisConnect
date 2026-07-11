# 24 — Suivi & statistiques de charge

**Domaine :** Énergie / charge · **Dépend de :** UC21 (détail charge) · **Statut :** implémentée

## Objectif / valeur
Capitaliser l'historique des sessions de charge (énergie ajoutée, durée, coût estimé) pour des tableaux
de bord Jeedom — au-delà de l'état instantané.

## Périmètre
- **Inclus** : détection des sessions (transition `charging_status` **`InProgress` → statut terminal**
  `Finished`/`Stopped`/`Disconnected`/`Failure` — l'enum réel de l'API ne connaît pas de valeur `Started`,
  cf. `24-tech.md`), agrégation (kWh estimés via Δ SOC × capacité batterie, durée), restitution (3
  commandes info dédiées `charge_session_energy`/`charge_session_duration`/`charge_session_cost`,
  historisées Jeedom).
- **Exclu** : facturation réelle, données opérateur de charge externes.

## Détails techniques
- S'appuyer sur l'**historisation Jeedom** des commandes info dédiées (voir spec technique `24-tech.md` —
  `charging_status` n'est pas historisé dans le code actuel, d'où 3 commandes `charge_session_*` dédiées)
  plutôt que de stocker un journal maison ; calculer les sessions à la volée via un petit état en cache.
- kWh ajoutés ≈ `Δ SOC% × capacité_batterie_kWh` (capacité saisie en config véhicule, par modèle).
- Coût estimé = kWh × tarif (config) — **informatif/estimatif**, documenté comme tel.

## Critères d'acceptation
- [x] Une session de charge produit un récapitulatif (énergie estimée, durée).
- [x] Les estimations sont marquées comme telles (pas de prétention à la précision wattmètre).

## À confirmer
- ~~Disponibilité d'une capacité batterie fiable via l'API (sinon saisie manuelle en config véhicule).~~
  **Résolu** (cf. `24-tech.md`) : l'API expose `energies[].extension.electric.battery.load.capacity` en
  best-effort, mais la **config véhicule `battery_capacity` (saisie manuelle) reste la source
  autoritaire** — millésime/forfait-dépendant côté API.
