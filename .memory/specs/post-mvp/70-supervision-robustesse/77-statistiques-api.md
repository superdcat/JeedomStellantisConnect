# 77 — Statistiques d'appels API

**Domaine :** Supervision / robustesse · **Dépend de :** MVP/02 (client) · **Alimente :** UC71 (Santé), UC72 (anti-ban) · **Statut :** à spécifier

## Objectif / valeur
Compter de façon fiable **tous** les appels (REST + commandes), par jour et par endpoint, pour surveiller
la consommation, **détecter une dérive** (martèlement → risque ban) et alimenter la régulation (UC72).

## Périmètre
- **Inclus** : compteur instrumenté dans `stellantisApi` (point unique), par jour + par endpoint,
  restitution (page plugin + Santé), alerte sur dérive.
- **Exclu** : facturation ; les quotas ici sont surtout un **proxy anti-ban** (l'API consommateur n'a pas
  de quota mensuel facturé connu, contrairement à un modèle B2B Mobilisights).

## Détails techniques
- Compteur incrémenté **après réception d'une réponse serveur** (un échec transport sans réponse ne compte
  pas). Persisté via `cache` : `stellantis::stats::day::AAAA-MM-JJ` → `{total, byEndpoint}` (TTL ~8 j).
- Instrumentation **non bloquante** (`try/catch Throwable`, jamais d'exception remontée à l'appel métier).
- Alerte de **dérive** : si le volume/min dépasse un seuil → log `warning` (signe d'une boucle anormale).

## Critères d'acceptation
- [ ] Tous les appels passent par `stellantisApi` et sont comptés (jour + par endpoint).
- [ ] La consommation est consultable (page plugin / Santé).
- [ ] Le comptage **ne casse jamais** un appel métier (instrumentation non bloquante).

## À confirmer
- Existence éventuelle d'un quota chiffré côté API consommateur (probablement non documenté ; surveiller
  surtout la **fréquence** pour l'anti-ban).
