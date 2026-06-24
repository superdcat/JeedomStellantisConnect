# Étude : piloter des caméras IMOU depuis Jeedom — API directe vs `imouapi`

> Analyse réalisée le 2026-06-15 pour le plugin Jeedom **imou**.
> Objectif du plugin : piloter des caméras IMOU (allumer/éteindre, activer/désactiver la
> surveillance, PTZ, etc.).
> Question posée : vaut-il mieux passer par l'**IMOU Open API**
> (https://open.imoulife.com/book/en/start.html) ou par le module Python **`imouapi`**
> (https://github.com/user2684/imouapi) ?

---

## ⚠️ Le constat qui change tout

**`imouapi` n'est pas une alternative à l'IMOU Open API : c'est un *wrapper* de cette même
API.** La bibliothèque exige le même `appId`/`appSecret` issus du même portail développeur
(`open.imoulife.com`), et fait par-dessous exactement les mêmes appels HTTP signés que ceux
que l'on ferait en direct.

La vraie question n'est donc **pas** « quelle source de données ? » mais :

> **Dois-je réimplémenter le protocole en PHP (natif Jeedom), ou réintroduire un runtime
> Python + un démon pour réutiliser un wrapper existant ?**

Le plafond fonctionnel est **identique** dans les deux cas (même cloud, mêmes endpoints).

---

## 1. L'IMOU Open API (la fondation commune)

| Aspect | Détail |
|---|---|
| **Transport** | HTTPS `POST` vers un datacenter régional : `https://openapi-fr.easy4ip.com/openapi/<method>` (Europe). Aussi SG (Asie) et West America |
| **Auth** | Compte développeur → `appId` + `appSecret`. Appel `accessToken` → jeton avec `expireTime` (à mettre en cache + renouveler avant expiration) |
| **Signature** | `sign = md5("time:{time},nonce:{nonce},appSecret:{appSecret}")`. Enveloppe `system` = `{ver, appId, sign, time, nonce}`. Contraintes : nonce unique sur 5 min, horloge à ±5 min près sinon erreur `SN1005` |
| **Pilotage** | `setDeviceCameraStatus` / `getDeviceCameraStatus` avec `enableType` + `enable` (bool) + `deviceId` + `channelId` + `token` |
| **PTZ** | `controlMovePTZ` |
| **Découverte** | `listDeviceDetailsByPage` / `deviceBaseList` |
| **Live** | `bindDeviceLive` (flux RTMP/HLS) |

### Mapping des objectifs sur `enableType`

| Besoin | `enableType` |
|---|---|
| **Allumer / éteindre la caméra** | `closeCamera` (privacy : `enable:true` = image coupée — **logique inversée**) |
| **Activer / désactiver la surveillance** | `motionDetect` (mouvement), `headerDetect` (détection humaine) |
| Projecteur / sirène / vision nocturne | `whiteLight`, alarme sonore, mode nuit, etc. |

➡️ Tout ce qui est visé est couvert nativement par un seul couple d'endpoints. La signature
est triviale en PHP (`md5()`, `uniqid()` pour le nonce, `time()`).

### Limites à connaître (valables quelle que soit l'option)

- **5 appareils max par compte développeur** (palier gratuit) — au-delà : erreur de licence.
  ⚠️ Ce quota frappe **à l'appel de pilotage/polling, pas à la découverte** : `listDeviceDetailsByPage`
  liste tout le compte, donc toutes les caméras apparaissent dans Jeedom, mais seules ≤ 5 sont
  réellement pilotables (erreur de licence par appel au-delà). Constaté en UC08 ; contrat exact non
  public, à confirmer empiriquement.
- **Prérequis `accessType=PaaS`** pour les endpoints de pilotage/config caméra (`setDeviceCameraStatus`,
  `getDeviceCameraStatus`, `device/config/*`) : un appareil hors PaaS fait échouer l'appel
  (doc IMOU `setDeviceCameraStatus.html`, 2026-06-17).
- **Pas de flux d'événements / push** : l'API est en **polling** uniquement. Pour rafraîchir
  l'état, on interroge périodiquement.
- Limites de fréquence d'appel (QPS) existantes mais non chiffrées publiquement → mettre en
  cache, ne pas marteler.
- Un changement fait depuis l'app IMOU peut mettre quelques minutes à se refléter.

---

## 2. La bibliothèque `imouapi`

| Aspect | Détail |
|---|---|
| **Nature** | Wrapper Python **asynchrone** (asyncio/aiohttp) de l'IMOU Open API |
| **Classes** | `ImouAPIClient` (bas niveau), `ImouDevice` (haut niveau, auto-découvre les capacités et n'instancie que les switches supportés), `ImouDiscoverService` |
| **Maturité** | v1.0.15 (**janvier 2024**, figée depuis), ~26 ★, **un seul mainteneur** |
| **Usage réel** | Socle du composant Home Assistant `imou_life`… dont le mainteneur a **arrêté le développement actif** et **recommande l'intégration officielle IMOU** pour les appareils récents |

**Atout** : la logique d'auto-découverte des capacités et l'abstraction des switches sont
déjà écrites et éprouvées via Home Assistant.

---

## 3. Confrontation avec l'architecture Jeedom (décisif)

Rappel : le plugin a été rendu **volontairement sans démon** (`resources/` supprimé,
`hasOwnDeamon:false`), 100 % PHP. Jeedom pilote nativement en PHP via `cron`/`cron5` et
`cmd::execute()`.

| Critère | **A. API directe en PHP** | **B. `imouapi` (Python)** |
|---|---|---|
| Adéquation Jeedom | ✅ Natif (PHP, cron, curl déjà présent) | ❌ Réintroduit un démon Python + IPC |
| Coût d'installation | ✅ Quasi nul | ❌ Recrée `resources/`, deps pip, runtime async |
| Pont PHP ↔ Python | ✅ Aucun | ❌ Socket/JSON ou CLI one-shot (cold start) à chaque commande |
| Bénéfice du démon | n/a — **l'API n'a pas de push**, le démon n'apporte aucun flux temps réel | ❌ Complexité d'un démon… pour du polling de toute façon |
| Dépendance externe | ✅ Aucune (code maîtrisé) | ❌ Lib figée, mainteneur unique, projet compagnon abandonné |
| Couverture fonctionnelle | ✅ **Tous** les endpoints (PTZ, live, serrures…) | ⚠️ Limitée à ce que la lib expose |
| Code protocole à écrire | ❌ Token, signature, enveloppe, erreurs (mais simple) | ✅ Déjà fait |
| Debug | ✅ Logs Jeedom unifiés | ❌ Deux runtimes à corréler |

Le seul vrai avantage de B (code protocole déjà écrit) est **faible** ici car la signature
IMOU est triviale, et il se paie par la réintroduction d'un runtime Python + un pont
inter-langage — alors même que **l'absence de push prive le démon de sa raison d'être**.

---

## 4. Recommandation

### ✅ Option A — Appeler l'IMOU Open API directement en PHP

Choix architecturalement cohérent avec Jeedom et avec la décision « sans démon » déjà prise :

- Pas de runtime Python ni de pont IPC à maintenir.
- Pas de dépendance à une lib figée / mono-mainteneur.
- Accès à **tous** les endpoints, pas seulement ceux exposés par le wrapper.
- Le polling se fait naturellement dans `cron`/`cron5` (le champ « Auto-actualisation »
  déjà présent dans le formulaire de config s'y prête).

### 🎯 La vraie astuce : `imouapi` comme implémentation de référence

Ne pas réinventer à l'aveugle : **lire le code source de `imouapi`** (modules `api` et
`device`) pour récupérer gratuitement la liste exacte des `enableType`, la logique de
découverte des capacités et la gestion des codes d'erreur — puis **réimplémenter proprement
en PHP**. Meilleur des deux mondes : zéro dépendance runtime, sans repartir de zéro sur la
connaissance du protocole.

### Esquisse côté Jeedom

- `core/class/imou.class.php` : client PHP (`accessToken` + cache token + signature md5) ;
  `cron5()` → poll `getDeviceCameraStatus` pour rafraîchir les commandes *info*.
- `imouCmd::execute()` : commandes *action* → `setDeviceCameraStatus` (`closeCamera`,
  `motionDetect`…) et `controlMovePTZ`.
- `appId`/`appSecret`/token stockés en configuration **chiffrée** (`$_encryptConfigKey`).
- `plugin_info/packages.json` : retirer `pip3 requests` (devenu inutile, plus de Python).

---

## Sources

- IMOU Open API — Spécification de développement : https://open.imoulife.com/book/http/develop.html
- IMOU Open API — accessToken : https://open.imoulife.com/book/http/accessToken.html
- IMOU Open API — setDeviceCameraStatus : https://open.imoulife.com/book/http/device/config/ability/setDeviceCameraStatus.html
- IMOU Open API — getDeviceCameraStatus : https://open.imoulife.com/book/http/device/config/ability/getDeviceCameraStatus.html
- imouapi — dépôt GitHub : https://github.com/user2684/imouapi
- imouapi — documentation : https://user2684.github.io/imouapi/
- imou_life — intégration Home Assistant (limites, dév. arrêté) : https://github.com/user2684/imou_life
