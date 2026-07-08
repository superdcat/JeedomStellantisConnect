# Spec technique — MVP 09 — État de connexion & fraîcheur de la donnée

> Découle de `09-etat-connexion-fraicheur.md`. **100 % local, aucun appel réseau** (guardrail anti-ban).
> La commande info `last_update` existe déjà (UC07 : `definitionsCommandes()`, `createCommands()`,
> `extraireFraicheur()`) → critère d'acceptation #1 déjà couvert. UC09 ajoute l'**état de connexion**,
> la **page Santé** et le **bandeau** de la page plugin.

## Architecture

Tout côté `core/class/stellantis.class.php` (méthodes statiques sur `stellantis`) + rendu HTML dans
`desktop/php/stellantis.php`. **Pas de nouvel endpoint AJAX, pas de JS** (bandeau rendu serveur, page
Santé câblée par le core).

Fichiers :
- **`core/class/stellantis.class.php`**
  - `stellantis::connectionState(): array` — état global du **lien au compte** (auth), sans réseau.
  - `stellantis::health(): array` — contrat core Jeedom (vérifié dans `plugin.class.php` :
    `method_exists($plugin->getId(), 'health')` → méthode **statique** sur la classe du plugin).
  - `stellantis::formaterAge(int $_ts): string` — helper privé « à l'instant / il y a N min/h/j ».
  - `refreshTelemetry()` — persiste l'état privacy du véhicule en cache.
  - `cron()` — pose/efface le flag `link_error`.
- **`desktop/php/stellantis.php`** — bandeau Bootstrap en tête de page (appel `connectionState()`).

## Server vs Client

100 % **serveur** (PHP). `connectionState()` ne lit que le cache (token + flags) → sûr et rapide à
appeler au rendu de la page. Pas de client : éviter un endpoint AJAX réduit la surface d'attaque et
n'apporte rien (l'état ne change qu'après (ré)authentification, qui recharge la page). La page Santé est
entièrement gérée par le core à partir de `health()`.

## Validation

- **connectionState()** — priorité stricte, aucune exception levée :
  1. `!stellantis::isConfigured()` → `['state'=>'unauthenticated', 'detail'=> « Plugin non configuré… »]`.
  2. `stellantisApi::getTokenInfo()['authenticated'] === false` → `['state'=>'unauthenticated',
     'detail'=> « Aucune connexion au compte ou session expirée… »]` (libellé réutilisé de
     `messageDepuisException`/auth_required).
  3. cache `stellantis::link_error` présent → `['state'=>'error', 'detail'=> message mappé]`
     (`rate_limited` → « Trop de requêtes… », sinon « API injoignable… »).
  4. sinon → `['state'=>'ok', 'detail'=> « Connecté au compte Stellantis »]`.
  ⚠️ `getTokenInfo()['authenticated']` reste `true` tant qu'un token existe **même expiré** (pas de
  contrôle d'expiration pour ce booléen) → d'où l'utilité du flag `link_error` pour distinguer un
  refresh KO (rate-limit/transport) d'un état `ok`.
- **health()** — jamais d'exception ; robuste à l'absence de donnée :
  - 1 ligne globale « Connexion au compte » : `result` = detail de `connectionState()`, `state` =
    (`connectionState()['state'] == 'ok'`), `advice` = conseil de reconnexion si non-ok.
  - 1 ligne par véhicule **activé** (`eqLogic::byType('stellantis', true)`), `test` = `getName()` :
    - privacy actif (cache `stellantis::privacy::<id>` présent et ≠ `None`, insensible casse) →
      `result` = « Mode privacy actif… », `state` = **true** (pas une erreur dure), `advice` explicatif.
    - sinon, lecture de la commande `last_update` via **`$cmd->execCmd()`** :
      - valeur exploitable (`strtotime` ≠ false) → `result` = « Dernière donnée reçue <âge> »,
        `state` = true.
      - absente/illisible → `result` = « Aucune donnée remontée pour le moment », `state` = false,
        `advice` = « Les données seront renseignées au prochain rafraîchissement ».
- **formaterAge(int)** : `< 60s` → « à l'instant » ; `< 3600s` → « il y a N min » ; `< 86400s` →
  « il y a N h » ; sinon « il y a N j ». Chaînes **littérales** dans `__()` (extracteur i18n).
- **Bandeau desktop/php** : `state=='ok'` → alert `success` ; `unauthenticated` → `warning` ;
  `error` → `danger`. `detail` déjà traduit par `connectionState()` (comme `testConnection()`).

## Server Actions / API

Aucun appel HTTP. Signatures :
- `public static function connectionState(): array` → `['state'=>'ok'|'unauthenticated'|'error',
  'detail'=>string]`. **Écart assumé vs spec** : le mode `privacy` de la spec est **par véhicule** →
  traité dans `health()`, pas dans l'état global (qui reste l'état du lien compte/auth). Documenté
  dans la spec fonctionnelle 09.
- `public static function health(): array` → liste de `['test'=>string, 'result'=>string,
  'advice'=>string, 'state'=>bool]`.
- `private static function formaterAge(int $_ts): string`.

Modifs de méthodes existantes :
- `refreshTelemetry()` : après calcul de `$privacy`, `cache::set('stellantis::privacy::'.$this->getId(),
  $privacy, 172800)` (2 j ; auto-purge des équipements supprimés, écriture locale → sans impact anti-ban).
- `cron()` : sur succès de `getToken()` → `cache::delete('stellantis::link_error')` ; sur échec →
  `$type = $e instanceof stellantisException ? $e->getApiType() : 'error'` ; si `rate_limited`/`transport`
  → `cache::set('stellantis::link_error', $type, 3600)`, sinon `cache::delete(...)` (auth_required =
  token supprimé = `unauthenticated` naturel). Branche « 0 véhicule » → `cache::delete('stellantis::link_error')`.
  Le flag ne couvre **que** la couche token/priming (une passe), **pas** les échecs `/status` par
  véhicule (déjà catchés/loggués) : l'état global reflète le lien compte/auth, pas chaque panne API.

## Dépendances

Aucune (100 % PHP, cœur Jeedom).

## Impact i18n (FR — traduction différée étape 10)

Nouvelles clés (les libellés d'erreur déconnecté/rate-limit/transport **réutilisent** des chaînes déjà
présentes dans le fichier) :
- `Connecté au compte Stellantis`
- `Erreur au dernier rafraîchissement : consultez les logs du plugin` (flag link_error non reconnu)
- `Connexion au compte`
- `Reconnectez-vous depuis la configuration du plugin` (advice)
- `Mode privacy actif — données de localisation restreintes`
- `Le véhicule masque ses données (mode vie privée) : ce n'est pas une erreur` (advice)
- `Aucune donnée remontée pour le moment`
- `Les données seront renseignées au prochain rafraîchissement` (advice)
- `Dernière donnée reçue %s`
- `à l'instant`, `il y a %d min`, `il y a %d h`, `il y a %d j`
- Bandeau : `État de la connexion` (titre)
