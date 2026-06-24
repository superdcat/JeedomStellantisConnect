# UC — Relecture des enregistrements

**Domaine :** Enregistrement & stockage · **Dépend de :** MVP/06 · **Statut endpoints :** à confirmer

## Objectif / valeur
Lister et obtenir une URL de **relecture** des enregistrements (SD ou cloud) sur une plage de
temps, pour visualiser un événement passé.

## Ce que permet l'API
- Recherche des enregistrements par période.
- Obtention d'une URL de lecture (flux/segment), souvent temporaire.

## Esquisse Jeedom
- UC plutôt « consultation » : exposer une commande/action générant une URL de relecture pour la
  dernière alarme, ou une vue listant les derniers enregistrements.
- Cycle de vie des URLs temporaires à gérer (comme le live, cf. `20-video-images/22-live-stream.md`).

## Critères d'acceptation
- [ ] On peut obtenir une URL lisible d'un enregistrement récent.

## À confirmer
- Endpoints de recherche + lecture ; formats de flux ; durée de validité des URLs.
- Pertinence réelle dans Jeedom (peut rester un « nice to have » de fin de roadmap).
