# UC — Catalogue étendu : propriétés IoT & types de commande non-binaires

**Domaine :** Pilotage avancé · **Dépend de :** UC12 (switches dynamiques) · **Statut endpoints :** à confirmer
**Prérequis transverse** des UC : vision nocturne, réglages image, détection humaine/IA, projecteur minuterie.

## Objectif / valeur
Généraliser le **catalogue déclaratif** (UC12) au-delà des switches booléens `setDeviceCameraStatus`,
pour exploiter le **modèle IoT « Things »** (cf. `.memory/analyse/imou-iot-things-model.md`) :
1. **Propriétés IoT réglables** `enum`/`int` (`rw`) via `setIotDeviceProperties` (ex. NightMode,
   sensibilités) → commandes Jeedom **select**/**slider**.
2. **Propriétés lecture seule** (`r`) → commandes **info** (lues via `getIotDeviceProperties` au cron).
3. **Services IoT** (`iotDeviceControl`) déjà amorcés (sirène) → généraliser (PTZ, projecteur minuterie).

## Ce que permet l'API
- `setIotDeviceProperties(productId, deviceId, properties)` — écrire des propriétés IoT (noms/format à confirmer).
- `getIotDeviceProperties(...)` — lire des propriétés IoT (pour les états).
- `iotDeviceControl(productId, deviceId, ref, content)` — invoquer un service (déjà implémenté).
- `getProductModel(productId)` — déjà mis en cache (`imou::getProductModelCached`).

## Esquisse Jeedom
- **Étendre l'entrée de catalogue** avec un champ `kind` : `switch` (actuel, setDeviceCameraStatus),
  `iotProperty` (enum/int via setIotDeviceProperties), `iotService` (déjà géré), `infoOnly` (lecture `r`).
- **Types de commande** : ajouter `select` (enum → liste `value/desc` du model) et `slider`
  (int → `range/step`). `creerCommande` doit accepter ces subTypes + leurs options (listValue/min/max).
- **Gating** : par `ability` et/ou présence de la propriété/service dans `getProductModel` (probe
  hors-ligne sur le model caché) + `requiresProductId` pour le IoT.
- **Refresh** : lecture des propriétés `r`/`rw` exposées via `getIotDeviceProperties` (regrouper les appels).

## Critères d'acceptation
- [ ] Une propriété `enum` (ex. NightMode) expose une commande **select** fonctionnelle.
- [ ] Une propriété `int` bornée expose un **slider** respectant `range/step`.
- [ ] Ajouter une propriété/service IoT au catalogue = une entrée (pas de code spécifique).
- [ ] Aucune commande exposée si la propriété/service est absent du modèle de l'appareil.

## À confirmer
- Contrat exact de `setIotDeviceProperties`/`getIotDeviceProperties` (format `properties`, ref vs identifier).
- Faisabilité du **batch** de lecture des propriétés (coût quota du refresh).
- Cartographie subType Jeedom ↔ type IoT (`enum`→select, `int`→slider, `bool`→binary).
