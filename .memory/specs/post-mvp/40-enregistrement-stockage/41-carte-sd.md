# UC — Carte SD (état & gestion)

**Domaine :** Enregistrement & stockage · **Dépend de :** MVP/06 · **Statut endpoints :** à confirmer

## Objectif / valeur
Connaître l'état de la **carte SD** (présence, capacité, % utilisé) et pouvoir la **formater**
depuis Jeedom — surveillance de la santé du stockage local.

## Ce que permet l'API
- **`deviceStorage`** (`deviceId`) → capacité de stockage : `usedBytes` / `totalBytes`
  (→ % occupé = `usedBytes*100/totalBytes`). Codes notables : **`DV1049`** = pas de média / pas de carte ;
  état anormal possible. **Confirmé par l'officiel** (capteur « storage used »), cf.
  `.memory/analyse/imou-home-assistant-comparaison.md` (§3.4). Gating : ability **`LocalStorage`** /
  **`LocalStorageEnable`** ou ref IoT **`14600`**.
- **`deviceSdcardStatus`** (`deviceId`) — statut détaillé de la carte SD. ⚠️ Endpoint **déclaré mais
  non utilisé** dans l'officiel (1.2.8) : à valider sur matériel réel avant de s'en servir.
- Action de formatage de la carte SD (endpoint **à confirmer** — non couvert par l'officiel).

## Esquisse Jeedom
- Commandes info : `sd_present` (binary, dérivé de `DV1049`), `sd_used_percent` (numeric, via `deviceStorage`),
  `sd_total` (numeric, `totalBytes`).
- Commande action : `sd_format` (avec confirmation côté UI) — sous réserve de confirmer l'endpoint.
- Rafraîchies dans le cron (basse fréquence, ex. `cronHourly`) ; coupler au skip-offline (UC71).

## Critères d'acceptation
- [ ] L'état SD (présence + occupation) est correct et visible.
- [ ] Le formatage fonctionne et est protégé contre le déclenchement accidentel.

## À confirmer
- ~~Endpoint de lecture~~ → **`deviceStorage` confirmé** (octets). Reste : endpoint de **formatage**
  (non couvert par l'officiel) et fiabilité de `deviceSdcardStatus`.
