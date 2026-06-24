# Spec technique — UC71 (statut online/offline & santé)

## Contrat API IMOU — `deviceOnline`
- **Requête** : `deviceOnline`, paramètre `deviceId` (string). Pas de `channelId` en entrée.
- **Réponse** : `result.data.onLine` (niveau device) **et** `result.data.channels[]` (objets
  `{channelId, onLine}`). Valeurs de `onLine` = **String** : `"0"`=offline, `"1"`=online,
  `"3"`=upgrading, `"4"`=sleeping (confirmé doc officielle
  `http/device/manage/query/deviceOnline.html`, fetch 2026-06-23).
- ⚠️ **Écart avec la spec fonctionnelle** : la spec annonçait un champ `online` à valeurs textuelles.
  Le contrat réel est le champ **`onLine`** (L majuscule), à **valeurs numériques string**. On
  s'aligne sur la doc officielle (fait foi sur le contrat).
- **Mapping binaire** : `online = 1` **ssi** `onLine == "1"` ; `"0"/"3"/"4"` → `0`. Le statut fin
  (sleep/upgrading distinct) est hors périmètre (future UC). Lecture **par canal** prioritaire (match
  du `channelId` de l'eqLogic dans `channels[]`), repli sur `data.onLine` (device).

## Architecture
Fichier unique modifié : `core/class/imou.class.php`.

1. **Commande info `online`** (binary) — SOCLE quasi-universel, créée par `createOnlineCommand()`
   appelée depuis `createCommands()`. Même pattern que `createLiveCommands`/`createPtzCommands`
   (commandes hors `commandCatalog`, qui ne couvre QUE les switches on/off/state). Toujours créée
   (pas de gating ability), jamais supprimée. Visible par défaut, historisée.
   - **SANS marqueur `pollable`** : la case UC73 « Exclure du rafraîchissement » n'apparaît pas. La
     poll de `online` est gérée séparément (`refreshOnline`) et **toujours active** (porte du
     skip-offline). Commentaire explicite : `refreshOnline()` ignore délibérément `pollDisabledSet()`.
2. **`refreshOnline()`** (nouvelle, NE LÈVE JAMAIS) : appelle `deviceOnline`, met à jour la cmd
   `online`, retourne `bool` (true = en ligne / indéterminable, false = offline confirmé).
   - **Fail-open** : double catch comme ailleurs — `imouException` (log avec code), `Throwable` (log
     sans code) ; les deux retournent `true` (simplification V1 documentée : on ne suspend pas tout
     le polling sur un blip de `deviceOnline`).
3. **`refreshStates()`** : appel de `refreshOnline()` **en premier** (après le guard `deviceId`). Si
   `false` → **court-circuit** : on saute camera-status, NVM et les 3 phases IoT, puis `return`. Gain
   quota direct (calque skip-offline de l'officiel HA).
4. **`syncEquipments()` — caméras disparues** : après la boucle de découverte (donc seulement si
   `discoverDevices()` a réussi — elle lève sinon), comparer les `logicalId` trouvés au cloud à ceux
   en base (`eqLogic::byType('imou')`). Pour un eqLogic **absent** du cloud → marquer non joignable
   (`setConfiguration('unreachable', 1)` + `checkAndUpdateCmd('online', 0)` si la cmd existe),
   **sans supprimer** ; retrouvés → `unreachable = 0`. **Garde** : ne pas marquer si la pagination a
   été tronquée (borne `DISCOVER_MAX_PAGES`) → `discoverDevices()` signale la complétude. Enregistrer
   `config::save('lastSync', time(), 'imou')`.
5. **`imou::health()`** (static, convention page Santé Jeedom : lignes
   `['test','result','advice','state']`, `state` booléen true=vert/false=rouge) :
   - Identifiants API (appId/appSecret renseignés).
   - Token IMOU valide (`imouApi::getToken(false)` en try/catch — lit le cache, ne brûle pas de quota).
   - Dernière synchronisation (`lastSync` → date lisible, ou « Jamais synchronisé »).
   - Une ligne par caméra : `En ligne` / `Hors ligne` / `Non joignable (disparue du compte)` selon
     la cmd `online` + le flag `unreachable`.

## Server vs Client
100 % serveur (PHP). Aucune entrée client : `deviceOnline` est appelé par le cron, `health()` par la
page Santé du core (admin). Pas de nouvel AJAX ni de JS.

## Validation
- Parsing défensif de `deviceOnline` via `modelGet` + type-checks (string), aucune confiance dans la
  forme de la réponse.
- Anti log-injection : neutralisation CRLF sur `deviceId`/`code` avant `log::add` (pattern existant).
- `refreshOnline` et le skip n'altèrent jamais le flux cron (try/catch, ne lève jamais).

## Server Actions / API
- `private function refreshOnline()` → `bool`.
- `private function createOnlineCommand($logicalIdLog)` (appelée dans `createCommands`).
- `public static function health()` → `array` de lignes.
- `discoverDevices()` : ajout d'un signalement de complétude (tronqué ou non) pour la garde
  unreachable — soit via un paramètre out, soit via une variante interne ; détail à l'implémentation.

## Dépendances
Aucune (PHP natif, endpoint cloud déjà accessible via `imouApi::callWithToken`).

## Chaînes i18n (FR — traduction différée étape 10)
`En ligne (état)` ; health : `Identifiants API`, `Renseignés`, `Manquants`, `Token IMOU`, `Valide`,
`Invalide ou injoignable`, `Dernière synchronisation`, `Jamais synchronisé`, `En ligne`, `Hors ligne`,
`Non joignable (disparue du compte)`, + conseils.
