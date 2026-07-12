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

// UC32 — Proxy same-origin de la tuile carte (widget dashboard « Mes véhicules »). La CSP Jeedom bloque
// toute image externe (<img src=osm/...>) : ce endpoint récupère la tuile côté serveur et la relaie,
// servie depuis la même origine que le reste de l'application (cf. jeedom-widgets-commandes.md § 7).
//
// ⚠️ Garde-fou autoload (CLAUDE.md) : point d'entrée EXTERNE → n'appelle QUE stellantis:: (jamais
// stellantisApi::/stellantisException directement). Toute la logique (garde d'accès, fetch, cache,
// validation) vit dans stellantis::renderStaticMap(), qui NE LÈVE JAMAIS (toujours un PNG en retour,
// placeholder au pire) : ce fichier n'a donc pas besoin d'un mapping d'exceptions élaboré.
//
// Contrôle d'accès : isConnect() (utilisateur connecté suffit, PAS admin — usage quotidien du panel/
// dashboard). Le contrôle fin par véhicule (hasRight('r'), équipement activé) est fait DANS
// renderStaticMap() (défense en profondeur : ce fichier ne fait pas confiance à un futur appelant qui
// oublierait la vérification).
//
// Pas de jeton CSRF requis : lecture GET simple, sans effet de bord (cf. jeedom-widgets-commandes.md § 5).

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect()) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    $resultat = stellantis::renderStaticMap((int) init('eqLogic_id'));
    header('Content-Type: ' . $resultat['type']);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: max-age=300, private');
    echo $resultat['body'];
    die();
} catch (Exception $e) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo displayException($e);
    die();
}
