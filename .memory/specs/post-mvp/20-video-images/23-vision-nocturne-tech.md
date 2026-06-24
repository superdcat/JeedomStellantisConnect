# Spec technique — post mvp 23 (vision nocturne)

> Spec fonctionnelle : `.memory/specs/post-mvp/20-video-images/23-vision-nocturne.md`
> Dépend de : UC12 (catalogue switches), UC13 (auto-découverte des propriétés IoT). Réfère :
> `.memory/analyse/imou-iot-things-model.md`.

## Constat déterminant

L'auto-découverte générique des propriétés IoT livrée en **UC13** couvre DÉJÀ, de bout en bout, la
vision nocturne et la sensibilité de lumière d'appoint pour toute caméra IoT qui les expose :

- `NightMode` (enum) → commande **select** `iot_NightMode_set` + info `iot_NightMode_state` ;
- `fillLightSensitivity` (enum 1-5) → commande **select** `iot_fillLightSensitivity_set` + info ;
- écriture via `setIotDeviceProperties` (`imouCmd::actionIotProperty`), lecture batch via
  `getIotDeviceProperties` (`refreshIotProperties`), polling, gating (propriété absente du modèle ⇒
  aucune commande), idempotence — tout est en place.
- `imou::iotPropertyLabels()` porte déjà les libellés FR de **propriété** :
  `'NightMode' => 'Vision nocturne'`, `'fillLightSensitivity' => "Sensibilité lumière d'appoint"`.

Les 3 critères d'acceptation UC23 sont donc satisfaits par UC13. **UC23 n'ajoute aucun appel cloud.**

## Écart résiduel = libellés FR des VALEURS d'enum

Aujourd'hui les options du select reprennent le `desc` **brut du modèle** (`specs.list[].desc`), souvent
chinois/anglais (cf. `WhiteLightMode` = 常亮/闪烁). Pour la vision nocturne, l'utilisateur doit lire
« Intelligente / Pleine couleur / Infrarouge / Désactivée ». C'est le point « À confirmer : mapping
exact des valeurs d'enum (libellés FR) » de la spec fonctionnelle.

## Architecture — 1 seul fichier `core/class/imou.class.php`

### 1. `imou::iotEnumValueLabels(): array` (NOUVEAU, static)
Table de surcharge FR des **valeurs** d'enum : `identifier => [ valeur(int) => 'libellé FR' ]`.
- `NightMode` : `0 => 'Intelligente'`, `1 => 'Pleine couleur'`, `2 => 'Infrarouge'`, `3 => 'Désactivée'`
  (confirmé Cruiser 2C / analyse IoT).
- Clés de valeur déclarées **en entier** (parade au mismatch int/string du lookup).
- `NightVision` (identifiant alternatif possible) **non mappé** délibérément : mapping des valeurs non
  confirmé sur un modèle réel → ses options retombent sur les `desc` du modèle. À compléter dès qu'un
  modèle réel l'expose. Le libellé de **propriété** `NightVision` est lui ajouté (cf. point 4).

### 2. `imou::iotPropertyWidget()` — branche `enum` (MODIF)
Aujourd'hui : construit directement la chaîne `listValue = "value|desc;…"` (avec neutralisation des
séparateurs sur `$desc`). On la fait produire une **liste structurée d'options** (données brutes, sans
`__()`) pour respecter le pattern UC13 « traduction une seule fois à la création » :
- `$spec['listValue']` devient un **tableau** d'options `['value' => (string)$value, 'desc' => $label,
  'i18nKey' => bool]` où :
  - `$label` = libellé FR de `iotEnumValueLabels()[$identifier][(int)$value]` si présent (brut, **sans
    `__()`**), sinon le `desc` du modèle ;
  - `i18nKey` = `true` ssi un override FR a été utilisé (donc traduisible).
- La neutralisation `|`/`;` n'est **plus** faite ici (déplacée au point 3, sur le libellé final).
- Reste inchangé : value doit être numérique ; enum sans valeurs ⇒ `null`.

⚠️ `iotPropertyWidget()` est statique, appelée à la **création** ET au **refresh** ; `listValue`
n'étant persisté qu'à la création, aucun `__()` ne doit y vivre (sinon coût inutile à chaque cron).

### 3. `imou::buildEnumListValue(array $options): string` (NOUVEAU, static)
Joint la liste d'options en chaîne Jeedom `"value|desc;…"`, en appliquant la traduction **une seule
fois** :
- pour chaque option : `$label = $opt['i18nKey'] ? __($opt['desc'], __FILE__) : $opt['desc'];`
- neutralisation `str_replace(['|',';'], [' ',' '], $label)` sur le libellé **final** (FR ou modèle) ;
- `implode(';', "value|label")`.

Appelé **uniquement** depuis `iotConfiguration()` (donc à la création des commandes, via
`createIotCommands`) — jamais au refresh.

### 4. `iotConfiguration()` (MODIF)
Quand `$spec['listValue']` est un tableau (enum), poser
`$config['listValue'] = self::buildEnumListValue($spec['listValue'])`. Sinon comportement inchangé.

### 5. `imou::iotPropertyLabels()` (MODIF)
Ajouter `'NightVision' => 'Vision nocturne'` (identifiant alternatif ; libellé de propriété seul).

## Server vs Client
100 % back-end PHP. Aucune UI/JS/AJAX/HTML nouvelle : le widget select est rendu par le core depuis
`subType` + `configuration.listValue` (déjà le cas UC13).

## Validation
- **Override appliqué seulement si `(identifier, (int)value)` connu** → propriété/valeur inconnue =
  fallback `desc` modèle. Aucun mode masqué, aucune régression sur les autres caméras/propriétés.
- **Type-safe** : clés de valeur entières dans la table + lookup `(int)$value`.
- **Traduction une seule fois** : `__()` uniquement dans `buildEnumListValue` (appelée à la création),
  jamais dans `iotPropertyWidget` (refresh). Cohérent avec `iotLabel()` (UC13).
- **Séparateurs** : `|`/`;` neutralisés sur le libellé final (FR ou modèle).
- **Idempotence** : `listValue` reconstruit identiquement à chaque re-sync (table déterministe).
- **Gating** : inchangé (auto-découverte UC13).

## Server Actions / API
- `imou::iotEnumValueLabels()`, `imou::buildEnumListValue()` (NOUVEAUX, static) ;
- `imou::iotPropertyWidget()` (branche enum), `imou::iotConfiguration()`, `imou::iotPropertyLabels()`
  (étendus). Aucun nouvel endpoint `imouApi`.

## Dépendances
Aucune.

## Impact i18n (FR uniquement — traduction déléguée Étape 10)
Nouvelles clés FR (libellés de valeurs `NightMode`) : « Intelligente », « Pleine couleur »,
« Infrarouge », « Désactivée ». Extraites par le translator depuis la table littérale
`iotEnumValueLabels()` (même mécanisme que `iotPropertyLabels()`/`iotLabel()`). « Vision nocturne »
existe déjà.

## Tests (recette manuelle)
(a) cam IoT exposant `NightMode` → select avec les 4 libellés FR ;
(b) changer le mode depuis Jeedom → effectif (vérifiable app IMOU) ;
(c) mode courant remonté dans l'info `_state` au cron ;
(d) cam sans `NightMode` → aucune commande vision nocturne ;
(e) propriété enum d'un AUTRE identifiant (non mappé) → libellés bruts du modèle (pas de régression).
