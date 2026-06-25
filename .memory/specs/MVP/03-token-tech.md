# Spec technique — MVP 03 — OAuth2 PKCE & token

> Référence fonctionnelle : `03-token.md`. Dépend de MVP 02. ⚠️ Contrat à confirmer contre
> `psa_car_controller` (`psa_client.py`, PR #754) — cf. `stellantis-api-architecture.md` § 1.1.

## Contrat OAuth2 (consommateur, par marque)
- Base auth : `https://idpcvs.{marque.tld}/am/oauth2/` — endpoints `authorize`, `access_token`,
  `token/revoke`. TLD/realm par marque (table dans `getApiConfig()` ; cf. external doc INDEX § 2).
- **Authorize** (GET, ouverture navigateur) : `client_id`, `response_type=code`, `scope=openid profile`,
  `redirect_uri`, `state`, `code_challenge`, `code_challenge_method=S256` (+ `local`/locale si requis).
- **Token** (POST) : header `Authorization: Basic base64(client_id:client_secret)`, body
  `grant_type=authorization_code` + `code` + `redirect_uri` + `code_verifier`. Réponse :
  `{access_token, refresh_token, expires_in (~890), id_token}`.
- **Refresh** (POST) : `grant_type=refresh_token` + `refresh_token`. **Revoke** : `token` + `realm`.

## Architecture (dans `stellantisApi`)
### PKCE helpers
- `genCodeVerifier(): string` — 43-128 chars `[A-Za-z0-9-._~]` aléatoires (source crypto).
- `genCodeChallenge(string $verifier): string` — `rtrim(strtr(base64_encode(hash('sha256',$v,true)),'+/','-_'),'=')`.
- `buildAuthUrl(): string` — génère verifier+state, **stocke en cache court** (`stellantis::oauth_pending`,
  TTL ~10 min : `{verifier,state}`), retourne l'URL. (action AJAX `getAuthUrl`)
- `exchangeCode(string $code, string $state): void` — relit `oauth_pending`, vérifie `state`, POST token,
  `writeTokenCache()`. (action AJAX `submitAuthCode`)

### Token lifecycle
- Constantes : `TOKEN_CACHE_KEY='stellantis::token'`, `TOKEN_MARGE=60` (s).
- `getToken(bool $force=false): string` — lit cache ; valide (`time()<exp-MARGE` et `!$force`) → rend ;
  sinon `refresh()` (POST refresh_token) → `writeTokenCache()` → rend. Si pas de refresh_token →
  `stellantisException('auth_required')`.
- `callWithToken($method,$path,$params=[])` — `getToken()`+`call()` ; `catch` : `isTokenError` →
  `getToken(true)` + **rejeu unique** ; `invalid_grant`/`auth_required` → relance (pas de boucle).
- `readTokenCache(): ?array` / `writeTokenCache($access,$refresh,$exp): void` — `cache::set/byKey`,
  **valeurs chiffrées** (`utils::encrypt`) ; `exp = time() + (int)expires_in` (plancher si absurde).

### Invalidation
- `stellantis::postConfig_client_id()` / `postConfig_brand()` → `cache::delete('stellantis::token')`
  + `cache::delete('stellantis::oauth_pending')`.

## Server vs Client
Serveur (PHP) pour toute la logique ; le front (page config) n'affiche que l'URL et le champ `code`
(2 actions AJAX). Le navigateur de l'utilisateur fait le login marque hors Jeedom.

## Concurrence (best effort)
Pas de mutex PHP-FPM ; au pire deux refresh redondants sur une courte fenêtre (toléré). Documenté dans
le docblock de `getToken()`.

## Sécurité / logs
- **Jamais** logger `access_token`/`refresh_token`/`client_secret`/`code`/`code_verifier`. Logs :
  « token rafraîchi (expire dans N s) », « ré-auth requise ». Cache Jeedom = stockage interne non exposé.

## Dépendances
Aucune (MVP). `hash('sha256')`, `random_bytes` natifs.
