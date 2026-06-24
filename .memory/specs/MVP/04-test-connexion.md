# 04 — Test de connexion depuis la config plugin

**Phase :** MVP · **Dépend de :** 03 · **Fichiers :** page config plugin, `core/ajax/imou.ajax.php`, `core/class/imou.class.php`

## Objectif
Donner un retour immédiat sur la validité des identifiants : un bouton « Tester la connexion »
dans la page de configuration du plugin.

## Périmètre
- **Inclus** : bouton UI + action AJAX + appel `getToken()` + message succès/erreur.
- **Exclu** : découverte des appareils (tâche 05).

## Détails techniques
- AJAX : ajouter dans `imou.ajax.php` une branche `init('action') == 'testConnection'` →
  `imou::testConnection()` qui appelle `imouApi::getToken(true)` et retourne `ajax::success()`
  ou `ajax::error()` avec un message lisible.
- UI : bouton dans la config plugin déclenchant un appel `$.ajax` vers
  `plugins/imou/core/ajax/imou.ajax.php` ; afficher le résultat via `$('#div_alert').showAlert(...)`.
- Garde : `isConnect('admin')` côté AJAX (déjà présent dans le squelette).

## Critères d'acceptation
- [ ] Avec des identifiants valides → message de succès (ex. « Connexion IMOU réussie »).
- [ ] Avec des identifiants invalides → message d'erreur clair (code/msg IMOU traduit si possible).
- [ ] Aucun secret affiché dans le message ni dans la console réseau.

## Notes / risques
- Bon point d'entrée pour valider de bout en bout les tâches 01→03 avant d'aller plus loin.
