# 24 — Suivi & statistiques de charge

**Domaine :** Énergie / charge · **Dépend de :** UC21 (détail charge) · **Statut :** à spécifier

## Objectif / valeur
Capitaliser l'historique des sessions de charge (énergie ajoutée, durée, coût estimé) pour des tableaux
de bord Jeedom — au-delà de l'état instantané.

## Périmètre
- **Inclus** : détection des sessions (transition `charging_status` Started→Finished), agrégation
  (kWh estimés via Δ SOC × capacité batterie, durée), restitution (commandes info + historisation Jeedom).
- **Exclu** : facturation réelle, données opérateur de charge externes.

## Détails techniques
- S'appuyer sur l'**historisation Jeedom** des commandes info (`battery_soc`, `charging_status`) plutôt
  que de stocker un journal maison ; calculer les sessions à la volée ou via un petit état en cache.
- kWh ajoutés ≈ `Δ SOC% × capacité_batterie_kWh` (capacité saisie en config véhicule, par modèle).
- Coût estimé = kWh × tarif (config) — **informatif/estimatif**, documenté comme tel.

## Critères d'acceptation
- [ ] Une session de charge produit un récapitulatif (énergie estimée, durée).
- [ ] Les estimations sont marquées comme telles (pas de prétention à la précision wattmètre).

## À confirmer
- Disponibilité d'une capacité batterie fiable via l'API (sinon saisie manuelle en config véhicule).
