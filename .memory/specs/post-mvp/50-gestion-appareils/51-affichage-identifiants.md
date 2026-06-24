# UC — Affichage des identifiants techniques (deviceId / productId)

**Domaine :** Gestion appareils · **Dépend de :** MVP/05-06 (découverte/sync) · **Statut :** livré

## Objectif / valeur
Exposer en **lecture seule**, dans l'onglet équipement, les identifiants techniques de la caméra :
- **`deviceId`** (numéro de série) — déjà stocké en configuration depuis la sync (UC06).
- **`productId`** (identifiant de modèle de produit) — **non vide uniquement** pour les appareils
  **IoT « Things »** (doc IMOU `iot/getProductModel.html`).

Utilité : diagnostic utilisateur (identifier précisément l'appareil), et surtout **savoir si la
caméra est un appareil IoT** — prérequis pour la piste « sirène manuelle via `iotDeviceControl` »
(cf. ci-dessous), puisque `setDeviceCameraStatus(enableType='siren')` est refusé (code `40999`) sur
les caméras non-IoT (même verdict que le wontfix `imou_life #61`).

## Contexte — pourquoi productId
`getProductModel` (modèle IoT « Things ») exige un `productId`, retourné par
`listDeviceDetailsByPage`/`listDeviceDetailsByIds`. Sa présence (non vide) **indique** que l'appareil
supporte les interfaces IoT. Affiché à l'utilisateur, il permet de décider si la sirène manuelle est
techniquement atteignable.

## Périmètre
- **Inclus** :
  - Capture de `productId` à la découverte (`normalizeDevice`) et persistance en `configuration`
    de l'eqLogic (`syncEquipments`), comme les autres champs techniques (deviceId/channelId/model/ability).
  - 2 champs **`readonly`** dans l'onglet équipement (`desktop/php/imou.php`), liés en
    `data-l1key="configuration" data-l2key="deviceId|productId"`.
- **Exclu** : toute édition de ces identifiants par l'utilisateur.

> **Historique** : un diagnostic temporaire (`diagnosticIotSirene` + action AJAX GET `diagnosticIot`)
> a servi à récupérer le `getProductModel` et confirmer la piste sirène IoT. **Retiré** une fois la
> sirène manuelle implémentée (cf. `12-projecteur-sirene-tech.md` § sirène IoT). Le mécanisme IoT est
> désormais documenté dans `.memory/analyse/imou-iot-things-model.md`.

## Détails techniques
- `normalizeDevice` ajoute `'productId' => $device['productId']` à chaque entrée normalisée.
- `syncEquipments` : `setConfiguration('productId', $entry['productId'])`.
- Formulaire : champs `eqLogicAttr` `readonly` (Jeedom les peuple automatiquement ; non éditables).
- i18n : 4 nouvelles chaînes UI FR enveloppées `{{...}}` (labels + tooltips) → traduites en/de/es.

## Critères d'acceptation
- [ ] Après synchronisation, l'onglet équipement affiche `deviceId` et `productId` (non modifiables).
- [ ] `productId` vide pour une caméra non-IoT, renseigné pour une caméra IoT.
- [ ] Les champs ne sont pas éditables par l'utilisateur (readonly).

## Notes / risques
- `productId` vide est **normal** pour la plupart des caméras (non-IoT) — ce n'est pas une erreur.
- Le diagnostic `diagnosticIot` est **temporaire** : à retirer une fois la piste sirène tranchée.
- Voir aussi `12-projecteur-sirene.md` / `12-projecteur-sirene-tech.md` (§ écart `siren` / 40999) et
  `11-switches-dynamiques.md` (catalogue, gating par `ability`).
