# Spec technique — 72 (Rate-limiting & anti-ban)

> **Nature** : UC **majoritairement déjà couvert** par les garde-fous anti-ban accumulés (MVP/10, UC13,
> UC14-17, UC54). Le travail neuf est **ciblé** : (1) supprimer la **dernière rafale** non traitée — le
> polling en cadence par défaut (déplacé ici depuis UC53) ; (2) rendre le 429 **visible** de l'utilisateur
> (AC2 « avec alerte ») ; (3) **auditer et documenter** que le reste des AC est déjà satisfait. **Aucun
> nouvel appel réseau/MQTT** : UC72 ne fait que *borner/tracer* les appels existants. Tout le code de prod
> est dans `core/class/stellantis.class.php`.
>
> **Décision de cadrage (validée avec l'utilisateur, après challenge advisor, 2026-07-15)** : le **backoff
> exponentiel** évoqué dans la section « Détails » de la spec fonctionnelle est **volontairement écarté**
> de cet UC (cf. § « Backoff exponentiel — écarté » plus bas). Le cooldown fixe 900 s satisfait déjà la
> lettre d'AC2 ; l'alerte livrée ici fournira la **télémétrie** (fréquence réelle des 429) qui manque
> aujourd'hui pour concevoir un vrai backoff calibré, plus tard, sur preuve.

## Architecture

### Fichiers touchés
- **`core/class/stellantis.class.php`** — seul fichier de production modifié :
  - `cron()` (≈ l.3931-3976) : **anti-rafale du polling**. La branche cadence **par défaut** (aujourd'hui
    `self::CRON_DEFAUT` évaluée via `Cron\CronExpression::isDue()`) est remplacée par une **gate de phase
    modulo** déterministe par véhicule. La branche `autorefresh` explicite (opt-out) est **inchangée**.
    `$minuteActuelle` est calculée **une seule fois avant la boucle** `foreach`.
  - `cron()` — branche succès du priming (≈ l.3890-3891, où `LINK_ERROR_KEY`/`degraded_warn` sont déjà
    effacés par slot) : **effacement de l'alerte 429** de ce slot (`message::removeAll`).
  - `stellantisApi::enterRateLimitCooldown()` (≈ l.5028-5031) : après l'armement du cooldown + le
    `log::add`, **déclenche l'alerte utilisateur** `stellantis::alerterRateLimit($_slot)`.
  - Nouveau helper **`stellantis::alerterRateLimit(int $slot): void`** — **`public static`** (appelé depuis
    `stellantisApi`, classe distincte), calqué sur `alerterOtpRequired`/`alerterDaemonAuthFailed`.
- **`core/i18n/{en_US,de_DE,es_ES}.json`** — traductions (étape 10, différée ; hors périmètre dev).

### Contrat core Jeedom
- **Hook `cron()`** (chaque minute) — inchangé dans son principe (cf. MVP/08, mémoire
  `jeedom-cron-autorefresh`). On **ne touche pas** au chemin `isDue()` des expressions `autorefresh`
  personnalisées ; on remplace uniquement le calcul de la cadence **par défaut**.
- **`message::add($plugin, $message, $action, $logicalId)`** / **`message::removeAll($plugin, $logicalId)`**
  — centre de messages Jeedom, déjà utilisé par le plugin (`otp_required`, `daemon_auth_failed`,
  `command_failed`). Le `$logicalId` sert de **tag** : un `removeAll` puis `add` garantit **au plus un**
  message actif par tag (pas d'empilement). **Ici le tag est suffixé par slot** (`rate_limited_<slot>`)
  pour ne pas qu'un compte efface l'alerte d'un autre.
- **`cache::byKey/set`** (lifetime en s) — throttle et cooldowns, déjà omniprésents.
- **Aucune** nouvelle action AJAX, page, template, JS, ni champ de formulaire.

### Contrat API Stellantis/PSA
**Aucun.** UC72 n'ajoute **aucun** appel REST ni MQTT. Il ne fait que **borner** (anti-rafale) et **tracer**
(alerte) des appels qui existent déjà. Faits durs de calibrage (analyse § 1.4, rappelés par la spec) :
ban si wakeup ~toutes les 2 min ; wakeup ~6/20 min ; remote token OTP 6/24 h. Ces bornes sont **déjà**
implémentées (cf. tableau d'audit) — UC72 ne les modifie pas.

## Server vs Client

**100 % serveur (PHP).** Toute la logique est back-end : hook cron, cooldown transport, alerte centre de
messages. **Aucun** JS, HTML, template ni AJAX. Justification : le rate-limiting et l'anti-ban sont des
préoccupations purement serveur (le client ne doit pas pouvoir les contourner).

## Validation

### Serveur
- **Anti-rafale (gate modulo)** :
  - Prédicat : pour un véhicule en **cadence par défaut** (`autorefresh` vide), rafraîchir seulement si
    `$minuteActuelle % 5 === $eqLogic->getId() % 5`, sinon `continue`. Offset ∈ {0,1,2,3,4} déterministe
    par `eqId`. Équivaut à `%d-59/5 * * * *` (offset 0 → minutes 0,5,…,55 ; offset 4 → 4,9,…,59) **sans**
    syntaxe cron `A-B/step` (non éprouvée dans ce projet ; `php` absent en local ⇒ non testable avant CI).
  - **Invariance fuseau** : tout décalage horaire réel est un multiple de 15 min (≤ Népal +5:45), donc
    divisible par 5 ⇒ `minute % 5` est identique quel que soit le fuseau. Aucun risque TZ.
  - **`$minuteActuelle` hoistée hors boucle** : calculée **une fois** juste avant `foreach ($vehicules …)`.
    Motif : un `/status` lent (timeout cURL 15 s) peut faire franchir la minute en cours de passe → une
    lecture par itération donnerait des décisions de phase incohérentes au sein d'une même passe.
  - **`autorefresh` explicite = opt-out intégral** : la branche `isDue()` existante (y compris le
    `try/catch` warn-throttlé sur expression invalide) est reprise **verbatim**. Un utilisateur qui saisit
    manuellement `*/5 * * * *` **n'entre pas** dans le spread (limite assumée, documentée).
  - **Pas de régression** : la gate remplace uniquement la branche `else` de la cadence, **après** le
    court-circuit `CMD_PENDING` (refresh forcé post-ack, l.3948-3957) et **après** le `continue` sur
    `tokenOk[$slot]` (l.3941-3943) / mode dégradé. Ces trois chemins sont intouchés.
- **Alerte 429 (`alerterRateLimit`)** :
  - Déclenchée depuis **`enterRateLimitCooldown()`** — **point unique** qui arme le cooldown sur un vrai
    HTTP 429 (via `handleApiError` ← `httpRequest`), donc couvre **tous** les chemins : priming/refresh
    token, `/status` du cron, refresh REST avant `charge_stop` (UC14), et les appels OTP (slot 1).
  - **Edge-triggered nativement** : pendant un cooldown actif, `callWithToken` court-circuite **avant**
    `httpRequest` (l.5002-5005) ⇒ `enterRateLimitCooldown` ne peut être ré-appelée qu'après **expiration
    réelle** du cooldown. Aucun throttle-key à inventer ; `message::removeAll` + `add` suffit à garantir
    au plus un message par slot (couvre aussi une éventuelle course 429 concurrente).
  - **Tag suffixé par slot** : `'rate_limited_' . $slot`. Même logique que `cacheKeyForSlot`.
  - **Effacement** : dans la branche **succès du priming** du cron (là où `LINK_ERROR_KEY`/`degraded_warn`
    sont déjà nettoyés par slot). Correct car cette branche n'est atteinte que si `rateLimitRemaining==0`
    (cooldown expiré) — donc la suspension annoncée est bien terminée. (Simple *effacement d'alerte*, pas
    un reset de compteur ⇒ le cache-hit de `getToken()` n'est pas un problème ici, contrairement au
    backoff écarté.)
- **Aucun input utilisateur** (cron + transport) ⇒ pas de validation d'entrée.

### Client
Aucune (pas de formulaire).

## Server Actions / API

**Aucune nouvelle action AJAX.** Détail des modifications :

### 1. `cron()` — anti-rafale du polling (branche cadence)
Remplacer le bloc actuel (l.3958-3976) :
```php
$autorefresh = trim((string) $eqLogic->getConfiguration('autorefresh', ''));
$expression = ($autorefresh != '') ? $autorefresh : self::CRON_DEFAUT;
try {
  $cron = new Cron\CronExpression(checkAndFixCron($expression), new Cron\FieldFactory());
  if (!$cron->isDue()) { continue; }
} catch (\Throwable $e) { /* warn throttlé + continue */ }
```
par :
```php
$autorefresh = trim((string) $eqLogic->getConfiguration('autorefresh', ''));
if ($autorefresh != '') {
  // Cadence PERSONNALISÉE (opt-out anti-rafale) : évaluée telle quelle via CronExpression (INCHANGÉ,
  // cf. docblock : cron() chaque minute + isDue exact ⇒ toute expression valide finit par être honorée).
  try {
    $cron = new Cron\CronExpression(checkAndFixCron($autorefresh), new Cron\FieldFactory());
    if (!$cron->isDue()) { continue; }
  } catch (\Throwable $e) {
    // … bloc warn throttlé + continue EXISTANT, repris VERBATIM (l.3966-3975) …
  }
} else {
  // Cadence PAR DÉFAUT (5 min, CRON_DEFAUT) + anti-rafale UC72 : décalage de phase déterministe par
  // véhicule (offset = eqId % 5) pour que la flotte ne tombe plus due à la même minute (0/5/10…).
  // 5 = le pas de CRON_DEFAUT ('*/5'). $minuteActuelle est figée pour toute la passe (voir hoist).
  if ($minuteActuelle % 5 !== $eqLogic->getId() % 5) { continue; }
}
```
Et **avant** la boucle `foreach ($vehicules as $eqLogic)` (≈ l.3931), hoister :
```php
$minuteActuelle = (int) date('i'); // figée pour toute la passe (anti-rafale UC72) — évite l'incohérence de phase si un /status est lent
```
> `CRON_DEFAUT` reste défini (documente la cadence 5 min, référencé dans le docblock l.3849 et le commentaire
> ci-dessus). Le docblock de `cron()` (l.3844-3855) gagne une phrase : la cadence par défaut passe désormais
> par une gate de phase (anti-rafale UC72), l'`isDue()` ne concernant plus que les cadences personnalisées.

### 2. `stellantisApi::enterRateLimitCooldown()` — déclenchement de l'alerte
Après le `cache::set` + `log::add` existants (l.5029-5030), ajouter :
```php
// UC72 : alerte utilisateur (AC2 « avec alerte ») — point unique qui voit TOUT 429 (cron, commandes,
// OTP). Edge-triggered : le cooldown court-circuite les appels suivants, donc rappelée au plus une fois
// par fenêtre. Best-effort (ne doit jamais faire échouer la gestion d'erreur transport).
try { stellantis::alerterRateLimit($_slot); } catch (\Throwable $e) { /* alerte best-effort */ }
```

### 3. `stellantis::alerterRateLimit(int $slot): void` (nouveau, **public static**)
Calqué sur `alerterOtpRequired` (l.2505) / `alerterDaemonAuthFailed` (l.2517) :
```php
// UC72 — Alerte utilisateur « rate-limit API (HTTP 429), appels suspendus », par compte. PUBLIC (appelée
// depuis stellantisApi::enterRateLimitCooldown, classe distincte — précédent stellantisApi→stellantis :
// getApiConfig/isConfigured). Tag suffixé par slot : un removeAll d'un compte n'efface pas l'alerte d'un
// autre. removeAll+add ⇒ au plus un message actif par compte (pas d'empilement, pas de throttle-key : le
// cooldown rend l'appel edge-triggered).
public static function alerterRateLimit(int $slot): void {
  $tag = 'rate_limited_' . $slot;
  message::removeAll('stellantis', $tag);
  if ($slot > 1) {
    message::add('stellantis', sprintf(__('Rate-limit de l\'API PSA (HTTP 429) sur le compte secondaire %s : appels temporairement suspendus pour protéger le compte d\'un blocage. Le rafraîchissement reprend automatiquement ensuite.', __FILE__), $slot), '', $tag);
  } else {
    message::add('stellantis', __('Rate-limit de l\'API PSA (HTTP 429) : appels temporairement suspendus pour protéger le compte d\'un blocage. Le rafraîchissement reprend automatiquement ensuite.', __FILE__), '', $tag);
  }
  log::add('stellantis', 'warning', 'UC72 : rate-limit API (HTTP 429) → alerte utilisateur' . ($slot > 1 ? ' (compte ' . $slot . ')' : ''));
}
```

### 4. `cron()` — effacement de l'alerte au retour à la normale
Dans la branche succès du priming (l.3890-3891, à côté des `cache::delete` existants) :
```php
message::removeAll('stellantis', 'rate_limited_' . $slot); // UC72 : cooldown 429 expiré + prime OK ⇒ efface l'alerte de CE compte
```

## Audit de couverture des critères d'acceptation (livrable documentaire)

UC72 étant **l'UC anti-ban**, il affirme explicitement l'état de chaque chemin d'appel (aucun silence) :

| Chemin d'appel | Garde anti-rafale / anti-ban | Statut |
|---|---|---|
| **Polling cron (cadence défaut)** | **Gate de phase `eqId % 5`** (UC72) — la flotte ne tombe plus à la même minute | ✅ **neuf (UC72)** |
| Polling cron (cadence perso) | `autorefresh` respecté ; cadence choisie par l'utilisateur | ✅ existant |
| Priming/refresh token (par slot) | `rateLimitRemaining` court-circuit + quota refresh 5/30 min + expiration token | ✅ existant (MVP/10, UC54) |
| Wakeup | cooldown per-véhicule **300 s** + quota global compte **5/20 min** ; **jamais au cron** | ✅ existant (UC13) → **AC3** |
| Commandes MQTT (charge/precond/lock/horn/…) | debounce per-véhicule **10 s** + quota global compte | ✅ existant (UC14-17) |
| Cooldown 429 (par slot, fixe 900 s) | armé par `enterRateLimitCooldown`, respecté par `call()` **et** cron | ✅ existant → **AC2 (cooldown)** |
| **Alerte 429 utilisateur** | `message::add` throttlé par slot (`alerterRateLimit`) | ✅ **neuf (UC72)** → **AC2 (alerte)** |

**Chemins de rafale résiduels — assumés, hors scope (documentés, pas corrigés)** :
- **`syncVehicles()`** boucle `refreshTelemetry()` sur tous les véhicules d'un compte **dos à dos, sans
  délai**, protégée seulement par un cooldown anti-double-clic de 15 s (`sync_cooldown`). Acceptable à
  l'échelle foyer (1-4 véhicules), déjà acté en UC53. Une vraie file/sérialisation serait
  disproportionnée.
- **Consommation `CMD_PENDING` du cron** : plusieurs véhicules acquittés dans la même passe peuvent
  déclencher leur `refreshTelemetry()` forcé sans délai entre eux — borné par `WAKEUP_QUOTA`/debounce
  (≤ 5/20 min), risque faible.

## Backoff exponentiel — écarté (limite assumée)

La section « Détails » de la spec fonctionnelle mentionne un « backoff exponentiel borné ». **Non
implémenté dans UC72**, décision validée :
1. **Calibrage impossible** : la spec elle-même note « valeurs exactes des seuils/durées de ban NON
   documentées ». Une courbe `min(900·2^(n-1), plafond)` serait une supposition.
2. **Piège de reset identifié** : un reset « au prime réussi » s'ancrerait sur `getToken()`, qui renvoie
   le token en **cache sans appel réseau** ⇒ un « succès » ne prouve pas la joignabilité de l'API et
   remettrait le compteur à 0 alors que les `/status` continuent de 429er. Un reset correct exige une
   **vraie réponse 2xx** de `httpRequest` — surface plus large, à concevoir sur données réelles.
3. **ROI** : les quotas existants (refresh 5/30 min, wakeup 5/20 min, debounce 10 s) bornent déjà la
   fréquence de re-test d'un 429. AC2 demande « un cooldown respecté globalement, avec alerte » — **pas**
   une progression. Le cooldown fixe 900 s + l'alerte suffisent.
> L'alerte livrée fournit désormais la trace (fréquence réelle des 429) pour décider, plus tard et sur
> preuve, si un backoff calibré est justifié. **À rouvrir dans un futur UC si la télémétrie le montre.**

## Dépendances

**Aucune** (ni PHP, ni pip). Aucune modification de `packages.json` / `info.json`.

## Impact i18n — chaînes FR introduites (traduction différée, étape 10)

À ajouter dans les 3 `core/i18n/*.json` sous `plugins/stellantis/core/class/stellantis.class.php` :
1. `Rate-limit de l'API PSA (HTTP 429) : appels temporairement suspendus pour protéger le compte d'un blocage. Le rafraîchissement reprend automatiquement ensuite.`
2. `Rate-limit de l'API PSA (HTTP 429) sur le compte secondaire %s : appels temporairement suspendus pour protéger le compte d'un blocage. Le rafraîchissement reprend automatiquement ensuite.` *(garde le `%s`)*

Les messages de `log::add` (anti-rafale, alerte) restent FR **non wrappés** (logs développeur, convention
projet). Les nouvelles chaînes UI sont des `__()` **littéraux** (scan statique de l'extracteur).

## Note changelog (utilisateur)
Le spread de phase **décale la minute de rafraîchissement** des véhicules en cadence par défaut dont
`eqId % 5 ≠ 0` (ex. `last_update` passe de :00/:05 à :01/:06…). Effet cosmétique et attendu, à **signaler
dans le changelog** de la version pour éviter un faux « bug » en recette.

## Critères d'acceptation → couverture
- **AC1** (aucun chemin ne peut émettre en rafale : cron/commandes/wakeup) : polling cron **= neuf (gate
  de phase)** ; commandes (debounce+quota) et wakeup (cooldown+quota) **déjà couverts** ; chemins
  résiduels `syncVehicles`/`CMD_PENDING` **documentés comme acceptables**. → **couvert**.
- **AC2** (429/ban ⇒ cooldown global + alerte) : cooldown global par slot **déjà là** ; **alerte
  utilisateur = neuf** (`alerterRateLimit`). → **couvert**.
- **AC3** (wakeup throttlé, jamais < 5 min) : cooldown per-véhicule 300 s + quota 5/20 min **déjà là**,
  jamais de wakeup au cron. → **couvert (existant, audité)**.
