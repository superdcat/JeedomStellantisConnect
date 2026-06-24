# Spec technique — mvp 07 capacites commandes

> Spec fonctionnelle : `.memory/specs/MVP/07-capacites-commandes.md`
> Dépend de : 06 (`imou::syncEquipments()` → `$eqLogic->save()`, déjà livrée).

## Contrat API IMOU
**Aucun nouvel appel cloud.** L'UC07 ne fait que créer des objets `cmd` Jeedom à partir des
eqLogic existants (issus de la découverte UC05/UC06). Les endpoints réels (`set/getDeviceCameraStatus`,
`motionDetect`, inversion `closeCamera`) ne sont consommés qu'en **UC08/09/10** (exécution + refresh).
→ Doc IMOU non requise à ce stade.

## Architecture
Un seul fichier modifié : **`core/class/imou.class.php`** (classe `imou`).

### 1. `imou::postSave()` (hook d'instance, aujourd'hui vide)
- Appelle `$this->createCommands();`.
- Déclenché automatiquement par le core après chaque `$eqLogic->save()` — donc à chaque sync UC06.
- Pas de récursion : `cmd::save()` ne rappelle pas `eqLogic::postSave()` dans le core. Commentaire
  d'ancrage ajouté pour le signaler.

### 2. `imou::createCommands()` (nouvelle méthode d'instance)
Création idempotente des **6 commandes MVP**, pilotée par un **tableau descripteur** pour éviter
la duplication. Ordre impératif : **les 2 commandes info d'abord (sauvegardées), puis les 4 actions**
(qui référencent l'id de leur info via `setValue`).

Descripteur des commandes :

| logicalId | name (FR) | type | subType | isHistorized | value (info liée) |
|---|---|---|---|---|---|
| `camera_state` | Caméra (état) | info | binary | 1 | — |
| `camera_on` | Allumer caméra | action | other | — | `camera_state` |
| `camera_off` | Éteindre caméra | action | other | — | `camera_state` |
| `surveillance_state` | Surveillance (état) | info | binary | 1 | — |
| `surveillance_on` | Activer surveillance | action | other | — | `surveillance_state` |
| `surveillance_off` | Désactiver surveillance | action | other | — | `surveillance_state` |

`generic_type` : **laissé vide** (décision MVP) — aucun generic_type Jeedom n'est sémantiquement
correct pour l'allumage d'une caméra ; `ENERGY_*` induirait une mauvaise synchro (Alexa/Google/HA).
Le lien `value` info↔action suffit pour la page équipement. Affinage widgets reporté post-MVP.

Logique par commande (helper `creerCommande($logicalId, $name, $type, $subType, $options)`) :
- `$cmd = $this->getCmd(null, $logicalId);` (appel canonique d'instance).
- **Absente (création)** : `new imouCmd()`, `setLogicalId($logicalId)`, `setEqLogic_id($this->getId())`,
  `setName(__($name, __FILE__))`, `setIsVisible(1)`. → `name` + `isVisible` posés **à la création
  seulement** (préservation de la perso utilisateur, esprit UC06).
- **Existante (mise à jour)** : `name` et `isVisible` **non touchés**.
- **Dans tous les cas** (technique re-posée) : `setType`, `setSubType`, et pour les info
  `setIsHistorized(1)`. `save()`.
- Pour les actions : après `save()`, `setValue($infoCmd->getId())` puis `save()` à nouveau — l'id
  de l'info doit être disponible (info sauvegardée AVANT), sinon `value=null` silencieux (B1).

Fin de méthode : `log::add('imou', 'debug', …)` avec le bilan (créées / mises à jour).

### Inversion `closeCamera` (ancrage UC10)
`camera_state` est déclarée comme info binaire « 1=allumée / 0=éteinte ». La lecture cloud réelle
(`closeCamera=true` ⇒ éteinte ⇒ valeur 0) et l'inversion associée sont implémentées en **UC10**
(refresh cron). Commentaire d'ancrage posé directement sur le descripteur de `camera_state`.

### Filtrage par `ability` (reporté UC12)
Le socle MVP (caméra + surveillance) est créé **inconditionnellement** : ces capacités sont
quasi universelles. Le filtrage fin selon le CSV `ability` de l'eqLogic est reporté en **UC12**
(simple commentaire dans le code).

## Server vs Client
Vocabulaire Next.js inadapté. Ici **100 % back-end PHP** : aucune UI, aucun JS, aucun endpoint
AJAX. Le déclenchement vient du hook `postSave()` du core, lui-même appelé par la sync UC06.

## Validation
- **Serveur** :
  - Idempotence garantie par `getCmd(null, $logicalId)` → re-synchro ne duplique pas (critère 2).
  - Ordre info→action + `save()` avant `getId()` → lien `value` toujours valide (B1).
  - Préservation `name` + `isVisible` sur commande existante (A1) ; technique (`type`/`subType`/
    `isHistorized`) toujours corrigée.
- **Client** : N/A (aucune UI).

## Server Actions / API
- `imou::postSave(): void` → délègue à `createCommands()`.
- `imou::createCommands(): void` → crée/garantit les 6 commandes de l'eqLogic courant.
- (optionnel privé) `imou::creerCommande($logicalId, $name, $type, $subType, $options = array()): imouCmd`
  → fabrique/retourne une commande idempotente.

## Dépendances
Aucune.

## Impact i18n (FR uniquement — traduction déléguée à l'Étape 10)
Nouvelles clés `__('…', __FILE__)` dans `core/class/imou.class.php` (noms de commandes) :
`Caméra (état)`, `Allumer caméra`, `Éteindre caméra`, `Surveillance (état)`,
`Activer surveillance`, `Désactiver surveillance`.
