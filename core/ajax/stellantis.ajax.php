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

    // UC54 : compte ciblé par les actions multi-comptes ci-dessous (défaut 1 = compte principal ;
    // validé 1..MAX_ACCOUNTS, jamais fait confiance à une valeur postée hors bornes).
    $slotDemande = (int) init('slot', 1);
    if ($slotDemande < 1 || $slotDemande > stellantis::MAX_ACCOUNTS) {
        $slotDemande = 1;
    }

    // Avant le garde global : testConnection répond TOUJOURS en ajax::success({ok, count, message}),
    // y compris quand le plugin n'est pas configuré (structure uniforme pour le front)
    if (init('action') == 'testConnection') {
        ajax::success(stellantis::testConnection($slotDemande));
    }

    // UC61 : extraction auto des identifiants depuis l'APK. AVANT le garde isConfigured() (on extrait
    // justement client_id/client_secret manquants). Reçoit brand+country du formulaire → l'admin n'a
    // pas à sauvegarder d'abord. Téléchargement ~100 Mo → délai serveur allongé pour cette action.
    // UC54 : $slotDemande ne sert qu'au REPLI (formulaire vide → config DU COMPTE ciblé), jamais sauvegardé.
    if (init('action') == 'extractCredentials') {
        set_time_limit(300);
        ajax::success(stellantis::extractCredentialsFromApk((string) init('brand'), (string) init('country'), (string) init('apk_url'), $slotDemande));
    }

    // Garde-fou autoload : appeler stellantis:: AVANT tout stellantisApi::/stellantisException
    // (l'autoloader Jeedom ne connaît que stellantis.class.php — cf. CLAUDE.md)
    // UC54 : garde GLOBALE inchangée (compte PRINCIPAL, slot 1) — un compte secondaire ne peut être
    // configuré qu'une fois le compte principal opérationnel (cf. UI : sections repliables masquées sinon).
    if (!stellantis::isConfigured()) {
        throw new Exception(__('Plugin non configuré : renseignez la marque, le Client ID et le Client Secret puis sauvegardez', __FILE__));
    }

    if (init('action') == 'getAuthUrl') {
        // UC54 : le compte CIBLÉ par cette action doit lui-même être configuré (peut être un compte
        // secondaire distinct du compte principal vérifié ci-dessus).
        if (!stellantis::isConfigured($slotDemande)) {
            throw new Exception(__('Ce compte n\'est pas configuré : renseignez au minimum le Client ID et le Client Secret puis sauvegardez', __FILE__));
        }
        $config = stellantis::getApiConfig($slotDemande);
        ajax::success(array(
            'url' => stellantisApi::buildAuthUrl($slotDemande),
            'redirectUri' => $config['redirectUri'],
        ));
    }

    if (init('action') == 'submitAuthCode') {
        if (!stellantis::isConfigured($slotDemande)) {
            throw new Exception(__('Ce compte n\'est pas configuré : renseignez au minimum le Client ID et le Client Secret puis sauvegardez', __FILE__));
        }
        stellantisApi::exchangeCode((string) init('code'), $slotDemande);
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

    // syncVehicles répond en structure uniforme {ok, created, updated, disabled, reactivables, reactivated, message}
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
