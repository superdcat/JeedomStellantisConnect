# 02 — Client HTTP IMOU bas niveau

**Phase :** MVP · **Dépend de :** 01 · **Fichiers :** `core/class/imou.class.php` (classe utilitaire `imouApi`)

## Objectif
Centraliser **tous** les appels à l'IMOU Open API dans une seule brique : construction de
l'enveloppe signée, envoi HTTPS POST, parsing de la réponse, gestion uniforme des erreurs.

## Périmètre
- **Inclus** : signature, enveloppe `system`, requête cURL, décodage JSON, mapping des codes
  d'erreur, exception dédiée.
- **Exclu** : logique métier (token, devices…) qui appellera ce client.

## Détails techniques
- Classe statique ou service `imouApi` avec une méthode pivot :
  `imouApi::call(string $method, array $params, ?string $token = null): array`.
- Signature : `time = time();` `nonce = uniqid('', true)` (ou UUID v4) ;
  `sign = md5("time:$time,nonce:$nonce,appSecret:$appSecret")`.
- Enveloppe envoyée :
  ```json
  { "system": {"ver":"1.0","appId":"…","sign":"…","time":…,"nonce":"…"},
    "id": "<uuid>", "params": { … , "token": "<si fourni>" } }
  ```
- Transport : cURL `POST` vers `"$baseUrl/openapi/$method"`, header `Content-Type: application/json`,
  timeout court (ex. 10 s).
- Réponse IMOU : `{ "result": { "code":"0", "msg":"…", "data": {…} } }`.
  - Succès si `result.code === "0"` → retourner `result.data`.
  - Sinon lever `imouException($code, $msg)`.
- Codes à traiter explicitement (confirmés dans `faq/code.html`) :
  - `SN1005` = nonce dupliqué (≠ `SN1002` décalage d'horloge > 5 min, ≠ `SN1001` signature invalide) ;
  - token à relayer pour refresh en tâche 03 : `TK1002` (expiré/inexistant), `TK1003` (illégal),
    `OP1007` (token absent/vide) ;
  - quota/licence (5 appareils).
- `appSecret` déchiffré via `utils::decrypt` au moment de l'appel ; jamais loggué.

## Critères d'acceptation
- [ ] `imouApi::call('accessToken', [])` part avec une signature valide (vérifiable contre l'exemple de la doc).
- [ ] Une réponse `code != 0` lève une exception typée avec code+message exploitables.
- [ ] Les logs `debug` montrent méthode + params **sans** secret ni token en clair.
- [ ] Aucune autre partie du code ne fait d'appel HTTP IMOU en dehors de ce client.

## Notes / risques
- Vérifier le format exact de l'enveloppe selon la version d'API (présence de `id`, position de `token`).
- Horloge serveur : si `SN1005` fréquent, documenter la nécessité d'une synchro NTP.
