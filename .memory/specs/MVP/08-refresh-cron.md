# 08 — Rafraîchissement périodique (cron)

**Phase :** MVP · **Dépend de :** 07 · **Fichiers :** `core/class/stellantis.class.php` (`stellantis::cron`/`cron5`)

## Objectif
Maintenir à jour les commandes info en interrogeant périodiquement `GET /status` (+ `/lastPosition`),
puisque l'API consommateur **n'offre pas de push** accessible.

## Périmètre
- **Inclus** : `cron`/`cron5` parcourant les véhicules actifs, lecture du statut, MAJ des cmd info ;
  cadence configurable par véhicule.
- **Exclu** : forcer une lecture fraîche (= **wakeup**, MQTT, post-MVP) ; régulation avancée (→ UC72/73).

## Détails techniques
- Implémenter `stellantis::cron5()` (décommenter le hook) :
  - pour chaque `eqLogic::byType('stellantis')` activé, `stellantisApi::callWithToken('GET','/user/vehicles/'.$apiId.'/status')`,
    puis `parseStatus()` → `checkAndUpdateCmd(...)`. Position via `/lastPosition` si nécessaire.
  - **mutualiser le token** (un seul `getToken()` par passe de cron).
- Respecter une **« Auto-actualisation »** par véhicule (`configuration:autorefresh`, cron Jeedom par
  équipement) si renseignée ; sinon cadence par défaut de `cron5`.
- ⚠️ **IMPORTANT — pas de wakeup ici** : le cron lit le **dernier état remonté** par la voiture
  (REST seul). On **ne réveille pas** le véhicule (le wakeup = MQTT + quota strict + risque batterie ;
  cf. analyse § 1.4 et UC73). Le polling REST est peu coûteux en énergie véhicule.
- Robustesse : un véhicule en erreur ne casse pas la boucle (try/catch **par véhicule**).

## Critères d'acceptation
- [ ] Les commandes info se mettent à jour automatiquement au fil des passes de cron.
- [ ] Un véhicule injoignable n'interrompt pas la mise à jour des autres.
- [ ] La cadence par défaut reste raisonnable (pas de martèlement) ; pas de wakeup déclenché.

## Notes / risques
- Fraîcheur limitée : sans wakeup, la donnée reflète le dernier événement remonté par la voiture
  (fin de trajet, jalon de charge). C'est une **limite MVP assumée et documentée** (le wakeup à la
  demande arrive en post-MVP avec le démon).
- Surveiller le volume d'appels (cf. UC77 statistiques) au-delà de quelques véhicules.
