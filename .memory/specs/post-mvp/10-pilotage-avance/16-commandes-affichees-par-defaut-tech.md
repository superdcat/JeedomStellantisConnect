# Spec technique — post mvp 16 (commandes affichées par défaut)

> À la CRÉATION des commandes, n'afficher par défaut que les commandes utiles ; masquer le reste.
> La visibilité posée à la création n'est JAMAIS réécrasée au re-sync (le choix utilisateur survit).
> S'appuie sur le flag `defaultVisible` introduit en UC75 (même donnée que le défaut `noPoll`).

## Architecture

**1 seul fichier** : `core/class/imou.class.php`. Aucun appel cloud, aucun JS/HTML/AJAX, aucune
nouvelle chaîne i18n.

Modifications :

1. **`creerCommande()`** — nouvelle option `$options['visibleOnCreate']` (bool), appliquée **uniquement**
   à la création (`$isNew`). Remplace `setIsVisible(1)` par (forme 2 lignes, pas de ternaire imbriqué) :
   ```php
   // UC16 — visibilité initiale (création seulement). Défaut MASQUÉ (0) si l'option est absente :
   // filet conservateur contre un futur site de création qui l'oublierait (ne pas re-noyer l'UI).
   $visible = empty($options['visibleOnCreate']) ? 0 : 1;
   $cmd->setIsVisible($visible);
   ```
   PHPDoc `@param $options` complété avec `visibleOnCreate`.

2. **`ptzCatalog()`** — ajout de `'defaultVisible' => true` à chaque entrée (PTZ visibles par défaut),
   comme `commandCatalog()` (fait en UC75). Consommé par `createPtzCommands()` dans le même changement.

3. **`createCommands()`** :
   - info d'état switch → `visibleOnCreate => $entry['defaultVisible']` ;
   - actions on/off → `visibleOnCreate => $entry['defaultVisible']` (les DEUX, pour éviter une
     asymétrie action↔état).

4. **`createIotCommands()`** : info d'état IoT et action IoT → `visibleOnCreate => false` (propriétés
   IoT auto-découvertes masquées par défaut).

5. **`createIotStatusCommands()`** : info statut → `visibleOnCreate => false`.

6. **`createPtzCommands()`** : action → `visibleOnCreate => $entry['defaultVisible']` (= true).

UC55 (snapshot) ne crée AUCUNE commande (source d'image = config eqLogic + AJAX) → hors périmètre.

## Server vs Client

100 % serveur (visibilité posée à la création). Aucun changement client.

## Validation

- **Création seulement** (`$isNew`) : la visibilité n'est posée qu'à la création → re-sync ne réécrase
  jamais le choix utilisateur (critère 2). Mécanisme partagé avec le `configurationOnCreate` d'UC75.
- **Non-régression** : commandes déjà en base (caméras synchronisées avant l'UC) → branche création non
  empruntée → visibilité conservée.
- **Défaut masqué (fallback)** : décision utilisateur (2026-06-20) — un appelant qui oublie l'option
  obtient une commande masquée (conservateur). Les 6 sites passent l'option explicitement.
- Capacité absente → aucune commande créée (inchangé, UC12).

## Server Actions / API

Aucun appel cloud. Décisions figées des points « À confirmer » UC16 :
- **Info d'état d'une capacité visible = visible** (cohérent avec UC75 : invariant *visible ⇔ pollée*
  pour les infos d'état switch — `visibleOnCreate` et le défaut `noPoll` dérivent du même
  `defaultVisible`).
- **Projecteur minuté** (`whiteLightTimer`) : **masqué** par défaut (`defaultVisible=false`).

## Dépendances

Aucune.

## i18n

Aucune nouvelle chaîne UI. Étape translator : rien à faire.

## Cohérence UC75

Pour chaque info d'état switch, `visibleOnCreate` et le défaut `noPoll` proviennent du même
`defaultVisible` → invariant *visible ⇔ pollée* respecté de bout en bout. `creerCommande` porte
désormais DEUX mécanismes « création seulement » distincts : `configurationOnCreate` (clés de config,
ex. `noPoll`) et `visibleOnCreate` (colonne `isVisible`).
