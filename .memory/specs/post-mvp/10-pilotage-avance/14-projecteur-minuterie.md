# UC — Projecteur à minuterie (white light countdown)

**Domaine :** Pilotage avancé · **Dépend de :** UC12, `13-commandes-iot-et-proprietes` · **Statut :** services IoT confirmés (Cruiser 2C)

## Objectif / valeur
Au-delà du projecteur on/off persistant (déjà livré via `whiteLight`), permettre un **allumage
temporisé** : le projecteur s'allume puis s'éteint seul après un compte à rebours — utile pour la
dissuasion ponctuelle sans laisser la lumière allumée.

## Ce que permet l'API (modèle IoT « Things », cf. `.memory/analyse/imou-iot-things-model.md`)
Services `iotDeviceControl` (refs résolus dynamiquement par identifier) :
- `whiteLightStart` — démarre le projecteur temporisé (input `clientLocalTime`).
- `whiteLightStop` — éteint le projecteur temporisé.
- `GetWhiteLightStatus` — renvoie l'état + le **compte à rebours** restant.
- Propriété `WhiteLightMode` (enum : fixe / clignotant) — mode du projecteur.

## Esquisse Jeedom
- Réutilise `imouCmd::actionIotService` (déjà en place pour la sirène) : entrée catalogue
  `iotService=['on'=>'whiteLightStart','off'=>'whiteLightStop']`, `requiresProductId`.
- Commande info **durée restante** (`int`) alimentée par `GetWhiteLightStatus` au cron.
- (Option) commande **select** `WhiteLightMode` (fixe/clignotant) via `setIotDeviceProperties`.
- Gating : ability projecteur (`WhiteLight`/`WLV2`) + appareil IoT.

## Critères d'acceptation
- [ ] Un appui « projecteur minuté » l'allume puis il s'éteint seul ; « arrêter » le coupe immédiatement.
- [ ] La durée restante remonte dans Jeedom.
- [ ] Absent sur caméra sans projecteur / non IoT.

## Notes / risques
- Distinguer clairement de l'on/off persistant (`whiteLight`) déjà livré — éviter la confusion UI.
- Format de `clientLocalTime` identique à la sirène (`Y-m-d H:i:s`), validé en prod.
