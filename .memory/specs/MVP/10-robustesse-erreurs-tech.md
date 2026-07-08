# Spec technique — MVP 10 — Robustesse & gestion d'erreurs

> Découle de `10-robustesse-erreurs.md`. Fichier unique : `core/class/stellantis.class.php`.
> **Beaucoup du périmètre est déjà en place** (UC03/08/09) : rejeu token borné à 1 (`callWithToken`),
> taxonomie `stellantisException` + `isTokenError()`/`isRateLimited()`, commandes jamais vidées sur
> erreur, `plugin_state=unauthenticated` + alerte page Santé (`connectionState()`/`health()`), cron qui
> s'arrête sur auth cassée sans marteler. UC10 **complète** : backoff 429, mode dégradé lisible, taxonomie.

## Architecture

Aucun nouvel endpoint API ni fichier. Modifs dans `stellantisApi` (backoff 429) et `stellantis`
(cron, connectionState, messageDepuisException, doc exception).

- **`stellantisApi`** :
  - `const RATELIMIT_KEY = 'stellantis::ratelimit_until'` ; `const RATELIMIT_COOLDOWN = 900`
    (15 min ; calibrable en UC72 — saute ~3 passes de cron au défaut 5 min ; posture anti-ban, cf.
    analyse § 1.4).
  - `public static function rateLimitRemaining(): int` — `max(0, (int) cache::byKey(RATELIMIT_KEY)->getValue(0) - time())`.
  - `private static function enterRateLimitCooldown(): void` — `cache::set(RATELIMIT_KEY, time()+RATELIMIT_COOLDOWN, RATELIMIT_COOLDOWN)` + `log::add(... 'warning' ...)`.
  - `httpRequest()` : après `$type = stellantisException::typeFromResponse(...)` et **avant** les
    branches sensible/non-sensible → si `$type == 'rate_limited'`, `enterRateLimitCooldown()`.
    Couvre 429 métier ET OAuth. Le `rate_limited` **synthétique** du quota de refresh
    (`consommerQuotaRefresh()`) est levé **avant le réseau** → ne passe pas par `httpRequest()` → ne
    déclenche PAS ce cooldown (voulu ; deux compteurs distincts).
  - `callWithToken()` : court-circuit en **tête de méthode, AVANT `getToken()`** — si
    `rateLimitRemaining() > 0`, lever `rate_limited` sans réseau. Protège test/sync ET les véhicules
    #2..N d'une même passe cron (la boucle cron avale l'exception par véhicule mais ne re-checke pas le
    cooldown entre itérations) → **protection fonctionnelle, ne pas retirer**.
- **`stellantis`** :
  - `cron()` : saute toute la passe si `stellantisApi::rateLimitRemaining() > 0` (log `info`, **avant**
    `getToken`, zéro réseau). Mode dégradé : sur `getToken()` en échec `auth_required` → log `warning`
    **throttlé 1×/h** (cache `stellantis::degraded_warn`) « dernières valeurs conservées, ré-auth
    requise » ; autres types → `info`. Nettoyage de `degraded_warn` au succès de `getToken()` (à côté du
    `LINK_ERROR_KEY`).
  - `connectionState()` : nouvelle branche `state='error'` si `rateLimitRemaining() > 0` (message avec
    minutes restantes), placée **avant** les checks `link_error`. Commentaire sur la branche
    `link_error=='rate_limited'` : ne sert plus qu'au cas « quota local (`REFRESH_QUOTA`) épuisé »
    (qui ne passe pas par `httpRequest` donc ne pose pas le cooldown).
  - `messageDepuisException()` : `case 'not_configured'` → message « Plugin non configuré… » (réutilisé).
  - `stellantisException` : ajouter `not_configured` à l'énumération de la doc ; `buildAuthUrl()` lève
    `not_configured` au lieu de `auth_required` (livré **atomiquement** avec le case ci-dessus).

## Server vs Client

100 % serveur/PHP. Aucun client, aucun AJAX nouveau. Le cooldown est un état partagé en cache lu par le
cron, `callWithToken` et `connectionState`.

## Validation

- **Critère 1** (token expiré rattrapé) : déjà couvert par le rejeu borné de `callWithToken()` — inchangé.
- **Critère 2** (429 → cooldown respecté) : SET dans `httpRequest` sur tout 429 ; CHECK dans `cron()`
  (passe entière) et `callWithToken()` (chaque appel). Aucun rejeu immédiat sur 429.
- **Critère 3** (auth cassée → mode dégradé lisible sans planter) : cron catch `\Throwable`, log
  `warning` throttlé, valeurs conservées, `connectionState/health` affichent `unauthenticated`.
- **Critère 4** (aucune erreur silencieuse, aucun secret) : aucun `catch` vide (vérifié) ; l'exception
  porte type+code+message tronqué (500 car) ; logs sans token/secret/VIN (conventions).
- Typage cache cohérent avec le fichier : timestamp absolu stocké + TTL = durée ; lecture toujours
  recalculée (`rateLimitRemaining`), robuste à la sémantique d'expiration du cache.

## Server Actions / API

- `stellantisApi::rateLimitRemaining(): int` (public) — secondes restantes de cooldown, 0 si aucun.
- `stellantisApi::enterRateLimitCooldown(): void` (privé) — pose le cooldown + log warning.
- Modifs de signatures : aucune (méthodes existantes enrichies : `httpRequest`, `callWithToken`, `cron`,
  `connectionState`, `messageDepuisException`, `buildAuthUrl`).

## Dépendances

Aucune (100 % PHP, cœur Jeedom).

## Impact i18n (FR — traduction différée étape 10)

Une seule nouvelle clé UI :
- `Trop de requêtes vers l'API : pause de protection, reprise dans ~%d min`

Réutilisées (mêmes clés, pas de duplication) : `Plugin non configuré : renseignez la marque, le Client
ID et le Client Secret puis sauvegardez` (cas `not_configured`), `Trop de requêtes vers l'API :
réessayez plus tard`. Les messages `log::add` ne sont pas i18n dans ce plugin.
