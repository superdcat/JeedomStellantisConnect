# Spec technique — 42 (Pression des pneus / alertes TPMS)

> Compagnon technique de `42-pression-pneus.md`. Plan validé le 2026-07-13
> (global uniquement — pas de per-roue ; lecteur `/alerts` autonome calqué sur `/maintenance` UC41 ;
> `active` absente = **fail-closed** ; throttle **1 h** nominal). 100 % PHP lecture/parsing.
> **1 nouvel appel REST throttlé**, aucun MQTT, aucun JS/AJAX, **aucun changement UI/config**.

## Contrat API Stellantis/PSA (vérifié 2026-07-13)

**Endpoint dédié** : `GET /user/vehicles/{apiId}/alerts` (REST OAuth2, même famille d'URI que `/status`,
`/lastPosition`, `/maintenance`). Passe par la brique unique `stellantisApi::callWithToken('GET', …)`.
Scope OAuth du plugin = `openid profile` (comme le code de référence) — les scopes granulaires
`alert_read`/`alert_write` du doc B2C officiel ne concernent **pas** le realm consommateur ; un
véhicule/forfait non couvert répond simplement 403/404.

**Réponse** — modèles de référence (`api_spec.md` de `psa_car_controller`) : `Alert`, `Alerts`,
**`AlertsEmbedded`**, `AlertMsgEnum`, `AlertEndPosition`, `AlertLinks`. Wrapper HAL `_embedded.alerts[]`,
chaque alerte = `{ id, type (AlertMsgEnum string), active (bool), started_at, end_at }`.

| Aspect | Décision | Source / justification |
|---|---|---|
| Wrapper réponse | `_embedded.alerts` puis `alerts` puis racine=liste (**multi-shape défensif**) | `AlertsEmbedded` **existe** côté référence (⇒ shape HAL plus certaine qu'en UC41 où `maintenance_embedded` manquait), mais on garde le repli par prudence |
| Types pneus AlertMsgEnum (8) | `tyreUnderInflation`, `underInflationTyreFault`, `wheelPressureFault`, `adjustTyrePressure`, `frontLeftTyreNotMonitored`, `frontrightTyreNotMonitored`, `rearLeftTyreNotMonitored`, `rearRightTyreNotMonitored` | Data-model interne § 3. **Catalogue best-effort NON vérifié contre un dump runtime** — extensible si la recette révèle d'autres types |
| Casse | comparaison **insensible à la casse** (liste stockée en minuscules, `type` incoming lowercasé) | Casse API incohérente et non vérifiée (`frontright…` en minuscule vs `frontLeft…`) → l'insensibilité à la casse neutralise le risque |
| Disponibilité runtime | **NON garantie** → best-effort, throttlé, création paresseuse | Ni `psa_car_controller` ni l'intégration HA (`homeassistant-stellantis-vehicles`, `const.py` vérifié) ne **lisent** réellement `/alerts` — présent seulement dans le client Swagger généré. **Même leçon transverse qu'UC41** (`stellantis-data-model.md` § 3) |

## Architecture

Tous les changements sont dans `core/class/stellantis.class.php` (aucune nouvelle classe/fichier → aucun
risque d'autoload). **Aucun** changement de `desktop/php/stellantis.php` ni de `configuration.txt/.php`
(pas de seuil éditable — `tyre_alert` est un simple OR, plus simple qu'UC41).

### ⚠️ Inversion de dépendance UC42 → UC43 (assumée & encadrée)

La spec fonctionnelle 42 « dépend de UC43 » (socle de lecture des alertes) mais **UC43 n'est pas encore
implémentée**. UC42 embarque donc un **lecteur `/alerts` minimal et autonome**, conçu pour qu'UC43
l'**ÉTENDE** (catalogue complet des ~80 types + `alerts_count`) **et non le réécrive** :
- `parseAlertes()` renvoie déjà la **liste générique des types d'alerte actifs** (pas seulement pneus) —
  UC43 la réutilise telle quelle.
- `suivreAlertes()` porte le fetch + throttle + cache. **UC43 doit brancher ses commandes ICI**, jamais
  créer un second appel/cache sur `/alerts` (sinon deux pollers concurrents → budget anti-ban doublé,
  TTL qui se marchent dessus). Consigne inscrite en commentaire de la méthode **et** dans
  `43-alertes-vehicule.md` (§ « Réutilisation UC42 »).

### 1. `definitionsCommandes()` — +1 info (création PARESSEUSE)

Jamais déclarée dans `createCommands()` (précédent UC21/23/24/31/33/34/41) : naît au 1er `/alerts` réussi
(via `ensureCommand()` dans `suivreAlertes()`). Libellé **littéral** dans `__()` (extracteur i18n, piège UC07).

| logicalId | nom FR (littéral) | subType | generic | unité | historisé |
|---|---|---|---|---|---|
| `tyre_alert` | `Alerte pression pneus` | binary | `''` | `''` | **oui** |

- **Historisée** (binaire) = déclencheur de scénario natif (précédent `service_due` UC41 / `at_home` UC34).
- `generic_type=''` : aucune constante core TPMS fiable (convention projet « on ne devine pas une
  constante core non vérifiée »).

### 2. `parseAlertes(array $_resp): array` — pur, statique, GÉNÉRIQUE

Miroir défensif de `parseMaintenance()` : shape/champ absent ⇒ liste vide, **jamais d'exception**. Seul
endroit portant les chemins JSON `/alerts`. Renvoie la **liste des `type` d'alertes ACTIVES** (chaînes
brutes, non filtrées pneus — forward-compatible UC43).

```
$liste = (isset($_resp['_embedded']['alerts']) && is_array($_resp['_embedded']['alerts'])) ? $_resp['_embedded']['alerts']
       : ((isset($_resp['alerts']) && is_array($_resp['alerts']))                          ? $_resp['alerts']
       : (array_is_list($_resp) ? $_resp : array()));   // repli racine=liste ; sinon rien
$types = array();
foreach ($liste as $a) {
  if (!is_array($a) || !isset($a['type']) || !is_string($a['type'])) continue;
  // ⚠️ FAIL-CLOSED : 'active' absente ⇒ INACTIVE. Pour une ALERTE sur endpoint NON vérifié, ne jamais
  // conclure « active » par défaut (éviter le faux positif « crie au loup »). On loggue en debug (jamais
  // error, best-effort) les entrées sans clé 'active' pour confirmer la vraie sémantique en recette.
  if (!isset($a['active'])) { log::add('stellantis','debug','/alerts : entrée sans clé active (type '.$a['type'].') — ignorée (fail-closed)'); continue; }
  if (!filter_var($a['active'], FILTER_VALIDATE_BOOLEAN)) continue;
  $types[] = $a['type'];
}
return $types;
```

> Note : `array_is_list()` existe en PHP ≥ 8.1 (cible Jeedom). Si indisponible, repli
> `array_keys($_resp) === range(0, count($_resp)-1)`.

### 3. `calculerAlertePneu(array $_typesActifs): int` — pur, statique

Séparation calcul/IO (précédent `calculerRevisionProche`/`calculerRecapTrajet`/`distanceHaversineM`).
**Toujours 0 ou 1, jamais null** : sur une réponse `/alerts` réussie on émet TOUJOURS la valeur (satisfait
AC3 « absence d'alerte ⇒ info à 0 »).

```
foreach ($_typesActifs as $t) {
  if (in_array(strtolower(trim($t)), self::TYRE_ALERT_TYPES, true)) return 1;
}
return 0;
```

### 4. `suivreAlertes(string $_apiId): void` — best-effort, appelée EN DERNIER dans `refreshTelemetry()`

Après `suivreMaintenance()` (même convention robustesse UC24/33/34/41 : `try/catch \Throwable` interne,
**ne lève JAMAIS**, ne doit jamais interrompre le refresh). Throttle = **timestamp de prochaine
interrogation autorisée** en cache (miroir `suivreMaintenance`).

```
$cle = self::ALERTS_NEXT_KEY . $this->getId();
if ((int) cache::byKey($cle)->getValue(0) > time()) return;   // throttle actif
if (stellantisApi::rateLimitRemaining() > 0) return;          // cooldown 429 : réessai après, SANS poser de throttle (déjà borné)
try {
  $resp = stellantisApi::callWithToken('GET', '/user/vehicles/' . $_apiId . '/alerts');
  cache::set($cle, time() + self::ALERTS_TTL_OK, self::ALERTS_TTL_OK);      // succès : 1 h
  $typesActifs = self::parseAlertes($resp);
  $tyre = self::calculerAlertePneu($typesActifs);              // 0/1, TOUJOURS émis sur succès (AC3)
  $cmd = $this->ensureCommand('tyre_alert');                   // création paresseuse ici
  $this->checkAndUpdateCmd($cmd, $tyre);
} catch (\Throwable $e) {
  // 403/404 = endpoint non supporté par CE véhicule/forfait → throttle LONG (évite de re-poller un
  // endpoint mort + bruit de log). Transitoire (5xx/timeout/token/inattendu) → throttle COURT.
  $httpCode = ($e instanceof stellantisException) ? $e->getHttpCode() : 0;
  $apiType  = ($e instanceof stellantisException) ? $e->getApiType()  : 'error';
  $ttl = ($httpCode == 403 || $httpCode == 404) ? self::ALERTS_TTL_ABSENT : self::ALERTS_TTL_ERREUR;
  cache::set($cle, time() + $ttl, $ttl);
  log::add('stellantis', 'info', 'Alertes véhicule non récupérées (' . $apiType . ') — poursuite');
}
```

Un throttle est **toujours** posé (succès + toute branche `catch`) ⇒ aucun retry-storm ; la garde
`rateLimitRemaining()` est le **seul** cas de sortie sans throttle (cooldown 429 lui-même borné).
Universel (tout véhicule a des pneus — **aucune garde de motorisation**).

### 5. Constantes (près des `MAINTENANCE_*`/TTL de la classe)

```
const ALERTS_NEXT_KEY   = 'stellantis::alerts_next::'; // + eqId (cache) : timestamp de prochaine interrogation /alerts autorisée
const ALERTS_TTL_OK      = 3600;    // 1 h  — cadence nominale (voir « Cadence » ci-dessous)
const ALERTS_TTL_ABSENT  = 604800;  // 7 j  — endpoint 403/404 (non supporté par ce véhicule/forfait)
const ALERTS_TTL_ERREUR  = 10800;   // 3 h  — erreur transitoire (réessai le jour même, sans storm)
// Catalogue best-effort des types AlertMsgEnum liés aux pneus (data-model § 3), EN MINUSCULES (comparaison
// insensible à la casse, casse API incohérente/non vérifiée). Extensible si un dump runtime révèle d'autres types.
const TYRE_ALERT_TYPES = array(
  'tyreunderinflation', 'underinflationtyrefault', 'wheelpressurefault', 'adjusttyrepressure',
  'frontlefttyrenotmonitored', 'frontrighttyrenotmonitored', 'rearlefttyrenotmonitored', 'rearrighttyrenotmonitored',
);
```

**Cadence 1 h — justification** (comme UC41 documente son 24 h/7 j/3 h) : une alerte TPMS de sous-gonflage
est un phénomène lent (fuite) et le véhicule alerte déjà le conducteur instantanément au tableau de bord ;
une latence ≤ 1 h côté Jeedom (scénario/notification) est acceptable. `/alerts` reste un appel REST
**distinct** du `/status` (5 min) : +~24 appels/j/véhicule sur un endpoint à fiabilité runtime non
confirmée → cadence délibérément > `/status` mais bien throttlée (anti-ban), mutualisée par la garde
`rateLimitRemaining()`.

### 6. Point d'appel

`refreshTelemetry()` : ajouter `$this->suivreAlertes($apiId);` **après** `$this->suivreMaintenance($apiId);`
(dernière ligne de la méthode).

## Server vs Client

100 % serveur. Aucune commande action, aucun MQTT, aucun JS/AJAX, aucun formulaire (pas de seuil
per-véhicule — `tyre_alert` est un pur OR). Restitution utilisateur = **scénario Jeedom natif** sur
changement de `tyre_alert` (pas de `message::add`, pas de spam).

## Validation

- **Serveur** : parsing défensif (shape/champ absent ⇒ liste vide, jamais d'exception) ; **fail-closed**
  sur `active` absente ; best-effort/`try/catch \Throwable` (robustesse cron). Émission systématique de
  `tyre_alert` (0/1) sur toute réponse `/alerts` réussie.
- **Client** : aucune.

## Server Actions / API

Aucune. Une seule info `tyre_alert` (binary historisée), alimentée par `suivreAlertes()`.

## Dépendances

Aucune (aucun paquet, aucune extension PHP).

## Décisions documentées (pour reviewers & futurs mainteneurs)

1. **Pas de commande par roue** (validé 2026-07-13) : les seuls types AlertMsgEnum **positionnels** sont
   `*TyreNotMonitored` (= capteur d'une roue non surveillé, sémantique ≠ sous-gonflage) ; les vraies
   alertes de pression (`tyreUnderInflation`…) ne portent **aucune** position. Des binaires `tyre_<fl|fr|
   rl|rr>` ne refléteraient jamais qu'un « capteur offline » → trompeur et faible valeur. Granularité
   par roue laissée à un UC ultérieur si un dump runtime la justifie. **Ne pas réintroduire à tort.**
2. **`active` absente = fail-closed** (validé 2026-07-13, sur avis advisor) : sur un endpoint à shape non
   vérifiée, on ne conclut JAMAIS « alerte active » par défaut (éviter le faux positif). Log `debug` des
   entrées sans clé `active` pour confirmer la vraie sémantique en recette avant tout assouplissement.
3. **Inversion de dépendance UC42/UC43** : voir § Architecture — `suivreAlertes()`/`parseAlertes()` sont
   le socle qu'UC43 étend, pas un doublon à recréer.

## Critères d'acceptation — couverture

- **AC1** (sous-gonflage → `tyre_alert=1`, scénario) : `calculerAlertePneu` = OR des types pneus actifs,
  binaire **historisée** ⇒ déclencheur de scénario.
- **AC2** (aucune « pression en bar ») : par construction, seule `tyre_alert` (binary) est créée.
- **AC3** (absence gérée, info à 0, pas d'erreur) : `tyre_alert=0` émis sur toute réponse `/alerts`
  réussie sans alerte pneus ; best-effort (`try/catch`) sinon.

## Recette manuelle (à ajouter à `81-validation-manuelle.md`)

Scénario UC42 : sur un véhicule dont le forfait expose `/alerts`, vérifier l'apparition de `tyre_alert`
après ≤ 1 cycle throttle (1 h), à `0` en l'absence d'alerte pneus, et à `1` si l'API remonte une alerte de
sous-gonflage (déclencheur de scénario). Vérifier l'**absence totale** de la commande sur un véhicule qui
ne supporte pas `/alerts` (403/404 → throttle long, pas de création). Contrôler dans les logs `debug`
l'éventuelle présence d'entrées `/alerts` sans clé `active` (confirmation de la sémantique fail-closed).
