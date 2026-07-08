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

// Point d'entrée du callback démon MQTT → Jeedom (UC11). Le démon (jeedom_com) poste ici en HTTP les
// messages MQTT reçus et les événements de connexion. Joignable en HTTP grâce à l'exception <Files> du
// .htaccess de core/php (le reste du dossier reste Deny from all).
try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    // Autoload : stellantis:: en premier (charge stellantis.class.php avant tout stellantisApi::)
    if (!jeedom::apiAccess(init('apikey'), 'stellantis')) {
        // Jamais de détail : requête non authentifiée
        die();
    }
    $data = json_decode(file_get_contents('php://input'), true);
    // Le démon enveloppe chaque événement sous la clé 'stellantis_daemon' (cf. demond.py _notify)
    if (is_array($data) && isset($data['stellantis_daemon']) && is_array($data['stellantis_daemon'])) {
        stellantis::handleDaemonMessage($data['stellantis_daemon']);
    }
    // Toujours répondre 200 + statut applicatif : le jeedom_com du démon n'a que 3 retries, un code
    // HTTP d'erreur ferait perdre le message silencieusement.
    echo json_encode(array('state' => 'ok'));
} catch (Throwable $e) {
    // core.inc.php est chargé si on arrive ici après le require ; sinon log:: peut ne pas exister.
    if (class_exists('log')) {
        log::add('stellantis', 'error', 'Callback démon : ' . $e->getMessage());
    }
    echo json_encode(array('state' => 'error'));
}
