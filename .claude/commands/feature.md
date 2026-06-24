---
description: Déroule le workflow agentic complet sur une feature à partir de sa spec fonctionnelle. Génère le plan technique, lance la boucle de dev + implémentation, exécute les reviews croisées.
argument-hint: [nom-de-la-spec]
---

# Workflow agentic complet

Tu vas dérouler le workflow complet pour la feature `$ARGUMENTS`.

## Consultation doc & connaissance — À LA DEMANDE seulement (lazy)

Tu codes à partir de la spec et de `CLAUDE.md` (déjà en contexte). **Ne charge RIEN « par sécurité ».**
Ne consulte une source externe/interne **que si une incertitude concrète te bloque** (typiquement
étapes 2, 6, 7) ; sinon, avance.

Quand c'est le cas, dans cet ordre et en t'arrêtant dès que tu as la réponse — chaque INDEX porte son
propre mode d'emploi en en-tête, ne le redécris pas ici :

1. **Connaissance interne d'abord** (local, gratuit, propre au projet) : `.memory/analyse/INDEX.md`
   (§ 0 = incertitude → fichier), puis ouvre **uniquement** le fichier pointé. `.memory/specs/README.md`
   donne le **statut de fiabilité** des endpoints.
2. **Doc IMOU** (contrat exact de l'API cloud) : `.memory/external/doc/imou/INDEX.md` (§ 0 =
   déclencheur → page), puis **un seul `WebFetch`** sur l'URL de la page. Jamais `start.html` d'abord.
3. **Doc Jeedom** (contrat du core) : `.memory/external/doc/jeedom/INDEX.md`. Pour une signature de
   classe core (`cache::`, `config::`, hooks `eqLogic`/`cmd`…), lis la **source du core**, pas le wiki.

Astuce tokens : **`grep` l'INDEX** pour la ligne utile plutôt que de le `Read` en entier. **Cite**
l'info retenue (endpoint, champ, code d'erreur) et sa source. Si une source **contredit** une
spec/analyse interne, **signale l'écart** — ne tranche pas en silence (la doc officielle fait foi sur
le contrat, l'analyse interne sur les décisions projet).

## Étape 1 — Charger la spec fonctionnelle

Lis la spec fonctionnelle `$ARGUMENTS` sous `.memory/specs/` (ex. `.memory/specs/**/$ARGUMENTS.md`).
Confirme en 1-2 phrases ce que tu as chargé.

## Étape 2 — Générer le plan technique

Sur la base de la spec, propose un plan concis :

- **Contrat API IMOU** : pour chaque appel cloud, l'endpoint, les paramètres et le format de réponse.
  En cas de doute, applique *Consultation à la demande* (interne d'abord, puis doc IMOU) **avant** de
  figer le plan — pas en cours d'implémentation.
- Type de composant ; architecture (fichiers à créer/modifier) ; logique de validation ;
  appels AJAX / actions nécessaires ; dépendances éventuelles.
- **Impact i18n** : lister les nouvelles chaînes UI **en français uniquement** (clés `{{...}}` /
  `__()`). La traduction est différée (étape 10) — ici on anticipe juste la liste FR.

## Étape 3 — Challenge par advisor

Invoque le sous-agent `code-reviewer` en mode advisor (revue critique du **plan**, pas du code) :
risques d'architecture, points de convention, suggestions. Présente la synthèse.

## Étape 4 — Validation utilisateur du plan

**ARRÊTE-TOI ICI** et demande :

> "Le plan technique te convient-il ? Veux-tu ajuster avant l'implémentation ? (oui / propose des ajustements)"

Attends sa réponse.

## Étape 5 — Écriture de la spec technique

Plan validé, écris la spec technique dans `.memory/specs/[même dossier]/$ARGUMENTS-tech.md` :

```markdown
# Spec technique — $ARGUMENTS

## Architecture
[composants, fichiers, structure]

## Server vs Client
[décision et justification]

## Validation
[stratégie côté client et serveur]

## Server Actions / API
[signatures et logique]

## Dépendances
[paquets à installer si nécessaires]
```

## Étape 6 — Boucle dev (skill `dev`) — **français uniquement**

Active le skill `dev` pour écrire le code à partir de la spec. En cas d'incertitude sur un contrat API
pendant le codage, applique *Consultation à la demande* plutôt que de deviner.

**i18n : on code en français, point.** Chaque chaîne UI est enveloppée dès l'écriture
(`{{Texte français}}` en HTML/JS, `__('Texte français', __FILE__)` en PHP). **Ne touche PAS aux
`core/i18n/{en_US,de_DE,es_ES}.json`** : la traduction est entièrement déléguée au sous-agent
`translator` en étape 10, sur le code figé.

## Étape 7 — Vérification

Vérifie que le plugin continuera de fonctionner dans Jeedom. Pour les appels cloud, **valide** que
endpoints, paramètres et parsing codés correspondent au contrat réel (recoupe interne + doc IMOU au
moindre doute résiduel ; signale tout écart code/spec/analyse/doc).

i18n à ce stade : vérifie **uniquement l'enveloppage français** (aucune chaîne en dur). La couverture
des 3 langues n'est **pas** attendue ici (étape 10) — ne la compte pas comme un défaut.

## Étape 8 — Reviews croisées

Lance **en parallèle** sur les fichiers créés/modifiés :
- sous-agent `security-reviewer` ;
- sous-agent `code-reviewer`.

Précise-leur que la traduction est **volontairement différée** : ils évaluent le code FR et
l'enveloppage (`{{...}}` / `__()`), mais l'absence de traduction `en_US`/`de_DE`/`es_ES` n'est **pas**
un finding ici. Présente une synthèse.

## Étape 9 — Décision finale utilisateur

Si findings `critical`/`high`, demande :

> "Reviews terminées. [N findings critiques/high]. Je propose des fix maintenant ou je continue ? (fix / continue)"

Attends la réponse. Si fix demandés : applique-les puis relance les reviews.

## Étape 10 — Traduction (sous-agent `translator`) — **une fois tout validé**

Le code FR est figé et validé. Invoque le sous-agent `translator` sur les fichiers créés/modifiés. Il doit :
- extraire toutes les clés UI françaises (`{{...}}` / `__()`) introduites/modifiées ;
- remplir/mettre à jour `core/i18n/{en_US,de_DE,es_ES}.json` sous `plugins/imou/<fichier>` ;
- garantir la **couverture complète des 3 langues**, signaler toute **clé orpheline** ;
- **valider lui-même que les 3 JSON parsent** avant de rendre la main (il dispose de `Bash` et lance la
  validation Python ; il ne rend `pass` que si les 3 fichiers parsent). **Tu n'as donc PAS à re-valider
  les JSON au retour** — c'est garanti par le sous-agent (un `pass` = 3 fichiers valides) ;
- si la `description` du plugin a changé : mettre à jour `info.json` (4 langues) et `docs/{fr_FR,en_US,de_DE,es_ES}/`.

Si verdict `needs_changes` (clés manquantes / JSON invalide non corrigé), relance jusqu'à couverture
complète et JSON valides. Présente la synthèse (clés ajoutées par langue, orphelines éventuelles).

## Étape 11 — Présentation finale

```
✅ Feature : $ARGUMENTS

📋 Spec fonctionnelle : .memory/specs/*/$ARGUMENTS.md
📐 Spec technique : .memory/specs/*/$ARGUMENTS-tech.md
💻 Feature : [fichiers créés/modifiés]
🔒 Review sécurité : [verdict]
🎯 Review qualité : [verdict]
🌍 Traduction (en/de/es) : [verdict translator — clés ajoutées par langue]
```

## Étape 12 — Capitalisation mémoire (apprentissages durables)

**Avant de clore**, capture ce que ce cycle a révélé et qui **servira aux features suivantes** —
**uniquement si c'est réellement nouveau**. Si tout est déjà couvert (specs, `CLAUDE.md`, code, doc
locale, git), **n'écris rien** (ni fichier, ni ligne, ni note « rien de neuf ») et clôture.

**Que retenir** (typique ici) : contrat API IMOU non évident confirmé en doc (casse d'un `enableType`,
type réel d'un champ, prérequis, absence de `data`…) ; code d'erreur IMOU et son sens réel ;
comportement empirique d'un quota/limite ; piège du core Jeedom (hook, récursion `save`, autoload) ;
décision d'archi prise pendant le dev.

**Où écrire** (selon la nature) :
1. **Mémoire persistante inter-sessions** (`MEMORY.md` + fichier sous le dossier mémoire de l'agent,
   **hors git**, chargée auto chaque session) : pour un apprentissage transverse utile dès le prochain
   démarrage. Format : 1 fichier = 1 fait (frontmatter `name`/`description`/`metadata.type`, corps FR,
   liens `[[autre]]`), **puis** une ligne de pointeur dans `MEMORY.md`.
2. **`.memory/analyse/`** (**versionné, partagé équipe**) : pour une analyse/décision/limite **propre
   au projet**. ⚠️ N'est utile que s'il reste **découvrable** → écris dans le fichier thématique
   existant (ou crée-en un) **ET mets `.memory/analyse/INDEX.md` à jour** (ligne + déclencheurs § 0 +
   date) — sinon un futur `/feature` ne le relira jamais. Alternative : la spec technique `…-tech.md`
   si l'info est strictement locale à l'UC.
3. **`.memory/specs/README.md`** : si tu as confirmé/infirmé le **statut de fiabilité d'un endpoint**.

> Un apprentissage transverse important mérite souvent **(1) + (2)** : en (1) le fait condensé, en (2)
> l'analyse détaillée — qui se référencent, sans doublon littéral.

**Règles** : avant d'écrire, **vérifie l'existant** (`MEMORY.md`, `.memory/analyse/INDEX.md` + fichier)
et mets à jour plutôt que dupliquer ; supprime une note devenue fausse. N'enregistre **pas** ce que
code/git/`CLAUDE.md`/specs disent déjà. Dates absolues ; **jamais** de secret/token.

Si tu as mémorisé : présente-le en 1-3 lignes. Sinon, ne dis rien de spécial et clôture.
