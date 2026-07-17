# Spec technique — 76 (Synchronisation sélective par véhicule)

> Feature **100 % locale** : aucun appel REST/MQTT nouveau, aucun contrat API Stellantis/PSA touché,
> aucune dépendance. Deux concepts **orthogonaux** :
> - `syncEnabled` (config eqLogic, bool, défaut 1) = **choix utilisateur** « inclure dans le
>   rafraîchissement auto » — l'eqLogic reste **activé** (`isEnable=1`), simplement non interrogé par le
>   cron.
> - `isEnable` (état core) = géré par le plugin pour la **disparition** : un véhicule absent du compte est
>   **désactivé** (jamais supprimé), et **réactivé** s'il réapparaît.

## Architecture

Fichiers touchés (tous existants — aucune nouvelle classe/fichier, donc aucun risque autoload) :

### 1. `core/class/stellantis.class.php`

**a) Nouvelle propriété statique + helper**
- `private static $synchroEnCours = false;` — garde transitoire (portée requête) posée autour des `save()`
  de `syncVehicles()` pour que `preSave()` distingue une bascule `isEnable` **pilotée par la synchro** d'une
  bascule **manuelle** de l'utilisateur.
- `public static function assurerSyncEnabledParDefaut(eqLogic $_eqLogic): bool` — **miroir exact**
  d'`assurerVisiblePanelParDefaut()` : pose `syncEnabled=1` uniquement si la clé est absente
  (`trim((string)getConfiguration('syncEnabled','')) === ''`), retourne `true` si modifié (le caller
  mutualise le `save()`). Public/statique (appelée aussi par `install.php`, point d'entrée externe).

**b) `cron()` (boucle par véhicule, ~L4180-4259)**
- **Auto-wakeup UC73** (actuel `if ($slot == 1) { $eqLogic->declencherAutoWakeupSiDu(); }`) : gardé
  **aussi** par `syncEnabled` → `if ($slot == 1 && $eqLogic->getConfiguration('syncEnabled', 1)) { … }`
  (un véhicule exclu du refresh ne doit jamais être réveillé en MQTT — sinon réveils batterie 12 V inutiles,
  leur refresh consécutif ne serait de toute façon jamais consommé).
- **Bloc `CMD_PENDING`** (refresh post-ack UC18) : **INCHANGÉ, NON gaté** — un refresh post-commande est la
  conséquence d'une **action explicite** de l'utilisateur (wakeup/lock/charge manuel), pas du polling
  périodique. Gater incohérent (on bloquerait la conséquence d'une action qu'on n'a pas bloquée à la source
  dans `stellantisCmd::execute()`).
- **Gate `syncEnabled`** : `if (!$eqLogic->getConfiguration('syncEnabled', 1)) { continue; }` placé
  **APRÈS** le bloc `CMD_PENDING` et **AVANT** la branche cadence (`autorefresh`/anti-rafale UC72). Exclut le
  polling périodique. Lecture défensive calquée sur `panel.php:38` (`!getConfiguration(...,1)` — défaut 1 =
  opt-out ; `"0"` est falsy en PHP ⇒ décoché ⇒ skip).

**c) `syncVehicles()` (~L587-723)**
- Poser `self::$synchroEnCours = true;` **au début**, remis à `false` dans un **`finally`** englobant toute la
  méthode (couvre tous les `save()` : découverte, réactivation, désactivation, ceux internes de
  `createCommands()`/`refreshTelemetry()`). ⚠️ `discoverVehicles()` peut lever `stellantisException` (déjà
  try/catch par slot) : le `finally` garantit la remise à `false` même sur exception.
- **Branche « existant »** (~L635-648) : remplacer le seul `if (!$eqLogic->getIsEnable()) { $reactivables++; }`
  par :
  - si **désactivé** :
    - `(int)$eqLogic->getConfiguration('autoDisabled', 0) === 1` → **réactivation auto** :
      `setIsEnable(1)` + `setConfiguration('autoDisabled', 0)` + `$reactives++` (nouveau compteur).
    - sinon (désactivation **manuelle** ou héritée d'avant UC76 = **pas de marqueur**) → **laisser
      désactivé** : `$reactivables++` (comportement actuel inchangé — pas de réactivation en masse à
      l'upgrade).
  - si **activé** : effacer un éventuel marqueur résiduel — `if ((int)$eqLogic->getConfiguration('autoDisabled', 0) === 1) { setConfiguration('autoDisabled', 0); }`
    (invariant : le marqueur ne vaut 1 que pour un véhicule que le plugin a désactivé et non réactivé
    manuellement depuis).
- **Défaut `syncEnabled`** : appeler `self::assurerSyncEnabledParDefaut($eqLogic)` **à côté** de
  `assurerVisiblePanelParDefaut($eqLogic)` (~L669), avant l'unique `save()` de la boucle (pas de 2ᵉ write).
- **Boucle de désactivation** (~L697-707) : à `setIsEnable(0)`, ajouter
  `setConfiguration('autoDisabled', 1)` **avant** le `save()`.
- **Message de retour** : ajouter le compteur `$reactives` (`sprintf(__('%d véhicule(s) réapparu(s)
  réactivé(s) automatiquement', __FILE__), $reactives)` quand `> 0`) ; le bloc `reactivables` (réapparus
  laissés désactivés = manuels) reste **inchangé**. Ajouter la clé `'reactivated' => $reactives` au tableau
  de retour (informatif ; le JS n'affiche que `message`).

**d) `preSave()` (~L4865, aujourd'hui vide)**
```
public function preSave() {
  // UC76 : refléter IMMÉDIATEMENT toute bascule MANUELLE de l'état d'activation (formulaire OU toggle
  // rapide de la liste des équipements — les deux passent par save()→preSave). Une intervention manuelle
  // sur isEnable exprime un choix explicite ⇒ efface le marqueur autoDisabled (sinon une réactivation
  // manuelle suivie d'une redésactivation manuelle laisserait le marqueur périmé et un futur
  // syncVehicles() réactiverait contre la volonté de l'utilisateur). Pilotée par $synchroEnCours pour ne
  // PAS interférer avec les bascules faites par syncVehicles() lui-même (qui gère le marqueur explicitement).
  // eqLogic::byId() fait une requête DB FRAÎCHE (vérifié en source core) ⇒ retourne ici l'ANCIENNE valeur
  // persistée (preSave s'exécute avant l'UPDATE). Défensif : ne lève JAMAIS (un throw bloquerait le save()).
  if (self::$synchroEnCours) { return; }
  try {
    if ($this->getId() == '') { return; } // création : pas d'ancien état
    $ancien = eqLogic::byId($this->getId());
    if (is_object($ancien) && $ancien->getIsEnable() != $this->getIsEnable()) {
      $this->setConfiguration('autoDisabled', 0);
    }
  } catch (\Throwable $e) {
    // Fail-safe : au pire le marqueur n'est pas effacé (dégradation vers le comportement documenté).
  }
}
```

**e) `health()` (boucle par véhicule, ~L1728)**
- Ajouter, **après** le bloc `last_command_result` et **avant** le bloc privacy (les deux terminent par
  `continue`), une branche pour un véhicule exclu (il reste `isEnable=1` ⇒ apparaît en Santé, sinon son
  ancienneté croîtrait sans explication) :
```
if (!$eqLogic->getConfiguration('syncEnabled', 1)) {
  $lignes[] = array(
    'test'   => $nom,
    'result' => __('Rafraîchissement automatique désactivé', __FILE__),
    'advice' => __('Ce véhicule est exclu du rafraîchissement périodique (choix utilisateur) : ce n\'est pas une erreur', __FILE__),
    'state'  => true,
  );
  continue;
}
```

### 2. `desktop/php/stellantis.php`
- Ajouter, près du champ « Auto-actualisation » (~L219), un `form-group` checkbox **calqué sur
  `isVisiblePanel`** (défaut `checked`) :
```
<div class="form-group">
  <label class="col-sm-4 control-label">{{Rafraîchissement automatique}}
    <sup><i class="fas fa-question-circle tooltips" title="{{Inclure ce véhicule dans le rafraîchissement périodique automatique. Décochez pour l'exclure (économie de quota / anti-ban) sans le supprimer : ses dernières valeurs sont conservées.}}"></i></sup>
  </label>
  <div class="col-sm-6">
    <label class="checkbox-inline">
      <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="syncEnabled" checked>
    </label>
  </div>
</div>
```
- `autoDisabled` = marqueur **serveur uniquement**, **PAS** de champ formulaire (précédent `image::*` UC52 :
  survit au save via le merge `utils::a2o()` clé-par-clé ; un effacement éventuel = dégradation **fail-safe**
  vers « pas de réactivation auto »). → **point de recette** à confirmer.

### 3. `plugin_info/install.php` (`stellantis_update()`)
- Ajouter une boucle de backfill **indépendante** (try/catch par équipement, `eqLogic::byType('stellantis')`
  sans `onlyEnable`) appelant `stellantis::assurerSyncEnabledParDefaut($eq)` + `save()` si modifié — même
  pattern que les backfills UC32/UC54 déjà présents.

## Server vs Client
100 % **serveur** (config eqLogic + logique cron/sync/preSave). Côté client : une **checkbox native**
(sérialisée 0/1 par le core, aucune saisie libre) — rien à valider en JS.

## Validation
- **Client** : aucune (checkbox).
- **Serveur** : lecture défensive `getConfiguration('syncEnabled', 1)` (défaut ON = opt-out) partout ;
  marqueur lu en `(int)getConfiguration('autoDisabled', 0) === 1`. Aucun clamp/parse (booléens).

## Server Actions / API
Aucune nouvelle action AJAX. L'action `sync` existante (`stellantis::syncVehicles()`) enrichit son message
(compteur `$reactives`). Signatures nouvelles :
- `public static function assurerSyncEnabledParDefaut(eqLogic $_eqLogic): bool`
- `private static $synchroEnCours = false;`
- `preSave()` (déjà déclarée, corps ajouté).

## Dépendances
Aucune.

## Décisions assumées (à documenter dans le code + reviews)
1. **Réactivation auto ciblée** : seuls les véhicules que le plugin a lui-même désactivés (`autoDisabled==1`)
   sont réactivés à leur réapparition. Une désactivation **manuelle** est respectée (jamais réactivée).
   Véhicules désactivés **avant UC76** (sans marqueur) = traités comme manuels ⇒ **pas de réactivation en
   masse** à l'upgrade. (Résout la tension AC2 littéral vs préservation du choix admin — cf. « À confirmer »
   de la spec : véhicule vendu = purge manuelle par l'utilisateur.)
2. **`syncEnabled` gate** : exclut le **polling périodique** (cron) **et** l'auto-wakeup UC73 ; **n'exclut
   PAS** le refresh post-commande (`CMD_PENDING`, UC18) — conséquence d'une action explicite.
3. **Sync manuel** (bouton « Synchroniser ») rafraîchit **tous** les véhicules découverts, y compris ceux à
   `syncEnabled=0` : c'est une action explicite ponctuelle, pas le « rafraîchissement auto » visé par AC1
   (le cron). `syncVehicles()` reste inchangé sur ce point.
4. **Outil recommandé pour « garder un véhicule silencieux sans le supprimer »** : `syncEnabled=0` (l'eqLogic
   reste activé mais non interrogé). Un véhicule vendu/à retirer = suppression manuelle (il ne sera recréé
   que s'il réapparaît côté compte).

## Chaînes UI FR introduites (traduction différée — étape translator)
- `{{Rafraîchissement automatique}}`
- `{{Inclure ce véhicule dans le rafraîchissement périodique automatique. Décochez pour l'exclure (économie de quota / anti-ban) sans le supprimer : ses dernières valeurs sont conservées.}}`
- `__('Rafraîchissement automatique désactivé', __FILE__)`
- `__('Ce véhicule est exclu du rafraîchissement périodique (choix utilisateur) : ce n\'est pas une erreur', __FILE__)`
- `__('%d véhicule(s) réapparu(s) réactivé(s) automatiquement', __FILE__)`

## Critères d'acceptation → couverture
- **AC1** (exclure du refresh auto sans supprimer) : checkbox `syncEnabled` + gate cron (polling +
  auto-wakeup) + ligne Santé. L'eqLogic reste activé/visible.
- **AC2** (véhicule disparu désactivé, pas supprimé ; revient s'il réapparaît) : désactivation déjà en place
  (filtrée par compte) + marqueur `autoDisabled` ⇒ réactivation auto à la réapparition, robuste aux bascules
  manuelles (via `preSave`).
