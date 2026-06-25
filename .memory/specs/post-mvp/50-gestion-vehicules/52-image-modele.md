# 52 — Image / vignette du modèle

**Domaine :** Gestion véhicules · **Dépend de :** UC51 · **Réf. archi :** `jeedom-widgets-commandes.md` § 7 (CSP) · **Statut :** à spécifier

## Objectif / valeur
Afficher une image du véhicule (vignette du modèle) comme image d'équipement, pour une UI plus claire
quand on a plusieurs véhicules.

## Périmètre
- **Inclus** : récupération d'une image de modèle (si l'API en fournit une URL, ou catalogue interne par
  marque/modèle), affectation comme image d'eqLogic (`setImage`/fichier plugin).
- **Exclu** : —

## Détails techniques
- Si `/vehicles` fournit une URL d'image (`pictures`/`_links`) : ⚠️ **CSP** — ne pas pointer une `<img>`
  directe vers une URL externe sur un dashboard ; la **télécharger côté serveur** une fois et la stocker
  comme **asset local du plugin** (origine `'self'`), puis l'utiliser comme image d'équipement.
- Sinon : petit **catalogue d'icônes** par marque embarqué dans le plugin (fallback).

## Critères d'acceptation
- [ ] Chaque véhicule a une image cohérente (modèle ou icône de marque) sans blocage CSP.
- [ ] L'image est servie en local (pas de dépendance réseau au rendu du dashboard).

## À confirmer
- Disponibilité d'une URL d'image de modèle dans la réponse API consommateur.
