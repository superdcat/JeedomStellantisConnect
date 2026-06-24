# UC — Estimation de la consommation de data du flux live

**Domaine :** Supervision / robustesse · **Dépend de :** UC74 (machinerie de période + pattern config), UC22/UC25 (flux live « live-snapshot ») · **Statut :** à spécifier (tech ci-contre)

## ⚠️ Cadre : INDICATEUR ESTIMATIF, INFORMATIF, NON ACTIONNABLE
Cette UC fournit un **ordre de grandeur** de la data flux consommée, **purement informatif** :
- **Estimatif** — calculé à partir du **temps de visionnage live** × un **débit supposé** (configurable),
  **pas** d'une mesure réseau réelle. L'overhead HLS/ffmpeg n'est pas mesuré.
- **Partiel** — ne compte **que la data tirée par le plugin lui-même** (mode « live-snapshot » UC25 :
  le serveur Jeedom tire le HLS via ffmpeg). Il **ignore** la consommation de **l'app mobile IMOU**,
  d'un lecteur HLS externe (commande `live_url` UC22), ou de tout autre client → c'est donc un **plancher**
  de la conso du plugin, **sans rapport** avec ce que consomment les autres clients.
- **Non actionnable** — le plugin **ne peut pas** brider ce trafic : c'est **l'utilisateur (ou ses clients)
  qui consomme** en regardant le flux. Aucune régulation possible (contrairement à UC77 sur les *appels*).
- **La vraie consommation fait foi dans le portail développeur IMOU**
  (console → *Distribution Of Daily Traffic Consumption*). Cet indicateur ne s'y substitue pas ;
  il aide juste à **se situer** entre deux consultations du portail.

> Ces réserves doivent apparaître **explicitement dans l'UI** (page de config + libellé Santé) :
> mention « estimation » + renvoi au portail IMOU. Critère d'acceptation dur (cf. plus bas).

## Objectif / valeur
Donner à l'utilisateur un **repère** de la data flux live consommée **par le plugin** sur la **période de
facturation courante**, rapportée à un **quota mensuel** (défaut **3 Go**, palier gratuit IMOU), pour
anticiper grossièrement l'approche du plafond — **sans** prétendre à l'exactitude (cf. cadre ci-dessus).

## Pourquoi estimatif (rappel technique)
L'IMOU Open API **n'expose aucun endpoint exploitable** pour lire la conso data : les interfaces `flow*`
(`flowWarn`/`flowHisDetial`/`flowHisTotal`) sont **non documentées et probablement dépréciées (notice
2018)**, absentes de `pyimouapi`/`imou_life` ; la seule source réelle est le **dashboard console** (UI, pas
d'API). Faute d'API, on **auto-instrumente** côté plugin (cf. `.memory/` note *imou-flow-endpoints-…*).

## Périmètre
- **Inclus** :
  - **Accumulateur** d'octets **estimés** sur la **période courante** (= même fenêtre anniversaire que
    UC74), incrémenté **dans le chemin live-snapshot** (frames servies par `imouStream.ajax.php`).
  - **Configuration plugin** :
    - **Quota data mensuel** en **Go** (`dataQuotaGo`, défaut **3**, éditable ; 0 = quota inconnu → pas de %) ;
    - **Débit estimé** du flux en **kbit/s** (`dataBitrateKbps`, défaut **1500**, éditable) — paramètre de
      calibrage : l'utilisateur l'ajuste pour **rapprocher l'estimation du chiffre réel du portail**.
    - **Jour de reset** : **AUCUN nouveau champ** — réutilise **`quotaResetDay`** de UC74
      (« remise à zéro le même jour que le reste des quotas »).
  - **Restitution** : conso estimée de la **période** (Go + **% du quota** si quota connu), sur la **page de
    configuration plugin** et la **page Santé** Jeedom, **toujours** assortie de la mention « estimation /
    voir portail IMOU ».
- **Exclu** :
  - Toute **régulation/bridage** du flux (impossible — non actionnable).
  - La conso de **l'app mobile IMOU** / d'un **lecteur HLS externe** (non mesurable côté plugin).
  - Les **clichés** (`captureSnapshot`/UC55) : volume négligeable face au flux **et** déjà comptés comme
    *appels* (UC74) ; hors scope ici.
  - La facturation et toute alerte bloquante.

## Esquisse Jeedom
- **Mesure (temps × débit)** : le mode live-snapshot (UC25) sert ~1 frame/1–3 s par caméra via
  `imouStream.ajax.php`. À **chaque frame servie**, on mesure le **temps écoulé depuis la frame précédente**
  de la **même caméra** (mémorisé en cache). Si l'écart est « raisonnable » (≤ `LIVE_SESSION_GAP_MAX`,
  sinon = nouvelle session, on n'accumule pas le trou), on ajoute `écart_s × débit_octets_par_s` à
  l'accumulateur de période. Ce proxy capte le **temps de visionnage réel** sans événement « fin de
  session » explicite (un release/timeout coupe simplement l'accumulation).
- **Débit** : `dataBitrateKbps` → octets/s = `kbps × 1000 / 8`.
- **Fenêtre / reset** : réutilise `imou::currentQuotaPeriodStart()` (UC74) → **même jour de reset** que le
  quota d'appels. Clé période dédiée et disjointe des clés UC74.
- **Instrumentation NON BLOQUANTE** : `try/catch Throwable`, **jamais** d'exception remontée au service de
  frame (critère dur — une frame doit toujours être servie même si la compta échoue).
- **Restitution** : méthode `imou::getDataStats()` (Go + % + débit + portail) ; ligne(s) dans
  `imou::health()` ; bloc lecture seule dans `configuration.{txt,php}`.
- **Limite assumée** : une **purge du cache Jeedom** remet l'accumulateur à zéro → indicateur **best-effort**
  (supervision, pas facturation), documenté — comme UC74.

## Critères d'acceptation
- [ ] Un **accumulateur d'octets estimés** s'incrémente pendant le visionnage live (mode live-snapshot) et
      se **réinitialise le même jour** que le quota d'appels (`quotaResetDay`, UC74).
- [ ] `dataQuotaGo` (défaut **3**) et `dataBitrateKbps` (défaut **1500**) sont **configurables** au niveau plugin.
- [ ] L'utilisateur voit la conso data estimée de la **période** (Go + % si quota connu) en **config** et **Santé**.
- [ ] L'UI **indique explicitement** que la valeur est **estimative** et que **la conso réelle est dans le
      portail IMOU** (mention présente en config **et** dans le libellé Santé). *(critère dur)*
- [ ] La compta **ne casse jamais** le service de frame live (instrumentation non bloquante, jamais d'exception).

## À confirmer
- **Valeur du quota gratuit** (3 Go observé — par défaut, éditable).
- **Débit par défaut** (1500 kbps = repère HD ; à calibrer terrain contre le portail — d'où le champ éditable).
- **Granularité** : un seul accumulateur global plugin (retenu) vs par caméra (non retenu — l'utilisateur
  raisonne sur le quota *compte*).
