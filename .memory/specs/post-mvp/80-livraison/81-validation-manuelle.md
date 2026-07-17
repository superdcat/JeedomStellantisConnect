# 81 — Recette fonctionnelle manuelle

**Domaine :** Livraison · **Dépend de :** (toutes) · **Statut :** vivant (à compléter au fil des UC)

## Objectif / valeur
Comme il n'y a **ni tests unitaires ni Jeedom local garanti**, fournir une **checklist de recette
manuelle** sur un Jeedom réel : la « preuve » qu'une UC marche vraiment (lint OK ≠ feature OK).

## Périmètre
- **Inclus** : scénarios de recette par UC livrée (étapes observables + résultat attendu).
- **Exclu** : automatisation (hors de portée).

## Conventions de ce document
> Ce fichier est **vivant** et repris par chaque `/feature` (contexte neuf à chaque cycle). Pour rester
> cohérent, respecter ces règles :
- **Ordre** = par **domaine** (arborescence du `README.md`), puis par **n° d'UC** croissant.
- **Gabarit d'un bloc** : `**Titre (MVP/NN | post-MVP NN, ajouté AAAA-MM-JJ — les K AC de \`NN-nom.md\`)**`,
  puis des **sous-scénarios numérotés et observables** (étapes concrètes + résultat attendu). La **date
  « ajouté » est obligatoire** pour tout nouveau bloc (les blocs historiques sans date ne sont pas
  rétro-corrigés).
- **UC de cadrage / documentaire (0 code)** : ne **jamais** fabriquer un « à observer sur Jeedom ».
  Pointer vers le(s) scénario(s) existant(s) qui couvre(nt) déjà l'AC (cas UC53).
- **Écart spec-vs-réel** : quand le comportement livré diverge du texte *cible* de la spec fonctionnelle,
  l'annoter `⚠️ Écart vs spec initiale : <prévu> non retenu — <raison>, cf. \`NN-tech.md\`` **et** le
  formuler en **assertion vérifiable positive** (ex. « vérifier qu'AUCUN champ % n'est proposé »).
- Un scénario s'ancre sur le **comportement réellement implémenté** (code + `CLAUDE.md`), pas sur le texte
  spéculatif d'une spec figée avant codage.

## Détails techniques — checklist (extrait, à compléter)

### Socle MVP (01→10)
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
- **Robustesse (09-10)** : couper la config → message clair, pas de crash ; provoquer un 401 → refresh
  transparent.

### 10-commandes-distance (démon MQTT)
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
- **OTP & remote token (post-MVP 12, ajouté 2026-07-17 — les 4 AC de `12-otp-remote-token.md`)** :
  > Prérequis : plugin authentifié (OAuth2) + démon lançable. Le remote token est le **mot de passe MQTT**,
  > distinct du token OAuth2 REST.
  1. **Activation en 2 étapes (AC1)** : page de config, section OTP → « Envoyer le SMS » → le flag
     `otp_sms_pending` passe à « en attente » (distinct du **compteur** à vie `otp_sms_count`, 0..20) et un
     SMS arrive. Puis saisir le **code SMS** + le **PIN à 4
     chiffres** (celui de l'app mobile de la marque) → « Activer » → état **« OTP actif »** ; le
     `customer_id` (CID) est résolu automatiquement (`GET /user`) — sinon saisie manuelle.
  2. **Remote token réutilisé par le démon (AC2)** : après activation, vérifier en cache
     (`stellantis::remote_token`) une valeur **chiffrée** (jamais de JSON en clair) ; le démon reçoit un
     `set_token` avec ce **remote token** (pas l'access_token OAuth2) → les commandes à distance
     deviennent possibles.
  3. **Alerte sans boucle à l'expiration (AC3)** : laisser expirer le remote token (~890 s sans
     renouvellement) → état **`otp_required`**, ligne page Santé + **message** centre de messages « refaire
     la procédure OTP », **aucune** régénération OTP automatique dans les logs (respect des quotas **6
     codes / 24 h** en cache, **20 SMS / compte à vie** en config — jamais remis à 0 auto).
  4. **Aucun secret loggué (AC4)** : `grep -iE "password|token|Bearer|[0-9]{4}"` sur les logs plugin +
     démon → **0** PIN/token en clair (seulement `***`).

- **Wakeup à la demande (post-MVP 13, ajouté 2026-07-17 — les 3 AC de `13-wakeup.md`)** :
  1. **Réveil manuel (AC1)** : appuyer sur la commande action **« Réveiller »** → publication MQTT sur
     `.../VehCharge/state` ; au **prochain `cron()`** (pas dans le callback), un flag `CMD_PENDING` déclenche
     un **refresh REST forcé** → les infos (batterie, charge, position…) se rafraîchissent après quelques
     instants.
  2. **Cooldown / quota (AC2)** : ré-appuyer aussitôt → **refusé** (cooldown per-véhicule **5 min**) ; sur
     une flotte, le **quota global compte 5 / 20 min** (marge sous le ban serveur ~6/20) borne le total, un
     excès est loggué `debug`, jamais une rafale.
  3. **Aucun wakeup automatique (AC3)** : laisser tourner le cron sans action → `grep -i "réveil" ` /
     `wakeup` dans `log/stellantis` = **aucun** wakeup spontané (le wakeup reste une action délibérée ;
     exception opt-in = auto-wakeup UC73, désactivé par défaut).

- **Charge démarrer/arrêter (post-MVP 14, ajouté 2026-07-17 — les 3 AC de `14-charge.md`)** :
  1. **Création conditionnelle (AC2)** : sur un **VE/PHEV** (`Electric`/`Hybrid`), les commandes action
     **`charge_start` / `charge_stop`** existent ; sur un **thermique pur**, elles sont **absentes** de la
     carte équipement.
  2. **Démarrer/Arrêter (AC1)** : « Démarrer » publie `type:immediate` sur `/VehCharge` ; « Arrêter »
     **rafraîchit d'abord le `/status` REST** (best-effort, pour ne pas reprogrammer avec une heure
     périmée) puis publie `type:delayed`. Après l'ack, `charging_status` reflète le nouvel état.
  3. **Refus non silencieux (AC3)** : provoquer un refus (batterie principale sous le seuil, véhicule hors
     ligne) → message clair via `last_command_result` (UC18), **jamais** d'erreur muette.
  > Garde-fou : debounce per-véhicule **10 s** posé AVANT tout appel réseau + quota global compte.

- **Préconditionnement (post-MVP 15, ajouté 2026-07-17 — les 2 AC de `15-preconditionnement.md`)** :
  1. **Activer/Désactiver (AC1)** : commandes action **`precond_on` / `precond_off`** créées
     **universellement** (y compris thermique — chauffage habitacle) ; après publication sur
     `/ThermalPrecond`, l'info **`precond_status`** (mapping `preconditionning.airConditioning.status`,
     **double n**) reflète l'état.
  2. **Refus signalé (AC2)** : un refus véhicule (`Failure` — batterie/non branché) est **loggué avec
     `failure_cause`** et remonté clairement (via `last_command_result`), pas d'erreur muette.
  > ⚠️ Les `programs` envoyés sont **toujours** le littéral figé par défaut (4 créneaux `on:0`) : le suivi
  > des programmes réels est hors scope UC15 (cf. `15-tech.md`). Debounce 10 s + quota global.

- **Portes verrouiller/déverrouiller (post-MVP 16, ajouté 2026-07-17 — les 3 AC de `16-portes.md`)** :
  1. **Lock/Unlock (AC1)** : commandes action **`lock` / `unlock`** créées **universellement** ; après
     l'ack, l'info **`doors_locked`** (MVP07) reflète l'état.
  2. **Confirmation native au déverrouillage (AC2)** : appuyer sur **`unlock`** → le core lève une
     **confirmation native** (dialog « Cette action nécessite une confirmation ») **avant** exécution
     (`actionConfirm`). ⚠️ C'est un garde-fou **UI**, **pas** une frontière d'autorisation : un
     scénario/apikey le **contourne** (cf. `jeedom-widgets-commandes.md` § 4).
  3. **Indisponibilité propre (AC3)** : l'API `/Doors` n'expose **aucun `failure_cause`** → une
     indisponibilité (thermique/équipement) laisse `doors_locked` **inchangé**, sans crash (retour d'état
     fin renvoyé à UC18). Debounce 10 s + quota global.

- **Klaxon & feux (post-MVP 17, ajouté 2026-07-17 — les 2 AC de `17-klaxon-feux.md`)** :
  1. **Effet physique (AC1)** : commandes action **`horn` / `lights`** créées **universellement** →
     appuyer déclenche l'action sur le véhicule (klaxon retentit, feux s'allument), publication sur
     `/Horn` / `/Lights`.
  2. **Paramètres par défaut (AC2)** : nb de coups / durée = **constantes** (`HORN_COUNT=2`,
     `LIGHTS_DURATION=10 s`).
  > ⚠️ Différence assumée vs UC13-16 : commandes **« sans état »** (aucune télémétrie à relire) → **pas** de
  > corrélation `CMD_CORR` ni d'entrée `last_command_result` stateful (le refresh REST au cron serait
  > inutile). Debounce 10 s (clés séparées klaxon/feux) + quota global.

- **Retour d'état async (post-MVP 18, ajouté 2026-07-17 — les 3 AC de `18-retour-etat-async.md`)** :
  1. **Retour réel (AC1)** : après une commande stateful (ex. `lock`), l'info **`last_command_result`** par
     véhicule affiche le résultat (état intermédiaire « Acceptée » puis **terminal**) ; le terminal (résolu
     par `vin`) **écrase** l'intermédiaire. Codes interprétés : `0`=succès, `400`=token, `900`/`903`
     intermédiaires, `901`=veille, autre=échec.
  2. **Refresh après confirmation (AC2)** : l'info impactée est rafraîchie **au prochain cron** (borné aux
     commandes **corrélées stateful** ; **jamais** sur le repli `vin` — klaxon/feux, events poussés).
  3. **Échec jamais silencieux (AC3)** : un échec (refus / hors ligne / token) est signalé
     (`message::add`, `removeAll` avant `add`), jamais un faux « succès ». ⚠️ **Écart vs spec initiale** :
     sur code `400` (token), **pas de re-publish automatique** — le token étant rafraîchi chaque minute, un
     400 est rare ; on **signale** « session renouvelée, réessayez » (cf. `18-tech.md`).
  > Test corrélation : lancer deux commandes rapprochées → elles ne sont **pas** confondues (corrélation
  > `correlation_id` puis repli `vin`). Le topic `events/MPHRTServices/#` (états poussés) est **filtré**.

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

### 20-energie-charge
- **Détail batterie & charge (post-MVP 21, ajouté 2026-07-17 — les 3 AC de `21-detail-batterie-charge.md`)** :
  1. **Infos détaillées sur VE/PHEV branché (AC1)** : au cours d'une charge, les commandes info
     **`charging_rate`** (km/h), **`charging_remaining`** (durée restante), **`charging_mode`**,
     **`charge_next_time`** (HH:MM) apparaissent (**création paresseuse** : au 1er `/status` qui les
     contient, pas dans `createCommands`) et se rafraîchissent au cron.
  2. **Durées ISO converties (AC2)** : `charging_remaining` affiche une valeur **numérique en minutes**
     (helper `dureeIsoEnMinutes`, sans clamp — peut dépasser 24 h), **pas** la chaîne ISO brute.
  3. **`battery_12v` universel / rien sur thermique pur (AC3)** : **`battery_12v`** (tension 12 V de
     servitude) est créé sur **tout** véhicule (y compris thermique) ; les 4 champs `charging.*` sont
     confinés à `type=='electric'` ⇒ **jamais** créés sur un thermique pur.

- **Programmation de charge (post-MVP 22, ajouté 2026-07-17 — les 2 AC de `22-programmation-charge.md`)** :
  1. **Fixer l'heure (AC1)** : sur VE/PHEV, la commande action **`charge_set_time`** (subType `message`,
     saisie **`HHMM` sans séparateur**, ex. `2030` = 20:30) publie `type:delayed` sur `/VehCharge` ; l'info
     `charge_next_time` (UC21) reflète la programmation. ⚠️ **Écart vs spec initiale** : **aucun seuil %
     (target SoC)** — non supporté par le contrat consommateur MQTT (cf. `22-tech.md`). **Vérifier qu'AUCUN
     champ % n'est proposé** dans le formulaire (décision produit assumée, pas un bug).
  2. **Saisie invalide (AC2)** : une saisie mal formée → **rejet net** (exception `invalid_input`), **pas
     de clamp** silencieux d'une saisie utilisateur.
  > ⚠️ Effet de bord assumé : `type:delayed` **interrompt une charge immédiate en cours**. Pas de
  > confirmation native (action de routine, ≠ `unlock`). Debounce **partagé** avec `charge_start`/`stop`.

- **Carburant & hybrides (post-MVP 23, ajouté 2026-07-11 — les 2 AC de `23-carburant-hybride.md`)** :
  1. **Hybride** : l'équipement expose SIMULTANÉMENT « Batterie » + « Autonomie électrique » (élec) ET
     « Carburant » + « Autonomie carburant » (thermique), sans collision (valeurs distinctes) ; « Autonomie
     totale » apparaît quand le `/status` fournit les deux autonomies (≈ somme des deux).
  2. **Thermique pur** : aucune commande EV (« Batterie »/« État de charge »/« Câble branché »/« Autonomie
     électrique » absentes) ; seules « Carburant » + « Autonomie carburant » (+ km, position, portes). Sur un
     thermique déjà découvert avant la mise à jour, l'ancienne « Autonomie » (carburant) est **masquée**
     (migration `stellantis_update`), pas de doublon avec « Autonomie carburant ».

- **Suivi & statistiques de charge (post-MVP 24, ajouté 2026-07-17 — les 2 AC de `24-suivi-charge-stats.md`)** :
  1. **Récapitulatif de session (AC1)** : au véhicule, saisir la config **`battery_capacity`** (kWh) et le
     **`charge_tarif`** (€/kWh) dans le formulaire desktop. Après une session (transition `charging_status`
     **`InProgress` → statut terminal** `Finished`/`Stopped`/`Disconnected`/`Failure`), 3 commandes info
     **historisées** se remplissent : **`charge_session_energy`** (kWh ≈ Δ SOC% × capacité),
     **`charge_session_duration`** (min), **`charge_session_cost`** (€).
  2. **Estimations marquées comme telles (AC2)** : les libellés portent **« (est.) »** ; `health()` signale
     une **capacité manquante**. ⚠️ L'API ne connaît **pas** de valeur `Started` ; les statuts terminaux
     **persistent** poll après poll → le log « session non comptabilisée » n'est émis que sur **transition**
     (dernier statut mémorisé en cache), jamais ~288×/j (cf. `24-tech.md`).

### 30-localisation-trajets
- **Position GPS structurée (post-MVP 31, ajouté 2026-07-17 — les 3 AC de `31-position-gps.md`)** :
  1. **Champs enrichis (AC1)** : au-delà de `position` (`lat,lon`, GEOLOC, socle MVP07), les commandes info
     **`latitude`**, **`longitude`**, **`heading`** (Cap, °), **`gps_signal`** (0-9), **`position_updated`**
     apparaissent (**création paresseuse**) et se rafraîchissent au cron.
  2. **Ordre des coordonnées (AC2)** : GeoJSON = `[lon, lat]` → **vérifier** que `position`/`latitude`/
     `longitude` sont **non inversés** (la carte pointe le bon endroit).
  3. **Absence gérée (AC3)** : `position_updated` = **horodatage propre à la position** (≠ `last_update`
     global) ; `heading`/`gps_signal` testés `is_numeric` (**`0` = valeur valide**). ⚠️ Asymétrie assumée :
     un véhicule en **privacy permanente** affiche « Position » (vide) mais **jamais** les 5 dérivées.

- **Panneau carte « Mes véhicules » (post-MVP 32, ajouté 2026-07-17 — les 3 AC de `32-panneau-carte.md`)** :
  1. **Menu panneau (AC1)** : activer le **toggle natif** `displayDesktopPanel` (info.json `display:panel`)
     → l'entrée de menu d'accueil **« Mes véhicules »** apparaît ; elle liste les véhicules `isVisiblePanel`
     (case du formulaire eqLogic, défaut coché) sur lesquels l'utilisateur a `hasRight('r')`.
  2. **Position visible (AC2)** : chaque véhicule montre une **tuile carte** — dans le **panel** (rendu
     serveur) en **`data:` URI inline**, dans le **widget dashboard** (posé sur la cmd `position`) via le
     **proxy** `core/ajax/stellantisMap.ajax.php`. Coordonnées + fraîcheur + lien **OpenStreetMap/`geo:`**
     **toujours** affichés en texte (repli si la tuile échoue).
  3. **Aucun blocage CSP (AC3)** : ouvrir la console navigateur → **aucune** erreur CSP (contenu servi
     **same-origin**, jamais d'`<img>` externe directe). ⚠️ Piège locale : lat/lon formatés via
     `formaterCoordonnee` (jamais `(string)$float` sous `LC_NUMERIC=fr_FR`).

- **Historique des trajets (post-MVP 33, ajouté 2026-07-17 — les 2 AC de `33-historique-trajets.md`)** :
  1. **Dernier trajet lisible (AC1)** : faire un vrai trajet (départ → roulage → arrêt) → détection via le
     prédicat **`moving==1` OU `ignition ∈ {Start,StartUp}`** (clôture seulement quand **les deux** disent
     « arrêté »). Apparaissent : **`moving`**, **`ignition`**, et pour le trajet clos **`trip_distance`**
     (km, **historisée** ⇒ l'historique Jeedom **EST** l'historique des trajets), **`trip_duration`** (min,
     **historisée**), **`trip_start`/`trip_end`** (heures), **`trip_start_position`/`trip_end_position`**
     (`lat,lon`).
  2. **Reconstruction locale (AC2)** : aucun endpoint « trips » (dépréciés côté consommateur) — reconstruit
     à partir du `/status` déjà récupéré au cron. **À surveiller en recette** : pas de **fragmentation** sur
     un arrêt court (dépend de la présence de `kinetic`/`ignition` selon millésime). ⚠️ Garde anti-fantôme :
     distance ≤ 0 ⇒ **aucun** trajet écrit (flicker `moving`/bruit GPS à l'arrêt).

- **Geofencing / zone domicile (post-MVP 34, ajouté 2026-07-17 — les 2 AC de `34-geofencing-alertes.md`)** :
  1. **Bascule at_home (AC1)** : renseigner la **zone domicile unique** en config plugin (`home_lat`,
     `home_lon`, `home_radius`) → l'info **`at_home`** (binary, `generic_type=PRESENCE`, **historisée**) par
     véhicule passe **1/0** selon la position (haversine au cron). L'info **`home_distance`** (m) est
     exposée mais **non historisée**.
  2. **Déclencheur de scénario (AC2)** : `at_home` est utilisable comme déclencheur de scénario Jeedom
     natif. **Hystérésis asymétrique** : entrée = rayon, sortie = rayon + **50 m** → **vérifier** l'absence
     de clignotement à la frontière tant que le bruit GPS < 50 m. ⚠️ Confidentialité : `home_lat`/`home_lon`
     **chiffrées** ; position absente (privacy/pas de fix) ⇒ **freeze** (jamais de faux « parti »).

### 40-entretien-alertes
- **Kilométrage & entretien (post-MVP 41, ajouté 2026-07-17 — les 2 AC de `41-kilometrage-entretien.md`)** :
  1. **Kilométrage (AC1)** : `mileage` (socle MVP07) est **historisé** et lisible (graphe d'usage).
  2. **Échéances d'entretien (AC2)** : si l'endpoint `GET /user/vehicles/{id}/maintenance` répond, les
     commandes info **`service_distance`** (km, historisée), **`service_days`** (jours, historisée),
     **`service_due`** (binary « révision proche », déclencheur de scénario) apparaissent (**création
     paresseuse**) ; seuils **par véhicule** `service_alert_km`/`service_alert_days` (défauts 1000 km /
     30 j). ⚠️ Disponibilité **NON garantie** (endpoint jamais lu par les réfs) → **best-effort** : champs
     absents ⇒ commandes non créées, **pas d'erreur** ; throttle différencié (24 h nominal, **7 j sur 404**,
     3 h transitoire) ⇒ **jamais** un appel `/maintenance` à chaque poll de 5 min.

- **Pression pneus / TPMS (post-MVP 42, ajouté 2026-07-17 — les 3 AC de `42-pression-pneus.md`)** :
  1. **Alerte binaire (AC1)** : une alerte de sous-gonflage remontée par `GET /alerts` met **`tyre_alert`**
     (binary, **historisée**, déclencheur de scénario) à **1** (= OR des 8 types pneus de l'AlertMsgEnum,
     comparaison insensible à la casse).
  2. ⚠️ **Écart vs spec initiale (AC2)** : la **pression numérique est absente** de l'API consommateur →
     **vérifier qu'AUCUNE commande « pression en bar » ni « par roue » n'existe** (les seuls types
     positionnels = `*TyreNotMonitored` = capteur non surveillé, **≠** sous-gonflage), cf. `42-tech.md`.
  3. **Absence gérée (AC3)** : sans alerte pneus → `tyre_alert` = **0**, pas d'erreur (**fail-closed** : une
     entrée sans clé `active` exploitable est ignorée, jamais comptée active). Throttle 1 h nominal / 7 j
     sur 403/404 / 3 h transitoire.

- **Alertes véhicule (post-MVP 43, ajouté 2026-07-17 — les 2 AC de `43-alertes-vehicule.md`)** :
  1. **Une info par type (AC1)** : les alertes actives de `/alerts` (catalogue AlertMsgEnum ~80 types :
     AdBlue, lave-glace, voyants, freinage, révision…) créent **dynamiquement** une commande binaire
     **`alert_<slug>`** par type rencontré (libellé = **libellé brut sécurisé**, jamais `__()`). Un type
     qui redevient inactif repasse à **0**.
  2. **Agrégat scénario (AC2)** : l'info **`alerts_count`** (numeric, **historisée**) permet le déclencheur
     natif « le véhicule a une alerte » (`#alerts_count# > 0`). ⚠️ Les binaires par type sont **NON
     historisées** (isHistorized = stockage, pas déclenchement) ; plafond **`ALERT_MAX_TYPES=100`**. Réutilise
     le **même** poller `/alerts` qu'UC42 (pas de 2ᵉ poller).

- **Ouvrants détaillés (post-MVP 44, ajouté 2026-07-17 — les 2 AC de `44-ouvrants-detailles.md`)** :
  1. **Infos par ouvrant (AC1)** : 8 commandes **`door_<id>`** (Driver/Passenger/RearLeft/RearRight/Trunk/
     RearWindow/RoofWindow + hood par anticipation), binary `generic_type=OPENING`, **non historisées**,
     **création paresseuse** ; se rafraîchissent au cron (`state=='Open'` ⇒ ouvert, **fail-closed**).
  2. **Agrégat (AC2)** : l'info **`opening_alert`** (binary, **historisée**) signale « un ouvrant ouvert »
     (déclencheur scénario « coffre resté ouvert »). ⚠️ **Écart vs spec initiale** : approche **STATIQUE**
     (enum connu de 7 valeurs), **pas** de création « si présent » ; un identifiant **inconnu** est **compté
     dans l'agrégat** + loggué `debug`, jamais émis en logicalId dynamique (cf. `44-tech.md`). Pas de
     commande d'ouverture/fermeture.

### 50-gestion-vehicules
- **Identité du véhicule (post-MVP 51, ajouté 2026-07-17 — les 2 AC de `51-identite-vehicule.md`)** :
  1. **Identité en config (AC1)** : la config de l'équipement affiche VIN, marque, motorisation et un champ
     **readonly « Libellé du véhicule »** (= `label`, surnom **renommable dans l'app mobile**). Une commande
     info string **`label`** (universelle) est peuplée.
  2. **Nom par défaut (AC2)** : le nom de l'eqLogic par défaut = **marque + libellé**. ⚠️ **Écart vs spec
     initiale** : l'API n'a **NI `model` NI `motorization`** → **pas** de commande `vin` ni `model` (« Libellé »
     ≠ « Modèle » ; le VIN reste visible **en config admin seulement**), cf. `51-tech.md`. La valeur `label`
     (texte externe libre) est **neutralisée** (`htmlspecialchars(aseptiser())`) avant écriture.

- **Image / vignette du modèle (post-MVP 52, ajouté 2026-07-17 — les 2 AC de `52-image-modele.md`)** :
  1. **Image cohérente (AC1)** : chaque eqLogic véhicule porte une image via une **cascade** — (1) **photo
     modèle** best-effort depuis le champ `pictures` de `/user/vehicles` → (2) **icône de marque** bundlée
     (`plugin_info/brands/{peugeot,citroen,ds,opel,vauxhall}.png`) → (3) repli **icône plugin**.
  2. **Servie en local (AC2)** : **vérifier** (console navigateur) que l'image se charge **sans dépendance
     réseau** au rendu et **sans blocage CSP** (asset same-origin, jamais d'`<img>` externe). Une image
     posée **manuellement** par l'utilisateur (source ∉ {model,brand}) **n'est jamais écrasée**. ⚠️ Shape
     `pictures` non vérifiée (stub Swagger) → **log `debug`** de la forme brute pour l'observer en beta.

- **Multi-véhicules & multi-comptes — cadrage (post-MVP 53, ajouté 2026-07-17 — les 2 AC de `53-multi-vehicules-comptes.md`)** :
  > ⚠️ **UC de cadrage, 100 % documentaire (aucun code)** → **aucun scénario propre à exécuter** ; on
  > pointe vers l'existant (règle « doc-only » des Conventions).
  1. **AC1 (sync correcte/performante à plusieurs véhicules)** : **déjà couvert** par
     **« Découverte/équipements (05-06) »** (1 eqLogic/VIN, 2ᵉ sync = 0 doublon) et **« Anti-ban (72) »**
     (quotas **GLOBAUX au compte**, token mutualisé 1×/passe, `try/catch` par véhicule).
  2. **AC2 (besoin multi-comptes cadré)** : décision **documentée** (cf. `stellantis-api-architecture.md`
     § 4.5) — pas de comportement observable propre ; sa **concrétisation** est **UC54** (voir sa recette).

- **Multi-marques & multi-comptes — lecture seule (post-MVP 54, ajouté 2026-07-17 — les 3 AC de `54-multi-marques.md`)** :
  1. **Slots de comptes (AC3, cas particulier multi-marques)** : le **slot 1** = le compte principal
     (config actuelle, **pilotage à distance**). Si le slot 1 est configuré, des sections repliables
     **« Compte secondaire N (lecture seule) »** apparaissent (jusqu'à `MAX_ACCOUNTS=3`) — OAuth 2 étapes +
     extraction APK + « Tester » **par slot**. Rattacher un **compte Citroën** au slot 2 alors que le slot 1
     est Peugeot → **Peugeot + Citroën coexistent**.
  2. **Cloisonnement par compte (AC1+AC2)** : les véhicules des deux comptes sont **tous rafraîchis** au
     cron (token primé **1× par slot**) ; un **429/quota/échec d'auth** d'un compte **ne gèle jamais**
     l'autre (clés cache **suffixées par slot**). `connectionState()` = **pire-état** des comptes ;
     `health()` = **1 ligne par compte**. **Vérifier** qu'aucune fuite de token entre comptes.
  3. ⚠️ **Limite assumée** : le **pilotage à distance** (commandes/OTP/MQTT) est sur le **slot 1
     UNIQUEMENT** (démon mono-connexion) → sur un véhicule de slot ≠ 1, les commandes **action** ne sont pas
     créées, et un appel forcé est **refusé** « lecture seule » (garde runtime `execute()`).

### 60-configuration-avancee
- **Extraction auto des identifiants APK (post-MVP 61, ajouté 2026-07-17 — les 5 AC de `61-extraction-auto-credentials.md`)** :
  1. **Pré-remplissage (AC1)** : marque + pays renseignés → clic **« Extraire automatiquement »** →
     confirmation/ToS (télécharge un APK tiers), message de progression, puis **Client ID + Client Secret
     pré-remplis** (extraits de `parameters.json` : `cvsClientId`/`cvsSecret`). **Pré-remplissage
     seulement** : l'admin **valide/sauvegarde** (pas de sauvegarde silencieuse).
  2. **APK jamais conservé (AC2)** : après extraction, **vérifier** que l'APK/`.bz2` téléchargé est
     **supprimé** immédiatement (jamais persisté sur le disque Jeedom).
  3. **Aucune extension PHP (AC3 adapté)** : la décompression bz2 + lecture zip passe par
     `resources/extract_credentials.py` (stdlib Python `bz2`/`zipfile`) → **aucune** extension PHP requise,
     **pas** de redémarrage d'Apache ; sur un env sans Python démon, message clair renvoyant vers la
     procédure manuelle (`docs/<langue>/index.md`).
  4. **Échec non bloquant (AC4)** : provoquer un échec (réseau, chemin introuvable, structure APK modifiée)
     → message **actionnable**, **aucun** état de config incohérent, la **saisie manuelle** reste possible
     ensuite.
  5. **Une seule marque (AC5)** : **vérifier** que seule la marque **configurée** est téléchargée (jamais
     les 5). ⚠️ Action strictement **manuelle** : jamais déclenchée par le cron ni à la sauvegarde de
     config.

### 70-supervision-robustesse
- **Santé & fraîcheur (post-MVP 71, ajouté 2026-07-17 — les 2 AC de `71-sante-fraicheur.md`)** :
  1. **Contenu de la page Santé (AC1)** : la page Santé (`stellantis::health()`) affiche l'état d'auth **par
     compte**, l'état du **démon** (dont le cas « OTP actif **mais** démon arrêté » signalé en **rouge**), la
     **fraîcheur par véhicule**, le **dernier `last_command_result` par véhicule** (avant le bloc privacy),
     une ligne **statistiques d'appels** (UC77) et un **lien Documentation**.
  2. **Aucun appel API (AC2)** : ouvrir la page Santé → **observer les logs** : **aucun** appel réseau
     supplémentaire au chargement (états lus depuis le cache/local). ⚠️ Le `state` (vert/rouge) du dernier
     résultat de commande est dérivé d'un **marqueur cache machine non traduit** (`CMD_STATUS_KEY`), pas du
     texte affiché (un test sur « Échec » casserait en en/de/es).

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
- **Renouvellement & alertes de token (post-MVP 74, ajouté 2026-07-17 — les 3 AC de `74-renouvellement-alertes-token.md`)** :
  1. **OAuth mort sans boucle (AC1)** : corrompre le refresh_token en cache (ou attendre un `invalid_grant`)
     → le token cache est **supprimé** ⇒ la passe cron suivante lève **`auth_required` sans réseau** (pas de
     boucle d'appels), les véhicules du slot sont **sautés** ; alerte **« reconnexion requise »** (log
     `warning` + **message** centre de messages + page Santé, `alerterAuthRequired`). Une ré-auth manuelle
     **ou** un refresh auto ré-efface l'alerte (`storeTokenResponse`).
  2. **Remote token expiré (AC2)** : cf. « OTP & remote token (12) » § 3 → **`otp_required`** +
     `alerterOtpRequired`, **sans** régénération OTP automatique (quota).
  3. **États visibles / pas de secret (AC3)** : bandeau page plugin + page Santé reflètent l'état ; `grep`
     des logs → **aucun** secret en clair. ⚠️ Limite assumée : message/flags **par slot** orphelins si un
     compte secondaire est **totalement déconfiguré** (préexistant, symétrique de `rate_limited_<slot>`).

- **Mode privacy du véhicule (post-MVP 75, ajouté 2026-07-17 — les 2 AC de `75-mode-privacy.md`)** :
  1. **Signalé clairement (AC1)** : couper Data/Géoloc **côté voiture** (`privacy.state ≠ None`) → l'info
     **`privacy_mode`** (binary, **historisée**, déclencheur de scénario) passe à **1** ; un **message
     d'aide** edge-triggered apparaît (« réactivez le partage de données côté véhicule, **ce n'est pas une
     panne** », tag `privacy_<eqId>`) et **retombe à 0** en sortie de privacy. Le privacy est **exclu** de
     `connectionState()` (pas une panne).
  2. **Pas de retry en boucle (AC2)** : **observer la cadence** — le polling est **réduit** (~1 poll /
     30 min, `CRON_PRIVACY_STEP`) tant que le privacy est actif (économie quota/anti-ban), mais on continue
     de sonder pour **détecter la sortie** (latence ≤ 30 min) ; pas de martèlement en retry. ⚠️ Contrat
     `privacy.state` enum `None`/`Geolocation`/`Full` ; le nom du véhicule est **neutralisé**
     (`htmlspecialchars`) dans `message::add`.

- **Synchronisation sélective (post-MVP 76, ajouté 2026-07-17 — les 2 AC de `76-synchronisation-selective.md`)** :
  1. **Exclure du refresh auto (AC1)** : décocher **« Inclure dans le rafraîchissement auto »**
     (`syncEnabled`, défaut coché) → le cron **saute** le véhicule (et l'auto-wakeup UC73) MAIS il **reste
     activé/visible**, ses **dernières valeurs et son historique sont conservés** ; une ligne page Santé
     **« Rafraîchissement automatique désactivé »** l'explique. Le refresh **post-commande** (CMD_PENDING) et
     le **« Synchroniser » manuel** (bouton) rafraîchissent **tous** les véhicules, même décochés.
  2. **Véhicule disparu (AC2)** : un véhicule **absent** de la découverte est **désactivé** (jamais
     supprimé), marqueur **`autoDisabled`** ; à sa **réapparition**, un véhicule **auto-désactivé** est
     **réactivé automatiquement**, tandis qu'une désactivation **manuelle** (ou héritée d'avant UC76, sans
     marqueur) est **respectée** (**pas** de réactivation en masse à l'upgrade). ⚠️ `preSave` efface
     `autoDisabled` sur toute bascule **manuelle** d'`isEnable`.

- **Statistiques d'appels API (post-MVP 77)** : les 3 AC de `77-statistiques-api.md`.
  1. **Comptage exhaustif (AC1)** : après un cycle de cron (télémétrie) + une ré-authentification OAuth2 +
     une activation/renouvellement OTP, la page plugin ET la page Santé affichent un total d'appels **> 0**
     avec un détail par endpoint reconnaissable (`/user/vehicles`, `/status`, `/access_token`…).
  2. **Consultable (AC2)** : le bloc « Consommation de l'API REST » est visible sur la page plugin
     (bandeau, sous l'état de connexion) et une ligne « Appels API REST (aujourd'hui) » apparaît sur la
     page Santé ; en multi-comptes (UC54), une ventilation « Compte 1 / Compte 2… » apparaît sur les deux
     surfaces.
  3. **Non bloquant (AC3)** : provoquer une panne du cache (ex. `cache::flush()` pendant un cron) →
     le refresh télémétrie **aboutit quand même** (aucune exception remontée depuis le comptage), au pire
     le compteur repart de zéro.
  4. **⚠️ Faux positif de dérive (seuil `STATS_DERIVE_SEUIL=60/min/compte`, estimation à confirmer)** :
     cliquer sur « Synchroniser les véhicules » sur une flotte multi-véhicules/multi-comptes → `grep -i
     "Dérive du volume d'appels" log/stellantis` doit rester **vide** (un sync d'une dizaine de véhicules
     reste largement sous le seuil, cloisonné par compte) ; si le warning apparaît en usage normal, le
     seuil est à recalibrer (pas une régression bloquante, cf. limite assumée de `77-tech.md`).
  5. **APK non compté** : après « Extraire automatiquement » les credentials APK (UC61), le total
     d'appels API REST **n'augmente pas** (téléchargement GitHub hors périmètre, cf. `downloadToFile()`).

### 80-livraison
- **Icône du plugin (post-MVP 83, ajouté 2026-07-17 — les 2 AC de `83-icone-plugin.md`)** :
  1. **Icône valide (AC1)** : le fichier `plugin_info/stellantis_icon.png` (PNG 309×348 « véhicule
     connecté » générique, coins arrondis + transparence) s'affiche dans la liste des plugins / le Market
     (remplace le placeholder du template).
  2. **Conformité (AC2)** : **vérifier** l'absence de **logo constructeur déposé** (Peugeot/Citroën…) et de
     **collision de code couleur** avec les icônes du core Jeedom.

## Critères d'acceptation
- [ ] Chaque UC livrée a au moins un scénario de recette observable, vérifié sur Jeedom réel.

## Notes
- Ne **jamais** prétendre qu'un comportement runtime est validé sans l'avoir constaté ici.
- NB : les en-têtes « Statut » des specs fonctionnelles livrées sont majoritairement **périmés**
  (« à spécifier » alors que la fonctionnalité est livrée et commitée) — nettoyage **hors périmètre** de
  cette recette (le fusionner diluerait la revue ligne-à-ligne de 81), à traiter en **commit séparé**
  mécanique.
