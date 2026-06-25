# 10 — Robustesse & gestion d'erreurs (socle MVP)

**Phase :** MVP · **Dépend de :** 07 · **Fichiers :** `core/class/stellantis.class.php` (`stellantisApi`, `stellantisException`)

## Objectif
Rendre le socle lecture **résilient** : un token expiré, un `invalid_grant`, un rate-limit ou un backend
Stellantis capricieux ne doivent ni planter Jeedom, ni marteler l'API, ni laisser l'utilisateur sans
information. Pose les **fondations** des guardrails approfondis en post-MVP (UC72/73/74).

## Périmètre
- **Inclus** : taxonomie d'erreurs (`stellantisException`), rejeu borné sur token, **backoff minimal**
  sur `429`, mode **dégradé** (lecture seule conservée, alerte claire), logs non sensibles.
- **Exclu** : régulation adaptative de cadence (→ UC72), stats d'appels (→ UC77), alertes token avancées
  (→ UC74).

## Détails techniques
- `stellantisException` porte un **type** : `not_configured`, `auth_required` (`invalid_grant`),
  `token_expired` (401), `rate_limited` (429), `privacy`, `api_error`, `transport`. Helper
  `isTokenError()` / `isRateLimited()`.
- **Rejeu token borné à 1** (déjà en 03) : 401 → refresh → 1 rejeu, sinon `auth_required`.
- **Backoff `429`** : sur rate-limit, **ne pas réessayer immédiatement** ; poser un **cooldown** en cache
  (`stellantis::ratelimit_until`) respecté par les appels suivants (les passes de cron sautent tant que le
  cooldown court). Anti-ban (cf. analyse § 1.4 : un ban se déclenche si on insiste).
- **Mode dégradé** : si l'auth est cassée (`auth_required`) → ne **pas** vider les commandes (garder les
  dernières valeurs), passer `plugin_state=unauthenticated`, alerter (log `warning` + page Santé), et
  **arrêter les appels** jusqu'à ré-auth (évite de brûler des tentatives).
- Logs : chaque erreur API loggée avec **type + code HTTP + message tronqué**, **jamais** de token/secret ;
  **aucun `catch` vide**.

## Critères d'acceptation
- [ ] Un `access_token` expiré est rattrapé (refresh + 1 rejeu) sans intervention.
- [ ] Un `429` déclenche un cooldown respecté par les passes suivantes (pas de martèlement → pas de ban).
- [ ] Une auth cassée bascule en mode dégradé lisible (ancien état conservé, alerte), sans planter le cron.
- [ ] Aucune erreur n'est silencieuse ; aucun secret/token dans les logs.

## Notes / risques
- Les **valeurs de cooldown/backoff** sont à calibrer (cf. analyse : guardrails wakeup 5 min charge /
  60 min veille — ici pour le polling REST, plus souple, mais respecter `429`).
- C'est le socle ; la régulation fine et les stats arrivent en `70-supervision-robustesse`.
