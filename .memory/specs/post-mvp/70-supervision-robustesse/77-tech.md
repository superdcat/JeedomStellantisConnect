# Spec technique — 77 · Statistiques d'appels API

> Domaine : supervision / robustesse. **100 % local** : aucun appel réseau/MQTT neuf — on ne fait
> qu'instrumenter (compter) et restituer les appels REST déjà émis via `stellantisApi`. Alimente UC71
> (Santé) et UC72 (anti-ban). Reviews advisor : socle validé (point d'instrumentation, normalisation,
> REST-only) ; 2 points majeurs intégrés (cloisonnement par slot, seuil de dérive documenté).

## Architecture

### Composants / fichiers
- **`core/class/stellantis.class.php`** (seul fichier de code touché) :
  - **`stellantisApi`** (couche HTTP — point unique) : nouvelles constantes + `normaliserEndpoint()` +
    `compterAppel()` ; **1 appel** à `compterAppel()` inséré dans `httpRequest()`. Commentaire ajouté
    dans `downloadToFile()` (exclusion assumée).
  - **`stellantis`** (couche métier/UI) : `recapStatistiquesApi()` (lecture cache, agrégat par slot) +
    1 ligne dans `health()`.
- **`desktop/php/stellantis.php`** (page plugin, admin-only) : bloc « Consommation de l'API REST ».

### Point d'instrumentation (AC1 + AC3)
Tous les appels REST convergent vers `stellantisApi::httpRequest()` :
`call()` (télémétrie), `requestToken()` (OAuth2), `requestSmsOtp()`/`requestRemoteToken()`/
`refreshRemoteToken()` (OTP = **face REST des commandes**). L'instrumentation se place **juste après la
garde d'échec transport** :

```
$body = curl_exec($ch); ... $httpCode = ...; curl_close($ch);
if ($body === false || $curlErrno !== 0) { ...throw 'transport'... }   // ← AUCUN comptage : pas de réponse serveur
self::compterAppel($_url, $_slot);                                      // ← ICI : réponse serveur reçue (2xx OU 4xx/5xx)
log::add(... '→ HTTP ' . $httpCode);
```

- ✅ Compte **uniquement après réponse serveur** (spec) : un échec transport (cURL) lève **avant** et
  n'est jamais compté.
- ✅ Compte **2xx ET erreurs HTTP** (429/401/404) : une erreur serveur est un appel réel et un signal
  anti-ban pertinent (le martèlement produit aussi des 429).
- ✅ **Point unique** : aucun second point d'instrumentation. Le `rate_limited` **synthétique** du quota
  de refresh est levé **avant le réseau** (dans `callWithToken`/`consommerQuotaRefresh`) → jamais compté,
  conforme à la règle « après réponse serveur ».

### Normalisation d'endpoint
`parse_url($_url, PHP_URL_PATH)` (retire host + query, dont le `client_id`) puis un **seul** remplacement
ciblé du seul segment variable de l'API :

```php
$path = preg_replace('#/user/vehicles/[^/]+#', '/user/vehicles/{id}', $path);
```

Labels résultants (jeu réel, ~9) : `/connectedcar/v4/user`, `/connectedcar/v4/user/vehicles`,
`/connectedcar/v4/user/vehicles/{id}/status` (+ `lastPosition`/`maintenance`/`alerts`),
`/am/oauth2/access_token`, `/applications/cvs/v4/mobile/smsCode` et `/token`.
Pas de whitelist fragile (ne peut pas mislabeler `v4`/`cvs`/`access_token`) ; un futur endpoint à
segment variable non prévu **dégrade proprement** (groupé par id brut, jamais faux). Path vide → label
`(inconnu)`.

### Modèle de données (cache)
Clé **cloisonnée par compte** (UC54, `cacheKeyForSlot`) — un 429/dérive d'un compte n'est jamais imputé
à un autre, et on sait **quel** compte consomme/dérive (objectif anti-ban) :

- `stellantisApi::cacheKeyForSlot('stellantis::stats::day::AAAA-MM-JJ', $slot)` →
  `{ "total": int, "byEndpoint": { "<label>": int } }`, **TTL 691200 s (~8 j)** → auto-purge (pas
  d'orphelin persistant si un compte secondaire est déconfiguré, contrairement aux flags `message` ;
  limite structurelle multi-comptes ainsi neutralisée par la simple expiration).
- `stellantisApi::cacheKeyForSlot('stellantis::stats::min::AAAA-MM-JJ HH:MM', $slot)` → `int`,
  **TTL 120 s** : fenêtre glissante de dérive (détection de boucle anormale), par compte.

RMW **non-atomique** assumé (même pattern que `consommerQuotaRefresh`/`consommerQuotaWakeup`) : sous
forte concurrence (cron + « Synchroniser » manuel), léger **sous-comptage** possible. Acceptable :
compteur **observationnel** (pas une garde de sécurité) ; le sens (ordre de grandeur, tendance) est
préservé.

## Server vs Client
100 % **serveur** (PHP). Le comptage est côté transport HTTP (`httpRequest`). La restitution est rendue
**serveur au chargement** de la page (snapshot, comme le bandeau `connectionState()`), aucun JS/AJAX neuf.

## Validation
- **Non bloquant (AC3)** : `compterAppel()` est **intégralement** dans `try/catch \Throwable` — jamais
  d'exception vers l'appel métier, quelle que soit la panne (cache DB indisponible, JSON corrompu…).
- **Fiabilité (AC1)** : placement après la garde transport ⇒ « uniquement après réponse serveur »
  garanti par construction ; point unique ⇒ exhaustivité REST.
- **Cap défensif** `STATS_MAX_ENDPOINTS = 50` : si `byEndpoint` atteint 50 clés distinctes et que le
  label est nouveau, il est agrégé dans un bucket `(autres)` (le `total` reste exact). Défense en
  profondeur (cf. `ALERT_MAX_TYPES` UC43) contre une prolifération anormale ; le jeu réel étant ~9, ce
  cap ne se déclenche jamais en fonctionnement normal.
- **Fuseau** : `date('Y-m-d')`/`date('Y-m-d H:i')` suivent le fuseau serveur PHP, cohérent écriture ↔
  lecture (même invariant que la gate de phase UC72). Rollover de minuit = frontière de journée
  observable ; sans impact fonctionnel.

## Server Actions / API

### `stellantisApi` (nouveau, privé)
```php
const STATS_DAY_PREFIX   = 'stellantis::stats::day::';
const STATS_MIN_PREFIX   = 'stellantis::stats::min::';
const STATS_TTL          = 691200;   // ~8 jours
const STATS_MIN_TTL      = 120;      // fenêtre de dérive
const STATS_DERIVE_SEUIL = 60;       // appels/min/compte → log warning (⚠️ ESTIMATION, cf. ci-dessous)
const STATS_MAX_ENDPOINTS = 50;      // cap défensif anti-prolifération

private static function normaliserEndpoint(string $_url): string;
private static function compterAppel(string $_url, int $_slot): void;   // try/catch \Throwable, ne lève jamais
```

`compterAppel()` :
1. `try { normalise ; RMW clé jour (total++ ; byEndpoint[label]++ avec cap → (autres)) ; cache::set TTL 8j`
2. `RMW clé minute (++) ; si === STATS_DERIVE_SEUIL → log::add('stellantis','warning', dérive …)` (**edge-triggered** : `=== seuil` ne loggue qu'une fois par minute, au franchissement) ; le libellé du log précise le compte si slot > 1.
3. `} catch (\Throwable $e) { /* instrumentation non bloquante (AC3) */ }`

### `stellantis` (nouveau, public — restitution)
```php
public static function recapStatistiquesApi(int $_nbJours = 7): array;
```
Boucle sur `slotsConfigures()` ; pour chaque slot lit la clé jour du jour + les N derniers jours et agrège :
```
{
  today: { total: int, byEndpoint: { label => int } },   // sommé sur tous les comptes
  total_periode: int, jours: int,
  par_compte: { slot => total_today }                     // ventilation (utile si multi-comptes)
}
```
Lecture cache seule, aucun appel réseau. Callable depuis `health()` (même classe) **et** depuis la page
plugin (via `stellantis::`, jamais `stellantisApi::` direct → respect de la règle autoload).

### `health()` (+1 ligne)
```
test   = "Appels API REST (aujourd'hui)"
result = "<total> appels — <top endpoints>"  (+ ventilation par compte si >1 slot), htmlspecialchars
advice = ""
state  = true   (ligne informative — la dérive est signalée par log warning, pas ici)
```
Cas vide → « aucun appel enregistré aujourd'hui », state=true.

### `desktop/php/stellantis.php` (bloc restitution)
Après le bandeau connexion : bloc compact « Consommation de l'API REST » = total du jour + détail par
endpoint (liste) + total sur 7 jours (+ ligne par compte si multi-comptes). Toute valeur affichée passée
à `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` (convention défensive projet — les labels sont internes,
pas de donnée externe/tainted, mais on garde la discipline).

## Dépendances
Aucune (pas de `packages.json`, pas d'`install.php`, pas de config neuve, pas de démon touché).

## ⚠️ Limites assumées
- **Commandes MQTT hors périmètre du compteur.** L'objectif spec dit « REST + commandes », mais le
  publish MQTT (`publishRemoteCommand`/`sendToDaemon` — wakeup/charge/lock/precond/horn/lights) **ne
  passe pas par `stellantisApi`** et est *fire-and-forget* (aucune réponse serveur synchrone en PHP ;
  compter au publish = compter des tentatives, pas des réponses ; compter à l'ack serait **incomplet par
  construction** — UC17 klaxon/feux n'ont volontairement aucune corrélation). Donc seule la **face REST
  des commandes (OTP `/smsCode`, `/token`)** est comptée. Le volume MQTT est déjà **borné par ses garde-
  fous dédiés** (quota wakeup 5/20 min, debounce commandes 10 s, cooldown per-véhicule) → hors périmètre
  de ce compteur. Libellés UI volontairement « **API REST** » (pas « API ») pour ne pas sur-promettre.
- **`downloadToFile()` (APK GitHub, UC61) non compté.** Il a un chemin cURL séparé (ne passe pas par
  `httpRequest`) ; c'est un téléchargement depuis GitHub (pas l'API PSA), événement rarissime déclenché
  par l'admin → hors télémétrie de consommation PSA. Commenté dans le docblock de `downloadToFile()`
  pour couper court à une lecture littérale de l'AC1.
- **Seuil de dérive `60/min/compte` = ESTIMATION à valider en recette.** Comme UC72 (backoff écarté faute
  de seuils de ban documentés), ce seuil n'est pas calibré sur donnée réelle : choisi généreux (un
  « Synchroniser » de ~10 véhicules ≈ ~21 appels/min, loin sous 60) pour éviter les faux positifs, et
  cloisonné par compte (chaque compte plus petit). À confirmer en recette → cas ajouté à
  `81-validation-manuelle.md` (« Synchroniser » en flotte multi-véhicules/comptes ⇒ vérifier absence de
  faux positif dans les logs). Reste ensuite ajustable sans changement d'archi.
- **RMW non-atomique** : léger sous-comptage possible sous concurrence (assumé, cf. Validation).

## i18n (FR uniquement — traduction différée au sous-agent `translator`)
Nouvelles clés à envelopper (`__()` en PHP, `{{...}}` en HTML) :
- `health()` (PHP `__()`) : `Appels API REST (aujourd'hui)`, `%s appels`, `aucun appel enregistré aujourd'hui`, `Compte %s` (via `sprintf`).
- `desktop/php/stellantis.php` (HTML `{{...}}`) : `Consommation de l'API REST`, `Appels aujourd'hui`, `Sur 7 jours`,
  `Détail par endpoint`, `Aucun appel enregistré`, `Compte` (label statique + numéro de slot concaténé en PHP ;
  le contexte `{{...}}` ne supporte pas de placeholder `%s` — d'où une clé distincte de `Compte %s` côté `health()`).

## Critères d'acceptation (couverture)
- **AC1** (tous les appels passent par `stellantisApi` et sont comptés, jour + endpoint) : ✅ point unique
  `httpRequest`, clé jour `{total, byEndpoint}`. Exclusions documentées (MQTT, `downloadToFile`).
- **AC2** (consommation consultable page plugin / Santé) : ✅ `recapStatistiquesApi()` restituée sur les
  deux surfaces.
- **AC3** (le comptage ne casse jamais un appel métier) : ✅ `compterAppel()` intégralement `try/catch
  \Throwable`.
