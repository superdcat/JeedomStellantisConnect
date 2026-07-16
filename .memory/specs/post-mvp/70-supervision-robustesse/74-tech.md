# Spec technique — UC74 (Renouvellement & alertes de token)

> **Nature de l'UC** : supervision/robustesse, **100 % local, AUCUN appel réseau/MQTT neuf**. Comme
> UC71/UC72, l'audit du code existant montre que **la quasi-totalité du périmètre est déjà en place** ;
> le seul livrable code est un **ajout ciblé** (une notification centre de messages manquante). Le reste
> de la spec fonctionnelle est **vérifié couvert** et documenté ci-dessous (aucun code neuf).

## Résultat d'audit (existant, `core/class/stellantis.class.php`)

| AC | Couverture existante | Delta UC74 |
|----|----------------------|------------|
| **AC1** — refresh token mort → alerte « reconnexion requise » **sans boucle** | **« Sans boucle » = déjà complet.** `refreshToken()` sur `invalid_grant` **supprime le token cache** (garde de concurrence : ne lève `auth_required` que si le refresh_token mort **est** celui du cache ; sinon rend le token concurrent déjà rafraîchi, un seul niveau) ⇒ toute passe suivante lève `auth_required` **sans réseau** (`getToken`→`refreshToken`→cache null→throw). `callWithToken`/`getToken` ne rejouent pas sur `auth_required`. Cron : `getToken()` primé **1×/slot** ; sur échec `tokenOk[$slot]=false` ⇒ tous les véhicules du slot sont **sautés** (pas de récurrence per-véhicule). **Mode dégradé** (stop des appels) + **log warning throttlé 1×/h/slot** (cache `degraded_warn` suffixé par slot) + bandeau page plugin (`connectionState()`→`unauthenticated`) + ligne page Santé (`health()`). | **Ajout du `message::add`** centre de messages (le seul manquant — la spec liste explicitement « log warning + **message** + Santé »). |
| **AC2** — remote token expiré → alerte « refaire l'OTP » **sans régénération auto** | **Déjà couvert.** `refreshRemoteToken()`/`getRemoteToken()` → échec de refresh = `stellantisException('otp_required')` + purge du remote token, **jamais** de SMS/OTP automatique (quotas 6/24 h, 20 SMS/vie protégés). `alerterOtpRequired()` (message center, tag `otp_required`, throttle 24 h) déclenché **proactivement** au cron via `syncDaemonToken()` (démon lancé) **et réactivement** sur chaque commande MQTT. État OTP visible en Santé (`otpState()`) et page config. | **Aucun code neuf** (vérifié). |
| **AC3** — états visibles (page plugin + Santé) ; aucun secret | **Déjà couvert.** Bandeau `connectionState()` (page plugin `desktop/php/stellantis.php`), `health()` (page Santé), état OTP (page config `configuration.txt`). Aucun secret loggué/affiché. | Le nouveau message center **renforce** la visibilité (icône cloche, global). |

## Architecture

**Fichier modifié : `core/class/stellantis.class.php` (uniquement).** Aucune modification de
`desktop/php/*`, `plugin_info/configuration.txt`, AJAX, démon, `packages.json`, config véhicule/plugin.

### 1. Nouvelle méthode `alerterAuthRequired(int $_slot): void`
- Placée **près des autres helpers d'alerte** (`alerterOtpRequired`/`alerterDaemonAuthFailed`/
  `alerterRateLimit`, ~L2506-2544) — même famille, même style.
- Corps : `message::removeAll('stellantis', 'auth_required_' . $_slot)` **puis**
  `message::add('stellantis', <texte>, '', 'auth_required_' . $_slot)`.
- **Tag suffixé par slot** (`auth_required_<slot>`), **comme `rate_limited_<slot>`** (multi-comptes UC54) —
  jamais un tag fixe façon `otp_required`/`daemon_auth_failed` (qui sont mono-compte par nature). Un
  `removeAll` d'un compte n'efface pas l'alerte d'un autre ; `removeAll`+`add` ⇒ au plus **un** message
  actif par compte (pas d'empilement).
- 3ᵉ paramètre de `message::add` = lien, laissé **vide** (`''`) comme tous les précédents.
- **Pas de `log::add`** dans le helper : le warning « Mode dégradé … (ré)authentifiez-vous » est **déjà
  émis inline** par le cron dans la même garde (éviter le double-warning). Le helper est donc focalisé sur
  la **notification utilisateur** (centre de messages).
- **Texte sans secret** (aucun token/refresh_token/client_secret).

### 2. Appel dans le hook cron (`cron()`, branche `auth_required` existante, ~L4068-4075)
- Appeler `self::alerterAuthRequired($slot);` **à l'intérieur de la garde `degraded_warn` déjà présente**
  (branche « première occurrence » : `if (cache::byKey($cleWarn)->getValue('') == '')`).
- **Réutilise le throttle `degraded_warn` existant** (1×/h/slot) : pas de 2ᵉ clé de throttle, pas de spam.
  Le message réapparaît (removeAll+add) au plus **1×/h** tant que l'auth reste cassée, puis disparaît au
  succès (cf. §3) — même cadence « persistant mais pas spammy » que `alerterOtpRequired` (24 h).
- **Se déclenche STRICTEMENT sur `auth_required`** : la branche `else` (transport/rate_limited transitoires)
  n'appelle jamais l'alerte ⇒ **pas de « cri au loup »** sur un incident réseau passager. `degraded_warn`
  n'est posé QUE dans la branche `auth_required` (jamais par transport/rate_limited) ⇒ le couplage
  throttle↔alerte est sûr. **Ajouter un commentaire** documentant explicitement pourquoi ce couplage est
  sûr (précédent : commentaire de `alerterRateLimit` sur l'edge-trigger), pour prévenir un futur éditeur
  qui poserait `degraded_warn` sur un autre type d'erreur.

### 3. Effacement dans `storeTokenResponse()` (~L5288, `stellantisApi`)
- Ajouter en fin de méthode : `message::removeAll('stellantis', 'auth_required_' . $_slot);`.
- **Point unique de recouvrement** : `storeTokenResponse()` est appelée par **`exchangeCode()`** (ré-auth
  manuelle) **et** `refreshToken()` (refresh auto réussi). Après un refresh token mort le token cache est
  supprimé ⇒ la **seule** voie de recouvrement est la ré-auth manuelle (`exchangeCode`) → ce point unique
  la couvre, avec **feedback immédiat** (pas d'attente ≤1 min du prochain cron).
- **Cohérent avec le précédent OTP** : `activateOtp()`/`renewRemoteToken()` effacent le message OTP
  immédiatement au succès de la ré-activation manuelle.
- **Pas de clear redondant dans le cron** : quand `getToken()` réussit à nouveau au cron, c'est qu'un
  token valide existe déjà en cache — donc `storeTokenResponse` a **déjà** effacé le message. Le clear
  cron serait strictement redondant (retiré du plan). `degraded_warn` continue d'être effacé au succès du
  priming (inchangé — c'est un flag cache, distinct du message).
- Précédent d'appel `stellantis::` depuis `stellantisApi` : `enterRateLimitCooldown()` appelle déjà
  `stellantis::alerterRateLimit()`. Ici on peut appeler directement `message::removeAll` (core, toujours
  chargé) — pas besoin de transiter par `stellantis::`.

## Server vs Client
**100 % serveur.** Logique cron + cache + centre de messages Jeedom. **Aucun appel réseau ajouté**
(guardrail anti-ban) ⇒ AC1 « sans boucle d'appels » garanti par construction. Aucune logique client/JS.

## Validation
- **Serveur** : l'alerte n'est produite que sur `auth_required` (jamais transport/rate_limited) ; throttlée
  via `degraded_warn` ; effacée au premier succès de token (refresh ou ré-auth manuelle).
- **Aucune entrée utilisateur** dans ce périmètre (pas de champ/form nouveau).
- **Sécurité** : message sans secret ; tags suffixés par slot ; pas de log de token.

## Server Actions / API
Aucune action AJAX ni endpoint REST/MQTT nouveau. Signatures :
- `private static function alerterAuthRequired(int $_slot): void` — nouvelle.
- Appel `self::alerterAuthRequired($slot)` dans `cron()`.
- `message::removeAll('stellantis', 'auth_required_' . $_slot)` en fin de `stellantisApi::storeTokenResponse()`.

## Dépendances
Aucune (ni PHP, ni pip). `packages.json` inchangé.

## Impact i18n (FR — traduction différée)
Deux nouvelles chaînes `__()` (les seules) :
1. `La connexion au compte Stellantis a expiré (jeton de rafraîchissement invalide ou révoqué). Reconnectez-vous depuis la configuration du plugin.` (slot 1)
2. `La connexion au compte secondaire %s a expiré (jeton de rafraîchissement invalide ou révoqué). Reconnectez-vous depuis la configuration du plugin.` (slots 2..N, `sprintf`)

## Limites assumées / hors périmètre (documentées)
- **Angle mort AC2 (démon arrêté + remote refresh token mort)** : `syncDaemonToken()` ne vérifie le remote
  token QUE si `deamon_info()['state'] == 'ok'`. Si l'OTP est activé mais le démon **arrêté** et que le
  remote refresh token meurt, aucune alerte OTP **proactive** n'est émise (seulement **réactive** à la
  prochaine commande). **Choix : hors périmètre UC74.** Justification : (1) le remote token n'est **utile
  qu'avec le démon lancé** (mot de passe MQTT) ⇒ son expiration démon-arrêté est sans effet fonctionnel ;
  (2) le **démon arrêté est déjà signalé en page Santé** (UC71 : « Démon arrêté — pilotage à distance
  indisponible ») ⇒ une alerte OTP supplémentaire serait un **signal redondant**. À revoir seulement si la
  recette révèle un besoin réel.
- **« À confirmer » de la spec (durées de vie / marges)** : l'access_token OAuth2 (~890 s) est déjà géré
  par `TOKEN_MARGE` (refresh proactif) ; le refresh_token est côté serveur (on réagit sur `invalid_grant`,
  pas de marge à calibrer) ; le remote token (~890 s) par le refresh proactif de `getRemoteToken()`.
  **Aucune calibration de marge n'est requise en code** — c'est une observation de recette, pas une tâche.
- **Message `auth_required_<slot>` orphelin si un compte secondaire est TOTALEMENT déconfiguré** (ex.
  `client_id_2` vidé) alors qu'une alerte est active : les hooks `preConfig_client_id_N`/`preConfig_brand_N`
  n'appellent que `purgeTokenCache(N)` (pas de `message::removeAll`), et un slot absent de
  `slotsConfigures()` n'est plus itéré par `cron()` ⇒ plus rien ne l'efface. **Impact faible & rare** : dans
  le flux normal (changement de credentials → ré-authentification), `storeTokenResponse()` efface déjà le
  message ; l'orphelin ne survient que si l'utilisateur déconfigure sans jamais se reconnecter, et le
  message reste **acquittable manuellement** dans le centre de messages Jeedom. **Choix : hors périmètre,
  documenté** — c'est une **limitation structurelle préexistante symétrique** à celle de
  `rate_limited_<slot>`/`LINK_ERROR_KEY` (mêmes hooks, même non-itération par le cron), **pas une régression
  introduite par UC74** ; la corriger pour le seul `auth_required` créerait une incohérence avec le cycle
  de vie des autres clés par slot (relève d'un futur nettoyage transverse du cycle de vie multi-comptes,
  pas d'UC74).
