# Spec technique — 43 (Alertes véhicule — AdBlue, lave-glace, voyants, révision)

> Compagnon technique de `43-alertes-vehicule.md`. Plan validé le 2026-07-13.
> 100 % PHP lecture/parsing. **AUCUN nouvel appel REST/MQTT** : UC43 **ÉTEND** le poller `/alerts`
> déjà livré par UC42 (`suivreAlertes()`/`parseAlertes()`) — interdiction formelle d'un 2ᵉ poller/cache.
> Aucun JS/AJAX, aucun formulaire, aucune dépendance. Tout dans `core/class/stellantis.class.php`.

## Contrat API Stellantis/PSA (réutilisé d'UC42, vérifié 2026-07-13)

Aucun contrat neuf : UC43 consomme la **même** réponse `GET /user/vehicles/{apiId}/alerts` déjà récupérée
et parsée par UC42. Rappel (cf. `42-tech.md` § contrat, `stellantis-data-model.md` § 3) :
- Wrapper HAL multi-shape `_embedded.alerts[]` → `alerts[]` → racine=liste. Chaque alerte =
  `{ id, type (AlertMsgEnum string), active (bool), started_at, end_at }`.
- **AlertMsgEnum ~80 types** (pneus, moteur, carburant/AdBlue, freinage, ouvrants, éclairage…) —
  catalogue **variable, best-effort, NON figé** (à recouper en recette avec l'intégration HA).
- Sémantique **fail-closed** sur `active` (absente ⇒ inactive) et comparaison **insensible à la casse** :
  déjà portées par `parseAlertes()`, **inchangées**.
- Disponibilité runtime **NON garantie** (ni `psa_car_controller` ni HA ne lisent réellement `/alerts`) →
  best-effort, throttle 1 h/7 j (403/404)/3 h porté par `suivreAlertes()`, **inchangé**.

`parseAlertes()` renvoie déjà la **liste GÉNÉRIQUE** des `type` d'alertes actives (chaînes brutes, pas
seulement pneus) : UC43 la **réutilise telle quelle**, jamais un second parseur.

## Architecture

Tous les changements dans `core/class/stellantis.class.php` (aucune nouvelle classe/fichier ⇒ aucun
risque d'autoload). Aucun changement de `desktop/php/*`, `configuration.txt/.php`, `refreshTelemetry()`,
`createCommands()`, du throttle, du cache ou du fetch `/alerts`.

### Modèle de commandes

**1. `alerts_count` (info agrégée) — dans `definitionsCommandes()`** (création PARESSEUSE, comme `tyre_alert`) :

| logicalId | nom FR (littéral `__()`) | subType | generic | unité | historisé |
|---|---|---|---|---|---|
| `alerts_count` | `Nombre d'alertes actives` | numeric | `''` | `''` | **oui** |

- **Historisée** : tendance + déclencheur de scénario natif « le véhicule a une alerte » (`#alerts_count# > 0`, AC2).
- `generic_type=''` : aucune constante core fiable (convention projet « on ne devine pas une constante core »).

**2. Binaires PAR TYPE — création DYNAMIQUE (hors `definitionsCommandes()`)**

Le catalogue AlertMsgEnum (~80 types, non figé) **ne peut pas** être déclaré statiquement → création
dynamique paresseuse, une commande **par type réellement rencontré actif au moins une fois** (borne la
prolifération : pas 80 d'un coup). Nouveau helper :

```
private function ensureAlertCommand(string $_typeBrut): stellantisCmd
```
- `logicalId = self::ALERT_CMD_PREFIX . <slug>` avec `<slug> = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($_typeBrut))), '_')`
  (extrait dans le helper pur `sluggifierTypeAlerte()`, partagé avec `synchroniserCommandesAlertes()` —
  pas de duplication). **Garde anti-vide** : si `$slug === ''` ⇒ **`throw stellantisException`** (type non
  exploitable — ne jamais créer `alert_`). Choix du `throw` (et non d'un retour anticipé) : cohérent avec
  le pattern existant de `trouverOuInstancierCmd()` sur un type de retour non-nullable ; chemin en pratique
  **inatteignable** (le seul appelant `synchroniserCommandesAlertes()` pré-filtre déjà les slugs vides) et
  de toute façon **capturé** par le `try/catch \Throwable` interne de `suivreAlertes()`.
- Réutilise la plomberie centralisée `trouverOuInstancierCmd('info', $lid, $defSynthétique)` avec une
  **définition synthétique** `array($lid => array($nom, 'binary'))` (⇒ pas de duplication du
  setEqLogic_id/type/subType/visible ; **aucun** changement de signature du core). Si la cmd existe déjà
  (`getId() != ''`) → retour direct.
- À la création (`getId() == ''`) : **nom = libellé brut SÉCURISÉ** =
  `htmlspecialchars(self::aseptiser($_typeBrut, 60), ENT_QUOTES, 'UTF-8')` — donnée RUNTIME, **jamais**
  `__()` (piège i18n UC07 : pas de `__($variable)`). `aseptiser()` seul ne protège pas le HTML → double
  traitement obligatoire pour un usage UI (même pattern que `traiterRetourCommande()`).
  `setGeneric_type('')`, **`setIsHistorized(0)`**, `save()`.
- **Traçabilité recette** : à la 1ʳᵉ création d'un type, `log::add('stellantis','info', ...)` avec le
  type assaini → logicalId (détection d'une éventuelle collision d'assainissement).

> **Décision — binaires par type NON historisées** : dans Jeedom, `isHistorized` conditionne le **stockage
> en table d'historique** (graphes/stats), **pas** le déclenchement de scénario (qui se fait sur l'event de
> la cmd via `checkAndUpdateCmd()`, indépendamment du flag). Historiser ~80 binaires × N véhicules gonflerait
> la table d'historique pour une valeur faible ⇒ **non historisées** (l'utilisateur peut activer
> l'historisation manuellement dans l'UI Jeedom sur un type précis s'il le souhaite). Diffère volontairement
> du précédent `tyre_alert`/`service_due`/`at_home` (commandes **uniques**, historisées). Seule
> `alerts_count` (agrégat unique) reste historisée. AC1/AC2 restent couverts.

**3. Constantes** (près des `ALERTS_*` d'UC42) :
```
const ALERT_CMD_PREFIX = 'alert_'; // préfixe logicalId des binaires d'alerte PAR TYPE (création dynamique UC43)
const ALERT_MAX_TYPES  = 100;      // plafond anti-prolifération (>> catalogue AlertMsgEnum réel ~80) : borne
                                   // la création de commandes persistantes sur une réponse /alerts anormale
```

> **Plafond `ALERT_MAX_TYPES` (durcissement sécurité, finding review `low`)** : les commandes `alert_*`
> sont **persistantes** (jamais supprimées) et créées à partir d'une réponse `/alerts` **non bornée** en
> taille. Une réponse anormale (bug amont / API compromise) créerait un nombre illimité et permanent de
> commandes. `synchroniserCommandesAlertes()` **tronque `$actifs` aux `ALERT_MAX_TYPES` premiers** (log
> `warning`) avant toute création. `alerts_count` reflète la valeur **tronquée** (cohérence avec les
> commandes réellement gérées). Généreux vs le catalogue réel (~80) ⇒ aucun impact en fonctionnement normal.
> ⚠️ `alerts_count` (pluriel + `_count`) et `tyre_alert` (préfixe `tyre_`) ne commencent **pas** par
> `alert_` (vérifié : `strpos('alerts_count','alert_')` échoue au 6ᵉ caractère `s`≠`_`) ⇒ **jamais**
> capturés par le filtre de remise à 0. Garde reserved-ids explicite en plus (belt & suspenders).

### Synchronisation par poll — `synchroniserCommandesAlertes(array $_typesActifs): void`

Nouveau helper privé, appelé dans la branche **SUCCÈS** de `suivreAlertes()` en réutilisant le
`$typesActifs` **déjà parsé** (aucun re-fetch, aucun re-parse) :

1. **Construire l'ensemble actif dédupliqué** : `$actifs = array()` indexé par `<slug>` (clé, via
   `sluggifierTypeAlerte()`) → `type brut` (valeur), en ignorant les slugs vides. La déduplication par clé
   garantit qu'`alerts_count` ne surcompte pas si l'API renvoie deux entrées de même `type`
   (`parseAlertes()` ne dédoublonne pas). **Plafond** : si `count($actifs) > self::ALERT_MAX_TYPES`, log
   `warning` + `array_slice($actifs, 0, self::ALERT_MAX_TYPES, true)` (borne la création persistante).
2. **Actifs → 1** : pour chaque `type brut` de `$actifs`, `$cmd = $this->ensureAlertCommand($type)` puis
   `$this->checkAndUpdateCmd($cmd, 1)`.
3. **Plus actifs → 0** : `cmd::byEqLogicId($this->getId(), 'info')` (core, retourne un `static[]` de
   `stellantisCmd`), pour chaque cmd dont `strpos($lid, self::ALERT_CMD_PREFIX) === 0` **et** `$lid` non
   présent dans l'ensemble des slugs actifs (`ALERT_CMD_PREFIX.$slug`) → `checkAndUpdateCmd($cmd, 0)`.
   Les commandes **persistent** (« une par type rencontré ») ⇒ un type revu inactif retombe à 0 sans être
   supprimé. **Garde reserved-ids explicite** (belt & suspenders) : `if (in_array($lid, array('alerts_count',
   'tyre_alert'), true)) continue;` — redondant avec le préfixe (déjà exclus) mais protège d'un futur
   renommage.
4. **`alerts_count`** = `count($actifs)` (types **distincts**) → `$this->ensureCommand('alerts_count')`
   puis `$this->checkAndUpdateCmd($cmd, count($actifs))`. Émis à **chaque** succès (0 si aucun ⇒ AC2).

**Robustesse** : ce helper est appelé depuis le bloc d'écriture isolé de `suivreAlertes()` (cf. ci-dessous),
donc protégé contre toute levée. Il n'accède **jamais** au réseau ni au cache de throttle.

### Modification de `suivreAlertes()` — isolation écriture vs réseau

Restructuration de la **branche succès** (corrige aussi un défaut préexistant UC42 : une exception
d'écriture `tyre_alert` était mal étiquetée « échec réseau » et écrasait le throttle 1 h par 3 h) :

```
$resp = stellantisApi::callWithToken('GET', '/user/vehicles/' . $_apiId . '/alerts');
cache::set($cle, time() + self::ALERTS_TTL_OK, self::ALERTS_TTL_OK); // throttle succès posé AVANT écritures
try {
  $typesActifs = self::parseAlertes($resp);
  // UC42 : tyre_alert (agrégat pneus dédié) — inchangé, mais désormais dans le bloc d'écriture isolé.
  $this->checkAndUpdateCmd($this->ensureCommand('tyre_alert'), self::calculerAlertePneu($typesActifs));
  // UC43 : binaires par type + alerts_count (réutilise le MÊME $typesActifs).
  $this->synchroniserCommandesAlertes($typesActifs);
} catch (\Throwable $e2) {
  // Aléa d'écriture de commande (DB…) : NE PAS toucher au throttle succès déjà posé (l'appel /alerts a
  // réussi) — sinon incident local mal étiqueté « échec réseau ». Best-effort, poursuite.
  log::add('stellantis', 'warning', 'Écriture des commandes d\'alerte échouée — poursuite : ' . $e2->getMessage());
}
```

Le `catch (\Throwable $e)` **externe** existant (403/404 → 7 j, transitoire → 3 h) reste **inchangé** et ne
capture plus que les vrais échecs de fetch/parse `/alerts`. La garde `rateLimitRemaining()` et le throttle
en tête de méthode sont **inchangés**.

### Point d'appel

Aucun ajout dans `refreshTelemetry()` (déjà branché sur `suivreAlertes()` en dernier, UC42). Toute la
logique UC43 vit à l'intérieur de `suivreAlertes()` / ses helpers.

## Server vs Client

**100 % serveur.** Aucune commande action, aucun MQTT, aucun JS/AJAX, aucun formulaire (pas de seuil
per-véhicule). Restitution utilisateur = **scénario Jeedom natif** sur `alerts_count` (ou sur un
`alert_<type>` précis) — pas de `message::add`, pas de spam.

## Validation

- **Serveur** : réutilise le parsing défensif de `parseAlertes()` (shape/champ absent ⇒ liste vide, jamais
  d'exception ; fail-closed sur `active`). Écritures de commandes isolées dans un `try/catch \Throwable`
  best-effort (ne casse jamais le throttle ni le refresh cron). `alerts_count` émis systématiquement (0/n)
  sur toute réponse `/alerts` réussie. Slug anti-vide (jamais de commande `alert_`). Nom de commande
  sécurisé (`aseptiser` + `htmlspecialchars`).
- **Client** : aucune.

## Server Actions / API

Aucune nouvelle action, aucun endpoint. Nouvelles méthodes (toutes dans `stellantis.class.php`) :
- `sluggifierTypeAlerte(string $_typeBrut): string` (privé statique) — slug `[a-z0-9_]` d'un type brut,
  partagé par `ensureAlertCommand()` et `synchroniserCommandesAlertes()` (pas de duplication).
- `ensureAlertCommand(string $_typeBrut): stellantisCmd` (privé) — création dynamique idempotente d'une
  binaire par type, via `trouverOuInstancierCmd()` + définition synthétique.
- `synchroniserCommandesAlertes(array $_typesActifs): void` (privé) — sync actifs→1 / plus-actifs→0 +
  `alerts_count`.
- `suivreAlertes()` (privé, UC42) — branche succès restructurée (isolation écriture/réseau + appel UC43).
- `definitionsCommandes()` — +1 entrée `alerts_count`.

Réutilisés **inchangés** : `parseAlertes()`, `calculerAlertePneu()`, `ensureCommand()`,
`trouverOuInstancierCmd()`, `checkAndUpdateCmd()` (core), `aseptiser()`, `cmd::byEqLogicId()` (core,
signature vérifiée : `byEqLogicId($_eqLogic_id, $_type=null, $_visible=null, ...)` → `static[]`).

## Dépendances

Aucune (aucun paquet, aucune extension PHP).

## Impact i18n (FR uniquement — traduction différée)

**Une seule** nouvelle chaîne UI statique : `__('Nombre d\'alertes actives', __FILE__)` (label `alerts_count`).
Les noms des commandes **par type** sont des **données runtime** (libellé brut sécurisé) ⇒ **pas** de clé i18n.

## Décisions documentées (pour reviewers & futurs mainteneurs)

1. **Création dynamique hors `definitionsCommandes()`** (catalogue AlertMsgEnum ~80 types variable/non figé) :
   pattern assumé, distinct des créations paresseuses **statiques** précédentes. Borne la prolifération par
   création paresseuse (un type doit avoir été actif ≥ 1 fois).
2. **Remise à 0 par énumération DB** (`cmd::byEqLogicId`) plutôt qu'un cache d'état : la vérité vient des
   commandes elles-mêmes, survit à `cache::flush()` (pas de staleness silencieuse).
3. **Binaires par type NON historisées** (voir § Modèle de commandes) : `isHistorized` ≠ trigger de
   scénario ; limite la croissance de la table d'historique.
4. **Libellé brut non traduit mais sécurisé** (`aseptiser` + `htmlspecialchars`) : donnée API, pas UI
   statique ; cohérent avec `traiterRetourCommande()`.
5. **Permanence assumée** : aucune purge/masquage des commandes obsolètes (conforme à la spec « une par
   type rencontré »). Compromis noté, pas un oubli.
6. **Réutilisation UC42** (contrainte structurante) : `parseAlertes()`/`suivreAlertes()` étendus, jamais
   dupliqués. Fix marginal du throttle-poisoning UC42 corrigé en passant.

## Critères d'acceptation — couverture

- **AC1** (alertes présentes → binary + libellé) : `ensureAlertCommand()` crée une binaire par type actif,
  nom = libellé brut sécurisé ; actifs→1 / plus-actifs→0 à chaque poll.
- **AC2** (info agrégée → scénario) : `alerts_count` (numeric historisée) émise à chaque succès, `> 0` =
  déclencheur de scénario natif « le véhicule a une alerte ».

## Recette manuelle (à ajouter à `81-validation-manuelle.md`)

Scénario UC43 : sur un véhicule dont le forfait expose `/alerts` avec ≥ 1 alerte active, vérifier
l'apparition d'une binaire `alert_<type>` (à `1`, nom = libellé brut) par type actif et d'`alerts_count`
= nombre de types distincts. Quand une alerte disparaît côté API, vérifier que sa binaire retombe à `0`
(sans disparaître) et qu'`alerts_count` décroît. Contrôler dans les logs `info` le mapping
`type brut → logicalId` à la 1ʳᵉ apparition (détection de collision d'assainissement). Vérifier
l'**absence** de toute commande d'alerte sur un véhicule 403/404 (throttle long, pas de création).
Confirmer qu'un scénario déclenché sur `alerts_count > 0` fonctionne.
