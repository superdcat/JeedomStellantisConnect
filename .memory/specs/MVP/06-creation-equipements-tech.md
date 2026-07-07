# Spec technique — MVP 06 — Création / mise à jour des équipements

> Référence fonctionnelle : `06-creation-equipements.md`. Dépend de MVP 05 (`discoverVehicles()`).
> Plan validé le **2026-07-07** (advisor `code-reviewer` + vérification source core Jeedom).

## Contrat API (vérifié)
Aucun **nouvel** appel API. `syncVehicles()` réutilise `stellantis::discoverVehicles()` (UC05,
`GET /user/vehicles`, déjà validé contre `psa_car_controller`) qui retourne une liste de
`['id', 'vin', 'brand', 'label', 'energy']`.

## Signatures core Jeedom (vérifiées contre la source `jeedom/core`, 2026-07-07)
- `eqLogic::byLogicalId($_logicalId, $_eqType_name, $_multiple = false)` — **pas** de paramètre
  `onlyEnable` : un eqLogic **désactivé est retrouvé** (donc pas de doublon sur re-sync d'un véhicule
  auto-désactivé). Retourne l'objet, ou `null`/`false` si absent → tester `!is_object($eq)`.
- `eqLogic::byType($_eqType_name, $_onlyEnable = false)` — défaut `false` → retourne **tous** les
  eqLogic (désactivés inclus), nécessaire pour détecter les véhicules disparus.

## Architecture — `core/class/stellantis.class.php` (classe `stellantis`)
`public static function syncVehicles(): array` retourne une **structure uniforme**
`['ok'=>bool, 'created'=>int, 'updated'=>int, 'disabled'=>int, 'reactivables'=>int, 'message'=>string]`
(même esprit que `testConnection()`), et **mappe `stellantisException` en interne** via la privée
`messageDepuisException()` (UC05-tech : « les appelants — UC06 sync — mappent »).

1. `discoverVehicles()` dans un `try/catch (stellantisException)` → `{ok:false, ..., message}` si erreur.
2. Pour chaque véhicule découvert (**try/catch par véhicule** — convention robustesse : un `save()` en
   erreur n'interrompt pas la boucle, log `warning` sans VIN sensible, on continue) :
   - `$eq = eqLogic::byLogicalId($v['vin'], 'stellantis')`.
   - **Absent** (`!is_object($eq)`) → `new stellantis()` : `setLogicalId($v['vin'])`,
     `setEqType_name('stellantis')`, `setIsEnable(1)`, `setIsVisible(1)`,
     `setName(trim($v['brand'].' '.$v['label']))` (fallback VIN si vide). → `created++`
   - **Présent** → on **ne touche NI `name` NI `object_id` NI `isEnable`** (préserve la perso
     utilisateur). Si l'eqLogic est désactivé et de nouveau découvert → `reactivables++` (pas de
     ré-activation auto : on ne sait pas distinguer désactivation auto vs manuelle ; l'utilisateur
     réactive à la main). → `updated++`
   - **Dans les deux cas** — champs techniques de `configuration` :
     - `apiId` = `$v['id']` : **toujours** réécrit (self-heal — requis pour les appels REST UC07+).
     - `vin` = `$v['vin']`, `brand` = `$v['brand']` : réécrits (découverte = autorité).
     - `energy` = `$v['energy']` : réécrit **uniquement si la config `energy` est vide**
       (`empty(...)` && `!empty($v['energy'])`) — ne jamais clobberer une valeur raffinée par UC07
       (`/status`, même vocabulaire). Commentaire FR obligatoire au-dessus.
   - `$eq->save()`.
3. **Véhicules disparus** : construire le set des VIN découverts ; itérer `eqLogic::byType('stellantis')` ;
   tout eqLogic `stellantis` dont le `logicalId` n'est **pas** dans le set ET encore activé →
   `setIsEnable(0)` + `save()` → `disabled++` (désactiver, **pas** supprimer — cf. UC76).
4. Message récap FR construit avec `__()` + `sprintf` (compteurs). Mention `reactivables` si > 0.

## Server vs Client
- **Serveur** : toute la logique de sync (mutations eqLogic — jamais côté client).
- **Client** : 1 bouton + 1 handler AJAX. Aucune donnée métier construite côté JS.

## Validation
- Serveur : `discoverVehicles()` garantit déjà `id`/`vin` scalaires non vides (UC05). Idempotence par
  `logicalId = VIN`. try/catch par véhicule.
- Client : bouton désactivé pendant la requête (anti double-clic). Test `data.state != 'ok'` (enveloppe
  AJAX) **en premier**, puis `data.result.ok` (booléen métier) — pattern identique à `testConnection`.

## Server Actions / API
```php
stellantis::syncVehicles(): array
// ['ok'=>bool,'created'=>int,'updated'=>int,'disabled'=>int,'reactivables'=>int,'message'=>string]
// Ne lève PAS : stellantisException mappée en interne → {ok:false, message}.
```
AJAX (`core/ajax/stellantis.ajax.php`) : branche `if (init('action') == 'sync')` placée **après** le
garde global `if (!stellantis::isConfigured()) throw` → `ajax::success(stellantis::syncVehicles());`.

## Front (`desktop/php/stellantis.php` + `desktop/js/stellantis.js`)
- PHP : bouton `{{Synchroniser les véhicules}}` (id `stellantis_btSync`, icône `fa-sync`) dans la barre
  de gestion, à côté de `stellantis_btTestConnexion`.
- JS : handler **délégué** `$('body').off('click','#stellantis_btSync').on('click','#stellantis_btSync', …)`
  (page rechargée en AJAX → délégation obligatoire, cf. mémoire `jeedom-front-config-page`), bouton
  `disabled` pendant la requête, `$('#div_alert').showAlert({message, level})`, puis
  **`window.location.reload()`** après succès pour rafraîchir la liste des cartes véhicules.
  ⚠️ **Dette technique MVP assumée** : `reload()` complet (perte scroll/tri). À remplacer par un
  re-render AJAX quand un endpoint `list` existera (post-MVP).

## i18n (chaînes FR introduites — traduction différée au sous-agent `translator`)
- `desktop/php/stellantis.php` : `{{Synchroniser les véhicules}}`
- `core/class/stellantis.class.php` (via `__()`) : messages de récap de `syncVehicles()`.

## Dépendances
Aucune (100 % PHP + JS, pas de démon).
```
