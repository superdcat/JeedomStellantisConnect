# 19 — Résilience de la connexion du démon MQTT (backoff + arrêt sur échec d'auth)

**Domaine :** Commandes à distance · **Dépend de :** UC11 (socle démon) · **Complète :** UC12 (OTP/remote token), UC72 (anti-ban) · **Statut :** à spécifier

## Objectif / valeur
Empêcher le démon MQTT de **se reconnecter en boucle serrée** quand le broker Stellantis **refuse la
connexion** (échec d'authentification), et de manière générale borner toute reconnexion par un
**backoff**. Objectifs : respecter les **guardrails anti-ban** (marteler le vrai broker = risque de
blocage du compte, cf. UC72), économiser CPU/réseau/batterie 12 V, et remonter à l'utilisateur un état
clair (« démon non autorisé, reconnectez-vous ») plutôt qu'un bruit de logs infini.

> **Contexte (constaté à l'implémentation UC11, 2026-07-08)** : une fois le socle démon en place mais
> **avant UC12** (remote token OTP + `customer_id`), le broker répond `rc=7` à chaque tentative
> (`IMA_OAUTH_ACCESS_TOKEN` + access_token OAuth2 seuls ne suffisent pas à l'auth MQTT complète). Le
> démon reprenait immédiatement, produisant une **tempête de reconnexions** (une toutes les quelques
> secondes) qui POSTe à chaque coup vers Jeedom. Cette UC borne ce comportement — elle est **utile même
> avant UC12** (dégrade proprement) et **après** (réseau instable, broker en maintenance, token périmé).

## Périmètre
- **Inclus** :
  - **Backoff exponentiel plafonné + jitter** sur les reconnexions (au lieu d'une reprise immédiate).
  - **Compteur d'échecs d'authentification consécutifs** → au-delà de **N** (défaut 5), le démon **cesse
    de retenter** et passe en **état « bloqué / auth requise »** (il reste vivant, écoute le socket, mais
    ne se reconnecte plus tout seul).
  - **Distinction** échec **transitoire** (réseau/DNS/broker indisponible → on retente avec backoff) vs
    échec **d'authentification / non autorisé** (→ incrémente le compteur, plafond plus bas).
  - **Reset** du compteur et du délai de backoff sur **connexion réussie** (`rc=0`).
  - **Réarmement** : une nouvelle action `connect`/`set_token` reçue sur le socket (ex. cron qui pousse un
    token frais via `syncDaemonToken`, ou redémarrage du démon) **remet le compteur à zéro** et relance
    les tentatives — l'utilisateur/le core garde la main.
  - **Remontée d'état à Jeedom** : événement démon→Jeedom distinguant `disconnected` (transitoire, en
    retry) et `auth_failed`/`blocked` (arrêt des tentatives) ; côté PHP, un `message::add('stellantis', …)`
    non spammé (dédupliqué) prévient l'utilisateur, et l'indicateur démon reflète l'état bloqué.
  - **Anti-spam du callback** : ne pas POSTer un événement à Jeedom à chaque tentative — au minimum
    throttler (1er échec + changement d'état), pas les N tentatives intermédiaires.
- **Exclu** :
  - Obtention/rafraîchissement du **remote token OTP** et du `customer_id` (→ UC12) — c'est ce qui fera
    passer le `rc=7` en `rc=0` ; cette UC ne « répare » pas l'auth, elle **borne** l'échec.
  - La connexion nominale et l'abonnement (→ UC11).
  - Le refresh du token OAuth2 sur `return_code='400'` en cours de session (→ UC11/UC18).

## Détails techniques (à confirmer au codage)
- **Codes paho** : dans `on_connect`, `rc` = code CONNACK (`0`=ok ; `4`=bad user/password ; `5`=not
  authorized). Dans `on_disconnect`, `rc≠0` = déconnexion inattendue (`7` observé = connexion
  perdue/refusée par le broker). ⚠️ **Cartographier rc → {transitoire | auth}** au moment de coder : `rc`
  7 sans CONNACK préalable = à traiter comme **non autorisé** dans notre contexte (broker ferme la socket
  faute d'auth complète). Documenter le mapping retenu dans le `-tech`.
- **Désactiver l'auto-reconnexion native** de `paho` (ne pas s'appuyer sur `loop_forever()` +
  `reconnect_delay_set` seuls, qui redonnent la main sans notre logique de comptage) : piloter la boucle
  explicitement pour appliquer backoff **et** le plafond d'échecs d'auth. `reconnect_delay_set(min_delay,
  max_delay)` peut servir de garde-fou bas niveau, mais le **compteur d'auth** est géré côté démon.
- **Valeurs par défaut proposées** (à ajuster) : backoff base **5 s**, facteur **2**, plafond **300 s**,
  **jitter** ±20 % ; **N = 5** échecs d'auth consécutifs avant arrêt ; **plancher dur ≥ 5 s** entre deux
  tentatives (jamais de reconnexion immédiate — guardrail). Rendre ces valeurs configurables si simple.
- **État exposé** : réutiliser le canal `stellantis_daemon` (cf. `send_change_immediate`,
  [[jeedom-daemon-pitfalls]]) avec un `type` explicite (`disconnected` vs `auth_failed`) + un flag
  `retrying`/`blocked`. `stellantis::handleDaemonMessage()` met à jour un `message::add` dédupliqué et
  l'état démon (sans jamais logguer le token, cf. `_redact`).
- **Cohérence lifecycle** : `deamon_start`/`deamon_stop` (UC11) inchangés ; l'état « bloqué » **ne tue pas**
  le process (le socket doit rester à l'écoute pour le réarmement). Ne pas faire mentir `deamon_info()`
  (le process est `state=ok`) — l'état d'auth est une info **métier** séparée, portée par le message/canal.

## Critères d'acceptation
- [ ] Sur refus répété du broker (auth), l'intervalle entre tentatives **croît** (backoff) et ne descend
      jamais sous le plancher ; **aucune** reconnexion immédiate en boucle.
- [ ] Après **N** échecs d'auth consécutifs, le démon **arrête** de retenter et signale un état
      « auth requise / bloqué » à Jeedom (message utilisateur, indicateur démon), **sans** se terminer.
- [ ] Une nouvelle action `connect`/`set_token` (token frais, redémarrage) **réarme** les tentatives et
      remet compteur + backoff à zéro.
- [ ] Une connexion réussie (`rc=0`) réinitialise compteur et backoff.
- [ ] Le callback démon→Jeedom **n'est pas** appelé à chaque tentative (throttlé / sur changement d'état) ;
      aucun token/secret en clair dans les logs.
- [ ] Comportement vérifié **avant UC12** (rc=7 borné proprement) et simulable pour un échec transitoire
      (broker injoignable → retry avec backoff, pas de passage prématuré en « bloqué »).

## À confirmer
- Mapping exact `rc` paho → catégorie (auth vs transitoire) dans notre contexte broker (rejouer contre le
  vrai broker en UC12).
- Comportement souhaité après « bloqué » : rester en attente d'un réarmement (retenu ici) **ou** planifier
  une re-tentative très espacée (ex. 1×/h) ? Trancher au codage selon le risque anti-ban (UC72).
- Faut-il exposer le compteur/état dans une commande info Jeedom (visibilité scénarios) ou se limiter au
  message + indicateur démon ?
