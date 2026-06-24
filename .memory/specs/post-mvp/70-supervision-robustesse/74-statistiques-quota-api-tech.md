# Spec technique — UC74 Statistiques d'utilisation & quota d'appels API

> Périmètre = **fondation** (mesure + config + alerte + restitution). La **régulation** de la cadence
> est UC77 (consomme cette donnée). 3 Go flux/mois = hors scope.

## Architecture

Fichiers touchés :
- **`core/class/imou.class.php`** :
  - `imouApi` : instrumentation (`recordCall`), lecture (`readPeriodStats`/`readDayStats`), config en
    cache statique (`quotaConfig`), constantes.
  - `imou` : calcul de période (`quotaPeriodStart`/`currentQuotaPeriodStart`), agrégat UI (`getApiStats`),
    lignes `health()`.
- **`plugin_info/configuration.txt`** (→ recopie `configuration.php`) : fieldset config quota + fieldset
  statistiques (lecture seule).
- **i18n** : chaînes FR enveloppées (`{{...}}` / `__()`), traduction déléguée (étape 10).

Aucun nouvel appel cloud. Aucune modif `install.php` (défauts via `config::byKey(..., défaut)`).

## Server vs Client
100 % **serveur** (PHP). Le comptage est dans la brique `imouApi` (cron + actions). La restitution est
rendue côté serveur dans `configuration.php` et `health()`. Pas de JS dédié (les champs config
s'auto-sauvent via le `configKey` du core).

## Modèle de données (cache Jeedom)
- **Période** : clé `imou::stats::period::<AAAA-MM-JJ début>` → `{ total:int, byMethod:{m:int}, alerted:bool }`.
  TTL `STATS_PERIOD_TTL` (~70 j). Autoritatif pour le quota.
- **Jour** : clé `imou::stats::day::<AAAA-MM-JJ>` → `{ total:int, byMethod:{m:int} }`. TTL `STATS_DAY_TTL` (~8 j).
- Préfixes disjoints. **Best-effort** : RMW non atomique (incrément perdu rare) + purge manuelle du cache
  remet à zéro — assumé/documenté (supervision, pas facturation).

## Config (niveau plugin, 'imou')
| Clé | Défaut | Domaine | Rôle |
|---|---|---|---|
| `quotaMensuel` | 30000 | ≥0 | Budget mensuel total d'appels du compte. 0 = quota inconnu (pas de %/alerte). |
| `quotaResetDay` | 1 | 1–31 | Jour anniversaire de reset (lu dans `Register Time` du compte). Clampé au dernier jour des mois courts. |
| `quotaRefreshPct` | 70 | 0–100 | Part du quota réservée au refresh auto (consommée par UC77). |
| `seuilAlertePct` | 90 | 0–100 | Seuil d'alerte en % du quota. 0 = alerte désactivée. |

Lecture en **cache statique PHP** (`imouApi::quotaConfig()`), 1 lecture DB/process. Un changement admin
prend effet au process suivant (acceptable).

## Server Actions / API (signatures)
- `imouApi::recordCall($method)` *(privée)* — incrémente jour + période, gère l'alerte (débounce
  `alerted` 1×/période). **`try/catch Throwable`, ne lève jamais.** Appelée dans `callOnce()` **après**
  le guard `errno !== 0` (compte toute requête ayant reçu une réponse ; rejeux UC72 inclus).
- `imouApi::quotaConfig()` *(privée, cache statique)* → `['quota'=>int,'resetDay'=>int,'refreshPct'=>int,'seuilPct'=>int]`.
- `imouApi::readPeriodStats($periodStart)` / `readDayStats($date)` *(publiques)* → `['total'=>int,'byMethod'=>array]`.
  Guard **regex calendaire ancrée** `/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])$/` sur la date.
- `imou::quotaPeriodStart($resetDay, $ts=null)` *(pure)* → `'AAAA-MM-JJ'` début de période (clamp mois courts).
- `imou::currentQuotaPeriodStart()` → lit `quotaResetDay`, délègue à `quotaPeriodStart(now)`.
- `imou::getApiStats()` → agrégat UI : `periodStart, periodTotal, periodByMethod(desc), quota, quotaPct|null,
  refreshPct, seuilPct, today{date,total,byMethod}, days[7]{date,total}`. Une boucle, ≤8 lectures cache.
- `imou::health()` : 2 lignes ajoutées — « Appels API (période en cours) » (`N / quota (X %)`,
  state=`quotaPct < seuilPct` ; si quota=0 → `N appel(s)`, state=true) et « Appels API (aujourd'hui) ».

## Validation
- **Serveur** : domaines config clampés à la lecture (`quotaMensuel≥0`, `resetDay∈[1,31]`, pct∈[0,100]) ;
  `$method` revalidé (`recordCall` reçoit déjà un method validé par `call()`, repli `'inconnu'`) ; date des
  readers validée par regex calendaire ; tous les accès cache/JSON défensifs (`is_array`, `intval`).
- **Client** : champs `number` `min/max/step` dans le formulaire (confort, non fiable seul).

## Constantes (imouApi)
`STATS_PERIOD_PREFIX='imou::stats::period::'`, `STATS_DAY_PREFIX='imou::stats::day::'`,
`STATS_PERIOD_TTL=70*86400`, `STATS_DAY_TTL=8*86400`.

## Dépendances
Aucune (PHP natif + classes core `cache`/`config`/`log`). Pas de paquet.

## Hors scope (UC77)
Calcul/forçage de la cadence de refresh, rétroaction sur la conso mesurée, affichage cadence calculée.
`quotaRefreshPct` est **stocké** ici mais **non consommé** tant qu'UC77 n'est pas livrée.
