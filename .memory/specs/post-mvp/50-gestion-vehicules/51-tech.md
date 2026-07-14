# Spec technique — 51 — Identité du véhicule (VIN, marque, libellé, énergie)

> Référence fonctionnelle : `51-identite-vehicule.md`. Dépend de MVP/05 (`discoverVehicles()`) et
> MVP/06 (`syncVehicles()`). Plan validé le **2026-07-14** (advisor `code-reviewer` + décision
> utilisateur sur le périmètre des commandes info).

## Contrat API (vérifié — AUCUN nouvel appel)
Feature **100 % locale** : aucun appel REST/MQTT neuf. Le champ exploité (`label`) provient de
`GET /user/vehicles`, déjà consommé par `discoverVehicles()` (contrat vérifié le 2026-07-06 vs
`psa_car_controller`, cf. `05-decouverte-vehicules-tech.md`). `discoverVehicles()` retourne déjà
`['id','vin','brand','label','energy']`.

**Résolution du « À confirmer » de la spec fonctionnelle** (présence d'un libellé commercial fiable) :
tranché par `stellantis-data-model.md` § 1 (l.34-37) — **l'API n'a NI champ `model` NI `motorization`**.
Le seul champ approchant est **`label`** = *« version » du véhicule*, en pratique **surnom renommable
par l'utilisateur dans l'app mobile** (pré-rempli avec la désignation commerciale, mais éditable).
Conséquence : on n'affiche PAS « Modèle » (trompeur si l'utilisateur l'a renommé « La voiture de Bob »)
mais **« Libellé du véhicule »**, avec un tooltip explicite. Pas de dérivation depuis la marque (la
marque est affichée séparément, et il n'existe pas de modèle commercial fiable à reconstituer).

## Architecture — fichiers touchés

### 1. `core/class/stellantis.class.php` — `syncVehicles()` (~l.502)
Persister le libellé en configuration, juste après `brand` :
```php
$eqLogic->setConfiguration('label', $v['label']); // découverte = autorité, réécrit à chaque sync (comme brand)
```
Clé de config nommée **`label`** (fidèle à la source API, cohérent avec la clé de sortie de
`discoverVehicles()`). Réécrite à chaque synchronisation (autorité découverte), au même titre que
`brand` — jamais conditionnée (contrairement à `energy`, qui est raffinée par le `/status`).

### 2. `core/class/stellantis.class.php` — `definitionsCommandes()`
Ajouter **une** commande info string (identité, universelle, non historisée) :
```php
'label' => array(__('Libellé', __FILE__), 'string', '', '', false),
```
- `subType='string'`, `generic_type=''` (aucune constante core « libellé/modèle » à deviner —
  convention projet « on ne devine pas de constante non vérifiée »).
- **Non historisée** : donnée d'identité statique (aucune valeur à historiser).
- ⚠️ **Sécurité — neutralisation obligatoire** (finding review sécu 2026-07-14) : `label` est le
  **premier texte VRAIMENT libre et externe** posé en valeur de commande info (surnom éditable dans
  l'app mobile, ex. `<img src=x onerror=...>`), rendu par le widget dashboard **générique** du core
  pour tout utilisateur ayant `hasRight('r')`. La valeur écrite dans la commande est donc neutralisée
  par `htmlspecialchars(self::aseptiser(...), ENT_QUOTES, 'UTF-8')` — **même convention que
  `last_command_result` (UC18) et les noms d'alerte (UC43)**. `aseptiser()` retire les caractères de
  contrôle et **borne la longueur** (anti-DoS d'affichage). Voir § 3 pour le point d'application exact.
- **Pas de commande `vin`** (décision utilisateur 2026-07-14) : le VIN est déjà visible en config
  avancée (admin-only) et l'exposer en commande info le mettrait sur les widgets dashboard aux côtés de
  la position (identité + géoloc) — surface de confidentialité non désirée, et l'AC1 ne l'exige pas.

### 3. `core/class/stellantis.class.php` — `createCommands()`
- Ajouter `'label'` à la liste **eager universelle** `$aCreer` (tout véhicule a un libellé).
- Après la boucle `ensureCommand`, **peupler la valeur depuis la config** (valeur STATIQUE — jamais via
  `parseStatus()`, qui est pur et ne connaît que le `/status`) :
```php
// UC51 : identité — valeur STATIQUE issue de la config (jamais du /status). Peuplée ici (pas dans
// parseStatus) : createCommands() est appelé au sync (après réécriture de la config d'identité) et au
// self-heal de motorisation — jamais à chaque cron. Compromis identique à l'affichage config de
// brand/energy (rafraîchis seulement à la synchro) : la donnée étant quasi immuable, c'est suffisant.
// Neutralisation AVANT affichage dashboard (cf. § 2 sécurité) : htmlspecialchars(aseptiser(...)).
$this->checkAndUpdateCmd($this->ensureCommand('label'), htmlspecialchars(self::aseptiser((string) $this->getConfiguration('label', '')), ENT_QUOTES, 'UTF-8'));
```
**Point d'application** : on neutralise **uniquement la valeur écrite dans la commande** (rendue sur
dashboard, audience `hasRight('r')`). La config `label` reste **brute** : le champ admin
(`<input readonly>` via `data-l1key`) est admin-only et peuplé par le JS core en `.val()` (valeur non
interprétée comme HTML) ; l'échapper double-échapperait aussi le **nom** de l'eqLogic (déjà échappé à
l'affichage par `panel.php`, UC32). L'`setName()` d'AC2 reste brut, inchangé.

### 4. `desktop/php/stellantis.php` — champ readonly « Libellé du véhicule »
À insérer après le bloc « Marque » (~l.169), avant « Motorisation ». Miroir des autres champs de
synchro (readonly) :
```html
<div class="form-group">
  <label class="col-sm-4 control-label">{{Libellé du véhicule}}
    <sup><i class="fas fa-question-circle tooltips" title="{{Nom du véhicule tel que défini dans l'application mobile (pré-rempli avec la désignation commerciale, modifiable ; renseigné par la synchronisation)}}"></i></sup>
  </label>
  <div class="col-sm-6">
    <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="label" readonly>
  </div>
</div>
```
⚠️ **Impératif** (mémoire `jeedom-eqlogic-sync-persist`) : ce champ **doit** figurer dans le formulaire
(même readonly), sinon la clé de config `label` serait **effacée au « Sauvegarder »** de l'équipement.
C'est aussi ce qui réalise l'affichage AC1.

## Server vs Client
100 % serveur (persistance config + peuplement commande). L'affichage du champ config est réalisé par
le PHP de la page, lié en `data-l1key="configuration" data-l2key="label"` (auto-load/save du core).
**Aucun nouvel AJAX, aucune action, aucun JS.**

## Validation
- **Serveur** : `discoverVehicles()` garantit déjà `label` scalaire (repli `''` si absent/non scalaire,
  cf. l.406). Rien à valider en plus. Idempotence par `logicalId=VIN` inchangée.
- **Client** : néant (champ readonly, pas de saisie).
- **Cas `label` vide** (API sans libellé) : config `label=''`, commande `label` peuplée à `''`, nom par
  défaut retombe sur le VIN (repli déjà codé l.493). Aucune dérivation trompeuse.

## AC — couverture
- **AC1** (identité VIN/marque/libellé/énergie visible en config) : VIN, ID API, Marque, Motorisation
  **déjà** affichés (readonly) ; le **Libellé** est ajouté par les parties 1 + 4. ✅
- **AC2** (nom par défaut lisible = marque + libellé) : **déjà** satisfait par
  `setName(trim($v['brand'].' '.$v['label']))` + repli VIN (`syncVehicles()` l.492-493). Vérification
  seule, aucun code. ✅

## Migration / existants
Aucune migration réseau (interdit en `install.php`). Les véhicules déjà découverts ont `vin`/`brand`/
`energy` en config ; **`label` (config + commande) apparaît au prochain clic « Synchroniser »** —
pattern standard (tous les champs d'identité sont sync-populated). Le champ « Libellé » s'affiche vide
jusque-là (le tooltip indique déjà « renseigné par la synchronisation »).

## Server Actions / API
Aucune nouvelle signature publique. Modifications internes de `syncVehicles()`,
`definitionsCommandes()`, `createCommands()`.

## i18n (chaînes FR introduites — traduction différée au sous-agent `translator`)
- `desktop/php/stellantis.php` : `{{Libellé du véhicule}}` + tooltip
  `{{Nom du véhicule tel que défini dans l'application mobile (pré-rempli avec la désignation commerciale, modifiable ; renseigné par la synchronisation)}}`
- `core/class/stellantis.class.php` (via `__()`) : `Libellé`

## Dépendances
Aucune (100 % PHP, pas de démon, pas d'appel réseau).
