# 81 — Recette fonctionnelle manuelle

**Domaine :** Livraison · **Dépend de :** (toutes) · **Statut :** vivant (à compléter au fil des UC)

## Objectif / valeur
Comme il n'y a **ni tests unitaires ni Jeedom local garanti**, fournir une **checklist de recette
manuelle** sur un Jeedom réel : la « preuve » qu'une UC marche vraiment (lint OK ≠ feature OK).

## Périmètre
- **Inclus** : scénarios de recette par UC livrée (étapes observables + résultat attendu).
- **Exclu** : automatisation (hors de portée).

## Détails techniques — checklist (extrait, à compléter)
- **Auth (MVP/01-04)** : config marque+credentials → générer l'URL → login marque → coller le `code` →
  « Tester la connexion » = OK + nb véhicules.
- **Token OAuth2 (MVP/03, ajouté 2026-07-06 — les 5 AC de `03-token.md`)** :
  1. Générer l'URL, se connecter, coller l'**URL de redirection complète** → bandeau « Connecté au
     compte » ; vérifier en base/cache que les tokens sont **chiffrés** (pas de JSON en clair).
  2. Deux actions consécutives dans la foulée → **aucun** appel réseau token visible dans les logs
     debug entre les deux (`getToken` rend le cache).
  3. Attendre l'expiration (~15 min) puis déclencher un appel → log « Token rafraîchi (expire dans
     N s) », l'appel métier aboutit (rejeu unique).
  4. Simuler un refresh_token mort (corrompre la valeur en cache) → message « ré-authentification
     requise », **pas de boucle** de refresh dans les logs, bandeau repasse « Non connecté ».
  5. `grep -i` des logs sur un extrait d'access_token/refresh_token/client_secret → **0 occurrence** ;
     coller le code seul (sans URL) → warning « state non vérifié » loggué, échange OK quand même.
  6. Sauvegarder la config **sans** changer client_id/brand → le token survit (pas de purge) ;
     changer la marque → bandeau « Non connecté » (purge effective).
- **Découverte/équipements (05-06)** : « Synchroniser » crée 1 eqLogic/VIN ; 2e sync = 0 doublon, nom
  perso conservé.
- **Télémétrie (07-08)** : après un trajet/charge, les infos (SOC, autonomie, km, position) évoluent au
  cron ; un véhicule injoignable n'interrompt pas les autres.
- **Robustesse (09-10)** : couper la config → message clair, pas de crash ; provoquer un 401 → refresh
  transparent.
- **Commandes (post-MVP 10-x)** : OTP réalisée une fois ; wakeup throttlé ; lock/charge → ack remonté ;
  refus véhicule signalé.
- **Anti-ban/batterie (72/73)** : vérifier qu'aucune rafale n'est émise ; auto-wakeup off par défaut.

## Critères d'acceptation
- [ ] Chaque UC livrée a au moins un scénario de recette observable, vérifié sur Jeedom réel.

## Notes
- Ne **jamais** prétendre qu'un comportement runtime est validé sans l'avoir constaté ici.
