# UC — Détection humaine & IA (formes, types, zones)

**Domaine :** Alarmes & événements · **Dépend de :** UC12, `13-commandes-iot-et-proprietes` · **Statut :** propriétés/events confirmés (Cruiser 2C)
**Connexe :** `32-zones-sensibilite.md` (sensibilité/zones), MVP/09 (surveillance mouvement).

## Objectif / valeur
Activer/désactiver les détections **intelligentes** (forme humaine, IA, intrusion de zone par type) au-delà
de la simple détection de mouvement (déjà livrée en MVP/09), pour des alertes plus pertinentes.

## Ce que permet l'API (propriétés IoT `rw` + events, cf. `.memory/analyse/imou-iot-things-model.md`)
- `HeaderDetect` / `aiHuman` (bool) — détection de **forme humaine** / IA humaine.
- `mobileDetect` (bool) — détection de mouvement (≈ `motionDetect` du socle).
- `DetectionSensitivity` (enum 1-5) — sensibilité de détection (cf. `32-zones-sensibilite`).
- Intrusion de zone : `crMotionDetect` / `crHuman` / `crCar` (bool) + `crSens` (enum 1-5) + zones `crossRegion`.
- **Events** (push) : `e_videoMotion`, `e_smartMixDetect` (type : personne/animal/véhicule/colis…),
  `e_aiPerArea`/`e_areaDetect` (intrusion de zone) — base d'une future remontée temps réel.

## Esquisse Jeedom
- Switches IoT (catalogue) : `detection_humaine` (HeaderDetect/aiHuman), `intrusion_humain`/`intrusion_vehicule` (crHuman/crCar).
- Select/slider `detection_sensibilite` (1-5) — mutualisé avec `32-zones-sensibilite`.
- Les **zones** (`crossRegion`, services rename/delete) : complexes → option/à arbitrer (cf. 32-zones-sensibilite).
- Gating par `ability` (`HeaderDetect`, AI…) + présence dans `getProductModel`.

## Critères d'acceptation
- [ ] Activer la détection humaine est effectif (vérifiable dans l'app IMOU).
- [ ] Régler la sensibilité depuis Jeedom fonctionne.
- [ ] Commandes absentes sur les modèles sans détection IA.

## À confirmer
- Recouper les identifiants (`HeaderDetect` vs `aiHuman` vs `smartHuman`) selon le modèle via `getProductModel`.
- Remontée des **events** : nécessite le callback push (`setMessageCallback`) → UC dédiée (alarmes temps réel).
