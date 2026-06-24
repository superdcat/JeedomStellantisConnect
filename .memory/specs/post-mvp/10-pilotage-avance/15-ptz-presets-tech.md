# Spec technique — post mvp 15 (PTZ : contrôle de l'orientation)

> Voie retenue (validée utilisateur 2026-06-18) : **`controlMovePTZ` (HTTP) UNIVERSEL** (IoT + non-IoT).
> Écart assumé vs la spec fonctionnelle (qui proposait un hybride IoT `PtzStepMoveFour`) :
> les valeurs enum `Operator`/`Zoom` de `PtzStepMoveFour` sont model-specific et **non documentées**
> (résolution fragile), alors que `controlMovePTZ` a un **contrat figé et documenté**. Repli IoT =
> **UC future** si un appareil IoT renvoie 40999 sur `controlMovePTZ` (cf. § Risques).

## Architecture

Un seul fichier : `core/class/imou.class.php`. Aucune dépendance, aucun nouvel endpoint à whitelister
(`imouApi::callWithToken('controlMovePTZ', …)` → POST `baseUrl/openapi/controlMovePTZ`).

- **`imou::ptzCatalog()`** (static) — catalogue des 6 commandes action PTZ, source de vérité unique
  (création + routage + nettoyage). Une entrée = `logicalId => ['operation' => '<code>', 'name' => '<FR>',
  'ability' => [<flags>]]`. logicalIds **tous préfixés `ptz_`** (routage par UN seul `strpos`) :
  - `ptz_up` (op `0`), `ptz_down` (op `1`), `ptz_left` (op `2`), `ptz_right` (op `3`) — directionnel,
    `ability` ∈ {`PT`, `PTZ`, `PT1`, `PT2`}.
  - `ptz_zoom_in` (op `8`), `ptz_zoom_out` (op `9`) — zoom, `ability` ∈ {`PTZ`, `ZoomFocus`}
    (PT1/PT2 = sans zoom → exclus).
  - Le champ `ability` est lu du **même CSV `ability`** de l'eqLogic que `commandCatalog()`, via le
    **même** `switchApplicable()` (tri-état) — pas de second mécanisme de gating.
- **`imou::PTZ_DURATION_MS = 500`** (const) — durée du mouvement en ms. `controlMovePTZ` **borne** le
  mouvement à `duration` (doc : "Movement duration in milliseconds") → nudge auto-arrêté ; **pas** de
  commande `stop` (op 10) nécessaire (le mode continu indéfini n'est pas utilisé).
- **`imou::createPtzCommands($logicalIdLog)`** — appelée par `createCommands()` après les phases IoT.
  Pour chaque entrée : `switchApplicable()` → `true` crée l'action (`creerCommande(type='action',
  subType='other')`), `false` supprime la commande si présente, `null` (CSV ability vide) ne touche
  à rien (anti-destruction). Nettoyage borné à la **liste exacte** des logicalId de `ptzCatalog()`
  (pas de regex/préfixe générique) → aucune commande tierce touchée.
- **`imouCmd::execute()`** — ordre des branches **documenté** : (1) `iot_*_set` → `actionIotProperty`,
  (2) `ptz_*` → `actionPtz`, (3) boucle `commandCatalog()` (switches). logicalIds disjoints.
- **`imouCmd::actionPtz($eqLogic, $entry)`** — garde `deviceId` (CODE_CONFIG si vide), `channelId`
  (défaut `'0'` si vide : param **requis** par `controlMovePTZ`, non omissible ; `'0'` = canal canonique
  mono-canal), appel `controlMovePTZ` (`operation` + `duration` figés par le catalogue), log info +
  remontée d'erreur (neutralisation CRLF). Momentané : **aucun état** à mettre à jour.

## Server vs Client

100 % serveur (PHP, `cmd::execute()`). Boutons d'action sans paramètre → aucune logique client.

## Validation

- **Serveur** : whitelist **dérivée de `ptzCatalog()`** (logicalId reconnu via le catalogue) ;
  `operation`/`duration` **figés par le catalogue** (jamais d'entrée utilisateur) ; `deviceId` requis ;
  gating `ability` (`switchApplicable`) **avant création**.
- **Client** : aucune.

## Server Actions / API

### Endpoint `controlMovePTZ` (confirmé `http/device/operate/controlMovePTZ.html`, 2026-06-18)
- Params : `token` (auto via `callWithToken`), `deviceId` (String), `channelId` (String, **requis**),
  `operation` (String, voir codes), `duration` (Long, ms).
- `operation` : `0`=haut, `1`=bas, `2`=gauche, `3`=droite, `4`=haut-gauche, `5`=bas-gauche,
  `6`=haut-droite, `7`=bas-droite, `8`=zoom avant, `9`=zoom arrière, `10`=stop.
- Réponse : **pas de `data`** (succès `code "0"`, déjà géré par `imouApi::call`).

### Signatures
- `imou::ptzCatalog(): array`
- `imou::createPtzCommands(string $logicalIdLog): void`
- `imouCmd::actionPtz(eqLogic $eqLogic, array $entry): void` (lève `imouException` si config
  incomplète ou échec API)

## Dépendances

Aucune.

## i18n (FR source — traduction en/de/es différée à l'étape translator)

6 clés `__()` (noms de commande) : `PTZ haut`, `PTZ bas`, `PTZ gauche`, `PTZ droite`,
`Zoom avant`, `Zoom arrière`.

## Risques

- **`controlMovePTZ` sur IoT non validé** : un appareil IoT « Things » pourrait verrouiller l'API HTTP
  (cf. `siren` read-only → 40999). Si le test sur la Cruiser 2C renvoie 40999, ajouter un repli IoT
  (`PtzStepMoveFour` via `iotDeviceControl`, valeurs `Operator`/`Zoom` résolues depuis `getProductModel`)
  en UC de suivi. À **valider en recette manuelle** (`.memory/specs/post-mvp/80-livraison/81-…`).
- **Presets/Collection** : hors périmètre de cette UC (option spec, gating futur `CollectionPoint`).
