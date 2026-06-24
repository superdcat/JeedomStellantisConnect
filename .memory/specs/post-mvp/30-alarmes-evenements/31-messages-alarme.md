# UC — Remontée des alarmes (mouvement / humain) dans Jeedom

**Domaine :** Alarmes & événements · **Dépend de :** MVP/06, MVP/10 · **Statut endpoints :** à confirmer

## Objectif / valeur
Faire remonter les **événements d'alarme** des caméras (détection de mouvement, détection
humaine, etc.) sous forme de commandes info Jeedom, pour déclencher des scénarios (notif,
lumières, sirène…).

## Ce que permet l'API
- Récupération des **messages d'alarme** par appareil (liste paginée datée). Pas de push : il
  faut **interroger** périodiquement (cohérent avec le cron MVP/10).
- Types d'alarme variés (mouvement, humain, son anormal selon modèle).

## Esquisse Jeedom
- Commande info `last_alarm` (timestamp/type) + éventuellement compteur du jour.
- Dans le cron : récupérer les messages depuis le dernier horodatage connu (curseur stocké en
  config eqLogic) ; mettre à jour la cmd info → l'utilisateur branche ses scénarios dessus.
- Anti-rejeu : ne pas re-signaler un message déjà traité (mémoriser le dernier id/horodatage).

## Critères d'acceptation
- [ ] Une détection réelle apparaît dans Jeedom au prochain cron, avec son type et son heure.
- [ ] Pas de double comptage entre deux passages de cron.

## À confirmer
- Endpoint exact des messages d'alarme + pagination + format des types.
- Latence de mise à disposition côté cloud (impacte la réactivité des scénarios).
