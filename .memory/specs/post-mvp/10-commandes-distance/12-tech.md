# Spec technique — 12 (Activation OTP & remote token)

> Basée sur la spec fonctionnelle `12-otp-remote-token.md` et le contrat confirmé contre le code de
> référence `flobz/psa_car_controller` (module `psa/otp`, classe `RemoteClient`), vérifié le 2026-07-09.

## Contrat API (source de vérité : code de référence)

Tous les appels REST portent le **Bearer OAuth2** courant (`stellantisApi::getToken()`) +
`x-introspect-realm: {realm}` + `accept: application/hal+json` + `User-Agent: okhttp/4.8.0`.

Base **remote** = `https://api.groupe-psa.com/applications/cvs/v4/mobile` (≠ base REST `connectedcar/v4`).

| Étape | Requête |
|---|---|
| Déclencher SMS | `POST {remoteBase}/smsCode?client_id={id}` — corps vide → SMS au n° du compte |
| Remote token initial | `POST {remoteBase}/token?client_id={id}`, JSON `{"grant_type":"password","password":{code_OTP}}` → `{access_token, refresh_token}` (TTL ~**890 s**) |
| Refresh remote token | `POST {remoteBase}/token?client_id={id}`, JSON `{"grant_type":"refresh_token","refresh_token":{r}}` → `{access_token, refresh_token}` |
| Provisionnement device + code roulant | serveur `https://otp.mpsa.com/iwws/MAC` (RSA-OAEP/AES/SHA256, challenge-réponse XML) → **cryptographique, Python** |

**Écarts signalés vs analyse interne (le code de référence fait foi) :**
1. Endpoint remote token = `applications/cvs/v4/mobile/token` (grant `password`/`refresh_token`), **pas**
   `connectedcar/v4/virtualkey/remoteaccess/token` (annoncé dans `stellantis-psacc-vs-natif.md` §6).
2. Mot de passe MQTT du démon = **remote token OTP** (`RemoteClient.username_pw_set("IMA_OAUTH_ACCESS_TOKEN",
   remote_access_token)`), **pas** le token OAuth2 REST posé par UC11. **Décision : basculer maintenant.**

**Quotas durs :** `get_otp_code()` fait 2 appels réseau à otp.mpsa.com → **6 codes / 24 h**. L'activation
SMS → **20 / compte à vie** (blocage définitif possible). Jamais de retry auto ; à l'expiration : **alerter**.

## Architecture

### Crypto OTP → module Python vendorisé + helper one-shot
- `resources/otp_vendor/{__init__,load,oaep,otp,tokenizer}.py` : **copie verbatim** du module `psa/otp` de
  `psa_car_controller` (GPL-3.0). Attribution dans `resources/THIRD_PARTY_NOTICES.md`. Version **figée**
  (pas de sync auto). API utilisée : `new_otp_session(sms, pin)` (provisionne, 2 POST otp.mpsa.com,
  renvoie l'objet `Otp`) ; `Otp.get_otp_code()` (code roulant, 2 appels réseau + hash local).
- `resources/otp_helper.py` : CLI mince. **Protocole stdin↔stdout JSON** (jamais argv : SMS/PIN/device
  sensibles → fuite via `ps`). Ne loggue jamais la requête. Actions :
  - `activate` : entrée `{action:"activate", sms, pin}` → `new_otp_session` puis `get_otp_code` →
    sérialise l'objet (`base64(pickle)`) → sortie `{status:"ok", device_secret, otp_code}`.
  - `code` : entrée `{action:"code", device_secret}` → `pickle.loads` → `get_otp_code` →
    `{status:"ok", otp_code}`.
  - Sortie d'échec toujours code 0 + `{status}` connu (`bad_input`, `otp_error`, `error`) ; traceback
    éventuel sur stderr uniquement (jamais sur stdout, corromprait le JSON). Ajoute `otp_vendor` à
    `sys.path` de façon stable (chemin du pickle reproductible entre process).

### PHP `stellantisApi` (REST, via `httpRequest`)
- Constantes : `REMOTE_API_BASE`, `REMOTE_TOKEN_CACHE_KEY='stellantis::remote_token'`,
  `REMOTE_TOKEN_TTL=890`, `REMOTE_TOKEN_MARGE=120`, `OTP_CODE_QUOTA_KEY`, `OTP_CODE_QUOTA_MAX=6`,
  `OTP_CODE_QUOTA_FENETRE=86400`.
- `requestSmsOtp():void` — POST smsCode (Bearer). Erreurs mappées `stellantisException`.
- `requestRemoteToken(string $otpCode):void` — POST token grant password → stocke cache chiffré
  `{access,refresh,exp}` (réutilise `utils::encrypt`, structure calquée sur le token OAuth2).
- `refreshRemoteToken():void` — POST token grant refresh_token (échec → `stellantisException`
  `otp_required`). **N'auto-régénère pas** de code OTP (respect quota).
- `getRemoteToken():string` (refresh proactif si < marge), `getRemoteTokenInfo():array`,
  `hasRemoteToken():bool`, `purgeRemoteTokenCache():void`.
- Quota code OTP : `consommerQuotaCodeOtp()` (gabarit identique à `consommerQuotaRefresh`, fenêtre 24 h).

### PHP `stellantis` (orchestration + démon)
- Constantes : `OTP_DEVICE_KEY='otp_device'` (config, chiffré `utils::encrypt`, survit à `cache::flush`),
  `OTP_SMS_COUNT_KEY='otp_sms_count'` (config, compteur 0..20 **lifetime**, jamais remis à 0 auto),
  `OTP_SMS_MAX=20`, `OTP_SMS_PENDING_KEY='otp_sms_pending'` (config, flag « SMS envoyé, activation en
  attente »), `OTP_ALERT_KEY='stellantis::otp_alert'` (cache, cooldown anti-spam `message::add`).
- `requestOtpSms():array` — garde `OTP_SMS_MAX` (refus net si atteint, message clair) → incrémente le
  compteur **avant** l'appel (le SMS consomme le quota même si le process meurt ensuite) → pose le flag
  pending → `stellantisApi::requestSmsOtp()`. Retour structuré `{ok, message}`.
- `activateOtp(string $sms, string $pin):array` — garde quota code OTP → `runOtpHelper(activate)` →
  stocke `device_secret` chiffré en config → `stellantisApi::requestRemoteToken(otp_code)` → efface le
  flag pending → `resolveCustomerId()` (best-effort) → `pushRemoteTokenToDaemon()` → `{ok, message}`.
- `generateOtpCode():?string` — interne : garde quota → `runOtpHelper(code, device_secret)`.
- `renewRemoteToken():array` — renouvellement **sans SMS** depuis le device stocké (génère un code OTP →
  `requestRemoteToken`). Exposé en UI/AJAX (bouton « Renouveler »).
- Refresh proactif porté par `syncDaemonToken()` (cron, si démon lancé) : `stellantisApi::getRemoteToken()`
  (refresh via refresh_token) ; échec `otp_required` → `alerterOtpRequired()` (throttlé). Refresh réactif
  (rejet 400 du broker) porté par `handleDaemonMessage()` (`refreshRemoteToken()`). **Aucune régénération
  OTP auto** (respect spec).
- `runOtpHelper(array $request):array` — **`proc_open`** (stdin = JSON, stdout lu, stderr ignoré),
  interpréteur `system::getCmdPython3('stellantis')`, script `resources/otp_helper.py`. Parse le JSON de
  sortie ; mapping `status`→message i18n comme `parseApkViaPython`. Jamais de secret loggué.
- `resolveCustomerId():void` — best-effort : `GET connectedcar/v4/user`, cherche un champ
  `customer_id`/`id` de forme `AP-ACNT…`/`OV-ACNT…` → `config::save('customer_id')`. Échec → non bloquant
  (le champ config manuel reste le repli ; abonnement démon différé, inchangé vs UC11). Ne loggue jamais
  le corps `/user` (PII).
- **Bascule MQTT (décision #2)** : `pushDaemonConnect()`/`syncDaemonToken()`/`handleDaemonMessage()`
  utilisent désormais le **remote token** comme mot de passe MQTT (au lieu de `stellantisApi::getToken()`).
  `reconnecterDemonSiLance()` : après activation/renouvellement, (re)connecte le démon via
  `pushDaemonConnect()` s'il tourne. Sans remote token → pas de connexion MQTT (log « activation OTP
  requise »), le démon reste lançable.
  `DAEMON_TOKEN_MARKER` marque désormais le remote token.
- Purge : `preConfig_client_id`/`preConfig_brand` étendus pour purger aussi `remote_token` +
  `device_secret` (compte/marque changé → device invalide). Le compteur SMS lifetime **n'est pas** remis
  à 0 (sécurité). `stellantis_remove()` purge `remote_token` + `device_secret`.
- Santé/`deamon_info` : ligne « Pilotage à distance (OTP) » — activé / non activé / expiré (otp_required).

### AJAX (`core/ajax/stellantis.ajax.php`)
- `requestOtpSms` → `stellantis::requestOtpSms()`.
- `activateOtp` (params `sms`, `pin`) → `stellantis::activateOtp()`.
- Admin-only, après le garde `isConfigured()` (via `stellantis::` — respect autoload).

### UI (`plugin_info/configuration.txt` → copie vers `.php`)
Nouveau fieldset « Pilotage à distance (activation OTP) » : avertissement quotas, bouton *Envoyer le SMS
d'activation*, champ *Code reçu par SMS*, champ *Code PIN de l'application* (`type=password`), bouton
*Activer le pilotage à distance*, label d'état. JS calqué sur l'existant (`$('body').off().on()`,
`showAlert`, `bootbox.confirm` pour l'avertissement quotas). `customer_id` non exposé (programmatique).

## Server vs Client
Tout côté **serveur** (PHP + helper Python one-shot). Le JS de la page config ne fait que déclencher les
AJAX et afficher les retours. Aucune logique métier ni secret côté client. Le démon reste un **transport
MQTT bête** (pas de logique OTP dedans) — l'OTP est du REST + crypto one-shot, pas du MQTT.

## Validation
- **Client (JS)** : champs SMS/PIN non vides avant `activateOtp` ; confirmation quotas avant SMS.
- **Serveur** : garde quotas (SMS lifetime 20, code 6/24 h) **avant** tout appel réseau ; refus net sans
  retry. PIN attendu 4 chiffres (validation souple, l'API tranche). Tous secrets chiffrés
  (`utils::encrypt`), jamais loggués (PIN/SMS/tokens/device). Helper : entrée jamais logguée.

## Server Actions / API
- `stellantisApi::requestSmsOtp() : void`
- `stellantisApi::requestRemoteToken(string $otpCode) : void`
- `stellantisApi::refreshRemoteToken() : void`  *(throws otp_required)*
- `stellantisApi::getRemoteToken() : string` / `getRemoteTokenInfo() : array` / `hasRemoteToken() : bool`
  / `purgeRemoteTokenCache() : void`
- `stellantis::requestOtpSms() : array` / `activateOtp(string,string) : array` / `renewRemoteToken() : array` / `runOtpHelper(array) : array`
- `stellantis::resolveCustomerId() : void` / `reconnecterDemonSiLance() : void` / `otpState() : string` / `purgeOtp() : void`

## Dépendances
`plugin_info/packages.json` : ajout `pycryptodomex` (importé `Cryptodome` par le module OTP), **version
exacte dans la valeur** (règle CLAUDE.md) : `"pycryptodomex": {"version":"3.20.0"}`. `requests` déjà
présent. `paho-mqtt` inchangé.

## Licence (pré-requis au vendoring)
En-têtes source du plugin = GPLv3-or-later (déjà présents). Anomalies corrigées : `LICENSE` (texte GPL
v3) et `info.json` `"licence":"GPL-3.0"` → cohérent + compatible avec le module vendorisé (GPL-3.0).
