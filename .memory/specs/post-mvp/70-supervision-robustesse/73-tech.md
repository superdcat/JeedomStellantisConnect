# Spec technique — 73 — Protection de la batterie 12 V (auto-wakeup adaptatif)

> Feature : auto-wakeup **opt-in**, **désactivé par défaut**, à cadence **adaptative** selon l'état du
> véhicule (en charge ~5 min / en veille ~60 min / roulage : aucun réveil), **jamais sous les limites
> UC72**, avec **avertissement clair du risque batterie 12 V** dans l'UI. Le polling REST normal continue
> indépendamment.
>
> UC73 est **l'exception opt-in** à la règle historique du projet « JAMAIS de wakeup au cron » (anti-ban /
> batterie 12 V). Cette exception est strictement encadrée (opt-in, garde-fous UC72, backoff).

## Architecture

**100 % PHP, cron-driven, lecture locale.** Aucun appel réseau/MQTT nouveau, aucune dépendance, aucune
action AJAX. UC73 ne fait que **déclencher, sous conditions, le wakeup MQTT existant (UC13)** en lisant la
**télémétrie déjà stockée** (`charging_status`, `moving`).

### Fichiers touchés

**`core/class/stellantis.class.php`** (cœur) :

- **Nouveau bloc de constantes** `UC73` (près des constantes wakeup UC13, L.2564+) :
  - `AUTO_WAKEUP_CONFIG = 'auto_wakeup'` — clé de config véhicule (opt-in).
  - `AUTO_WAKEUP_CHARGE_MIN_CONFIG = 'auto_wakeup_charge_min'` / `AUTO_WAKEUP_IDLE_MIN_CONFIG = 'auto_wakeup_idle_min'` — clés de config des cadences par véhicule.
  - `AUTO_WAKEUP_LAST_KEY = 'stellantis::auto_wakeup_last::'` — (+ eqId) cache : horodatage du dernier
    auto-wakeup (succès OU échec ⇒ sert de gate de cadence ET de backoff). **Distinct** de
    `WAKEUP_COOLDOWN_KEY` (300 s, plancher dur partagé manuel+auto).
  - `AUTO_WAKEUP_CHARGE_MIN_DEFAUT = 5` / `AUTO_WAKEUP_IDLE_MIN_DEFAUT = 60` — défauts (minutes).
  - `AUTO_WAKEUP_MIN_PLANCHER = 5` — plancher dur (min) = `WAKEUP_COOLDOWN / 60` : une cadence configurée
    plus basse est remontée à 5 min (jamais sous le cooldown UC13/limite UC72).
  - `AUTO_WAKEUP_IDLE_MAX_MIN = 1440` — plafond (min) d'une cadence configurée (garde-fou saisie).
  - TTL de `AUTO_WAKEUP_LAST_KEY` = **dérivé** `AUTO_WAKEUP_IDLE_MAX_MIN * 60` (auto-purge des véhicules
    supprimés ; jamais inférieur à la cadence idle max — cf. finding advisor #6).

- **Helper PUR/statique** `cadenceAutoWakeupSecondes(string $_chargingStatus, $_moving, int $_chargeMin, int $_idleMin): ?int`
  (testable, aucun accès cache/cmd/config) :
  - `(int) $_moving === 1` ⇒ **`null`** (véhicule en roulage : déjà réveillé, REST frais → pas de wakeup,
    on ne gaspille ni quota ni batterie).
  - `strcasecmp($_chargingStatus, 'InProgress') == 0` ⇒ cadence de charge (`$_chargeMin`).
  - sinon (veille / statut terminal / inconnu) ⇒ cadence de veille (`$_idleMin`).
  - **Clamp** de la cadence retenue : `max(AUTO_WAKEUP_MIN_PLANCHER, min(AUTO_WAKEUP_IDLE_MAX_MIN, $cadenceMin))`,
    puis `* 60` (secondes). Garantit l'invariant UC72 par construction.

- **Helpers de repli défauts** (clé vide ≠ 0, précédent `seuilAlerteKm/Jours` UC41) :
  `cadenceChargeMin(): int` / `cadenceIdleMin(): int` — lisent la config véhicule, replient sur les défauts
  si vide/non numérique.

- **Orchestrateur best-effort** `declencherAutoWakeupSiDu(): void` (instance, **ne lève jamais** —
  `try/catch \Throwable` englobant, convention `suivre*`) :
  1. `(int) $this->getConfiguration(AUTO_WAKEUP_CONFIG, 0) !== 1` ⇒ `return` (**opt-out par défaut, AC1**).
  2. `self::accountSlotDe($this) != 1` ⇒ `return` (pilotage MQTT = slot 1 uniquement, UC54 — source unique
     `accountSlotDe`, pas de lecture brute, cf. advisor #4).
  3. Lit l'état local : `getCmd('info','charging_status')` / `getCmd('info','moving')` puis `execCmd()`
     (défensif : cmd absente ⇒ chaîne vide / non-mouvement ⇒ branche veille — thermique OK).
  4. `cadenceAutoWakeupSecondes(...)` avec `cadenceChargeMin()`/`cadenceIdleMin()` ⇒ `null` ⇒ `return`
     (roulage).
  5. **Gate cadence** : `time() - (int) cache::byKey(AUTO_WAKEUP_LAST_KEY.eqId)->getValue(0) < $cadence`
     ⇒ `return`.
  6. Déclenchement :
     ```
     $reussi = false;
     try { $this->wakeup(); $reussi = true; }
     catch (stellantisException $e) {
        // rate_limited (cooldown 300s / quota compte) → debug (contention attendue, non fatale)
        // otp_required → debug (alerterOtpRequired déjà émis par wakeup() = canal canonique)
        // autre (not_configured/transport) → warning THROTTLÉ 1×/h/véhicule (clé cleWarn dédiée)
     }
     // last_auto = now DANS TOUS LES CAS (succès ET échec) ⇒ cadence = backoff, pas de rafale
     // chaque minute (cron 1/min), cf. advisor #2.
     cache::set(AUTO_WAKEUP_LAST_KEY.eqId, time(), TTL);
     if ($reussi) log::add(... 'info', 'Réveil automatique (' . charge|veille . ') déclenché pour #id');
     ```
     Le pré-check `hasRemoteToken()` est **supprimé** (advisor #3) : `wakeup()` gère lui-même l'alerte OTP.

- **Hook dans `cron()`** (L.3960+) : dans la boucle véhicules, **après** `if (empty($tokenOk[$slot])) continue;`
  (L.3972) et **avant** le bloc `CMD_PENDING`, en réutilisant le `$slot` déjà résolu par `accountSlotDe` :
  ```
  // UC73 : auto-wakeup adaptatif (opt-in) — SLOT 1 uniquement (pilotage à distance). Indépendant de la
  // gate de phase du polling REST : s'évalue chaque minute, cadence propre. Best-effort (ne lève jamais).
  if ($slot == 1) {
    $eqLogic->declencherAutoWakeupSiDu();
  }
  ```
  Placé **avant** `CMD_PENDING` et la gate de phase de polling ⇒ évalué à chaque passe cron (la cadence
  propre gère la fréquence). Le polling REST (`refreshTelemetry` + ses gates) reste **strictement inchangé**
  (**AC3**).

**`desktop/php/stellantis.php`** (formulaire par véhicule) — nouveau bloc `UC73` après `service_alert_days`
(L.258), champs éditables `eqLogicAttr data-l2key` (mêmes précédents que `battery_capacity`/`service_alert_*`) :
- Checkbox `auto_wakeup` (**pas** d'attribut `checked` ⇒ OFF par défaut, **AC1**).
- **Avertissement visible risque batterie 12 V** (texte d'alerte + tooltip, **AC1**).
- `auto_wakeup_charge_min` (`type=number min=0 step=1`, placeholder 5 ; tooltip annoté « sans effet sur
  véhicule thermique », advisor #9).
- `auto_wakeup_idle_min` (`type=number min=0 step=1`, placeholder 60).

## Server vs Client

**100 % serveur.** Décision cron, lecture cache/cmd/config, garde-fous — tout côté PHP. Le client = seulement
le formulaire de config (liaison native `eqLogicAttr`, auto-save/-load du core). **Aucun JS custom**
(pas d'action AJAX, pas de widget). Justification : la régulation anti-ban doit être autoritaire côté serveur ;
le front n'a aucun rôle de décision.

## Validation

**Serveur (autoritaire)** :
- Opt-in strict (`auto_wakeup !== 1` ⇒ off) — **AC1**.
- Garde slot 1 (`accountSlotDe`) — cohérence UC54.
- **Clamp des cadences** : plancher 5 min (jamais sous cooldown UC13/limites UC72) + plafond 1440 min —
  invariant **AC2** par construction.
- Réutilise **inchangés** le cooldown per-véhicule 300 s + le quota GLOBAL compte 5/20 min de
  `wakeup()`/`publishRemoteCommand()` ⇒ « jamais sous UC72 » garanti même en contention.
- Best-effort (ne lève jamais) ; `last_auto` mis à jour dans tous les cas ⇒ backoff = cadence (pas de
  rafale de tentatives) ; échec non transitoire throttlé (pas de spam log).

**Client (indicatif)** : `type=number min=0` sur les cadences ; le serveur reste autoritaire par clamp
(une saisie 1 min ⇒ ramenée à 5 min ; vide ⇒ défaut).

## Server Actions / API

**Aucune action AJAX nouvelle.** Signatures ajoutées à `stellantis` :
- `cadenceAutoWakeupSecondes(string, $moving, int, int): ?int` — **pure**, décision de cadence.
- `cadenceChargeMin(): int` / `cadenceIdleMin(): int` — repli défauts config véhicule.
- `declencherAutoWakeupSiDu(): void` — orchestrateur best-effort (jamais throw), appelé par `cron()`.

Réutilise **sans modification** : `wakeup()`, `publishRemoteCommand()`, `consommerQuotaWakeup()`,
`accountSlotDe()`, `alerterOtpRequired()` (via le chemin interne de `wakeup()`).

## Contrat API Stellantis/PSA

**Inchangé.** Le seul canal est le wakeup MQTT existant (UC13) : `publishRemoteCommand('/VehCharge/state',
{action:'state'})` → topic `psa/RemoteServices/from/cid/{CID}/VehCharge/state` (contrat `RemoteClient.wakeup`
de `psa_car_controller`). L'ack (UC18) programme le refresh REST au prochain cron. La détection d'état est
purement locale (aucun appel réseau).

## Dépendances

**Aucune** (pas de paquet pip, pas de démon nouveau, pas d'extension PHP, `demond.py` inchangé).

## Limites assumées (documentées)

- **Contention multi-VE (advisor #1)** : le quota wakeup est **GLOBAL au compte** (slot 1). À cadence charge
  5 min, deux VE en charge simultanée dépassent le plafond compte (5/20 min) ⇒ certaines passes sont
  refusées (`rate_limited`, backoff via `last_auto`). **Choix assumé** : le quota UC72 EST le plafond dur
  (anti-ban prioritaire) — AC2 « jamais sous UC72 » reste satisfaite (on ne dépasse jamais). Pas de division
  dynamique de cadence (scope creep) ; le backoff + le log throttlé donnent la visibilité. Un futur UC
  pourra affiner (répartition équitable / round-robin) si le besoin est confirmé en recette.
- **`preRemove` (advisor #10)** : `AUTO_WAKEUP_LAST_KEY` est auto-expirante (TTL 24 h) ⇒ **aucune purge
  dédiée** (cohérent avec les autres clés cache per-véhicule à TTL borné : `WAKEUP_COOLDOWN_KEY`,
  `CHARGE_LAST_STATUT_KEY`…).
- **Cadence de charge sur thermique** : `charging_status` absent sur thermique pur ⇒ branche veille par
  construction (sûr). Le champ reste visible mais annoté « sans effet sur véhicule thermique ».

## Chaînes i18n (FR — traduction différée étape 10)

- `Réveil automatique adaptatif`
- `Réveille périodiquement le véhicule pour rafraîchir la télémétrie (batterie, position, charge…) que le polling REST seul ne peut pas obtenir. Cadence adaptative : fréquente en charge, rare en veille. Nécessite l'activation de l'OTP (pilotage à distance).`
- `⚠️ Risque batterie 12 V : réveiller le véhicule consomme la batterie de servitude. Un usage excessif peut la décharger (démarrage / accès sans clé inopérants). À n'activer qu'en connaissance de cause.`
- `Cadence de réveil en charge (min)`
- `Fréquence de réveil automatique quand le véhicule est en charge — minimum 5 min (protection anti-ban / batterie). Sans effet sur un véhicule thermique. Laisser vide pour le défaut (5 min).`
- `Cadence de réveil en veille (min)`
- `Fréquence de réveil automatique quand le véhicule est à l'arrêt — plus la valeur est élevée, plus la batterie 12 V est préservée. Laisser vide pour le défaut (60 min).`

Les messages `log::add` restent en français **non** enveloppés (convention). Les exceptions de `wakeup()`
sont déjà i18n'd et non re-surfacées par l'auto-wakeup.
