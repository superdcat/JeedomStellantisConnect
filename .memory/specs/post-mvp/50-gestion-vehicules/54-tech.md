# Spec technique — 54 (Multi-marques & multi-comptes)

> **Décision de périmètre (2026-07-15, workflow `/feature 54`, après challenge advisor)** :
> **multi-comptes en LECTURE seule, slots fixes, rétro-compatible ; pilotage à distance (commandes/OTP)
> sur le compte principal (slot 1) uniquement.** Un cycle unique (backend + UI simple). Cadré par
> `53-tech.md` (namespacer par **identifiant de compte générique**, marque = attribut du compte).

## Architecture

Aucune dépendance nouvelle, aucun fichier de classe nouveau (autoload : tout reste dans
`stellantis`/`stellantisApi`). Aucun endpoint/topic nouveau : UC54 **route** le contrat MVP existant
(`GET /user/vehicles`, `/status`, `/lastPosition`, `/maintenance`, `/alerts`, OAuth2 PKCE par marque via
la table `BRANDS`) **par compte** au lieu du compte global unique.

### Modèle « slots de comptes fixes »
- Constante `MAX_ACCOUNTS = 3` (couvre Peugeot + Citroën + 1 marge ; extensible via la constante).
- **Slot 1 = la config globale ACTUELLE** (clés NON suffixées : `client_id`, `client_secret`, `brand`,
  `country`, `redirect_uri` ; cache token = clé actuelle). ⇒ **zéro migration, installs existantes et
  véhicules existants inchangés** (un véhicule sans `accountSlot` vaut 1).
- **Slots 2..N = clés suffixées** : `client_id_<n>`, `client_secret_<n>`, `brand_<n>`, `country_<n>`,
  `redirect_uri_<n>`.
- `$_encryptConfigKey` étendu : `['client_secret','client_secret_2','client_secret_3','home_lat','home_lon']`
  (liste finie ⇒ chiffrement natif du core conservé pour tous les secrets).

### Helpers (nouveaux, purs/utilitaires)
- `stellantis::configKeyForSlot(string $_base, int $_slot): string` → `$_base` si `$_slot <= 1`, sinon
  `$_base.'_'.$_slot`. Unique endroit du nommage des clés de config par slot.
- `stellantisApi::cacheKeyForSlot(string $_base, int $_slot): string` → `$_base` si `$_slot <= 1`, sinon
  `$_base.'::'.$_slot`. **À appeler à CHAQUE accès à une clé de cache niveau-compte** (anti-oubli — cf.
  bug `RATELIMIT_KEY` ci-dessous). Réutilisable par `stellantis` (link_error/degraded_warn).
- `stellantis::slotsConfigures(): int[]` → liste des slots `1..MAX_ACCOUNTS` dont `client_id` **et**
  `client_secret` (du slot) sont non vides. Consommé par `cron()`, `syncVehicles()`, `health()`.

### ⚠️ Clés de cache à cloisonner PAR SLOT (via `cacheKeyForSlot`)
Niveau **compte** → suffixer par slot :
- `stellantisApi::TOKEN_CACHE_KEY` (`stellantis::token`)
- `stellantisApi::OAUTH_PENDING_KEY` (`stellantis::oauth_pending`)
- `stellantisApi::REFRESH_QUOTA_KEY` (`stellantis::refresh_quota`) — **oubli corrigé** : sinon le quota de
  refresh du compte A gèlerait le compte B.
- `stellantisApi::RATELIMIT_KEY` (`stellantis::ratelimit_until`) — **oubli corrigé** : sinon un 429 sur le
  compte A gèlerait le polling du compte B (court-circuit `callWithToken`/`cron`).
- `stellantis::LINK_ERROR_KEY` (`stellantis::link_error`) et `stellantis::degraded_warn`.

**NE PAS toucher** (déjà scopés, ou volontairement mono-compte) :
- Clés **par véhicule** déjà suffixées `eqId` : `CMD_PENDING_KEY`, `WAKEUP_COOLDOWN_KEY`,
  `CHARGE_DEBOUNCE_KEY`, `CMD_CORR_KEY`, `privacy::{id}`, sessions charge/trajet… (indépendantes du compte).
- Clés **commandes/OTP/démon** : `REMOTE_TOKEN_CACHE_KEY`, `OTP_*`, `WAKEUP_QUOTA_KEY`, `DAEMON_*`,
  `customer_id`, `otp_device`… restent **mono-compte (slot 1)** — le pilotage à distance est slot 1 only.

## Server vs Client

Backend PHP (routage/cron/sync par slot) + UI de config (HTML/JS, sections comptes 2/3). Aucun composant
runtime nouveau. Le **démon MQTT reste strictement inchangé** (mono-connexion, slot 1).

## Validation

### Routage par compte (threading `$slot`, défaut **1** partout → rétro-compat)
- `stellantis::getApiConfig(int $_slot = 1): array` — **réécrire le corps** : `brand`/`clientId`/
  `clientSecret`/`country`/`redirectUri` lus via `configKeyForSlot(..., $_slot)` (aujourd'hui lus en
  global sans tenir compte du param `$_brand` — qui disparaît au profit de `$_slot`). Ajouter `'slot'` au
  tableau retourné. Repli marque inconnue → `peugeot` (inchangé), par slot.
- Méthodes `stellantisApi` à threader `$_slot` (défaut 1) : `call`, `callWithToken`, `getToken`,
  `refreshToken`, `requestToken`, `storeTokenResponse`, `readTokenCache`, `getTokenInfo`,
  `purgeTokenCache`, `buildAuthUrl`, `exchangeCode`, `rateLimitRemaining`, `enterRateLimitCooldown` (+ la
  conso du quota de refresh interne à `refreshToken`).
  - ⚠️ Le **rejeu réactif** de `callWithToken` doit propager le slot : `getToken(true, $failedToken, $slot)`.
  - `getToken(bool $_force=false, ?string $_failedToken=null, int $_slot=1)` : le `$_slot` en **dernier**
    param préserve les appels positionnels existants.
- `refreshTelemetry()` : `$slot = (int) $this->getConfiguration('accountSlot', 1)` en tête, propagé à
  **tous** les appels REST du véhicule — `/status`, `/lastPosition`, et aux helpers réseau
  `suivreMaintenance($apiId, $slot)` / `suivreAlertes($apiId, $slot)` (qui gagnent un param `$slot`).
  `parseStatus`/geofencing/trajets/sessions (aucun réseau) inchangés.

### `cron()` — restructuration du flux (pas un simple paramètre)
Passer d'un `return` global sur échec à un **traitement par slot** :
1. `$vehicules = byType('stellantis', true)` ; si vide → purger `LINK_ERROR_KEY` de **tous** les slots et
   `return`.
2. `$slots = slotsConfigures()` ; pour chaque `$slot` : si `rateLimitRemaining($slot) > 0` → log + marquer
   le slot indisponible + `continue` (ne gèle PAS les autres) ; sinon `try { getToken(false,null,$slot);
   delete link_error/degraded(slot) ; if ($slot==1) syncDaemonToken(); $tokenOk[$slot]=true; } catch {
   pose link_error/degraded PAR SLOT (même taxonomie qu'aujourd'hui) ; $tokenOk[$slot]=false; }`.
   `syncDaemonToken()` **seulement slot 1** (commandes).
3. Boucle véhicules : `$slot = accountSlot` ; si `$slot` ∉ `$slots` → `debug` « compte non configuré » +
   `continue` (jamais de désactivation destructive) ; si `empty($tokenOk[$slot])` → `continue` ; puis
   bloc `wakeup_pending` (inchangé) et évaluation `autorefresh`/`isDue` (inchangés) → `refreshTelemetry()`
   (qui route par slot). try/catch par véhicule conservé.

### `discoverVehicles(int $_slot = 1)` & `syncVehicles()` — sync par compte
- `discoverVehicles($slot)` : `callWithToken('GET','/user/vehicles',[],$slot)` ; logs préfixés du slot.
- `syncVehicles()` : boucler `foreach (slotsConfigures() as $slot)` :
  - `try { $vehicules = discoverVehicles($slot); $slotOk=true; } catch { log; $slotOk=false; continue; }`
  - pour chaque véhicule : `eqLogic::byLogicalId($vin,'stellantis')` (lookup global) ; **si l'eqLogic
    existant a un `accountSlot` ≠ `$slot` → log warning** (même VIN sur 2 comptes / mauvaise config) et
    conserver la découverte comme autorité ; poser `setConfiguration('accountSlot', $slot)` (réécrit à
    chaque sync, comme `brand`) ; reste inchangé (apiId/vin/brand/label/energy, image, createCommands,
    refreshTelemetry).
  - accumuler `$vinsParSlot[$slot]`.
  - **🔴 Désactivation filtrée par slot** (corrige le bug l.559-565) : **seulement** pour les slots où
    `$slotOk === true`, itérer `eqLogic::byType('stellantis')` filtré
    `getConfiguration('accountSlot',1) == $slot`, désactiver ceux dont le VIN ∉ `$vinsParSlot[$slot]`.
    **Jamais** désactiver les véhicules d'un slot en échec de découverte ni d'un slot non parcouru.
  - Compteurs agrégés (créés/màj/désactivés tous slots). Le cooldown `sync_cooldown` reste global (1 bouton).

### `createCommands()` — commandes action slot-1 uniquement
Garder la création des commandes **info** pour tous les slots. Conditionner les commandes **action**
(`ensureActionCommand`) par `$this->getConfiguration('accountSlot', 1) == 1`. Les slots ≥2 = lecture seule
(aucune commande action créée ⇒ rien à exécuter dans `stellantisCmd::execute`). Changement localisé aux
points `ensureActionCommand` existants.

### État & santé par compte
- Factoriser la logique actuelle en `connectionStateForSlot(int $_slot): array`. `connectionState()` (sans
  arg, consommé par le bandeau `desktop/php/stellantis.php` et le cron) = **agrégat pire-état** des slots
  configurés (détail listant le(s) compte(s) en défaut). `health()` : une ligne « Connexion au compte
  principal » (slot 1) puis « Connexion au compte secondaire N » par slot ≥2 configuré ; lignes OTP/démon
  (slot 1) et fraîcheur par véhicule inchangées.

### Hooks de purge par slot
- Ajouter `preConfig_client_id_2/_3` et `preConfig_brand_2/_3` : sur changement, **purger le token du slot
  concerné uniquement** (`stellantisApi::purgeTokenCache($slot)`), **jamais l'OTP** (OTP = slot 1).
- `preConfig_client_id`/`preConfig_brand` (slot 1) **inchangés** (purgent token slot 1 **+** OTP — slot 1
  est le compte de pilotage).

## Server Actions / API (AJAX)
Paramétrer par `slot` (défaut 1, validé `1..MAX_ACCOUNTS` côté serveur) les actions
`core/ajax/stellantis.ajax.php` : `getAuthUrl`, `submitAuthCode`, `extractCredentials`, `testConnection`.
Garde `isConfigured()` : la garde globale existante reste (slot 1) ; `getAuthUrl`/`submitAuthCode`
valident le slot ciblé via `isConfigured($slot)` (surcharge `isConfigured(int $_slot = 1)`). Les actions
OTP (`requestOtpSms`/`activateOtp`/`renewRemoteToken`) et `sync` **inchangées** (OTP slot 1 ; sync boucle
en interne sur tous les slots).

## UI de config (`plugin_info/configuration.txt` → `cp` vers `.php`)
- **Renommer** la section « Connexion API » existante en **« Compte principal (pilotage à distance) »**
  (clés/ids/JS slot 1 inchangés). Section OTP conservée dessous (slot 1 only).
- **Ajouter** 2 fieldsets repliables **« Compte secondaire N (lecture seule) »** (N=2,3), rendus
  **seulement si le slot 1 est configuré** (évite le cas « slot 1 vide, seul 2/3 configuré ») : champs
  `brand_N`/`client_id_N`/`client_secret_N`/`country_N`/`redirect_uri_N` + bouton extraction APK + OAuth
  2 étapes, boutons à ids suffixés (`stellantis_btGenererAuthUrl_N`…). Chaque section affiche un
  bandeau « lecture seule (pas de pilotage à distance) ».
- JS : généraliser les handlers existants pour lire `data-l1key="client_id_N"` et passer `slot: N` dans
  les `$.ajax`. L'état de connexion par section via `getTokenInfo($slot)`.
- **eqLogic** (`desktop/php/stellantis.php`) : afficher en **readonly** le compte du véhicule
  (`accountSlot` → « Compte principal » / « Compte secondaire N — lecture seule »). Autorité = découverte
  (comme `brand`) ; la fusion `utils::a2o()` préserve la clé même absente d'un champ éditable.

## Dépendances
Aucune (ni PHP, ni pip). `demond.py` inchangé.

## i18n (FR uniquement ; traduction déléguée étape 10)
Réutilise les clés existantes (« Marque », « Client ID », « Client Secret », « Pays », « URL de
redirection », boutons OAuth/APK…). **Nouvelles clés FR** : « Compte principal (pilotage à distance) »,
« Compte secondaire %s (lecture seule) », « Ce compte est en lecture seule : le pilotage à distance
(commandes) n'est disponible que sur le compte principal », « Configurez d'abord le compte principal
ci-dessus », « Connexion au compte principal », « Connexion au compte secondaire %s », « Compte du
véhicule », « Compte principal », « Compte secondaire %s — lecture seule ». (Les libellés dynamiques
passent par `sprintf(__('… %s …', __FILE__), $n)` — chaîne **littérale** dans `__()`.)

## Critères d'acceptation — traçabilité
- **AC1** (2 véhicules de comptes différents coexistent/rafraîchissent) : sync + cron par slot ;
  désactivation filtrée par slot ; cache token/quota/429 cloisonnés.
- **AC2** (token/realm/credentials = compte du véhicule) : `refreshTelemetry` route par `accountSlot` via
  `getApiConfig($slot)` + cache token par slot.
- **AC3** (multi-marques = cas particulier) : chaque slot porte sa `brand_N` → Peugeot slot 1 + Citroën
  slot 2 fonctionne par construction.
- **Limite assumée** (documentée UI + `53-tech.md`) : slots ≥2 = lecture seule (commandes/OTP slot 1).

## À valider en recette
- Expression : aucun véhicule d'un slot en échec de découverte n'est désactivé.
- Un 429 sur le compte 2 ne gèle pas le rafraîchissement du compte 1 (cloisonnement `RATELIMIT_KEY`).
- Chiffrement natif OK pour `client_secret_2/_3` (via `$_encryptConfigKey`).
- Fusion config `utils::a2o()` : `accountSlot` (absent du form éditable) préservé au Sauvegarder eqLogic.
