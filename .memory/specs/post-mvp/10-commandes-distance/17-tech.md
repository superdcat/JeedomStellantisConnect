# Spec technique — 17 (Klaxon & feux — retrouver le véhicule)

## Contrat API (MQTT via démon)

Confirmé **verbatim** contre `psa_car_controller/psa/RemoteClient.py` (branche `master`, 2026-07-10) —
le contrat signalé « évolutif » par l'issue #1199 dans la spec fonctionnelle est en réalité **inchangé** :

| Commande | Service (segment topic) | `req_parameters` |
|---|---|---|
| Klaxon | `/Horn` | `{"nb_horn": <int count>, "action": "activate"}` |
| Feux | `/Lights` | `{"action": "activate", "duration": <int secondes>}` |

```python
def horn(self, vin, count):
    msg = self.mqtt_request(vin, {"nb_horn": count, "action": "activate"}, "/Horn")
def lights(self, vin, duration: int):
    msg = self.mqtt_request(vin, {"action": "activate", "duration": duration}, "/Lights")
```

Enveloppe `MQTTRequest` identique à UC13-16 (topic complet
`psa/RemoteServices/from/cid/{CID}/Horn` et `.../Lights`, message
`{access_token, customer_id, correlation_id, req_date, vin, req_parameters}`) — entièrement produite par
`buildMqttRequest()` / `publishRemoteCommand()` (quota global compte anti-ban, vérif CID + démon, alignement
remote token). Aucun nouvel appel réseau propre.

## Architecture

Deux commandes **action « sans état »** (aucune info liée, bouton par défaut `subType='other'`,
`generic_type=''`), **universelles** (tout véhicule a klaxon + feux). Un seul fichier modifié :
`core/class/stellantis.class.php`.

1. **Constantes UC17** (nouveau bloc « UC17 — Klaxon & feux ») :
   - `HORN_SERVICE = '/Horn'`, `LIGHTS_SERVICE = '/Lights'`
   - `HORN_COUNT = 2` (nombre de coups par défaut), `LIGHTS_DURATION = 10` (durée d'allumage par défaut, s)
   - `HORN_DEBOUNCE = 10` / `HORN_DEBOUNCE_KEY = 'stellantis::horn_debounce::'`
   - `LIGHTS_DEBOUNCE = 10` / `LIGHTS_DEBOUNCE_KEY = 'stellantis::lights_debounce::'`
     (constantes séparées par domaine, même si valeur identique — convention UC13-16).
2. **`definitionsActions()`** : ajouter
   `'horn' => array(__('Klaxonner', __FILE__), 'other', '')` et
   `'lights' => array(__('Allumer les feux', __FILE__), 'other', '')` (verbes à l'impératif = convention).
3. **`createCommands()`** : `ensureActionCommand('horn')` + `ensureActionCommand('lights')`
   **inconditionnels** (après `lock`/`unlock`).
4. **Méthodes métier** `horn()` et `lights()` (publiques, docblock complet `@throws`) déléguant à un
   **helper privé partagé** `declencherSignal(string $_service, array $_reqParameters, string $_cleDebounce, int $_debounce, string $_refusMsg): void` (le `int $_debounce` est requis pour distinguer `HORN_DEBOUNCE` de `LIGHTS_DEBOUNCE`) :
   - (1) debounce per-véhicule court : refus net `rate_limited` si trop récent (`$_refusMsg`) ;
   - (2) pré-requis OTP : `!stellantisApi::hasRemoteToken()` → `alerterOtpRequired()` + throw `otp_required` ;
   - (3) pose le debounce **AVANT** tout appel réseau ;
   - (4) `publishRemoteCommand($_service, $_reqParameters)` (retour `correlation_id` loggué).
   - ⚠️ **Décision (validée)** : commandes **stateless** → **PAS** de `cache::set(CMD_CORR_KEY…)`. Aujourd'hui
     `CMD_CORR_KEY` a un unique effet — programmer un refresh REST au prochain cron
     (`programmerRefreshApresAck`) ; klaxon/feux n'ont **aucune télémétrie** à relire → un refresh serait
     gaspillé (slot de quota anti-ban pour rien) et aucun consommateur « corrélation seule » n'existe avant
     UC18. Un **commentaire explicite** dans les deux méthodes documente cette **dette assumée** : la
     corrélation ack→véhicule (retour d'état `last_command_result`) est du ressort d'**UC18**, transverse à
     UC13-17, qui réécrira ce pipeline.
5. **`stellantisCmd::execute()`** : `case 'horn'` → `$eqLogic->horn()` ; `case 'lights'` → `$eqLogic->lights()`.

## Server vs Client

100 % serveur (PHP). Aucun JS/HTML custom : les deux commandes action `subType='other'` sont rendues par
le **widget bouton par défaut du core** (comme wakeup/precond/lock). Pas de confirmation
(`actionConfirm`) : actions bénignes (≠ `unlock`).

## Validation

- **Debounce** per-véhicule court (10 s, cache) posé **avant** le réseau : borne les répétitions
  (anti-boucle scénario) tout en autorisant un enchaînement klaxon→feux (clés distinctes).
- **Pré-requis OTP** : sans remote token, pas de canal MQTT → `otp_required` + alerte throttlée.
- **Quota global compte** (anti-ban serveur) : réutilisé tel quel depuis `publishRemoteCommand()`.
- **Aucune** garde motorisation (universel), aucune garde batterie proactive.
- `$_refusMsg` est **toujours** une chaîne déjà traduite par un `__()` **littéral** au point d'appel
  (`horn()`/`lights()`), jamais reconstruite dans le helper (piège extracteur i18n statique, cf. UC07).

## Server Actions / API

- `public function horn(): void` — docblock `@throws stellantisException 'rate_limited'|'otp_required'|api_error`.
  Corps : `$this->declencherSignal(self::HORN_SERVICE, array('nb_horn' => self::HORN_COUNT, 'action' => 'activate'), self::HORN_DEBOUNCE_KEY . $this->getId(), self::HORN_DEBOUNCE, __('Klaxon déjà déclenché à l\'instant ; patientez %d s', __FILE__));` (le `%d` restant est injecté par le helper via `sprintf` sur le reste du debounce).
- `public function lights(): void` — idem, `self::LIGHTS_SERVICE`, `array('action' => 'activate', 'duration' => self::LIGHTS_DURATION)`, `self::LIGHTS_DEBOUNCE_KEY`, `self::LIGHTS_DEBOUNCE`, message `__('Feux déjà déclenchés à l\'instant ; patientez %d s', __FILE__)`.
- `private function declencherSignal(string $_service, array $_reqParameters, string $_cleDebounce, int $_debounce, string $_refusMsg): void` — factorise les étapes 1-4 (voir Architecture § 4).

> **Paramètres nb-coups / durée** : constantes à défaut raisonnable (2 coups, 10 s). Le critère
> d'acceptation autorise « configurables **OU** défaut raisonnable ». La configurabilité par commande est
> une amélioration différée (éviterait le piège « clé de config effacée au Sauvegarder » du formulaire
> desktop tant qu'un champ readonly-safe n'est pas prévu).

## Dépendances

Aucune. Réutilise intégralement le socle MQTT UC11 (démon) + le point de publication UC13
(`publishRemoteCommand`/`buildMqttRequest`).

## Impact i18n (FR — traduction différée étape 10)

Nouvelles clés `__()` (chaînes littérales) dans `core/class/stellantis.class.php` :
- `Klaxonner`
- `Allumer les feux`
- `Klaxon déjà déclenché à l'instant ; patientez %d s`
- `Feux déjà déclenchés à l'instant ; patientez %d s`

Le message `otp_required` réutilise une clé existante
(`Pilotage à distance non activé : activez l'OTP dans la configuration du plugin`).
