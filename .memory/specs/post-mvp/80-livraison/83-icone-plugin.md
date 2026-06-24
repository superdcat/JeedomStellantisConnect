# UC — Icône du plugin

**Domaine :** Livraison / packaging · **Dépend de :** — · **Complémentaire de :** UC82 (packaging/doc) · **Statut :** à spécifier (tech)

## Objectif / valeur
Doter le plugin d'une **icône propre** (identité visuelle dans le store de plugins et l'en-tête de
configuration) à la place de l'icône par défaut générée à la création du squelette.

## Périmètre
- **Inclus** : génération d'une icône respectant les **prérequis Jeedom**, intégration dans le plugin,
  remplacement de l'icône par défaut.
- **Exclu** : les images des **équipements** (par modèle de caméra) — couvert par UC55.

## Esquisse Jeedom
- Emplacement/format de l'icône plugin **à confirmer en tech** (typiquement un PNG sous `plugin_info/`,
  référencé par le core ; vérifier dimensions, fond transparent, et le champ éventuel d'`info.json`).
- Générer une icône cohérente avec le thème « caméra / sécurité IMOU ».

## Critères d'acceptation
- [ ] La nouvelle icône s'affiche dans la liste des plugins et l'en-tête de configuration.
- [ ] L'icône respecte les contraintes Jeedom (format, dimensions, transparence).
- [ ] L'icône par défaut du squelette n'est plus utilisée.

## À confirmer
- Chemin et nom de fichier exacts de l'icône plugin attendus par Jeedom (et déclaration dans `info.json`
  si requise) → consulter la doc Jeedom packaging.
- Contraintes précises (taille en px, format PNG/SVG, poids).
