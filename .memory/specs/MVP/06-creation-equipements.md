# 06 — Création / mise à jour des équipements (eqLogic)

**Phase :** MVP · **Dépend de :** 05 · **Fichiers :** `core/class/imou.class.php`, `core/ajax/imou.ajax.php`, `desktop/php/imou.php`, `desktop/js/imou.js`

## Objectif
Transformer la découverte (tâche 05) en équipements Jeedom : un bouton « Synchroniser » crée
ou met à jour les eqLogic IMOU sans doublon.

## Périmètre
- **Inclus** : bouton « Synchroniser », méthode de sync, idempotence, stockage deviceId/channelId.
- **Exclu** : commandes (tâche 07), polling (tâche 10).

## Détails techniques
- Identité d'un équipement = `logicalId = "<deviceId>_<channelId>"` (clé d'idempotence).
- Méthode `imou::syncEquipments(): array` :
  - pour chaque device découvert, `eqLogic::byLogicalId($logicalId, 'imou')` ; créer si absent.
  - renseigner `name` (si création), `eqType_name = 'imou'`, `isEnable`, `logicalId`,
    configuration `deviceId`, `channelId`, `model`, `ability` ; `save()`.
  - ne pas écraser le nom personnalisé par l'utilisateur lors d'une re-sync.
- UI : bouton « Synchroniser les caméras IMOU » sur la page plugin (`desktop/php/imou.php`),
  action AJAX `action=sync`, puis rafraîchir la liste des eqLogic.
- Conserver les champs de config standard par équipement (objet parent, activer/visible, etc.).

## Critères d'acceptation
- [ ] La 1re synchro crée un équipement par caméra/canal.
- [ ] Une 2e synchro ne crée pas de doublon et préserve le nom + l'objet parent personnalisés.
- [ ] Les champs deviceId/channelId/ability sont visibles dans la config avancée de l'équipement.

## Notes / risques
- Décider du sort d'un équipement dont la caméra a disparu du compte (désactiver plutôt que supprimer — voir tâche 11).
