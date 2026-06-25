# Index de la doc API Stellantis / Groupe PSA Connected Vehicles

> **But** : trouver LA bonne source sans re-chercher à chaque fois. L'agent lit cet index (gratuit),
> repère la page utile, puis fait **un seul `WebFetch`** sur l'URL directe.
>
> ⚠️ **Particularité majeure vs une API classique** : il y a **deux mondes** (cf.
> `.memory/analyse/stellantis-api-architecture.md`) :
> 1. **API OFFICIELLE** (`developer.groupe-psa.io`) — **bien documentée** mais **inaccessible** à un
>    particulier (accès partenaire sur dossier). On la lit quand même : c'est la **référence propre du
>    data model et du catalogue de commandes**.
> 2. **API CONSOMMATEUR** (reverse-engineered, celle qu'on implémente) — **pas de doc officielle** : la
>    source de vérité est le **code** de `psa_car_controller` (cf.
>    `.memory/analyse/stellantis-implementations-reference.md`).
>
> **Maintenance** : si une URL renvoie 404 (le portail bouge, rebrand Stellantis en cours), re-vérifier
> depuis `https://developer.groupe-psa.io/`. **Dernière synchro** : 2026-06-25.

---

## 0. Correspondance « déclencheur » → source (raccourci)

| Déclencheur | Source prioritaire |
|---|---|
| 1. Quelle API / accès / viabilité | `analyse/stellantis-api-architecture.md` (interne) d'abord |
| 2. OAuth2 PKCE (flow, endpoints marque, realms, refresh) | § 1 ci-dessous + `psa_client.py` (code réf.) |
| 3. `client_id`/`client_secret`/certs (extraction APK) | `app_decoder.py` + dépôt `flobz/psa_apk` (§ 3) |
| 4. Data model statut véhicule (champs exacts) | `analyse/stellantis-data-model.md` + `models/*` psa_cc (§ 3) + B2C overview (§ 1) |
| 5. Catalogue & payloads des commandes à distance | B2B « Remote Set-Up » (§ 1) + `RemoteClient` psa_cc (§ 3) |
| 6. MQTT (broker, topics, auth) | `RemoteClient` psa_cc / DeepWiki §5.1 (§ 3) + WebPortal MQTT (§ 1) |
| 7. Remote token OTP (SMS/PIN, quotas) | `psa_car_controller` FAQ + issues #851/#925/#967 (§ 3) |
| 8. Mode privacy véhicule (Plane Mode) | Connected-vehicles « Privacy » (§ 1) |
| 9. Limites / quotas / ban / batterie 12 V | issues psa_cc #1130/#967/#859 + forum HA (§ 3) |
| 10. Changelog / dépréciations API | Changelog officiel (§ 1) |
| 11. Comment classe core Jeedom (cache/config/cmd…) | `.memory/external/doc/jeedom/INDEX.md` |

---

## 1. API officielle Stellantis (`developer.groupe-psa.io`) — doc de référence (lecture seule)

URL = préfixe `https://developer.groupe-psa.io/`.

| Chemin | Sujet |
|---|---|
| `connected-vehicles/about/` | Vue d'ensemble des API véhicules connectés |
| `connected-vehicles/remotes/` | Vue d'ensemble des **commandes à distance** (catalogue) |
| `connected-vehicles/privacy/` | **Mode privacy** véhicule (Data / Data&Location / Plane Mode) |
| `webapi/b2c/overview/about/` | API **B2C** end-user : vue d'ensemble + **data model** |
| `webapi/b2c/quickstart/about-authentication/` | Auth B2C (OAuth2) — principe |
| `webapi/b2c/quickstart/enroll-users/` | **Endpoints OAuth2** (authorize/access_token/revoke), realms |
| `webapi/b2c/quickstart/app-registration/` | Enregistrement d'app (confirme « accès sur demande ») |
| `webapi/b2c/remote/about/` | Commandes à distance côté B2C |
| `webapi/b2b/quickstart/authentication/` | Auth B2B (TLS mutuel, certificat) — pour comprendre la voie officielle |
| `webapi/b2b/remote/set-up/` | **Détail des commandes** (payloads : door, horn, lights, charging, preconditioning, state, immobilization, stolen) + webhook callbacks |
| `webapi/b2b/api-reference/changelog/` | Changelog API B2B (dépréciations trips) |
| `changelog/` | Changelog global du portail (versions v3/v4, retraits) |
| `webportal/v1/advanced-features/mqtt/` | **MQTT** (WebPortal embarqué) : topics, payload, certificat — ≠ broker commandes consommateur |

> ⚠️ La base REST **officielle** est `api-cert.groupe-psa.com/connectedcar/v3` (cert mutuel). La base
> **consommateur** (celle qu'on utilise) est `api.groupe-psa.com/connectedcar/v4`. **Ne pas confondre.**

## 2. Endpoints consommateur essentiels (mémo — détail dans l'analyse interne)

| Besoin | Endpoint |
|---|---|
| Autorisation OAuth2 PKCE | `GET https://idpcvs.{marque.tld}/am/oauth2/authorize` |
| Token / refresh | `POST https://idpcvs.{marque.tld}/am/oauth2/access_token` |
| Révocation | `POST https://idpcvs.{marque.tld}/am/oauth2/token/revoke` |
| Liste véhicules | `GET https://api.groupe-psa.com/connectedcar/v4/user/vehicles` |
| Statut véhicule | `GET .../connectedcar/v4/user/vehicles/{id}/status` |
| Dernière position | `GET .../connectedcar/v4/user/vehicles/{id}/lastPosition` |
| Alertes (pneus, défauts, ouvrants…) | `GET .../user/vehicles/{id}/alerts` (AlertMsgEnum ~80 types) |
| Entretien (révision) | `GET .../user/vehicles/{id}/maintenance` |
| Push webhook (B2C officiel only) | `POST .../user/vehicles/{id}/monitors` (inaccessible particulier) |
| Commandes (MQTT) | broker `mw-{brand}-m2c.mym.awsmpsa.com:8885`, publish `psa/RemoteServices/from/cid/{CID}/{ServiceType}/state` |

Marques (TLD / realm) : Peugeot `idpcvs.peugeot.com` / `clientsB2CPeugeot` · Citroën `idpcvs.citroen.com`
/ `clientsB2CCitroen` · DS `idpcvs.driveds.com` / `clientsB2CDS` · Opel `idpcvs.opel.com` /
`clientsB2COpel` · Vauxhall `idpcvs.vauxhall.co.uk` / `clientsB2CVauxhall`.

## 3. Code de référence (la vraie « doc » du protocole consommateur)

| Source | À ouvrir pour |
|---|---|
| `github.com/flobz/psa_car_controller` — `psacc/application/psa_client.py` | OAuth2 PKCE, realms, scope, refresh |
| idem — `psa/setup/app_decoder.py` | extraction `client_id`/`client_secret`/certs depuis l'APK |
| idem — classe `RemoteClient` (cf. `deepwiki.com/flobz/psa_car_controller/5.1-mqtt-remote-client`) | MQTT : broker, topics, auth, **payloads des commandes** |
| idem — `psa/connected_car_api/models/*` + `api_spec.md` | **data model** (champs exacts du statut, OpenAPI informel) |
| idem — issues #1121/#811/#393/#839, discussions #700/#678 | **dumps JSON réels** + changelog v4.15.1 + pièges |
| idem — `docs/psacc_api.md`, `FAQ.md`, `psa/oauth.py` | API REST locale + OTP + `@rate_limit(6,1800)` |
| `github.com/flobz/psa_apk` | APK par marque (source des credentials) |
| `github.com/andreadegiovine/homeassistant-stellantis-vehicles` | **mapping capteurs→entités** le plus à jour (`const.py`/`configs.json`) |
| `github.com/Mips2648/jeedom-daemon-py` | **lib démon Python Jeedom** (post-MVP MQTT) |
| `github.com/lelas33/plugin_peugeotcars` | ancien plugin Jeedom PHP (⚠️ **abandonné** ; miner le changelog pour les bugs à éviter) |

> **Règle tokens** : `grep` cet index pour la ligne utile plutôt que de le `Read` en entier. **Cite**
> l'info retenue (endpoint, champ, payload) et sa source. Si une source **contredit** une spec/analyse
> interne → **signaler l'écart** (le code de référence fait foi sur le contrat de l'API consommateur ;
> l'analyse interne sur les décisions projet).
