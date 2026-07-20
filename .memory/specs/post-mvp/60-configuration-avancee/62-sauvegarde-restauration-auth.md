# 62 — Sauvegarde & restauration de la configuration d'authentification / activation

**Domaine :** Configuration avancée · **Dépend de :** MVP/01 (config plugin), MVP/03 (token OAuth2),
UC12 (OTP / remote token), UC54 (slots multi-comptes) · **Statut :** à spécifier (tech)

## Objectif / valeur
Permettre à l'utilisateur d'**exporter** l'état d'authentification/activation du plugin dans un fichier
qu'il télécharge, puis de le **ré-importer** sur une installation neuve (réinstallation du plugin,
migration de box, restauration Jeedom, changement de machine).

La valeur centrale : **ne pas reconsommer un SMS d'activation OTP**. L'activation du pilotage à distance
(UC12) provisionne un *device* OTP via SMS + code PIN ; ce SMS est prélevé sur un quota **dur et définitif
de 20 activations / compte à vie** — un compte peut se **bloquer** irrémédiablement. Repartir d'une install
fraîche oblige aujourd'hui à refaire toute la procédure (nouveau SMS + code PIN), gaspillant une unité
irremplaçable. Sauvegarder/restaurer le *device* OTP (et les identifiants) supprime ce coût.

Bénéfices secondaires : préserver le **compteur `otp_sms_count`** (garde-fou anti-blocage, cf. « Notes »),
et éviter de refaire l'échange OAuth2 navigateur (PKCE) si le refresh token est encore valide.

## Périmètre
- **Inclus (uniquement l'authentification / activation)** :
  - **Par slot de compte (1..N, cf. UC54)** : `client_id`, `client_secret`, `brand`, `country`,
    `redirect_uri` (clés non suffixées pour le slot 1, suffixées `_2`/`_3` pour les secondaires).
  - **Slot 1 (pilotage à distance, mono-compte)** : `otp_device` (device OTP provisionné — **l'actif
    non régénérable sans SMS**), `otp_sms_count` (compteur d'activations SMS à vie 0..20),
    `otp_sms_pending`, `customer_id` (CID MQTT).
  - `broker_host` / `socketport` (paramètres démon MQTT s'ils ont été surchargés).
  - **Caches de tokens, en best-effort** : token OAuth2 (access + refresh) **par slot**, remote token OTP.
    Utiles pour un redémarrage sans re-clic, mais **non garantis** (durée de vie courte, rotation
    serveur) : ils ne sont qu'un accélérateur, jamais la donnée critique.
- **Exclu** :
  - Les **équipements véhicule** (eqLogic) et leur historique : re-créés par la **découverte** une fois
    l'auth restaurée, ou couverts par le **backup Jeedom natif**. Cette UC n'est pas un backup général.
  - La télémétrie, les caches d'état (sessions de charge, trajets, geofencing, statistiques d'appels).
  - Les réglages **personnels / non-auth** : zone domicile `home_*`, `map_tile_url`, seuils d'entretien,
    cadences auto-wakeup, `isVisiblePanel`, `syncEnabled`… (hors sujet « auth/activation » ; extension
    possible plus tard sous forme d'un « export complet » distinct).
  - Les logs.

## Détails techniques

### Format du fichier
Un fichier **JSON versionné** produit et consommé par le plugin :
`{ "plugin": "stellantis", "schema_version": 1, "exported_at": <ISO8601>, "payload": <chiffré> }`.
Le `schema_version` permet de **refuser proprement** (ou adapter) un fichier issu d'une version
incompatible. Nom de fichier suggéré : `stellantis-auth-<date>.json` (ou extension dédiée `.stellantis`).

### ⚠️ Contrainte structurante — le chiffrement au repos est spécifique à l'instance
Les valeurs sensibles sont stockées chiffrées via `utils::encrypt` (config `$_encryptConfigKey`) et via la
classe `cache` (tokens). Ce chiffrement utilise une **clé propre à l'instance Jeedom** : un blob chiffré
**ne se déchiffre pas** sur une autre installation. **Conséquence directe** : l'export ne peut pas se
contenter de recopier les blobs chiffrés — il doit **déchiffrer** les valeurs, les mettre dans le fichier,
puis les **ré-chiffrer** à l'import avec la clé de la **nouvelle** instance. Le fichier transporte donc des
secrets **hors** de la protection at-rest de Jeedom → il **doit** être protégé par lui-même (cf. ci-dessous).
*(à confirmer : mécanisme exact de la clé d'instance — cf. « À confirmer ».)*

### ⚠️⚠️ Sécurité critique — le fichier de restauration est un vecteur d'exécution de code
`otp_device` est un **`base64(pickle)`** que `resources/otp_helper.py` **désérialise via `pickle.loads()`**
(action `code`). Le docblock de `stellantis::storeOtpDevice()` porte un **invariant de sécurité explicite** :
ce blob ne doit **JAMAIS** provenir d'une autre source que le cache config chiffré du plugin lui-même. Or
une restauration depuis un fichier est **exactement** une source externe. Un fichier **forgé** importé
⇒ `pickle.loads()` d'un objet malveillant ⇒ **exécution de code arbitraire** sous l'utilisateur
Apache/Jeedom.

**Exigence non négociable** : le fichier doit être **chiffré ET authentifié** (chiffrement authentifié /
AEAD, ex. AES-256-GCM), avec une **passphrase saisie par l'utilisateur**. L'`otp_device` ne doit **jamais**
être écrit ni désérialisé tant que l'**authenticité du fichier n'est pas vérifiée** (tag AEAD valide).
Cela aligne le modèle de confiance sur la réalité (« l'utilisateur restaure **son propre** fichier »),
protège les secrets une fois le fichier hors de Jeedom, et neutralise le vecteur pickle : sans la
passphrase, personne ne peut fabriquer un payload accepté.

### Chiffrement du fichier (passphrase)
- À la **sauvegarde** : l'utilisateur saisit une passphrase (+ confirmation). Le `payload` (JSON des
  secrets en clair) est chiffré en **AES-256-GCM**, clé dérivée par **PBKDF2/scrypt** (sel aléatoire
  stocké dans le fichier, itérations élevées). Le tag d'authentification GCM est conservé.
- À la **restauration** : même passphrase → dérivation → déchiffrement + **vérification du tag**. Tag
  invalide (mauvaise passphrase, fichier corrompu ou forgé) ⇒ **refus net, aucune écriture**, message
  clair. `ext-openssl` (quasi toujours présente) suffit (`openssl_encrypt`/`decrypt` en `aes-256-gcm`).

### UI (page de configuration du plugin, admin-only)
- Les deux actions vivent sur la **page de config plugin** (`plugin_info/configuration.php`, éditée via le
  miroir `configuration.txt`), aux côtés des identifiants et de l'activation OTP (UC12/UC54), sous
  `isConnect('admin')`.
- **« Sauvegarder la configuration d'authentification »** → génère le fichier et le renvoie en
  **téléchargement** (réponse HTTP `Content-Disposition: attachment`). Voir le patron de téléchargement
  déjà utilisé par UC61 (`downloadToFile`/génération de réponse binaire).
- **« Restaurer »** → **champ d'upload de fichier** + champ passphrase → soumission AJAX (multipart,
  `$_FILES`) → parse + validation stricte + écriture.

### Après une restauration réussie
1. Ré-chiffrer et écrire les clés de config (par slot) + `otp_device`/`otp_sms_count`/`customer_id`/broker.
2. Ré-injecter les caches de tokens (si présents et non expirés) — sinon ignorer silencieusement.
3. Best-effort : `resolveCustomerId()` si le CID manque, `renewRemoteToken()` (**sans SMS**, consomme au
   pire 1 unité du quota 6/24 h) pour réactiver immédiatement le pilotage, `reconnecterDemonSiLance()`.
4. Proposer/déclencher une **découverte des véhicules** pour recréer les équipements.
5. **Message de synthèse** (ce qui a été restauré, ce qui reste à faire — ex. « refaites l'échange OAuth
   si la connexion est en erreur »).

### Robustesse
- Validation stricte du schéma (`plugin == 'stellantis'`, `schema_version` connu, champs attendus) **avant**
  toute écriture ; **transaction logique** : soit tout est écrit, soit rien (pas d'état à moitié restauré).
- Le fichier temporaire uploadé est **supprimé** immédiatement après traitement (jamais persisté).
- **Jamais** de secret loggué (ni à l'export, ni à l'import, ni en cas d'erreur) — logs limités à
  « export généré » / « restauration OK/KO (raison non sensible) ».

## Critères d'acceptation
- [ ] Un clic sur « Sauvegarder » (compte configuré + OTP activé) produit un **fichier téléchargé**
      contenant la config d'authentification/activation, **chiffré par la passphrase** saisie.
- [ ] Sur une **installation fraîche**, « Restaurer » (fichier + bonne passphrase) rétablit la connexion et
      le pilotage à distance **sans envoyer de nouveau SMS**.
- [ ] `otp_sms_count` est **restauré** : le compteur « 20 / vie » n'est pas remis à 0 par la réinstallation.
- [ ] **Mauvaise passphrase, fichier corrompu ou forgé** ⇒ refus clair, **aucune écriture**, et
      `otp_device` **jamais** désérialisé (invariant pickle respecté).
- [ ] Les secrets ne sont **jamais** loggués ; le fichier uploadé n'est **jamais** laissé sur le disque.
- [ ] **Multi-comptes** : tous les slots configurés (UC54) sont sauvegardés puis restaurés.
- [ ] Après restauration, au plus **un** geste utilisateur (bouton « Renouveler le jeton distant », sans
      SMS) — ou une reprise best-effort automatique — suffit à réactiver le pilotage.
- [ ] Un fichier d'une `schema_version` inconnue est **refusé proprement** (message explicite, pas de crash).

## Notes / risques
- **Le fichier contient les clés du royaume** (`client_secret`, `otp_device` = capacité équivalente au mot
  de passe MQTT, refresh token OAuth). D'où l'obligation de chiffrement authentifié par passphrase **forte** :
  l'UI doit l'exiger et **avertir** l'utilisateur de conserver le fichier en lieu sûr.
- **`otp_sms_count` restauré = fonctionnalité de sécurité, pas seulement de confort** : sans restauration
  du compteur, un utilisateur pourrait, à force de réinstallations, dépasser sans le savoir les 20
  activations à vie et **bloquer son compte**. Le restaurer préserve le garde-fou UC12.
- **Tokens best-effort** : le refresh token OAuth peut être **à usage unique / roté** côté serveur, et le
  remote token expire en ~890 s ⇒ un fichier ancien peut avoir des tokens périmés. C'est acceptable : les
  actifs **durables** sont *identifiants + `otp_device`* ; le refresh OAuth relance l'accès REST, le
  `renewRemoteToken` (sans SMS) relance le canal MQTT.
- **Le device OTP peut être révoqué/expiré côté serveur** inWebo indépendamment du plugin (perte de session
  serveur, révocation) : dans ce cas la restauration ne suffit pas et un SMS redevient inévitable — à
  **documenter** comme limite, sans le présenter comme un échec du plugin.
- **Ne remplace pas** le backup Jeedom natif (équipements, scénarios, historiques) : c'est un **complément
  ciblé** sur l'auth/activation.
- Cohérent avec la posture ToS/risque déjà en place (UC82) : aucune donnée n'est envoyée à un tiers, tout
  reste local (fichier chez l'utilisateur).

## À confirmer
- **Mécanisme exact de `utils::encrypt`/`decrypt`** (clé propre à l'instance) et donc la nécessité du
  cycle *decrypt → fichier → re-encrypt* : confirmer que les blobs chiffrés ne sont **pas** portables entre
  installations (fortement probable). Cf. mémoire `jeedom-encrypt-config-key`.
- **Disponibilité d'`ext-openssl` + `aes-256-gcm`** sur les installations Jeedom courantes
  (Debian/Raspberry Pi) — très probable ; sinon repli documenté (autre AEAD, ou refus explicite si absent).
- **Rotation du refresh token OAuth2** (usage unique ?) : si oui, tracer clairement la dégradation vers un
  ré-échange OAuth navigateur quand le refresh restauré est périmé.
- **Cycle de vie serveur du device OTP** : durée de validité / conditions de révocation indépendantes du
  plugin — pour calibrer le message de restauration (« pilotage réactivé » vs « refaites l'activation SMS »).
- **Patron d'upload de fichier** dans le formulaire de config plugin Jeedom (admin) : `ajax::init()` +
  `$_FILES`, garde CSRF, taille max — confirmer contre un exemple du core ; réutiliser le patron de
  **téléchargement** de sortie déjà éprouvé en UC61.
- **Contrainte d'accès `configuration.php`** (cf. CLAUDE.md) : toute évolution du formulaire passe par
  `configuration.txt` puis `cp` vers `.php` — à intégrer au plan technique.
