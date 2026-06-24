# UC — Vision nocturne (mode nuit)

**Domaine :** Vidéo & images · **Dépend de :** UC12, `13-commandes-iot-et-proprietes` · **Statut :** propriété confirmée (Cruiser 2C)

## Objectif / valeur
Choisir depuis Jeedom le **mode de vision nocturne** de la caméra (et la sensibilité de la lumière
d'appoint), pour adapter l'image à l'éclairage ou forcer la couleur la nuit.

## Ce que permet l'API (modèle IoT « Things »)
- Propriété `NightMode` (**enum**, 4 valeurs) : `0` intelligente, `1` pleine couleur, `2` infrarouge,
  `3` désactivée.
- Propriété `fillLightSensitivity` (**enum 1-5**) : sensibilité de la lumière d'appoint.
- (Connexe) `WhiteLightMode` (fixe/clignotant) — cf. `14-projecteur-minuterie`.
Pilotage via `setIotDeviceProperties` ; lecture via `getIotDeviceProperties`.

### Repli caméras **non-IoT** (ability `NVM`)
Les caméras classiques **sans `productId`** ne passent pas par les propriétés IoT. L'implémentation
officielle (lib `pyimouapi` 1.2.8) utilise alors deux endpoints dédiés — cf.
`.memory/analyse/imou-home-assistant-comparaison.md` (§3.5) :
- **`getNightVisionMode`** (`deviceId`, `channelId`) → `mode` courant + liste `modes` (options renvoyées
  **dynamiquement** par l'API, à mettre en minuscules).
- **`setNightVisionMode`** (`deviceId`, `channelId`, `mode`).
- Mapping nom→API : `intelligent→Intelligent`, `fullcolor→FullColor`, `infrared→Infrared`, `off→Off`,
  `lowlight→LowLight`, `smartlowlight→SmartLowLight`.
Gating : ability **`NVM`**. À prévoir pour **élargir l'UC23 aux caméras non-IoT** (l'implémentation
actuelle ne couvre que la voie IoT).

## Esquisse Jeedom
- Commande **select** `vision_nocturne` (4 modes) — type `iotProperty`/`select` (cf. `13-commandes-iot-et-proprietes`).
- Commande **slider/select** `lumiere_sensibilite` (1-5).
- Commande info reflétant le mode courant (refresh).
- Gating : présence des propriétés dans `getProductModel` + appareil IoT.

## Critères d'acceptation
- [ ] Changer le mode nuit depuis Jeedom est effectif (vérifiable dans l'app IMOU).
- [ ] Le mode courant remonte dans Jeedom.
- [ ] Commandes absentes si la caméra n'expose pas ces propriétés.

## À confirmer
- Mapping exact des valeurs d'enum (libellés FR) ; endpoint `setIotDeviceProperties` (format).
- Certaines caméras exposent `NightVision` sous un autre identifiant — recouper avec `getProductModel`.
