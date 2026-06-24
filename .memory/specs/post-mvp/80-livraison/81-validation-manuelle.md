# 18 — Checklist de validation manuelle (golden master)

**Phase :** Post-MVP · **Dépend de :** 10 (puis enrichie au fil des tâches) · **Fichiers :** `docs/` / cette spec

## Objectif
Disposer d'un scénario de recette reproductible à dérouler sur une vraie caméra avant chaque
release, faute de tests automatisés (le plugin tourne dans un Jeedom réel contre un cloud réel).

## Périmètre
- **Inclus** : scénario de bout en bout + cas d'erreur.
- **Exclu** : tests unitaires (non pertinents pour les appels cloud).

## Scénario de recette
1. Config plugin : saisir appId/appSecret/datacenter → « Tester la connexion » = succès.
2. Identifiants erronés → message d'erreur clair.
3. « Synchroniser » → les caméras apparaissent en équipements (pas de doublon en re-sync).
4. Renommer une caméra + re-sync → le nom personnalisé est préservé.
5. « Éteindre » la caméra → image coupée dans l'app IMOU ; `camera_state` = 0.
6. « Allumer » → image rétablie ; `camera_state` = 1.
7. « Activer surveillance » / « Désactiver » → reflété dans l'app ; `surveillance_state` à jour.
8. Changer un état depuis l'app IMOU → Jeedom se met à jour au prochain cron (≤ 5 min).
9. Débrancher une caméra → passe offline (tâche 11).
10. Couper le réseau brièvement pendant un cron → pas d'erreur fatale, reprise auto (tâche 16).
11. (UC22 flux live) Rendre visibles `live_get`/`live_release`/`live_url` → « Obtenir le flux live » →
    `live_url` contient une URL HLS lisible (lecteur HLS/VLC) ; « Libérer le flux live » → `live_url` vidée.
12. (UC22) « Obtenir le flux live » deux fois de suite sans libérer → réutilise le binding (pas d'erreur,
    URL toujours valide). Cas limite : si l'appareil ne fournit pas de flux, `live_url` reste vide (pas de fatal).
13. (UC22) « Libérer le flux live » sans avoir obtenu de flux (cache vide) → aucune erreur (no-op).

## Critères d'acceptation
- [ ] Tous les points du scénario passent sur au moins un modèle de caméra réel.
- [ ] Les secrets ne fuient nulle part (logs, DOM, réseau).

## Notes / risques
- Tenir cette checklist à jour à chaque nouvelle capacité (PTZ, snapshot, live…).
