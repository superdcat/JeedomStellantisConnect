# 81 — Recette fonctionnelle manuelle

**Domaine :** Livraison · **Dépend de :** (toutes) · **Statut :** vivant (à compléter au fil des UC)

## Objectif / valeur
Comme il n'y a **ni tests unitaires ni Jeedom local garanti**, fournir une **checklist de recette
manuelle** sur un Jeedom réel : la « preuve » qu'une UC marche vraiment (lint OK ≠ feature OK).

## Périmètre
- **Inclus** : scénarios de recette par UC livrée (étapes observables + résultat attendu).
- **Exclu** : automatisation (hors de portée).

## Détails techniques — checklist (extrait, à compléter)
- **Auth (MVP/01-04)** : config marque+credentials → générer l'URL → login marque → coller le `code` →
  « Tester la connexion » = OK + nb véhicules.
- **Token OAuth2 (MVP/03, ajouté 2026-07-06 — les 5 AC de `03-token.md`)** :
  1. Générer l'URL, se connecter, coller l'**URL de redirection complète** → bandeau « Connecté au
     compte » ; vérifier en base/cache que les tokens sont **chiffrés** (pas de JSON en clair).
  2. Deux actions consécutives dans la foulée → **aucun** appel réseau token visible dans les logs
     debug entre les deux (`getToken` rend le cache).
  3. Attendre l'expiration (~15 min) puis déclencher un appel → log « Token rafraîchi (expire dans
     N s) », l'appel métier aboutit (rejeu unique).
  4. Simuler un refresh_token mort (corrompre la valeur en cache) → message « ré-authentification
     requise », **pas de boucle** de refresh dans les logs, bandeau repasse « Non connecté ».
  5. `grep -i` des logs sur un extrait d'access_token/refresh_token/client_secret → **0 occurrence** ;
     coller le code seul (sans URL) → warning « state non vérifié » loggué, échange OK quand même.
  6. Sauvegarder la config **sans** changer client_id/brand → le token survit (pas de purge) ;
     changer la marque → bandeau « Non connecté » (purge effective).
- **Découverte/équipements (05-06)** : « Synchroniser » crée 1 eqLogic/VIN ; 2e sync = 0 doublon, nom
  perso conservé.
- **Télémétrie (07-08)** : après un trajet/charge, les infos (SOC, autonomie, km, position) évoluent au
  cron ; un véhicule injoignable n'interrompt pas les autres.
- **Carburant & hybrides (post-MVP 23, ajouté 2026-07-11 — les 2 AC de `23-carburant-hybride.md`)** :
  1. **Hybride** : l'équipement expose SIMULTANÉMENT « Batterie » + « Autonomie électrique » (élec) ET
     « Carburant » + « Autonomie carburant » (thermique), sans collision (valeurs distinctes) ; « Autonomie
     totale » apparaît quand le `/status` fournit les deux autonomies (≈ somme des deux).
  2. **Thermique pur** : aucune commande EV (« Batterie »/« État de charge »/« Câble branché »/« Autonomie
     électrique » absentes) ; seules « Carburant » + « Autonomie carburant » (+ km, position, portes). Sur un
     thermique déjà découvert avant la mise à jour, l'ancienne « Autonomie » (carburant) est **masquée**
     (migration `stellantis_update`), pas de doublon avec « Autonomie carburant ».
- **Robustesse (09-10)** : couper la config → message clair, pas de crash ; provoquer un 401 → refresh
  transparent.
- **Socle démon MQTT (post-MVP 11, ajouté 2026-07-08 — les 5 AC de `11-socle-demon-mqtt.md`)** :
  1. Plugin configuré + authentifié → la page plugin affiche le bloc démon avec l'état **« OK »**
     après « Démarrer le démon » ; logs `stellantis_daemon` : « Démarrage du démon MQTT » puis
     « connecté au broker MQTT ». Non authentifié → démon **non lançable** + message explicite.
  2. `grep -iE "IMA_OAUTH|access_token|password|Bearer"` sur les logs du démon **et** du plugin →
     **0 occurrence** d'un token en clair (seulement `***` / longueurs).
  3. Couper le réseau puis le rétablir → le démon se **reconnecte** (log « connecté au broker »
     réapparaît) sans intervention.
  4. Simuler l'expiration du token (attendre le refresh proactif du cron, ou forcer un 400) → log
     « token rafraîchi », **pas de boucle** refresh→400→refresh dans les logs.
  5. « Arrêter le démon » puis désinstaller/mettre à jour le plugin → **aucun** process `demond.py`
     orphelin (`ps aux | grep demond`), port socket libéré.
  > CID inconnu au socle → log « abonnement différé (customer id inconnu) » attendu, sans erreur
  > (l'abonnement effectif et l'ack de commande relèvent de UC12+).
- **Résilience connexion démon (post-MVP 19, ajouté 2026-07-11 — les 6 AC de `19-resilience-connexion-demon.md`)** :
  > ⚠️ Le scénario `rc=7` **organique** d'avant UC12 n'est plus reproductible (depuis UC12,
  > `pushDaemonConnect` exige `hasRemoteToken()`) → les cas « auth » se testent par **déclenchement
  > artificiel** (broker/token délibérément invalides).
  1. **Backoff transitoire** : pointer `broker_host` (config plugin) vers un hôte injoignable (ex. IP non
     routée) → « Démarrer le démon ». Logs `stellantis_daemon` : « nouvelle tentative dans Xs » avec X
     **croissant** (≈5 → 10 → 20 …) et **plafonné à 300 s**, **jamais < 5 s**. Aucune rafale de
     reconnexions immédiates. Le POST callback (log plugin) n'est **pas** émis à chaque tentative
     (1 seul événement `disconnected`/`retrying` pour la série).
  2. **Blocage après N échecs d'auth** : avec un pilotage OTP activé, invalider volontairement le remote
     token/CID (ou pointer vers un broker qui refuse l'auth) → après **5 échecs d'auth consécutifs**, log
     « N échecs d'authentification consécutifs → arrêt des tentatives » ; le process `demond.py` reste
     **vivant** (`ps aux | grep demond`) mais **cesse** de retenter ; un **message** utilisateur
     « démon … n'a pas pu s'authentifier … » apparaît (centre de messages), non spammé (1 seul).
  3. **Réarmement** : depuis l'état bloqué, deux déclencheurs remettent compteur + backoff à zéro et
     relancent une tentative (retour en connexion si les credentials sont redevenus valides) —
     (a) **« Démarrer le démon »** (relance → action `connect`) → log « Connexion MQTT à … (tentative) » ;
     (b) **rotation du remote token** (~15 min, action `set_token`) → log « Rotation du token MQTT →
     reconnexion (réarmement de la FSM) ». Dans les deux cas, plus aucun « arrêt des tentatives ».
  4. **Reset sur succès** : après une connexion réussie (`rc=0`), une coupure réseau ultérieure repart
     d'un backoff **court** (compteur réinitialisé), pas du dernier délai plafonné.
  5. **Page Santé** : pour chacun des 3 états, la ligne « Connexion du démon (pilotage à distance) »
     reflète l'état (Connecté / Reconnexion en cours / **Authentification refusée** en rouge) ; ligne
     **absente** si le démon est arrêté ou l'OTP non activé (pas de faux négatif).
  6. **Aucun secret loggué** : `grep -iE "apikey|password|access_token|token|Bearer"` sur
     `log/stellantis_daemon` (y compris en niveau **debug**) → **0** occurrence en clair (seulement `***`).
- **Commandes (post-MVP 12-x)** : OTP réalisée une fois ; wakeup throttlé ; lock/charge → ack remonté ;
  refus véhicule signalé.
- **Anti-ban (post-MVP 72)** : vérifier qu'aucune rafale de polling n'est émise (flotte à cadence défaut
  décalée par véhicule) ; sur HTTP 429, un message utilisateur « suspension temporaire » apparaît puis
  disparaît à la reprise.
- **Protection batterie 12 V / auto-wakeup adaptatif (post-MVP 73)** : le réveil auto est **opt-in**,
  réservé au **compte principal (slot 1)**. Prérequis : OTP activé + démon MQTT lancé.
  1. **Off par défaut** (AC1) : sur un véhicule fraîchement synchronisé, la case « Réveil automatique
     adaptatif » est **décochée** ; l'avertissement risque batterie 12 V est **visible** (pas qu'un
     tooltip) ; aucun réveil n'est émis au cron (log `stellantis` : aucun « Réveil automatique … déclenché »).
  2. **Cadence en charge** (AC2) : activer la case, véhicule **en charge** (`charging_status = InProgress`)
     → un « Réveil automatique (charge) déclenché pour l'équipement #… » apparaît, espacé d'**~5 min**
     (jamais moins), et un refresh REST suit l'ack (info batterie/charge rafraîchies).
  3. **Cadence en veille** (AC2) : véhicule **à l'arrêt** (ni charge ni mouvement) → « Réveil automatique
     (veille) déclenché … » espacé d'**~60 min** (défaut).
  4. **Roulage** : véhicule `moving = 1` → **aucun** réveil auto (la voiture est déjà éveillée, REST frais).
  5. **Clamp serveur** (AC2) : saisir `1` dans « Cadence de réveil en charge (min) », Sauvegarder → les
     réveils restent espacés d'**≥ 5 min** (la saisie sous le plancher est remontée à 5 côté serveur).
  6. **Indépendance du polling REST** (AC3) : désactiver la case → le rafraîchissement REST périodique
     (batterie, position…) **continue** à la cadence habituelle ; seuls les réveils MQTT cessent.
  7. **Respect UC72 / anti-ban** : avec plusieurs véhicules en charge simultanée, le quota global compte
     (5/20 min) n'est **jamais** dépassé (un excès est refusé, loggué en `debug`, jamais une rafale).

## Critères d'acceptation
- [ ] Chaque UC livrée a au moins un scénario de recette observable, vérifié sur Jeedom réel.

## Notes
- Ne **jamais** prétendre qu'un comportement runtime est validé sans l'avoir constaté ici.
