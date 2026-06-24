# 11 — Statut online / offline & santé équipement

**Phase :** Post-MVP · **Dépend de :** 10 · **Fichiers :** `core/class/imou.class.php`

## Objectif
Exposer la disponibilité réelle de chaque caméra et alimenter la page « Santé » de Jeedom.

## Périmètre
- **Inclus** : commande info `online`, mise à jour via cron, `refresh`/santé, gestion des
  caméras disparues du compte.
- **Exclu** : alertes/scénarios (laissés à l'utilisateur).

## Détails techniques
- Endpoint **`deviceOnline`** (`deviceId`) → cmd info `online` (binary). Confirmé par l'officiel
  (états : online / offline / sleep / upgrading). Le champ `online` est par ailleurs **déjà calculé**
  par `normalizeDevice` à la découverte mais n'est aujourd'hui **ni exposé en cmd ni rafraîchi**.
- Mettre à jour `online` dans `cron5` (tâche 10).
- **Optimisation quota (calquée sur l'officiel)** : dans chaque cycle de poll, appeler `deviceOnline`
  **en premier** ; si l'équipement est **offline, court-circuiter** les autres appels cloud de cet
  équipement (états, propriétés IoT, batterie…). Réduit fortement le quota quand des caméras sont
  hors ligne. Cf. `.memory/analyse/imou-home-assistant-comparaison.md` (§3.2, §5.2) et UC73/UC74.
- Implémenter `imou::health()` pour la page Santé (online, dernière synchro, token valide).
- Caméra disparue du compte lors d'une re-sync → marquer l'eqLogic non joignable (badge) plutôt
  que de le supprimer.

## Critères d'acceptation
- [ ] L'état online/offline est correct et visible sur le widget + page Santé.
- [ ] Débrancher une caméra la fait passer offline au prochain cron.
- [ ] Une caméra offline n'engendre plus d'appels cloud superflus (skip-offline) sur le reste du cycle.

## Notes / risques
- Endpoint d'online **confirmé `deviceOnline`** (cf. analyse comparative) ; le champ `online` de la
  liste reste un repli/secours.
