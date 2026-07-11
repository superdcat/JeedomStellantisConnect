# Spec technique — 23 (Carburant & véhicules hybrides)

> Scission propre de l'autonomie par énergie sur les hybrides, et pas de commande EV sur un thermique
> pur. 100 % lecture/parsing PHP : **aucun** nouvel appel REST ni MQTT, aucune dépendance. Source unique
> = le `GET /user/vehicles/{id}/status` déjà récupéré au cron (data-model § 2.1).

## Architecture

Tout se joue dans `core/class/stellantis.class.php` (+ une migration best-effort dans
`plugin_info/install.php`). Aucun nouveau fichier, aucun point d'entrée externe → aucun risque autoload.

### 1. `definitionsCommandes()` — table des commandes info
- **Renommer** le libellé de `autonomy` : `__('Autonomie', __FILE__)` → `__('Autonomie électrique', __FILE__)`
  (clarté sur hybride où coexistent 3 autonomies). ⚠️ Le libellé n'est posé qu'à la **création**
  (`ensureCommand`) : ne touche que les commandes **nouvellement créées** ; les installs existantes gardent
  « Autonomie » (tradeoff accepté, pas de migration de libellé).
- **Ajouter** `autonomy_fuel` → `array(__('Autonomie carburant', __FILE__), 'numeric', '', 'km', true)`.
- **Ajouter** `autonomy_total` → `array(__('Autonomie totale', __FILE__), 'numeric', '', 'km', true)`.

> ⚠️ Ces 2 clés **doivent** exister dans la table : `refreshTelemetry` appelle `ensureCommand($logicalId)`
> pour **chaque** clé émise par `parseStatus` → une clé absente de la table lève `stellantisException` et
> casse le refresh du véhicule.

### 2. `parseStatus()` — mapping pur (§ contrat : champ absent ⇒ clé absente, jamais d'exception)
- Branche `type == 'electric'` : `autonomy` **inchangé** (autonomie sur batterie).
- Branche `type == 'fuel'` : remplacer l'écriture partagée dans `autonomy` (garde « élec prioritaire »
  `if (!isset($valeurs['autonomy']) ...)`) par une écriture dans **`autonomy_fuel`** (clé propre, sans
  garde). **Supprimer** le commentaire caduc `// scission autonomy_fuel = post-MVP/23`.
  ```php
  } elseif ($type == 'fuel') {
    if (isset($energie['level']) && is_numeric($energie['level'])) {
      $valeurs['fuel_level'] = (float) $energie['level'];
    }
    if (isset($energie['autonomy']) && is_numeric($energie['autonomy'])) {
      $valeurs['autonomy_fuel'] = (float) $energie['autonomy'];
    }
  }
  ```
- **Après** la boucle `energies` (autonomie totale dérivée — hybride uniquement) :
  ```php
  // UC23 : autonomie totale combinée = somme élec + carburant. VALEUR DÉRIVÉE (aucun champ API natif,
  // cf. data-model § 2.1) — seule exception au pattern "1 champ /status → 1 clé" de parseStatus. Émise
  // UNIQUEMENT si les DEUX autonomies sont présentes dans ce même /status ⇒ impossible hors hybride
  // (satisfait AC1/AC2 par construction). Création paresseuse : jamais déclarée dans createCommands.
  if (isset($valeurs['autonomy']) && isset($valeurs['autonomy_fuel'])) {
    $valeurs['autonomy_total'] = $valeurs['autonomy'] + $valeurs['autonomy_fuel'];
  }
  ```

### 3. `createCommands()` — création par motorisation (config `energy`)
- Electric/Hybrid : **inchangé** (`autonomy` déjà créé avec `battery_soc`/`charging_*`).
- Thermal/Hybrid : **remplacer** l'ajout conditionnel de `autonomy` par `autonomy_fuel` (eager, comme
  `fuel_level`) :
  ```php
  if ($motorisation == 'Thermal' || $motorisation == 'Hybrid') {
    $aCreer[] = 'fuel_level';
    $aCreer[] = 'autonomy_fuel';
  }
  ```
- `autonomy_total` : **création paresseuse uniquement** (jamais dans `createCommands`) — précédent UC21.
  N'apparaît que si un `/status` fournit *réellement* les deux autonomies, évitant une commande figée à
  vide sur un hybride dont l'API ne renvoie pas les deux simultanément.

### 4. `plugin_info/install.php` — migration best-effort (`stellantis_update()`)
Sur un **thermique déjà découvert**, l'ancien `autonomy` (alimenté par le carburant sous la logique
partagée) cesse d'être émis par `parseStatus` → il se fige et cohabiterait avec le nouveau `autonomy_fuel`
(2 autonomies redondantes). Masquer (non destructif, aligné UC76 « disparus → désactivés ») :
```php
// UC23 : après scission autonomy/autonomy_fuel, l'ancienne cmd 'autonomy' d'un THERMIQUE PUR (alimentée
// autrefois par le carburant) se fige. La masquer pour éviter le doublon avec 'autonomy_fuel'. Best-effort,
// idempotent, thermique UNIQUEMENT (hybride/électrique : 'autonomy' reste l'autonomie élec, ne pas toucher).
try {
  foreach (eqLogic::byType('stellantis') as $eq) {
    if (trim((string) $eq->getConfiguration('energy', '')) !== 'Thermal') { continue; }
    $cmd = $eq->getCmd('info', 'autonomy');
    if (is_object($cmd) && $cmd->getIsVisible() == 1) {
      $cmd->setIsVisible(0);
      $cmd->save();
    }
  }
} catch (\Throwable $e) {
  log::add('stellantis', 'warning', 'Mise à jour UC23 : masquage autonomy thermique ignoré (' . $e->getMessage() . ')');
}
```
⚠️ Ne PAS supprimer la commande (peut être utilisée dans un scénario). Ne PAS toucher les hybrides.

## Server vs Client
100 % **serveur** (parsing PHP dans `parseStatus`, création de commandes, migration). Aucun code
client/JS, aucun widget, aucun AJAX, aucune action MQTT. Cohérent avec UC21 (télémétrie de lecture pure).

## Validation
- **Défensive** (parsing) : `isset` + `is_numeric` sur chaque champ ; champ absent/non numérique ⇒ clé non
  émise (contrat `parseStatus`). Aucune saisie utilisateur ⇒ pas de validation client.
- **Par construction** : `autonomy_total` exige la coexistence des deux autonomies dans le même `/status`
  ⇒ jamais sur électrique pur ni thermique pur (AC1/AC2).
- **Migration** : best-effort, try/catch global, idempotente, bornée aux `energy=='Thermal'`.

## Server Actions / API
Aucune. Pas de nouvel endpoint REST, pas de commande MQTT, pas de handler AJAX.

## Dépendances
Aucune (ni PHP, ni pip).

## i18n (FR — traduction différée au sous-agent translator)
Nouvelles clés introduites : `Autonomie électrique`, `Autonomie carburant`, `Autonomie totale`.
La clé `Autonomie` devient **orpheline** (plus référencée) → le translator la signalera/nettoiera.

## Critères d'acceptation → couverture
- **AC1** (hybride : SOC/autonomie élec ET niveau/autonomie carburant sans collision) : branches `electric`
  (`battery_soc`+`autonomy`) et `fuel` (`fuel_level`+`autonomy_fuel`) écrivent des clés **distinctes** ;
  `autonomy_total` en bonus dérivé. ✔
- **AC2** (thermique pur : aucune commande EV) : `createCommands` ne crée `battery_soc`/`charging_*`/
  `autonomy` que sur Electric/Hybrid ; le thermique n'obtient que `fuel_level`/`autonomy_fuel` ;
  `parseStatus` n'émet aucune clé EV sur un `/status` sans entrée `Electric`. ✔
