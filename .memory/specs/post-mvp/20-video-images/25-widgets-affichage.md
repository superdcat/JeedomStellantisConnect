# UC — Widgets d'affichage (commandes par défaut + live stream)

**Domaine :** Vidéo / images · **Dépend de :** MVP/07, UC15 (PTZ), UC22 (live stream) · **Complémentaire de :** UC16 · **Statut :** ✅ livré (cf. `25-widgets-affichage-tech.md`)

## Objectif / valeur
Fournir, **avec le plugin**, des widgets d'affichage soignés (dashboard + mobile) pour les commandes
utiles au quotidien, plutôt que de laisser le rendu générique de Jeedom. Inclut un **widget de flux
vidéo live** intégrant l'image temps réel de la caméra.

## Périmètre
- **Inclus** :
  - Widgets pour les **commandes affichées par défaut** (cf. UC16) : caméra on/off, surveillance
    on/off, sirène (déclenchement), **PTZ** (pavé directionnel + zoom), projecteur on/off.
  - Un **widget « live stream »** affichant le flux vidéo de la caméra.
- **Exclu** : la récupération/format du flux lui-même (fournie par UC22) ; les réglages image (UC24).

## Esquisse Jeedom
- Widgets plugin sous `core/template/dashboard/` (et `core/template/mobile/`), déclarés/sélectionnables
  par commande (mécanisme de templates de widget Jeedom). *Format exact et déclaration à confirmer en tech.*
- **PTZ** : un widget regroupant les 6 actions `ptz_*` en un pavé (↑ ↓ ← → + zoom +/−) au lieu de 6
  boutons séparés → ergonomie « télécommande ».
- **Live stream** : widget consommant l'URL de flux fournie par UC22. Choix du transport jouable en
  navigateur (HLS, FLV, ou rafraîchissement de snapshot façon UC21) à arbitrer — l'API IMOU n'expose
  pas forcément un flux directement lisible par un `<video>` standard. *À confirmer (cf. UC22).*

## Critères d'acceptation
- [ ] Les commandes par défaut disposent d'un widget plugin dédié (rendu plus lisible que le générique).
- [ ] Le PTZ s'affiche comme un pavé directionnel unique et fonctionnel.
- [ ] Un widget live affiche l'image de la caméra et se rafraîchit.
- [ ] Les widgets sont disponibles en dashboard ET en vue mobile.

## À confirmer
- Mécanisme de widget retenu (template plugin vs widget core paramétré).
- Transport du live jouable côté navigateur (dépend de ce que renvoie UC22 : RTSP/RTMP/HLS/snapshot).
- Coût quota d'un live (rafraîchissement snapshot) vs articulation avec UC74 (quota).
