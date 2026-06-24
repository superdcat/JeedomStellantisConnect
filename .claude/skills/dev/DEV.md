---
name: dev
description: Applique un workflow de Development. Active-toi quand l'utilisateur demande d'appliquer la skill dev, tu réalise l'implémentation jusqu'à ce qu'on soit au niveau attendu.
---

# Skill — DEV Workflow

Implémentation du plugin **Jeedom IMOU** : coder une UC à partir de sa spec, **piloté par ses
critères d'acceptation**, jusqu'à ce qu'ils soient tous verts et que les reviews croisées passent.

## Quand t'activer
- L'utilisateur demande explicitement le skill DEV ;
- L'étape 6 de `/feature` t'invoque.

## Contexte (déjà chargé — ne pas recharger)

**Conventions, i18n, secrets, architecture, classes** : tout est dans **`CLAUDE.md`** (chargé chaque
session) — ne le redécris pas. **Specs source** : `.memory/specs/` ; chaque spec porte ses
**Critères d'acceptation** = la DoD. Doute sur un contrat API/core → consultation **à la demande** via
les INDEX (cf. `/feature` § *Consultation*), pas « par sécurité ».

Deux rappels critiques (fatals **invisibles à `php -l`**) :
- **Tout HTTP IMOU passe par `imouApi`** — aucun cURL ailleurs.
- **Autoload 1 classe ↔ 1 fichier** : jamais d'appel direct `imouApi::` / `imouException` depuis un
  point d'entrée externe (`*.ajax.php`, hooks cron, `desktop/php/*.php`, `install.php`). Router via
  `imou`/`imouCmd` (dont `imou.class.php` charge aussi `imouApi`/`imouException`).

## Vérification sur ce stack (pas de tests unitaires, pas de PHP local garanti)

Dans l'ordre : (1) critères d'acceptation → **checklist concrète observable** (notre « test
d'abord ») ; (2) `php -l` si dispo — **syntaxe seule**, ne détecte PAS un « Class not found » ;
(3) reviews croisées `code-reviewer` + `security-reviewer` ; (4) CI Jeedom au push ; (5) recette
manuelle (`.memory/specs/post-mvp/80-livraison/81-validation-manuelle.md`).
**Ne jamais prétendre qu'un comportement runtime est validé sans l'avoir constaté** (lint OK ≠ feature OK).

## Boucle (répéter jusqu'à convergence)

1. **Cadrer** : charger la spec ; reformuler ses critères d'acceptation en checklist observable
   (ex. « Éteindre → image coupée + `camera_state=0` » ; « 2e synchro → 0 doublon ») ; vérifier les
   dépendances (« Dépend de ») ; lister les fichiers à toucher.
2. **Implémenter par petits incréments** : le minimum pour **un critère à la fois** ; réutiliser
   l'existant (`imouApi`, helpers `eqLogic`/`cmd`/`config`/`cache`) ; **idempotence** via `logicalId`
   (pas de doublon en re-sync) ; pas d'invention sur un endpoint « À confirmer » (confirmer ou signaler).
   **i18n** : envelopper chaque chaîne UI en **français** (`{{...}}` / `__()`) — **ne PAS toucher aux
   `core/i18n/*.json`** : la traduction des 3 langues est produite en fin de `/feature` par le
   sous-agent `translator` (contexte isolé), sur le code figé.
3. **Vérifier** : `php -l` (si dispo) + **check autoload** (toute classe référencée depuis un point
   d'entrée externe a son `<Classe>.class.php`, ou transite par `imou`/`imouCmd`) + dérouler la
   checklist (ce qui exige un Jeedom réel → « à valider en recette »).
4. **Auto-revue** : passer la checklist qualité ci-dessous ; corriger ce qui est rapide.
5. **Itérer** : reprendre en 2 tant que des critères ne sont pas couverts.
6. **Reviews croisées** : `security-reviewer` + `code-reviewer` **en parallèle** sur les fichiers
   touchés. Corriger tant qu'il reste `blocker`/`major` (ou `critical`/`high`), puis **relancer**.
   `minor` : corrigés si rapide, sinon listés. Synthèse.
7. **Livrer** : une tâche = **un commit** (FR, impératif) ; jamais refactor + feature mêlés ; branche
   dédiée si on est sur `main` ; ne push/commit **que si demandé**.

## Checklist qualité (spécifique Jeedom/IMOU)

- [ ] Tous les **critères d'acceptation** couverts (ou marqués « à valider en recette »).
- [ ] Tout appel IMOU via `imouApi` ; **autoload** OK (pas d'appel direct `imouApi::` / `imouException`
      depuis un point d'entrée externe).
- [ ] **Fidélité spec** : le chemin d'appel suit la spec (ex. UC04 : AJAX → `imou::testConnection()`,
      pas directement `imouApi::getToken()`).
- [ ] Aucun **secret/token en clair** (logs, DOM, réponses AJAX, commentaires).
- [ ] Chaînes UI enveloppées en **français** (`{{...}}` / `__()`) ; **pas** d'édition des JSON i18n
      (déléguée au `translator` en étape 10 de `/feature`).
- [ ] **Idempotence** : re-synchro/re-save sans doublon, personnalisations préservées.
- [ ] Erreurs API **non silencieuses** : `log::add('imou','error',…)` + remontée propre ; jamais un `catch` vide.
- [ ] **Robustesse cron** : une caméra en erreur n'interrompt pas la boucle (try/catch par équipement).
- [ ] Indentation 2 espaces ; pas de code mort ni de `var_dump`/debug oublié.
- [ ] `php -l` OK (ou impossibilité signalée) ; la page admin et le cron **ne plantent pas** si config vide / API injoignable.

## Présentation finale

Fichiers créés/modifiés ; état des critères (couverts / à valider en recette) ; synthèse des reviews
(verdicts + findings restants) ; restes « À confirmer » + étapes de recette manuelle.

## Principes

**Spec d'abord** (rien hors périmètre sans accord) ; **honnêteté de vérif** (lint/relu ≠ testé sur
Jeedom réel) ; **petits pas** (incréments courts, reviewables) ; **pas d'invention** sur un
endpoint/paramètre non confirmé (on vérifie ou on signale).
