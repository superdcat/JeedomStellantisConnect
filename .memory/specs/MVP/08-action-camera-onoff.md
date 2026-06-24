# 08 — Commande action : allumer / éteindre la caméra

**Phase :** MVP · **Dépend de :** 07 · **Fichiers :** `core/class/imou.class.php` (`imouCmd::execute`)

## Objectif
Rendre fonctionnelles les commandes `camera_on` / `camera_off` via `setDeviceCameraStatus`
sur l'`enableType=closeCamera`.

## Périmètre
- **Inclus** : exécution de l'action, appel API, mise à jour immédiate de la commande info.
- **Exclu** : surveillance (tâche 09), polling (tâche 10).

## Détails techniques
- Dans `imouCmd::execute($_options)` : router sur le `logicalId` de la commande.
- `camera_on`  → `setDeviceCameraStatus(deviceId, channelId, 'closeCamera', enable=false)`.
- `camera_off` → `setDeviceCameraStatus(deviceId, channelId, 'closeCamera', enable=true)`.
- Récupérer `deviceId`/`channelId` depuis la config de l'eqLogic parent (`$this->getEqLogic()`).
- Après succès : mettre à jour la cmd info `camera_state` (`$eqLogic->checkAndUpdateCmd('camera_state', $val)`)
  pour un retour visuel immédiat (sans attendre le cron). **Penser à l'inversion closeCamera.**
- En cas d'erreur API : `log::add('imou','error',…)` + remonter l'exception.

## Critères d'acceptation
- [ ] Cliquer « Éteindre » coupe l'image de la caméra (vérifiable dans l'app IMOU) ; « Allumer » la rétablit.
- [ ] L'état `camera_state` reflète correctement (1=allumée, 0=éteinte) immédiatement après l'action.
- [ ] Une erreur API n'est pas silencieuse (log + remontée).

## Notes / risques
- Latence de propagation côté cloud IMOU possible : l'update optimiste de l'info masque ce délai,
  le cron (tâche 10) re-synchronisera la vérité.
