# 13 — PTZ (contrôle de l'orientation)

**Phase :** Post-MVP · **Dépend de :** 07 · **Fichiers :** `core/class/imou.class.php`

## Objectif
Permettre de bouger les caméras motorisées (haut/bas/gauche/droite, zoom) et gérer des presets.

## Périmètre
- **Inclus** : commandes action directionnelles via `controlMovePTZ` ; (option) presets.
- **Exclu** : trajectoires automatiques.

## Détails techniques
- Créer les commandes PTZ **uniquement** si la capacité PT/PTZ est dans `ability`.
- `controlMovePTZ(deviceId, channelId, operation, duration)` :
  operations типiques `0=haut,1=bas,2=gauche,3=droite,…,zoomIn/zoomOut` (à confirmer doc).
- Commandes action : `ptz_up/down/left/right`, `zoom_in/zoom_out` ; `duration` courte (ex. 500 ms).
- (Option) presets : appels `addDevicePreset`/`moveDevicePreset` si exposés par l'API.

## Critères d'acceptation
- [ ] Sur une caméra motorisée, les 4 directions + zoom fonctionnent.
- [ ] Les commandes PTZ n'apparaissent pas sur une caméra fixe.

## Voie IoT « Things » (caméras avec `productId`) — confirmée Cruiser 2C
Sur les appareils IoT, le PTZ passe par des **services** `iotDeviceControl` (cf.
`.memory/analyse/imou-iot-things-model.md`), résolus dynamiquement par identifier
(`imou::resolveIotService`) :
- `PtzStepMoveFour` (pas-à-pas : `Operator` Left/Right/Up/Down + `Zoom`) → idéal pour les boutons directionnels.
- `PtzMoveFour` / `PtzMoveEight` (continu : `Horizontal`/`Vertical`/`Zoom` ∈ [-1,1] + `Duration` ms).
- `std_reset_ptz` (recalage). Presets : `SetCollection`/`GetCollection`/`TurnCollection`/`RenameCollection`/`DeleteCollection`.
Réutilise `imouCmd::actionIotService` (déjà en place pour la sirène). Repli `controlMovePTZ` (HTTP) pour le non-IoT.

## Notes / risques
- Valeurs exactes d'`operation` et unité de `duration` à confirmer dans la doc IMOU (voie HTTP).
- Voie IoT : `ref` variables par modèle → toujours résoudre par identifier via `getProductModel`.
