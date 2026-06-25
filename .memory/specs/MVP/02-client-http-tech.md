# Spec technique — MVP 02 — Client HTTP REST `stellantisApi`

> Référence fonctionnelle : `02-client-http.md`. Dépend de MVP 01 (config).
> ⚠️ Contrat API consommateur **non documenté officiellement** : recouper avec `psa_car_controller`
> (`psa_client.py`) au moindre doute — cf. `.memory/analyse/stellantis-implementations-reference.md`.

## Architecture
Tout dans la brique unique `stellantisApi` (`core/class/stellantis.class.php`, **même fichier** que
`stellantis`/`stellantisCmd` pour respecter l'autoload : chargée via la classe principale). Pas de front.

### Méthodes publiques
```php
// transport REST bas niveau (sans logique de token)
public static function call(string $method, string $path, array $params = [], ?string $accessToken = null): array
```
- Construit l'URL `apiBaseUrl . $path` (base `https://api.groupe-psa.com/connectedcar/v4`).
- `GET` → `$params` en query (+ `client_id`). `POST`/`PUT` → corps JSON.
- Headers : `Authorization: Bearer $accessToken` (si fourni), `x-introspect-realm: {realm}`,
  `Accept: application/json`, `Content-Type: application/json` (écriture).
- cURL : `CURLOPT_TIMEOUT` ~15 s, `CURLOPT_RETURNTRANSFER`, suivre redirections **non** (sécurité).
- Décodage : `json_decode($body, true)`. `2xx` → retourner le tableau (ou `[]` si corps vide).
  Sinon → `throw new stellantisException(...)` typée (cf. ci-dessous).

### `stellantisException`
- Champs : `httpCode` (int), `apiType` (string : `token_expired|auth_required|rate_limited|privacy|api_error|transport`),
  message tronqué.
- Statique : `typeFromResponse(int $http, ?array $body): string` (401→token_expired ;
  `invalid_grant`→auth_required ; 429→rate_limited ; corps vide + 200 sur /status possible→privacy à
  laisser au métier ; sinon api_error). `isTokenError()`, `isRateLimited()`.

## Server vs Client
100 % **serveur** (PHP). Aucune UI.

## Validation
- HTTP `2xx` requis ; sinon exception. Corps non-JSON sur `2xx` → exception `api_error` (sauf vide toléré).
- Timeout / erreur cURL (pas de réponse) → `transport` (ne consomme **pas** de quota → ne pas compter).

## Sécurité / logs
- `redactHeaders()` : ne jamais logger `Authorization`/`client_secret`. Logs `debug` = `METHOD path`
  + code HTTP, **pas** le token. `client_secret` n'apparaît jamais ici (réservé à la couche OAuth).

## Dépendances
Aucune (cURL natif PHP). MVP : `packages.json` reste vide.
