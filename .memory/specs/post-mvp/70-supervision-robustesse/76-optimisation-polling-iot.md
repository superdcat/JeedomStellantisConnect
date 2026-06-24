# UC — Optimisation du polling IoT (lecture détaillée groupée)

**Domaine :** Supervision / robustesse · **Dépend de :** UC13 (socle IoT), MVP/10 (cron) · **Complémentaire de :** UC71 (skip-offline), UC73 (sync sélective), UC74 (quota) · **Statut :** ❌ **CLÔTURÉE SANS IMPLÉMENTATION (2026-06-24) — prémisse invalide**

> Issue de l'étude comparative `.memory/analyse/imou-home-assistant-comparaison.md` (§4, §5.4).

> ## ❌ Décision 2026-06-24 — UC abandonnée (prémisse erronée, gain résiduel non justificatif)
>
> **La prémisse de cette UC est fausse.** Vérification ligne par ligne dans `pyimouapi==1.2.8` (la lib dont
> dépend l'intégration officielle Imou Home Assistant — `manifest.json : "pyimouapi==1.2.8"`) :
> - `getIotDeviceDetailInfo(deviceId, productId)` renvoie les **capacités** (`abilityRefs` au niveau device
>   **et** `channels[].abilityRefs`), **PAS** un snapshot des valeurs de propriétés ;
> - son unique consommateur (`_async_update_device_ability_refs`) ne lit **que** `abilityRefs`, et il est
>   appelé **à la DÉCOUVERTE** (`async_get_devices`), pas à chaque cycle de poll ;
> - les **valeurs** courantes passent, comme côté Jeedom, par **`getIotDeviceProperties`** (batch keyé par `ref`).
>
> ⇒ Il n'existe **aucun** raccourci « toutes les valeurs IoT en 1 appel ». `getIotDeviceDetailInfo` est
> l'**équivalent fonctionnel** du `getProductModel`+ability déjà utilisé par Jeedom.
>
> **Seul levier confirmé** (non retenu) : fusionner les deux lectures `getIotDeviceProperties`
> (`refreshIotProperties` enum/int + `refreshIotBoolProperties` bool) en **un** appel batch. Gain
> **marginal** : par défaut ces infos sont `noPoll=1` (UC75) → **0 appel** ; le gain 2→1 ne joue que si
> l'utilisateur a activé le polling des **deux** catégories sur une caméra. Risque (collision de `ref`
> cross-catégorie, perte d'isolation des responsabilités) > bénéfice. `refreshIotServiceStatuses` (services
> `Get…`) reste non fusionnable.
>
> **Action retenue** : conserver le code actuel (déjà batché par catégorie, défensif, `noPoll` par défaut).
> Si un vrai poste de quota IoT émerge un jour, rouvrir sur **données réelles** (instrumentation UC74).
> Détail & sources : `.memory/analyse/imou-home-assistant-comparaison.md` §4 & §5.4 (corrigés 2026-06-24).
>
> Le reste de cette spec est conservé **à titre historique** (prémisse d'origine), barré par la présente décision.

## Objectif / valeur
Réduire le **nombre d'appels API** du polling des caméras IoT « Things ». Aujourd'hui un cycle IoT fait
**plusieurs appels** par équipement (`refreshIotProperties` + `refreshIotBoolProperties`
+ `refreshIotServiceStatuses`, chacun un `getIotDeviceProperties`). L'officiel lit **toutes les
propriétés d'un device en UN seul appel** via `getIotDeviceDetailInfo`, puis parse localement. Gain
direct de quota (cf. palier gratuit / quotas non publics) et de latence.

## Ce que permet l'API
- **`getIotDeviceDetailInfo`** (`deviceId`, `productId`) → renvoie en une fois :
  - `abilityRefs` (capacités) au niveau device **et** par canal (`channels[].abilityRefs`) ;
  - un **snapshot complet des `properties`** (toutes les valeurs courantes).
- L'officiel s'en sert à la fois pour la **découverte des capacités** ET pour le **rafraîchissement
  d'état** (un appel couvre switches IoT, selects, sensors, binary, text).

## Esquisse Jeedom
- **Polling** : remplacer les N `getIotDeviceProperties` par **1 `getIotDeviceDetailInfo`** par device IoT
  et par cycle, puis dispatcher les valeurs vers les commandes info (`iot_*_state`, `iotsw_*_state`,
  statuts de service) en lisant le snapshot `properties`.
- **Découverte (option)** : `getIotDeviceDetailInfo` pourrait aussi alimenter la création des commandes
  (UC13) — à arbitrer avec le `getProductModel` actuel (cache 7j) qui fournit le **modèle/labels** ;
  `getIotDeviceDetailInfo` fournit les **valeurs + refs réellement présents sur CE device**. Les deux
  sont complémentaires (modèle = schéma, detailInfo = instance).
- **Décision sur données réelles** : instrumenter via UC74 pour **mesurer** `1×getIotDeviceDetailInfo`
  vs `N×getIotDeviceProperties` (selon la facturation réelle au quota) avant de basculer.
- Conserver le **batch par catégorie** actuel comme repli si `getIotDeviceDetailInfo` s'avère plus coûteux
  ou indisponible sur certains modèles.

## Critères d'acceptation
- [ ] Le polling d'une caméra IoT consomme moins d'appels qu'avant (mesuré via UC74).
- [ ] Toutes les commandes info IoT (switches/selects/sliders/statuts) restent correctement rafraîchies.
- [ ] Le repli `getIotDeviceProperties` fonctionne si `getIotDeviceDetailInfo` échoue/est absent.
- [ ] Aucun appel supplémentaire pour les caméras non-IoT (pas de `productId`).

## À confirmer
- Format exact du snapshot `properties` (clés = refs ? labels ?) et correspondance avec les `ref` utilisés
  en écriture (`setIotDeviceProperties`).
- Disponibilité de `getIotDeviceDetailInfo` sur tous les modèles IoT ciblés (recouper avec `getProductModel`).
- Coût quota réel d'un `getIotDeviceDetailInfo` vs plusieurs `getIotDeviceProperties`.
