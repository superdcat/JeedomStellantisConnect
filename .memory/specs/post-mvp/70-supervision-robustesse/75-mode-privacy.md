# 75 — Mode privacy du véhicule (« Plane Mode »)

**Domaine :** Supervision / robustesse · **Dépend de :** MVP/09 · **Statut :** à spécifier

## Objectif / valeur
Détecter et gérer le **mode privacy** côté véhicule (l'utilisateur a coupé Data / Géoloc / « Plane Mode »
dans la voiture) : l'API devient muette **indépendamment du plugin**. Il faut l'expliquer, pas le traiter
comme une panne ni marteler en retry.

## Périmètre
- **Inclus** : détection (réponses vides/incomplètes, absence de position/données), info `privacy_mode`,
  message d'aide « activez le partage de données dans le véhicule », arrêt des retry inutiles.
- **Exclu** : —

## Détails techniques
- Heuristique : `/status` répond mais sans position/données fraîches de façon persistante → probable mode
  privacy (vs erreur réseau ponctuelle). Distinguer des autres états (MVP/09).
- Info `privacy_mode` (binary) + message ; ne pas compter ça comme erreur dure ; **réduire** la fréquence
  d'appel tant que le mode privacy semble actif (économie quota/anti-ban).

## Critères d'acceptation
- [ ] Un véhicule en mode privacy est signalé clairement (info + message d'aide), pas en erreur.
- [ ] Le plugin n'enchaîne pas les retry inutiles dans cet état.

## À confirmer
- Signature exacte d'une réponse « privacy » côté API consommateur (doc officielle privacy + observation).
