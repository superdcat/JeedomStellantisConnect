# UC — Enregistrement cloud (état & planification)

**Domaine :** Enregistrement & stockage · **Dépend de :** MVP/07 · **Statut endpoints :** à confirmer

## Objectif / valeur
Consulter l'état de l'**abonnement/enregistrement cloud** et, si supporté, activer/désactiver
ou planifier l'enregistrement.

## Ce que permet l'API
- Statut du service cloud (actif/expiration).
- Configuration des plans d'enregistrement (selon offre/modèle).

## Esquisse Jeedom
- Commande info `cloud_active` (binary) + `cloud_expire` (date) si exposé.
- Commande action d'activation/planification si l'API le permet.

## Critères d'acceptation
- [ ] L'état du service cloud est affiché correctement.

## À confirmer
- Disponibilité réelle de ces endpoints selon l'abonnement ; ce qui est pilotable vs lecture seule.
