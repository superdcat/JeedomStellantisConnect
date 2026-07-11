# Spec technique — 24 (Suivi & statistiques de charge)

> Capitaliser l'historique des sessions de charge (énergie ajoutée **estimée**, durée, coût **estimé**)
> pour des dashboards Jeedom, au-delà de l'état instantané. **100 % PHP lecture/parsing** : aucun nouvel
> appel REST ni MQTT, aucune dépendance. Source = le `GET /user/vehicles/{id}/status` déjà récupéré au
> cron (data-model § 2.1) + 2 paramètres saisis en config véhicule.

## Divergences actées (spec fonctionnelle vs réalité) — À LIRE

1. **Enum `charging.status`** : la spec fonctionnelle parle de transition « `Started`→`Finished` ». La
   valeur `Started` **n'existe pas** dans l'API. Enum réel (data-model § 2.1, vérifié dumps +
   `psa_car_controller`) : `Disconnected` / `InProgress` / `Finished` / `Failure` / `Stopped`. La
   transition réelle suivie est **`InProgress` → statut terminal** (`Finished`/`Stopped`/`Disconnected`/
   `Failure`). *(Corriger aussi `24-suivi-charge-stats.md` en fin de cycle.)*
2. **« S'appuyer sur l'historisation de `battery_soc`/`charging_status` »** (spec § Détails techniques) :
   hypothèse **fausse** dans le code actuel — `charging_status` est défini `historiser=false`
   (`definitionsCommandes`). On retient donc l'autre voie proposée par la spec : **petit état en cache** +
   **3 commandes info dédiées historisées** (leur historique Jeedom = le journal des sessions, sans
   journal maison). Divergence assumée, cohérente avec la réalité du code.
3. **« À confirmer : capacité batterie fiable via l'API »** : **résolu**. L'API expose
   `energies[].extension.electric.battery.load.capacity` (Wh, réel sur dump e-C4 = 36384 Wh) mais
   millésime/forfait-dépendant → **config manuelle `battery_capacity` = source autoritaire**, l'API sert
   de **repli best-effort** uniquement.

## Architecture

Tout se joue dans `core/class/stellantis.class.php` + 2 champs éditables dans `desktop/php/stellantis.php`.
Aucun nouveau fichier, aucun point d'entrée externe → aucun risque autoload. **Pas** de migration
`install.php` (rien de repurposé), **pas** de dépendance.

### 1. `definitionsCommandes()` — 3 commandes info (création PARESSEUSE)
Ajouter (5e élément `true` = historiser, **obligatoire** sinon aucun point conservé → AC1 KO) :
```php
// UC24 : suivi des sessions de charge. Créées PARESSEUSEMENT (jamais dans createCommands, précédent
// UC21/23) — naissent à la 1re session close. Libellés « (est.) » = marquage estimation (AC2). VE/PHEV
// uniquement par construction (suivreSessionCharge gated sur charging_status, branche 'electric').
'charge_session_energy'   => array(__('Énergie dernière charge (est.)', __FILE__), 'numeric', '', 'kWh', true),
'charge_session_duration' => array(__('Durée dernière charge', __FILE__), 'numeric', '', 'min', true),
'charge_session_cost'     => array(__('Coût dernière charge (est.)', __FILE__), 'numeric', '', '€', true),
```
> ⚠️ Ces 3 clés **doivent** exister dans la table : `ensureCommand($logicalId)` lève `stellantisException`
> sur une clé inconnue. `createCommands()` reste **INCHANGÉ** (pas de création eager).

### 2. Constantes UC24 (près de `suivreSessionCharge`, ou bloc dédié)
```php
const CHARGE_SESSION_KEY = 'stellantis::charge_session::';     // + eqId (cache) : session en cours {start_ts,start_soc,cap_kwh}
const CHARGE_SESSION_TTL = 604800;                              // 7 j — RÉÉCRIT à chaque poll InProgress → jamais d'expiration en cours de charge
const CHARGE_STATUTS_TERMINAUX = array('Finished', 'Stopped', 'Disconnected', 'Failure'); // fins de session (≠ InProgress)
const CHARGE_LAST_STATUT_KEY = 'stellantis::charge_last_statut::'; // + eqId (cache) : dernier charging_status observé, pour ne loguer QUE sur transition
```
> Noms préfixés `CHARGE_*` (cohérence avec `CHARGE_NEXT_TIME_KEY`/`CHARGE_DEBOUNCE_KEY` d'UC14/22) — `SESSION_KEY`/
> `SESSION_TTL` seuls étaient ambigus (review croisée, corrigé).
>
> **`Stopped` traité comme terminal** : sans risque de fragmenter une charge différée (UC22), car on ne
> **démarre** une session que sur `InProgress` — un `Stopped` « en attente du créneau » survient **avant**
> toute session ⇒ no-op. Seul un `Stopped` transitoire *en cours* de charge produirait 2 sessions
> (= 2 vrais segments de charge séparés d'un poll ≥ 5 min) : comportement acceptable, documenté.
>
> **`CHARGE_LAST_STATUT_KEY`** (review croisée) : mémorise le dernier `charging_status` observé (toutes
> issues confondues), pour distinguer une vraie **transition** vers un terminal d'un état terminal
> **stable** qui persiste poll après poll (`Disconnected` au repos hors charge, `Finished` qui reste tant
> que le câble n'est pas débranché) — sans quoi le log « fin détectée sans session » spammerait à chaque
> poll d'un VE/PHEV inactif (~288×/j/véhicule). TTL = `CHARGE_SESSION_TTL` (même cadence que la session).

### 3. `refreshTelemetry()` — branchement (à la toute fin de la méthode)
Après le bloc `extraireHeureChargeDifferee` (fin de méthode), appeler :
```php
// UC24 : suivi des sessions de charge (VE/PHEV). Best-effort, robuste — ne lève jamais (try/catch interne),
// posé APRÈS le reste du refresh pour ne rien interrompre.
$this->suivreSessionCharge($valeurs, $status);
```

### 4. `suivreSessionCharge()` — ORCHESTRATION (cache + IO, PAS de calcul métier)
Machine à états mono-passe. Sépare l'orchestration du calcul pur (précédent UC18
`traiterRetourCommande`/`interpreterAck`). **Ne lève jamais** (try/catch enveloppant + log warning).
```php
private function suivreSessionCharge(array $_valeurs, array $_status): void {
  try {
    // Gating : uniquement si le /status porte un état de charge (⇒ VE/PHEV ; thermique pur = clé absente).
    if (!isset($_valeurs['charging_status'])) {
      return;
    }
    $statut = (string) $_valeurs['charging_status']; // déjà assaini (lettres) par parseStatus
    // Dernier statut observé : sert UNIQUEMENT à distinguer une vraie TRANSITION vers un terminal d'un état
    // terminal STABLE qui persiste poll après poll (Disconnected au repos, Finished non débranché…) — sans
    // quoi le log ci-dessous spammerait à chaque poll (~288×/j/véhicule). Réécrit AVANT toute branche/return :
    // tous les chemins de sortie laissent le cache à jour pour le prochain appel (review croisée).
    $cleDernierStatut = self::CHARGE_LAST_STATUT_KEY . $this->getId();
    $dernierStatut = (string) cache::byKey($cleDernierStatut)->getValue('');
    cache::set($cleDernierStatut, $statut, self::CHARGE_SESSION_TTL);
    $cleSession = self::CHARGE_SESSION_KEY . $this->getId();
    $brut = (string) cache::byKey($cleSession)->getValue('');
    $session = ($brut !== '') ? json_decode($brut, true) : null;
    if (!is_array($session)) {
      $session = null; // auto-guérison : JSON invalide / absent ⇒ pas de session
    }
    $soc = $this->socCourant($_valeurs); // ?float : /status courant, sinon dernière valeur connue (execCmd)

    // ── Début de session ────────────────────────────────────────────────
    if (strcasecmp($statut, 'InProgress') == 0) {
      if ($session === null) {
        if ($soc === null) {
          return; // début vu mais SOC inconnu : attendre un poll avec SOC (pas de start_soc fiable inventé)
        }
        $session = array(
          'start_ts'  => time(),
          'start_soc' => $soc,
          'cap_kwh'   => $this->capaciteBatterieKwh($_status), // snapshot de repli (config prioritaire, sinon extension API)
        );
      }
      // (Ré)écrit systématiquement → rafraîchit le TTL. start_ts/start_soc restent figés (première détection).
      cache::set($cleSession, json_encode($session), self::CHARGE_SESSION_TTL);
      return;
    }

    // ── Fin de session ──────────────────────────────────────────────────
    if (self::estStatutTerminal($statut)) {
      if ($session === null) {
        // Terminal sans session : charge trop courte entre 2 polls (cadence ≥ 5 min), ou cache::flush admin.
        // Logué UNIQUEMENT sur TRANSITION vers ce terminal (dernierStatut différent) — un terminal STABLE
        // qui persiste poll après poll ne reloggue pas (sinon spam permanent). Jamais de récap fabriqué
        // (convention UC18). Limite documentée (voir plus bas).
        if ($dernierStatut !== '' && strcasecmp($dernierStatut, $statut) != 0) {
          log::add('stellantis', 'info', 'Charge : fin détectée sans session enregistrée (charge courte ou cache vidé) pour l\'équipement #' . $this->getId() . ' — non comptabilisée');
        }
        return;
      }
      // Idempotence : CONSOMMER (purger) la session AVANT d'écrire les commandes. Si le statut reste
      // terminal au poll suivant → session absente → no-op (aucun doublon d'historique). Un échec d'écriture
      // rarissime coûte 1 récap perdu, préféré à un doublon silencieux.
      cache::delete($cleSession);
      $capKwh = $this->capaciteBatterieKwh($_status); // relu à la FIN (config fraîche prioritaire)…
      if ($capKwh === null && isset($session['cap_kwh']) && is_numeric($session['cap_kwh'])) {
        $capKwh = (float) $session['cap_kwh'];       // …repli sur le snapshot de début
      }
      $recap = self::calculerRecapSession($session, time(), $soc, $capKwh, $this->tarifCharge());
      foreach ($recap as $logicalId => $valeur) {
        $cmd = $this->ensureCommand($logicalId); // création paresseuse ici
        $this->checkAndUpdateCmd($cmd, $valeur);
      }
      return;
    }
    // Autre statut (ni InProgress ni terminal, ou charging_status momentanément absent traité plus haut) :
    // no-op — ne ferme JAMAIS une session (anti-fragmentation).
  } catch (\Throwable $e) {
    log::add('stellantis', 'warning', 'Charge : suivi de session en erreur pour l\'équipement #' . $this->getId() . ' : ' . $e->getMessage());
  }
}

// Détection terminale insensible à la casse (harmonisée avec le strcasecmp du début de session — convention
// du fichier pour les enums API, cf. privacy/precond). Remplace un in_array(..., true) strict (asymétrie
// corrigée en review croisée).
private static function estStatutTerminal(string $_statut): bool {
  foreach (self::CHARGE_STATUTS_TERMINAUX as $terminal) {
    if (strcasecmp($_statut, $terminal) == 0) {
      return true;
    }
  }
  return false;
}
```

### 5. `calculerRecapSession()` — CALCUL PUR (aucun IO, testable)
```php
/**
 * UC24 : calcule le récapitulatif d'une session close. PUR (aucun accès cache/cmd/config — tout est
 * passé en argument, y compris l'instant de fin) → testable. Durée toujours calculée ; énergie/coût
 * seulement si les données requises sont connues. ΔSOC clampé ≥ 0 (jamais d'énergie négative).
 * @return array logicalId => valeur (clés absentes = non calculables, contrat façon parseStatus)
 */
private static function calculerRecapSession(array $_session, int $_endTs, ?float $_endSoc, ?float $_capKwh, ?float $_tarif): array {
  $recap = array();
  $startTs = (isset($_session['start_ts']) && is_numeric($_session['start_ts'])) ? (int) $_session['start_ts'] : null;
  if ($startTs !== null) {
    // Durée bornée par la cadence de polling → ESTIMATION (documenté). max(0,…) contre une horloge qui recule.
    $recap['charge_session_duration'] = (int) round(max(0, $_endTs - $startTs) / 60);
  }
  $startSoc = (isset($_session['start_soc']) && is_numeric($_session['start_soc'])) ? (float) $_session['start_soc'] : null;
  if ($startSoc !== null && $_endSoc !== null && $_capKwh !== null && $_capKwh > 0) {
    $deltaSoc = max(0.0, $_endSoc - $startSoc);
    $kwh = round($deltaSoc / 100.0 * $_capKwh, 2);
    $recap['charge_session_energy'] = $kwh;
    if ($_tarif !== null && $_tarif >= 0) {
      $recap['charge_session_cost'] = round($kwh * $_tarif, 2);
    }
  }
  return $recap;
}
```

### 6. Helpers de lecture
```php
// SOC courant : /status frais prioritaire, sinon dernière valeur connue de la commande battery_soc
// (parseStatus est défensif : le SOC peut manquer au poll exact de la transition). ?float.
private function socCourant(array $_valeurs): ?float {
  if (isset($_valeurs['battery_soc']) && is_numeric($_valeurs['battery_soc'])) {
    return (float) $_valeurs['battery_soc'];
  }
  $cmd = $this->getCmd('info', 'battery_soc');
  if (is_object($cmd)) {
    $val = $cmd->execCmd();
    if (is_numeric($val)) {
      return (float) $val;
    }
  }
  return null;
}

// Capacité batterie en kWh : config `battery_capacity` (autoritaire) sinon repli API best-effort. ?float.
private function capaciteBatterieKwh(array $_status): ?float {
  $config = trim((string) $this->getConfiguration('battery_capacity', ''));
  if ($config != '' && is_numeric($config) && (float) $config > 0) {
    return (float) $config;
  }
  return self::extraireCapaciteBatterieKwh($_status);
}

// Repli API : energies[].extension.electric.battery.load.capacity (Wh → kWh). Via energiesDepuisStatus()
// (une seule source de vérité pour energies[] v4.15+/energy[], comme UC21/23). PUR. ?float.
private static function extraireCapaciteBatterieKwh(array $_status): ?float {
  foreach (self::energiesDepuisStatus($_status) as $energie) {
    if (!is_array($energie) || !isset($energie['type']) || strtolower((string) $energie['type']) != 'electric') {
      continue;
    }
    $capaciteWh = $energie['extension']['electric']['battery']['load']['capacity'] ?? null; // ?? tolère chemins absents (PHP7+)
    if (is_numeric($capaciteWh) && $capaciteWh > 0) {
      return (float) $capaciteWh / 1000.0;
    }
  }
  return null;
}

// Tarif électricité (€/kWh) depuis la config véhicule. Vide/non numérique ⇒ null (coût non estimé). ?float.
private function tarifCharge(): ?float {
  $config = trim((string) $this->getConfiguration('charge_tarif', ''));
  if ($config != '' && is_numeric($config) && (float) $config >= 0) {
    return (float) $config;
  }
  return null;
}
```

### 7. `health()` — découvrabilité de la capacité manquante
Sans `battery_capacity` **ni** repli API, l'énergie n'est **jamais** calculée (silence permanent vs AC1).
Le rendre observable. Placer **en tête** de la boucle `foreach (eqLogic::byType('stellantis', true) …)`
(juste après `$nom = $eqLogic->getName();`, **avant** le `continue` privacy) :
```php
// UC24 : capacité requise pour estimer l'énergie de charge (VE/PHEV). Absente ⇒ énergie jamais estimée →
// nudge actionnable. state=true : fonction estimative optionnelle, PAS une erreur dure (cf. ligne privacy).
$motor = trim((string) $eqLogic->getConfiguration('energy', ''));
if (($motor == 'Electric' || $motor == 'Hybrid') && trim((string) $eqLogic->getConfiguration('battery_capacity', '')) == '') {
  $lignes[] = array(
    'test'   => $nom,
    'result' => __('Capacité batterie non renseignée — énergie de charge non estimée', __FILE__),
    'advice' => __('Renseignez la capacité (kWh) dans la configuration du véhicule pour estimer l\'énergie de charge', __FILE__),
    'state'  => true,
  );
}
```

### 8. `desktop/php/stellantis.php` — 2 champs config ÉDITABLES
⚠️ **Obligatoire** : une clé de config véhicule absente du formulaire est **effacée au Sauvegarder**
(le form réécrit toute la config). Placer après le champ `autorefresh` (dans le même `<fieldset>`/col
gauche). Éditables (**pas** `readonly`, contrairement aux champs de synchro) :
```html
<div class="form-group">
  <label class="col-sm-4 control-label">{{Capacité batterie}}
    <sup><i class="fas fa-question-circle tooltips" title="{{Capacité utile de la batterie de traction en kWh — saisie manuelle, sert à estimer l'énergie ajoutée lors d'une charge (laisser vide si inconnue)}}"></i></sup>
  </label>
  <div class="col-sm-6">
    <input type="number" min="0" step="0.1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="battery_capacity">
  </div>
</div>
<div class="form-group">
  <label class="col-sm-4 control-label">{{Tarif électricité}}
    <sup><i class="fas fa-question-circle tooltips" title="{{Prix du kWh en euros — sert à estimer le coût d'une charge (informatif, laisser vide pour ne pas estimer le coût)}}"></i></sup>
  </label>
  <div class="col-sm-6">
    <input type="number" min="0" step="0.01" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="charge_tarif">
  </div>
</div>
```

## Server vs Client
100 % **serveur** (parsing/état PHP dans `refreshTelemetry`, calcul pur, `health`). Le seul ajout client
= 2 `<input>` natifs liés au modèle par `data-l1key/data-l2key` (mécanisme core, pas de JS custom).
Aucun widget, aucun AJAX, aucune action MQTT. Cohérent UC21/23 (télémétrie de lecture pure).

## Validation
- **Défensive (parsing/état)** : `isset`/`is_numeric` partout ; `json_decode` invalide ⇒ « pas de session »
  (auto-guérison) ; `suivreSessionCharge` ne lève jamais (try/catch + log) → respecte la robustesse cron.
- **Saisie utilisateur (config)** : `battery_capacity`/`charge_tarif` lus défensivement à l'usage
  (`is_numeric`, `> 0` / `>= 0`) ; vide = fonctionnalité partielle (durée seule / pas de coût), jamais
  d'erreur. `type="number"` côté form = garde-fou UI léger, la validation dure est côté lecture.
- **Anti-fragmentation / anti-doublon** : démarrage sur `InProgress` seul, fermeture sur terminal seul,
  purge du cache **avant** écriture des commandes.

## Server Actions / API
Aucune. Pas de nouvel endpoint REST, pas de commande MQTT, pas de handler AJAX. Nouveaux chemins de
lecture `/status` : `energies[].extension.electric.battery.load.capacity` (repli capacité) — data-model § 2.1.

## Dépendances
Aucune (ni PHP, ni pip).

## i18n (FR — traduction différée au sous-agent translator)
Nouvelles clés : `Énergie dernière charge (est.)`, `Durée dernière charge`, `Coût dernière charge (est.)`,
`Capacité batterie`, `Tarif électricité`, les 2 tooltips, la ligne health `Capacité batterie non
renseignée — énergie de charge non estimée` + son advice, et le message log (log **non** traduit par
convention). ⚠️ Toutes en chaînes **littérales** dans `__()` (jamais `__($var)` — piège UC07).

## Limites connues (documenter, non corrigeables sans journal maison / API push)
- **Sessions courtes non comptabilisées** : une charge démarrée ET finie entre 2 polls (cadence ≥ 5 min)
  n'a aucun `InProgress` observé → non détectée. Le log « non comptabilisée » ne se déclenche que sur la
  **transition** vers ce statut terminal (comparaison à `CHARGE_LAST_STATUT_KEY`) — un état terminal qui
  reste **stable** poll après poll (véhicule débranché au repos, charge terminée non débranchée…) ne
  reloggue **pas** à chaque poll (review croisée : évitait un spam ~288×/j/véhicule).
- **Perte sur `cache::flush` admin** : une session en cours effacée ⇒ la fin suivante loggue « non
  comptabilisée » (si transition détectée) et ne produit pas de récap (même chemin que ci-dessus).
- **Bornes = instants de poll** (`time()`), pas les instants réels début/fin → durée/énergie estimées
  (±cadence). Assumé (marquage « (est.) »).
- **Échantillonnage/compactage natif de l'historique Jeedom** : au-delà de `setIsHistorized`, Jeedom peut
  moyenner d'anciens points — hors contrôle du plugin.

## Critères d'acceptation → couverture
- **AC1** (une session produit un récap énergie+durée) : `suivreSessionCharge` ferme sur terminal et émet
  `charge_session_duration` (toujours) + `charge_session_energy` (si SOC début/fin + capacité connus).
  Découvrabilité capacité manquante via `health()`. ✔ *(limite « session courte » documentée)*
- **AC2** (estimations marquées) : libellés `charge_session_energy`/`charge_session_cost` portent
  « (est.) ». ✔
