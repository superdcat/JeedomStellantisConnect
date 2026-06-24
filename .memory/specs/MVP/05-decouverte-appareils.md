# 05 — Découverte des appareils

**Phase :** MVP · **Dépend de :** 03 · **Fichiers :** `core/class/imou.class.php`

## Objectif
Récupérer la liste des caméras du compte IMOU et leurs métadonnées (identifiants, canaux,
capacités) pour pouvoir créer les équipements.

## Périmètre
- **Inclus** : appel paginé de la liste des appareils, normalisation en un tableau PHP exploitable.
- **Exclu** : création des eqLogic (tâche 06), création des commandes (tâche 07).

## Détails techniques
- Méthode `imou::discoverDevices(): array` retournant une liste de :
  `['deviceId','name','channelId','model','ability','online']`.
- Endpoint : `listDeviceDetailsByPage` (ou `deviceBaseList`) — gérer la **pagination**
  (`bindId`/`limit`/`token` selon la doc) et concaténer les pages.
- Le champ `ability` (chaîne de capacités, ex. `"AlarmMD,WLAN,PT,…"`) est conservé : il servira
  en tâche 07/12 à savoir quelles commandes créer.
- Multi-canaux : un appareil peut exposer plusieurs `channelId` → un équipement par couple
  `deviceId/channelId` (décision : voir tâche 06).

## Critères d'acceptation
- [ ] `imou::discoverDevices()` renvoie toutes les caméras du compte (pagination incluse).
- [ ] Chaque entrée contient au minimum deviceId, name, channelId, ability, online.
- [ ] Gère proprement un compte sans appareil (tableau vide, pas d'erreur).

## Notes / risques
- Limite **5 appareils** par compte développeur (palier gratuit) : documenter le message si dépassé.
- Confirmer le nom exact de l'endpoint et les champs (`ability`, `channels`) dans la doc.
