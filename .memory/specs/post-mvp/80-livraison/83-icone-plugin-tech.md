# Spec technique — post mvp 83 (icône du plugin)

> Contrat Jeedom (doc officielle `dev/Icone_de_plugin`, vérifiée 2026-06-20) : icône plugin =
> `plugin_info/<id>_icon.png`, **PNG 309 × 348 px, transparence autour, sans texte** (reco post-2020).
> Pas de déclaration dans `info.json` : le core résout par convention via `plugin::getPathImgIcon()`.
> Le `.htaccess` de `plugin_info/` autorise déjà l'accès web aux `.png` → l'icône est servie.

## Architecture

Asset de packaging + correctif PHP d'une ligne. 3 fichiers, **changement atomique** (ordre : créer →
modifier → supprimer) pour ne jamais laisser un repli pointant sur un fichier absent :

1. **CRÉER `plugin_info/imou_icon.png`** — 309 × 348 px, RGBA, transparence autour.
   Design (validé utilisateur 2026-06-20) : panneau à coins arrondis, **dégradé orange IMOU**
   (`#FF9A4D` → `#F1561A`, distinct du bleu/turquoise Jeedom officiel), **glyphe caméra dôme blanche**
   centrée (plaque de fixation + dôme demi-sphère + objectif sombre + reflet), ombre portée douce,
   **sans texte**.
2. **MODIFIER `desktop/php/imou.php`** (repli défensif, ~l.236) : chaîne de repli
   `plugins/imou/plugin_info/template_icon.png` → `plugins/imou/plugin_info/imou_icon.png`.
   Le primaire reste `getPathImgIcon()` (qui pointe par convention sur `imou_icon.png`). Le guard
   `method_exists` est conservé (changement minimal, in-scope).
3. **SUPPRIMER `plugin_info/template_icon.png`** — icône squelette, plus utilisée (critère 3).

Aussi : mettre à jour la **référence descriptive** caduque dans
`.memory/specs/post-mvp/50-gestion-appareils/55-images-modeles-equipements-tech.md` (l.66,
`template_icon.png` → `imou_icon.png`).

## Server vs Client

Asset statique servi par Apache ; aucun code serveur/cloud. Le correctif PHP est un simple repli de
chemin.

## Validation

- Post-génération : dimensions **exactes** 309 × 348, mode **RGBA**, présence d'un canal alpha non
  trivial (transparence autour), + inspection visuelle (relecture du PNG).
- Aucune entrée utilisateur, aucun appel réseau.

## Server Actions / API

Aucune. La génération du PNG est faite par un **script Python/Pillow de build** (hors arbre plugin,
conservé en scratchpad pour reproductibilité). Rendu en **supersampling ×4 + downscale LANCZOS** pour
un antialiasing propre.

## Dépendances

**Aucune côté plugin** : `packages.json` inchangé, le plugin reste 100 % PHP. Pillow est une dépendance
de **build local** uniquement (n'est jamais livrée ni requise à l'exécution).

## i18n

Aucune nouvelle chaîne UI (les libellés « Icône du plugin » existent déjà depuis UC55). Étape
translator : rien à faire.
