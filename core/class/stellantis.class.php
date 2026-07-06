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

  // Purge des tokens SEULEMENT si la valeur change réellement : le formulaire de config soumet tous
  // les champs à chaque save, une purge inconditionnelle casserait le token à chaque sauvegarde.
  // En preConfig, config::byKey retourne encore l'ancienne valeur.
  public static function preConfig_client_id($_value) {
    if ((string) $_value !== (string) config::byKey('client_id', 'stellantis')) {
      stellantisApi::purgeTokenCache();
    }
    return $_value;
  }

  public static function preConfig_brand($_value) {
    if ((string) $_value !== (string) config::byKey('brand', 'stellantis')) {
      stellantisApi::purgeTokenCache();
    }
    return $_value;
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

/**
 * Exception typée du plugin pour toute erreur d'appel à l'API Stellantis/PSA.
 * Types possibles : token_expired | auth_required | rate_limited | privacy | api_error | transport.
 * ⚠️ « privacy » n'est jamais produit par typeFromResponse() : réservé au code métier (UC07), qui le
 * construira lui-même face à une réponse 2xx vide de statut (mode privé activé sur le véhicule).
 */
class stellantisException extends Exception {
  private $httpCode;
  private $apiType;

  public function __construct(string $_message, int $_httpCode = 0, string $_apiType = 'api_error') {
    // Message tronqué : le corps brut complet est loggué en debug par le transport avant la levée
    parent::__construct(mb_substr($_message, 0, 500, 'UTF-8'), $_httpCode);
    $this->httpCode = $_httpCode;
    $this->apiType = $_apiType;
  }

  // Déduit le type d'erreur d'une réponse HTTP non-2xx
  public static function typeFromResponse(int $_httpCode, ?array $_body): string {
    $corps = ($_body === null) ? '' : json_encode($_body);
    if (strpos($corps, 'invalid_grant') !== false) {
      return 'auth_required';
    }
    if ($_httpCode == 401) {
      return 'token_expired';
    }
    if ($_httpCode == 429) {
      return 'rate_limited';
    }
    return 'api_error';
  }

  public function getHttpCode(): int {
    return $this->httpCode;
  }

  public function getApiType(): string {
    return $this->apiType;
  }

  public function isTokenError(): bool {
    return in_array($this->apiType, array('token_expired', 'auth_required'));
  }

  public function isRateLimited(): bool {
    return $this->apiType == 'rate_limited';
  }
}

/**
 * Brique unique par laquelle passent tous les appels HTTP REST du plugin (cf. CLAUDE.md).
 * UC02 : transport générique (httpRequest) + façade connectedcar v4 (call).
 * La couche OAuth2 (UC03) réutilisera httpRequest() (form-urlencoded + Basic) — aucun autre cURL.
 */
class stellantisApi {
  // Méthodes HTTP acceptées par la façade call()
  const METHODES_AUTORISEES = array('GET', 'POST', 'PUT', 'DELETE');

  // Cycle de vie du token OAuth2
  const TOKEN_CACHE_KEY = 'stellantis::token';
  const OAUTH_PENDING_KEY = 'stellantis::oauth_pending';
  const TOKEN_MARGE = 60;
  // Quota local anti-ban aligné sur @rate_limit(6,1800) du code de référence psa_car_controller
  const REFRESH_QUOTA_KEY = 'stellantis::refresh_quota';
  const REFRESH_QUOTA_MAX = 6;
  const REFRESH_QUOTA_FENETRE = 1800;

  /**
   * Appel authentifié à l'API connectedcar v4 : client_id en query (toutes méthodes),
   * Bearer + x-introspect-realm en headers, Accept hal+json.
   * Retourne le JSON décodé tel quel (enveloppe HAL non déballée — à la charge du métier).
   * @throws stellantisException
   */
  public static function call(string $_method, string $_path, array $_params = array(), ?string $_accessToken = null): array {
    $method = strtoupper($_method);
    if (!in_array($method, self::METHODES_AUTORISEES)) {
      throw new stellantisException('Méthode HTTP non autorisée : ' . $method);
    }
    if (!preg_match('#^/[A-Za-z0-9/_-]+$#D', $_path)) {
      throw new stellantisException('Chemin API invalide : ' . $_path);
    }
    $config = stellantis::getApiConfig();
    $query = array('client_id' => $config['clientId']);
    $headers = array(
      'Accept: application/hal+json',
      'x-introspect-realm: ' . $config['realm'],
    );
    $rawBody = null;
    if ($method == 'GET' || $method == 'DELETE') {
      // $query en second : le client_id de la config ne peut pas être écrasé par $_params
      $query = array_merge($_params, $query);
    } else {
      $rawBody = json_encode($_params);
      $headers[] = 'Content-Type: application/json';
    }
    if ($_accessToken !== null && $_accessToken !== '') {
      $headers[] = 'Authorization: Bearer ' . $_accessToken;
    }
    $url = $config['apiBaseUrl'] . $_path . '?' . http_build_query($query);
    return self::httpRequest($method, $url, $headers, $rawBody);
  }

  /*     * ******************* OAuth2 PKCE & cycle de vie du token ******************* */

  // Génère un code_verifier PKCE : 64 caractères base64url depuis une source cryptographique
  private static function genCodeVerifier(): string {
    return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
  }

  private static function genCodeChallenge(string $_verifier): string {
    return rtrim(strtr(base64_encode(hash('sha256', $_verifier, true)), '+/', '-_'), '=');
  }

  /**
   * Construit l'URL d'autorisation OAuth2 PKCE et mémorise {verifier, state} en cache chiffré (10 min).
   * @throws stellantisException
   */
  public static function buildAuthUrl(): string {
    if (!stellantis::isConfigured()) {
      throw new stellantisException('Plugin non configuré : renseignez le Client ID et le Client Secret', 0, 'auth_required');
    }
    $config = stellantis::getApiConfig();
    $verifier = self::genCodeVerifier();
    $state = bin2hex(random_bytes(16));
    cache::set(self::OAUTH_PENDING_KEY, utils::encrypt(json_encode(array('verifier' => $verifier, 'state' => $state))), 600);
    // RFC3986 : l'espace du scope doit être encodé %20 (pas « + »)
    return $config['authBaseUrl'] . '/authorize?' . http_build_query(array(
      'client_id' => $config['clientId'],
      'response_type' => 'code',
      'scope' => 'openid profile',
      'redirect_uri' => $config['redirectUri'],
      'state' => $state,
      'code_challenge' => self::genCodeChallenge($verifier),
      'code_challenge_method' => 'S256',
    ), '', '&', PHP_QUERY_RFC3986);
  }

  /**
   * Échange le code d'autorisation contre les tokens.
   * Chemin nominal : $_input = URL de redirection complète collée → state toujours vérifié.
   * Chemin dégradé : code brut seul → accepté, state invérifiable (loggué en warning).
   * @throws stellantisException
   */
  public static function exchangeCode(string $_input): void {
    $input = trim($_input);
    if ($input == '') {
      throw new stellantisException('Aucun code d\'autorisation fourni', 0, 'auth_required');
    }
    $pendingBrut = (string) cache::byKey(self::OAUTH_PENDING_KEY)->getValue('');
    $pending = ($pendingBrut == '') ? null : json_decode(utils::decrypt($pendingBrut), true);
    if (!is_array($pending) || !isset($pending['verifier']) || !isset($pending['state'])) {
      throw new stellantisException('Demande d\'autorisation absente ou expirée : générez une nouvelle URL', 0, 'auth_required');
    }
    $code = $input;
    $state = null;
    if (strpos($input, '://') !== false) {
      $params = array();
      $query = parse_url($input, PHP_URL_QUERY);
      if (is_string($query)) {
        parse_str($query, $params);
      }
      if (!isset($params['code']) || $params['code'] == '') {
        throw new stellantisException('Code d\'autorisation introuvable dans l\'URL collée', 0, 'auth_required');
      }
      if (!isset($params['state']) || $params['state'] == '') {
        // Une URL de redirection contient toujours le state renvoyé par l'IdP : son absence est anormale
        throw new stellantisException('Paramètre state absent de l\'URL collée : générez une nouvelle URL et recommencez', 0, 'auth_required');
      }
      $code = $params['code'];
      $state = (string) $params['state'];
    }
    if ($state !== null) {
      if (!hash_equals($pending['state'], $state)) {
        throw new stellantisException('Paramètre state invalide : générez une nouvelle URL et recommencez', 0, 'auth_required');
      }
    } else {
      // Chemin dégradé « code seul » : state invérifiable, mais le PKCE couvre le risque — le code ne
      // peut être échangé qu'avec le code_verifier stocké côté serveur (généré par l'admin lui-même).
      // Ne pas supprimer cette garantie : elle remplace la vérification du state ici.
      log::add('stellantis', 'warning', 'Échange OAuth : state non vérifié (entrée = code seul ; collez plutôt l\'URL de redirection complète)');
    }
    $config = stellantis::getApiConfig();
    $reponse = self::requestToken(array(
      'grant_type' => 'authorization_code',
      'code' => $code,
      'redirect_uri' => $config['redirectUri'],
      'code_verifier' => $pending['verifier'],
    ));
    self::storeTokenResponse($reponse, null);
    cache::delete(self::OAUTH_PENDING_KEY);
    log::add('stellantis', 'info', 'Authentification OAuth2 réussie, tokens enregistrés');
  }

  /**
   * Rend un access_token valide, sans appel réseau si possible.
   * @param bool $_force ignorer la validité du token en cache (après un 401)
   * @param string|null $_failedToken token qui vient d'échouer chez l'appelant : si le cache en
   *                    contient déjà un autre (refresh concurrent), il est rendu sans réseau ni quota
   * @throws stellantisException
   */
  public static function getToken(bool $_force = false, ?string $_failedToken = null): string {
    $token = self::readTokenCache();
    if ($token !== null) {
      if (!$_force && time() < $token['exp'] - self::TOKEN_MARGE) {
        return $token['access_token'];
      }
      if ($_force && $_failedToken !== null && $token['access_token'] !== $_failedToken) {
        return $token['access_token'];
      }
    }
    $token = self::refreshToken();
    return $token['access_token'];
  }

  /**
   * Appel métier authentifié : getToken() + call(), avec refresh réactif et rejeu unique
   * sur token expiré. auth_required remonte tel quel (pas de boucle).
   * @throws stellantisException
   */
  public static function callWithToken(string $_method, string $_path, array $_params = array()): array {
    $accessToken = self::getToken();
    try {
      return self::call($_method, $_path, $_params, $accessToken);
    } catch (stellantisException $e) {
      if ($e->getApiType() != 'token_expired') {
        throw $e;
      }
      $accessToken = self::getToken(true, $accessToken);
      return self::call($_method, $_path, $_params, $accessToken);
    }
  }

  // Statut non sensible pour l'UI (aucun token exposé)
  public static function getTokenInfo(): array {
    $token = self::readTokenCache();
    if ($token === null) {
      return array('authenticated' => false, 'expiresIn' => null);
    }
    return array('authenticated' => true, 'expiresIn' => max(0, $token['exp'] - time()));
  }

  // Purge tokens + demande d'autorisation en attente (changement de credentials ou de marque)
  public static function purgeTokenCache(): void {
    cache::delete(self::TOKEN_CACHE_KEY);
    cache::delete(self::OAUTH_PENDING_KEY);
    log::add('stellantis', 'info', 'Tokens purgés (changement de configuration)');
  }

  /**
   * Rafraîchit l'access_token (rotation du refresh_token persistée).
   * Concurrence sans mutex : sur invalid_grant, si le refresh_token en cache diffère de celui qui a
   * échoué, un process concurrent a déjà tourné le token → on rend le cache (un seul niveau, pas de
   * boucle). auth_required seulement si le refresh_token mort est bien celui du cache.
   * @throws stellantisException
   */
  private static function refreshToken(): array {
    $token = self::readTokenCache();
    if ($token === null || !isset($token['refresh_token']) || $token['refresh_token'] == '') {
      throw new stellantisException('Aucun refresh token : ré-authentification requise', 0, 'auth_required');
    }
    self::consommerQuotaRefresh();
    try {
      $reponse = self::requestToken(array(
        'grant_type' => 'refresh_token',
        'refresh_token' => $token['refresh_token'],
      ));
    } catch (stellantisException $e) {
      if ($e->getApiType() == 'auth_required') {
        $enCache = self::readTokenCache();
        if ($enCache !== null && isset($enCache['refresh_token']) && $enCache['refresh_token'] !== $token['refresh_token']) {
          log::add('stellantis', 'debug', 'Refresh concurrent détecté : réutilisation du token déjà rafraîchi');
          return $enCache;
        }
        cache::delete(self::TOKEN_CACHE_KEY);
        log::add('stellantis', 'warning', 'Refresh token expiré ou révoqué : ré-authentification requise');
        throw new stellantisException('Refresh token expiré ou révoqué : ré-authentification requise', $e->getHttpCode(), 'auth_required');
      }
      throw $e;
    }
    self::storeTokenResponse($reponse, $token['refresh_token']);
    $nouveau = self::readTokenCache();
    log::add('stellantis', 'info', 'Token rafraîchi (expire dans ' . ($nouveau['exp'] - time()) . ' s)');
    return $nouveau;
  }

  /**
   * POST vers l'endpoint token (échange ou refresh) : Authorization Basic + corps form-urlencoded.
   * @throws stellantisException
   */
  private static function requestToken(array $_body): array {
    $config = stellantis::getApiConfig();
    $headers = array(
      'Authorization: Basic ' . base64_encode($config['clientId'] . ':' . $config['clientSecret']),
      'Content-Type: application/x-www-form-urlencoded',
      'Accept: application/json',
    );
    return self::httpRequest('POST', $config['authBaseUrl'] . '/access_token', $headers, http_build_query($_body), true);
  }

  // Persiste la réponse token ; conserve l'ancien refresh_token si la réponse n'en fournit pas
  private static function storeTokenResponse(array $_reponse, ?string $_ancienRefresh): void {
    if (!isset($_reponse['access_token']) || $_reponse['access_token'] == '') {
      throw new stellantisException('Réponse token invalide (access_token absent)');
    }
    $refresh = (isset($_reponse['refresh_token']) && $_reponse['refresh_token'] != '') ? $_reponse['refresh_token'] : (string) $_ancienRefresh;
    // Plancher > TOKEN_MARGE : un expires_in anormalement bas rendrait chaque getToken() « expiré »
    // dès l'écriture et épuiserait le quota de refresh à lui seul
    $expiresIn = (int) (isset($_reponse['expires_in']) ? $_reponse['expires_in'] : 0);
    if ($expiresIn > 0 && $expiresIn < 120) {
      log::add('stellantis', 'warning', 'expires_in inhabituellement court reçu de l\'IdP : ' . $expiresIn . ' s (plancher 120 s appliqué)');
    }
    $exp = time() + max(120, $expiresIn);
    cache::set(self::TOKEN_CACHE_KEY, utils::encrypt(json_encode(array(
      'access_token' => $_reponse['access_token'],
      'refresh_token' => $refresh,
      'exp' => $exp,
    ))), 0);
  }

  // Relit le cache token déchiffré ; null si absent ou illisible
  private static function readTokenCache(): ?array {
    $brut = (string) cache::byKey(self::TOKEN_CACHE_KEY)->getValue('');
    if ($brut == '') {
      return null;
    }
    $token = json_decode(utils::decrypt($brut), true);
    return (is_array($token) && isset($token['access_token']) && isset($token['exp'])) ? $token : null;
  }

  // Quota local anti-ban en fenêtre fixe : dépassement → rate_limited SANS appel réseau
  private static function consommerQuotaRefresh(): void {
    $quota = json_decode((string) cache::byKey(self::REFRESH_QUOTA_KEY)->getValue(''), true);
    $maintenant = time();
    if (!is_array($quota) || !isset($quota['windowStart']) || $maintenant - $quota['windowStart'] > self::REFRESH_QUOTA_FENETRE) {
      $quota = array('windowStart' => $maintenant, 'count' => 0);
    }
    if ($quota['count'] >= self::REFRESH_QUOTA_MAX) {
      throw new stellantisException('Quota de rafraîchissement de token atteint (' . self::REFRESH_QUOTA_MAX . ' par 30 min) : réessayez plus tard', 429, 'rate_limited');
    }
    $quota['count']++;
    cache::set(self::REFRESH_QUOTA_KEY, json_encode($quota), self::REFRESH_QUOTA_FENETRE);
  }

  /**
   * Transport HTTP bas niveau : cURL, timeouts, décodage JSON, mapping d'erreurs, logs redactés
   * (jamais de token/secret, jamais la query string — le client_id y figure).
   * @param bool $_reponseSensible réponse pouvant contenir des tokens (endpoint OAuth) : le corps
   *             brut n'est alors jamais loggué ni réinjecté dans le message d'exception — seuls les
   *             champs error/error_description sont relayés
   * @throws stellantisException
   */
  private static function httpRequest(string $_method, string $_url, array $_headers = array(), ?string $_rawBody = null, bool $_reponseSensible = false): array {
    $urlSansQuery = strtok($_url, '?');
    $ch = curl_init();
    curl_setopt_array($ch, array(
      CURLOPT_URL => $_url,
      CURLOPT_CUSTOMREQUEST => $_method,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_TIMEOUT => 15,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_HTTPHEADER => $_headers,
    ));
    if ($_rawBody !== null) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $_rawBody);
    }
    $body = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $curlErrno !== 0) {
      log::add('stellantis', 'warning', $_method . ' ' . $urlSansQuery . ' → erreur de transport cURL #' . $curlErrno . ' : ' . $curlError);
      throw new stellantisException('Erreur de transport vers l\'API (cURL #' . $curlErrno . ' : ' . $curlError . ')', 0, 'transport');
    }
    log::add('stellantis', 'debug', $_method . ' ' . $urlSansQuery . ' → HTTP ' . $httpCode);
    $decoded = null;
    if (trim($body) !== '') {
      $decoded = json_decode($body, true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        $decoded = null;
        if ($httpCode >= 200 && $httpCode < 300) {
          if (!$_reponseSensible) {
            log::add('stellantis', 'debug', 'Corps non-JSON sur HTTP ' . $httpCode . ' : ' . $body);
          }
          throw new stellantisException('Réponse API illisible (JSON invalide, HTTP ' . $httpCode . ')', $httpCode);
        }
      }
    }
    if ($httpCode >= 200 && $httpCode < 300) {
      return is_array($decoded) ? $decoded : array();
    }
    $type = stellantisException::typeFromResponse($httpCode, is_array($decoded) ? $decoded : null);
    if ($_reponseSensible) {
      // Jamais le corps brut d'une réponse OAuth (peut contenir des artefacts de session/token)
      $detail = '';
      if (is_array($decoded)) {
        $detail = trim((isset($decoded['error']) ? $decoded['error'] : '') . ' '
          . (isset($decoded['error_description']) ? $decoded['error_description'] : ''));
      }
      log::add('stellantis', 'debug', 'Erreur HTTP ' . $httpCode . ' sur endpoint sensible' . ($detail != '' ? ' : ' . $detail : ' (corps non loggué)'));
      throw new stellantisException('Erreur OAuth HTTP ' . $httpCode . ($detail != '' ? ' : ' . $detail : ''), $httpCode, $type);
    }
    log::add('stellantis', 'debug', 'Corps d\'erreur HTTP ' . $httpCode . ' : ' . $body);
    throw new stellantisException('Erreur API HTTP ' . $httpCode . ' : ' . $body, $httpCode, $type);
  }
}
