# Spec technique — 51 affichage identifiants (deviceId / productId)

> Spec fonctionnelle : `51-affichage-identifiants.md` · **Statut : livré.**
> Réutilise la découverte MVP/05-06 ; aucun nouvel appel cloud.

## Architecture
Deux volets, dans `core/class/imou.class.php` et `desktop/php/imou.php` :

### 1. Capture & persistance de `productId` (back-end)
- **`imou::normalizeDevice()`** : ajoute `'productId' => (string) $device['productId']` (champ niveau
  device de `listDeviceDetailsByPage`) à chaque entrée normalisée. Vide si l'appareil n'est pas IoT.
- **`imou::syncEquipments()`** : `setConfiguration('productId', $entry['productId'])` à chaque synchro,
  au même titre que `deviceId`/`channelId`/`model`/`ability` (données techniques rafraîchies).
- `deviceId` était déjà persisté (MVP/06) ; rien à ajouter pour lui.

### 2. Affichage lecture seule (form équipement)
- `desktop/php/imou.php`, section « Paramètres spécifiques » : 2 champs `input` `eqLogicAttr`
  `readonly`, liés en `data-l1key="configuration" data-l2key="deviceId"` et `…="productId"`.
- Jeedom peuple automatiquement ces champs depuis la `configuration` de l'eqLogic ; `readonly`
  empêche l'édition (valeurs purement informatives, gérées par la synchro).

## Server vs Client
Back-end PHP pour la capture/persistance ; côté « client », simple affichage HTML lié au modèle
(aucun JS, aucun AJAX). Pas de vocabulaire Server/Client Next.js pertinent.

## Validation
- `productId` **vide = normal** (caméra non-IoT) — ce n'est pas une erreur, pas de log d'alerte.
- Champs `readonly` → non éditables (l'utilisateur ne peut pas corrompre les identifiants techniques).
- Idempotent : la valeur est re-posée à chaque synchro (cohérent avec les autres champs techniques).
- Aucune donnée sensible (deviceId/productId ne sont pas des secrets) ; pas de neutralisation
  spécifique requise dans le form (Jeedom échappe l'affichage).

## Server Actions / API
- Aucune route ni action nouvelle. La valeur provient de `listDeviceDetailsByPage` (déjà appelé par
  `discoverDevices`/`syncEquipments`).
- `productId` est ensuite consommé par le pilotage IoT (`getProductModel`, `iotDeviceControl` — cf.
  `12-projecteur-sirene-tech.md` et `.memory/analyse/imou-iot-things-model.md`).

## Dépendances
Aucune.

## Impact i18n (FR — traduit en/de/es)
4 chaînes UI dans `desktop/php/imou.php` (labels + infobulles) : « Numéro de série (deviceId) »,
« Identifiant produit (productId) » et leurs tooltips. Couvertes dans `core/i18n/{en_US,de_DE,es_ES}.json`.
