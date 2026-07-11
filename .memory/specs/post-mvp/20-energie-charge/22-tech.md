# Spec technique — 22 (Programmation de la charge)

> Spec fonctionnelle : `22-programmation-charge.md`. Feature **1 commande action paramétrée** (la
> première du plugin). Réutilise intégralement le canal MQTT UC14 (`/VehCharge`, `type:"delayed"`),
> aucun nouvel endpoint réseau, aucune dépendance. **Un seul fichier modifié** :
> `core/class/stellantis.class.php`.

## Contrat API (vérifié vs code de référence)

Source de vérité : `flobz/psa_car_controller` `RemoteClient.py` (master), lu le 2026-07-11.

- **Programmer l'heure = `change_charge_hour(vin, hour, minute)`** → `veh_charge_request(vin, hour,
  minute, DELAYED_CHARGE)` publie sur le service **`/VehCharge`** :
  ```json
  { "program": {"hour": H, "minute": M}, "type": "delayed" }
  ```
  → **Identique au payload UC14 `charge_stop`** (constantes `CHARGE_SERVICE`, `CHARGE_TYPE_DELAYED`
  déjà présentes). Seule différence : l'heure vient de l'**utilisateur** (reprogrammation délibérée),
  pas du cache (préservation de `charge_stop`).
- **Seuil % / target SoC = NON supporté par l'API consommateur MQTT** (décision, cf. § dédié). Aucun
  pourcentage n'apparaît dans **aucun** payload de charge de `RemoteClient` → l'AC « et/ou seuil % »
  est réalisée **uniquement** sur la branche « heure ». `charge_set_threshold` **abandonné**.
- **Lecture de la programmation (AC2) = déjà couverte par UC21** : l'info `charge_next_time` (HH:MM,
  dérivée de `charging.next_delayed_time` au cron) existe. Rien à créer côté lecture. La boucle **ack
  MQTT → refresh REST au prochain cron → `parseStatus` → `charge_next_time`** referme l'AC2.

## Architecture

**Un seul fichier : `core/class/stellantis.class.php`.** Aucun AJAX / JS / config / démon /
`packages.json`. Première commande action **paramétrée** (UC13-17 = boutons `other` sans paramètre).

| Élément | Modification |
|---|---|
| `definitionsActions()` | +1 entrée `'charge_set_time' => array(__('Programmer l'heure de charge (HHMM)', __FILE__), 'message', '')`. subType **`message`** = widget natif champ texte (l'utilisateur saisit l'heure). |
| `createCommands()` | `$this->ensureActionCommand('charge_set_time');` **dans le bloc `Electric`/`Hybrid`** (après `charge_start`/`charge_stop` — jamais sur thermique pur). |
| `execute()` (switch) | `case 'charge_set_time'` → lit la valeur défensivement `$_options['message'] ?? $_options['title'] ?? ''`, appelle `$eqLogic->chargeSetTime((string) $val)`. |
| `parserHeureSaisie()` | **Nouveau helper pur statique** (près de `parseHeureIso`) — parse la saisie `HHMM` → `[heure, minute]`, lève `invalid_input` si invalide. |
| `chargeSetTime()` | **Nouvelle méthode publique** — gabarit `chargeControl`, publie une charge `delayed` à l'heure saisie. |
| `stellantisException` (docblock taxonomie) | Ajouter le type **`invalid_input`** à la liste documentée (produit par le code, comme `privacy` ; jamais par `typeFromResponse`). |

### `parserHeureSaisie(string $_saisie): array` (helper PUR)

Format Jeedom **`HHMM` sans séparateur** (choix utilisateur) : `2030` = 20:30, `730` = 07:30,
`0030` = 00:30. Tolère par robustesse les séparateurs `:`/`h`/`H` et espaces (copier-coller), mais le
format canonique documenté reste `HHMM`.

```php
private static function parserHeureSaisie(string $_saisie): array {
  $saisie = trim($_saisie);
  // DEUX branches distinctes (⚠️ ne PAS stripper le séparateur puis lire une longueur 3/4 générique : une
  // saisie « séparateur + heure 2 chiffres + minute 1 chiffre » comme "20h5" serait strippée en "205",
  // longueur 3, lue à tort HMM = 02:05 — valeur fausse acceptée en silence).
  if (preg_match('/[\s:hH]/', $saisie)) {
    // AVEC séparateur (ex. "20h30", "20:30", "20 30") : minute EXIGÉE sur 2 chiffres (lève l'ambiguïté ;
    // "20h5" est rejeté, jamais deviné). '+' tolère plusieurs séparateurs consécutifs sans risque (la
    // classe [\s:hH] ne peut avaler un chiffre → aucun glissement heure↔minute possible).
    if (!preg_match('/^(\d{1,2})[\s:hH]+(\d{2})$/', $saisie, $m)) {
      throw new stellantisException(__('Format d\'heure invalide (attendu HHMM, ex. 2030)', __FILE__), 0, 'invalid_input');
    }
    $heure = (int) $m[1];
    $minute = (int) $m[2];
  } else {
    // Format canonique Jeedom HHMM SANS séparateur : 3 chiffres = HMM, 4 = HHMM. Rejet net (invalid_input)
    // si non exploitable — JAMAIS de clamp d'une saisie utilisateur (cohérent avec le contrat parseStatus).
    if (!ctype_digit($saisie) || strlen($saisie) < 3 || strlen($saisie) > 4) {
      throw new stellantisException(__('Format d\'heure invalide (attendu HHMM, ex. 2030)', __FILE__), 0, 'invalid_input');
    }
    $minute = (int) substr($saisie, -2);
    $heure = (int) substr($saisie, 0, strlen($saisie) - 2);
  }
  if ($heure > 23 || $minute > 59) {
    throw new stellantisException(__('Heure hors plage (00h00 à 23h59)', __FILE__), 0, 'invalid_input');
  }
  return array($heure, $minute);
}
```

- Sans séparateur, longueur < 3 (ex. `30`) = ambigu → rejeté (il faut au moins `HMM`).
- Avec séparateur, minute obligatoirement sur 2 chiffres (`20h5` rejeté, `20h05` = 20:05).
- `2400`/`1260` → hors plage → rejeté.
- Pas de séparateur `.` accepté (ambigu avec une saisie décimale/pourcentage).

### `chargeSetTime(string $_heure): void` (gabarit UC14, guardrails réutilisés)

Ordre des gardes **aligné sur les méthodes sœurs** (garde métier d'abord) :

1. **Garde motorisation** `Electric`/`Hybrid` (sinon `stellantisException` `not_configured` — mêmes
   message et logique que `chargeControl`). Aucun appel réseau.
2. **Parse + validation** via `parserHeureSaisie($_heure)` → `[heure, minute]` (lève `invalid_input`
   avant tout effet de bord réseau).
3. **Debounce per-véhicule** — clé **PARTAGÉE** `CHARGE_DEBOUNCE_KEY . getId()` (mutualisée avec
   `charge_start`/`charge_stop` : les **3** commandes publient sur le **même service `/VehCharge`** →
   anti-boucle commun, cohérent avec la convention « une clé par service MQTT » ; message de refus
   **réutilisé** « Commande de charge déjà envoyée à l'instant… »).
4. **Pré-requis OTP** (`stellantisApi::hasRemoteToken()` sinon `alerterOtpRequired()` + `otp_required`).
5. **Pose du debounce AVANT réseau** (`cache::set(CHARGE_DEBOUNCE_KEY.getId(), time(), CHARGE_DEBOUNCE)`).
6. **Publication** :
   ```php
   $correlationId = $this->publishRemoteCommand(self::CHARGE_SERVICE, array(
     'program' => array('hour' => $heure, 'minute' => $minute),
     'type' => self::CHARGE_TYPE_DELAYED,
   ));
   ```
7. **Mapping `CMD_CORR_KEY`** (commande **stateful** : `cache::set(CMD_CORR_KEY.$correlationId, getId(),
   CMD_CORR_TTL)` → l'ack déclenche un refresh REST au prochain cron → `charge_next_time` reflète la
   nouvelle programmation). Log info « Programmation de charge (HH:MM) demandée pour l'équipement #… ».

> **Pas de refresh-avant-envoi** (contrairement à `charge_stop`) : l'utilisateur **impose** une heure,
> il n'y a aucune programmation à préserver.
>
> ⚠️ **Effet de bord documenté** : `type:"delayed"` = « repasser en charge différée » (cf. commentaire
> `CHARGE_TYPE_DELAYED`, l.2078). Reprogrammer **interrompt une charge immédiate en cours** au profit du
> différé (même mécanisme que `charge_stop`). Documenté dans le nom de la commande, le docblock et le
> log ; **pas de confirmation native** (`actionConfirm`) — action de routine à faible enjeu, décision
> utilisateur 2026-07-11 (≠ `unlock` qui est un risque sécurité).

`execute()` (switch `stellantisCmd`) :
```php
case 'charge_set_time':
  // UC22 : programme l'heure de charge différée (HHMM). Valeur saisie via le widget action 'message'
  // (title en repli défensif ; is_string garde contre un $_options non-scalaire d'un appel scénario/API).
  $heure = (isset($_options['message']) && is_string($_options['message']) && $_options['message'] !== '')
    ? $_options['message']
    : ((isset($_options['title']) && is_string($_options['title'])) ? $_options['title'] : '');
  $eqLogic->chargeSetTime($heure);
  break;
```

## Server vs Client

**100 % serveur (PHP).** Widget `message` natif du core (aucun HTML/JS custom → pas de duplication
dashboard/mobile, pas de CSP à gérer). La saisie transite par `execute($_options)`. Le serveur est la
seule autorité de validation (une saisie invalide échoue proprement, sans effet réseau).

## Validation

- **Serveur** : `parserHeureSaisie` (helper pur, testable) rejette tout format/plage invalide via
  `invalid_input` **avant** debounce/OTP/réseau. Défensif pour un appel scénario/apikey (valeur vide →
  rejet net). `chargeSetTime` re-valide (méthode publique).
- **Client** : aucun code custom. Le nom de la commande porte le format attendu `(HHMM)` pour compenser
  l'absence de contrainte de saisie du widget natif.

## Server Actions / API

- `stellantisCmd::execute()` : nouveau `case 'charge_set_time'`.
- `stellantis::chargeSetTime(string $_heure): void` (publique).
- `stellantis::parserHeureSaisie(string): array` (privée statique pure).
- Réutilise **inchangés** : `publishRemoteCommand`/`buildMqttRequest`, constantes `CHARGE_SERVICE`,
  `CHARGE_TYPE_DELAYED`, `CHARGE_DEBOUNCE`/`CHARGE_DEBOUNCE_KEY`, `CMD_CORR_KEY`/`CMD_CORR_TTL`. **UC14
  `chargeControl()` NON modifié.**

## Dépendances

Aucune (pas de `packages.json`, pas d'extension PHP, pas de paquet Python).

## Décision — seuil % (target SoC) hors périmètre (2026-07-11)

La spec fonctionnelle liste `charge_set_threshold` (%) comme « éventuel » et le seuil % en « À
confirmer ». **Résolu : NON réalisable via le contrat consommateur MQTT.** Vérifié verbatim dans
`RemoteClient.py` (master) : aucun payload de charge (`charge_now`, `veh_charge_request`,
`change_charge_hour`) ne contient de pourcentage / SoC cible / charge-limit. Le `?percentage=` de
l'endpoint Flask **local** de `psa_car_controller` et l'entité `charge_limit` de l'intégration Home
Assistant sont des fonctions **logicielles locales** (arrêt auto côté logiciel qui surveille le SOC),
**pas** une commande envoyée au véhicule. UC22 se limite donc à la **programmation de l'heure** —
AC1 (fixer une heure de fin) et AC2 (lisibilité via `charge_next_time`) satisfaits.

## i18n (FR uniquement — traduction déléguée au translator, étape 10)

Nouvelles clés `__()` (chaînes **littérales**) :
- `Programmer l'heure de charge (HHMM)` (nom de la commande) ;
- `Format d'heure invalide (attendu HHMM, ex. 2030)` (parsing) ;
- `Heure hors plage (00h00 à 23h59)` (parsing).

Chaînes **réutilisées** (déjà traduites, ne pas dupliquer) : `Commande de charge indisponible : véhicule
non rechargeable`, `Commande de charge déjà envoyée à l'instant ; patientez %d s`, `Pilotage à distance
non activé : activez l'OTP dans la configuration du plugin`. Ne PAS toucher `core/i18n/*.json` pendant
l'implémentation.
