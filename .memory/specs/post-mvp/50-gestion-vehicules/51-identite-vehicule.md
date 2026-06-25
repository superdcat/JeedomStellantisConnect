# 51 — Identité du véhicule (VIN, marque, modèle, énergie)

**Domaine :** Gestion véhicules · **Dépend de :** MVP/05, MVP/06 · **Statut :** à spécifier

## Objectif / valeur
Afficher proprement l'identité de chaque véhicule (VIN, marque, modèle commercial, motorisation) dans la
config et, au besoin, en commandes info — utile pour distinguer plusieurs véhicules et pour le support.

## Périmètre
- **Inclus** : exposition VIN/marque/modèle/énergie (config avancée + éventuelles commandes info string),
  affichage soigné sur la carte équipement.
- **Exclu** : image du modèle (UC52).

## Détails techniques
- Données déjà collectées en découverte (MVP/05) : `vin`, `brand`, `label`/`model`, motorisation.
- Optionnel : commandes info `vin`, `model` (string) pour affichage dashboard ; sinon simple affichage
  dans la config de l'équipement.

## Critères d'acceptation
- [ ] L'identité (VIN, marque, modèle, énergie) est visible dans la config de l'équipement.
- [ ] Le nom par défaut de l'équipement est lisible (marque + modèle).

## À confirmer
- Présence d'un libellé commercial fiable dans `/vehicles` (sinon dériver de la marque).
