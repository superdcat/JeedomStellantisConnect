# Spec technique — post mvp 75 (rafraîchissement par défaut à la création)

> Décision validée utilisateur 2026-06-19. Polarité : on poll UNIQUEMENT les infos d'état des
> capacités **visibles par défaut** (UC16) ; toutes les autres infos pollables sont créées avec
> `configuration.noPoll = 1` (case « Exclure du rafraîchissement automatique » d'UC73 déjà cochée).

## Architecture

**1 seul fichier** : `core/class/imou.class.php`. Aucun appel cloud, aucun changement JS/HTML/AJAX,
aucune nouvelle chaîne i18n.

Modifications :

1. **`commandCatalog()`** — ajouter `'defaultVisible' => true|false` à chaque entrée. Encode l'ensemble
   « visible par défaut » (UC16), consommé ici pour dériver le défaut de `noPoll` :
   - `true`  : `closeCamera`, `motionDetect`, `whiteLight`, `siren`.
   - `false` : `linkageSiren` (sirène sur détection), `whiteLightTimer` (projecteur minuté).
   ⚠️ Ce flag pilote **uniquement** le défaut `noPoll` (UC75) ; il **ne pilote pas encore** `isVisible`
   (comportement de visibilité UC16, non implémenté). Mutualisable : UC16 réutilisera le même flag.

2. **`creerCommande()`** — nouveau mécanisme `$options['configurationOnCreate']` (array) :
   - capturer `$isNew = !is_object($cmd)` en tête ;
   - appliquer les clés de `configurationOnCreate` **après** le bloc `configuration` standard, et
     **uniquement si `$isNew`**. Posé une seule fois à la création, jamais reposé au re-sync.
   - **Contrat** (PHPDoc) : `noPoll` ne doit JAMAIS passer par `configuration` (qui est reposé à chaque
     passage et écraserait le choix manuel UC73) — uniquement par `configurationOnCreate`. Les deux
     ensembles de clés sont disjoints (`pollable`/`iotIdentifier`/… vs `noPoll`).

3. **`createCommands()`** (info d'état switch, l.915) — si `$entry['defaultVisible'] !== true` →
   ajouter `'configurationOnCreate' => array('noPoll' => 1)` aux options.

4. **`createIotCommands()`** (info propriété IoT, l.1088) — toujours
   `'configurationOnCreate' => array('noPoll' => 1)` (propriétés IoT masquées par défaut).

5. **`createIotStatusCommands()`** (info statut service IoT, l.1180) — toujours
   `'configurationOnCreate' => array('noPoll' => 1)` (statuts IoT masqués par défaut).

PTZ (`createPtzCommands`) : actions sans état, jamais pollées → non concerné.

## Server vs Client

100 % serveur (défaut structurel posé à la création). Le client est inchangé : la case UC73 lit
`configuration.noPoll` via `cmdAttr` (`data-l2key="noPoll"`) → reflète automatiquement le défaut posé.

## Validation

- **Idempotence** : le défaut n'est posé que dans la branche création (`$isNew`) → jamais réimposé au
  re-sync → choix manuel UC73 préservé (critère 3).
- **Non-régression** : les commandes déjà en base (caméras synchronisées avant UC75) ne passent pas par
  la branche création → aucun `noPoll` ajouté → polling inchangé (critère 4). Attendu, pas une régression.
- Aucune entrée utilisateur, pas de récursion (`cmd::save()` ne rappelle pas `eqLogic::postSave()`).

## Server Actions / API

Aucun nouvel appel cloud. La feature **réduit** le quota par défaut (exclusion pré-cochée). Décision
figée du point « À confirmer » commun UC16↔UC75 : **l'info d'état d'une capacité visible par défaut
(camera/surveillance/projecteur) est elle-même pollée** ; tout le reste (sirène sur détection,
propriétés IoT, statuts IoT) → `noPoll=1` à la création.

## Dépendances

Aucune.

## i18n

Aucune nouvelle chaîne UI (label/tooltip de la case déjà fournis par UC73). Étape translator : rien à faire.

## Notes / risques

- **Caméras antérieures à l'UC** : restent pollées intégralement (le défaut ne touche que les commandes
  nouvellement créées). À documenter en release notes.
- **`defaultVisible` sans UC16** : pas d'incohérence runtime — la commande reste visible
  (`setIsVisible(1)` inchangé) mais non pollée. Cohérent avec la sémantique UC73 (info visible mais
  exclue du polling). UC16 alignera la visibilité ensuite.
