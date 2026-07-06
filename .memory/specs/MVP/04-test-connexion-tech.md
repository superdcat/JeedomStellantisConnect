# Spec technique — MVP 04 — Test de connexion

> Référence fonctionnelle : `04-test-connexion.md`. Dépend de MVP 03 (`callWithToken`).
> Contrat vérifié le **2026-07-06** contre `psa_car_controller` (`psa_client.py` :
> `res.embedded.vehicles`, mapping HAL → JSON brut `_embedded.vehicles`, champs `{vin, id, brand,
> label}` par véhicule).

## Architecture

### `core/class/stellantis.class.php` — classe `stellantis`
- `public static function testConnection(): array` → `['ok' => bool, 'count' => int,
  'message' => string]`. **Toujours** cette structure, quel que soit l'état (consommable par un futur
  appelant non-AJAX, ex. cron UC08) :
  - `!isConfigured()` → `ok=false`, message « non configuré » (même libellé que le garde AJAX —
    harmonisé, review 2026-07-06) ;
  - **cooldown serveur 15 s** (`cache stellantis::test_cooldown`, review sécurité 2026-07-06) :
    l'anti double-clic JS est contournable en appelant l'AJAX directement, or chaque test = 1 vrai
    appel API → un second test sous 15 s répond `ok=false` sans réseau ;
  - `stellantisApi::callWithToken('GET', '/user/vehicles')` ;
  - succès → `ok=true`, `count = count(_embedded.vehicles)` (`isset` ternaire, style du fichier ;
    réponse 2xx sans `_embedded.vehicles` → `count=0`, `ok=true`) ;
  - `catch stellantisException` → `ok=false`, message via `messageDepuisException()`.
- `private static function messageDepuisException(stellantisException $e): string` — mapping
  `apiType` → message **actionnable** :
  - `auth_required` → « Aucune connexion au compte ou session expirée : (ré)authentifiez-vous depuis
    la configuration du plugin » ;
  - `token_expired` → « Token invalide malgré un rafraîchissement : réessayez, puis
    ré-authentifiez-vous si le problème persiste » ;
  - `rate_limited` → « Trop de requêtes : réessayez plus tard » ;
  - `transport` → « API injoignable : vérifiez la connexion Internet de votre Jeedom » ;
  - défaut (`api_error`) → **message générique « Erreur API (HTTP N) : consultez les logs du
    plugin »** — le corps brut de la réponse reste dans le log debug de `httpRequest()`, il n'est
    **pas** réinjecté dans le DOM (advisor 2026-07-06). Pas de branche `privacy` (jamais produite
    avant UC07 — YAGNI).
- **i18n** : ces messages sont des **chaînes UI** → enveloppées `__('...', __FILE__)` (nuance actée à
  la décision UC01 : seuls logs et messages d'exception restent non enveloppés).

### `core/ajax/stellantis.ajax.php`
- Branche `testConnection` → `ajax::success(stellantis::testConnection())`, placée **avant** le garde
  global `isConfigured()` (advisor 2026-07-06) : le cas « non configuré » répond ainsi en
  `ajax::success({ok:false, message:...})` avec le message de la spec, structure uniforme — le garde
  global ne protège que les actions OAuth.
- Jamais d'appel direct `stellantisApi::` (autoload + fidélité spec).

### `desktop/php/stellantis.php`
- Vignette « Tester la connexion » dans la section Gestion : `div.cursor.logoSecondary`
  `id="stellantis_btTestConnexion"`, icône `fa-plug` — **sans** classe `eqLogicAction` (réservée au
  dispatcher core).

### `desktop/js/stellantis.js`
- Handler `$('#stellantis_btTestConnexion').off('click').on('click', ...)` :
  - **anti double-clic** : bouton désactivé au `beforeSend` (classe `disabled`), réactivé en
    `complete` (1 test = 1 appel API réel + refresh potentiel → guardrail anti-ban) ;
  - **`data.state != 'ok'` testé en premier** (`data.result` est alors une *string* →
    `showAlert({message: data.result, level: 'danger'})`) ; sinon
    `showAlert({message: data.result.message, level: data.result.ok ? 'success' : 'warning'})`
    (advisor 2026-07-06 : tester `data.result.ok` d'abord afficherait « undefined » sur ajax::error).

## Server vs Client
Serveur = tout le test et le mapping des messages ; client = déclenchement + affichage. Aucune donnée
sensible ne transite (ni token ni corps brut d'API dans la réponse AJAX).

## Validation
Serveur uniquement (types d'erreur déjà garantis par `stellantisException`). Client : aucun input.

## Server Actions / API
- AJAX `action=testConnection` (admin-only) → `{ok: bool, count: int, message: string}`.
- Coût : 1 appel `GET /user/vehicles` (+ 1 refresh éventuel), déclenché manuellement — accepté.

## Dépendances
Aucune.
