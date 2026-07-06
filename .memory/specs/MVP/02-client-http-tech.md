# Spec technique — MVP 02 — Client HTTP REST `stellantisApi`

> Référence fonctionnelle : `02-client-http.md`. Dépend de MVP 01 (config).
> ⚠️ Contrat API consommateur **non documenté officiellement** : recoupé le **2026-07-06** contre
> `psa_car_controller` (`connected_car_api/configuration.py` + `vehicles_api.py`) — cf.
> `.memory/analyse/stellantis-implementations-reference.md`.

## Contrat transport (vérifié 2026-07-06)
- `client_id` → **query param** (`in: query`), sur **tous** les appels connectedcar, toutes méthodes.
- `Authorization: Bearer {access_token}` → header. `x-introspect-realm: {realm}` → header.
- `Accept: application/hal+json` (la référence envoie **hal+json**, pas `application/json` —
  écart corrigé vs première version de cette spec). Réponses potentiellement enveloppées HAL
  (`_links`/`_embedded`) : **`call()` retourne le JSON décodé tel quel, sans déballer** — le
  déballage est métier (UC05/07).
- Endpoints types : `GET /user/vehicles`, `GET /user/vehicles/{id}/status`.

## Architecture
Tout dans `core/class/stellantis.class.php` (**même fichier** que `stellantis`/`stellantisCmd`,
règle autoload : jamais d'appel `stellantisApi::`/`stellantisException` depuis un point d'entrée
externe sans passage préalable par `stellantis::`). Pas de front, pas d'AJAX.

### Deux niveaux (décision advisor 2026-07-06)
1. **Transport générique privé** — porte cURL, timeout, décodage, mapping d'erreurs, logs redactés :
   ```php
   private static function httpRequest(string $method, string $url, array $headers = [], ?string $rawBody = null): array
   ```
   Réutilisé en UC03 par la couche OAuth (POST `authBaseUrl` en **form-urlencoded** + `Basic`),
   incompatible avec la façade `call()` (Bearer + JSON + `apiBaseUrl`) — évite tout cURL dupliqué.
   - cURL : `CURLOPT_TIMEOUT` 15 s, `CURLOPT_CONNECTTIMEOUT` 10 s, `CURLOPT_RETURNTRANSFER`,
     `CURLOPT_FOLLOWLOCATION` **false** (sécurité).
   - Erreur cURL (pas de réponse) → `stellantisException` type `transport` (ne consomme pas de quota).
   - `2xx` : corps vide (`trim === ''`) → `[]` ; sinon `json_decode($body, true)` puis
     **`json_last_error() !== JSON_ERROR_NONE`** → `api_error` (jamais de test sur la valeur décodée :
     `json_decode('null')` est du JSON valide).
   - `!2xx` → `stellantisException::typeFromResponse()` ; **corps brut complet loggué en `debug`
     avant troncature** ; message d'exception tronqué `mb_substr(..., 0, 500, 'UTF-8')`.
   - Logs `debug` : `METHOD path → HTTP code` **sans query string** (le `client_id` y figure),
     jamais de token/secret/header d'auth.
2. **Façade métier** :
   ```php
   public static function call(string $method, string $path, array $params = [], ?string $accessToken = null): array
   ```
   - Garde-fous (durcis post-review sécurité 2026-07-06) : méthode ∈ {GET, POST, PUT, DELETE},
     `$path` validé par `#^/[A-Za-z0-9/_-]+$#D` (`D` : sans lui, `$` PCRE laisse passer un `\n`
     terminal unique ; bloque `..`, espaces, CR/LF injectés via un segment
     dynamique VIN/apiId — contrôle centralisé dans la brique unique) ; fusion query
     `array_merge($_params, $query)` pour que le `client_id` de la config soit inécrasable.
   - URL = `apiBaseUrl . $path` + query (`client_id` toujours ; + `$params` si GET/DELETE).
   - `POST`/`PUT` → `$params` en corps JSON + `Content-Type: application/json`.
   - Headers : `Accept: application/hal+json`, `x-introspect-realm`, `Authorization: Bearer` si
     `$accessToken` fourni.

### `stellantisException extends Exception`
- Propriétés : `httpCode` (int), `apiType` (string :
  `token_expired|auth_required|rate_limited|privacy|api_error|transport`).
- `static typeFromResponse(int $http, ?array $body): string` : 401 → `token_expired` ; corps
  contenant `invalid_grant` → `auth_required` ; 429 → `rate_limited` ; sinon `api_error`.
  ⚠️ **`privacy` n'est jamais produit ici** : réservé au métier (UC07 le construira sur une réponse
  2xx vide de statut) — documenté dans le docblock.
- Helpers : `isTokenError(): bool` (`token_expired|auth_required`), `isRateLimited(): bool`.

## Server vs Client
100 % **serveur** (PHP). Aucune UI, aucune chaîne i18n (logs/exceptions non enveloppés, décision UC01).

## Validation
- HTTP `2xx` requis ; sinon exception typée. Corps non-JSON sur `2xx` → `api_error` (sauf vide → `[]`).
- Timeout / erreur cURL → `transport`.
- Garde-fous d'entrée (méthode whitelistée, path `/…`).

## Sécurité / logs
- Jamais de log de `Authorization`, `client_secret`, token, ni d'URL complète avec query.
- Corps d'erreur loggué en `debug` uniquement (les corps d'erreur API ne contiennent pas de secret).

## Dépendances
Aucune (cURL natif PHP). MVP : `packages.json` reste vide.
