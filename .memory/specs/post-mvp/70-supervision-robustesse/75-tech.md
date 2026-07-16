# Spec technique — 75 (Mode privacy du véhicule / « Plane Mode »)

> Réf. fonctionnelle : `75-mode-privacy.md`. Domaine supervision/robustesse. **100 % local, AUCUN appel
> réseau/MQTT neuf** : exploite le champ `privacy.state` déjà présent dans le `/status` récupéré au cron.
> Un seul fichier modifié : `core/class/stellantis.class.php`.

## Contexte : socle déjà en place (depuis le MVP)

Le mode privacy est **partiellement géré depuis le MVP** — UC75 ne fait que **compléter** :
- `refreshTelemetry()` lit `$status['privacy']['state']` (défaulté à `'None'` si absent), le met en cache
  `stellantis::privacy::<eqId>` (TTL 2 j) et **skippe `/lastPosition`** si `state ≠ 'None'` (pas de retry
  inutile sur la position — AC2 partiellement satisfait).
- Privacy **jamais traité comme erreur dure** : exclu de `connectionState()` (état global par compte) ;
  `health()` affiche déjà une ligne « Mode privacy actif — données de localisation restreintes ».

Ce qui **manque** (livrables UC75) : (1) une **info** exposée au dashboard/scénarios, (2) un **message
d'aide** actionnable, (3) une **réduction de la fréquence de polling** tant que privacy actif.

## Contrat API (confirmé, en production)

- Champ `privacy.state` du `/status` (endpoint `GET /user/vehicles/{id}/status`), enum
  **`None` / `Geolocation` / `Full`** (cf. `.memory/analyse/stellantis-data-model.md` § 2.6). `≠ None` ⇒
  position indisponible + données restreintes côté API, **indépendamment du plugin**.
- **Détection** = `strcasecmp(state, 'None') != 0`. Aucune heuristique floue sur « réponses vides » n'est
  nécessaire : l'API expose un champ explicite (l'« À confirmer » de la spec fonctionnelle est **clos**).
  Contrat déjà exploité en prod depuis le MVP (le skip `/lastPosition` en dépend) — pas de re-vérification.

## Architecture

Trois modifications localisées dans `core/class/stellantis.class.php` :

### 1. Info `privacy_mode` (binary, historisée) — AC1

- **`definitionsCommandes()`** : ajout
  `'privacy_mode' => array(__('Mode vie privée', __FILE__), 'binary', '', '', true)`.
  - `generic_type = ''` : pas de constante core « privacy » fiable (convention projet « on ne devine pas »).
  - **Historisée** (`true`) ⇒ déclencheur de scénario natif (précédent `at_home`/`service_due`/`tyre_alert`/
    `opening_alert` : l'event cmd déclenche même non historisé, mais on veut aussi la trace des bascules).
- **`parseStatus()`** : NOUVEAU 3ᵉ paramètre `string $_privacy = 'None'` (modèle de `$_position`), pour
  **éviter la double extraction** du chemin `privacy.state` (déjà lu dans `refreshTelemetry` pour gater
  `/lastPosition`). Mapping **INCONDITIONNEL** en fin de fonction :
  ```php
  $valeurs['privacy_mode'] = (strcasecmp($_privacy, 'None') != 0) ? 1 : 0;
  ```
  Émis à **chaque** `/status` (jamais absent) ⇒ **création paresseuse** au 1er poll (jamais dans
  `createCommands`, pattern UC21+) ET **retombe toujours à 0** à la sortie de privacy (AC1 robuste — un
  mapping conditionnel `isset` figerait la commande à sa dernière valeur sur un shape partiel).
  - ⚠️ `parseStatus` reste **pur** : le defaulting `'None'` vit dans `refreshTelemetry` (décision réseau),
    la valeur est **passée** ici. Le seul appelant est `refreshTelemetry` (ligne ~1072) ; le défaut de
    paramètre couvre tout appelant futur sans privacy (⇒ `privacy_mode = 0`).

### 2. Message d'aide edge-triggered — AC1

- Nouvelle méthode d'instance **best-effort `suivrePrivacy(string $_privacy): void`** (try/catch
  `\Throwable`, **ne lève jamais** — précédent `suivreSessionCharge`/`suivreGeofencing`), qui **centralise
  l'écriture du cache** `stellantis::privacy::<eqId>` (remplace le `cache::set` inline actuel, **même clé,
  même TTL 172800 s** — `health()` et la gate cron restent inchangés) ET détecte la transition :
  1. lit la valeur **précédente** du cache (`getValue('None')`) **avant** de l'écraser ;
  2. écrit la nouvelle valeur ;
  3. transition **None→actif** : `message::removeAll` puis `message::add` (tag **par véhicule**
     `privacy_<eqId>`, texte d'aide, cf. i18n) + `log::add` info ;
  4. transition **actif→None** : `message::removeAll` (efface l'aide) + `log::add` info ;
  5. état **stable** : rien (pas de martèlement du centre de messages).
- **Appelée dans `refreshTelemetry()`** juste après la lecture de `$privacy`, **avant** la garde
  `/lastPosition`. Remplace le `cache::set('stellantis::privacy::'...)` inline (ligne ~1053).
- **`preRemove()`** : ajout `message::removeAll('stellantis', 'privacy_' . $this->getId())` +
  `cache::delete('stellantis::privacy::' . $this->getId())`.
  - ⚠️ **Asymétrie assumée** vs les autres caches par véhicule (`CHARGE_NEXT_TIME_KEY`, throttles
    maintenance/alertes…) qui s'auto-purgent par TTL sans nettoyage explicite : un `message::add`
    **visible** dans le centre de messages est gênant s'il devient orphelin après suppression du
    véhicule (≠ un cache inerte). Justifié en commentaire.

### 3. Cadence de polling réduite en privacy — AC2

- Nouvelle constante **`CRON_PRIVACY_STEP = 30`** (~1 poll/30 min). **INVARIANT** : doit être un
  **multiple de `CRON_DEFAUT_STEP` (=5)** ET diviser 60 (⇒ espacement uniforme + phase cohérente avec la
  gate 5 min existante). Documenté en commentaire à côté de la constante.
- Dans **`cron()`**, branche cadence **PAR DÉFAUT uniquement** (pas la branche `autorefresh` custom) :
  ```php
  $privacyActif = (strcasecmp((string) cache::byKey('stellantis::privacy::' . $eqLogic->getId())->getValue('None'), 'None') != 0);
  $pas = $privacyActif ? self::CRON_PRIVACY_STEP : self::CRON_DEFAUT_STEP;
  if ($minuteActuelle % $pas !== $eqLogic->getId() % $pas) {
    continue;
  }
  ```
  - Réutilise la mécanique de **gate de phase modulo UC72** (`$minuteActuelle` déjà figée 1×/passe),
    offset **variable** `eqId % $pas`. Correct car 30 est multiple de 5 (la phase 5 min est un
    sous-ensemble compatible).
  - On **continue de sonder** (espacé) pour **détecter la SORTIE** de privacy : latence de reprise de la
    cadence normale **≤ 30 min** (documentée). Pas de chicken-egg : cache absent ⇒ `getValue('None')` ⇒
    cadence normale (jamais de blocage sur un véhicule neuf).
  - **Portée = tout le `/status`** (SOC/portes/12V compris), pas que la position : c'est l'intention AC2
    (économie quota/anti-ban globale). Note changelog : en privacy prolongé, **toute** la télémétrie du
    véhicule se rafraîchit à 30 min au lieu de 5.

## Server vs Client

100 % **serveur** (PHP). Aucun changement UI/JS : `privacy_mode` est rendu par le widget dashboard
générique du core ; le message d'aide passe par le centre de messages natif ; la ligne page Santé
existe déjà. Aucun fichier `desktop/*`, `panel.php`, ajax ou template touché.

## Validation

- **Serveur** : `parseStatus` reste pur/défensif (défaut de paramètre) ; `suivrePrivacy` best-effort
  (try/catch `\Throwable`) ⇒ ne peut jamais interrompre le refresh ; gate cron défensive (défaut `'None'`).
- Détection = `strcasecmp(..., 'None')` (insensible à la casse, comme les autres enums API du parseur).
- **Pas de client** à valider (aucune saisie utilisateur introduite).

## Server Actions / API

Aucune nouvelle action, aucun nouvel endpoint AJAX, aucun appel REST/MQTT. Signatures modifiées/ajoutées :
- `public static function parseStatus(array $_status, ?array $_position = null, string $_privacy = 'None'): array`
  (ajout du 3ᵉ paramètre + mapping inconditionnel `privacy_mode`).
- `private function suivrePrivacy(string $_privacy): void` (NOUVELLE, best-effort).
- `refreshTelemetry()` : appel `suivrePrivacy($privacy)` (remplace le `cache::set` inline) + passe
  `$privacy` à `parseStatus`.
- `cron()` : gate de phase à pas variable (privacy).
- `preRemove()` : nettoyage message + cache privacy.
- `definitionsCommandes()` : entrée `privacy_mode`.

## Dépendances

Aucune (pas de paquet, pas d'extension PHP, pas de changement `packages.json`).

## Impact i18n (FR — traduction déléguée étape 10)

Nouvelles chaînes UI françaises (à traduire en/de/es) :
- `Mode vie privée` — libellé de la commande info.
- Message d'aide (`sprintf`, `%s` = nom du véhicule) :
  « Le véhicule « %s » est en mode vie privée : le partage des données (Data / Géolocalisation) a été
  coupé côté véhicule. Réactivez le partage de données depuis l'écran du véhicule pour retrouver la
  position et la télémétrie. Ce n'est pas une panne du plugin. »

Les messages `log::add` restent des **littéraux FR non traduits** (convention projet).

## Limites assumées (documentées)

1. **Custom `autorefresh` non réduit** : la cadence réduite ne s'applique qu'à la cadence PAR DÉFAUT. Un
   utilisateur ayant forcé une cadence custom garde sa cadence même en privacy (opt-out intégral,
   **précédent UC72** — même doctrine, même formulation).
2. **Auto-wakeup (UC73) & CMD_PENDING (UC18) contournent la cadence réduite** : l'auto-wakeup est évalué
   à chaque passe (cadence propre) et le refresh post-ack force un `refreshTelemetry()` complet — tous deux
   **hors gate de phase** → ils peuvent rafraîchir plus souvent que 30 min en privacy. Cas de rafale
   résiduel **assumé**, même précédent que les « chemins résiduels documentés » UC72. La cadence réduite
   n'est donc pas une garantie absolue en présence d'auto-wakeup.
3. **Re-notification sur cas-limite** (accepté, pas corrigé) : expiration du cache privacy (TTL 2 j)
   pendant un privacy prolongé sans poll, ou `cache::flush()` admin ⇒ la valeur précédente redevient
   `'None'` ⇒ une entrée en privacy peut être re-notifiée alors que rien n'a changé côté véhicule
   (précédent UC74, tags orphelins — limitation structurelle, pas une régression).
