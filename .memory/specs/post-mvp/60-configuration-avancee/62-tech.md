# Spec technique — 62 (Sauvegarde & restauration de la config d'authentification)

> Spec fonctionnelle : `62-sauvegarde-restauration-auth.md`. Plan validé le 2026-07-21 (challenge advisor
> intégré). **Décision de scope** : le durcissement du `pickle.loads()` de `resources/otp_helper.py`
> (défense en profondeur si la passphrase fuit) est **DIFFÉRÉ à une UC de durcissement dédiée** — hors
> périmètre UC62. La porte d'ingestion externe créée par la restauration reste protégée par l'**AEAD seul**
> (tag AES-256-GCM + passphrase) ; c'est acceptable car une fuite conjointe fichier+passphrase implique
> déjà la perte des « clés du royaume » (client_secret, refresh token, otp_device). Dette tracée en
> mémoire + `.memory/analyse/`.

## Architecture

100 % PHP, aucun appel réseau au cœur de l'export/import (seule la *reprise* post-restauration best-effort
appelle le réseau — CID/remote token — et est opt-in). Chiffrement authentifié via `ext-openssl`
(`aes-256-gcm`).

### Fichiers touchés
1. **`core/class/stellantis.class.php`** (classe `stellantis`) — orchestration + crypto fichier :
   - `public static exportAuthConfig(string $_passphrase): array` → `{ok:bool, filename?, content?, message}`
     (`content` = base64 du fichier JSON complet ; téléchargé côté navigateur).
   - `public static restoreAuthConfig(string $_fichierB64, string $_passphrase, bool $_renew = false): array`
     → `{ok:bool, message}`.
   - Helpers privés **purs** (pas d'IO Jeedom, testables/raisonnables isolément) :
     - `chiffrerPayloadAuth(array $_clair, string $_passphrase): string` (retourne le JSON fichier complet).
     - `dechiffrerFichierAuth(string $_fichierJson, string $_passphrase): array` (valide structure +
       schema_version + `plugin` **avant** déchiffrement ; `openssl_decrypt` avec tag → refus si `false`).
     - `opensslGcmDisponible(): bool` (garde de dépendance).
   - Helpers d'IO privés (lecture/écriture config par slot, cohérents avec les helpers existants) :
     - `collecterPayloadAuth(): array` (construit le clair depuis `slotsConfigures()`, `readOtpDevice()`,
       config OTP/CID/broker, + tokens best-effort via `stellantisApi`).
     - `appliquerPayloadAuth(array $_clair, bool $_renew): array` (écritures + reprise ; retourne un rapport).
   - Constantes :
     `const AUTH_EXPORT_SCHEMA_VERSION = 1;`
     `const AUTH_EXPORT_PBKDF2_ITER = 210000;`
     `const AUTH_EXPORT_TAILLE_MAX = 1048576;` (1 Mo : plafond du fichier restauré, **avant** tout decode)
     `const AUTH_PASSPHRASE_MIN = 12;`
2. **`core/class/stellantis.class.php`** (classe `stellantisApi`) — accès aux caches token (déchiffre/rechiffre,
   cloisonnés par slot via `cacheKeyForSlot`) :
   - `public static exportTokenCache(int $_slot = 1): ?array` (retourne `{access_token, refresh_token, exp}`
     déchiffré, ou `null`).
   - `public static importTokenCache(int $_slot, array $_token): void` (re-chiffre `utils::encrypt` + `cache::set(..., 0)`).
   - `public static exportRemoteTokenCache(): ?array` / `importRemoteTokenCache(array $_token): void` (idem, mono-compte slot 1).
3. **`core/ajax/stellantis.ajax.php`** — 2 branches admin-only :
   - `restoreAuth` : placée **AVANT** le garde global `isConfigured()` (au niveau de `extractCredentials`) —
     une install neuve à restaurer est **non configurée** ⇒ sinon inutilisable (fix advisor). Lit
     `init('file')`, `init('passphrase')`, `init('renew')`.
   - `exportAuth` : placée **APRÈS** le garde `isConfigured()` (n'a de sens que sur une instance configurée).
     Lit `init('passphrase')`.
4. **`plugin_info/configuration.txt`** (+ `cp` → `.php`) — nouveau `<fieldset>` « Sauvegarde & restauration »
   après le fieldset OTP, + handlers JS dans le bloc `<script>`.

## Server vs Client

- **Serveur** (PHP) : toute la crypto (dérivation PBKDF2, AES-256-GCM, tag), la validation stricte, le
  cycle decrypt→clair→re-encrypt, les écritures config/cache. Le serveur est la seule autorité de confiance.
- **Client** (JS) : UX seulement — saisie passphrase + confirmation, sélection fichier (lu via `FileReader`
  → base64), déclenchement du download (Blob + `<a download>`), confirmations `bootbox`. Aucune logique de
  sécurité côté client.

### Download / Upload — décision (écart assumé vs `$_FILES`)
Pas de `$_FILES`/multipart (aucun précédent dans le code ; le patron « download » d'UC61 sert au
téléchargement *vers le serveur*, pas *vers le navigateur*). Retenu :
- **Export** : `ajax::success({content:<b64 fichier>, filename})` ; le JS crée un `Blob` et un `<a download>`
  (fichier de quelques Ko). Reste sous le flux admin + CSRF de `ajax::init()`.
- **Restore** : le JS lit le fichier via `FileReader.readAsText` → POST standard `$.ajax` (data object,
  `dataType:'json'`) du contenu base64. ⇒ le token CSRF injecté par le core fonctionne nativement, **et le
  fichier n'atterrit jamais sur le disque serveur** (le critère « fichier temporaire supprimé » est satisfait
  par construction). ⚠️ À vérifier en implémentation : `init('file')` ne doit pas altérer `+`/`/`/`=` (jQuery
  URL-encode correctement un data object en `application/x-www-form-urlencoded` ; PHP décode) — sinon
  encoder en base64url côté JS.

## Format du fichier (contrat crypto)

```json
{
  "plugin": "stellantis",
  "schema_version": 1,
  "exported_at": "<ISO8601 UTC>",
  "kdf": { "algo": "pbkdf2", "hash": "sha256", "iter": 210000, "salt": "<b64 16o>" },
  "cipher": "aes-256-gcm",
  "iv": "<b64 12o>",
  "tag": "<b64 16o>",
  "data": "<b64 ciphertext>"
}
```
- Clé : `hash_pbkdf2('sha256', $passphrase, $salt, 210000, 32, true)` (256 bits).
- Chiffrement : `openssl_encrypt($clairJson, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16)`,
  `$iv = random_bytes(12)`, `$salt = random_bytes(16)`.
- Déchiffrement : `openssl_decrypt($cipherRaw, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag)` →
  retourne `false` si le tag GCM est invalide (mauvaise passphrase, fichier corrompu **ou forgé**)
  ⇒ **refus net, AUCUNE écriture**.

### Payload en clair (chiffré dans `data`)
```json
{
  "accounts": {
    "1": {"client_id":"…","client_secret":"…","brand":"…","country":"…","redirect_uri":"…"},
    "2": {"…"}, "3": {"…"}
  },
  "otp": {"device":"<blob base64(pickle) DÉCHIFFRÉ>", "sms_count": <int>, "sms_pending": "…"},
  "customer_id": "…",
  "broker": {"broker_host":"…", "socketport":"…"},
  "tokens": {
    "oauth": {"1": {"access_token":"…","refresh_token":"…","exp":<int>}, "…"},
    "remote": {"access_token":"…","refresh_token":"…","exp":<int>}
  }
}
```
- **Seuls les slots réellement configurés** (`slotsConfigures()`) sont exportés.
- **OTP / CID / broker / tokens.remote = slot 1 uniquement** (pilotage mono-compte, cohérent UC12/UC54).
- Blocs `otp`, `customer_id`, `broker`, `tokens` **omis si absents/vides** (export dégrade proprement même
  sans OTP activé — le cas « compte configuré + OTP activé » de l'AC1 est le nominal, pas l'exclusif).
- **Cohérence slot 1 (fix advisor)** : à la restauration, l'`otp_device` n'est jamais réécrit sans les
  identifiants slot 1 du même fichier (jamais de device orphelin incohérent avec les credentials).

## Cycle decrypt → fichier → re-encrypt (contrainte instance)

Le chiffrement at-rest utilise une clé **propre à l'instance** (`utils::encrypt`, cf. mémoire
`jeedom-encrypt-config-key`) : un blob chiffré n'est **pas** portable. Donc :
- **Export** : lit les valeurs en **clair**. Les clés de `$_encryptConfigKey` (`client_secret*`) sont
  déjà déchiffrées par `config::byKey`. `otp_device` : `readOtpDevice()` (decrypt manuel). Tokens :
  nouveaux accesseurs `stellantisApi::export*TokenCache()` (decrypt manuel).
- **Import** : ré-chiffre avec la clé de la **nouvelle** instance. `config::save` re-chiffre auto les clés
  `$_encryptConfigKey`. `otp_device` : `storeOtpDevice()` (encrypt manuel). Tokens :
  `stellantisApi::import*TokenCache()` (encrypt manuel + `cache::set`).

## Validation

### Client
- Passphrase ≥ `AUTH_PASSPHRASE_MIN` (12) **et** confirmation identique (sinon alerte, pas d'appel).
- Fichier sélectionné avant restauration.
- `bootbox.confirm` sur la restauration : « La configuration d'authentification actuelle sera **remplacée**.
  Continuer ? » (fix advisor : écrasement explicite).
- Case à cocher opt-in « Tenter de réactiver le pilotage maintenant (consomme 1 code du quota 6/24 h) »
  → `renew` (fix advisor : jamais de consommation de quota silencieuse).

### Serveur (dans l'ordre, AVANT toute écriture)
1. `opensslGcmDisponible()` faux ⇒ refus « Chiffrement AES-256-GCM indisponible sur ce serveur ».
2. Passphrase vide ou < 12 ⇒ refus.
3. `strlen($_fichierB64) > AUTH_EXPORT_TAILLE_MAX * 2` (marge base64) **puis** taille décodée
   `> AUTH_EXPORT_TAILLE_MAX` ⇒ refus, **avant** tout `openssl_decrypt` (anti-DoS mémoire).
4. `json_decode` du fichier + structure stricte : `plugin === 'stellantis'` (sanity-check pas cher **avant**
   déchiffrement) ; `schema_version` **connu** (`=== AUTH_EXPORT_SCHEMA_VERSION`) sinon refus explicite
   « Version de sauvegarde non prise en charge » (pas de crash) ; présence + décodabilité de `kdf.salt`,
   `iv`, `tag`, `data`.
5. Dérivation + `openssl_decrypt` avec tag ⇒ `false` ⇒ refus « Fichier invalide ou passphrase incorrecte »
   (message unique volontairement indistinct mauvaise-passphrase / fichier-forgé ; **jamais** de passphrase
   ni de ciphertext dans le message).
6. `json_decode` du clair + validation de structure (`accounts` non vide, types attendus).
7. Seulement alors : écritures.

### Atomicité / robustesse (fix advisor)
- **Toute** la validation + le déchiffrement se font **avant la moindre écriture** ⇒ pas d'état à moitié
  restauré sur entrée invalide (le mode d'échec réaliste).
- Les écritures (`config::save`, `storeOtpDevice`, `cache::set`) sont simples ; chacune est isolée dans son
  propre `try/catch \Throwable` et alimente un **rapport par clé/compte** (jamais un OK/KO global opaque).
- Pas de rollback transactionnel réel (non supporté par Jeedom) : la restauration est **idempotente et
  ré-exécutable** — c'est le vrai mécanisme de reprise sur échec partiel, documenté dans le message.

## Server Actions / API

### `stellantis::exportAuthConfig(string $_passphrase): array`
1. Garde openssl + passphrase (≥12).
2. `collecterPayloadAuth()` (slots configurés, OTP/CID/broker slot 1, tokens best-effort).
3. `chiffrerPayloadAuth($clair, $_passphrase)`.
4. `log::add('info', 'UC62 : sauvegarde générée (N comptes' . (otp? ', OTP inclus':'') . ')')` — jamais de secret.
5. Retour `{ok:true, filename:'stellantis-auth-<AAAAMMJJ-HHMMSS>.json', content:base64(fichier),
   message:'Sauvegarde générée. Conservez ce fichier ET sa passphrase en lieu sûr, jamais par le même canal.'}`.

### `stellantis::restoreAuthConfig(string $_fichierB64, string $_passphrase, bool $_renew): array`
1. `base64_decode` borné (taille) → `dechiffrerFichierAuth()` (toute la validation ci-dessus). Échec ⇒
   `{ok:false, message}` (aucune écriture, `otp_device` **jamais** désérialisé — l'invariant pickle tient :
   le blob n'est écrit qu'après succès du tag AEAD, et n'est de toute façon jamais passé au helper Python
   pendant la restauration ; sa désérialisation ultérieure part du cache config, source de confiance).
2. `appliquerPayloadAuth($clair, $_renew)` :
   - Par slot de `accounts` : `config::save` des 5 clés via `configKeyForSlot`.
   - OTP (slot 1) : `storeOtpDevice(device)`, `config::save(OTP_SMS_COUNT_KEY)`, `OTP_SMS_PENDING_KEY`.
   - `customer_id`, `broker_host`, `socketport` (slot 1) via `config::save` si présents.
   - Tokens best-effort : `stellantisApi::importTokenCache(slot,…)` / `importRemoteTokenCache(…)` (ignorés
     silencieusement si absents/périmés).
   - **Reprise best-effort** (chacune en try/catch, ne lève jamais) :
     `resolveCustomerId()` si CID manque ; si `$_renew` **et** `!hasRemoteToken()` **et** `hasOtpDevice()`
     ⇒ `renewRemoteToken()` (au pire 1 unité du quota 6/24 h — endossé par la spec, opt-in) ;
     `reconnecterDemonSiLance()`.
3. `log::add('info', 'UC62 : restauration OK (N comptes…)' )` ou `'warning', 'UC62 : restauration refusée (<raison NON sensible>)'`.
4. Retour `{ok:true, message:<synthèse>}` — synthèse listant : comptes restaurés, OTP restauré (oui/non),
   pilotage réactivé (oui / « utilisez Renouveler »), + « Lancez la découverte des véhicules pour recréer
   les équipements » + « Si la connexion reste en erreur, refaites l'échange OAuth (navigateur) ».

### AJAX (`core/ajax/stellantis.ajax.php`)
```php
// AVANT le garde isConfigured() (install neuve = non configurée) :
if (init('action') == 'restoreAuth') {
    ajax::success(stellantis::restoreAuthConfig((string) init('file'), (string) init('passphrase'), init('renew') == '1' || init('renew') === true));
}
// … garde isConfigured() global inchangé …
// APRÈS le garde (export = instance configurée) :
if (init('action') == 'exportAuth') {
    ajax::success(stellantis::exportAuthConfig((string) init('passphrase')));
}
```

## Impact i18n (chaînes FR — traduction différée à l'étape translator)

### `plugin_info/configuration.txt`
- Legend : `Sauvegarde & restauration de l'authentification`
- Avertissement : `Ce fichier contient vos identifiants et votre appareil OTP (les « clés » de votre compte). Chiffrez-le avec une passphrase forte, conservez-le en lieu sûr, et ne transmettez jamais le fichier et la passphrase par le même canal.`
- Labels : `Passphrase`, `Confirmer la passphrase`, `Fichier de sauvegarde`
- Boutons : `Sauvegarder la configuration d'authentification`, `Restaurer`
- Case opt-in : `Tenter de réactiver le pilotage à distance maintenant (consomme 1 code du quota journalier)`
- Help-blocks : `Réutilisable sur une nouvelle installation sans reconsommer de SMS d'activation.`,
  `La passphrase n'est jamais stockée : sans elle, le fichier est inutilisable.`
- Alertes JS : `Les deux passphrases sont différentes`, `Passphrase trop courte (12 caractères minimum)`,
  `Sélectionnez d'abord un fichier de sauvegarde`, `Sauvegarde en cours…`, `Restauration en cours…`
- Confirm JS : `La configuration d'authentification actuelle sera remplacée par le contenu du fichier. Continuer ?`

### `core/class/stellantis.class.php` (messages retour `__(...)`)
- `Chiffrement AES-256-GCM indisponible sur ce serveur (extension openssl requise).`
- `Choisissez une passphrase d'au moins %d caractères.`
- `Sauvegarde générée. Conservez ce fichier ET sa passphrase en lieu sûr, jamais par le même canal.`
- `Fichier de sauvegarde trop volumineux : restauration refusée.`
- `Fichier de sauvegarde invalide (format non reconnu).`
- `Version de sauvegarde non prise en charge (%d).`
- `Fichier invalide ou passphrase incorrecte.`
- `Restauration réussie : %d compte(s) restauré(s).` (+ fragments de synthèse : `Pilotage à distance
  restauré.`, `Utilisez « Renouveler le jeton distant » pour réactiver le pilotage.`, `Lancez la découverte
  des véhicules pour recréer les équipements.`, `Si la connexion reste en erreur, refaites l'échange
  d'autorisation (bouton OAuth).`)

## Dépendances

Aucune nouvelle dépendance paquet. `ext-openssl` (quasi toujours présente sur Jeedom Debian/Raspberry Pi)
requise pour `aes-256-gcm` ; **garde de disponibilité** au runtime (`opensslGcmDisponible()`) → refus
explicite propre si absente (pas de repli silencieux). Aucun impact `packages.json` / `info.json`.

## Hors périmètre (tracé)

- **Durcissement `pickle.loads()`** (`resources/otp_helper.py`) : UC de durcissement dédiée future
  (unpickler restreint à `find_class`), avec recette OTP live. Dette sécurité documentée
  (`.memory/analyse/`, mémoire). UC62 ne modifie PAS `otp_helper.py`.
- Sauvegarde des réglages personnels/non-auth (`home_*`, `map_tile_url`, seuils, cadences,
  `isVisiblePanel`, `syncEnabled`) : « export complet » distinct, futur.
- Équipements/historiques : couverts par le backup Jeedom natif + la découverte.
