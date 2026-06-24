# 10 — Rafraîchissement périodique (cron5)

**Phase :** MVP · **Dépend de :** 07 · **Fichiers :** `core/class/imou.class.php` (`imou::cron5`)

## Objectif
Maintenir à jour l'état réel des commandes info (caméra, surveillance) en interrogeant l'API,
puisque celle-ci ne fournit **aucun push**.

## Périmètre
- **Inclus** : `cron5()` qui parcourt les équipements actifs et met à jour les cmd info ;
  respect du champ « Auto-actualisation » déjà présent dans le formulaire.
- **Exclu** : online/offline détaillé (tâche 11), rate limiting avancé (tâche 16).

## Détails techniques
- Implémenter `imou::cron5()` (décommenter le hook) :
  - pour chaque `eqLogic::byType('imou')` activé, lire l'état via `getDeviceCameraStatus`
    pour `closeCamera` et `motionDetect`, puis `checkAndUpdateCmd('camera_state'|'surveillance_state', $val)`.
  - appliquer l'inversion `closeCamera`.
- Respecter l'« Auto-actualisation » (`configuration:autorefresh`, cron par équipement) si
  renseignée ; sinon cadence par défaut de `cron5`.
- Limiter le nombre d'appels (≤ caméras × 2) pour rester sous les quotas ; mutualiser le token.
- Robustesse : un équipement en erreur ne doit pas casser la boucle (try/catch par équipement).

## Critères d'acceptation
- [ ] Un changement fait depuis l'app IMOU se reflète dans Jeedom au plus tard au prochain cron.
- [ ] Une caméra injoignable n'interrompt pas la mise à jour des autres.
- [ ] Pas d'explosion du nombre d'appels API (vérifier les logs).

## Notes / risques
- Si `getDeviceCameraStatus` exige un appel par `enableType`, regrouper proprement (2 appels/caméra).
- Au-delà de quelques caméras, surveiller les quotas (tâche 16).
