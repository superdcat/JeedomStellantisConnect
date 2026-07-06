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

/* * ***************************Includes********************************* */
require_once __DIR__  . '/../../../../core/php/core.inc.php';

class stellantis extends eqLogic {
  /*     * *************************Attributs****************************** */

  /*
  * Permet de définir les possibilités de personnalisation du widget (en cas d'utilisation de la fonction 'toHtml' par exemple)
  * Tableau multidimensionnel - exemple: array('custom' => true, 'custom::layout' => false)
  public static $_widgetPossibility = array();
  */

  // Champs de configuration du plugin chiffrés automatiquement par le core (config::save/byKey)
  public static $_encryptConfigKey = array('client_secret');

  // Table des marques : TLD du serveur d'authentification idpcvs.{tld}, realm B2C et redirect_uri
  // par défaut ({pays} substitué par le code pays configuré). Possédée par cette classe, consommée
  // par stellantisApi (UC02/03) via getApiConfig() — ne pas la dupliquer.
  // ⚠️ Orthographe du realm Peugeot à confirmer au runtime (clientsB2CPeugeot chez psa_car_controller
  // vs clientsB2CPeugot sur le portail officiel).
  const BRANDS = array(
    'peugeot' => array('tld' => 'peugeot.com', 'realm' => 'clientsB2CPeugeot', 'redirectUri' => 'mymap://oauth2redirect/{pays}'),
    'citroen' => array('tld' => 'citroen.com', 'realm' => 'clientsB2CCitroen', 'redirectUri' => 'mymacsdk://oauth2redirect/{pays}'),
    'ds' => array('tld' => 'driveds.com', 'realm' => 'clientsB2CDS', 'redirectUri' => 'mymdssdk://oauth2redirect/{pays}'),
    'opel' => array('tld' => 'opel.com', 'realm' => 'clientsB2COpel', 'redirectUri' => 'mymopsdk://oauth2redirect/{pays}'),
    'vauxhall' => array('tld' => 'vauxhall.co.uk', 'realm' => 'clientsB2CVauxhall', 'redirectUri' => 'mymvxsdk://oauth2redirect/{pays}'),
  );

  // Base commune de l'API REST consommateur (identique pour toutes les marques)
  const API_BASE_URL = 'https://api.groupe-psa.com/connectedcar/v4';

  /*     * ***********************Methode static*************************** */

  /**
   * Retourne la configuration API prête à l'emploi (défauts propres, jamais d'exception).
   * @param string|null $_brand marque explicite ; null = marque configurée (mono-marque MVP,
   *                            paramètre prévu pour le multi-marques post-MVP)
   */
  public static function getApiConfig(?string $_brand = null): array {
    $brand = ($_brand === null) ? config::byKey('brand', 'stellantis') : $_brand;
    $brand = strtolower(trim((string) $brand));
    if ($brand == '') {
      $brand = 'peugeot';
    } elseif (!isset(self::BRANDS[$brand])) {
      log::add('stellantis', 'warning', 'Marque configurée inconnue « ' . $brand . ' », repli sur peugeot');
      $brand = 'peugeot';
    }
    $brandConfig = self::BRANDS[$brand];
    $country = strtolower(trim((string) config::byKey('country', 'stellantis', 'fr')));
    if ($country == '') {
      $country = 'fr';
    }
    $redirectUri = trim((string) config::byKey('redirect_uri', 'stellantis'));
    if ($redirectUri == '') {
      $redirectUri = str_replace('{pays}', $country, $brandConfig['redirectUri']);
    }
    return array(
      'brand' => $brand,
      'clientId' => trim((string) config::byKey('client_id', 'stellantis')),
      'clientSecret' => trim((string) config::byKey('client_secret', 'stellantis')),
      'country' => $country,
      'realm' => $brandConfig['realm'],
      'authBaseUrl' => 'https://idpcvs.' . $brandConfig['tld'] . '/am/oauth2',
      'apiBaseUrl' => self::API_BASE_URL,
      'redirectUri' => $redirectUri,
    );
  }

  /**
   * Vrai si les identifiants minimaux (client_id + client_secret) sont renseignés.
   */
  public static function isConfigured(): bool {
    return trim((string) config::byKey('client_id', 'stellantis')) != ''
      && trim((string) config::byKey('client_secret', 'stellantis')) != '';
  }

  /*
  * Fonction exécutée automatiquement toutes les minutes par Jeedom
  public static function cron() {}
  */

  /*
  * Fonction exécutée automatiquement toutes les 5 minutes par Jeedom
  public static function cron5() {}
  */

  /*
  * Fonction exécutée automatiquement toutes les 10 minutes par Jeedom
  public static function cron10() {}
  */

  /*
  * Fonction exécutée automatiquement toutes les 15 minutes par Jeedom
  public static function cron15() {}
  */

  /*
  * Fonction exécutée automatiquement toutes les 30 minutes par Jeedom
  public static function cron30() {}
  */

  /*
  * Fonction exécutée automatiquement toutes les heures par Jeedom
  public static function cronHourly() {}
  */

  /*
  * Fonction exécutée automatiquement tous les jours par Jeedom
  public static function cronDaily() {}
  */
  
  /*
  * Permet de déclencher une action avant modification d'une variable de configuration du plugin
  * Exemple avec la variable "param3"
  public static function preConfig_param3( $value ) {
    // do some checks or modify on $value
    return $value;
  }
  */

  /*
  * Permet de déclencher une action après modification d'une variable de configuration du plugin
  * Exemple avec la variable "param3"
  public static function postConfig_param3($value) {
    // no return value
  }
  */

  /*
   * Permet d'indiquer des éléments supplémentaires à remonter dans les informations de configuration
   * lors de la création semi-automatique d'un post sur le forum community
   public static function getConfigForCommunity() {
      // Cette function doit retourner des infos complémentataires sous la forme d'un
      // string contenant les infos formatées en HTML.
      return "les infos essentiel de mon plugin";
   }
   */

  /*     * *********************Méthodes d'instance************************* */

  // Fonction exécutée automatiquement avant la création de l'équipement
  public function preInsert() {
  }

  // Fonction exécutée automatiquement après la création de l'équipement
  public function postInsert() {
  }

  // Fonction exécutée automatiquement avant la mise à jour de l'équipement
  public function preUpdate() {
  }

  // Fonction exécutée automatiquement après la mise à jour de l'équipement
  public function postUpdate() {
  }

  // Fonction exécutée automatiquement avant la sauvegarde (création ou mise à jour) de l'équipement
  public function preSave() {
  }

  // Fonction exécutée automatiquement après la sauvegarde (création ou mise à jour) de l'équipement
  public function postSave() {
  }

  // Fonction exécutée automatiquement avant la suppression de l'équipement
  public function preRemove() {
  }

  // Fonction exécutée automatiquement après la suppression de l'équipement
  public function postRemove() {
  }

  /*
  * Permet de crypter/décrypter automatiquement des champs de configuration des équipements
  * Exemple avec le champ "Mot de passe" (password)
  public function decrypt() {
    $this->setConfiguration('password', utils::decrypt($this->getConfiguration('password')));
  }
  public function encrypt() {
    $this->setConfiguration('password', utils::encrypt($this->getConfiguration('password')));
  }
  */

  /*
  * Permet de modifier l'affichage du widget (également utilisable par les commandes)
  public function toHtml($_version = 'dashboard') {}
  */

  /*     * **********************Getteur Setteur*************************** */
}

class stellantisCmd extends cmd {
  /*     * *************************Attributs****************************** */

  /*
  public static $_widgetPossibility = array();
  */

  /*     * ***********************Methode static*************************** */


  /*     * *********************Methode d'instance************************* */

  /*
  * Permet d'empêcher la suppression des commandes même si elles ne sont pas dans la nouvelle configuration de l'équipement envoyé en JS
  public function dontRemoveCmd() {
    return true;
  }
  */

  // Exécution d'une commande
  public function execute($_options = array()) {
  }

  /*     * **********************Getteur Setteur*************************** */
}
