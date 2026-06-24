# Index des analyses internes — plugin Jeedom IMOU

> **But** : rendre la connaissance interne du projet (décisions d'architecture, limites/pièges
> déjà rencontrés, apprentissages durables) **découvrable et lazy-loadable** par le workflow de dev,
> sans tout charger. L'agent lit cet index (gratuit, local), repère le fichier d'analyse utile, puis
> ouvre **uniquement** ce fichier.
>
> `.memory/analyse/` complète `.memory/specs/` (qui porte l'intention des UC) et la doc externe
> (`.memory/external/doc/`) : ici on consigne ce que **le projet a tranché** ou ce qu'on a **appris
> en codant** — ce que ni le code, ni git, ni `CLAUDE.md` ne disent déjà.
>
> **Maintenance** : à chaque enseignement durable ajouté (Étape 12 du workflow `/feature`), écrire
> dans le bon fichier thématique ci-dessous (ou en créer un nouveau pour un sujet distinct) **et
> mettre à jour cet index** (ligne + déclencheurs). Garder l'index et les fichiers synchronisés.
> **Dernière synchro** : 2026-06-24 (UC76 : indexation de `imou-home-assistant-comparaison.md` + correction vérifiée — `getIotDeviceDetailInfo` = découverte de capacités `abilityRefs`, **PAS** un snapshot de valeurs ; UC76 clôturée sans code).

---

## 0. Correspondance « incertitude » → fichier d'analyse (raccourci)

| Si l'incertitude porte sur… | Fichier |
|---|---|
| Widget de commande Jeedom : fichier `cmd.<type>.<subType>.<nom>.html`, `setTemplate`, tokens dispo (`#id#`/`#logicalId#`/`#uid#`…), pourquoi `#cmd_id[…]#` n'existe pas | `jeedom-widgets-commandes.md` §§ 1-2 |
| Widget pilotant PLUSIEURS commandes (pavé PTZ, lecteur live) : résoudre les sœurs par `byEqLogic` (≠ `jeedom.cmd.byEqLogicId` qui n'existe pas) ; masqué ≠ non-exécutable | `jeedom-widgets-commandes.md` § 3 |
| Exécuter une action depuis un widget, récupérer la valeur de retour PHP (`jeedom.cmd.execute` success.result) ; auth/CSRF AJAX core 4.4+ ; AJAX plugin admin-only inutilisable au dashboard | `jeedom-widgets-commandes.md` §§ 4-5 |
| Poser un template widget sans écraser le choix utilisateur (« si vide ») | `jeedom-widgets-commandes.md` § 6 |
| **CSP Jeedom bloque tout média/image EXTERNE** sur le dashboard (image cassée / « violates CSP ») → afficher du contenu externe (caméra) impose un **proxy same-origin** ; live-snapshot via endpoint + ffmpeg ; dépendance ffmpeg | `jeedom-widgets-commandes.md` § 7 |
| Ajouter une **PAGE** de plugin au **menu** Jeedom (panel) ; masquer/afficher l'entrée de menu par config ; `info.json "display"`/`"mobile"` ; toggle natif `displayDesktopPanel`/`displayMobilePanel` ; `getDisplay()` statique ; page panel non-admin + sélection par équipement `isVisiblePanel` | `jeedom-panel-page-menu.md` |
| Choix « API PHP native vs lib Python `imouapi` », pourquoi sans démon | `imou-api-vs-imouapi.md` |
| Limites/quota IMOU (palier 5 appareils, QPS, latence app) | `imou-api-vs-imouapi.md` § Limites à connaître |
| Prérequis d'appel (`accessType=PaaS`), mapping objectif → `enableType` | `imou-api-vs-imouapi.md` § 1 |
| Comportement empirique constaté en prod (quota à l'appel, propagation…) | `imou-api-vs-imouapi.md` § Limites à connaître |
| Appareil IoT « Things » : productId, getProductModel, iotDeviceControl, services vs propriétés (sirène manuelle, PTZ, projecteur minuterie…) | `imou-iot-things-model.md` |
| Une capacité échoue en `setDeviceCameraStatus` (ex. 40999) — propriété en lecture seule, piloter par service IoT | `imou-iot-things-model.md` §§ 1, 3 |
| Contrat exact `set/getIotDeviceProperties` (clé=`ref`, lecture batch, pas de channelId, casse JSON du modèle, enum/int→select/slider) | `imou-iot-things-model.md` § 6 |
| Régler une propriété `bool` IoT non capability-switch (flip/WDR/LED/suivi… ; whitelist `iotBoolCatalog`, valeur entière 1/0, préfixe `iotsw_`) | `imou-iot-things-model.md` §§ 3, 6 (UC24) |
| Lire la sortie d'un service `Get…` (iotDeviceControl → `result.data.content.outputData` clé=ref ; ex. compte à rebours projecteur minuté ; identifiant de sortie non documenté) | `imou-iot-things-model.md` § 3 |
| PTZ : quelle voie (HTTP `controlMovePTZ` universel vs services IoT `PtzStepMoveFour`), codes `operation`, sémantique `duration`, tokens d'ability PTZ (PT/PTZ/PT1/PT2/ZoomFocus/CollectionPoint) | `imou-iot-things-model.md` § 7 |
| Que renvoie `getIotDeviceDetailInfo` ? Peut-on lire toutes les valeurs IoT en 1 appel pour économiser le quota du polling ? Parité endpoints avec l'intégration officielle Home Assistant (`pyimouapi`) | `imou-home-assistant-comparaison.md` (⚠️ §4/§5.4 : detailInfo = **capacités `abilityRefs`**, PAS un snapshot de valeurs ; UC76 close) |
| Comparaison avec l'officiel Imou (HA) : endpoints non utilisés par Jeedom (batterie/réveil, online, restart, stockage, vision nocturne legacy, redirection `currentDomain`), points où Jeedom est en avance | `imou-home-assistant-comparaison.md` |

> Si aucun fichier ne couvre le sujet : ce n'est pas (encore) analysé en interne → passer à la doc
> externe (`.memory/external/doc/imou|jeedom/INDEX.md`), et penser à capitaliser en Étape 12.

---

## 1. Catalogue des analyses

| Fichier | Sujet | Points clés indexés |
|---|---|---|
| `imou-api-vs-imouapi.md` | Décision d'architecture : appeler l'IMOU Open API **directement en PHP** (Solution A, sans démon) plutôt que via le wrapper Python `imouapi`. | Fondation API commune (transport, auth, signature md5, token) ; mapping objectif → `enableType` (`closeCamera` inversé, `motionDetect`…) ; **limites** : 5 appareils gratuit (quota qui frappe **à l'appel**, pas à la découverte), `accessType=PaaS` requis, pas de push (polling), QPS non public, latence app→cloud ; recommandation + esquisse Jeedom. |
| `imou-iot-things-model.md` | Modèle IoT « Things » des caméras IMOU (mécanisme + capacités découvertes Cruiser 2C). | `productId` = marqueur IoT ; 3 dimensions (properties/services/events) ; **règle de pilotage par type** (setDeviceCameraStatus vs setIotDeviceProperties vs iotDeviceControl) ; pourquoi `siren` échoue en 40999 (propriété read-only) → services `SirenStart/Stop` ; catalogue réutilisable services+propriétés+events ; implications roadmap ; **§ 7 PTZ : `controlMovePTZ` HTTP universel (≠ services IoT), codes `operation`, `duration` borne le mouvement, tokens d'ability PTZ**. |
| `jeedom-panel-page-menu.md` | Page de plugin au **menu** Jeedom (panel) & toggle d'affichage **natif** du core (UC27). | `info.json "display"`/`"mobile"` enregistre une page-panneau au menu d'accueil (≠ page de gestion, ≠ widget) ; le core ajoute nativement les cases « Afficher le panneau desktop/mobile » (clés `displayDesktopPanel`/`displayMobilePanel`, **masqué par défaut**) → **aucun toggle custom à coder** ; `plugin::getDisplay()` statique (pas de condition) ; page panel = `isConnect()` non-admin + accès par eqLogic `hasRight('r')` + sélection par équipement `isVisiblePanel` (pattern GSL) ; réf. `jeedom/plugin-gsl`. |
| `imou-home-assistant-comparaison.md` | Étude comparative plugin Jeedom vs intégration officielle Imou Home Assistant (`imou_life` / lib `pyimouapi==1.2.8`). | Parité (signature, token, découverte, switches, PTZ, live, snapshot, IoT) ; points où Jeedom **dépasse** l'officiel (quota/licence, logs, token chiffré, auto-découverte IoT générique) ; endpoints HA **absents** de Jeedom → UC (batterie+réveil UC57, `deviceOnline` UC71, `restartDevice` UC52, `deviceStorage` UC41, vision nocturne legacy UC23 ext., redirection `currentDomain` UC72) ; **⚠️ §4 & §5.4 corrigés (2026-06-24, UC76)** : `getIotDeviceDetailInfo` = **découverte de capacités `abilityRefs`** (device + canal), appelé à la découverte, **PAS** un snapshot de valeurs → la piste « N→1 appels de polling » est **invalide**, UC76 close sans code. |
| `jeedom-widgets-commandes.md` | Widgets de commande Jeedom (templates dashboard/mobile), vérifié contre la source du core (UC25). | Fichier `cmd.<type>.<subType>.<nom>.html` + `setTemplate('imou::<nom>')` ; tokens dispo (`#id#`/`#logicalId#`/`#eqLogic_id#`/`#uid#`/`#value_id#`/`#state#`) ; **`#cmd_id[…]#` et `jeedom.cmd.byEqLogicId` n'existent PAS** ; résolution des commandes sœurs par AJAX **`byEqLogic`** (renvoie tout, incl. masquées) → **masqué ≠ non-exécutable** ; `jeedom.cmd.execute` gère CSRF/droits, `success.result` = retour PHP de l'action ; auth core 4.4+ (session + POST forcé) ; AJAX plugin **admin-only** inutilisable au dashboard ; template posé **« si vide »**. **§ 7 GOTCHA CSP : le navigateur bloque tout média/image EXTERNE (`default-src 'self'`) → proxy same-origin obligatoire** ; UC25 live-snapshot = `<img>` → `core/ajax/imouStream.ajax.php` (isConnect+`hasRight('r')`) qui extrait des frames du HLS via **ffmpeg** (`proc_open` mode tableau), auto-cadencé, quota ~0/frame (URL HLS en cache). |
