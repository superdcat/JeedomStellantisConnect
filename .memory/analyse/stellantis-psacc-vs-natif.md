# psa_car_controller : dépendance d'exécution vs implémentation native — analyse comparative

> Analyse réalisée le 2026-07-06 pour le plugin Jeedom **stellantis**.
> Question posée : faut-il **implémenter nativement** le protocole PSA (décision actuelle, cf.
> `[[stellantis-api-architecture]]`) ou **dépendre à l'exécution** de
> [`flobz/psa_car_controller`](https://github.com/flobz/psa_car_controller) (PSACC), qui implémente
> déjà tout (OAuth2 PKCE, REST v4, MQTT, OTP, extraction APK) et expose une **API REST locale** ?
>
> ⚠️ **Statut des sources** : fiche PSACC vérifiée le 2026-07-06 sur le dépôt GitHub (README,
> `docs/psacc_api.md`, `docs/Install.md`, `docs/Docker.md`, `pyproject.toml`, releases, issues,
> discussion #779) via recherche web dédiée. Docs internes croisées :
> `[[stellantis-api-architecture]]`, `[[stellantis-implementations-reference]]`, specs MVP et UC11/12/82.

---

## 0. TL;DR — verdict

**Conserver l'implémentation native** (MVP PHP pur, démon Python maison post-MVP). La dépendance à
PSACC ne gagne que sur **un** axe (mutualisation de la maintenance du protocole) et perd sur tous les
autres : expérience d'installation Jeedom, intégration au core (secrets chiffrés, santé, logs, i18n),
sécurité (API locale **non authentifiée** qui déverrouille la voiture), empreinte technique (stack
Python lourde) et risque de couplage (précédent `plugin_peugeotcars`, mort exactement sur ce modèle).
PSACC reste la **spec vivante** du protocole + un **système d'alerte avancé** (ses issues signalent les
cassures Stellantis avant nous). Détail § 5 ; nuance post-MVP (variante « connecteur ») § 4.3.

---

## 1. Fiche factuelle PSACC (état 2026-07-06)

- **Nature** : application Python autonome (GPL-3.0, ~99 % Python), serveur **Flask + Dash** sur le
  **port 5000** : dashboard (cartes de trajets, courbes de charge, stats) + **API REST locale**.
  Historisation **SQLite** (`info.db` : `position`, `battery`, `battery_curve`, `battery_soh`).
- **Santé** : **activement maintenu** — v3.7.5 du 30 juin 2026, plusieurs releases/mois, 565★,
  323 forks, ~139 issues ouvertes. Aucun fork plus actif. Bus factor : un mainteneur principal (flobz).
- **Couverture fonctionnelle** : tout ce que vise notre plugin, lecture **et** commandes (charge,
  seuil, heures creuses, préconditionnement, klaxon, feux, portes, wakeup, SOH, trajets), plus
  l'**extraction automatique des credentials depuis l'APK** (androguard + téléchargement APK) et le
  **login OAuth automatisé** (Playwright/webkit, module `headless_oauth.py`).
- **API REST locale** (17 endpoints, `docs/psacc_api.md`) — tous en **GET**, **sans authentification** :
  `/get_vehicleinfo/<VIN>` (`?from_cache=1`), `/wakeup/<VIN>`, `/charge_now/<VIN>/<1|0>`,
  `/charge_control?vin=…&hour&minute|percentage`, `/charge_hour?…`, `/preconditioning/<VIN>/<1|0>`,
  `/horn/<VIN>/<count>`, `/lights/<VIN>/<duration>`, `/lock_door/<VIN>/<1|0>`, `/battery/soh/<VIN>`,
  `/vehicles/trips`, `/vehicles/chargings`, `/settings[...]`, `/` (dashboard).
- **Déploiement** : `pip3 install psa-car-controller` (+ assistant `--web-conf`), image Docker
  `flobz/psa_car_controller` (volume `/config`), add-on Home Assistant dédié
  ([`flobz/psacc-ha`](https://github.com/flobz/psacc-ha)). **Python ≥ 3.11** requis.
- **Dépendances** (pyproject, Poetry) : `paho-mqtt >=1.5,<2.0` (✅ confirme notre pin UC11/82),
  `androguard ^4.1.2`, `playwright >=1.44` (optionnel), `dash >=4,<5` + `plotly` +
  `dash-bootstrap-components` + `Flask`, `pandas`/`numpy`/`scipy`, `pydantic ^1.9` (**v1**, vieillissante),
  `oauth2-client`, `cryptography`, `pycryptodomex`.
- **Config/secrets côté PSACC** : `config.json` / `charge_config.json` / `otp.bin` **en clair** dans son
  répertoire de config, hors de tout mécanisme Jeedom.
- **Faits protocole neufs pour nous** (à reporter dans les specs, cf. § 6) : flux OAuth PSA **durci**
  (discussion #779 : login automatisé Playwright ajouté car le flow manuel casse souvent ; procédure de
  secours = extraire le `code` via F12/Network — c'est **exactement notre design MVP/03**) ; **remote
  token MQTT TTL ~890 s**, refresh via `connectedcar/v4/virtualkey/remoteaccess/token`, repli OTP/SMS.

---

## 2. Les options en présence

- **A — Native (décision actuelle)** : MVP lecture 100 % PHP (OAuth2 PKCE + REST v4 + cron), commandes
  post-MVP via **démon Python maison** minimal (`paho-mqtt` seul, socket Jeedom). PSACC = référence à lire.
- **B — Dépendance à PSACC**, trois variantes distinctes :
  - **B1 — Service externe** : l'utilisateur déploie PSACC lui-même (Docker/pip, hors Jeedom) ; le
    plugin devient un **client PHP de l'API REST locale** (`http://host:5000/...`). C'est le modèle de
    l'add-on Home Assistant et du fil communautaire Jeedom (virtuel + script).
  - **B2 — PSACC packagé par le plugin** : `packages.json` installe `psa-car-controller` (pip) et le
    plugin le pilote comme son démon (`hasOwnDeamon:true` sur le serveur Flask).
  - **B3 — PSACC comme bibliothèque** : notre **propre démon** importe `PSAClient`/`RemoteClient` en
    lib Python au lieu de réimplémenter OAuth/MQTT/OTP.

---

## 3. Comparaison par axe

| Axe | A. Natif | B1. Service externe | B2. Packagé | B3. Lib dans notre démon |
|---|---|---|---|---|
| **Effort de dev initial** | 🔴 Élevé (tout le protocole, MQTT/OTP surtout) | 🟢 Minimal (client HTTP local) | 🟡 Moyen (packaging + pilotage) | 🟡 Moyen (démon fin, protocole offert) |
| **Maintenance protocole** (quand Stellantis casse) | 🔴 À notre charge | 🟢 Mutualisée (flobz corrige, releases fréquentes) | 🟢 Mutualisée | 🟢 Mutualisée (mais API interne non stable) |
| **Install utilisateur Jeedom** (marketplace 1-clic) | 🟢 MVP : zéro dépendance | 🔴 Docker/pip **hors Jeedom** + assistant web port 5000 + double config | 🔴 pip lourd (Python ≥3.11, pandas/scipy/dash/androguard) sur Debian 12/RPi | 🔴 idem B2 (toute la dep tree, y c. web inutilisé) |
| **Intégration Jeedom** (secrets `$_encryptConfigKey`, logs `log::add`, santé démon, i18n) | 🟢 Totale | 🔴 Secrets/config/état **dans PSACC**, en clair, hors Jeedom | 🟡 Démon non conçu pour Jeedom (pas de `jeedom_com`, config propre, dashboard concurrent) | 🟢 Bonne (notre démon, nos hooks) |
| **Sécurité** | 🟢 Maîtrisée | 🔴 API locale **sans auth** : tout hôte/process joignant le port 5000 peut **déverrouiller la voiture** | 🔴 idem + surface Flask/Dash | 🟢 Pas de serveur HTTP exposé |
| **Empreinte** (RPi, RAM, deps) | 🟢 MVP : 0 dep ; post-MVP : paho-mqtt seul | 🟡 Déportée chez l'utilisateur | 🔴 Très lourde | 🔴 Lourde (deps web tirées quand même) |
| **Fraîcheur data / fonctionnel** | 🟢 = API PSA | 🟢 + historisation SQLite/trajets offerte | 🟢 idem | 🟢 = API PSA |
| **Licence** | 🟢 Libre (protocole ≠ code) | 🟢 GPL sans effet (appel HTTP) | 🟡 GPL-3 embarquée (redistribution) | 🔴 Démon dérivé ⇒ obligations **GPL-3** |
| **Risque de couplage** | 🟢 Aucun tiers au runtime | 🔴 Breaking changes de l'API locale, bus factor, versions PSACC hétérogènes chez les utilisateurs | 🔴 idem + on subit ses migrations (pydantic v1…) | 🔴 API interne **non contractuelle** |

## 4. Enseignements qui pèsent dans la décision

### 4.1 Le précédent `plugin_peugeotcars` (l'option B a déjà été tentée — et est morte)
Le seul plugin Jeedom PSA existant ([`lelas33/plugin_peugeotcars`](https://github.com/lelas33/plugin_peugeotcars),
dernier push 27/12/2023, abandon confirmé) utilisait **précisément** PSACC en pont pour les commandes
(v0.6). Son changelog documente les plaies de ce couplage : race condition sur `config.json`, parsing
shell des mots de passe, « climatisation fantôme » sur token expiré. L'abandon a laissé les
utilisateurs avec un plugin cassé (401 depuis le passage PKCE). Un plugin **dépendant** d'un service
tiers meurt **deux fois** : quand le tiers casse, et quand le pont casse.

### 4.2 Le public Jeedom n'est pas le public Home Assistant
L'add-on HA `psacc-ha` fonctionne car HA **héberge des conteneurs** (add-on store) : PSACC s'installe
en 1 clic **dans** HA. Jeedom n'a pas d'équivalent : `packages.json` fait de l'apt/pip, pas du Docker.
En B1, on demande à un utilisateur Jeedom (souvent RPi, non-dev) de maintenir un second service avec sa
propre interface, sa propre config et ses propres mises à jour — c'est exactement ce que le fil
communautaire « connecter psa-car-controller à Jeedom » fait à la main depuis 2023, et ça reste un
bricolage d'utilisateurs avancés, pas un plugin marketplace.

### 4.3 La nuance honnête : où PSACC gagne vraiment
La partie **dure et fragile** du protocole (MQTT + OTP + remote token 890 s + re-OTP) est déjà résolue
et **maintenue en continu** chez PSACC, alors que notre démon maison devra suivre chaque cassure
Stellantis nous-mêmes. Deux mitigations retenues :
1. **PSACC = spec vivante + canari** : suivre ses releases/issues (elles signalent les cassures avant
   nos utilisateurs) ; règle déjà actée dans `[[stellantis-implementations-reference]]`.
2. **Option de repli documentée** : si, au moment d'attaquer `post-mvp/10-commandes-distance`, le démon
   maison s'avère trop coûteux, la variante **B1 en mode « connecteur optionnel »** (mode de config
   « j'ai déjà un PSACC, voici son URL » → commandes en PHP pur via son API locale, sans démon) est le
   meilleur compromis : PHP pur, opt-in utilisateur averti, sans faire de PSACC un prérequis du plugin.
   À réévaluer à l'ouverture de l'UC11 — **pas** une décision à prendre maintenant.

## 5. Pourquoi le natif l'emporte (synthèse)

1. **Le MVP ne se discute même pas** : la lecture = 3 endpoints REST + OAuth2. Exiger un conteneur
   Docker tiers pour lire un SOC serait absurde ; le natif PHP donne un plugin marketplace 1-clic,
   zéro dépendance, secrets chiffrés dans Jeedom.
2. **Sécurité rédhibitoire en B1/B2** : l'API locale PSACC est en GET **sans authentification** —
   `GET /lock_door/<VIN>/0` déverrouille la voiture pour quiconque atteint le port 5000. Un plugin
   grand public ne peut pas institutionnaliser ça.
3. **L'intégration Jeedom est le produit** : la valeur du plugin vs « PSACC + script », c'est justement
   le setup guidé, les secrets chiffrés, la santé/fraîcheur, les widgets, l'i18n. En dépendant de
   PSACC on garde tous ses défauts d'intégration et on ajoute les nôtres.
4. **Le couplage a déjà échoué** (§ 4.1) et la licence GPL-3 interdit de « juste embarquer » le code
   proprement (B3) sans contaminer le démon.
5. **Le seul avantage de B (maintenance mutualisée) se capture autrement** : spec vivante + canari
   (§ 4.3), et repli B1 optionnel si l'UC11 patine.

➡️ **Décision confirmée** : architecture hybride native (`[[stellantis-api-architecture]]` § 3)
inchangée. La recherche renforce même le créneau : le seul plugin existant est mort, la place est libre
pour un plugin **sans** dépendance externe.

## 6. Retombées à intégrer aux specs (faits neufs, indépendants de la décision)

- **MVP/03 (OAuth2)** : PSA a **durci le flux OAuth** (PSACC #779, module `headless_oauth.py`
  Playwright ajouté pour ça). Notre design « URL affichée → l'utilisateur colle le `code` (F12/Network) »
  est précisément la **procédure de secours** documentée chez PSACC → le rester, mais **documenter
  pas-à-pas la récupération via F12** (mot de passe ≤16 caractères, `code` de 36 caractères,
  `invalid_grant` si trop lent) et prévoir des messages d'erreur pédagogiques.
- **UC12 (OTP/remote token)** : **TTL du remote token ~890 s** ; repli re-OTP (`otp.bin` chez PSACC) ;
  erreur OTP `NOK:SN` vue en 2026 (#1205).
  > ⚠️ **Correction 2026-07-09 (vérifié contre `RemoteClient`/`psa_client` de psa_car_controller)** :
  > l'endpoint du remote token n'est **PAS** `connectedcar/v4/virtualkey/remoteaccess/token` (annoncé ici
  > par erreur) mais **`POST https://api.groupe-psa.com/applications/cvs/v4/mobile/token?client_id=…`**
  > (grants `password` avec le code OTP, puis `refresh_token`). Le SMS d'activation se déclenche par
  > **`POST …/applications/cvs/v4/mobile/smsCode?client_id=…`** (Bearer OAuth2 + `x-introspect-realm`).
  > La crypto OTP (device inWebo contre `https://otp.mpsa.com/iwws/MAC`, RSA-OAEP/AES/SHA256) est
  > **vendorisée** de `psa_car_controller/psa/otp` (GPL-3) → `resources/otp_vendor` (dépend de
  > `pycryptodomex`). Détail : `.memory/specs/post-mvp/10-commandes-distance/12-tech.md`.
- **UC11/82 (packaging)** : pin `paho-mqtt >=1.5,<2.0` **confirmé** par le pyproject PSACC.
- **Doc utilisateur (UC82)** : pointer PSACC comme outil recommandé pour **extraire les credentials**
  (`app_decoder.py` automatise APK→client_id/secret), sans en faire un prérequis d'exécution.

## Sources
- PSACC dépôt / API locale / install : https://github.com/flobz/psa_car_controller ;
  `docs/psacc_api.md` ; `docs/Install.md` ; `docs/Docker.md` ; releases (v3.7.5, 2026-06-30)
- OAuth durci / procédure de secours : https://github.com/flobz/psa_car_controller/discussions/779 ;
  issues #1244, #1223, #1205, #1242
- Add-on Home Assistant : https://github.com/flobz/psacc-ha
- Précédent Jeedom : https://github.com/lelas33/plugin_peugeotcars ;
  https://community.jeedom.com/t/connecter-psa-car-controller-a-jeedom/101751
- Docs internes : `[[stellantis-api-architecture]]`, `[[stellantis-implementations-reference]]`,
  `.memory/specs/post-mvp/10-commandes-distance/11-socle-demon-mqtt.md`,
  `.memory/specs/post-mvp/80-livraison/82-packaging-doc.md`
