# Spec technique — UC56 Affichage du nom de modèle

## Architecture

Pur affichage : **aucun nouvel appel cloud**. `deviceModel` est déjà capté par `normalizeDevice`
(`listDeviceDetailsByPage`) et stocké en `configuration.model` à la synchro (`syncEquipments`).

Fichiers touchés :

1. **`core/class/imou.class.php`**
   - `private static function modelCommercialNames()` — table `code technique → nom commercial`,
     curatée depuis le wiki communautaire imou_life « Supported models »
     (https://github.com/user2684/imou_life/wiki/Supported-models). Esprit `commandCatalog`/`ptzCatalog`.
     Extensible. Privée (résolveur unique point d'entrée public).
   - `public static function modelCommercialName($code)` — résolveur tolérant :
     1. cast string + `trim`,
     2. match exact,
     3. repli match **normalisé** (`strtoupper`) sur clé table normalisée → pare la fragilité de casse,
     4. inconnu → `log::add('imou','debug',…)` (observabilité de la table) puis retour `''`.
   - Dans `syncEquipments`, après `setConfiguration('model', …)` :
     `setConfiguration('modelName', self::modelCommercialName($entry['model']))`.
     `model`/`ability`/`deviceId`/`productId` étant **reposés à chaque sync**, `modelName` l'est aussi
     → table qui évolue ⇒ propagée au prochain sync. Pas de backfill dédié.

2. **`desktop/php/imou.php`** — deux champs **lecture seule** (`readonly`) dans « Paramètres spécifiques »,
   à côté de `deviceId`/`productId` :
   - « Modèle (code) » → `data-l1key="configuration" data-l2key="model"` ;
   - « Nom commercial » → `data-l1key="configuration" data-l2key="modelName"`.

## Server vs Client

**Stockage à la synchro** (`configuration.modelName`), **pas** calcul au rendu : le formulaire `imou.php`
est un template **rempli côté JS** (`eqLogicAttr` auto-load depuis le JSON eqLogic), il n'y a pas de
rendu PHP par-eqLogic. Le défaut « figé » est neutralisé car `modelName` est reposé à chaque sync
(comme `model`/`ability`).

## Validation

Aucune saisie utilisateur (champs `readonly`) → pas de validation serveur ni d'AJAX. Robustesse du
résolveur : cast string, `trim`, repli normalisé puis `''`. Repli **champ vide** (pas de recopie du code,
qui serait redondant avec « Modèle (code) » juste au-dessus).

## Server Actions / API

Aucune. Aucun endpoint cloud ni action AJAX.

## Dépendances

Aucune.

## Chaînes i18n (FR, traduction différée étape 10)

- `{{Modèle (code)}}`
- `{{Code technique du modèle, renseigné par la synchronisation}}`
- `{{Nom commercial}}`
- `{{Nom commercial du modèle, déduit du code technique. Vide si le modèle n'est pas (encore) répertorié.}}`
