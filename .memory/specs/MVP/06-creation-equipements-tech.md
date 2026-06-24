# Spec technique — mvp 06 creation mise a jour equipements

> Spec fonctionnelle : `.memory/specs/MVP/06-creation-equipements.md`
> Dépend de : 05 (`imou::discoverDevices()`, déjà livrée).

## Contrat API IMOU
**Aucun nouvel appel cloud.** La tâche 06 consomme la liste renvoyée par
`imou::discoverDevices()` (entrées `['deviceId','name','channelId','model','ability','online']`)
et fait uniquement de la persistance Jeedom (eqLogic). Pas de doc IMOU requise.

## Contrat Jeedom (vérifié dans la source du core)
- `ajax::init($_allowGetAction = array())` (Core **4.4+**, `jeedom/core` master) : **ne prend pas**
  de booléen token ; l'auth est globale depuis 4.4. La protection CSRF d'une action mutante repose
  sur le **forçage du POST** : en laissant le tableau d'actions-GET-autorisées **vide**, toute
  tentative `action=sync` en GET est rejetée. → on garde `ajax::init()` tel quel.
  Le commentaire historique « durcir en `ajax::init(true)` » est **obsolète (modèle V3)** et corrigé.
- eqLogic : `eqLogic::byLogicalId($logicalId, 'imou')` pour l'idempotence ; `new imou()`,
  `setLogicalId/setName/setEqType_name/setIsEnable/setConfiguration`, `save()`.

## Architecture
Composants (4 fichiers) :

### 1. `core/class/imou.class.php` — `imou::syncEquipments(): array`
- `$found = self::discoverDevices();` (peut lever `imouException` → laissée remonter).
- Pour chaque entrée : `$logicalId = $deviceId . '_' . $channelId`.
  - `eqLogic::byLogicalId($logicalId, 'imou')` :
    - **`null` (création)** : `new imou()`, `setLogicalId($logicalId)`, `setEqType_name('imou')`,
      `setName($name)` (nom découvert), `setIsEnable(1)`. → `$created++`.
    - **existant (mise à jour)** : **ne touche ni `name`, ni `object_id`, ni `isEnable`, ni
      `isVisible`** (personnalisation utilisateur préservée — critère d'acceptation 2). → `$updated++`.
  - **Dans les deux cas** : `setConfiguration('deviceId'|'channelId'|'model'|'ability', …)`
    (données techniques rafraîchies depuis le cloud), puis `save()`.
- Retour : `['created'=>int, 'updated'=>int, 'total'=>int]` où **`total = created + updated`**
  (entrées traitées lors de CETTE sync, pas le total d'eqLogic en base).
- Logs `log::add('imou','info', …)` du bilan (compteurs castés `(int)`), jamais de secret.
- **Hors périmètre** : caméra disparue du compte → tâche 11 (désactiver, pas supprimer).
  Simple commentaire dans le code, aucune implémentation.

### 2. `core/ajax/imou.ajax.php` — branche `action=sync`
- `if (init('action') == 'sync')` (POST, `isConnect('admin')` déjà garanti en tête de fichier).
- Structure calquée sur `testConnection` :
  ```php
  if (init('action') == 'sync') {
    try {
      $bilan = imou::syncEquipments();
    } catch (imouException $e) {
      $detail = htmlspecialchars($e->getMessage() . ' (' . $e->getImouCode() . ')', ENT_QUOTES, 'UTF-8');
      ajax::error(__('Échec de la synchronisation IMOU', __FILE__) . ' : ' . $detail, $e->getCode());
    }
    ajax::success(/* message FR avec compteurs */);
  }
  ```
- **catch local obligatoire** : sinon l'exception tombe dans le `catch (Exception)` global
  (`displayException`), perdant le format + le code IMOU.
- Mise à jour du commentaire CSRF obsolète.

### 3. `desktop/php/imou.php` — bouton « Synchroniser »
- Ajouté dans `.eqLogicThumbnailContainer`, **sans** classe `eqLogicAction` ni `data-action`
  (pour ne pas être intercepté par `plugin.template.js`) :
  ```html
  <div class="cursor logoSecondary" id="bt_imouSync">
    <i class="fas fa-sync"></i><br>
    <span>{{Synchroniser les caméras IMOU}}</span>
  </div>
  ```
- Zone d'alerte `<div id="imou_sync_alert">` pour le retour.

### 4. `desktop/js/imou.js` — handler clic
- Pattern `$.ajax` calqué sur `configuration.js` :
  ```js
  $('#bt_imouSync').off('click').on('click', function () { … })
  ```
- POST `plugins/imou/core/ajax/imou.ajax.php`, `data:{action:'sync'}`, `dataType:'json'`,
  `global:false`. Bouton désactivé + alerte d'attente pendant l'appel.
- Succès : `showAlert` succès puis `window.location.reload()` (rechargement de la liste eqLogic).
- Erreur transport : alerte `danger`. `data.result` serveur déjà échappé HTML (anti-XSS).

## Server vs Client
Vocabulaire Next.js inadapté. Répartition Jeedom :
- **Back-end (serveur PHP)** : `syncEquipments()` (logique + persistance) + endpoint AJAX.
- **Front (navigateur)** : bouton + handler JS (déclenchement + retour visuel uniquement, aucune
  logique métier).

## Validation
- **Serveur** : `discoverDevices()` valide déjà la réponse cloud ; `deviceId`/`channelId` castés
  string en tâche 05 → `logicalId` non vide. `byLogicalId` garantit l'idempotence (pas de doublon).
  Préservation explicite des champs personnalisés sur update.
- **Client** : bouton désactivé pendant l'appel ; alerte d'erreur si le serveur Jeedom est injoignable.

## Server Actions / API
- `imou::syncEquipments(): array` → `['created'=>int,'updated'=>int,'total'=>int]`.
- AJAX `action=sync` (POST, admin) → `ajax::success(message FR)` / `ajax::error(détail FR + code IMOU)`.

## Dépendances
Aucune.

## Impact i18n (FR, traduction déléguée à l'Étape 10)
- `desktop/php/imou.php` : `{{Synchroniser les caméras IMOU}}`
- `desktop/js/imou.js` : `{{Synchronisation en cours…}}`, `{{Erreur de communication avec le serveur Jeedom}}`
- `core/ajax/imou.ajax.php` : `__('Échec de la synchronisation IMOU', __FILE__)`,
  `__('Synchronisation terminée', __FILE__)` + libellés de compteurs
  (`__('créé(s)', __FILE__)`, `__('mis à jour', __FILE__)` — formulation figée au dev).
