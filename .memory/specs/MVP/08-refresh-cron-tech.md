# Spec technique — MVP 08 — Rafraîchissement périodique (cron)

> Référence fonctionnelle : `08-refresh-cron.md`. Dépend de MVP 07 (`refreshTelemetry()`). Plan validé le
> **2026-07-07** (advisor `code-reviewer` + vérif source core Jeedom).

## Écart assumé vs spec fonctionnelle
La spec disait « implémenter `cron5()` ». **On implémente `cron()` (chaque minute)** : `cron5` ne se
déclenche qu'aux minutes multiples de 5, or `CronExpression::isDue()` teste une correspondance **exacte**
à la minute → une expression `autorefresh` non alignée (ex. `*/7`, `3,33 * * * *`), pourtant valide et
acceptée par l'assistant cron, ne serait **jamais** due → véhicule jamais rafraîchi, silencieusement.
`cron()` visite chaque minute → `isDue()` matche toute expression valide. Cadence par défaut (véhicule
sans `autorefresh`) obtenue via l'expression `*/5 * * * *` (= 5 min, intention de la spec).

## Contrat API
Aucun nouvel appel : réutilise `refreshTelemetry()` (UC07 : `GET /status` + `/lastPosition` via
`stellantisApi::callWithToken`).

## Signatures core Jeedom (vérifiées contre `jeedom/core`, 2026-07-07)
- `eqLogic::byType($_eqType_name, $_onlyEnable = false)` → `byType('stellantis', true)` = activés seulement.
- Lib cron bundlée : `new Cron\CronExpression(checkAndFixCron($expr), new Cron\FieldFactory)` puis
  `->isDue()`. Autoloadée via `core.inc.php` ; `checkAndFixCron()` = fonction globale du core.
- `stellantisApi::getToken()` **public** (retourne l'access token, refresh si besoin, lève
  `stellantisException` si non authentifié).

## Architecture — `core/class/stellantis.class.php` uniquement, hook `stellantis::cron()`
Décommenter/implémenter `public static function cron()`. Aucun AJAX/JS/config nouvelle (`autorefresh`
existe déjà dans le formulaire eqLogic).

### Logique
1. `$vehicules = eqLogic::byType('stellantis', true)`. Vide → return.
2. **Prime token 1×/passe** : `stellantisApi::getToken()` dans `try/catch (\Throwable)`. Échec →
   `log::add('stellantis','info', …)` + **return**. Raison réelle : sans ce pré-appel, N véhicules
   retenteraient chacun `refreshToken()` en cas de panne OAuth et **épuiseraient le quota anti-ban**
   (`REFRESH_QUOTA_MAX`) en une passe (les derniers récolteraient `rate_limited`). Sur le chemin heureux,
   le cache token mutualise déjà naturellement entre véhicules.
3. `foreach` véhicule :
   - `$expr = autorefresh` si `configuration('autorefresh')` non vide, sinon `'*/5 * * * *'` (constante
     `CRON_DEFAUT`).
   - `try { $cron = new Cron\CronExpression(checkAndFixCron($expr), new Cron\FieldFactory()); $due =
     $cron->isDue(); } catch (\Throwable) { … skip + warning throttlé ; continue; }`.
   - `if (!$due) continue;`.
   - `try { $eqLogic->refreshTelemetry(); } catch (\Throwable $e) { log warning (getId(), jamais le VIN) }`.
4. **Pas de wakeup** (REST seul, dernier état remonté — limite MVP assumée).

### Décisions
- **Expression invalide → skip** le véhicule (pas de repli « rafraîchir par défaut » : le repli serait la
  cadence la plus agressive, choisie silencieusement — contraire à l'anti-ban si typo). Warning
  **throttlé 1×/h/véhicule** via `cache` (`stellantis::cron_warn::<id>`, TTL 3600) sinon 1440 logs/jour.
- **`catch (\Throwable)`** partout (pas seulement `stellantisException`) : une erreur PHP imprévue
  (parsing, cast) sur un véhicule ne doit pas casser toute la boucle (robustesse CLAUDE.md).
- Logs : `getId()`/`getHumanName()` — **jamais** `getLogicalId()` (= VIN).

## Server vs Client
100 % serveur (hook cron). Aucune UI.

## Validation
- Robustesse : token primé + garde d'auth en tête ; `try/catch (\Throwable)` par véhicule ; expression
  cron invalide non fatale (skip + warning throttlé).
- Cadence : défaut 5 min (`*/5`) ; `autorefresh` honoré à la minute (toute expression valide finit par
  matcher). Pas de martèlement API (appel seulement si `isDue`). Pas de wakeup.
- ⚠️ `cron()` s'exécute chaque minute mais ne fait qu'un `byType` + parsing `isDue` si rien n'est dû :
  coût négligeable au MVP. À surveiller au-delà de quelques véhicules (UC77).
- **Reporté post-MVP (finding sécu low, review 2026-07-08)** : aucun **plancher de cadence** n'encadre
  `autorefresh` — un admin pourrait saisir `* * * * *` → 1-2 appels `/status`+`/lastPosition` par minute
  et par véhicule (plafonné à la granularité de `cron()`). Risque anti-ban modéré sur une flotte mal
  configurée. À traiter en supervision post-MVP (`70-supervision-robustesse`) : plancher technique
  (refus/arrondi d'un intervalle < N min) et/ou recommandation de cadence min dans l'aide du champ.

## Server Actions / API
```php
stellantis::cron(): void   // hook Jeedom, boucle refreshTelemetry() sur les véhicules dus
```

## Dépendances
Aucune (lib cron déjà bundlée par le core).
```
