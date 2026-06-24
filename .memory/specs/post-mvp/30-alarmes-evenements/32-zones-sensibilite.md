# UC — Zones & sensibilité de détection de mouvement

**Domaine :** Alarmes & événements · **Dépend de :** MVP/07 · **Statut endpoints :** à confirmer

## Objectif / valeur
Régler depuis Jeedom la **sensibilité** de la détection de mouvement et, si supporté, les
**zones** de détection — pour limiter les fausses alarmes.

## Ce que permet l'API
- Lecture/écriture de la sensibilité de détection de mouvement.
- Configuration de zones/régions de détection (selon modèle).

## Esquisse Jeedom
- Commande action de type slider/select pour la sensibilité (`min/max`), + commande info d'état.
- Zones : probablement trop complexe pour un widget standard → exposer un préréglage ou
  documenter la config via l'app. À arbitrer.

## Critères d'acceptation
- [ ] Modifier la sensibilité depuis Jeedom est effectif (vérifiable dans l'app IMOU).

## À confirmer
- Endpoint et plage de valeurs de sensibilité ; faisabilité réaliste de l'édition de zones via l'API.
