# Spec technique — 34 (Geofencing & alertes de zone)

> **Domaine** : localisation/trajets · **Dépend de** : UC31 (position GPS) · **Type** : calcul 100 % local
> (haversine), aucun appel réseau neuf. Pattern best-effort au cron calqué sur UC24/33
> (`suivreSessionCharge`/`suivreTrajet`). **Périmètre retenu (validé 2026-07-12) : Option A** — **zone
> domicile UNIQUE, configurée en config plugin (partagée par tous les véhicules)**.

## Contexte & décision

`at_home` est **dérivé** de la position (`latitude`/`longitude`) **déjà** produite par `parseStatus()` au
cron (`/lastPosition`, récupérée seulement si `privacy.state == None`). **Aucun appel REST/MQTT neuf** →
**AC2 (indépendance) satisfait par construction**.

**Config = plugin, pas par véhicule** (décision validée) : « le domicile » est une notion du **foyer**, pas
du véhicule → une seule saisie, précédent établi du projet (`broker_host`/`customer_id`/`map_tile_url` sont
en config plugin). Les **commandes** `at_home`/`home_distance` restent **par véhicule** (chaque véhicule a
sa propre position → son propre `at_home` calculé depuis la zone domicile partagée).

## Architecture

Deux fichiers modifiés :

### 1. `core/class/stellantis.class.php`

**`definitionsCommandes()`** — 2 commandes info, **création paresseuse** (jamais dans `createCommands`,
précédent UC21/24/31/33 ; naissent au 1ᵉʳ cron avec zone domicile configurée **et** position dispo) :

| logicalId | nom FR | subType | generic_type | unité | historisé |
|---|---|---|---|---|---|
| `at_home` | Au domicile | binary | `PRESENCE` | — | **true** (AC2 : déclencheur de scénario) |
| `home_distance` | Distance au domicile | numeric | `''` | m | **true** |

> `generic_type='PRESENCE'` pour `at_home` : précédent `charging_plugged` (binaire de présence vérifié),
> meilleur widget natif. `home_distance` numérique historisée (graphe distance-domicile, comme `trip_distance`).

**Constantes** (près des constantes UC24/33) :
```
const HOME_RADIUS_DEFAUT = 150;   // m — rayon par défaut si config vide/invalide
const HOME_HYSTERESIS_M  = 50;    // m — marge de SORTIE (anti-clignotement au bord, cf. « À confirmer » spec)
```

**`suivreGeofencing(array $_valeurs): void`** — best-effort, **try/catch enveloppant, NE LÈVE JAMAIS**
(log warning), appelée en **fin** de `refreshTelemetry()` **après** `suivreTrajet()` (robustesse cron).
Lit la config **plugin** via `config::byKey('<clé>', 'stellantis')`. Logique :
1. `home_lat`/`home_lon` : si l'un **absent ou non numérique** → `return` (geofencing off, aucune commande
   créée). *(Seuls lat+lon sont bloquants.)*
2. `home_radius` : vide → `HOME_RADIUS_DEFAUT` ; présent mais `≤ 0`/non numérique → `HOME_RADIUS_DEFAUT`
   + `log debug` (config invalide, sinon `at_home` figé à 0 en silence — advisor #3).
3. Position courante : `latitude`/`longitude` de `$_valeurs` ; si l'un **absent/non numérique** → `return`
   (**gèle** `at_home` : privacy active ou pas de fix GPS ⇒ on ne fabrique pas un faux « parti »).
4. `distance = self::distanceHaversineM($home_lat, $home_lon, $lat, $lon)` (mètres).
5. **Lire l'état précédent AVANT d'écrire** (advisor #2) : `$cmd = $this->ensureCommand('at_home')`
   (création paresseuse ici) ; `$prec = $cmd->execCmd()` ; `$etaitAuDomicile = is_numeric($prec) ? (int)$prec : 0`
   (cast défensif : `''`/`null` sur cmd neuve ⇒ inconnu = 0, advisor #5).
6. **Hystérésis asymétrique** :
   - si `$etaitAuDomicile === 1` → `nouvel = (distance <= radius + HOME_HYSTERESIS_M) ? 1 : 0` (sortie « collante ») ;
   - sinon → `nouvel = (distance <= radius) ? 1 : 0` (entrée au rayon nominal).
7. Écrire : `$this->checkAndUpdateCmd($cmd, $nouvel)` ; puis `home_distance` :
   `$this->checkAndUpdateCmd($this->ensureCommand('home_distance'), (int) round(distance))`.

**`distanceHaversineM(float $_lat1, float $_lon1, float $_lat2, float $_lon2): float`** — **PUR/STATIQUE**
(aucun accès cache/cmd/config), testable. Haversine, rayon Terre `6371000` m, `deg2rad`, retourne des mètres.

**Appel** dans `refreshTelemetry()` : `$this->suivreGeofencing($valeurs);` après `$this->suivreTrajet(...)`.

### 2. `plugin_info/configuration.txt` (+ `cp` vers `.php`)

⚠️ **Contrainte CLAUDE.md** : `configuration.php` non éditable directement → éditer **`configuration.txt`**
puis `cp plugin_info/configuration.txt plugin_info/configuration.php` (les deux doivent rester identiques).

Nouveau `<fieldset>` (après le 1ᵉʳ, « Connexion API ») :
```html
<fieldset>
  <legend><i class="fas fa-map-marker-alt"></i> {{Zone domicile (geofencing)}}</legend>
  <div class="alert alert-info">{{Coordonnées de votre domicile : chaque véhicule exposera une info « Au domicile » (1/0) utilisable dans vos scénarios. Laissez vide pour désactiver.}}</div>
  <!-- 3 form-groups : home_lat, home_lon (number step any), home_radius (number, placeholder 150) -->
  <input class="configKey form-control" data-l1key="home_lat"/>
  <input class="configKey form-control" data-l1key="home_lon"/>
  <input class="configKey form-control" data-l1key="home_radius" placeholder="150"/>
</fieldset>
```
Clés config plugin : `home_lat`, `home_lon`, `home_radius` (non chiffrées, auto load/save core via `configKey`).

## Server vs Client
100 % serveur (calcul au cron PHP). Aucun JS, aucun AJAX (Option A = champs scalaires `configKey` natifs).
Restitution via commande info standard → l'utilisateur bâtit ses automatisations dans les **scénarios
Jeedom** natifs (déclencheur sur changement de `at_home`).

## Validation
- **Défensif** : config `is_numeric` (lat/lon bloquants, radius avec défaut+clamp) ; position absente ⇒
  freeze (privacy/no-fix safe) ; état précédent casté défensivement.
- **Robustesse cron** : `suivreGeofencing` try/catch enveloppant → n'interrompt jamais la boucle ni le refresh.
- **Hystérésis** (répond au « À confirmer » de la spec) : seuil d'entrée `radius`, seuil de sortie
  `radius + HOME_HYSTERESIS_M` → pas de clignotement au bord malgré un GPS variable. *(Le rejet d'un point
  GPS aberrant isolé via `gps_signal` = amélioration future, hors périmètre AC.)*
- **Confidentialité** : **ne jamais loguer** les coordonnées domicile ni la position véhicule (seulement
  l'`getId()` de l'eqLogic si besoin, comme UC24/33).

## Server Actions / API
Aucune. Aucun appel REST/MQTT neuf. Aucune commande action (uniquement 2 commandes **info** au cron).

## Dépendances
Aucune. `packages.json` inchangé.

## i18n (FR source — traduction différée au sous-agent `translator`, étape 10)
- `core/class/stellantis.class.php` (`definitionsCommandes`) : `Au domicile`, `Distance au domicile`.
- `plugin_info/configuration.txt` (`{{...}}`) : `Zone domicile (geofencing)`, l'alerte d'intro, et les
  libellés/tooltips des 3 champs (ex. `Latitude du domicile`, `Longitude du domicile`, `Rayon (m)` + tooltips).
Clés i18n : `plugins/stellantis/core/class/stellantis.class.php` et
`plugins/stellantis/plugin_info/configuration.php`. **Ne pas** toucher aux `core/i18n/*.json` pendant le dev.

## Tâches de clôture (hygiène)
- Cocher les AC et passer le statut de `34-geofencing-alertes.md` de « à spécifier » à « implémenté » —
  fait par l'orchestrateur en fin de cycle.
