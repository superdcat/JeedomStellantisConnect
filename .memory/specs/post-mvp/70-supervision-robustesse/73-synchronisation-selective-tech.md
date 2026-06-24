# Spec technique — post mvp 73 (synchronisation sélective par caméra)

> Approche retenue (validée utilisateur 2026-06-18) : **B + lien onglet Équipement**.
> Le réglage se fait **par commande info**, dans l'onglet **Commandes** (case à cocher native), avec
> un **renvoi informatif** dans la section « Paramètres spécifiques » de l'onglet Équipement.
> Écart assumé vs l'esquisse de la spec fonctionnelle (qui plaçait des cases positives rendues
> dynamiquement dans l'onglet Équipement) : l'approche A imposait un endpoint AJAX + un rendu JS
> dynamique + un **hook de timing** fragile selon la version du core. B est natif, robuste, minimal,
> et satisfait tous les critères d'acceptation.

## Architecture

3 fichiers :
- `core/class/imou.class.php` — gating du polling + marqueur `pollable`.
- `desktop/js/imou.js` — case à cocher par commande info pollable.
- `desktop/php/imou.php` — ligne de renvoi informatif (onglet Équipement).

### Stockage du flag
- **`configuration.noPoll = 1`** sur la **commande info** = info **exclue** du rafraîchissement auto.
  **Défaut absent = pollé** → aucune régression, aucune migration des commandes existantes (cohérent
  pour TOUTES les infos, antérieures comme nouvelles). Flag **inversé** (coché = exclu) : choix
  délibéré vs un flag positif `doPoll`, qui afficherait à tort « décoché » les commandes créées avant
  l'UC (valeur absente) sans migration.
- **`configuration.pollable = 1`** : marqueur **structurel** (non éditable par l'utilisateur) posé à la
  CRÉATION de chaque commande info réellement pollée (les 3 phases). Sert au JS à n'afficher la case
  QUE sur une info pollable (jamais sur une info créée manuellement ni sur une action). Reposé à chaque
  re-sync (toujours `1`, inoffensif) ; `noPoll` n'est JAMAIS reposé par `creerCommande` → le choix
  utilisateur survit au re-sync.
- **Duplication d'eqLogic** : le core copie la `configuration` des commandes → `noPoll` est **hérité**
  par la caméra dupliquée. Acceptable et documenté (les caméras viennent de la synchro cloud, pas de la
  duplication ; l'ajout manuel d'équipement est désactivé côté page plugin).

## Server vs Client

- **Serveur** : tout le gating (3 phases de `refreshStates()`). Lecture du flag via la `configuration`
  des commandes info.
- **Client** : une case à cocher native (`cmdAttr` → sauvegarde par le core), aucune logique métier.

## Validation

- **Serveur** : gating purement défensif — `pollDisabledSet()` renvoie un set indexé par `logicalId` ;
  chaque phase saute une info présente dans le set. Aucune entrée utilisateur sensible (booléen de
  configuration). Invariants préservés : `refreshStates()` **ne lève jamais** (try/catch par appel
  inchangés) ; **anti-destruction** (modèle IoT null → no-op) ; pas de récursion.
- **Client** : aucune (case à cocher).

## Server Actions / API

Aucun nouvel appel cloud. La feature **filtre** les appels existants :

- **`imou::pollDisabledSet(): array`** (privé) — `cmd::byEqLogicId($this->getId())` (1 requête/caméra/
  cycle, négligeable face aux appels HTTP de polling qui dominent ; 1 requête groupée < N `getCmd`).
  Renvoie `[<logicalId info> => true]` pour les commandes **info** avec `configuration.noPoll` vrai.
- **`refreshStates()`** — construit le set une fois en tête, puis :
  - **phase switches** : `if (isset($set[$entry['state']]))` → `continue` **avant** l'appel
    (`getDeviceCameraStatus` évité) + log debug.
  - le set est passé aux deux phases IoT.
- **`refreshIotProperties($deviceId, $model, $pollDisabled = array())`** — exclut du lot les `ref`
  dont le `stateLogicalId` ∈ set ; **si le lot est vide → pas d'appel** `getIotDeviceProperties`.
- **`refreshIotServiceStatuses($deviceId, $model, $pollDisabled = array())`** — `if (isset($set[$entry['info']]))`
  → `continue` **avant** l'appel (`iotDeviceControl` évité) + log debug.
- **Marqueur `pollable=1`** ajouté aux options `configuration` des `creerCommande` info dans
  `createCommands()` (switches), `createIotCommands()` (propriétés IoT) et `createIotStatusCommands()`
  (statuts de service).

### Conséquences quota (critères d'acceptation)
- Décocher (cocher `noPoll` sur) tous les états switches d'une caméra ⇒ **0 `getDeviceCameraStatus`**.
- Exclure toutes les propriétés IoT ⇒ **0 `getIotDeviceProperties`** (lot vide).
- Exclure un statut de service ⇒ **1 `iotDeviceControl` en moins**.

## Dépendances

Aucune.

## i18n (FR source — traduction en/de/es différée à l'étape translator)

- `desktop/js/imou.js` : `Exclure du rafraîchissement automatique` (libellé de la case) ;
  `Cocher = cette info n'est plus rafraîchie au cron. La commande reste actionnable et rafraîchissable manuellement.` (tooltip).
- `desktop/php/imou.php` : `Synchronisation sélective` (label) ; `Choisir quelles infos sont rafraîchies automatiquement se règle par commande, dans l'onglet « Commandes » (case « Exclure du rafraîchissement automatique »).` (aide).

## Notes / risques

- **Articulation `autorefresh`** : `autorefresh` règle *quand* (cadence), cette UC règle *quoi*
  (périmètre). Le gating est dans `refreshStates()` → s'applique quel que soit le chemin de cron
  (`cron()` pour les caméras avec `autorefresh`, `cron5()` pour les autres).
- **Dégradation gracieuse** : la liste des cases = les commandes info **existantes** (pas le modèle
  IoT) → si le modèle IoT est momentanément indisponible, les cases des `iot_*_state` déjà créées
  restent affichées et leurs flags respectés ; aucun flag n'est perdu/écrasé.
- **UC71 (online/santé)** : l'info `online`, quand elle existera, pourra recevoir le marqueur
  `pollable` (sélectionnable) ou en être exclue (toujours pollée car structurante) — à arbitrer alors.
