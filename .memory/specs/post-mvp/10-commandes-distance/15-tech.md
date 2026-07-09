# Spec technique — 15 (Préconditionnement climatique immédiat)

## Contrat API confirmé (source : `psa_car_controller/psa/RemoteClient.py` + `constants.py`, WebFetch)

- Service MQTT : `/ThermalPrecond` (topic = `psa/RemoteServices/from/cid/{CID}/ThermalPrecond`, même
  enveloppe `MQTTRequest` que wakeup (UC13) et charge (UC14) — via `buildMqttRequest()`, inchangé).
- Payload `req_parameters` : `{"asap": "activate"|"deactivate", "programs": <objet 4 programmes>}`.
- `programs` (littéral du code de référence, envoyé quand aucun programme n'a été appris depuis les
  events MQTT — c'est **exactement** notre cas puisque le suivi des programmes est hors scope UC15) :
  ```json
  {"program1":{"day":[0,0,0,0,0,0,0],"hour":34,"minute":7,"on":0},
   "program2":{"day":[0,0,0,0,0,0,0],"hour":34,"minute":7,"on":0},
   "program3":{"day":[0,0,0,0,0,0,0],"hour":34,"minute":7,"on":0},
   "program4":{"day":[0,0,0,0,0,0,0],"hour":34,"minute":7,"on":0}}
  ```
- Lecture d'état : `preconditionning.airConditioning.status` (**double n**, confirmé data-model) —
  `Enabled/Disabled/Finished/Failure` + `failure_cause`.

⚠️ **Risque documenté et accepté** : le payload `programs` est toujours ce littéral figé (jamais les
programmes réels du véhicule, puisque leur suivi — via les events MQTT `psa/RemoteServices/events/…` —
est explicitement hors scope UC15/spec fonctionnelle). C'est le **même comportement par défaut** que le
code de référence `psa_car_controller` applique tant qu'il n'a pas appris de programmes réels (usage
réel, multi-années, aucun signalement de programmation écrasée) — on s'aligne donc sur un comportement
déjà éprouvé, pas une improvisation. Si un futur retour terrain contredit cette hypothèse (programmation
existante réellement altérée par un `precond_on`/`off`), ce sera le déclencheur pour implémenter le
suivi des events `programs` (variante avancée déjà notée « Exclu » dans la spec fonctionnelle 15).

## Architecture — `core/class/stellantis.class.php` (aucun nouveau fichier)

- Constantes (bloc UC15, miroir UC13/UC14) :
  - `PRECOND_SERVICE = '/ThermalPrecond'`
  - `PRECOND_DEBOUNCE = 10` (s) + `PRECOND_DEBOUNCE_KEY` (cache, + eqId) — anti-boucle per-véhicule,
    pas de cooldown long (un vrai toggle on→off doit passer), quota anti-ban déjà mutualisé dans
    `publishRemoteCommand()`.
- `precondProgramDefaut(): array` — méthode statique privée (pas une `const` array, pour rester
  cohérent avec le style « 1 const = 1 ligne commentée » du fichier) qui retourne le littéral ci-dessus,
  avec commentaire expliquant le choix (cf. risque documenté).
- `definitionsActions()` += `precond_on` → `__('Activer le préconditionnement', __FILE__)`,
  `precond_off` → `__('Désactiver le préconditionnement', __FILE__)`.
- `definitionsCommandes()` += `precond_status` → `__('Préconditionnement', __FILE__)`, `string`, pas
  de generic_type, non historisé (miroir `charging_status`).
- `createCommands()` : `precond_status` + `precond_on`/`precond_off` créés **universellement** (tout
  véhicule, y compris thermique — chauffage habitacle, pas seulement climatisation élec).
- `precondControl(bool $_activer): void` (nouvelle méthode d'instance, miroir `chargeControl`) :
  1. Debounce per-véhicule (`PRECOND_DEBOUNCE_KEY`), posé **avant** tout appel réseau.
  2. Pré-requis OTP (`stellantisApi::hasRemoteToken()`, sinon `alerterOtpRequired()` + exception
     `otp_required`).
  3. `publishRemoteCommand(self::PRECOND_SERVICE, ['asap' => $_activer ? 'activate' : 'deactivate',
     'programs' => self::precondProgramDefaut()])`.
  4. `cache::set(self::CMD_CORR_KEY . $correlationId, $this->getId(), self::CMD_CORR_TTL)` — réutilise
     le pipeline d'ack générique existant (**aucune** modification de `handleDaemonMessage`).
  5. `log::add('stellantis', 'info', ...)`.
- `parseStatus()` : += extraction `preconditionning.airConditioning.status` → `precond_status`
  (filtrage lettres, même défense en profondeur que `charging_status`) ; si `Failure`, log warning avec
  `failure_cause` (répond au critère d'acceptation « refus remonté clairement » — validation stricte du
  seuil batterie/branchement = UC18, pas cette UC).
- `stellantisCmd::execute()` += cases `precond_on`/`precond_off` → `$eqLogic->precondControl(true/false)`.

## Validation

- Côté serveur (véhicule) uniquement : aucune garde motorisation/batterie proactive côté plugin (cf.
  risque documenté § contrat API et note UC18 de la spec fonctionnelle). Le refus éventuel remonte via
  `precond_status = Failure` au prochain cron (pipeline d'ack générique) + log warning avec cause.
- Debounce per-véhicule (anti-boucle scénario), pré-requis OTP, quota global compte (mutualisé) : mêmes
  garde-fous que UC13/UC14, aucune duplication de logique.

## Dépendances

Aucune nouvelle dépendance (réutilise entièrement le démon MQTT UC11 + remote token UC12).
