# Étude comparative — Intégration officielle Imou (Home Assistant) vs plugin Jeedom IMOU

> Analyse interne (français). Date : 2026-06-23.
> Source externe : `Imou-OpenPlatform/Imou-Home-Assistant` (domaine `imou_life` v1.2.8), reposant
> sur la lib PyPI **`pyimouapi==1.2.8`** (toute la logique API y vit : `openapi.py`, `device.py`,
> `ha_device.py`, `const.py`).
> Source interne : `core/class/imou.class.php` (~3269 lignes) + `.memory/specs/`.

## 0. TL;DR

Le plugin Jeedom est **plus mature que ne le laisse croire le CLAUDE.md** (« squelette ») et, sur
plusieurs points (sécurité, quota/licence, auto-découverte IoT générique), **dépasse** l'intégration
officielle. Mais l'implémentation HA expose **5 endpoints utiles que Jeedom n'appelle pas du tout**
et qui débloquent des fonctionnalités absentes côté Jeedom :

| Endpoint HA non utilisé par Jeedom | Débloque | Proposition |
|---|---|---|
| `getDevicePowerInfo` + `wakeUpDevice` | **Niveau de batterie** + réveil caméra dormante | **UC57 (neuf)** |
| `deviceOnline` | **État en ligne/santé** + skip de poll si offline (gain quota) | **UC71 + amélioration cron** |
| `restartDevice` | **Redémarrage** appareil | **UC52** (corriger le nom d'endpoint dans la spec) |
| `deviceStorage` | **Capteur de stockage** utilisé (SD/cloud) | **UC41** |
| `getIotDeviceDetailInfo` | **Découverte des capacités IoT** (`abilityRefs`) en 1 appel | ⚠️ **PAS un snapshot de valeurs** — cf. §4 & §5.4 (correction 2026-06-24) |

---

## 1. Ce que fait l'officiel et que Jeedom fait déjà (parité)

Architecture, signature et flux sont **alignés** (c'était la promesse de la « Solution A ») :

- **Signature** identique : `md5("time:…,nonce:…,appSecret:…")`, enveloppe `system` (ver/appId/sign/time/nonce)
  + `params` (token injecté dans `params`, pas en header) + `id` (UUIDv4). ✅
- **Token** : pris paresseusement, mis en cache, **refresh réactif** sur `TK1002`/`TK1003`/`OP1007` avec
  rejeu unique. Jeedom va **plus loin** : token **chiffré** dans le cache + refresh **proactif** (marge 300s)
  + bornes TTL anti-aberration. HA garde le token **en mémoire process, non chiffré, non persistant**. ✅+
- **Découverte** : `listDeviceDetailsByPage` paginé, 1 eqLogic par couple `deviceId_channelId`. ✅ (Aucun des
  deux n'utilise `deviceBaseList`.)
- **Switches** via `setDeviceCameraStatus` (caméra/surveillance/projecteur/sirène), piège d'inversion
  `closeCamera` connu des deux côtés. ✅
- **PTZ** via `controlMovePTZ` (operation 0-3 + durée). ✅
- **Live HLS** : `bindDeviceLive`/`getLiveStreamInfo`, gestion `LV1001`/`LV1002`. ✅
- **Snapshot** : `setDeviceSnapEnhanced` → `data.url` (repli `setDeviceSnap`), attente avant download. ✅
- **IoT « Things »** : `getIotDeviceProperties`/`setIotDeviceProperties`/`iotDeviceControl`. ✅

## 2. Là où Jeedom est en avance sur l'officiel

À conserver, ce sont de vrais différenciateurs :

1. **Quota/licence palier gratuit 5 appareils** : Jeedom le documente et le gère (mémoire
   `imou-prerequis-et-quota-appels-pilotage`). **HA l'ignore totalement** — toute erreur non listée tombe
   en `RequestFailedException` brute.
2. **Sécurité des logs** : `redactParams` + `sanitizeLog` (anti-CRLF, troncature). HA logue plus brut.
3. **Token chiffré + persistant** (vs mémoire process en clair côté HA).
4. **Auto-découverte IoT générique (UC13)** : Jeedom découvre dynamiquement les propriétés enum/int via
   `getProductModel` et génère select/slider. **HA code en dur chaque `ref`** (table de ~60 codes numériques
   dans `const.py`). Conséquence : certaines entités que HA implémente une par une (température `16000`,
   humidité `16100`, volume `15400`, mode d'armement `15200`…) sont **potentiellement déjà couvertes
   automatiquement** par l'UC13 de Jeedom, sans code dédié.
5. **Robustesse front** : limiteur de concurrence live FIFO (UC25) et **proxy same-origin** (ffmpeg) pour
   contourner la CSP — HA n'a pas ces contraintes (pas de CSP navigateur).
6. **Quota-conscience** : `getIotDeviceProperties` déjà **batché** par catégorie (un seul appel pour tous
   les `ref` d'un lot), `pollDisabledSet` (UC73), refresh sélectif par caméra.

## 3. Endpoints utilisés par l'officiel et ABSENTS du code Jeedom

Vérifié par grep sur `imou.class.php` : **aucune** occurrence de `getDevicePowerInfo`, `wakeUpDevice`,
`deviceOnline`, `getIotDeviceDetailInfo`, `deviceStorage`, `restartDevice`, `modifyDeviceAlarmStatus`,
`getNightVisionMode`, `currentDomain`.

### 3.1 Batterie + réveil (DV1030) — **lacune nette**
`getDevicePowerInfo` (`electricitys[]` : `litElec`/`alkElec`/`electric`) → niveau batterie %. Couplé à
`wakeUpDevice` (`url:"/device/wakeup"`) déclenché sur le code `DV1030` (appareil dormant) avant relecture.
**Jeedom n'a rien** : ce n'est PAS une propriété IoT (donc non couverte par l'UC13), c'est un endpoint
dédié. Critique pour les caméras sur batterie (très répandues chez IMOU).

### 3.2 État en ligne / santé — **donnée déjà calculée mais jamais exposée**
`deviceOnline` (online/offline/sleep/upgrading). HA s'en sert **en premier** dans chaque cycle de poll :
**si offline, il stoppe** (aucun autre appel cloud) → grosse économie de quota. Côté Jeedom,
`normalizeDevice` calcule déjà un booléen `online` à la découverte, mais **aucune commande info `online`
ni court-circuit cron** ne l'exploite (UC71 planifiée, pas faite).

> **Contrat réel confirmé (doc officielle, fetch 2026-06-23 — UC71 livrée)** : le champ est **`onLine`**
> (camelCase, **L majuscule**, ≠ `online`), retourné dans `result.data` **au niveau device** (`data.onLine`)
> **ET par canal** (`data.channels[]` = `{channelId, onLine}`). Valeurs = **String** : `"0"`=offline,
> `"1"`=online, `"3"`=upgrading, `"4"`=sleeping (≠ libellés textuels supposés ici). UC71 mappe en binaire
> (`online=1` ssi `onLine=="1"`), lecture canal prioritaire + repli device. Détail : mémoire
> `imou-deviceonline-contrat-skip-offline.md`.

### 3.3 Redémarrage — **action triviale manquante**
`restartDevice` (HA : bouton). UC52 planifiée côté Jeedom **mais la spec nomme l'endpoint `rebootDevice`**
— le nom réel confirmé par l'officiel est **`restartDevice`**. À corriger avant implémentation.

### 3.4 Stockage — **capteur réellement renseigné**
`deviceStorage` (`usedBytes`/`totalBytes` → %), avec codes `DV1049` (pas de média) / état anormal.
UC41 planifiée, non faite. (`deviceSdcardStatus` existe aussi mais est déclaré **non utilisé** même chez HA.)

### 3.5 Vision nocturne « legacy » — **couverture partielle**
Jeedom fait la vision nocturne via **propriété IoT** (UC23). HA a en plus une voie **non-IoT** dédiée
(`getNightVisionMode`/`setNightVisionMode`) pour les caméras classiques sans `productId`. Les caméras
anciennes non-IoT ne sont donc peut-être pas couvertes par l'UC23 actuelle.

### 3.6 Détection mouvement « legacy »
HA écrit la détection de mouvement des caméras **non-IoT** via `modifyDeviceAlarmStatus`, **pas**
`setDeviceCameraStatus`. Jeedom utilise `setDeviceCameraStatus(motionDetect)` (UC09). Pour les caméras
legacy, `modifyDeviceAlarmStatus` peut être le seul chemin fiable → repli à prévoir.

## 4. Différences d'architecture à noter

- **Découverte des capacités IoT** : HA appelle `getIotDeviceDetailInfo` pour obtenir les **`abilityRefs`**
  (capacités supportées) du device **et par canal** ; Jeedom passe par `getProductModel` (modèle produit,
  mis en cache 7j) puis lit les propriétés. HA déclare `getProductModel` mais **ne l'utilise pas** en 1.2.8.
  > ⚠️ **CORRECTION 2026-06-24 (UC76, vérifié dans `pyimouapi==1.2.8`)** : contrairement à ce qui était
  > écrit ici et en §5.4, `getIotDeviceDetailInfo` **ne renvoie PAS** « un snapshot de toutes les valeurs
  > de propriétés ». Lecture ligne par ligne de `pyimouapi/device.py` 1.2.8 :
  > - `async_get_iot_device_detail_info(deviceId, productId)` → envoie **seulement** `deviceId` + `productId` ;
  > - son **unique** consommateur `_async_update_device_ability_refs` lit **uniquement** `data.abilityRefs`
  >   (chaîne de capacités) et `data.channels[].abilityRefs` — **aucune** lecture de `properties`/valeurs ;
  > - il est appelé **à la DÉCOUVERTE** (dans `async_get_devices`, `if productId in device`), **pas** à
  >   chaque cycle de poll ;
  > - les **valeurs** courantes passent, comme chez Jeedom, par `getIotDeviceProperties` (batch keyé par `ref`).
  >
  > C'est un endpoint de **découverte de capacités**, équivalent fonctionnel du `getProductModel`+ability de
  > Jeedom — **pas** un raccourci de polling. La piste §5.4 est donc **invalide** (cf. §5.4).
- **Redirection datacenter** : la réponse `accessToken` de HA peut contenir `currentDomain` ; HA **remplace
  alors son URL de base**. Jeedom fixe le host par datacenter (europe/asia/america) et **n'écoute pas**
  `currentDomain` → risque si IMOU redirige un compte vers un autre POP.
- **Identifiant des sous-appareils/accessoires** : HA compose `deviceId_parentDeviceId_parentProductId`
  pour TOUS les appels IoT sur un accessoire (sinon erreur). À garder en tête si Jeedom adresse un jour
  sonnettes/serrures (UC61/62).
- **Évaluation d'expressions** : HA dérive %/V/A/kWh/état stockage via `simpleeval` sur des sous-champs.
  Pertinent seulement si Jeedom adresse les capteurs énergie de prises connectées (niche).

## 5. Propositions — améliorations & nouvelles UC

Classées par rapport valeur/effort. Numérotation alignée sur la roadmap existante.

### 5.1 🟢 UC57 (NEUVE, domaine 50) — Niveau de batterie & réveil
- **Endpoints** : `getDevicePowerInfo`, `wakeUpDevice`. **Code** : `DV1030` → wakeUp + 1 retry.
- **Livrable** : commande **info numérique `battery`** (%) + type/`alkElec`/`litElec`, pollée par `cron5`
  (réutilise le pattern `refreshStates`). Optionnel : seuil batterie faible.
- **Pourquoi** : caméras sur batterie très courantes ; donnée totalement absente ; non couverte par l'UC13.
- **Effort** : faible. **Valeur** : élevée.

### 5.2 🟢 UC71 (✅ LIVRÉE) + court-circuit cron — Online/santé
- **Endpoint** : `deviceOnline`. **Livrable** : commande info binaire `online` (la donnée existe déjà dans
  `normalizeDevice`). **Amélioration cron** : poller `deviceOnline` **en premier** ; si offline, **sauter**
  les autres appels cloud de l'équipement (calque `_should_skip_device_update`/skip-offline de HA).
- **Pourquoi** : visibilité d'état **+ économie de quota directe** (couple bien avec UC73).
- **Effort** : faible/moyen. **Valeur** : élevée (quota).

### 5.3 🟢 UC52 (PLANIFIÉE → à faire) — Redémarrage + correction de spec
- **Endpoint réel** : **`restartDevice`** (≠ `rebootDevice` écrit dans la spec — **corriger la spec**).
- **Livrable** : commande action `reboot` (ability `Reboot` / refs `2300,21200,90600` pour les modèles IoT).
- **Effort** : très faible. **Valeur** : moyenne.

### 5.4 ~~🟡 Amélioration polling IoT (quota) — adopter `getIotDeviceDetailInfo`~~ → ❌ INVALIDE (UC76, 2026-06-24)
> **Statut : abandonnée.** L'analyse de départ (1×`getIotDeviceDetailInfo` à la place des N
> `getIotDeviceProperties`) reposait sur une **prémisse fausse** : `getIotDeviceDetailInfo` **ne renvoie
> pas les valeurs** des propriétés, seulement les **capacités** (`abilityRefs`) — cf. §4 (correction vérifiée
> dans `pyimouapi==1.2.8`). Il n'existe donc **aucun** raccourci « toutes les valeurs en 1 appel ».
- Le seul levier confirmé serait de **fusionner** les deux lectures `getIotDeviceProperties`
  (`refreshIotProperties` enum/int + `refreshIotBoolProperties` bool) en **un seul** appel batch (l'endpoint
  accepte tous les `ref` d'un coup). Gain réel **marginal** : par défaut ces infos sont `noPoll=1` (UC75)
  → **0 appel** ; le gain (2→1) ne se matérialise que si l'utilisateur a activé le polling des **deux**
  catégories sur une même caméra. `refreshIotServiceStatuses` (services `Get…`) n'est pas fusionnable.
- **Décision (2026-06-24)** : **UC76 clôturée sans implémentation** — prémisse invalide + gain résiduel non
  justificatif face au risque (collision de `ref` cross-catégorie, perte de l'isolation des responsabilités).
  Le code actuel (déjà batché par catégorie, défensif) est conservé. Si un jour un vrai poste de quota IoT
  émerge, le rouvrir sur **données réelles** via l'instrumentation §5.8 (UC74).

### 5.5 🟡 UC41 (PLANIFIÉE → à faire) — Capteur de stockage
- **Endpoint** : `deviceStorage` (%`usedBytes/totalBytes`), codes `DV1049`/anormal. **Livrable** : info
  numérique `storage_used`. **Effort** : faible. **Valeur** : moyenne.

### 5.6 🟡 UC23 (extension) — Vision nocturne caméras non-IoT
- Ajouter le repli `getNightVisionMode`/`setNightVisionMode` (ability `NVM`) pour les caméras sans
  `productId`, options renvoyées dynamiquement par l'API (lowercased). **Effort** : faible. **Valeur** : moyenne.

### 5.7 🟡 UC09 (robustesse) — Détection mouvement legacy
- Repli `modifyDeviceAlarmStatus` quand la caméra n'est pas pilotable via `setDeviceCameraStatus(motionDetect)`
  (caméras non-IoT). **Effort** : faible. **Valeur** : moyenne (couverture matériel ancien).

### 5.8 🟡 UC74 (PLANIFIÉE → à faire) — Compteur de quota
- Instrumenter `imouApi::call()` (point unique) pour compter les appels (par jour / par méthode). Sert de
  socle décisionnel à §5.2, §5.4 et au throttling UC72. **Effort** : faible/moyen. **Valeur** : transverse.

### 5.9 🟠 UC72 (robustesse) — Redirection datacenter `currentDomain`
- Lire `currentDomain` dans la réponse `accessToken` et basculer le host si présent. **Effort** : faible.
  **Valeur** : faible/moyenne (robustesse multi-région).

### 5.10 🟠 Domaine 30 (alarmes) — repères confirmés par l'officiel
- HA expose un **select d'armement Home/Away/Disarm** (ref `15200`) : à rapprocher de **UC33 (plans
  d'armement)**. HA n'utilise PAS de push (`getDeviceAlarmParam` déclaré mais inutilisé) → **UC35 (push)**
  reste le chantier le plus lourd (endpoint HTTPS public + callback), non éclairé par l'officiel.
- **Détection humaine/IA** (UC34) : refs `17100/17900/108900/18200` confirmés → probablement déjà
  atteignables via l'auto-découverte UC13/UC24 (à vérifier sur un modèle réel).

### 5.11 ⚪ Niche (basse priorité)
Capteurs énergie de prises connectées (power/voltage/current/kWh via `iotDeviceControl` + expressions),
capteur de contact de porte (`16300`), minuterie de prise (`28800`). Probablement hors périmètre « caméras »
du plugin ; à n'envisager que si des prises/accessoires IMOU sont ciblés.

## 6. Tableau de couverture endpoints (officiel → Jeedom)

| Endpoint officiel | Jeedom | Proposition |
|---|---|---|
| `accessToken` | ✅ (chiffré, proactif) | — |
| `listDeviceDetailsByPage` | ✅ | — |
| `getDeviceCameraStatus` / `setDeviceCameraStatus` | ✅ | — |
| `controlMovePTZ` | ✅ | — |
| `getProductModel` | ✅ (utilisé ; HA ne l'utilise pas) | — |
| `getIotDeviceProperties` / `setIotDeviceProperties` / `iotDeviceControl` | ✅ | §5.4 (optimisation) |
| `bindDeviceLive` / `getLiveStreamInfo` / `unbindLive` | ✅ | — |
| `setDeviceSnapEnhanced` / `setDeviceSnap` | ✅ | — |
| `getDevicePowerInfo` | ❌ | **UC57** |
| `wakeUpDevice` | ❌ | **UC57** |
| `deviceOnline` | ❌ | **UC71** + cron |
| `restartDevice` | ❌ | **UC52** |
| `deviceStorage` | ❌ | **UC41** |
| `getIotDeviceDetailInfo` | ❌ (équivalent : `getProductModel`+ability) | ~~§5.4~~ — découverte de capacités, **pas** un snapshot de valeurs (cf. §4) |
| `getNightVisionMode` / `setNightVisionMode` | ❌ (fait en IoT) | **UC23 ext.** |
| `modifyDeviceAlarmStatus` | ❌ (fait via setDeviceCameraStatus) | **UC09 repli** |
| `currentDomain` (redirection) | ❌ | **UC72** |
| `getDeviceAlarmParam` / `deviceSdcardStatus` / `getProductModel`(côté HA) | n/a | déclarés inutilisés chez HA — ne pas copier sans raison |
