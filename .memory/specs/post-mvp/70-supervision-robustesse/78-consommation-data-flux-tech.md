# Spec technique — UC78 Estimation de la consommation de data du flux live

> Indicateur **estimatif / informatif / non actionnable**. Mesure = **temps de visionnage live ×
> débit supposé**, instrumentée dans le chemin **live-snapshot** (UC25). Réutilise la **période** UC74
> (même jour de reset). Aucun appel cloud nouveau. La conso réelle reste celle du **portail IMOU**.

## Architecture
Fichiers touchés :
- **`core/class/imou.class.php`** (classe `imou`) :
  - `recordLiveData($deviceId, $channelId)` — accumulation temps×débit (appelée par l'AJAX frame),
  - `dataConfig()` — config en cache statique, `readDataPeriod($periodStart)`, `getDataStats()`,
    ligne(s) `health()`, constantes.
  - Réutilise `currentQuotaPeriodStart()` / `quotaPeriodStart()` (UC74) — **pas de duplication**.
- **`core/ajax/imouStream.ajax.php`** (UC25) : 1 appel à `imou::recordLiveData(...)` **après** qu'une frame
  a été servie avec succès (op défaut), sous `try/catch` non bloquant.
- **`plugin_info/configuration.txt`** (→ recopie `configuration.php`) : fieldset « Consommation data flux »
  (2 champs config + bloc lecture seule + **mention estimation/portail**).
- **i18n** : chaînes FR enveloppées (`{{...}}`/`__()`), traduction déléguée (étape translator).

Aucun nouvel appel à l'IMOU Open API. Aucune modif `install.php` (défauts via `config::byKey(..., défaut)`).

## Server vs Client
100 % **serveur** (PHP). Mesure dans l'AJAX frame (serveur) ; restitution rendue serveur dans
`configuration.php` et `health()`. Pas de JS dédié (champs config auto-sauvés via `configKey` du core).

## Modèle de données (cache Jeedom)
- **Période** : clé `imou::data::period::<AAAA-MM-JJ début>` → `{ bytes:int }`. TTL `DATA_PERIOD_TTL` (~70 j).
  `<début>` = `imou::currentQuotaPeriodStart()` (UC74) → **même fenêtre anniversaire / même reset**.
- **Dernière frame / caméra** : clé `imou::data::lastframe::<deviceId>_<channelId>` → timestamp (int).
  TTL `LIVE_FRAME_TTL` (≈ `LIVE_SESSION_GAP_MAX` + marge) : un trou de visionnage fait expirer la clé →
  pas d'accumulation parasite à la reprise.
- Préfixes `period::` / `lastframe::` disjoints, et disjoints des préfixes UC74 (`stats::`).
- **Best-effort** : RMW non atomique (perte d'incrément rare) + purge cache → remise à zéro. Assumé/documenté.

## Calcul (recordLiveData)
```
now      = time()
key      = LIVE_FRAME_PREFIX . deviceId . '_' . channelId
last     = (int) cache::byKey(key)->getValue(0)
cache::set(key, now, LIVE_FRAME_TTL)            // toujours réarmer
if (last <= 0) return                            // 1re frame de session → pas de delta
delta = now - last
if (delta <= 0 || delta > LIVE_SESSION_GAP_MAX) return   // gap/horloge → nouvelle session
bytesPerSec = max(0, dataConfig()['bitrateKbps']) * 1000 / 8
add         = (int) round(delta * bytesPerSec)
if (add <= 0) return
// RMW sur le blob de période
ps = imou::currentQuotaPeriodStart()
blob = readDataPeriod(ps) ; blob['bytes'] += add
cache::set(DATA_PERIOD_PREFIX . ps, json_encode(blob), DATA_PERIOD_TTL)
```
Le tout dans **`try/catch Throwable`** ; ne lève **jamais** (le service de frame prime).

## Config (niveau plugin, 'imou')
| Clé | Défaut | Domaine | Rôle |
|---|---|---|---|
| `dataQuotaGo` | 3 | ≥0 (step 0.5) | Quota data mensuel en Go. 0 = inconnu → pas de % ni jauge. |
| `dataBitrateKbps` | 1500 | ≥0 | Débit estimé du flux (kbit/s). Paramètre de **calibrage** vs portail. 0 = estimation off. |
| `quotaResetDay` | (UC74) | 1–31 | **Réutilisé tel quel** — aucun nouveau champ. Même reset que le quota d'appels. |

Lecture en **cache statique PHP** (`imou::dataConfig()`), 1 lecture DB/process (comme `quotaConfig` UC74).

## Server Actions / API (signatures)
- `imou::recordLiveData($deviceId, $channelId)` *(publique statique)* — cf. Calcul. `try/catch Throwable`,
  ne lève jamais. Garde `deviceId` non vide (sinon no-op) ; `channelId` défaut `'0'`.
- `imou::dataConfig()` *(privée, cache statique)* → `['quotaGo'=>float,'bitrateKbps'=>int]` (clampés ≥0).
- `imou::readDataPeriod($periodStart)` *(publique)* → `['bytes'=>int]`. Guard **regex calendaire ancrée**
  `/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])$/` sur la date ; accès cache/JSON défensifs.
- `imou::getDataStats()` → agrégat UI : `periodStart, bytes, go (bytes/2^30, 2 déc.), quotaGo,
  pct|null (null si quotaGo=0), bitrateKbps`.
- `imou::health()` : **1 ligne** ajoutée — « Data flux live estimée (période) » →
  valeur `X,XX Go / 3 Go (Y %) · estimation — voir portail IMOU` ; `state = (pct === null) ? true : pct < 100`.
  (Pas d'alerte bloquante : informatif. Si `quotaGo=0` → `X,XX Go · estimation`, state=true.)

## Validation
- **Serveur** : domaines config clampés à la lecture (`dataQuotaGo≥0`, `dataBitrateKbps≥0`) ; `deviceId`/
  `channelId` assainis (déjà validés en amont par l'AJAX UC25 qui résout l'eqLogic `imou`) ; date du reader
  validée par regex ; tous les accès cache/JSON défensifs (`is_array`, `intval`) ; **non bloquant** (Throwable
  avalé). Le `delta` est borné par `LIVE_SESSION_GAP_MAX` → pas d'accumulation aberrante sur saut d'horloge.
- **Client** : champs `number` `min/step` dans le formulaire (confort, non fiable seul).

## Constantes (imou)
`DATA_PERIOD_PREFIX='imou::data::period::'`, `LIVE_FRAME_PREFIX='imou::data::lastframe::'`,
`DATA_PERIOD_TTL=70*86400`, `LIVE_FRAME_TTL=60`, `LIVE_SESSION_GAP_MAX=20` (s).

## Dépendances
Aucune (PHP natif + classes core `cache`/`config`/`log`). Pas de paquet. La machinerie de période vient de
UC74 (doit être livrée avant ou avec).

## Hors scope
Régulation/bridage du flux (non actionnable), conso app mobile / lecteur externe (non mesurable), clichés
(UC55, négligeable + déjà comptés en appels UC74), alerte bloquante, lecture via une éventuelle API IMOU
future de remplacement des `flow*`.
