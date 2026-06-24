# UC — Synchronisation sélective par caméra (choix des infos rafraîchies)

**Domaine :** Supervision / robustesse · **Dépend de :** UC10 (cron/refreshStates), UC13 (propriétés IoT),
UC14 (statuts de service) · **Complémentaire de :** UC72 (rate-limiting) · **Statut :** à spécifier (tech)

## Objectif / valeur
Laisser l'utilisateur **choisir, caméra par caméra, quelles informations sont rafraîchies
automatiquement** au cron. Aujourd'hui `refreshStates()` poll TOUTES les capacités applicables de
CHAQUE caméra à chaque cycle. Or :
- chaque **état de switch** (`getDeviceCameraStatus`) coûte **un appel API par capacité** (endpoint
  non-batchable, confirmé doc 2026-06-18) → c'est le principal poste de quota ;
- l'utilisateur n'a pas toujours besoin de tout (ex. il pilote le projecteur mais se moque de la
  vision nocturne ou du compte à rebours).

Donner ce réglage permet de **réduire la consommation d'appels** (palier gratuit = 5 appareils,
quotas non publics) et le **bruit** (historisation/logs), sans renoncer aux commandes elles-mêmes.

## Décisions (validées utilisateur, 2026-06-18)
- **Granularité = par capacité** (et non par groupe ni preset) : une case à cocher **par info
  réellement pollable de la caméra** (cam­éra, surveillance, projecteur, chaque propriété IoT
  exposée, compte à rebours minuterie…), **construite dynamiquement** selon ce que la caméra expose.
- **Portée = poll-only** : décocher une info **n'enlève pas** la commande (toujours visible,
  actionnable, utilisable en scénario, rafraîchissable manuellement) ; on **coupe uniquement** son
  rafraîchissement automatique au cron. Réversible sans perte.

## Périmètre
- **Inclus** : sélection par caméra des infos auto-rafraîchies ; gating des 3 phases de
  `refreshStates()` (états switches, propriétés IoT batch, statuts de service) **à la maille de
  l'info** ; UI dynamique listant les capacités réelles de la caméra ; valeur par défaut.
- **Exclu** : la **cadence** de rafraîchissement (déjà couverte par `autorefresh`, UC10) ; le
  throttling/backoff global (UC72) ; toute suppression de commande.

## Esquisse Jeedom (intention — détails en spec technique)
- **Stockage** : flags par caméra dans la `configuration` de l'eqLogic (clé par `logicalId` d'info,
  ou liste des infos désactivées). Défaut **absent = activé** → aucune régression.
- **UI** : section « Paramètres spécifiques » de l'onglet Équipement (`desktop/php/imou.php`), à côté
  d'`autorefresh`. Les cases sont **rendues dynamiquement** (JS `desktop/js/imou.js`) à partir des
  infos pollables réellement présentes sur la caméra (états switches applicables + `iot_*_state` +
  statuts de service). Une capacité absente n'apparaît pas.
- **Gating polling** : `refreshStates()` saute une info dont le flag est « off ». Le gating s'applique
  quel que soit le chemin de cron qui rafraîchit la caméra — `cron()` (caméras avec `autorefresh`) ou
  `cron5()` (les autres) appellent tous deux `refreshStates()`. Conséquence quota par type :
  - **switches** : chaque info désactivée = **un appel `getDeviceCameraStatus` en moins** (gain direct) ;
  - **propriétés IoT** : lecture **batch** → l'appel `getIotDeviceProperties` n'est évité que si
    **toutes** les propriétés sont désactivées ; sinon seul le `ref` désactivé est retiré du lot ;
  - **statuts de service** : chaque statut désactivé = **un appel `iotDeviceControl` en moins**.
- **Nouvelles capacités** découvertes plus tard (re-sync) : **pollées par défaut** (flag absent =
  activé), l'utilisateur peut ensuite les décocher.

## Critères d'acceptation
- [ ] L'onglet Équipement liste **dynamiquement** les infos pollables réelles de la caméra (pas de
      case pour une capacité absente).
- [ ] Décocher une info **arrête son rafraîchissement** au cron ; la commande reste présente et
      reste **actionnable / rafraîchissable manuellement**.
- [ ] Décocher tous les états switches d'une caméra **supprime les appels `getDeviceCameraStatus`**
      correspondants (vérifiable dans les logs debug).
- [ ] Décocher toutes les propriétés IoT d'une caméra **supprime l'appel `getIotDeviceProperties`**.
- [ ] Par défaut (caméra jamais configurée), **tout est synchronisé** (comportement actuel inchangé).
- [ ] Réglage **par caméra** (indépendant d'une caméra à l'autre).

## Notes / risques
- **Énumération dynamique** : la liste des infos pollables doit refléter le gating réel
  (`switchApplicable`, présence au modèle IoT). Source de vérité = les commandes info existantes de
  l'eqLogic et/ou un appel de découverte (à arbitrer en tech : lire les `cmd` info de l'eqLogic vs
  recalculer le catalogue applicable).
- **Dégradation gracieuse** : si le modèle IoT est momentanément indisponible (réseau KO), la liste
  peut ne pas pouvoir afficher les infos IoT — l'UI ne doit alors **ni perdre ni écraser les flags
  déjà enregistrés** (cohérent avec l'anti-destruction « modèle null → on ne touche à rien »). Un flag
  « off » d'une info temporairement non listée reste respecté au cron.
- **Articulation future avec `online` (UC71)** : quand le statut online/santé sera livré, l'info
  `online` devra soit apparaître dans la liste sélectionnable, soit être traitée comme **essentielle**
  (toujours pollée car peu coûteuse et structurante pour la page Santé) — à arbitrer à ce moment-là.
- **Cohérence avec `autorefresh`** : `autorefresh` règle *quand* (cadence), cette UC règle *quoi*
  (périmètre) — les deux se combinent (une info non synchronisée n'est jamais pollée, quelle que soit
  la cadence).
- **Robustesse préservée** : le gating ne change pas les garanties « ne lève jamais » de
  `refreshStates()` ni l'anti-destruction (modèle IoT indisponible → on ne touche à rien).
- **i18n** : libellés des cases (FR) + l'intitulé de la section. Les libellés d'info réutilisent ceux
  des commandes (déjà traduits).
- **Lien UC72** : ce réglage par caméra s'articule avec le rate-limiting global (throttling/backoff) ;
  les deux réduisent la pression quota par des leviers différents (périmètre vs fréquence/temporisation).
