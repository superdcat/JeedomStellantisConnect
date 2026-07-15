# 72 — Rate-limiting & anti-ban

**Domaine :** Supervision / robustesse · **Dépend de :** MVP/10 · **Complémentaire de :** UC73 (batterie), UC77 (stats) · **Statut :** à spécifier

## Objectif / valeur
Protéger le compte d'un **ban API** PSA/Stellantis (documenté, persistant, bloque aussi le remote token).
Centraliser backoff, cooldowns et limites de fréquence pour **ne jamais marteler** l'API.

## Périmètre
- **Inclus** : backoff exponentiel sur `429`/erreurs, cooldown global respecté par cron & commandes,
  **plafonds de fréquence** (notamment wakeup), file/anti-rafale des commandes.
- **Exclu** : la décision de cadence selon l'état du véhicule (→ UC73).

## Détails techniques
- ⚠️ Faits durs (cf. analyse § 1.4) : **ban si wakeup ~toutes les 2 min** ; wakeup limité **~6/20 min** ;
  remote token OTP **6/24 h**. Un ban bloque aussi le refresh remote token → **prévention >> guérison**.
- Implémentation : cooldown en cache (`stellantis::ratelimit_until`) respecté partout ; backoff
  exponentiel borné sur erreurs transport/429 ; **throttle dédié wakeup** (min 5 min entre deux) ;
  sérialiser les commandes (pas de rafale).
- **Anti-rafale du polling (déplacé depuis UC53, cf. `50-gestion-vehicules/53-tech.md` § couverture AC1)** :
  à la cadence par défaut `CRON_DEFAUT='*/5 * * * *'`, tous les véhicules d'une flotte tombent dus à la
  **même minute** (0/5/10…) → rafale de N × (~2 appels REST). Lisser via un **décalage de phase
  déterministe** pour les véhicules en cadence **par défaut** uniquement (ex. offset `eqId % 5` →
  expression `'%d-59/5 * * * *'`) ; un `autorefresh` personnalisé est **respecté tel quel** (opt-out).
  ⚠️ Limites à assumer : collision résiduelle mod-5 (les `eqId` sont globaux/non denses) ; **changement de
  phase observable** (`last_update` passe de :00/:05 à :01/:06… → à signaler au changelog) ; syntaxe cron
  `A-B/step` non éprouvée dans ce projet → **vérifier l'expression générée en recette** (historique de
  pièges cron). ROI modeste à l'échelle foyer 1-4 (hygiène préventive, pas d'urgence).
- Sur ban détecté : passer en mode dégradé (lecture conservée), alerter, suspendre les appels un temps long.

## Critères d'acceptation
- [ ] Aucun chemin de code ne peut émettre des appels en rafale (cron, commandes, wakeup).
- [ ] Un `429`/ban déclenche un cooldool respecté globalement, avec alerte.
- [ ] Le wakeup est throttlé sous la limite serveur (jamais < 5 min).

## À confirmer
- Valeurs exactes des seuils/durées de ban (non documentées ; calibrer prudemment, cf. issues #1130/#967).
