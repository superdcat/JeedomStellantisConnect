# 04 — Test de connexion

**Phase :** MVP · **Dépend de :** 03 · **Fichiers :** `core/ajax/stellantis.ajax.php`, `core/class/stellantis.class.php`, `desktop/php/stellantis.php` + `desktop/js/stellantis.js`

## Objectif
Donner à l'utilisateur un bouton « Tester la connexion » qui valide bout-en-bout que les credentials et
le token fonctionnent : on appelle un endpoint léger (liste des véhicules) et on affiche un retour clair.

## Périmètre
- **Inclus** : bouton + action AJAX `testConnection`, appel réel minimal, message succès/échec lisible.
- **Exclu** : création des équipements (→ 06), parsing complet du statut (→ 07).

## Détails techniques
- Méthode `stellantis::testConnection(): array` → `stellantisApi::callWithToken('GET','/user/vehicles')`
  et renvoie un résumé `['ok'=>bool, 'count'=>int, 'message'=>string]`.
- ⚠️ **Fidélité du chemin d'appel** : l'AJAX appelle `stellantis::testConnection()`, **pas** directement
  `stellantisApi::callWithToken()` (respect de l'autoload : passage par la classe principale).
- Mapping des erreurs en messages utilisateur **actionnables** :
  - non configuré (pas de client_id) → « Renseignez la configuration » ;
  - pas de token / `invalid_grant` → « Connectez-vous (OAuth) / ré-authentifiez-vous » ;
  - `429` → « Trop de requêtes, réessayez plus tard » ;
  - `401` après refresh → « Token invalide » ; autre → message API tronqué.
- UI : bouton sur la page plugin, action AJAX `testConnection`, affichage via `$('#div_alert').showAlert`.

## Critères d'acceptation
- [ ] Avec une config valide et un token, le test affiche « OK » + nombre de véhicules trouvés.
- [ ] Sans config / sans token, le test affiche un message d'aide clair (pas une stack trace).
- [ ] Aucun secret/token affiché dans le message ni dans le DOM.

## Notes / risques
- Le test consomme **1 appel API** (et un éventuel refresh) — acceptable, déclenché manuellement.
