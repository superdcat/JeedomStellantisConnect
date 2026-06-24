# Spec technique — MVP 01 — Configuration globale du plugin

> Spec fonctionnelle : `01-config-plugin.md`. Plan validé par l'utilisateur après challenge `code-reviewer` (advisor).

## Architecture

Trois champs de configuration **niveau plugin** (`config::*(..., 'imou')`) :
`appId`, `appSecret` (chiffré), `datacenter` (clé symbolique → host API).

Fichiers touchés :

| Fichier | Action | Rôle |
|---|---|---|
| `plugin_info/configuration.php` | réécrit | Formulaire de la page de config plugin (ouverte par `gotoPluginConf`). 3 champs liés en `class="configKey" data-l1key="<clé>"`. |
| `core/class/imou.class.php` | modifié | (1) mapping `datacenter → baseUrl` ; (2) hook `preConfig_appSecret()` (chiffrement) ; (3) helper `imou::getApiConfig()`. |
| `core/i18n/{en_US,de_DE,es_ES}.json` | **non touché ici** | Traduction déléguée à l'agent `translator` (Étape 10). |

### Convention de binding (vérifiée sur la baseline)
La config **niveau plugin** utilise `class="configKey form-control" data-l1key="<clé>"`
(le core auto-charge/sauve via `config::byKey/save(..., 'imou')`). C'est **différent** du
pattern eqLogic (`data-l1key="configuration" data-l2key="…"`). Le hook plugin
`<classe>::preConfig_<clé>($value)` est appelé par le core **avant** la persistance.

## Server vs Client

**Server-side PHP.** Le formulaire est rendu serveur ; la persistance est **native au core**
(pas de route AJAX custom pour le MVP 01). Le chiffrement/déchiffrement de `appSecret` est
**exclusivement serveur** (`utils::encrypt/decrypt`), jamais exposé au DOM ni loggué.
Côté client : seul le JS générique du core (`configKey`) charge/sauve les champs.

## Validation

- **Client** : minimale. `datacenter` est un `select` avec l'option **Europe en premier**
  (défaut visuel à la première installation, aucune valeur stockée). `appId`/`appSecret`
  ont un placeholder indicatif. Pas de blocage dur (le test de connexion réel = tâche 04).
- **Serveur** :
  - `preConfig_appSecret($value)` ne re-chiffre **que** si une nouvelle valeur est saisie.
    Au rechargement, le champ `configKey` contient la valeur **déjà stockée (chiffrée)** :
    on la compare à la valeur courante et, si identique (ou vide), on **conserve** sans
    re-chiffrer. → pas de double-chiffrement, **pas d'heuristique de format** (égalité exacte).
  - `getApiConfig()` renvoie des **défauts propres** si non configuré (`appId=''`,
    `appSecret=''`, `baseUrl` = host Europe), pour que les tâches suivantes gèrent
    proprement l'état « non configuré ».

## Server Actions / API

Aucune route AJAX. Une méthode statique interne :

```php
// Mapping région → host (à confirmer en doc IMOU)
imou::$_datacenterHosts = [
  'europe'  => 'https://openapi-fr.easy4ip.com', // défaut
  'asia'    => 'https://openapi-sg.easy4ip.com',
  'america' => 'https://openapi-or.easy4ip.com',
];

// Chiffrement à la sauvegarde (hook plugin appelé par le core)
public static function preConfig_appSecret(string $value): string;
//  - $value vide OU === valeur stockée  → retourne la valeur stockée (inchangée)
//  - sinon (nouvelle saisie en clair)    → retourne utils::encrypt($value)

// Helper de lecture consommé par imouApi (tâche 02)
public static function getApiConfig(): array; // ['appId' => …, 'appSecret' => …(déchiffré), 'baseUrl' => …]
```

## Dépendances

**Aucune.** 100 % PHP natif (`config::*`, `utils::encrypt/decrypt`, `log::add` fournis par le core).

## i18n — nouvelles clés FR (source, enveloppées `{{...}}`)

Sous le chemin `plugins/imou/plugin_info/configuration.php` :
`Identifiants développeur IMOU`, `App ID`, tooltip appId, `App Secret`, tooltip appSecret,
`Laisser vide pour conserver la valeur actuelle`, `Datacenter`, tooltip datacenter,
`Europe`, `Asie`, `Amérique`.

> Les anciennes clés du template (`Global param 1/2/3`, etc.) disparaissent : l'agent
> `translator` (Étape 10) produit les 3 langues et signale les éventuelles orphelines.

## Critères d'acceptation (rappel)

- [ ] 3 champs saisissables et persistés (survivent rechargement/redémarrage).
- [ ] `appSecret` jamais en clair (DB chiffrée, pas de log, masqué `inputPassword` au DOM).
- [ ] `imou::getApiConfig()` retourne `['appId','appSecret','baseUrl']` prêt à l'emploi.

## Points à valider sur instance Jeedom réelle

1. Le hook `preConfig_appSecret` est bien invoqué par le core à la sauvegarde de la config plugin.
2. Le rechargement du champ `appSecret` (valeur chiffrée) ne déclenche pas de re-chiffrement.
3. Hosts datacenter exacts (doc IMOU Open Platform).
