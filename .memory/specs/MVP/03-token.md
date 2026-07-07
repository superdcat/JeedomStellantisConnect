# 03 — Authentification OAuth2 PKCE & gestion du token

**Phase :** MVP · **Dépend de :** 02 · **Fichiers :** `core/class/stellantis.class.php` (`stellantisApi`), `core/ajax/stellantis.ajax.php`, page de config

## Objectif
Mettre en place le flow **OAuth2 Authorization Code + PKCE** (obligatoire depuis 2023, plus de grant
`password`) et la gestion du token : génération de l'URL d'autorisation, échange du `code` collé par
l'utilisateur, **stockage chiffré** des tokens, **refresh** proactif/réactif.

## Périmètre
- **Inclus** : génération URL d'autorisation (PKCE), échange code→tokens, cache chiffré, refresh,
  invalidation sur changement de credentials.
- **Exclu** : remote token OTP/MQTT (post-MVP) ; appels métier (05+).

## Détails techniques
- **Setup interactif** (pas de login silencieux) :
  1. Le plugin génère un `code_verifier` (≥43 chars aléatoires) + `code_challenge = base64url(sha256(verifier))`
     + `state`. Construit l'URL : `https://idpcvs.{marque.tld}/am/oauth2/authorize?client_id=…&
     response_type=code&scope=openid%20profile&redirect_uri=…&state=…&code_challenge=…&code_challenge_method=S256`
     (+ `local`/locale selon marque). Affichée à l'utilisateur (action AJAX `getAuthUrl`).
  2. L'utilisateur se connecte sur le site de sa marque, est redirigé vers `redirect_uri?code={UUID}&…`,
     et **colle le `code`** dans un champ (action AJAX `submitAuthCode`). ⚠️ Le `redirect_uri` est un
     **scheme d'app mobile** (`mymap://…`) que le navigateur **ne sait pas ouvrir** → il affiche une
     **page d'erreur (normal)** ; l'utilisateur copie l'**URL complète** de la barre d'adresse (repli si
     la redirection est absente : onglet **Réseau (F12)**, cf. doc utilisateur). Le `code` est
     **éphémère** (usage unique, expire en quelques instants) → à échanger sans attendre.
  3. Échange : `POST https://idpcvs.{marque.tld}/am/oauth2/access_token`,
     `Authorization: Basic base64(client_id:client_secret)`, body `grant_type=authorization_code&code=…&
     redirect_uri=…&code_verifier=…`. Réponse `{access_token, refresh_token, expires_in, id_token}`.
- **Cache chiffré** (`cache::set('stellantis::token', …)`, valeurs chiffrées) :
  `{access_token, refresh_token, exp}` où `exp = time() + expires_in`.
- **`getToken(bool $force=false): string`** : token caché valide (`time() < exp - MARGE`) → le rendre
  (aucun réseau) ; sinon **refresh** (`grant_type=refresh_token`) + MAJ cache. ⚠️ `expires_in` court
  (**~15 min à ~1 h** selon source/IdP — à mesurer) → refresh fréquent ; `refresh_token` ~30 j **avec
  rotation** (persister le nouveau). ⚠️ **≤ 6 refresh / 30 min** (`@rate_limit(6,1800)` psa_cc, cf. UC72).
  `redirect_uri` propre à chaque marque (corrigé 2026-07-06 contre `constants.py`/`realm_info` de
  psa_car_controller) : `mymap`(Peugeot)/`mymacsdk`(Citroën)/`mymdssdk`(DS)/`mymopsdk`(Opel)/
  `mymvxsdk`(Vauxhall)`://oauth2redirect/{pays}` — table `stellantis::BRANDS` (UC01).
- **`callWithToken($method,$path,$params)`** : `getToken()` + `call()` ; sur `401`/token error →
  `getToken(true)` (refresh) puis **rejeu unique** ; sur `invalid_grant` (refresh token mort) → erreur
  claire « ré-authentification requise » (pas de boucle).
- `code_verifier`/`state` stockés temporairement (cache court) entre étapes 1 et 2.
- Invalidation : `postConfig_client_id`/`postConfig_brand` → purge `stellantis::token`.

## Critères d'acceptation
- [ ] L'utilisateur obtient une URL d'autorisation valide et, après collage du `code`, le plugin stocke
      `access_token` + `refresh_token` (chiffrés).
- [ ] `getToken()` rend un token valide sans appel réseau tant qu'il n'est pas proche de l'expiration.
- [ ] Un `access_token` expiré est rafraîchi automatiquement ; rejeu réactif borné à **1**.
- [ ] Un `refresh_token` mort (`invalid_grant`) remonte « ré-auth requise », sans boucle.
- [ ] Aucun token ni `client_secret` en clair dans les logs / le DOM.

## Notes / risques
- Contrat exact (PKCE, noms de params, `redirect_uri` par marque, header Basic) à **confirmer** contre
  `psa_car_controller` (`psa_client.py`, PR #754) — cf. `stellantis-api-architecture.md` § 1.1.
- **Durcissement OAuth PSA — le collage manuel est le chemin canonique** (corroboré 2026-07-06, cf.
  `stellantis-psacc-vs-natif.md` § 6 + discussion `flobz/psa_car_controller` #779) : PSA a durci le
  flux d'autorisation ; `psa_car_controller` a dû ajouter un login navigateur automatisé (Playwright).
  Sans navigateur embarqué, la récupération manuelle du `code` (URL affichée → l'utilisateur colle
  l'URL de redirection) est **la procédure de secours documentée** — c'est exactement notre design, à
  **conserver** et à **documenter finement** côté utilisateur (page d'erreur attendue, repli F12/Réseau).
- **`code` éphémère → `invalid_grant`** : un code expiré / déjà utilisé / partiellement copié fait
  échouer l'échange en `400 invalid_grant`. `exchangeCode()` **traduit** ce cas en message actionnable
  (« régénérez l'URL et recommencez rapidement ») au lieu de « HTTP 400 » (code 2026-07-06) ; le
  `pending` (verifier/state, TTL 10 min) est conservé pour un nouvel essai.
- Voir le compagnon `03-token-tech.md`.
