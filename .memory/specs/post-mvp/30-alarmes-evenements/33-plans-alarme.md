# UC — Plans d'armement (planification de la surveillance)

**Domaine :** Alarmes & événements · **Dépend de :** MVP/07 · **Statut endpoints :** à confirmer

## Objectif / valeur
Consulter et modifier les **plages horaires d'armement** de la surveillance (alarm plan) —
ex. surveillance active seulement la nuit / en l'absence.

## Ce que permet l'API
- Lecture/modification du **plan d'alarme** (créneaux jour/heure) — `modifyDeviceAlarmPlan`
  et équivalent de lecture (**noms exacts à confirmer**).

## Esquisse Jeedom
- Plutôt que d'exposer un éditeur d'agenda complexe : commandes action « préréglages »
  (ex. `armement_24_7`, `armement_nuit`, `armement_off`) appliquant des plans prédéfinis.
- Laisser l'édition fine des créneaux à l'app IMOU (documenté), Jeedom pilote des profils.

### Mode d'armement simple (Home / Away / Disarm)
L'implémentation officielle expose, sur les modèles IoT, un **select d'armement** via la propriété IoT
**ref `15200`** : `0` = Home (maison), `1` = Away (absent), `2` = Disarm (désarmé). Plus simple qu'un
plan horaire et utile en scénario. **Probablement déjà atteignable par l'auto-découverte UC13** (enum)
— à vérifier sur matériel réel et, le cas échéant, à exposer avec des libellés FR dédiés. Cf.
`.memory/analyse/imou-home-assistant-comparaison.md` (§5.10).

## Critères d'acceptation
- [ ] Appliquer un préréglage depuis Jeedom modifie le plan d'armement (vérifiable dans l'app).

## À confirmer
- Format exact du plan (structure des créneaux) ; faisabilité des préréglages.
