# 83 — Icône du plugin

**Domaine :** Livraison · **Dépend de :** — · **Statut :** à faire

## Objectif / valeur
Doter le plugin d'une **icône** conforme aux exigences du Market Jeedom (obligatoire pour publication),
évoquant le véhicule connecté Stellantis, **sans** réutiliser les codes couleur des icônes officielles
Jeedom ni un logo de marque protégé.

## Périmètre
- **Inclus** : `plugin_info/<id>_icon.png` (et déclaration), visuel véhicule/voiture connectée neutre.
- **Exclu** : branding par marque (Peugeot/Citroën… = marques déposées → éviter les logos officiels).

## Détails techniques
- Respecter les contraintes Jeedom (cf. doc « Icône de plugin ») : format/taille, pas de collision
  couleur avec les icônes du core.
- Visuel suggéré : silhouette de voiture + signal/connexion (générique), pas de logo constructeur.

## Critères d'acceptation
- [ ] Le plugin a une icône valide conforme aux exigences Market.
- [ ] Pas de logo de marque déposée ni de code couleur réservé Jeedom.

## Notes
- Réf. doc : `.memory/external/doc/jeedom/INDEX.md` → `Icone_de_plugin`.
