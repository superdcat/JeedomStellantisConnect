# Spec technique — MVP 03 — OAuth2 PKCE & token

> Référence fonctionnelle : `03-token.md`. Dépend de MVP 02. Contrat confirmé le **2026-07-06** contre
> `psa_car_controller` (`psa/oauth.py` : corps form-urlencoded `{grant_type, code, redirect_uri,
> code_verifier}`, credentials en **Basic** (délégué lib `oauth2_client`, `with_client_credentials=False`),
> PKCE S256, `state` aléatoire, `@rate_limit(6, 1800)` sur le refresh).

## Contrat OAuth2 (consommateur, par marque)
- Base auth : `authBaseUrl = https://idpcvs.{tld}/am/oauth2` (via `stellantis::getApiConfig()`).
- **Authorize** (GET, navigateur utilisateur) : `client_id`, `response_type=code`,
  `scope=openid profile`, `redirect_uri`, `state`, `code_challenge`, `code_challenge_method=S256`.
- **Token** (POST `/access_token`) : header `Authorization: Basic base64(client_id:client_secret)` +
  `Content-Type: application/x-www-form-urlencoded` ; corps échange :
  `grant_type=authorization_code&code&redirect_uri&code_verifier` ; refresh :
  `grant_type=refresh_token&refresh_token`. Réponse : `{access_token, refresh_token, expires_in, id_token}`.
- **Rotation** : le refresh peut retourner un nouveau `refresh_token` (persister ; s'il est absent de la
  réponse, conserver l'ancien).

## Architecture (couche OAuth dans `stellantisApi`, réutilise `httpRequest()` — zéro cURL nouveau)

### Constantes
`TOKEN_CACHE_KEY='stellantis::token'`, `OAUTH_PENDING_KEY='stellantis::oauth_pending'`,
`TOKEN_MARGE=60` (s), `REFRESH_QUOTA_KEY='stellantis::refresh_quota'`, `REFRESH_QUOTA_MAX=6`,
`REFRESH_QUOTA_FENETRE=1800` (s).

### PKCE / setup interactif
- `genCodeVerifier()` privé : 64 chars base64url depuis `random_bytes(48)` (source crypto).
- `genCodeChallenge($verifier)` privé : `rtrim(strtr(base64_encode(hash('sha256',$v,true)),'+/','-_'),'=')`.
- `buildAuthUrl(): string` — exige `stellantis::isConfigured()` (sinon `stellantisException`
  `auth_required`) ; `state = bin2hex(random_bytes(16))` (pas de `uniqid()`) ; stocke
  `utils::encrypt(json{verifier,state})` sous `OAUTH_PENDING_KEY`, **TTL 600 s** ; retourne l'URL.
- `exchangeCode(string $input): void` — **chemin nominal** : l'utilisateur colle l'**URL de redirection
  complète** → `parse_url()`+`parse_str()` extraient `code`+`state`, `state` **toujours vérifié**
  (`hash_equals` ; mismatch → exception ; **URL sans `state` → exception** aussi, une redirection IdP
  en contient toujours un). **Chemin dégradé** : code brut seul (pas d'URL) → accepté avec
  `log::add warning` « state non vérifié (entrée = code seul) » — risque couvert par le PKCE (le code
  n'est échangeable qu'avec le `code_verifier` stocké côté serveur). Relit `OAUTH_PENDING_KEY`
  (absent/expiré → exception « relancez la génération d'URL ») ; POST token ; `storeTokenResponse()` ;
  purge pending.

### Token lifecycle
- `requestToken(array $body): array` privé — POST `authBaseUrl.'/access_token'` via `httpRequest()`,
  Basic + form-urlencoded (`http_build_query`), **flag `$_reponseSensible=true`** (review sécurité
  2026-07-06) : le corps brut d'une réponse OAuth (succès comme erreur) n'est **jamais** loggué ni
  réinjecté dans un message d'exception — seuls `error`/`error_description` sont relayés.
- `storeTokenResponse(array $reponse, ?string $ancienRefresh)` / `readTokenCache(): ?array` privés —
  (nom retenu à la place du `writeTokenCache` initialement speccé : la méthode valide la réponse et
  calcule `exp` en interne). JSON `{access_token, refresh_token, exp}` chiffré `utils::encrypt`,
  `cache::set(..., 0)` (**vérifié dans la source du core 2026-07-06 : lifetime 0 = pas d'expiration** ;
  `byKey()` retourne toujours un objet → `getValue($default)`). `exp = time() + max(120,(int)expires_in)`
  (plancher **> TOKEN_MARGE**, sinon un `expires_in` anormalement bas rendrait chaque token « expiré »
  dès l'écriture et épuiserait le quota ; warning loggué si `expires_in < 120`).
- `getToken(bool $force=false, ?string $failedToken=null): string` —
  1. cache valide (`time() < exp - TOKEN_MARGE`) et `!$force` → rendre sans réseau ;
  2. **anti-storm** (advisor 2026-07-06) : si `$force` et `$failedToken !== null` et que le token en
     cache **diffère** de `$failedToken` → un autre process a déjà rafraîchi → rendre le token du cache
     sans réseau ni décompte de quota ;
  3. sinon `refreshToken()`.
- `refreshToken(): array` privé —
  - pas de `refresh_token` en cache → `stellantisException` `auth_required` ;
  - **quota fenêtre fixe** : `{count, windowStart}` en cache ; si `now - windowStart > 1800` → reset ;
    si `count >= 6` → `stellantisException` `rate_limited` **sans réseau** ;
  - POST refresh ; **rotation** persistée ;
  - **concurrence × rotation (critical advisor 2026-07-06)** : sur `invalid_grant`, relire le cache :
    si le `refresh_token` en cache **diffère** de celui qui vient d'échouer → un process concurrent a
    déjà tourné le token → utiliser le cache (un seul niveau, pas de boucle). `auth_required` seulement
    si le refresh_token en cache est **identique** à celui qui a échoué (réellement mort).
    (Remplace l'ancienne hypothèse « deux refresh redondants tolérés », fausse avec rotation.)
- `callWithToken($method,$path,$params=[]): array` — `getToken()`+`call()` ; catch `token_expired` →
  `getToken(true, $tokenUtilisé)` + **rejeu unique** ; `auth_required` → relance telle quelle.
- `getTokenInfo(): array` — pour l'UI, **non sensible** : `{authenticated: bool, expiresIn: int|null}`.

### Invalidation (garde anti-purge intempestive, advisor 2026-07-06)
`stellantis::preConfig_client_id($value)` / `preConfig_brand($value)` : comparer à
`config::byKey(...)` (encore l'ancienne valeur en preConfig) ; **purger `TOKEN_CACHE_KEY` +
`OAUTH_PENDING_KEY` uniquement si la valeur change réellement** (le formulaire soumet tous les champs à
chaque save — une purge inconditionnelle en postConfig casserait le token à chaque sauvegarde).

## Server vs Client
Serveur pour toute la logique. Front = page config plugin : fieldset « Connexion au compte », bouton
« Générer l'URL » → lien cliquable `target="_blank" rel="noopener"`, champ de collage URL/code, bouton
« Valider », feedback ; `<script>` inline (pas de fichier JS plugin sur cette page), **délégation
d'événements** (`.off().on()` — la page peut être rechargée en AJAX) et IDs préfixés `stellantis_`.
Chaînes JS aussi en `{{...}}`.
⚠️ **Autoload** : dans `core/ajax/stellantis.ajax.php`, un appel `stellantis::` (ex. `isConfigured()`)
doit **précéder** tout usage de `stellantisApi::`/`stellantisException` (sinon `Class not found`).

## Validation
- Serveur = source de vérité : `state` vérifié (URL complète), `code` extrait par `parse_url/parse_str`,
  pending TTL 600 s, erreurs typées.
- Client : champ non vide uniquement.

## Server Actions / API (AJAX, admin-only)
- `action=getAuthUrl` → `['url' => ..., 'redirectUri' => ...]` (redirectUri affiché pour aider l'utilisateur).
- `action=submitAuthCode`, param `code` (URL complète ou code brut) → succès/erreur (messages `__()`).

## Concurrence
Pas de mutex : la détection a posteriori (refresh_token du cache ≠ celui qui a échoué) couvre le cas
réel des crons/pages concurrents avec rotation. Documenté dans le docblock de `refreshToken()`.

## Sécurité / logs
Jamais de log de `access_token`/`refresh_token`/`client_secret`/`code`/`code_verifier`/`state`. Logs :
« token rafraîchi (expire dans N s) », « ré-authentification requise », « state non vérifié (code seul) ».
Tokens au repos chiffrés (`utils::encrypt`) dans le cache Jeedom (interne, non exposé web ; une purge
globale du cache — `cache::flush()` par l'admin — impose une ré-auth : notification à la charge du cron
UC08/09 sur `auth_required`).

## Dépendances
Aucune (`random_bytes`, `hash('sha256')` natifs). `packages.json` reste vide.
