# 07 — Lecture des capacités & création des commandes

**Phase :** MVP · **Dépend de :** 06 · **Fichiers :** `core/class/imou.class.php`

## Objectif
Doter chaque équipement de ses commandes Jeedom (info + action) correspondant aux capacités
IMOU pilotées dans le MVP : marche/arrêt caméra et surveillance.

## Périmètre
- **Inclus** : création idempotente des commandes lors de `postSave`/sync ; mapping
  capacité → commande info + commande action.
- **Exclu** : la logique d'exécution (tâches 08/09) et le rafraîchissement (tâche 10).

## Détails techniques
- Hook : créer les commandes dans `imou::postSave()` (ou appelé depuis la sync) via une méthode
  `createCommands()` idempotente (clé = `logicalId` de la cmd).
- Commandes MVP par équipement :
  | logicalId | name | type | subType | enableType IMOU |
  |---|---|---|---|---|
  | `camera_state` | Caméra (état) | info | binary | `closeCamera` (inversé) |
  | `camera_on` | Allumer caméra | action | other | `closeCamera=false` |
  | `camera_off` | Éteindre caméra | action | other | `closeCamera=true` |
  | `surveillance_state` | Surveillance (état) | info | binary | `motionDetect` |
  | `surveillance_on` | Activer surveillance | action | other | `motionDetect=true` |
  | `surveillance_off` | Désactiver surveillance | action | other | `motionDetect=false` |
- Les commandes action référencent la commande info correspondante (`value`) pour l'affichage
  bouton on/off ; configurer `display::generic_type` (ex. `ENERGY_STATE`/`ENERGY_ON_OFF`) pour
  des widgets propres.
- **Attention `closeCamera` est inversé** : `closeCamera=true` ⇒ caméra éteinte. La commande
  info `camera_state` doit présenter « allumée=1 / éteinte=0 » → prévoir l'inversion au mapping.

## Critères d'acceptation
- [ ] Après synchro, chaque équipement possède les 6 commandes ci-dessus.
- [ ] Une re-synchro ne duplique pas les commandes.
- [ ] Les types/subTypes sont corrects (info binary historisable, action on/off).

## Notes / risques
- Ne créer que les commandes dont la capacité existe dans `ability` (si l'info est fiable),
  sinon créer le socle MVP et affiner en tâche 12.
