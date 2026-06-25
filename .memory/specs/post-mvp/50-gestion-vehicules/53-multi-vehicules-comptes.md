# 53 — Multi-véhicules & multi-comptes

**Domaine :** Gestion véhicules · **Dépend de :** MVP/05, MVP/06 · **Statut :** à spécifier

## Objectif / valeur
Gérer proprement plusieurs véhicules d'un même compte (déjà couvert par la sync) **et** le cas de
plusieurs comptes/marques (foyer avec une Peugeot + une Citroën).

## Périmètre
- **Inclus** : robustesse de la sync multi-véhicules (déjà MVP), réflexion sur le **multi-comptes**
  (un jeu de tokens par compte/marque).
- **Exclu** : le détail du multi-marques (UC54).

## Détails techniques
- **Multi-véhicules d'un compte** : déjà géré (1 eqLogic/VIN, MVP/06) — ici on valide la robustesse à
  l'échelle (boucle cron, mutualisation token).
- **Multi-comptes** : la config plugin actuelle = **un** jeu credentials/token (donc un compte/une marque).
  Pour plusieurs comptes, deux options : (a) **plusieurs installations**/instances impossibles en Jeedom
  (un plugin = une config) → (b) stocker un **jeu de credentials/tokens par marque** et router par
  `brand` au niveau véhicule. → cf. UC54.

## Critères d'acceptation
- [ ] La sync reste correcte et performante avec plusieurs véhicules.
- [ ] Le besoin multi-comptes est cadré (décision documentée, même si l'implémentation est en UC54).

## À confirmer
- Faut-il vraiment supporter 2 comptes de **même** marque ? (rare) — sinon multi-marques (UC54) suffit.
