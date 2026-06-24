# Spec technique — MVP 03 — Gestion du token d'accès

> Référence fonctionnelle : `03-token.md`. Dépend de MVP 02 (client HTTP `imouApi`).
> Contrat API confirmé via la doc IMOU `http/accessToken.html` (cf. § Contrat API).

## Contrat API IMOU (validé doc)

- **Méthode** : `accessToken` — POST `/openapi/accessToken`, enveloppe signée, **sans token**.
- **Paramètres** : aucun (`params` = objet vide ; déjà géré par `call()` via `new stdClass()`).
- **Réponse `result.data`** :
  - `accessToken` (String) — token administrateur.
  - `expireTime` (Long) — **durée résiduelle en secondes** (PAS un timestamp). Exemple doc : `259176` (~3 j).
- **Validité** : 3 jours. Reco officielle : renouveler « avant expiration **ou** sur erreur `TK1002` »,
  et **« ne pas appeler fréquemment »** → d'où le cache + refresh proactif.
- **Codes d'erreur token** : `TK1002`, `TK1003`, `OP1007` — déjà dans `imouException::$tokenErrorCodes`
  et reconnus par `imouApi::isTokenError()`.

## Architecture

Tout dans la brique unique `imouApi` (`core/class/imou.class.php`). Aucun front, aucune dépendance.

### Méthodes publiques ajoutées

- **`getToken(bool $force = false): string`**
  - `$force === false` : lit le cache `imou::token`. Si présent, token non vide et
    `time() < exp - TOKEN_MARGE` → retourne le token caché (**aucun appel réseau**).
  - Sinon (forçage, cache absent/purgé, ou proactif `time() >= exp - TOKEN_MARGE`) :
    `call('accessToken')`, valide `accessToken` non vide, calcule
    `exp = time() + duree` où `duree = (int) expireTime` (borné, cf. TTL plancher), écrit le cache,
    retourne le token.
  - Lève `imouException` (RESPONSE_ERROR) si `accessToken` manquant/vide.
- **`callWithToken(string $method, array $params = array()): array`**
  - **Point d'entrée recommandé pour tout appel métier authentifié** (consommé par MVP 04/05+).
  - `getToken()` → `call($method, $params, $token)`.
  - `catch imouException` : si `isTokenError(code)` → `getToken(true)` puis **rejeu unique** ;
    sinon relance l'exception. Le rejeu est strictement borné à 1 (pas de boucle).

### Constantes privées

- `TOKEN_CACHE_KEY = 'imou::token'`
- `TOKEN_MARGE = 300` (s, refresh proactif)
- `TOKEN_TTL_PLANCHER = 86400` (s = 24 h ; appliqué si `expireTime <= 0` ou absent, + warning loggué)

### Helpers privés (nommage anglais, aligné sur `buildEnvelope`/`execHttp`/`sanitizeLog`)

- `readTokenCache(): ?array` — `cache::byKey(TOKEN_CACHE_KEY)->getValue()` + `json_decode`,
  retourne `['token'=>string,'exp'=>int]` ou `null` si absent/illisible.
- `writeTokenCache(string $token, int $exp): void` — `cache::set(TOKEN_CACHE_KEY, json_encode([...]))`.

### Invalidation sur changement de credentials

- `imou::postConfig_appId()` / `imou::postConfig_appSecret()` → `cache::delete('imou::token')`
  pour éviter de rejouer un token d'un ancien compte.

## Server vs Client

100 % **serveur** (PHP). Aucune UI, aucune validation client : la tâche ne produit ni HTML ni JS.

## Validation

- **Serveur uniquement** :
  - `accessToken` non vide → sinon `imouException(CODE_REPONSE)`.
  - `expireTime` casté en `int` et borné : si `<= 0`, fallback `TOKEN_TTL_PLANCHER` + warning.
  - Cache illisible/JSON invalide → traité comme absent (ré-génération à la volée).
  - Rejeu réactif strictement limité à **1** (critère d'acceptation #3).
- Pas de validation côté client (hors périmètre).

## Server Actions / API

Aucune Server Action / route AJAX dans cette tâche. Signatures internes :

```php
// imouApi
public static function getToken(bool $force = false): string
public static function callWithToken(string $method, array $params = array()): array
private static function readTokenCache()        // ?array
private static function writeTokenCache($token, $exp) // void

// imou (eqLogic) — invalidation
public static function postConfig_appId($value)
public static function postConfig_appSecret($value)
```

## Concurrence (best effort)

Pas de mutex PHP-FPM. **Acceptable** : l'API IMOU tolère plusieurs tokens valides simultanés
(TTL 3 j) ; au pire deux appels `accessToken` redondants sur une courte fenêtre. Documenté dans
le docblock de `getToken()` pour éviter l'ajout ultérieur d'un verrou coûteux inutile.

## Sécurité / logs

- **Jamais** logger le token (critère #4). Logs : « token récupéré (expire dans N s) »,
  « refresh proactif/réactif déclenché » — sans la valeur. `redactParams()` masque déjà `token`.
- Cache Jeedom = stockage interne, non exposé web.

## Impact i18n

**Aucune** nouvelle chaîne UI (`{{...}}` / `__()`). Uniquement des logs et messages d'exception
techniques (non i18n par convention). → Étape translator = no-op pour cette tâche.

## Dépendances

Aucune.
