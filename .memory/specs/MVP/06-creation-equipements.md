# 06 — Création / mise à jour des équipements (eqLogic)

**Phase :** MVP · **Dépend de :** 05 · **Fichiers :** `core/class/stellantis.class.php`, `core/ajax/stellantis.ajax.php`, `desktop/php/stellantis.php`, `desktop/js/stellantis.js`

## Objectif
Transformer la découverte (tâche 05) en équipements Jeedom : un bouton « Synchroniser » crée ou met à
jour les eqLogic véhicules **sans doublon**.

## Périmètre
- **Inclus** : bouton « Synchroniser les véhicules », méthode de sync, idempotence, stockage id/VIN/marque.
- **Exclu** : commandes info (→ 07), polling (→ 08).

## Détails techniques
- **Identité d'un équipement = `logicalId = VIN`** (clé d'idempotence stable).
- Méthode `stellantis::syncVehicles(): array` :
  - pour chaque véhicule découvert, `eqLogic::byLogicalId($vin, 'stellantis')` ; créer si absent.
  - renseigner `name` (si création : `brand` + `label` — pas de champ `model` dans l'API, cf. UC05
    2026-07-06 ; `label` = surnom renommable côté app), `eqType_name='stellantis'`, `isEnable`,
    `logicalId=$vin`, configuration `apiId` (id API), `vin`, `brand`, `energy` (vocabulaire normalisé
    UC05 : Electric/Thermal/Hybrid/'', **indicatif** — source de vérité au fil de l'eau = `/status`
    UC07, même vocabulaire) ; `save()`.
  - **ne pas écraser** le nom personnalisé ni l'objet parent lors d'une re-sync.
- UI : bouton « Synchroniser les véhicules » (page plugin), action AJAX `sync`, puis rafraîchir la liste.
- Conserver les champs de config standard par équipement (objet parent, activer/visible).

## Critères d'acceptation
- [ ] La 1re synchro crée un équipement par véhicule (clé VIN).
- [ ] Une 2e synchro ne crée pas de doublon et préserve nom + objet parent personnalisés.
- [ ] `apiId`/`vin`/`brand`/motorisation sont visibles dans la config avancée de l'équipement.

## Notes / risques
- Décider du sort d'un véhicule disparu du compte : **désactiver** plutôt que supprimer (cf. UC76 sync
  sélective).
- `apiId` (≠ VIN) est requis pour les appels REST suivants : bien le stocker en configuration.
