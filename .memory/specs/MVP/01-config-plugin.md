# 01 — Configuration globale du plugin

**Phase :** MVP · **Dépend de :** — · **Fichiers :** page de config plugin, `core/class/imou.class.php`

## Objectif
Permettre à l'utilisateur de saisir les identifiants développeur IMOU, communs à toutes les
caméras : `appId`, `appSecret`, et le datacenter régional. Ces valeurs sont stockées au niveau
**plugin** (pas équipement).

## Périmètre
- **Inclus** : page de configuration du plugin (bouton « Configuration » / `gotoPluginConf`),
  3 champs, persistance, chiffrement de `appSecret`.
- **Exclu** : test de connexion (→ tâche 04), gestion du token (→ tâche 03).

## Détails techniques
- Champs :
  - `appId` (texte).
  - `appSecret` (texte, **chiffré**, affiché masqué type `inputPassword`).
  - `datacenter` (select) : `Europe` → `https://openapi-fk.easy4ip.com`,
    `Asie` → `https://openapi-sg.easy4ip.com`, `Amérique` → `https://openapi-or.easy4ip.com`.
    Valeur par défaut : Europe. Hosts **confirmés** dans la doc IMOU (`http/develop.html`) :
    Europe = `openapi-fk` (Frankfurt), Asie = `openapi-sg`, Amérique = `openapi-or`, port 443.
- Stockage : `config::save('key', $val, 'imou')` / lecture `config::byKey('key', 'imou', $default)`.
- Chiffrement de `appSecret` : utiliser les hooks plugin `imou::preConfig_appSecret($value)`
  (chiffre via `utils::encrypt`) et déchiffrer à la lecture côté client (tâche 02). Ne jamais
  logguer `appSecret`.
- Vue : déterminer le fichier de la page de config plugin selon la version Jeedom (souvent
  `plugin_info/configuration.php` ou panneau dédié). Réutiliser la structure `data-l1key`.

## Critères d'acceptation
- [ ] Les 3 champs sont saisissables et persistés (survivent à un rechargement / redémarrage).
- [ ] `appSecret` n'apparaît en clair ni dans la base, ni dans les logs, ni dans le DOM rendu.
- [ ] Un helper PHP `imou::getApiConfig()` retourne `['appId','appSecret','baseUrl']` prêt à l'emploi.

## Notes / risques
- Le mécanisme exact de page de config plugin varie selon la version Jeedom → confirmer avant code.
- Prévoir un défaut propre quand la config est vide (les autres tâches doivent gérer « non configuré »).
