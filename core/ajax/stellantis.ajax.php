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

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

  /* Fonction permettant l'envoi de l'entête 'Content-Type: application/json'
    En V3 : indiquer l'argument 'true' pour contrôler le token d'accès Jeedom
    En V4 : autoriser l'exécution d'une méthode 'action' en GET en indiquant le(s) nom(s) de(s) action(s) dans un tableau en argument
  */
    ajax::init();

    // Avant le garde global : testConnection répond TOUJOURS en ajax::success({ok, count, message}),
    // y compris quand le plugin n'est pas configuré (structure uniforme pour le front)
    if (init('action') == 'testConnection') {
        ajax::success(stellantis::testConnection());
    }

    // UC61 : extraction auto des identifiants depuis l'APK. AVANT le garde isConfigured() (on extrait
    // justement client_id/client_secret manquants). Reçoit brand+country du formulaire → l'admin n'a
    // pas à sauvegarder d'abord. Téléchargement ~100 Mo → délai serveur allongé pour cette action.
    if (init('action') == 'extractCredentials') {
        set_time_limit(300);
        ajax::success(stellantis::extractCredentialsFromApk((string) init('brand'), (string) init('country'), (string) init('apk_url')));
    }

    // Garde-fou autoload : appeler stellantis:: AVANT tout stellantisApi::/stellantisException
    // (l'autoloader Jeedom ne connaît que stellantis.class.php — cf. CLAUDE.md)
    if (!stellantis::isConfigured()) {
        throw new Exception(__('Plugin non configuré : renseignez la marque, le Client ID et le Client Secret puis sauvegardez', __FILE__));
    }

    if (init('action') == 'getAuthUrl') {
        $config = stellantis::getApiConfig();
        ajax::success(array(
            'url' => stellantisApi::buildAuthUrl(),
            'redirectUri' => $config['redirectUri'],
        ));
    }

    if (init('action') == 'submitAuthCode') {
        stellantisApi::exchangeCode((string) init('code'));
        ajax::success(__('Authentification réussie : le plugin est connecté à votre compte', __FILE__));
    }

    // UC12 — Activation OTP (pilotage à distance). Structures uniformes {ok, message} (erreurs mappées
    // en interne, comme testConnection/syncVehicles). Admin-only via le garde global ci-dessus.
    if (init('action') == 'requestOtpSms') {
        ajax::success(stellantis::requestOtpSms());
    }

    if (init('action') == 'activateOtp') {
        ajax::success(stellantis::activateOtp((string) init('sms'), (string) init('pin')));
    }

    if (init('action') == 'renewRemoteToken') {
        ajax::success(stellantis::renewRemoteToken());
    }

    // syncVehicles répond en structure uniforme {ok, created, updated, disabled, reactivables, message}
    // (mappe stellantisException en interne, comme testConnection)
    if (init('action') == 'sync') {
        ajax::success(stellantis::syncVehicles());
    }

    throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));
    /*     * *********Catch exeption*************** */
}
catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
