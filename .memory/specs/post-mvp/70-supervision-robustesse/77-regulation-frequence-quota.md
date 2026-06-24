# UC — Régulation automatique de la fréquence de rafraîchissement selon le budget quota

**Domaine :** Supervision / robustesse · **Dépend de :** UC74 (compteurs + config quota), UC10 (cron de polling) · **Complémentaire de :** UC73 (sync sélective — réduit le coût/cycle), UC71 (skip-offline — réduit le coût/cycle) · **Statut :** à spécifier (tech)

## Objectif / valeur
Empêcher le **polling automatique** (cron) de **griller le quota mensuel** : le plugin **calcule
automatiquement** la cadence de rafraîchissement pour que la consommation des refresh auto reste
**≤ `quotaRefreshPct` × `quotaMensuel`** sur le mois, **en fonction du nombre de caméras pollées** et du
**nombre d'appels par cycle**.

> **Exemple** : `quotaMensuel` 30 000 × `quotaRefreshPct` 70 % = **21 000 appels/mois** pour le refresh.
> 11 caméras × ~4 appels/cycle ≈ 44 appels/cycle → **21 000 / 44 ≈ 477 cycles/mois ≈ 1 cycle / ~91 min**.
> La cadence calculée (~90 min) remplace le 5 min fixe qui épuiserait le quota en quelques jours.

C'est la **valeur principale** attendue par l'utilisateur ; UC74 n'en est que la fondation (mesure +
config).

## Périmètre
- **Inclus** :
  - **Calcul de l'intervalle de refresh cible** :
    - `budgetRefresh = quotaMensuel × quotaRefreshPct` ;
    - `coûtCycle` = somme, sur les caméras en **cadence auto**, du nb d'appels/cycle de chacune
      (`deviceOnline` + `getDeviceCameraStatus` des switches pollés **non exclus UC73** + NVM éventuel +
      batches IoT) — **même logique de sélection** que `refreshStates()` ;
    - `cyclesParPériode = budgetRefresh / coûtCycle` ; `intervalle = duréeDeLaPériode / cyclesParPériode`,
      où la **période** est la fenêtre mensuelle **anniversaire** d'UC74 (jour de reset `quotaResetDay`),
      pas le mois calendaire.
  - **Application** de la cadence au **polling cron** (UC10) : la cadence effective est **dérivée** au lieu
    d'un `cron5` fixe (globale, ou par caméra au prorata de son coût).
  - **Garde-fou par rétroaction** (feedback) : si la **conso mensuelle MESURÉE** (UC74) **dépasse le rythme
    budgété** (au prorata du mois écoulé), **espacer davantage** (throttle) jusqu'au reset ; si on est en
    avance sur le budget, possibilité d'**accélérer** dans la limite d'un **plancher**. Couvre les erreurs
    d'estimation du `coûtCycle`.
  - **Transparence** : cadence calculée + budget restant **affichés en lecture seule** (config / Santé).
- **Exclu** :
  - Le **comptage** et la **config quota** (UC74).
  - Le **réglage manuel par caméra** (`autorefresh`, UC10) : il reste **prioritaire** (override) ; la
    régulation ne s'applique qu'aux caméras laissées en **cadence par défaut**.
  - La **data du flux live** (≈ 3 Go/mois) — hors scope (cf. UC74).

## Esquisse Jeedom — calcul ÉVÉNEMENTIEL + recalibrage NOCTURNE (pas de recalcul par appel)
- **Intervalle STOCKÉ** : la cadence est calculée puis **persistée** (config plugin `refreshIntervalSec`).
  `imou::cron()` ne fait que **lire** cette valeur et l'appliquer — **aucun recalcul sur le chemin chaud**.
- **Estimation du coût/cycle** : `imou::estimateCallsPerCycle($eqLogic)` compte **hors-ligne** le nombre de
  données à collecter par caméra (≈ `deviceOnline` + commandes info pollées : switches `commandCatalog`
  applicables, NVM, lots IoT), en tenant compte des **exclusions UC73**. **Conservateur** (sur-estimer =
  intervalle plus long = sûr). `coûtCycle` = somme sur les caméras **activées en cadence auto**.
- **Recalcul (événementiel)** `imou::computeAndStoreRefreshInterval()` déclenché à :
  - une modif de config quota (`postConfig_quotaMensuel`/`quotaRefreshPct`/`refreshIntervalMin`/`Max`) ;
  - le cycle de vie d'une caméra : **ajout / enregistrement / activation / désactivation** (`postSave`) et
    **suppression** (`postRemove`).
  Formule (budget RESTANT sur la période anniversaire) :
  `budgetRefresh = quotaMensuel × quotaRefreshPct/100` ;
  `restant = max(0, budgetRefresh − consoPériodeÀDate)` (conso totale UC74 en proxy, conservateur) ;
  `tempsRestant = finPériode − maintenant` ;
  `intervalle = tempsRestant / (restant / coûtCycle)`, **borné [plancher, plafond]**.
- **Recalibrage NOCTURNE** : `imou::cronDaily()` rappelle `computeAndStoreRefreshInterval()` → réajuste
  l'intervalle selon la **conso réelle** du jour (si on consomme trop vite, `restant` fond → intervalle
  s'allonge ; sinon il se resserre vers le plancher). Dérive bornée à ~1 jour entre deux recalibrages.
- **Application** : `imou::cron()` (chaque minute), pour chaque caméra en cadence auto (sans `autorefresh`),
  refresh si `maintenant − dernierRefresh ≥ intervalleStocké` ; `dernierRefresh` en **cache**
  (`imou::lastrefresh::<id>`, pas de `save()`). **Plafond `MAX_REFRESH_PER_TICK`** par tick (anti-rafale au
  démarrage / après recalcul → étalement durable). `cron5()` devient un **filet** basse-cadence
  (rattrapage des caméras *stale* au-delà de 2×plafond si `cron()` a défailli), pas un double-poll.

## Critères d'acceptation
- [ ] La cadence de refresh auto est **calculée** pour ne pas dépasser `quotaRefreshPct × quotaMensuel`
      sur le mois, **en fonction du nombre de caméras**.
- [ ] Modifier `quotaMensuel`, `quotaRefreshPct` **ou** le nombre de caméras **recalcule** la cadence.
- [ ] Un **garde-fou** (feedback sur la conso mesurée UC74) empêche le dépassement même si l'estimation
      initiale du coût/cycle est imparfaite.
- [ ] **Plancher/plafond** respectés ; l'**override manuel** (`autorefresh`) reste prioritaire.
- [ ] La cadence calculée et le budget restant sont **visibles** par l'utilisateur.

## Décisions V1 (tranchées)
- **Cadence GLOBALE** (un intervalle pour toutes les caméras en cadence auto).
- **Coût/cycle STATIQUE conservateur** (compte des données à collecter) ; le **recalibrage nocturne** sur
  la **conso réelle** corrige la dérive (pas de mesure par appel → léger).
- **Calcul ÉVÉNEMENTIEL** (config quota + cycle de vie caméra) **+ nocturne**, jamais par hit.
- **Quasi-épuisement** → intervalle = **plafond** (pas de gel dur ; un minimum de refresh subsiste).
- **Application** via **timestamp `dernierRefresh`/caméra** en cache, dans `cron()` minute, avec cap
  `MAX_REFRESH_PER_TICK`.
- **Fenêtre mensuelle** = celle d'UC74 (**anniversaire**, jour `quotaResetDay`).
- **Proxy conso** : on régule le refresh pour tenir dans `budgetRefresh` ; la conso **totale** UC74 sert de
  proxy (conservateur : les actions ponctuelles rognent un peu le budget refresh, la marge
  `quota − budgetRefresh` les absorbe).
