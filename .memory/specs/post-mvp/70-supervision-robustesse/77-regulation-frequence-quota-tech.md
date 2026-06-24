# Spec technique — UC77 Régulation auto de la fréquence de rafraîchissement

> Modèle **événementiel + nocturne** : la cadence est **calculée puis stockée**, jamais recalculée sur le
> chemin chaud. Consomme la conso/quota d'UC74. **Aucun appel cloud.**

## Architecture
Fichier unique : **`core/class/imou.class.php`** (classe `imou`) + **`plugin_info/configuration.txt`** (→ `.php`).
Pas de modif `imouApi` (on réutilise `imouApi::readPeriodStats` d'UC74). Pas de paquet.

Méthodes ajoutées (classe `imou`) :
- `estimateCallsPerCycle($eqLogic)` *(instance)* → int : nb de données à collecter/cycle pour CETTE caméra
  (`1` deviceOnline + switches `commandCatalog` pollés & applicables & non exclus UC73 + NVM + lots IoT).
  Conservateur (sur-estime ⇒ intervalle plus long ⇒ sûr). Aucun appel cloud (lecture commandes + config).
- `quotaPeriodEndTs($resetDay, $ts=null)` *(pure)* → timestamp de **fin** de période anniversaire (miroir
  strict de `quotaPeriodStart` : début de la période suivante, clamp mois courts, déc→jan).
- `computeAndStoreRefreshInterval()` *(static)* → int secondes : calcule l'intervalle global et le **persiste**
  (`config::save('refreshIntervalSec', …, 'imou')` + `refreshIntervalAt = time()`). Ne lève jamais.
- `getRegulationInfo()` *(static)* → agrégat UI (intervalle, ≈ humain, budget, conso, restant, nb caméras,
  coûtCycle, période, recalculé le, actif).
- Hooks : `postConfig_quotaMensuel/quotaRefreshPct/refreshIntervalMin/refreshIntervalMax` → recompute ;
  `postRemove()` → recompute ; `postSave()` (existant) → ajoute recompute après `createCommands()`.
- `cronDaily()` *(nouveau)* → recompute (recalibrage nocturne sur conso réelle).
- `cron()` *(modifié)* / `cron5()` *(filet)* — cf. ci-dessous.

## Server vs Client
100 % **serveur**. Calcul dans les hooks/cron PHP, valeur stockée en config, appliquée au cron. Affichage
rendu serveur (config page + Santé). Aucun JS.

## Server Actions / API (logique)
- **Calcul** `computeAndStoreRefreshInterval()` :
  - `quota,refreshPct,min,max` (config, clampés). Si `quota<=0` → stocke `DEFAULT_REFRESH_INTERVAL` (300 s),
    `actif=false` (repli cadence fixe historique).
  - `coûtCycle = Σ estimateCallsPerCycle()` sur `eqLogic::byType('imou', true)` **sans** `autorefresh`.
    Si `coûtCycle<=0` (aucune caméra régulée) → stocke `max` (rien à poller).
  - `budgetRefresh = floor(quota × refreshPct/100)`.
  - `restant = max(0, budgetRefresh − consoPériode)` où `consoPériode = imouApi::readPeriodStats(quotaPeriodStart())['total']` (proxy conservateur).
  - `tempsRestant = max(60, quotaPeriodEndTs() − now)`.
  - `roundsRestants = restant / coûtCycle` ; `interval = roundsRestants>0 ? tempsRestant/roundsRestants : max`.
  - **clamp** `[min×60, max×60]`, cast int, persiste. Retourne la valeur.
- **Application** `cron()` (chaque minute) :
  - lit `interval = (int) config::byKey('refreshIntervalSec','imou', DEFAULT)`.
  - caméra **avec** `autorefresh` → branche CronExpression existante (inchangée).
  - caméra **sans** → si `now − lastRefreshTs ≥ interval` : `refreshStates()` + `cache::set('imou::lastrefresh::'.$id, now, …)`. **Cap `MAX_REFRESH_PER_TICK` (10)** sur ces refresh/tick (anti-rafale → étalement durable car les `lastRefreshTs` divergent).
- **Filet** `cron5()` : pour les caméras sans `autorefresh`, refresh **uniquement** si `now − lastRefreshTs > 2 × (max×60)` (rattrapage si `cron()` a défailli) — pas de double-poll en régime normal.
- **Recalibrage** `cronDaily()` → `computeAndStoreRefreshInterval()`.

## Validation
- **Serveur** : clamps config (quota≥0, pct/jours/min/max bornés) ; `interval` borné `[min,max]` ;
  `tempsRestant≥60` ; divisions gardées (`coûtCycle>0`, `roundsRestants>0`) ; hooks ne lèvent jamais
  (try/catch Throwable autour du recompute, l'instrumentation ne doit pas casser un save/cron).
- **Client** : champs `number min/max/step` pour `refreshIntervalMin/Max` (confort).

## Constantes (classe imou)
`DEFAULT_REFRESH_INTERVAL = 300` (repli/quota inconnu) ; `DEFAULT_CALLS_PER_CYCLE` (repli si une caméra n'a
pas encore de commandes, ex. 4) ; `MAX_REFRESH_PER_TICK = 10`.

## Config (niveau plugin, 'imou')
| Clé | Défaut | Rôle |
|---|---|---|
| `refreshIntervalMin` | 5 | Plancher (minutes). |
| `refreshIntervalMax` | 360 | Plafond (minutes, = 6 h). |
| `refreshIntervalSec` | (calculé) | Intervalle effectif stocké (secondes) — **non éditable** (résultat). |
| `refreshIntervalAt` | (calculé) | Timestamp du dernier calcul (affichage). |

## Dépendances
Aucune. PHP natif + core `config`/`cache`/`log` + `imouApi::readPeriodStats` (UC74).

## Hors scope
Comptage/quota (UC74) ; data flux live (3 Go) ; cadence par-caméra (V1 = globale).
