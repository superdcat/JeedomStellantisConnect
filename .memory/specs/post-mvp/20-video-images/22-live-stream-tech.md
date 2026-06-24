# Spec technique — post mvp 22 (flux live)

> Contrat API IMOU confirmé (doc §8 « Diffusion live », vérifiée 2026-06-20). Exposition de l'URL HLS
> dans une commande info `live_url` (option explicitement autorisée par la spec fonctionnelle l.15) ;
> **lecteur vidéo embarqué différé à UC25**. Tâche « la plus incertaine » → périmètre minimal robuste.

## Contrat API IMOU

Tous les appels via la brique `imouApi::callWithToken($method, $params)` (token + refresh gérés,
renvoie `result.data`).

- **`bindDeviceLive`** — params : `deviceId`, `channelId`, `streamId` (int : 0=HD principal, 1=SD).
  `liveMode` optionnel ("proxy") → **omis**. Réponse `data` : `liveToken`, `liveStatus`,
  `streams[]{ hls, coverUrl, streamId }`. **Prérequis** : crée la source live (persistante côté compte).
- **`getLiveStreamInfo`** — params : `deviceId`, `channelId`. Réponse `data.streams[]{ hls, streamId,
  status, liveToken }`. Nécessite un bind préalable.
- **`unbindLive`** — params : `liveToken`. Pas de `data`. Libère la ressource.
- Pas d'expiration documentée des URLs ; bindings persistants (quota non chiffré).

## Architecture

**1 seul fichier** : `core/class/imou.class.php`. Aucune dépendance, aucun JS.

1. **`createLiveCommands($logicalIdLog)`** (privé, classe `imou`), appelé **en dernier** dans
   `createCommands()` (après `createPtzCommands()` ; aucun lien `value` externe requis). Crée **sans
   gating** (capacité cloud universelle), idempotent via `creerCommande` :
   - info **`live_url`** — subType `string`, **NON pollable** (aucun marqueur `pollable`, jamais
     rafraîchie au cron : un bind consomme du quota), `visibleOnCreate => false` (hors socle UC16,
     cohérent économie de quota), non historisée.
   - action **`live_get`** — « Obtenir le flux live », `value` → id de `live_url`, `visibleOnCreate => false`.
   - action **`live_release`** — « Libérer le flux live », `value` → id de `live_url`, `visibleOnCreate => false`.
   Pas de cleanup (toujours présentes pour toute caméra).

2. **Helpers statiques (classe `imou`)** — centralisent appel cloud + parsing (comme `captureSnapshot`) :
   - `liveBind($deviceId, $channelId, $streamId)` → `callWithToken('bindDeviceLive', …)`, renvoie `data`.
   - `liveInfo($deviceId, $channelId)` → `callWithToken('getLiveStreamInfo', …)`, renvoie `data`.
   - `liveUnbind($liveToken)` → `callWithToken('unbindLive', ['liveToken'=>…])`.
   - `resolveHlsUrl($data, $streamId)` → parse `data['streams']` (défensif : array sinon ''),
     choisit l'entrée au `streamId` demandé sinon la 1re, renvoie son `hls` **assaini via
     `sanitizeImageUrl`** (garde http(s) générique : ≤2048, pas de caractères de cassure d'attribut —
     l'URL est injectée dans un attribut HTML). '' si rien d'exploitable.

3. **`execute()`** — nouvelle **branche (4)** insérée entre (2) PTZ et (3) switches du catalogue.
   Préfixe **`live_` réservé** (à documenter dans le commentaire d'ordre du routage) :
   ```php
   if (strpos($logicalId, 'live_') === 0) {
     if ($logicalId === 'live_get')      { $this->actionLiveGet($eqLogic); }
     elseif ($logicalId === 'live_release') { $this->actionLiveRelease($eqLogic); }
     else { /* live_* hors périmètre : log debug, no-op */ }
     return;
   }
   ```
   Disjonction : `live_` n'est utilisé ni par `iot_`/`ptz_` ni par le catalogue.

4. **`actionLiveGet($eqLogic)`** (privé, `imouCmd`) :
   - garde `deviceId` (sinon `imouException` CODE_CONFIG) ; `channelId` défaut `'0'`.
   - `cacheKey = "imou::live::<deviceId>_<channelId>"`.
   - `$token = cache` ; si `$token` : `try { $hls = resolveHlsUrl(liveInfo(...), STREAM_HD); }
     catch { $hls=''; cache::delete; }` (log debug sur échec).
   - si `$hls === ''` (pas de token, ou info vide/échec) : `$data = liveBind(deviceId, channelId,
     STREAM_HD)` ; `cache::set(key, $data['liveToken'], TTL)` (si liveToken non vide) ;
     `$hls = resolveHlsUrl($data, STREAM_HD)`.
   - `checkAndUpdateCmd('live_url', $hls)` (pas de save eqLogic → pas de récursion) ; log info FR.
   - échec `bindDeviceLive` → `imouException` remontée (Jeedom l'affiche).
   - **Rebind borné** : au plus 1 bind par exécution → un échec quota ne crée pas d'orphelin.

5. **`actionLiveRelease($eqLogic)`** (privé, `imouCmd`) :
   - `cacheKey` idem ; `$token = cache`. Si `$token` : `liveUnbind($token)` ; `cache::delete(key)`.
   - `checkAndUpdateCmd('live_url', '')` ; log info FR. Si pas de token : no-op tracé (debug).

Constantes : `STREAM_HD = 0` ; TTL cache liveToken `LIVE_TOKEN_TTL = 21600` (6 h).

## Server vs Client

100 % serveur. Le client n'est pas modifié : `live_url` est une info string standard (affichable,
copiable, exploitable par un lecteur HLS externe / widget). Lecteur embarqué = UC25.

## Validation

- Garde `deviceId` ; `streamId` **figé** (0) → aucune entrée utilisateur libre.
- Parsing `streams[]` défensif (array sinon '') ; URL HLS **assainie** http(s) avant stockage.
- Pas de récursion : `checkAndUpdateCmd` ne save pas l'eqLogic ; le `liveToken` est en **cache**
  (jamais en config → pas de `save()`/postSave).
- Erreurs `imouException` remontées telles quelles (comme `actionPtz`).

## Server Actions / API

Voir Architecture §2/§4/§5. Bind **à la demande** uniquement (jamais au cron). Réutilisation via cache
(évite les re-bind). `live_release` libère.

**Limite assumée (PHPDoc `actionLiveGet`)** : le `liveToken` est volontairement en cache (pas en config,
pour éviter `save()`/postSave récursif). Conséquence : un vidage de cache (redémarrage, TTL) avant
`live_release` peut laisser un **binding orphelin** côté IMOU → nettoyage via `liveList` prévu en UC
future (hors périmètre). Ne pas « corriger » en stockant le token en config (réintroduirait postSave).

## Dépendances

Aucune.

## i18n (FR source — traduction en/de/es différée à l'étape translator)

3 chaînes UI (libellés de commande, enveloppés via `__()` par `creerCommande`) :
- `Obtenir le flux live` (live_get)
- `Libérer le flux live` (live_release)
- `URL du flux live` (live_url)

## Scénarios de recette manuelle (à consigner dans 81-validation-manuelle.md)

1. `bindDeviceLive` réussit mais `streams[]` vide/absent → `live_url` vidée, pas de fatal.
2. `liveToken` en cache mais binding supprimé côté IMOU → `getLiveStreamInfo` échoue → rebind borné
   réussit → `live_url` à jour.
3. `live_release` sans binding préalable (cache vide) → no-op tracé, aucune erreur.
