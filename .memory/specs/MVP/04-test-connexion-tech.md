# Spec technique — MVP 04 — Test de connexion

> Spec fonctionnelle : `04-test-connexion.md`. Plan validé par l'utilisateur après challenge
> `code-reviewer` (advisor). Dépend de 01 (config plugin), 02 (client HTTP), 03 (token) — tous codés.

## Architecture

Un bouton « Tester la connexion » dans la page de config plugin (`configuration.php`) déclenche un
appel AJAX `testConnection` qui appelle `imouApi::getToken(true)` (appel réseau réel à l'endpoint
`accessToken`, déjà ancré UC02/03). Retour succès/erreur affiché via `showAlert`.

| Fichier | Action | Rôle |
|---|---|---|
| `core/ajax/imou.ajax.php` | modifié | Branche `init('action') == 'testConnection'` → `imouApi::getToken(true)` ; `catch (imouException)` → `ajax::error()` avec message clair (msg + code IMOU) ; succès → `ajax::success(__('Connexion IMOU réussie'))`. |
| `plugin_info/configuration.txt` | modifié | Note d'info statique, bouton `#bt_imouTestConnection`, conteneur d'alerte `#imou_test_alert`, inclusion du JS via `include_file('desktop','configuration','js','imou')`. |
| `plugin_info/configuration.php` | régénéré | `cp configuration.txt configuration.php` (contrainte outillage CLAUDE.md). |
| `desktop/js/configuration.js` | **créé** | Handler clic → `$.ajax` POST `action=testConnection`, désactivation du bouton, `showAlert` du résultat. |
| `core/class/imou.class.php` | **non touché** | Pas de méthode `testConnection()` : l'AJAX appelle directement la brique `imouApi` (suppression de l'indirection, finding advisor #1). |

### Décisions issues du challenge advisor
1. **Pas de `imou::testConnection()`** — indirection sans valeur ; l'AJAX consomme directement le
   contrat public `imouApi::getToken(true)`.
2. **Test sur config enregistrée** (`getToken` lit `config::byKey`). Une note d'info statique
   au-dessus du bouton prévient l'utilisateur d'enregistrer avant de tester. L'option « POSTer
   appId/appSecret » est **rejetée** : `appSecret` apparaîtrait dans la console réseau → viole le
   critère « aucun secret affiché ».
3. **Conteneur d'alerte renommé `#imou_test_alert`** (au lieu de `#div_alert`, déjà utilisé par le
   core → collision DOM).
4. **JS dans un fichier dédié `desktop/js/configuration.js`** chargé par `include_file(...)` (et non
   `<script>` inline ni `<script src>` brut) : exécution garantie par le core **et** traduction des
   `{{...}}` assurée par le moteur i18n Jeedom.
5. **Message d'erreur composite** (FR fixe + `msg`/`code` IMOU bruts) : incohérence linguistique
   acceptée comme connue en MVP — utile au diagnostic, sans secret.

## Server vs Client

- **Serveur** : action AJAX (PHP natif). Garde `isConnect('admin')` déjà présente. L'appel réseau
  IMOU, le déchiffrement de l'`appSecret` et la génération des messages (`__()`) sont **exclusivement
  serveur**. La réponse ne contient que du texte (succès / message d'erreur + code IMOU) — jamais de
  token ni de secret.
- **Client** : JS minimal (un handler clic + `$.ajax` + `showAlert`). Aucune logique métier, aucune
  donnée sensible manipulée.

## Validation

- **Client** : le bouton (`<a class="btn">`, pas de submit) déclenche seulement l'AJAX ; désactivé
  pendant l'appel, réactivé au retour. `global:false` pour ne pas déclencher le loader/handler
  d'erreur global du core. Note d'info statique « test sur config enregistrée ».
- **Serveur** : `imouApi::getToken(true)` valide de bout en bout config → signature → token. Le cas
  non-configuré est déjà géré par `call()` (`CODE_CONFIG` → message « Plugin IMOU non configuré »).
  Tout échec lève `imouException` (code + msg nettoyés anti log-injection).

## Server Actions / API

```php
// core/ajax/imou.ajax.php — ajouté avant le throw "aucune méthode"
if (init('action') == 'testConnection') {
    try {
        imouApi::getToken(true); // throws imouException sur échec
    } catch (imouException $e) {
        ajax::error(
            __('Échec de connexion IMOU', __FILE__) . ' : ' . $e->getMessage() . ' (' . $e->getImouCode() . ')',
            $e->getCode()
        );
    }
    ajax::success(__('Connexion IMOU réussie', __FILE__));
}
```

`ajax::success()` / `ajax::error()` terminent le script ; le `throw` final reste pour les actions
inconnues. Les exceptions non-`imouException` retombent dans le `catch (Exception)` global existant.

```js
// desktop/js/configuration.js
$('#bt_imouTestConnection').off('click').on('click', function () {
  // disable bouton + showAlert "en cours" → $.ajax POST {action:'testConnection'}
  //   success: data.state=='ok' ? showAlert(data.result,'success') : showAlert(data.result,'danger')
  //   error  : showAlert(message générique, 'danger')
  //   always : réactiver le bouton
})
```

## Dépendances

**Aucune.** 100 % PHP natif + jQuery/`showAlert`/`include_file` fournis par le core.

## i18n — nouvelles clés FR (source, enveloppées ; traduction déléguée Étape 10)

- `plugins/imou/plugin_info/configuration.php` :
  `Tester la connexion`, `Le test porte sur la configuration enregistrée. Enregistrez la config avant de tester.`
- `plugins/imou/desktop/js/configuration.js` :
  `Test de connexion en cours…`, `Erreur de communication avec le serveur Jeedom`
- `plugins/imou/core/ajax/imou.ajax.php` :
  `Connexion IMOU réussie`, `Échec de connexion IMOU`

## Critères d'acceptation (rappel)

- [ ] Identifiants valides → message succès (« Connexion IMOU réussie »).
- [ ] Identifiants invalides → message d'erreur clair (msg + code IMOU).
- [ ] Aucun secret affiché (message ni console réseau).

## Points à valider sur instance Jeedom réelle

1. `include_file('desktop','configuration','js','imou')` charge bien le JS dans le modal de config
   et les `{{...}}` y sont traduits.
2. `showAlert` rend l'alerte dans `#imou_test_alert` (pas de collision avec le `#div_alert` du core).
3. Comportement réel des codes IMOU sur identifiants erronés (msg/code remontés à l'utilisateur).
