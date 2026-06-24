# UC — Redémarrer & renommer la caméra

**Domaine :** Gestion des appareils · **Dépend de :** MVP/06 · **Statut endpoints :** à confirmer

## Objectif / valeur
Redémarrer une caméra à distance (dépannage) et synchroniser/éditer son nom — confort
d'administration depuis Jeedom.

## Ce que permet l'API
- Redémarrage de l'appareil : endpoint **`restartDevice`** (`deviceId`). ⚠️ Le vrai nom est
  `restartDevice`, **pas** `rebootDevice` — **confirmé par l'implémentation officielle Imou pour Home
  Assistant** (lib `pyimouapi` 1.2.8), cf. `.memory/analyse/imou-home-assistant-comparaison.md` (§3.3).
  Gating : ability **`Reboot`** (caméras non-IoT) ou refs IoT **`2300` / `21200` / `90600`**.
- Renommage de l'appareil côté IMOU.

## Esquisse Jeedom
- Commande action `reboot` (avec confirmation UI).
- Renommage : décision — soit pousser le nom Jeedom vers IMOU, soit juste afficher ; éviter les
  boucles avec la synchro (MVP/06 ne doit pas écraser le nom utilisateur).

## Critères d'acceptation
- [ ] Le redémarrage déclenche bien un reboot (caméra brièvement offline puis online).

## À confirmer
- ~~Endpoint de reboot~~ → **confirmé `restartDevice`**. Reste : sens de synchro du nom
  (Jeedom→IMOU et/ou IMOU→Jeedom).
