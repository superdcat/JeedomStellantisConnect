# 03 — Gestion du token d'accès

**Phase :** MVP · **Dépend de :** 02 · **Fichiers :** `core/class/imou.class.php`

## Objectif
Obtenir, mettre en cache et renouveler automatiquement l'`accessToken` requis par tous les
appels métier, de façon transparente.

## Périmètre
- **Inclus** : appel `accessToken`, cache avec TTL, refresh proactif et réactif.
- **Exclu** : usage métier du token (tâches 05+).

## Détails techniques
- Méthode `imouApi::getToken(bool $force = false): string`.
- Appel `accessToken` (signé, sans token) → réponse `{ accessToken, expireTime }`.
  `expireTime` est une durée en secondes (à confirmer) ou un timestamp ; normaliser en
  « instant d'expiration ».
- Cache via la classe Jeedom `cache` :
  `cache::set('imou::token', json(['token'=>…,'exp'=>…]))` / lecture `cache::byKey('imou::token')`.
- Refresh **proactif** : renouveler si `now >= exp - marge` (marge = 300 s).
- Refresh **réactif** : si un appel métier échoue pour cause de token invalide/expiré
  (code IMOU correspondant), appeler `getToken(true)` puis **rejouer une fois** l'appel.
- Sérialiser l'obtention pour éviter les appels concurrents inutiles (best effort).

## Critères d'acceptation
- [ ] Le 1er appel récupère un token ; les suivants réutilisent le cache (pas de nouvel appel `accessToken`).
- [ ] À l'approche de l'expiration, le token est renouvelé sans erreur visible.
- [ ] Un token invalidé côté serveur déclenche un refresh + rejeu transparent (1 seule fois).
- [ ] Le token n'est jamais écrit en clair dans les logs.

## Notes / risques
- Confirmer la sémantique de `expireTime` (durée vs timestamp) — défaut prudent si ambigu.
- Le cache Jeedom peut être purgé : toujours pouvoir re-générer un token à la volée.
