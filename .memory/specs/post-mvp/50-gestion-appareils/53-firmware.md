# UC — Firmware (version & mise à jour)

**Domaine :** Gestion des appareils · **Dépend de :** MVP/06 · **Statut endpoints :** à confirmer

## Objectif / valeur
Afficher la **version de firmware** et signaler/déclencher une **mise à jour** disponible.

## Ce que permet l'API
- Lecture de la version courante + disponibilité d'une mise à jour.
- Déclenchement d'une mise à jour firmware.

## Esquisse Jeedom
- Commandes info `firmware_version` (string), `firmware_update_available` (binary).
- Commande action `firmware_upgrade` (avec avertissement : opération sensible, caméra indisponible).
- Vérification basse fréquence (`cronDaily`).

## Critères d'acceptation
- [ ] La version est affichée ; une MAJ dispo est signalée.

## À confirmer
- Endpoints (lecture version, check update, lancement) ; gestion de l'indisponibilité pendant la MAJ.
