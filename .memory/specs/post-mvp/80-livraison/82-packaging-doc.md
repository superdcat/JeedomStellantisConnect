# 17 — Packaging & documentation utilisateur

**Phase :** Post-MVP · **Dépend de :** 10 · **Fichiers :** `plugin_info/*`, `docs/fr_FR/*`

## Objectif
Préparer le plugin pour l'installation et l'usage : dépendances propres, manifeste correct,
documentation d'installation et de configuration.

## Périmètre
- **Inclus** : icône du plugin, finalisation de la doc fr_FR (captures, cas d'usage),
  enrichissement du changelog au fil des versions, préparation à la publication.
- **Exclu** : publication Market (étape ultérieure).

> ✅ **Déjà fait** (hors de cette UC) : `packages.json` vidé et `info.json` `hasDependency:false`
> (cible 100 % PHP, sans Python) ; `docs/fr_FR/index.md` et `changelog.md` initiaux rédigés.

## Détails techniques
- `info.json` : vérifier en continu la cohérence (`category` `security`, `require`,
  `compatibility`, version) à chaque release.
- `docs/fr_FR/index.md` : compléter le guide (captures d'écran, dépannage) au fur et à mesure
  des fonctionnalités livrées.
- `docs/fr_FR/changelog.md` : ajouter une entrée par version.
- Icône du plugin (`plugin_info/imou_icon.png`) conforme aux contraintes Market.

## Critères d'acceptation
- [ ] Un nouvel utilisateur peut configurer le plugin en suivant la doc, sans aide.
- [ ] `info.json` valide (manifeste accepté par les workflows Jeedom).
- [ ] Icône présente et conforme.

## Notes / risques
- Aligner avec les workflows CI Jeedom (`work.yml`) qui valident le plugin.
