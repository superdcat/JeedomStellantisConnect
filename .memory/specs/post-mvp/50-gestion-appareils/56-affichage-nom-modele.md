# UC — Affichage du nom de modèle (code technique + nom commercial)

**Domaine :** Gestion des appareils · **Dépend de :** MVP/06 (modèle capté en `configuration.model`), UC51 (affichage des identifiants en lecture seule) · **Statut :** à spécifier (tech)

## Objectif / valeur
Afficher, dans l'onglet Équipement (champs **lecture seule**, à côté de `deviceId`/`productId`), le **nom
exact du modèle** de la caméra. Le champ `deviceModel` renvoyé par l'API est souvent un **code technique**
peu parlant (ex. `IPC-F42F-D`, `TP7`) → quand c'est le cas, afficher **en plus** le **nom commercial**
(ex. « Bullet 2E », « Cruiser 2 ») résolu via une table de correspondance, pour que l'utilisateur
reconnaisse son matériel.

## Périmètre
- **Inclus** :
  - Champ **lecture seule** « Modèle » = `configuration.model` (le `deviceModel` brut capté à la
    découverte — déjà stocké, cf. MVP/06).
  - Champ **lecture seule** « Nom commercial » = résolu depuis une **table `code technique → nom
    commercial`** (curatée, extensible). Code inconnu de la table → repli (champ vide, ou code recopié).
- **Exclu** : l'image d'équipement (UC55) ; tout pilotage. Aucun nouvel appel cloud.

## Esquisse Jeedom
- `deviceModel` est **déjà capté** par la découverte (`normalizeDevice` → `configuration.model`). Le champ
  « Modèle (code) » est un simple `eqLogicAttr` lecture seule lié à `configuration.model` (comme `deviceId`).
- **Table de correspondance** `code → nom commercial` : méthode static maison (ex. `imou::modelCommercialNames()`),
  curatée et extensible (même esprit que les catalogues `commandCatalog`/`ptzCatalog`). Source de départ :
  le wiki communautaire **imou_life « Supported models »** (colonnes *Model ID* → *Model Name*,
  https://github.com/user2684/imou_life/wiki/Supported-models).
- **Restitution du nom commercial** — deux options (à trancher en tech) :
  - calcul **au rendu** côté serveur (`desktop/php/imou.php` lit `configuration.model` et fait le lookup
    pour pré-remplir le champ readonly) → toujours à jour si la table évolue ;
  - ou **stocké à la synchro** (`configuration.modelName`) → simple à lier en `eqLogicAttr`, mais figé
    jusqu'au prochain sync.
- `brand` (« lechange »/« imou », aussi renvoyé par `listDeviceDetailsByPage`) : exposable en complément
  si utile (optionnel).

## Critères d'acceptation
- [ ] Le **code modèle exact** (`deviceModel`) s'affiche en lecture seule dans l'onglet Équipement.
- [ ] Quand le code est présent dans la table, le **nom commercial** correspondant s'affiche.
- [ ] Code absent de la table → repli propre (pas de champ cassé : code recopié ou champ vide).
- [ ] Champs en lecture seule (non modifiables par l'utilisateur), cohérents avec `deviceId`/`productId` (UC51).

## À confirmer
- **Format réel de `deviceModel`** selon les modèles : la doc montre `"TP7"`, le wiki imou_life utilise des
  *Model ID* type `"IPC-F42F-D"` → vérifier que `deviceModel` correspond bien à la clé *Model ID* (sinon
  prévoir une normalisation/alias, cf. la fragilité de matching déjà constatée en UC55-tech).
- **Exhaustivité / maintenance** de la table : périmètre initial (modèles courants + appareils de
  l'utilisateur, dont la Cruiser 2C) vs liste complète maintenue ; cohérence licence/source de la liste.
- **Stockage vs calcul** du nom commercial (sync `configuration.modelName` vs lookup au rendu).
