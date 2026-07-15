# 54 — Multi-marques & multi-comptes (Peugeot / Citroën / DS / Opel / Vauxhall)

**Domaine :** Gestion véhicules · **Dépend de :** MVP/01 (config marque), MVP/03 (OAuth par marque) · **Cadré par :** UC53 (`53-tech.md`) · **Statut :** à spécifier

## Objectif / valeur
Permettre à un foyer d'avoir dans la même instance Jeedom des véhicules relevant de **plusieurs comptes**
(donc potentiellement de **marques différentes**), chaque compte ayant ses propres credentials / realm /
tokens.

## Périmètre
- **Inclus** : config **par compte** (credentials + tokens distincts), routage des appels par le compte du
  véhicule, setup OAuth indépendant par compte.
- **Exclu** : —

## Détails techniques
> 🎯 **Cadré par UC53** (`53-tech.md`, décision 2026-07-15) : namespacer par **identifiant de compte
> générique**, PAS par marque — la marque devient un **attribut du compte**. Ce choix couvre le
> multi-marques **et** laisse structurellement ouvert le cas « 2 comptes de la **même** marque » au même
> coût, sans dette de redesign. (Reformulation de la version antérieure de cette spec, qui namespaçait par
> `brand` et fermait de fait le multi-compte même-marque.)

- Généraliser la config MVP/01 (aujourd'hui mono-compte) en **table indexée par compte** :
  `accounts: { <accountId> → {brand, client_id, client_secret, redirect_uri, country} }`
  (cf. `getApiConfig()` à généraliser en `getApiConfig($accountId)`).
- Chaque véhicule porte son `accountId` (config eqLogic) → `stellantisApi` choisit
  credentials/realm/TLD/token selon le **compte** du véhicule traité (la `brand` du compte fournit
  TLD/realm).
- Clé de cache token → `TOKEN_CACHE_KEY::<accountId>` ; remote token OTP **par compte**.
- **Cron** : primer le token **une fois par compte distinct** de la flotte (généralise la mutualisation
  actuelle « 1×/passe » du MVP en « 1×/compte/passe »).
- Le setup OAuth (UC MVP/03) se fait **une fois par compte** utilisé.
- ⚠️ **Autoload** : ne pas introduire de point d'entrée externe appelant une classe hors
  `stellantis`/`stellantisApi` (cf. CLAUDE.md).

## Critères d'acceptation
- [ ] Deux véhicules de **comptes** différents (marques identiques ou non) coexistent et se rafraîchissent
  correctement.
- [ ] Le token/realm/credentials utilisés correspondent toujours au **compte** du véhicule appelé.
- [ ] Le multi-marques (Peugeot + Citroën) fonctionne comme cas particulier du multi-comptes.

## À confirmer
- Ergonomie de la page config pour gérer N comptes sans alourdir l'UI (onglets/sections par compte).
- **Exposer** ou non le cas « 2 comptes de la même marque » (rare) : le design l'autorise ; c'est un
  **choix produit** (cf. `53-tech.md`) — trancher selon la demande réelle, sans impact sur le socle.
