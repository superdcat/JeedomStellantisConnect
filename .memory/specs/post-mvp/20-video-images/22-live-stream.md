# 15 — Flux live (streaming)

**Phase :** Post-MVP · **Dépend de :** 06 · **Fichiers :** `core/class/imou.class.php`

## Objectif
Fournir une URL de flux en direct (RTMP/HLS) pour visualiser la caméra dans Jeedom ou un widget.

## Périmètre
- **Inclus** : binding du flux, exposition de l'URL, gestion du cycle de vie (bind/unbind).
- **Exclu** : transcodage, enregistrement.

## Détails techniques
- `bindDeviceLive(deviceId, channelId, ...)` → URL(s) de flux (RTMP/HLS selon l'API) ;
  `unbindLive` pour libérer.
- Commande info `live_url` (string) ou intégration dans un widget vidéo (HLS de préférence pour le navigateur).
- Gérer la ré-obtention quand l'URL expire ; ne binder qu'à la demande pour économiser les quotas.

## Critères d'acceptation
- [ ] Une URL de flux valide est obtenue et lisible (lecteur HLS).
- [ ] Le flux est libéré quand il n'est plus utilisé.

## Notes / risques
- Compatibilité navigateur (HLS vs RTMP) ; coûts/quotas live côté IMOU.
- Tâche la plus incertaine — peut être reportée sans impacter le pilotage.
