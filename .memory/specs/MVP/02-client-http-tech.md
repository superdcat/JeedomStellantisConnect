# Spec technique — MVP 02 — Client HTTP IMOU bas niveau

> Spec fonctionnelle : `02-client-http.md`. Plan validé après challenge `code-reviewer` (advisor).
> Dépend de MVP 01 (`imou::getApiConfig()`).

## Architecture

Brique unique centralisant **tous** les appels HTTP à l'IMOU Open API. Ajout de **2 classes**
dans `core/class/imou.class.php` (pas de fichier dédié — cohérent avec la disposition du plugin) :

| Symbole | Type | Rôle |
|---|---|---|
| `imouException extends Exception` | exception typée | `getCode()` = code IMOU (string), `getMessage()` = msg. Constantes de codes d'erreur connus. |
| `imouApi` | classe statique | Méthode pivot `call()` + helpers privés (signature, uuid, redaction). |

Aucune UI, aucune route AJAX, aucune dépendance externe.

## Server vs Client

**Server intégral.** Signature/secret/cURL ne quittent jamais le serveur. C'est la « brique unique »
imposée par l'architecture : aucun autre code ne fait d'appel HTTP IMOU.

## Validation

- **Client** : sans objet (pas d'UI).
- **Serveur** (dans `call()`, dans l'ordre) :
  1. **Config** : `imou::getApiConfig()` ; si `appId` ou `appSecret` vide → `imouException` « non configuré ».
  2. **Transport** : `curl_errno() !== 0` → `imouException` erreur de communication (errno/erreur cURL en détail).
  3. **Décodage** : `json_decode` ; si `null`/non tableau → si HTTP ≠ 200 → exception transport HTTP `$httpCode` ; sinon « réponse inattendue ».
  4. **Structure** : absence de `result` → « réponse inattendue ».
  5. **Métier** : `(string)$result['result']['code'] !== '0'` → `imouException($code, $msg)`. Sinon retourne `result.data` (tableau, `array()` si absent).

> On NE se fie PAS au seul code HTTP pour détecter les erreurs applicatives : IMOU renvoie souvent
> HTTP 200 + `result.code` ≠ 0. Le body `result.code` fait foi.

## Server Actions / API

API interne PHP (pas de route AJAX) :

```php
// Pivot : tout appel IMOU passe par là. Lève imouException en cas d'échec.
imouApi::call(string $method, array $params = array(), ?string $token = null): array;

// Reconnaît les codes "token invalide/expiré" pour déclencher un refresh en tâche 03.
// NB : SN1005 (décalage horloge/nonce) n'EST PAS un token error → exclu volontairement.
imouApi::isTokenError(string $code): bool;

class imouException extends Exception {}              // getCode()=code IMOU, getMessage()=msg
```

Helpers privés de `imouApi` :
- `buildSign(int $time, string $nonce, string $appSecret): string` — `md5("time:$time,nonce:$nonce,appSecret:$appSecret")`.
- `uuidv4(): string` — vrai UUID RFC 4122 v4 (`random_bytes(16)` + bits version `0x40` / variant `0x80`).
- `redactParams(array $params): array` — masque `token`, `appSecret`, `sign`, `password` avant log.

### Détails transport
- URL : `"$baseUrl/openapi/$method"`.
- Enveloppe : `{ "system": {"ver":"1.0","appId":…,"sign":…,"time":…,"nonce":…}, "id":<uuidv4>, "params": { …, "token"?:… } }`.
  Le `token` n'est ajouté à `params` que s'il est fourni (non null/non vide).
- cURL : `POST`, header `Content-Type: application/json`, body = `json_encode(envelope)`,
  `CURLOPT_RETURNTRANSFER=true`, `CURLOPT_CONNECTTIMEOUT=5`, `CURLOPT_TIMEOUT=10`,
  `CURLOPT_SSL_VERIFYPEER=true`, `CURLOPT_SSL_VERIFYHOST=2`.

### Logs (français source, jamais de secret)
- `log::add('imou','debug', 'imouApi::call ' . $method . ' params=' . json_encode(redactParams($params)))`.
- Sur exception : `log::add('imou','error', …)` avec code + msg, **jamais** sign/token/appSecret.

## Dépendances

**Aucune.** cURL, `random_bytes`, `json_*` natifs PHP (Jeedom `require 4.2`). `packages.json` inchangé.

## i18n

**Aucun impact.** Les messages d'exception restent en français source **sans `__()`** (catchés/loggués
côté serveur, pas affichés directement). La traduction d'un éventuel message remonté en UI sera faite
au niveau du contrôleur AJAX (tâche 04), pas dans l'exception. → Rien à produire à l'Étape 10.

## Points à valider sur instance / doc IMOU réelle

1. Format exact de l'enveloppe (présence de `id`, position du `token` dans `params`).
2. `result.code` toujours string ? (la normalisation `(string)` couvre les deux cas).
3. **Codes d'erreur exacts** pour `isTokenError()` (token invalide/expiré) et `SN1005` — à confirmer
   dans la référence des error codes IMOU avant que la tâche 03 ne s'appuie dessus.
4. Si `SN1005` fréquent → documenter la synchro NTP du serveur Jeedom.

## Critères d'acceptation (rappel)

- [ ] `imouApi::call('accessToken', [])` part avec une signature valide.
- [ ] `code != 0` lève une exception typée (code + msg exploitables).
- [ ] Logs `debug` = méthode + params **sans** secret ni token.
- [ ] Aucun appel HTTP IMOU hors de cette brique.
