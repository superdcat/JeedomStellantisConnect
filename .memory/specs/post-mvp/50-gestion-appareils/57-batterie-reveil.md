# UC — Niveau de batterie & réveil d'appareil dormant

**Domaine :** Gestion des appareils · **Dépend de :** MVP/06-07, MVP/10 (cron) · **Statut endpoints :** confirmé (officiel HA)

> Issue de l'étude comparative `.memory/analyse/imou-home-assistant-comparaison.md` (§3.1, §5.1).
> Endpoints confirmés par l'implémentation officielle Imou pour Home Assistant (lib `pyimouapi` 1.2.8).

## Objectif / valeur
Exposer le **niveau de batterie** des caméras sur batterie (très répandues chez IMOU) et **réveiller**
un appareil dormant pour pouvoir le piloter/le lire. Donnée totalement absente aujourd'hui : ce n'est
**pas** une propriété IoT, donc **non couverte par l'auto-découverte UC13** — d'où une UC dédiée.

## Ce que permet l'API
- **`getDevicePowerInfo`** (`deviceId`) → tableau `electricitys[]` :
  - `electric` = pourcentage de charge (0-100) ;
  - `litElec` / `alkElec` = niveau pile lithium / alcaline (modèles multi-cellules) ;
  - `type` = type d'alimentation.
- **`wakeUpDevice`** (`deviceId`, `url:"/device/wakeup"`) → réveille un appareil en veille.
- **Code `DV1030`** = appareil dormant : l'officiel déclenche `wakeUpDevice` puis **rejoue une fois**
  la lecture (`getDevicePowerInfo`).
- Capacités de gating : ability **`Electric`** (caméras non-IoT) ou refs IoT **`11600`** / **`106200`**.

## Esquisse Jeedom
- Commande **info numérique `battery`** (%) créée si la capacité `Electric`/`11600`/`106200` est présente
  (gating dans `createCommands`, calqué sur les autres infos d'état).
- Optionnel : info `battery_type` (lithium/alcaline) et seuil **batterie faible** (warning + commande info
  binaire `battery_low`).
- **Rafraîchissement** : dans le polling existant (`cron5`/`refreshStates`), **basse fréquence** suffit.
  Sur code `DV1030`, appeler `wakeUpDevice` (1 tentative bornée) puis relire — réutilise le pattern de
  rejeu unique déjà présent dans `imouApi`.
- **Quota** : 1 appel `getDevicePowerInfo` par caméra batterie et par cycle de poll → coupler avec le
  skip-offline (UC71) et la sync sélective (UC73) pour limiter le coût.

## Critères d'acceptation
- [ ] Une caméra sur batterie expose une info `battery` (%) cohérente avec l'app IMOU.
- [ ] La commande/info est absente pour les caméras secteur (pas de capacité batterie).
- [ ] Un appareil dormant (`DV1030`) est réveillé puis sa batterie est lue (pas d'échec silencieux définitif).
- [ ] Le polling batterie ne casse jamais le cycle cron (instrumentation non bloquante).

## À confirmer
- Champs exacts retournés selon modèle (mono- vs multi-cellules) ; unité de `electric`.
- Comportement de `wakeUpDevice` (délai avant que la lecture suivante aboutisse) — l'officiel relit
  immédiatement après réveil ; prévoir une marge si nécessaire.
- Faut-il une commande **action** « réveiller » exposée à l'utilisateur, ou réveil purement interne ?
