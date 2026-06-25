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
