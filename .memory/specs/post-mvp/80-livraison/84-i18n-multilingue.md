# 84 — Internationalisation (multilingue)

**Domaine :** Livraison · **Dépend de :** (toutes les UC avec UI) · **Statut :** vivant (sous-agent `translator`)

## Objectif / valeur
Garantir que le plugin est **nativement multilingue** : `fr_FR` (source), `en_US`, `de_DE`, `es_ES`.
Toute chaîne UI livrée doit avoir ses **3 traductions**.

## Périmètre
- **Inclus** : enveloppage systématique des chaînes UI (`{{...}}` HTML/JS, `__('...', __FILE__)` PHP),
  fichiers `core/i18n/{en_US,de_DE,es_ES}.json` (pas de `fr_FR.json`), `description` `info.json` 4 langues,
  docs dupliquées par langue.
- **Exclu** : traduction des commentaires/logs/noms de variables (restent en français).

## Détails techniques
- Processus : pendant le dev, on **enveloppe en français** sans toucher aux JSON ; la traduction des 3
  langues est produite **en fin de cycle** par le sous-agent `translator` (contexte isolé, code figé),
  qui valide lui-même que les 3 JSON parsent (cf. `.claude/agents/translator.md` et `/feature` étape 10).
- Chemin de clé : `plugins/stellantis/<chemin/relatif/fichier>`. Délimiteurs JSON = guillemets droits `"`
  uniquement (piège des guillemets courbes).

## Critères d'acceptation
- [ ] Aucune chaîne UI en dur (toutes enveloppées en français).
- [ ] Chaque clé UI livrée existe dans **les 3** langues cibles ; les 3 JSON parsent.
- [ ] `description` d'`info.json` fournie pour les 4 langues.

## Notes
- Terminologie véhicule cohérente (FR « véhicule/charge/autonomie/verrouillage » → EN/DE/ES usuels).
