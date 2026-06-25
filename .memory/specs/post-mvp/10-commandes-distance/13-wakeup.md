# 13 — Wakeup / rafraîchissement à la demande

**Domaine :** Commandes à distance · **Dépend de :** UC11 (démon), UC12 (remote token) · **Statut :** à spécifier

## Objectif / valeur
Permettre de **forcer** le véhicule à remonter un état frais (batterie, charge, position…), ce que le
polling REST seul (MVP/08) ne peut pas faire. C'est la première « commande » MQTT et la plus utile.

## Périmètre
- **Inclus** : commande action `wakeup` (publish MQTT `{"action":"state"}`), MAJ des infos après l'ack,
  **garde-fous de fréquence stricts**.
- **Exclu** : automatisation de la fréquence (→ UC73 protection batterie / cadence adaptative).

## Détails techniques
- Commande `stellantisCmd` action `wakeup` → message socket → démon publie `{"action":"state"}` sur
  `psa/RemoteServices/from/cid/{cid}/...`. La réponse / les événements rafraîchissent ensuite `/status`.
- ⚠️ **Garde-fous critiques** (cf. analyse § 1.4) :
  - **limite serveur ~6 wakeups / 20 min** ; un wakeup ~toutes les 2 min → **ban API** persistant
    (bloque aussi le refresh du remote token) ;
  - **risque batterie 12 V réel** : wakeups trop fréquents → keyless HS.
  - ⇒ le wakeup est une action **délibérée** (bouton), **throttlée** (cooldown en cache, ex. min 5 min
    entre deux), **jamais** déclenchée à chaque cron.
- Idéalement, exposer le wakeup comme action manuelle + (UC73) une option d'auto-wakeup **opt-in** avec
  cadence sûre (5 min en charge / 60 min en veille).

## Critères d'acceptation
- [ ] Un appui sur « Réveiller » force une remontée d'état (infos mises à jour après quelques instants).
- [ ] Un cooldown empêche deux wakeups rapprochés (anti-ban / anti-batterie).
- [ ] Aucun wakeup n'est déclenché automatiquement par le cron MVP.

## À confirmer
- Payload/topic exacts du wakeup et délai typique de remontée d'état (cf. `RemoteClient`).
