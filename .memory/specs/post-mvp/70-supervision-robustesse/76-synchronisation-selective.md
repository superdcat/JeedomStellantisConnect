# 76 — Synchronisation sélective par véhicule

**Domaine :** Supervision / robustesse · **Dépend de :** MVP/06, MVP/08 · **Statut :** à spécifier

## Objectif / valeur
Laisser l'utilisateur **choisir** quels véhicules sont rafraîchis (et à quelle cadence), et gérer
proprement un véhicule retiré du compte — pour économiser les appels (quota/anti-ban) et éviter le bruit.

## Périmètre
- **Inclus** : option par eqLogic « inclure dans le rafraîchissement auto », cadence par véhicule,
  gestion d'un véhicule disparu (désactiver plutôt que supprimer).
- **Exclu** : la régulation globale (UC72/73).

## Détails techniques
- Config eqLogic : `autorefresh` (cadence) + `syncEnabled` (bool) ; le cron (MVP/08) ne traite que les
  véhicules `isEnable && syncEnabled`.
- Véhicule absent à la re-sync : **désactiver** l'eqLogic (conserver l'historique), ne pas supprimer ;
  le ré-activer s'il revient.

## Critères d'acceptation
- [ ] L'utilisateur peut exclure un véhicule du rafraîchissement auto (sans le supprimer).
- [ ] Un véhicule disparu est désactivé, pas supprimé ; il revient s'il réapparaît.

## À confirmer
- Comportement souhaité pour un véhicule « vendu » (purge manuelle laissée à l'utilisateur).
