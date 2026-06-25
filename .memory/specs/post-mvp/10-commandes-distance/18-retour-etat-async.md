# 18 — Retour d'état asynchrone des commandes

**Domaine :** Commandes à distance · **Dépend de :** UC11 (démon) · **Transverse** à UC13-17 · **Statut :** à spécifier

## Objectif / valeur
Les commandes MQTT sont **asynchrones** : la voiture confirme (ou échoue) **après coup**, sur un topic de
réponse. Cette UC fiabilise le **retour utilisateur** : succès/échec, états intermédiaires, et MAJ des
infos une fois l'action réellement appliquée.

## Périmètre
- **Inclus** : le démon écoute les topics `to/cid/...` + `events/MPHRTServices/{vin}`, interprète les
  `return_code`/états (Accepted → Waking-Up → Send → Success/Failure), remonte à Jeedom (`jeedom_com`),
  MAJ des commandes info + une info `last_command_result` par véhicule.
- **Exclu** : la logique propre à chaque commande (UC13-17).

## Détails techniques
- États intermédiaires notifiés avant le résultat final ; `return_code='400'` = token expiré → refresh +
  re-publish (UC11). Échec → message d'erreur exploitable (et ne pas laisser l'utilisateur croire au succès).
- Remontée vers Jeedom via callback `jeedom_com` → `stellantis::handleDaemonMessage()` met à jour
  `last_command_result` (string) et rafraîchit les infos impactées (ex. après `lock` → `doors_locked`).
- Corrélation commande↔réponse (id de requête) pour ne pas confondre deux commandes simultanées.

## Critères d'acceptation
- [ ] Après une commande, l'utilisateur voit un retour réel (succès/échec) une fois la voiture ayant répondu.
- [ ] Les infos impactées sont rafraîchies après confirmation (pas seulement au prochain cron).
- [ ] Un échec (refus véhicule, hors ligne, token) est clairement signalé, jamais silencieux.

## À confirmer
- Format exact des messages de réponse / corrélation d'id (cf. DeepWiki `RemoteClient` §5.1).
