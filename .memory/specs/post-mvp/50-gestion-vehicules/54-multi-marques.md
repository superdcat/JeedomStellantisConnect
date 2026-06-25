# 54 — Multi-marques (Peugeot / Citroën / DS / Opel / Vauxhall)

**Domaine :** Gestion véhicules · **Dépend de :** MVP/01 (config marque), MVP/03 (OAuth par marque) · **Statut :** à spécifier

## Objectif / valeur
Permettre à un foyer d'avoir des véhicules de **marques différentes** dans la même instance Jeedom, chaque
marque ayant ses propres credentials/realm/token.

## Périmètre
- **Inclus** : config **par marque** (credentials + tokens distincts), routage des appels par la marque du
  véhicule, setup OAuth indépendant par marque.
- **Exclu** : —

## Détails techniques
- Généraliser la config MVP/01 (aujourd'hui mono-marque) en **table par marque** :
  `{brand → {client_id, client_secret, redirect_uri, token, refresh_token}}` (cf. `getApiConfig($brand)`).
- Chaque véhicule porte sa `brand` (config eqLogic) → `stellantisApi` choisit TLD/realm/credentials/token
  selon la marque du véhicule traité.
- Le setup OAuth (UC MVP/03) se fait **une fois par marque** utilisée.

## Critères d'acceptation
- [ ] Deux véhicules de marques différentes coexistent et se rafraîchissent correctement.
- [ ] Le token/realm/credentials utilisés correspondent toujours à la marque du véhicule appelé.

## À confirmer
- Ergonomie de la page config pour gérer N marques sans alourdir l'UI (onglets/sections par marque).
