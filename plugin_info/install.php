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
