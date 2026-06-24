# UC — Sonnette vidéo (événements d'appel)

**Domaine :** Contrôle d'accès · **Dépend de :** MVP/06, 30-alarmes-evenements/31-messages-alarme · **Statut endpoints :** à confirmer

## Objectif / valeur
Pour les **sonnettes vidéo IMOU** : remonter l'événement « appui sur la sonnette » dans Jeedom
pour déclencher des scénarios (notification, allumage, affichage du flux).

## Ce que permet l'API
- Les appuis sonnette remontent comme un type d'**alarme/message** (même mécanisme de pull que
  `30-alarmes-evenements/31-messages-alarme.md`).

## Esquisse Jeedom
- Commande info `doorbell_press` (timestamp) alimentée par la boucle de récupération des messages.
- L'utilisateur branche un scénario (notif + snapshot/live de la sonnette).

## Critères d'acceptation
- [ ] Un appui sonnette génère un événement exploitable dans Jeedom (≤ délai de cron).

## À confirmer
- Type exact de l'événement sonnette ; matériel disponible pour tester (dépend du parc).
