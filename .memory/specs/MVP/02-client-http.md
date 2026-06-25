# 02 — Client HTTP REST bas niveau (`stellantisApi`)

**Phase :** MVP · **Dépend de :** 01 · **Fichiers :** `core/class/stellantis.class.php` (classe utilitaire `stellantisApi`)

## Objectif
Centraliser **tous** les appels REST à l'API consommateur PSA/Stellantis dans une seule brique :
construction de la requête authentifiée (Bearer + realm), envoi HTTPS, parsing JSON, gestion uniforme
des erreurs et exception dédiée. (Le flow OAuth2/token lui-même = tâche 03 ; ici le transport.)

## Périmètre
- **Inclus** : requête cURL générique, headers d'auth, décodage JSON, mapping des codes d'erreur HTTP,
  `stellantisException`, comptage/log non sensible.
- **Exclu** : logique OAuth2 (token) → tâche 03 ; logique métier (véhicules) → 05+ ; MQTT/commandes → post-MVP.

## Détails techniques
- Classe `stellantisApi` avec une méthode pivot :
  `stellantisApi::call(string $method, string $path, array $params = [], ?string $accessToken = null): array`.
- Transport : cURL vers `"$apiBaseUrl$path"` (base `https://api.groupe-psa.com/connectedcar/v4`),
  timeout court (~10-15 s), `Accept: application/json`.
- Headers authentifiés : `Authorization: Bearer {accessToken}` + `x-introspect-realm: {realm}` ;
  `client_id` en **query param** (selon contrat). Realm/base viennent de `stellantis::getApiConfig()`.
- Réponse : `200` → `json_decode` du corps (retourner le tableau). Sinon lever
  `stellantisException($httpCode, $bodyMessage)`.
- **Codes à traiter explicitement** :
  - `401` / corps `invalid_token` → erreur de token (à relayer pour refresh en tâche 03) ;
  - `invalid_grant` (sur l'endpoint token) → refresh/refresh-token mort → ré-auth requise ;
  - `429` / `RateLimitException` → **rate-limit** (backoff, cf. UC72) ;
  - `4xx`/`5xx` génériques → exception typée avec code+message exploitables.
- `client_secret` n'est utilisé que par la couche OAuth2 (tâche 03), **jamais** en clair ici ; logs
  `debug` = méthode + path **sans** token ni secret (`redactHeaders()`).

## Critères d'acceptation
- [ ] `stellantisApi::call('GET','/user/vehicles', [], $token)` part avec Bearer + realm corrects.
- [ ] Une réponse HTTP `!= 2xx` lève une exception typée (code HTTP + message API exploitables).
- [ ] Les logs `debug` montrent méthode + path **sans** token/secret en clair.
- [ ] Aucune autre partie du code ne fait d'appel HTTP REST en dehors de ce client.

## Notes / risques
- Le contrat exact (param `client_id` en query vs header, casse des headers) est à **confirmer** contre
  `psa_car_controller` (`psa_client.py`) — cf. `.memory/analyse/stellantis-implementations-reference.md`.
- Backend Stellantis instable (erreurs 400/`NoneType` historiques) → messages d'erreur précis, jamais de
  `catch` vide.
