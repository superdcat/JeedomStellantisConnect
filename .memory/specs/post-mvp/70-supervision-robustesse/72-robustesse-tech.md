# Spec technique — post mvp 72 (robustesse)

> Périmètre **restant** : la redirection datacenter `currentDomain` est déjà livrée (commit `fb37ff2`).
> Cette UC complète : **retries bornés + backoff**, **table codes→messages FR actionnables**,
> **id de corrélation dans les logs**, et le **point 8** (anti-toast-storm quota en polling).
> Décision assumée : **pas de throttle QPS** (la doc `faq/limit.html` ne définit aucune limite
> par seconde/minute, uniquement des plafonds journaliers par endpoint).

## Architecture
Tout est confiné à `core/class/imou.class.php`, couche `imouApi` + quelques call-sites.

- **`imouApi::call()`** devient une **boucle de tentatives** (max `CALL_MAX_ATTEMPTS = 3` → 1 essai + 2 rejeux).
  L'attempt unitaire est extrait dans un privé **`callOnce()`** : l'enveloppe (time/nonce/sign/`id` uuid)
  est **reconstruite à chaque tentative** (corrige `SN1005`).
- **Classification du transport** dans `callOnce()` : `errno≠0` (réseau) ou **HTTP 5xx** → `CODE_TRANSPORT`
  (rejouable) ; **HTTP 4xx** non-JSON → `CODE_REPONSE` (NON rejouable) ; HTTP 200 non-JSON → `CODE_REPONSE`.
- **Backoff** : `usleep` 150 ms puis 400 ms (≤ 550 ms cumulés/appel → tolérable en cron synchrone).
- **Id de corrélation** `cid` (8 hex) généré **une fois** par `call()`, stable sur les rejeux, préfixe
  chaque ligne de log (debug/retry/error) + n° de tentative.
- **Table `messageUtilisateur($code, $fallback)`** : map code IMOU/interne → message FR actionnable,
  appliquée au throw. Codes inconnus → repli sur le `msg` brut API (sanitizé). `getImouCode()` préservé.

## Server vs Client
100 % serveur (PHP). Aucun front, aucun AJAX, aucune nouvelle commande. Robustesse interne au pivot.

## Validation
- Méthode IMOU : regex inchangée (`^[a-zA-Z][a-zA-Z0-9_]*$`).
- Config (appId/appSecret) : check inchangé en tête de `call()`.
- `currentDomain` forgé : garde regex host existante (inchangée).
- Backoff borné + nb de tentatives borné : aucun risque de boucle infinie.

## Server Actions / API
### Rejeu — quels codes
| Catégorie | Codes | Rejeu |
|---|---|---|
| Réseau / 5xx | `CODE_TRANSPORT` | oui **si `$idempotent`** (timeout = exécution possible côté serveur) |
| Nonce dupliqué | `SN1005` (`CODE_NONCE`) | **toujours** (rejet AVANT exécution → sûr même non-idempotent) |
| Surcharge | `OP1026`, `DV1009`, `DV1010` | **toujours** (rejet AVANT exécution → sûr) |
| Token | `TK1002/TK1003/OP1007` | **non** dans `call()` (géré par `callWithToken` : refresh + rejeu unique) |
| Fonctionnel | quota jour, horloge, signature, params, droits, offline, inconnu | **non** (throw immédiat) |

### Idempotence
- `call($method, $params, $token, $silentCodes, $idempotent = true)` et
  `callWithToken($method, $params, $silentCodes, $idempotent = true)`.
- Appels **non-idempotents** passant `$idempotent = false` : `actionPtz` (controlMovePTZ),
  `actionIotService` (sirène, via `callIotService(..., $idempotent=false)`).
- Tous les autres (lectures, `setDeviceCameraStatus`, `setIotDeviceProperties`, `setNightVisionMode`)
  sont idempotents → défaut `true`.

### Point 8 — anti-toast-storm sur quota journalier (OP1011/OP1014)
- `imouApi::QUOTA_CODES = array('OP1011','OP1014')` + `imouApi::isQuotaError($code)`.
- Les **lectures de polling** (`lireEtatSwitch`, `refreshOnline`, `refreshNightVisionMode`,
  `refreshIotProperties`, `refreshIotServiceStatuses`, `refreshIotBoolProperties`) passent
  `QUOTA_CODES` en `silentCodes` → le log de `call()` repasse en `debug` (pas de message/toast Jeedom).
  Les **actions utilisateur** ne passent PAS silent → l'utilisateur garde un toast d'erreur clair.
- `refreshStates` : si la boucle switch rencontre un code quota, log **une seule** synthèse `warning`
  et `return` (inutile de poursuivre le cycle — quota épuisé pour la journée ; économise des appels).

### Table messages FR (clés `__()` introduites)
- `OP1011`/`OP1014` : « Quota d'appels IMOU atteint pour aujourd'hui : réessayez demain ou réduisez la fréquence de rafraîchissement. »
- `OP1026`/`DV1009` : « API IMOU momentanément surchargée : réessayez dans un instant. »
- `OP1009` : « Droits insuffisants pour cette opération côté IMOU (vérifiez votre licence/abonnement). »
- `DV1002` : « Appareil introuvable côté IMOU : resynchronisez vos caméras. »
- `DV1007`/`DV1020` : « Caméra hors ligne. »
- `OP1002`/`OP1003`/`OP1004` : « Requête refusée par l'API IMOU (paramètre manquant ou invalide). »
- `SN1002` : « Horloge du serveur Jeedom désynchronisée (écart > 5 min) : synchronisez l'heure (NTP). »
- `SN1001` : « Signature IMOU invalide : vérifiez l'appId/appSecret du plugin. »
- + wrapping `__()` des messages internes du pivot déjà existants (config/transport/réponse).

## Dépendances
Aucune. PHP natif (`usleep`, `random_bytes`, `cache`).
