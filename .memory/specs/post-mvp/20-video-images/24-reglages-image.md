# UC — Réglages image & divers (flip, WDR, OSD, LED, suivi)

**Domaine :** Vidéo & images · **Dépend de :** UC12, `13-commandes-iot-et-proprietes` · **Statut :** propriétés confirmées (Cruiser 2C)

## Objectif / valeur
Exposer les réglages courants de la caméra sous forme de commandes Jeedom, pour les piloter/scénariser
sans ouvrir l'app IMOU.

## Ce que permet l'API (propriétés IoT `rw`, cf. `.memory/analyse/imou-iot-things-model.md`)
- `IPCViewFlipState` (bool) — **retournement de l'image** (montage tête en bas).
- `wideDynamic` (bool) — **WDR** (grande plage dynamique).
- `breathingLight` (bool) — **LED indicateur** de l'appareil.
- `smartTrack` (bool) — **suivi intelligent** (caméras motorisées).
- `TargetDetectFrame` (bool) — **cadre de détection** de cible à l'écran.
- `playSound` (bool) — **son de notification** de l'appareil.
- `osd` (struct) — incrustation **OSD** (texte/affichage) — *option, plus complexe*.

## Esquisse Jeedom
- Les booléens → entrées catalogue `iotProperty` (ou switch si aussi exposé en capability) → commandes
  **binary** action + info, réutilisant le mécanisme UC12.
- `osd` (struct) : reporté/optionnel (widget non trivial) — à arbitrer.
- Gating : présence de chaque propriété dans `getProductModel` (on ne crée que ce qui existe).

## Critères d'acceptation
- [ ] Activer/désactiver flip, WDR, LED, suivi intelligent depuis Jeedom est effectif.
- [ ] Chaque commande n'apparaît que si la caméra expose la propriété correspondante.

## Notes / risques
- Beaucoup de propriétés bool sont aussi des capability switches → décider du canal (setDeviceCameraStatus
  vs setIotDeviceProperties) selon `accessMode` et disponibilité (cf. règle §3 de l'analyse IoT).
- Éviter de surcharger l'UI : regrouper, et laisser l'utilisateur masquer les commandes non voulues.
