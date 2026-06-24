# UC — Projecteur & sirène (dissuasion)

**Domaine :** Pilotage avancé · **Dépend de :** MVP/07 · **Statut endpoints :** à confirmer

## Objectif / valeur
Déclencher à distance le **projecteur** (lumière blanche) et la **sirène** des caméras qui en
sont équipées, depuis Jeedom — usage typique : dissuasion sur détection, scénario d'alarme.

## Ce que permet l'API
- Commutateurs de capacité via `setDeviceCameraStatus` (`enableType` = `whiteLight`,
  `linkagewhitelight`, alarme sonore/sirène — **noms exacts à confirmer**).
- Selon modèle : déclenchement ponctuel (impulsion) ou état persistant.

## Esquisse Jeedom
- Commandes action `projecteur_on/off`, `sirene_on/off` (+ éventuellement `sirene_pulse`).
- Créées uniquement si la capacité figure dans `ability` (réutilise le mécanisme MVP/07 et
  `11-switches-dynamiques.md`).
- Commande info d'état si l'API expose la lecture.

## Critères d'acceptation
- [ ] Sur une caméra équipée, projecteur et sirène se déclenchent/s'arrêtent depuis Jeedom.
- [ ] Absent sur les modèles non équipés.

## À confirmer
- `enableType` exacts (lumière, sirène) ; mode impulsion vs persistant ; durée paramétrable.
