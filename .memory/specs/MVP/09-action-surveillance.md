# 09 — Commande action : activer / désactiver la surveillance

**Phase :** MVP · **Dépend de :** 07 · **Fichiers :** `core/class/imou.class.php` (`imouCmd::execute`)

## Objectif
Rendre fonctionnelles les commandes `surveillance_on` / `surveillance_off` via
`setDeviceCameraStatus` sur l'`enableType=motionDetect`.

## Périmètre
- **Inclus** : exécution, appel API, update immédiat de la cmd info `surveillance_state`.
- **Exclu** : détection humaine et autres switches (tâche 12).

## Détails techniques
- `surveillance_on`  → `setDeviceCameraStatus(deviceId, channelId, 'motionDetect', enable=true)`.
- `surveillance_off` → `setDeviceCameraStatus(deviceId, channelId, 'motionDetect', enable=false)`.
- Même schéma que la tâche 08 : routage par `logicalId`, update optimiste de `surveillance_state`,
  log + remontée d'erreur.
- `motionDetect` n'est **pas** inversé (true = surveillance active).

## Critères d'acceptation
- [ ] Activer/désactiver la surveillance change bien la détection de mouvement (vérifiable dans l'app IMOU).
- [ ] `surveillance_state` reflète immédiatement l'état demandé.
- [ ] Erreur API non silencieuse.

## Notes / risques
- Certaines caméras distinguent `motionDetect` (mouvement) et `headerDetect` (humain) → la 12 ajoutera ce dernier.
- **Repli caméras legacy (non-IoT)** : l'implémentation officielle Imou pour Home Assistant écrit la
  détection de mouvement des caméras anciennes via **`modifyDeviceAlarmStatus`** (`deviceId`, `channelId`,
  `enable`), **pas** `setDeviceCameraStatus`. Si `setDeviceCameraStatus(motionDetect)` échoue/est refusé
  sur un modèle ancien, prévoir ce repli. Cf. `.memory/analyse/imou-home-assistant-comparaison.md` (§3.6, §5.7).
