# UC — Alarmes temps réel (push / callback)

**Domaine :** Alarmes & événements · **Dépend de :** MVP/06-07 · **Statut endpoints :** à confirmer
**Connexe :** `messages-alarme.md` (récupération par polling), `34-detection-humaine-ia.md` (types d'events).

## Objectif / valeur
Remonter les **événements en temps réel** (mouvement, IA personne/véhicule, intrusion de zone,
anomalies stockage…) sans attendre le polling — pour déclencher des scénarios Jeedom réactifs.

## Ce que permet l'API
- L'IMOU Open API **n'a pas de flux push direct** côté client : elle propose un **callback** —
  `setMessageCallback`/`getMessageCallback` (doc IMOU `http/push/…`, `push/push.html`, `push/event.html`).
  IMOU pousse les événements vers **une URL HTTPS publique** que l'on enregistre.
- Le `getProductModel` liste les **`events`** disponibles par modèle (ex. `e_videoMotion`,
  `e_smartMixDetect`, `e_aiPerArea`, `e_storageAbnormal`…) — cf. `.memory/analyse/imou-iot-things-model.md`.

## Esquisse Jeedom
- **Endpoint de réception** public : une page `core/ajax/…` (ou route dédiée) accessible depuis
  Internet, qui valide la signature du callback IMOU puis met à jour des commandes **info**
  (ex. `derniere_alarme`, `type_evenement`) et émet éventuellement un événement Jeedom.
- Enregistrement du callback (`setMessageCallback`) au moment de la config/sync.
- Mapping `event → commande info` par caméra (deviceId/channelId).
- **Repli polling** : si le push n'est pas joignable (Jeedom non exposé), conserver `31-messages-alarme`
  (getAlarmMessage) comme alternative.

## Critères d'acceptation
- [ ] Un mouvement/une détection génère une mise à jour quasi immédiate dans Jeedom.
- [ ] La signature du callback est vérifiée (rejet des requêtes non authentifiées).
- [ ] Dégradation propre si l'URL publique n'est pas configurable (repli polling documenté).

## À confirmer / risques
- Contrat exact `setMessageCallback` (format, sécurité/signature, abonnement par appareil ou global).
- **Exposition publique** de Jeedom requise (reverse proxy/HTTPS) — souvent indisponible : prévoir le repli.
- Anti-abus : valider strictement le payload entrant (anti-injection, anti-spoofing).
