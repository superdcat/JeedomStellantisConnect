# Index de la doc IMOU Open API

> **But** : éviter de re-fetcher le sommaire en ligne (`start.html`) à chaque besoin.
> Cet index local recense **toutes les pages** de la doc IMOU avec leur URL directe.
> L'agent lit cet index (gratuit), repère la page utile, puis fait **un seul `WebFetch`
> directement sur cette page** — sans repasser par le sommaire en ligne.
>
> **URL de base** : `https://open.imoulife.com/book/en/`
> Pour obtenir l'URL complète d'une page, préfixer le chemin de la colonne « Page » par cette base.
> Ex. : `http/accessToken.html` → `https://open.imoulife.com/book/en/http/accessToken.html`
>
> **Maintenance** : si une page n'existe pas (404) ou si l'arborescence change, re-générer cet
> index depuis `start.html` et mettre à jour la date ci-dessous.
> **Dernière synchro** : 2026-06-17.

---

## 0. Correspondance « déclencheurs doc » → pages (raccourci)

Déclencheurs courants d'une consultation doc → page (auto-portés ci-dessous) :

| Déclencheur | Page(s) prioritaire(s) |
|---|---|
| 1. Auth & signature | `http/develop.html` |
| 2. Token (validité, refresh, scope) | `http/accessToken.html` ; `readme/account.html` |
| 3. Endpoint d'une action | voir domaine ci-dessous (PTZ, on/off, surveillance, stockage…) |
| 4. Paramètres de requête | page de l'endpoint concerné |
| 5. Format de réponse | page de l'endpoint concerné |
| 6. Codes d'erreur | `faq/code.html` |
| 7. Capacités (`abilities`) | `faq/feature.html` ; `faq/ability.html` ; `http/device/manage/query/listDeviceAbility.html` |
| 8. Prérequis / dépendances d'appel | `readme/model.html` ; summary du domaine ; `http/device/live/bindDeviceLive.html` |
| 9. Quotas & limites de fréquence | `faq/limit.html` |
| 10. Disponibilité/fiabilité endpoint | page endpoint + recouper `.memory/specs/README.md` |

---

## 1. Socle / essentiels (à connaître avant tout)

| Page | Sujet |
|---|---|
| `start.html` | Description du document (sommaire en ligne) |
| `readme/create.html` | Créer une application (appId/appSecret) |
| `readme/model.html` | Modes d'intégration (docking mode) |
| `readme/account.html` | Système de comptes |
| `readme/upload.html` | Téléchargement de ressources |
| `http/develop.html` | **Spécification de développement** (signature, enveloppe `system`, format requête) |
| `http/privacy.html` | Politique de confidentialité API |
| `http/postman.html` | Simulation des interfaces via Postman |
| `http/accessToken.html` | **accessToken** : obtenir le token administrateur |

## 2. Références (codes, capacités, quotas)

| Page | Sujet |
|---|---|
| `faq/code.html` | **Codes de retour globaux** (mapping d'erreurs) |
| `faq/feature.html` | Interrupteurs de capacité appareil (capability switch) |
| `faq/ability.html` | Ensemble des capacités appareil (ability set) |
| `faq/limit.html` | **Limites d'appel d'interface** (quotas / fréquence) |
| `faq/oemAppService.html` | Accord de licence service/logiciel OEMApp |

## 3. Gestion des appareils (équipements)

### Ajout / liaison
| Page | Sujet |
|---|---|
| `http/device/manage/bind/summary.html` | Résumé ajout d'appareil |
| `http/device/manage/bind/bindDevice.html` | bindDevice |
| `http/device/manage/bind/unBindDevice.html` | unBindDevice |

### Découverte / requête (MVP 05)
| Page | Sujet |
|---|---|
| `http/device/manage/query/summary.html` | Résumé requête d'appareils |
| `http/device/manage/query/deviceBaseList.html` | deviceBaseList |
| `http/device/manage/query/deviceBaseDetailList.html` | deviceBaseDetailList |
| `http/device/manage/query/queryBaseDeviceChannelInfo.html` | queryBaseDeviceChannelInfo |
| `http/device/manage/query/deviceOpenList.html` | deviceOpenList |
| `http/device/manage/query/deviceOpenDetailList.html` | deviceOpenDetailList |
| `http/device/manage/query/queryOpenDeviceChannelInfo.html` | queryOpenDeviceChannelInfo |
| `http/device/manage/query/listDeviceDetailsByIds.html` | listDeviceDetailsByIds |
| `http/device/manage/query/listDeviceDetailsByPage.html` | listDeviceDetailsByPage |
| `http/device/manage/query/deviceOnline.html` | deviceOnline (état en ligne) |
| `http/device/manage/query/listDeviceAbility.html` | **listDeviceAbility** (capacités) |
| `http/device/manage/query/checkDeviceBindOrNot.html` | checkDeviceBindOrNot |
| `http/device/manage/query/bindDeviceChannelInfo.html` | bindDeviceChannelInfo |
| `http/device/manage/query/deviceVersionList.html` | deviceVersionList |
| `http/device/manage/query/unBindDeviceInfo.html` | unBindDeviceInfo |
| `http/device/manage/query/shareDeviceList.html` | shareDeviceList |
| `http/device/manage/query/upgradeProcessDevice.html` | upgradeProcessDevice |
| `http/device/manage/query/subAccountDeviceInfo.html` | subAccountDeviceInfo |
| `http/device/manage/query/subAccountDeviceList.html` | subAccountDeviceList |

### Maintenance
| Page | Sujet |
|---|---|
| `http/device/manage/update/summary.html` | Résumé maintenance |
| `http/device/manage/update/modifyDeviceName.html` | modifyDeviceName |
| `http/device/manage/update/uploadDeviceCoverPicture.html` | uploadDeviceCoverPicture |
| `http/device/manage/update/verifyPassword.html` | verifyPassword |
| `http/device/manage/update/modifyPassword.html` | modifyPassword |
| `http/device/manage/update/upgradeDevice.html` | upgradeDevice |

## 4. Configuration des appareils

### Capacités / état caméra (MVP 08 on/off, MVP 10 polling)
| Page | Sujet |
|---|---|
| `http/device/config/ability/summary.html` | Résumé capacités |
| `http/device/config/ability/setDeviceCameraStatus.html` | **setDeviceCameraStatus** (allumer/éteindre) |
| `http/device/config/ability/getDeviceCameraStatus.html` | **getDeviceCameraStatus** (lire l'état) |

### Détection de mouvement / surveillance (MVP 09, alarmes)
> ⚠️ **MVP09 surveillance on/off** : utiliser `setDeviceCameraStatus` avec `enableType='motionDetect'`
> (section « Capacités / état caméra » ci-dessus), **pas** `modifyDeviceAlarmStatus`. La doc IMOU
> recommande explicitement `setDeviceCameraStatus` pour les appareils **PaaS** (prérequis du plugin).
> `motionDetect` est DIRECT (`enable=true` ⇒ surveillance active), contrairement à `closeCamera`
> (inversé). Vérifié doc IMOU le 2026-06-17 (cf. `.memory/specs/MVP/09-action-surveillance-tech.md`).

| Page | Sujet |
|---|---|
| `http/device/config/alarm/summary.html` | Résumé détection de mouvement |
| `http/device/config/alarm/modifyDeviceAlarmStatus.html` | modifyDeviceAlarmStatus (legacy / non-PaaS ; pour PaaS préférer `setDeviceCameraStatus`) |
| `http/device/config/alarm/deviceAlarmPlan.html` | deviceAlarmPlan |
| `http/device/config/alarm/modifyDeviceAlarmPlan.html` | modifyDeviceAlarmPlan |
| `http/device/config/alarm/setDeviceAlarmSensitivity.html` | setDeviceAlarmSensitivity |
| `http/device/config/alarm/setDeviceAlarmRegion.html` | setDeviceAlarmRegion |
| `http/device/config/alarm/getDeviceAlarmParam.html` | getDeviceAlarmParam |

### Stockage (carte SD)
| Page | Sujet |
|---|---|
| `http/device/config/storage/summary.html` | Résumé config stockage |
| `http/device/config/storage/deviceStorage.html` | deviceStorage |
| `http/device/config/storage/deviceSdcardStatus.html` | deviceSdcardStatus |
| `http/device/config/storage/recoverSDCard.html` | recoverSDCard (formatage) |

### WiFi
| Page | Sujet |
|---|---|
| `http/device/config/wifi/summary.html` | Résumé WiFi |
| `http/device/config/wifi/wifiAround.html` | wifiAround |
| `http/device/config/wifi/controlDeviceWifi.html` | controlDeviceWifi |
| `http/device/config/wifi/currentDeviceWifi.html` | currentDeviceWifi |

### Points de collection (PTZ presets)
| Page | Sujet |
|---|---|
| `http/device/config/collection/summary.html` | Résumé points de collection |
| `http/device/config/collection/setCollection.html` | setCollection |
| `http/device/config/collection/getCollection.html` | getCollection |
| `http/device/config/collection/modifyCollection.html` | modifyCollection |
| `http/device/config/collection/deleteCollection.html` | deleteCollection |
| `http/device/config/collection/turnCollection.html` | turnCollection |

### Cruise (ronde)
| Page | Sujet |
|---|---|
| `http/device/config/cruise/summary.html` | Résumé cruise |
| `http/device/config/cruise/setTimeCruisePlan.html` | setCruiseConfig (setTimeCruisePlan) |
| `http/device/config/cruise/getTimeCruisePlan.html` | getCruiseConfig (getTimeCruisePlan) |

### Écran vidéo (OSD, nuit, zoom, projecteur)
| Page | Sujet |
|---|---|
| `http/device/config/video/summary.html` | Résumé écran vidéo |
| `http/device/config/video/setDeviceOsd.html` | setDeviceOsd |
| `http/device/config/video/queryDeviceOsd.html` | queryDeviceOsd |
| `http/device/config/video/frameReverseStatus.html` | frameReverseStatus |
| `http/device/config/video/modifyFrameReverseStatus.html` | modifyFrameReverseStatus |
| `http/device/config/video/getZoomFocus.html` | getZoomFocus |
| `http/device/config/video/setZoomFocus.html` | setZoomFocus |
| `http/device/config/video/setNightVisionMode.html` | setNightVisionMode |
| `http/device/config/video/getNightVisionMode.html` | getNightVisionMode |
| `http/device/config/video/setFillLightSensitivity.html` | setFillLightSensitivity (projecteur/lumière) |
| `http/device/config/video/getFillLightSensitivity.html` | getFillLightSensitivity |

### Enregistrement (planning local)
| Page | Sujet |
|---|---|
| `http/device/config/record/summary.html` | Résumé config enregistrement |
| `http/device/config/record/setLocalRecordPlanRules.html` | setLocalRecordPlanRules |
| `http/device/config/record/queryLocalRecordPlan.html` | queryLocalRecordPlan |
| `http/device/config/record/setLocalRecordStream.html` | setLocalRecordStream |
| `http/device/config/record/queryLocalRecordStream.html` | queryLocalRecordStream |

### Fuseau horaire
| Page | Sujet |
|---|---|
| `http/device/config/timezone/summary.html` | Résumé fuseau horaire |
| `http/device/config/timezone/timeZoneConfigByDay.html` | timeZoneConfigByDay |
| `http/device/config/timezone/timeZoneConfigByWeek.html` | timeZoneConfigByWeek |
| `http/device/config/timezone/timeZoneQueryByDay.html` | timeZoneQueryByDay |
| `http/device/config/timezone/timeZoneQueryByWeek.html` | timeZoneQueryByWeek |

## 5. Opérations sur l'appareil (PTZ, snapshot, redémarrage)

| Page | Sujet |
|---|---|
| `http/device/operate/summary.html` | Résumé opérations |
| `http/device/operate/setDeviceSnap.html` | setDeviceSnap (capture image) |
| `http/device/operate/setDeviceSnapEnhanced.html` | setDeviceSnapEnhanced |
| `http/device/operate/controlMovePTZ.html` | **controlMovePTZ** (PTZ directionnel) |
| `http/device/operate/controlLocationPTZ.html` | controlLocationPTZ (PTZ position) |
| `http/device/operate/devicePTZInfo.html` | devicePTZInfo |
| `http/device/operate/restartDevice.html` | restartDevice |
| `http/device/operate/calibrationDeviceTime.html` | calibrationDeviceTime |
| `http/device/operate/getDeviceTime.html` | getDeviceTime |

## 6. Enregistrements vidéo

### Local
| Page | Sujet |
|---|---|
| `http/device/record/local/summary.html` | Résumé enregistrement local |
| `http/device/record/local/queryLocalRecordBitmap.html` | queryLocalRecordBitmap |
| `http/device/record/local/queryLocalRecordNum.html` | queryLocalRecordNum |
| `http/device/record/local/queryLocalRecords.html` | queryLocalRecords |

### Cloud
| Page | Sujet |
|---|---|
| `http/device/record/cloud/summary.html` | Résumé enregistrement cloud |
| `http/device/record/cloud/queryCloudRecordBitmap.html` | queryCloudRecordBitmap |
| `http/device/record/cloud/queryCloudRecordNum.html` | queryCloudRecordNum |
| `http/device/record/cloud/queryCloudRecords.html` | queryCloudRecords |
| `http/device/record/cloud/getCloudRecords.html` | getCloudRecords |
| `http/device/record/cloud/deleteCloudRecords.html` | deleteCloudRecords |

## 7. Messages & alarmes

| Page | Sujet |
|---|---|
| `http/device/alarm/summary.html` | Résumé messages appareil |
| `http/device/alarm/getAlarmMessage.html` | getAlarmMessage |
| `http/device/alarm/deleteAlarmMessage.html` | deleteAlarmMessage |

## 8. Diffusion live

| Page | Sujet |
|---|---|
| `http/device/live/summary.html` | Résumé diffusion live |
| `http/device/live/bindDeviceLive.html` | bindDeviceLive (prérequis flux) |
| `http/device/live/unbindLive.html` | unbindLive |
| `http/device/live/liveList.html` | liveList |
| `http/device/live/queryLiveStatus.html` | queryLiveStatus |
| `http/device/live/getLiveStreamInfo.html` | getLiveStreamInfo (URL flux) |
| `http/device/live/modifyLivePlanStatus.html` | modifyLivePlanStatus |
| `http/device/live/modifyLivePlan.html` | modifyLivePlan |
| `http/device/live/batchModifyLivePlan.html` | batchModifyLivePlan |
| `http/device/live/createDeviceRtmpLive.html` | createDeviceRtmpLive |
| `http/device/live/deleteDeviceRtmpLive.html` | deleteDeviceRtmpLive |
| `http/device/live/queryDeviceRtmpLive.html` | queryDeviceRtmpLive |

## 9. Serrures / contrôle d'accès (sonnette vidéo, porte)

| Page | Sujet |
|---|---|
| `http/door/summary.html` | Résumé accès serrure |
| `http/door/getDevicePowerInfo.html` | getDevicePowerInfo |
| `http/door/openDoorRemote.html` | openDoorRemote |
| `http/door/getDoorKeys.html` | getDoorKeys |
| `http/door/deleteDoorKey.html` | deleteDoorKey |
| `http/door/generateSnapkey.html` | generateSnapkey |
| `http/door/getSnapkeyList.html` | getSnapkeyList |
| `http/door/getOpenDoorRecord.html` | getOpenDoorRecord |
| `http/door/wakeUpDevice.html` | wakeUpDevice |
| `http/door/doorbellCallAnswer.html` | doorbellCallAnswer |
| `http/door/doorbellCallHangUp.html` | doorbellCallHangUp |
| `http/door/doorbellCallRefuse.html` | doorbellCallRefuse |

## 10. Comptes & sous-comptes

| Page | Sujet |
|---|---|
| `http/account/summary.html` | Résumé intégration de comptes |
| `http/account/subaccount.html` | Description fonction sous-compte |
| `http/account/createSubAccount.html` | createSubAccount |
| `http/account/getOpenIdByAccount.html` | getOpenIdByAccount |
| `http/account/deleteSubAccount.html` | deleteSubAccount |
| `http/account/listSubAccount.html` | listSubAccount |
| `http/account/subAccountToken.html` | subAccountToken |
| `http/account/addPolicy.html` | addPolicy |
| `http/account/clearPolicy.html` | clearPolicy |
| `http/account/queryDevicePermission.html` | queryDevicePermission |
| `http/account/deleteDevicePermission.html` | deleteDevicePermission |
| `http/account/listSubAccountDevice.html` | listSubAccountDevice |

## 11. Push de messages / callback

| Page | Sujet |
|---|---|
| `push/push.html` | Processus de push d'événements |
| `push/alarm.html` | Définition des types de messages d'événement |
| `push/event.html` | Format des messages d'événement |
| `http/push/summary.html` | Résumé config push |
| `http/push/setMessageCallback.html` | setMessageCallback |
| `http/push/getMessageCallback.html` | getMessageCallback |

## 12. Service de stockage cloud

| Page | Sujet |
|---|---|
| `http/cloud/summary.html` | Résumé stockage cloud |
| `http/cloud/cloudStorage.html` | Présentation des forfaits cloud |
| `http/cloud/openCloudRecord.html` | openCloudRecord |
| `http/cloud/unBindDeviceCloud.html` | unBindDeviceCloud |
| `http/cloud/getDeviceCloud.html` | getDeviceCloud |
| `http/cloud/deviceCloudList.html` | deviceCloudList |
| `http/cloud/setAllStorageStrategy.html` | setAllStorageStrategy |
| `http/cloud/setStorageStrategy.html` | setStorageStrategy |
| `http/cloud/queryCloudRecordCallNum.html` | queryCloudRecordCallNum |
| `http/cloud/unUsedCloudList.html` | unUsedCloudList |

## 13. IoT — modèle de données « Things »

| Page | Sujet |
|---|---|
| `iot/IoTThingsDataModel.html` | Vue d'ensemble du modèle de données IoT |
| `iot/getProductModel.html` | getProductModel |
| `iot/iotDeviceControl.html` | iotDeviceControl |
| `iot/getIotDeviceProperties.html` | getIotDeviceProperties |
| `iot/setIotDeviceProperties.html` | setIotDeviceProperties |

## 14. SDK & autres intégrations (hors périmètre plugin PHP, pour info)

| Page | Sujet |
|---|---|
| `js/sdk.html` | Développement JavaScript (application légère) |
| `mobile/summary.html` | Résumé développement mobile |
| `mobile/android/sdk.html` / `mobile/android/demo.html` | OpenSDK Android |
| `mobile/ios/sdk.html` / `mobile/ios/demo.html` | OpenSDK iOS |
| `pc/summary.html` / `pc/demo.html` | Application desktop |
| `guide/haDev.html` | Développement Home Assistant (tiers) |
