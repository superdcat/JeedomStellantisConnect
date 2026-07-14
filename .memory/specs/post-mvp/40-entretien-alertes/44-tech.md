# Spec technique — 44 (Ouvrants détaillés — portes, fenêtres, coffre, capot)

> Compagnon technique de `44-ouvrants-detailles.md`. Plan validé le 2026-07-14.
> 100 % PHP lecture/parsing du `/status` **déjà récupéré** par `refreshTelemetry()` — **AUCUN appel
> REST/MQTT neuf**, aucun JS/AJAX, aucun formulaire, aucune dépendance. Tout dans
> `core/class/stellantis.class.php`.

## Contrat API Stellantis/PSA (data-model § 2.4, confirmé)

Source : `doors_state.opening[n]` dans le `GET /user/vehicles/{id}/status` (le même objet que
`doors_state.locked_state` déjà lu par `extraireVerrouillage()` pour `doors_locked`, MVP07).
- Chaque entrée : `{ identifier (string), state (string) }`.
- `identifier ∈ { Driver, Passenger, RearLeft, RearRight, Trunk, RearWindow, RoofWindow }` (7 valeurs
  **confirmées** data-model § 2.4).
- `state ∈ { Open, Closed }`.
- → commande info binaire `door_<id>` avec `generic_type OPENING`.

**⚠️ « Capot » (spec titre) absent de l'enum confirmé** : le `doors_state.opening` consommateur ne liste
pas d'identifiant capot/hood dans le data-model. Traitement : `door_hood` (« Capot ») est **déclaré** et le
mapping accepte `hood`/`bonnet` **par anticipation** (spéculatif, NON confirmé) — coût nul si absent
(création paresseuse ⇒ aucune commande créée). À valider en recette ; si un autre identifiant réel
apparaît, l'étendre dans `OPENING_IDENTIFIERS`.

**⚠️ Shape alternative « champs nommés »** (évoquée par la spec fonctionnelle, `mapping défensif`) : seule
la shape **tableau** `doors_state.opening[n]` (confirmée data-model) est traitée. Si un modèle exposait les
ouvrants en champs nommés (`doors_state.driver_door.state`…), ils seraient **ignorés silencieusement**
(pas d'erreur, pas de commande) — comportement best-effort assumé, à confirmer contre un payload réel avant
de considérer AC1 « fait » pour tous les modèles.

## Architecture

Tous les changements dans `core/class/stellantis.class.php` (aucune nouvelle classe/fichier ⇒ aucun
risque d'autoload). Aucun changement de `refreshTelemetry()`, `createCommands()`, `desktop/php/*`,
`configuration.txt/.php`.

### Décision : STATIC (enum connu) + agrégat — PAS dynamique (≠ UC43)

UC43 (alertes) a introduit la création DYNAMIQUE pour un catalogue ~80 types AlertMsgEnum **non figé**.
Ici l'enum est **petit (7-8), connu et signifiant** ⇒ approche **STATIC idiomatique** (miroir de
`extraireVerrouillage`/`doors_locked`) retenue :
- **libellés FR localisés** (`__('Coffre')`) — bien meilleurs qu'un identifiant brut anglais (`Trunk`) ;
- plus simple, s'intègre à `parseStatus()` (pur) ;
- s'appuie sur la création paresseuse existante (`ensureCommand()` dans la boucle de `refreshTelemetry`).

Identifiants **inconnus** (« partiel selon modèle ») : jamais émis comme logicalId (⇒ pas de throw
`ensureCommand`), mais **comptés dans l'agrégat** `opening_alert` + loggués `debug` (recette). Dynamique
UC43 considéré et **écarté** (sur-ingénierie pour un enum labellisé de taille fixe).

### 1. `definitionsCommandes()` — +9 entrées (création PARESSEUSE, « si présentes »)

Jamais déclarées dans `createCommands()` (précédent UC21/23/24/31/33/34/41/42/43) : naissent au 1er
`/status` qui expose l'ouvrant (boucle `ensureCommand` de `refreshTelemetry`). Libellés **littéraux** dans
`__()` (extracteur i18n, piège UC07).

| logicalId | nom FR (littéral) | subType | generic | historisé |
|---|---|---|---|---|
| `door_driver`      | `Porte conducteur`      | binary | `OPENING` | non |
| `door_passenger`   | `Porte passager`        | binary | `OPENING` | non |
| `door_rear_left`   | `Porte arrière gauche`  | binary | `OPENING` | non |
| `door_rear_right`  | `Porte arrière droite`  | binary | `OPENING` | non |
| `door_trunk`       | `Coffre`                | binary | `OPENING` | non |
| `door_rear_window` | `Fenêtre arrière`       | binary | `OPENING` | non |
| `door_roof_window` | `Toit ouvrant`          | binary | `OPENING` | non |
| `door_hood`        | `Capot`                 | binary | `OPENING` | non |
| `opening_alert`    | `Ouvrant ouvert`        | binary | `''`      | **oui** |

- **Per-ouvrant NON historisés** : ce sont des **états** (cohérent avec `doors_locked`, non historisé). Le
  déclenchement de scénario ne dépend **pas** de `isHistorized` (event `cmd` — cf. mémoire
  `jeedom-cmd-creation-patterns` / commentaire UC43). L'utilisateur peut activer l'historisation par
  ouvrant lui-même dans l'UI Jeedom si besoin.
- **`opening_alert` historisé** : agrégat unique, valeur de **surveillance** (« coffre resté ouvert »),
  déclencheur de scénario natif (précédent `tyre_alert`/`service_due`/`at_home`/`alerts_count`).
- `generic_type` : `OPENING` **par ouvrant** (1 = ouvert, convention core Jeedom « Ouvrant » —
  data-model + spec ; polarité widget à confirmer en recette, repli cosmétique sinon). **`''` sur
  `opening_alert`** (agrégat, pas un ouvrant précis — précédent `tyre_alert`/`alerts_count` ; ne PAS
  mimer le type du capteur élémentaire).

### 2. Constante `OPENING_IDENTIFIERS` (adjacente à la table, invariant à respecter)

Map `identifiant API EN MINUSCULES → logicalId`. **Invariant** (pas d'infra de test unitaire dans le
projet — validation CI Jeedom) : **toute valeur DOIT être un logicalId déclaré dans
`definitionsCommandes()`** (sinon `ensureCommand` lèverait et casserait le refresh). Gardée adjacente aux
entrées ci-dessus avec un commentaire imposant l'invariant.

```
const OPENING_IDENTIFIERS = array(
  'driver'     => 'door_driver',
  'passenger'  => 'door_passenger',
  'rearleft'   => 'door_rear_left',
  'rearright'  => 'door_rear_right',
  'trunk'      => 'door_trunk',
  'rearwindow' => 'door_rear_window',
  'roofwindow' => 'door_roof_window',
  'hood'       => 'door_hood',   // spéculatif (capot NON confirmé côté /status) — cf. § contrat
  'bonnet'     => 'door_hood',   // idem, alias anticipé
);
```

### 3. `extraireOuvrants(array $_status): array` — pur, statique (miroir `extraireVerrouillage`)

Seul endroit portant les chemins JSON `doors_state.opening`. Défensif : shape/champ absent ⇒ `array()`,
jamais d'exception ni de warning PHP (même contrat pur que `parseStatus`).

```
if (!isset($_status['doors_state']['opening']) || !is_array($_status['doors_state']['opening'])) {
  return array();                       // ouvrants non exposés ⇒ aucune commande (« si présentes »)
}
$valeurs = array();
$auMoinsUnOuvert = false;
foreach ($_status['doors_state']['opening'] as $ouvrant) {
  if (!is_array($ouvrant) || !isset($ouvrant['identifier'], $ouvrant['state'])
      || !is_scalar($ouvrant['identifier']) || !is_scalar($ouvrant['state'])) {
    continue;                           // entrée malformée : ignorée (défensif)
  }
  // Fail-closed : SEUL « Open » explicite ⇒ ouvert (un état inconnu type « Ajar » ⇒ 0, pas de fausse alerte).
  $ouvert = (strcasecmp(trim((string) $ouvrant['state']), 'Open') == 0);
  if ($ouvert) {
    $auMoinsUnOuvert = true;            // agrégat sur TOUS les ouvrants (connus + inconnus)
  }
  $cle = strtolower(trim((string) $ouvrant['identifier']));
  if (isset(self::OPENING_IDENTIFIERS[$cle])) {
    $valeurs[self::OPENING_IDENTIFIERS[$cle]] = $ouvert ? 1 : 0;  // logicalId STATIQUE déclaré
  } else {
    log::add('stellantis', 'debug', 'Ouvrant non catalogué (identifier ' . self::aseptiser((string) $ouvrant['identifier'], 40) . ') — compté dans opening_alert');
  }
}
$valeurs['opening_alert'] = $auMoinsUnOuvert ? 1 : 0;   // émis dès que doors_state.opening exploitable (0 si tous fermés ⇒ AC2)
return $valeurs;
```

### 4. `parseStatus()` — 1 ligne d'intégration

Après le bloc verrouillage (`$valeurs['doors_locked'] = …`) :
```
$valeurs = array_merge($valeurs, self::extraireOuvrants($_status));
```
Aucune collision de clé : aucun `door_*`/`opening_alert` n'existe ailleurs dans `parseStatus`/
`definitionsCommandes` (vérifié). `refreshTelemetry()` **inchangé** (sa boucle `ensureCommand` +
`checkAndUpdateCmd` crée paresseusement et met à jour à chaque poll — cadence 5 min).

## Server vs Client

100 % serveur. Aucune commande action (spec : ouverture/fermeture NON disponibles), aucun MQTT, aucun
JS/AJAX, aucun formulaire. Restitution = **scénario Jeedom natif** sur `opening_alert` (ou un `door_<id>`
précis) — pas de `message::add`.

## Validation

- **Serveur** : parsing pur défensif (`isset`/`is_array`/`is_scalar` à chaque niveau ⇒ jamais d'exception
  ni de warning, `array()` si absent) ; **fail-closed** sur `state` (seul « Open » ⇒ 1) ; n'émet QUE des
  logicalId **statiques déclarés** (jamais dynamique ⇒ pas de throw `ensureCommand`) ; identifiant inconnu
  aseptisé avant log `debug` ; `opening_alert` émis (0/1) dès que `doors_state.opening` est exploitable.
- **Client** : aucune.

## Server Actions / API

Aucune nouvelle action, aucun endpoint. Nouvelle méthode (dans `stellantis.class.php`) :
- `extraireOuvrants(array $_status): array` (privé statique, pur) — mapping des ouvrants + agrégat.
Réutilisés **inchangés** : `parseStatus()` (+1 ligne), `ensureCommand()`, `checkAndUpdateCmd()`,
`aseptiser()`, `refreshTelemetry()` (0 changement).

## Dépendances

Aucune (aucun paquet, aucune extension PHP).

## Impact i18n (FR uniquement — traduction différée)

**9 nouvelles chaînes UI littérales** `__('…', __FILE__)` : `Porte conducteur`, `Porte passager`,
`Porte arrière gauche`, `Porte arrière droite`, `Coffre`, `Fenêtre arrière`, `Toit ouvrant`, `Capot`,
`Ouvrant ouvert`.

## Décisions documentées (pour reviewers & futurs mainteneurs)

1. **STATIC (enum connu) et non dynamique (≠ UC43)** : enum de taille fixe/connue ⇒ libellés FR localisés
   + simplicité, vs le catalogue ~80 non figé d'UC43. Inconnus comptés dans l'agrégat + loggués, jamais
   perdus ni émis comme logicalId (étanchéité au throw `ensureCommand`).
2. **Fail-closed sur `state`** : seul « Open » explicite ⇒ ouvert (un état ambigu ⇒ 0, pas de fausse
   alerte) — même posture qu'UC42/43 sur une donnée à shape non garantie.
3. **Per-ouvrant non historisés, `opening_alert` historisé** : `isHistorized` = table d'historique, pas
   trigger de scénario (cf. mémoire `jeedom-cmd-creation-patterns`).
4. **`generic_type OPENING` par ouvrant / `''` sur l'agrégat** : convention projet (agrégats en `''`) ;
   OPENING = type core standard « Ouvrant » (data-model + spec), polarité widget à confirmer en recette.
5. **Capot & shape « champs nommés »** : écarts AC/contrat **documentés** (§ contrat), pas laissés
   flottants — `door_hood` déclaré par anticipation (coût nul si absent), shape tableau seule traitée.

## Critères d'acceptation — couverture

- **AC1** (ouvrants exposés → infos OPENING, rafraîchies) : `door_<id>` binary `OPENING`, créés
  paresseusement au 1er `/status` qui les expose, mis à jour à chaque poll (5 min).
- **AC2** (agrégat « un ouvrant ouvert ») : `opening_alert` binary **historisé** = OR des ouverts (tous
  ouvrants) → déclencheur de scénario natif.

## Recette manuelle (à ajouter à `81-validation-manuelle.md`)

Sur un véhicule exposant `doors_state.opening` : vérifier l'apparition des `door_<id>` (widget OPENING,
« Ouvert »/« Fermé » — confirmer la polarité 1=ouvert) pour chaque ouvrant présent, et d'`opening_alert`
(0 tous fermés, 1 si au moins un ouvert), qui se rafraîchissent au poll. Ouvrir le coffre → `door_trunk=1`
+ `opening_alert=1` (déclencheur de scénario). Contrôler dans les logs `debug` un éventuel identifiant
« non catalogué » (⇒ étendre `OPENING_IDENTIFIERS` + `definitionsCommandes`). Vérifier l'**absence** des
commandes sur un modèle n'exposant pas `doors_state.opening`. Confirmer si un capot (hood/bonnet) est réel.
