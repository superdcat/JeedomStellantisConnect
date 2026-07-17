<?php
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

// Fonction exécutée automatiquement après l'installation du plugin
function stellantis_install() {
}

// Fonction exécutée automatiquement après la mise à jour du plugin
function stellantis_update() {
  // UC11 : arrêter le démon avant relance (le core le redémarre ensuite si hasOwnDeamon). Best-effort.
  try {
    stellantis::deamon_stop();
  } catch (\Throwable $e) {
    log::add('stellantis', 'warning', 'Mise à jour : arrêt du démon ignoré (' . $e->getMessage() . ')');
  }
  // UC23 : après scission autonomy/autonomy_fuel, l'ancienne cmd 'autonomy' d'un THERMIQUE PUR (alimentée
  // autrefois par le carburant) se fige. La masquer pour éviter le doublon avec 'autonomy_fuel'. Best-effort,
  // idempotent, thermique UNIQUEMENT (hybride/électrique : 'autonomy' reste l'autonomie élec, ne pas toucher).
  try {
    foreach (eqLogic::byType('stellantis') as $eq) {
      if (trim((string) $eq->getConfiguration('energy', '')) !== 'Thermal') { continue; }
      $cmd = $eq->getCmd('info', 'autonomy');
      if (is_object($cmd) && $cmd->getIsVisible() == 1) {
        $cmd->setIsVisible(0);
        $cmd->save();
      }
    }
  } catch (\Throwable $e) {
    log::add('stellantis', 'warning', 'Mise à jour UC23 : masquage autonomy thermique ignoré (' . $e->getMessage() . ')');
  }
  // UC32 : backfill du panneau carte pour les installs antérieures qui ne re-synchronisent pas
  // immédiatement (assurerVisiblePanelParDefaut + assurerTemplatePositionParDefaut, helpers partagés
  // avec createCommands()/syncVehicles() — pas de logique dupliquée ici). Best-effort, idempotent,
  // borné à eqLogic::byType('stellantis') — un véhicule en erreur n'interrompt pas la boucle (même
  // convention que le reste du fichier).
  foreach (eqLogic::byType('stellantis') as $eq) {
    try {
      if (stellantis::assurerVisiblePanelParDefaut($eq)) {
        $eq->save();
      }
      $cmdPosition = $eq->getCmd('info', 'position');
      if (is_object($cmdPosition) && stellantis::assurerTemplatePositionParDefaut($cmdPosition)) {
        $cmdPosition->save();
      }
    } catch (\Throwable $e) {
      log::add('stellantis', 'warning', 'Mise à jour UC32 : backfill panneau carte ignoré pour un équipement (' . $e->getMessage() . ')');
    }
  }
  // UC54 : backfill 'accountSlot'/'accountSlotLabel' = 1/« Compte principal » pour les véhicules
  // antérieurs à la fonctionnalité multi-comptes (jamais de régression — cf.
  // assurerAccountSlotParDefaut : ne touche jamais un véhicule déjà routé vers un compte). Best-effort,
  // idempotent, boucle INDÉPENDANTE (un échec ici ne doit pas empêcher les backfills ci-dessus).
  foreach (eqLogic::byType('stellantis') as $eq) {
    try {
      if (stellantis::assurerAccountSlotParDefaut($eq)) {
        $eq->save();
      }
    } catch (\Throwable $e) {
      log::add('stellantis', 'warning', 'Mise à jour UC54 : backfill compte ignoré pour un équipement (' . $e->getMessage() . ')');
    }
  }
  // UC76 : backfill du défaut « inclure dans le rafraîchissement automatique » (syncEnabled=1) pour les
  // véhicules antérieurs à la synchronisation sélective (mêmes précédent/garanties qu'assurerVisiblePanelParDefaut
  // ci-dessus : ne pose la clé QUE si absente, jamais de régression sur un choix déjà exprimé). Best-effort,
  // idempotent, boucle INDÉPENDANTE (un échec ici ne doit pas empêcher les backfills ci-dessus). Sans lien
  // avec le marqueur 'autoDisabled' (posé uniquement par syncVehicles() lors d'une future désactivation).
  foreach (eqLogic::byType('stellantis') as $eq) {
    try {
      if (stellantis::assurerSyncEnabledParDefaut($eq)) {
        $eq->save();
      }
    } catch (\Throwable $e) {
      log::add('stellantis', 'warning', 'Mise à jour UC76 : backfill rafraîchissement auto ignoré pour un équipement (' . $e->getMessage() . ')');
    }
  }
}

// Fonction exécutée automatiquement après la suppression du plugin
function stellantis_remove() {
  // UC11 : ne jamais laisser un process démon orphelin après désinstallation. Best-effort.
  try {
    stellantis::deamon_stop();
  } catch (\Throwable $e) {
    log::add('stellantis', 'warning', 'Suppression : arrêt du démon ignoré (' . $e->getMessage() . ')');
  }
  // UC12 : hygiène — ne pas laisser survivre le remote token / device OTP (équivalents mot de passe).
  try {
    stellantis::purgeOtp();
  } catch (\Throwable $e) {
    log::add('stellantis', 'warning', 'Suppression : purge OTP ignorée (' . $e->getMessage() . ')');
  }
}
