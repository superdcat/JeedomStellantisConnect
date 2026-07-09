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
  // 'apkFile' = nom de base de l'archive APK par marque dans le dépôt communautaire flobz/psa_apk
  // (UC61, extraction auto des identifiants) ; l'URL complète est APK_BASE_URL . apkFile . '.apk.bz2'.
  const BRANDS = array(
    'peugeot' => array('tld' => 'peugeot.com', 'realm' => 'clientsB2CPeugeot', 'redirectUri' => 'mymap://oauth2redirect/{pays}', 'apkFile' => 'mypeugeot'),
    'citroen' => array('tld' => 'citroen.com', 'realm' => 'clientsB2CCitroen', 'redirectUri' => 'mymacsdk://oauth2redirect/{pays}', 'apkFile' => 'mycitroen'),
    'ds' => array('tld' => 'driveds.com', 'realm' => 'clientsB2CDS', 'redirectUri' => 'mymdssdk://oauth2redirect/{pays}', 'apkFile' => 'myds'),
    'opel' => array('tld' => 'opel.com', 'realm' => 'clientsB2COpel', 'redirectUri' => 'mymopsdk://oauth2redirect/{pays}', 'apkFile' => 'myopel'),
    'vauxhall' => array('tld' => 'vauxhall.co.uk', 'realm' => 'clientsB2CVauxhall', 'redirectUri' => 'mymvxsdk://oauth2redirect/{pays}', 'apkFile' => 'myvauxhall'),
  );

  // Base commune de l'API REST consommateur (identique pour toutes les marques)
  const API_BASE_URL = 'https://api.groupe-psa.com/connectedcar/v4';

  // UC61 : base du dépôt communautaire d'APK (flobz/psa_apk). L'archive .apk.bz2 y est un blob git
  // normal (seuls les *.apk sont en LFS) → le lien raw renvoie le vrai binaire. Surchargeable par la
  // clé de config 'apk_url' (URL complète) pour absorber un déplacement/une indisponibilité du dépôt.
  const APK_BASE_URL = 'https://github.com/flobz/psa_apk/raw/main/';

  // Plafond de sécurité de l'APK décompressé (anti-DoS disque) et taille max d'une entrée JSON lue en
  // mémoire dans l'APK (anti zip-bomb) — les JSON ciblés font quelques Ko.
  const APK_TAILLE_MAX = 314572800; // 300 Mo
  const APK_ENTREE_MAX = 1048576;   // 1 Mo

  // Expression cron par défaut (véhicule sans « Auto-actualisation » renseignée) : toutes les 5 minutes.
  const CRON_DEFAUT = '*/5 * * * *';

  // Flag du dernier échec de lien (UC09), posé/effacé par cron() : distingue un refresh KO (rate-limit/
  // transport, token encore présent mais expiré) d'un état sain. TTL borné → auto-guérison.
  const LINK_ERROR_KEY = 'stellantis::link_error';

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

  /* * ******************* UC61 — Extraction auto des identifiants depuis l'APK ******************* */

  // Structure d'échec uniforme de l'extraction APK (partagée par extractCredentialsFromApk et
  // parseApkViaPython) : mêmes clés que le succès, ok=false. Jamais de succès partiel.
  private static function echecApk(string $_msg): array {
    return array('ok' => false, 'client_id' => '', 'client_secret' => '', 'message' => $_msg);
  }

  /**
   * Télécharge l'APK de la marque depuis le dépôt communautaire, en extrait client_id/client_secret
   * et les retourne pour pré-remplissage (SANS rien sauvegarder). Retourne TOUJOURS la structure
   * uniforme ['ok'=>bool, 'client_id'=>string, 'client_secret'=>string, 'message'=>string] — toute
   * erreur est mappée en interne (jamais de levée), comme testConnection()/syncVehicles().
   * Découplé des credentials sauvegardés : marque, pays ET url d'APK viennent du formulaire (repli sur
   * la config si vides), l'admin n'a donc pas besoin de sauvegarder avant. Sur échec, le message
   * renvoie explicitement vers la procédure manuelle (jamais de crash ni d'état partiel).
   * @param string|null $_brand   marque du formulaire (vide/null → config sauvegardée puis peugeot)
   * @param string|null $_country code pays 2 lettres (vide/null → config sauvegardée puis fr)
   * @param string|null $_apkUrl  override d'URL du formulaire (vide/null → config puis défaut marque)
   */
  public static function extractCredentialsFromApk(?string $_brand = null, ?string $_country = null, ?string $_apkUrl = null): array {
    // Balayage des orphelins d'une exécution précédente tuée par SIGKILL (OOM, timeout proxy) : le
    // finally ci-dessous ne s'exécute pas dans ce cas → auto-guérison ici (fichiers > 1 h).
    self::purgerApkOrphelins();
    // 1. Résolution/validation de la marque (jamais d'index indéfini) et du pays. Une chaîne vide
    // (cas réel : le formulaire poste toujours une chaîne, jamais null) retombe sur la config puis défaut.
    $brand = strtolower(trim((string) $_brand));
    if ($brand == '') {
      $brand = strtolower(trim((string) config::byKey('brand', 'stellantis')));
    }
    if ($brand == '') {
      $brand = 'peugeot';
    }
    if (!array_key_exists($brand, self::BRANDS)) {
      return self::echecApk(__('Marque inconnue : impossible de déterminer l\'application mobile à télécharger', __FILE__));
    }
    $country = strtolower(trim((string) $_country));
    if ($country == '') {
      $country = strtolower(trim((string) config::byKey('country', 'stellantis', 'fr')));
    }
    if ($country == '') {
      $country = 'fr';
    }
    // 2. Cooldown serveur (guardrail anti-ban + anti double-clic contournable par rejeu POST). Calé
    // sur la durée réaliste d'un téléchargement de ~100 Mo (bien plus long qu'un test/sync) pour
    // éviter qu'un 2e clic ne lance un téléchargement CONCURRENT pendant que le 1er tourne encore.
    if (cache::byKey('stellantis::apk_cooldown')->getValue('') != '') {
      return self::echecApk(__('Une extraction vient d\'être lancée : patientez avant de réessayer', __FILE__));
    }
    cache::set('stellantis::apk_cooldown', '1', 120);
    // 3. Chemins temporaires uniques (tempnam → pas de collision entre deux extractions concurrentes)
    $bz2Path = tempnam(sys_get_temp_dir(), 'stellantis_apk_');
    $apkPath = tempnam(sys_get_temp_dir(), 'stellantis_apk_');
    if ($bz2Path === false || $apkPath === false) {
      // Nettoyer celui qui aurait été créé (l'autre a échoué) : le finally ci-dessous n'est pas encore actif
      foreach (array($bz2Path, $apkPath) as $chemin) {
        if (is_string($chemin) && $chemin != '' && file_exists($chemin)) {
          @unlink($chemin);
        }
      }
      return self::echecApk(__('Impossible de créer un fichier temporaire pour le téléchargement', __FILE__));
    }
    try {
      // 4. Téléchargement. URL surchargeable : formulaire puis config avancée, sinon défaut par marque.
      $urlOverride = trim((string) $_apkUrl);
      if ($urlOverride == '') {
        $urlOverride = trim((string) config::byKey('apk_url', 'stellantis'));
      }
      $url = ($urlOverride != '') ? $urlOverride : self::APK_BASE_URL . self::BRANDS[$brand]['apkFile'] . '.apk.bz2';
      try {
        stellantisApi::downloadToFile($url, $bz2Path);
      } catch (stellantisException $e) {
        log::add('stellantis', 'warning', 'UC61 : échec du téléchargement de l\'APK : ' . $e->getMessage());
        return self::echecApk(__('Échec du téléchargement de l\'application mobile : vérifiez la connexion Internet de votre Jeedom, ou saisissez les identifiants manuellement.', __FILE__));
      }
      // 5. Décompression (bz2) + parsing (zip → cultures.json → parameters.json) délégués au script
      // Python resources/extract_credentials.py (bibliothèque standard bz2+zipfile) : aucune extension
      // PHP requise, donc aucun redémarrage d'Apache après l'installation des dépendances.
      return self::parseApkViaPython($bz2Path, $apkPath, $country);
    } finally {
      // 6. Nettoyage systématique : ne JAMAIS conserver l'APK/le .bz2 sur le disque Jeedom
      foreach (array($bz2Path, $apkPath) as $chemin) {
        if (is_string($chemin) && $chemin != '' && file_exists($chemin)) {
          @unlink($chemin);
        }
      }
    }
  }

  // Supprime les fichiers temporaires stellantis_apk_* de plus d'une heure (orphelins laissés par une
  // requête tuée avant le finally de extractCredentialsFromApk). Best-effort, jamais bloquant.
  private static function purgerApkOrphelins(): void {
    $motif = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'stellantis_apk_*';
    $fichiers = glob($motif);
    if (!is_array($fichiers)) {
      return;
    }
    $limite = time() - 3600;
    foreach ($fichiers as $fichier) {
      if (is_file($fichier) && @filemtime($fichier) !== false && filemtime($fichier) < $limite) {
        @unlink($fichier);
      }
    }
  }

  /**
   * Décompresse (bz2) et lit l'APK via le script Python resources/extract_credentials.py (bibliothèque
   * standard bz2+zipfile) — AUCUNE extension PHP requise, donc pas de redémarrage d'Apache après
   * l'installation des dépendances. PHP a téléchargé le .bz2 et possède les fichiers temp ; Python
   * décompresse (borné anti-bombe) + lit cultures.json/parameters.json et renvoie sur STDOUT un JSON
   * {status, client_id, client_secret}. Les chemins internes de l'APK et les noms de champs vivent
   * désormais DANS le script Python (à faire évoluer là-bas si Stellantis change la structure). Le
   * mapping status → message utilisateur (i18n) reste ici. Ne retourne JAMAIS de succès partiel.
   */
  private static function parseApkViaPython(string $_bz2Path, string $_apkPath, string $_country): array {
    $dossier = realpath(__DIR__ . '/../../resources');
    if ($dossier === false) {
      log::add('stellantis', 'warning', 'UC61 : dossier resources/ introuvable pour le script d\'extraction');
      return self::echecApk(__('Extraction automatique indisponible : réessayez ou saisissez les identifiants manuellement.', __FILE__));
    }
    $cmd = system::getCmdPython3('stellantis')
      . ' ' . escapeshellarg($dossier . '/extract_credentials.py')
      . ' --bz2 ' . escapeshellarg($_bz2Path)
      . ' --apk ' . escapeshellarg($_apkPath)
      . ' --country ' . escapeshellarg($_country)
      . ' --max-total ' . escapeshellarg((string) self::APK_TAILLE_MAX)
      . ' --max-entry ' . escapeshellarg((string) self::APK_ENTREE_MAX)
      . ' 2>/dev/null'; // stderr écarté (le script n'y met qu'un éventuel traceback, jamais de secret)
    $sortie = array();
    $code = 0;
    exec($cmd, $sortie, $code);
    $resultat = json_decode(implode('', $sortie), true);
    if (!is_array($resultat) || !isset($resultat['status'])) {
      log::add('stellantis', 'warning', 'UC61 : script d\'extraction Python injoignable ou sortie illisible (code ' . $code . ')');
      return self::echecApk(__('Extraction automatique indisponible (dépendances Python manquantes ?) : installez les dépendances du plugin ou saisissez les identifiants manuellement.', __FILE__));
    }
    switch ((string) $resultat['status']) {
      case 'ok':
        $clientId = isset($resultat['client_id']) ? trim((string) $resultat['client_id']) : '';
        $clientSecret = isset($resultat['client_secret']) ? trim((string) $resultat['client_secret']) : '';
        // Jamais de succès partiel : les DEUX identifiants doivent être présents et non vides
        if ($clientId == '' || $clientSecret == '') {
          log::add('stellantis', 'warning', 'UC61 : identifiants absents de parameters.json (cvsClientId/cvsSecret)');
          return self::echecApk(__('Identifiants introuvables dans l\'application mobile (champs absents) : saisissez-les manuellement.', __FILE__));
        }
        log::add('stellantis', 'info', 'UC61 : identifiants extraits de l\'APK avec succès');
        return array(
          'ok' => true,
          'client_id' => $clientId,
          'client_secret' => $clientSecret,
          'message' => __('Identifiants extraits : vérifiez les champs puis sauvegardez la configuration.', __FILE__),
        );
      case 'decompress_failed':
        log::add('stellantis', 'warning', 'UC61 : échec de la décompression bz2 de l\'APK');
        return self::echecApk(__('Échec de la décompression de l\'application mobile téléchargée : fichier corrompu, incomplet ou trop volumineux. Réessayez ou saisissez les identifiants manuellement.', __FILE__));
      case 'zip_unreadable':
        log::add('stellantis', 'warning', 'UC61 : ouverture de l\'APK impossible (archive illisible)');
        return self::echecApk(__('Application mobile illisible (archive invalide) : réessayez ou saisissez les identifiants manuellement.', __FILE__));
      case 'cultures_missing':
        return self::echecApk(__('Structure de l\'application mobile inattendue (cultures.json introuvable ou illisible) : elle a peut-être changé. Saisissez les identifiants manuellement.', __FILE__));
      case 'country_absent':
        return self::echecApk(sprintf(__('Pays « %s » absent de la liste des cultures de l\'application mobile : vérifiez le code pays ou saisissez les identifiants manuellement.', __FILE__), strtoupper($_country)));
      case 'culture_invalid':
        log::add('stellantis', 'warning', 'UC61 : culture au format inattendu');
        return self::echecApk(__('Structure de l\'application mobile inattendue (culture illisible) : saisissez les identifiants manuellement.', __FILE__));
      case 'parameters_missing':
        return self::echecApk(__('Structure de l\'application mobile inattendue (parameters.json introuvable ou illisible) : elle a peut-être changé. Saisissez les identifiants manuellement.', __FILE__));
      case 'credentials_missing':
        log::add('stellantis', 'warning', 'UC61 : identifiants absents de parameters.json (cvsClientId/cvsSecret)');
        return self::echecApk(__('Identifiants introuvables dans l\'application mobile (champs absents) : saisissez-les manuellement.', __FILE__));
      default:
        log::add('stellantis', 'warning', 'UC61 : status d\'extraction inattendu « ' . (string) $resultat['status'] . ' »');
        return self::echecApk(__('Extraction automatique impossible : réessayez ou saisissez les identifiants manuellement.', __FILE__));
    }
  }

  /**
   * Test de connexion bout-en-bout (credentials + token + API) via un appel léger.
   * Retourne TOUJOURS ['ok' => bool, 'count' => int, 'message' => string] — consommable
   * aussi bien par l'AJAX que par un appelant interne (cron).
   */
  public static function testConnection(): array {
    if (!self::isConfigured()) {
      return array(
        'ok' => false,
        'count' => 0,
        'message' => __('Plugin non configuré : renseignez la marque, le Client ID et le Client Secret puis sauvegardez', __FILE__),
      );
    }
    // Cooldown serveur : chaque test = 1 vrai appel API (l'anti double-clic JS est contournable)
    if (cache::byKey('stellantis::test_cooldown')->getValue('') != '') {
      return array(
        'ok' => false,
        'count' => 0,
        'message' => __('Un test vient d\'être effectué : patientez quelques secondes avant de réessayer', __FILE__),
      );
    }
    cache::set('stellantis::test_cooldown', '1', 15);
    try {
      $reponse = stellantisApi::callWithToken('GET', '/user/vehicles');
    } catch (stellantisException $e) {
      // 404 « No vehicle found » : compte valide sans véhicule, pas une erreur de connexion
      if ($e->getApiType() == 'no_vehicle') {
        return array('ok' => true, 'count' => 0, 'message' => __('Connexion OK : aucun véhicule sur le compte', __FILE__));
      }
      return array('ok' => false, 'count' => 0, 'message' => self::messageDepuisException($e));
    }
    $count = count(self::vehiculesBrutsDepuisReponse($reponse));
    return array(
      'ok' => true,
      'count' => $count,
      'message' => sprintf(__('Connexion OK : %d véhicule(s) trouvé(s) sur le compte', __FILE__), $count),
    );
  }

  /**
   * Récupère et normalise la liste des véhicules du compte.
   * Retourne une liste de ['id','vin','brand','label','energy'] — energy en vocabulaire projet
   * normalisé (Electric|Thermal|Hybrid|''), cf. analyse data-model § 1. Compte vide → [].
   * Laisse remonter stellantisException (les appelants mappent les erreurs).
   * @throws stellantisException
   */
  public static function discoverVehicles(): array {
    try {
      $reponse = stellantisApi::callWithToken('GET', '/user/vehicles');
    } catch (stellantisException $e) {
      // 404 « No vehicle found » : compte valide sans véhicule, pas une erreur
      if ($e->getApiType() == 'no_vehicle') {
        log::add('stellantis', 'info', 'Découverte : 0 véhicule sur le compte');
        return array();
      }
      throw $e;
    }
    $bruts = self::vehiculesBrutsDepuisReponse($reponse);
    $vehicules = array();
    foreach ($bruts as $brut) {
      // Données externes non fiables : exiger des scalaires non vides pour les clés d'identité
      // (un cast (string) sur un tableau produirait silencieusement "Array" → collision de logicalId)
      if (!is_array($brut)
        || !isset($brut['id']) || !is_scalar($brut['id']) || trim((string) $brut['id']) == ''
        || !isset($brut['vin']) || !is_scalar($brut['vin']) || trim((string) $brut['vin']) == '') {
        $idApi = (is_array($brut) && isset($brut['id']) && is_scalar($brut['id'])) ? trim((string) $brut['id']) : '';
        log::add('stellantis', 'warning', 'Véhicule ignoré à la découverte : entrée au format inattendu (id ou vin absent/invalide)'
          . ($idApi != '' ? ' (id API ' . $idApi . ')' : ''));
        continue;
      }
      // La validation de forme de l'id pour un usage en path (/user/vehicles/{id}/...) est déléguée
      // à la regex de chemin de stellantisApi::call() — ne pas relâcher cette garantie côté call()
      $vehicules[] = array(
        'id' => trim((string) $brut['id']),
        'vin' => trim((string) $brut['vin']),
        'brand' => (isset($brut['brand']) && is_scalar($brut['brand'])) ? (string) $brut['brand'] : '',
        'label' => (isset($brut['label']) && is_scalar($brut['label'])) ? (string) $brut['label'] : '',
        'energy' => self::energieDepuisEngine((isset($brut['engine']) && is_array($brut['engine'])) ? $brut['engine'] : array()),
      );
    }
    // Pagination jamais observée dans le code de référence : non gérée, mais détectée (cf. spec 05-tech)
    if (isset($reponse['_links']['next'])) {
      log::add('stellantis', 'warning', 'Réponse /user/vehicles paginée détectée : seuls les '
        . count($vehicules) . ' premiers véhicules sont pris en compte (pagination non gérée, à signaler)');
    }
    log::add('stellantis', 'info', 'Découverte : ' . count($vehicules) . ' véhicule(s) sur le compte');
    return $vehicules;
  }

  // Extrait la liste brute _embedded.vehicles d'une réponse HAL /user/vehicles (seul point de
  // parsing de l'enveloppe : à faire évoluer ici si le schéma HAL change)
  private static function vehiculesBrutsDepuisReponse(array $_reponse): array {
    return (isset($_reponse['_embedded']['vehicles']) && is_array($_reponse['_embedded']['vehicles'])) ? $_reponse['_embedded']['vehicles'] : array();
  }

  /**
   * Dérive la motorisation normalisée du plugin (Electric|Thermal|Hybrid|'') depuis engine[] de
   * /user/vehicles. Basé sur la PRÉSENCE des classes (un bi-moteur électrique a 2 entrées Electric),
   * insensible à la casse (variations de schéma PSA avérées). L'UC07 mappe energies[].type du
   * /status vers ce même vocabulaire — table unique, cf. data-model § 1.
   */
  private static function energieDepuisEngine(array $_engines): string {
    $aElectrique = false;
    $aThermique = false;
    foreach ($_engines as $engine) {
      $classe = (is_array($engine) && isset($engine['class'])) ? strtolower((string) $engine['class']) : '';
      if ($classe == 'electric') {
        $aElectrique = true;
      } elseif ($classe == 'thermic') {
        $aThermique = true;
      }
    }
    if ($aElectrique && $aThermique) {
      return 'Hybrid';
    }
    if ($aElectrique) {
      return 'Electric';
    }
    if ($aThermique) {
      return 'Thermal';
    }
    return '';
  }

  /**
   * Synchronise les véhicules du compte avec les équipements Jeedom (idempotent, clé logicalId = VIN).
   * Crée les équipements manquants, met à jour les champs techniques des existants SANS écraser la
   * personnalisation utilisateur (nom, objet parent, activation), désactive ceux disparus du compte.
   * Retourne TOUJOURS ['ok','created','updated','disabled','reactivables','message'] — stellantisException
   * mappée en interne (pas de levée), comme testConnection(). Appelée par l'action AJAX 'sync'.
   */
  public static function syncVehicles(): array {
    // Cooldown serveur (guardrail anti-ban) : chaque synchro = 1 vrai appel API ; l'anti double-clic JS
    // est contournable par rejeu POST — même protection que testConnection()
    if (cache::byKey('stellantis::sync_cooldown')->getValue('') != '') {
      return array('ok' => false, 'created' => 0, 'updated' => 0, 'disabled' => 0, 'reactivables' => 0,
        'message' => __('Une synchronisation vient d\'être effectuée : patientez quelques secondes avant de réessayer', __FILE__));
    }
    cache::set('stellantis::sync_cooldown', '1', 15);
    try {
      $vehicules = self::discoverVehicles();
    } catch (stellantisException $e) {
      return array('ok' => false, 'created' => 0, 'updated' => 0, 'disabled' => 0, 'reactivables' => 0,
        'message' => self::messageDepuisException($e));
    }
    $crees = 0;
    $majs = 0;
    $reactivables = 0;
    $vinsDecouverts = array();
    foreach ($vehicules as $v) {
      // Robustesse (convention cron) : un véhicule en erreur n'interrompt pas la synchro
      try {
        $vinsDecouverts[] = $v['vin'];
        // byLogicalId retrouve aussi les équipements désactivés (pas de filtre onlyEnable) → pas de doublon
        $eqLogic = eqLogic::byLogicalId($v['vin'], 'stellantis');
        $creation = !is_object($eqLogic);
        if ($creation) {
          $eqLogic = new stellantis();
          $eqLogic->setLogicalId($v['vin']);
          $eqLogic->setEqType_name('stellantis');
          $eqLogic->setIsEnable(1);
          $eqLogic->setIsVisible(1);
          $nom = trim($v['brand'] . ' ' . $v['label']);
          $eqLogic->setName($nom != '' ? $nom : $v['vin']);
        } elseif (!$eqLogic->getIsEnable()) {
          // Véhicule de nouveau présent mais équipement désactivé : on NE réactive PAS automatiquement
          // (impossible de distinguer désactivation auto vs manuelle) — on signale juste la possibilité
          $reactivables++;
        }
        // Champs techniques : jamais name/object_id/isEnable sur un équipement existant (perso préservée)
        $eqLogic->setConfiguration('apiId', $v['id']); // requis pour les appels REST (UC07+), réécrit → self-heal
        $eqLogic->setConfiguration('vin', $v['vin']);
        $eqLogic->setConfiguration('brand', $v['brand']);
        // energy : écrit seulement si absent — ne jamais écraser une valeur raffinée par le /status (UC07)
        if (trim((string) $eqLogic->getConfiguration('energy', '')) == '' && $v['energy'] != '') {
          $eqLogic->setConfiguration('energy', $v['energy']);
        }
        $eqLogic->save();
        if ($creation) {
          $crees++;
        } else {
          $majs++;
        }
        // UC07 : créer les commandes info (selon motorisation) puis les peupler via le /status
        // (best-effort). Après save() (id disponible) et hors postSave → pas de récursion. La boucle
        // périodique de rafraîchissement est en UC08 ; ici c'est une passe unique au clic Synchroniser.
        $eqLogic->createCommands();
        $eqLogic->refreshTelemetry();
      } catch (Exception $e) {
        // Jamais de VIN en clair dans les logs (convention CLAUDE.md). Englobe erreur de save ET erreur
        // API du refreshTelemetry : message générique (l'équipement peut avoir été créé malgré tout).
        log::add('stellantis', 'warning', 'Synchronisation : erreur sur un véhicule : ' . $e->getMessage());
      }
    }
    // Véhicules disparus du compte → désactiver plutôt que supprimer (cf. UC76)
    $desactives = 0;
    foreach (eqLogic::byType('stellantis') as $eqExistant) {
      if (!in_array($eqExistant->getLogicalId(), $vinsDecouverts, true) && $eqExistant->getIsEnable()) {
        $eqExistant->setIsEnable(0);
        $eqExistant->save();
        $desactives++;
      }
    }
    log::add('stellantis', 'info', 'Synchronisation : ' . $crees . ' créé(s), ' . $majs . ' mis à jour, ' . $desactives . ' désactivé(s)');
    $message = sprintf(__('Synchronisation terminée : %1$d créé(s), %2$d mis à jour, %3$d désactivé(s)', __FILE__), $crees, $majs, $desactives);
    if ($reactivables > 0) {
      $message .= ' — ' . sprintf(__('%d véhicule(s) réapparu(s) laissé(s) désactivé(s) : réactivez-les manuellement si besoin', __FILE__), $reactivables);
    }
    return array('ok' => true, 'created' => $crees, 'updated' => $majs, 'disabled' => $desactives, 'reactivables' => $reactivables, 'message' => $message);
  }

  /* * ******************* UC07 — Commandes info de télémétrie ******************* */

  /**
   * Table unique des commandes info (source de vérité, isolée) : logicalId => [nom FR, subType,
   * generic_type, unité, historiser]. Tout ajout/retrait de commande socle se fait ICI.
   * ⚠️ Les noms sont enveloppés en __() avec une chaîne LITTÉRALE (jamais une variable) pour que
   * l'extracteur i18n (sous-agent translator) les capture.
   */
  private static function definitionsCommandes(): array {
    return array(
      'battery_soc'      => array(__('Batterie', __FILE__), 'numeric', 'BATTERY', '%', true),
      'autonomy'         => array(__('Autonomie', __FILE__), 'numeric', '', 'km', true),
      'charging_status'  => array(__('État de charge', __FILE__), 'string', '', '', false),
      'charging_plugged' => array(__('Câble branché', __FILE__), 'binary', 'PRESENCE', '', false),
      'fuel_level'       => array(__('Carburant', __FILE__), 'numeric', '', '%', true),
      'mileage'          => array(__('Kilométrage', __FILE__), 'numeric', '', 'km', true),
      'doors_locked'     => array(__('Verrouillage', __FILE__), 'binary', '', '', false),
      'position'         => array(__('Position', __FILE__), 'string', 'GEOLOC', '', false),
      'last_update'      => array(__('Dernière MAJ', __FILE__), 'string', '', '', false),
    );
  }

  /**
   * Crée (idempotent) les commandes info de CE véhicule selon sa motorisation (config `energy`).
   * Socle universel toujours créé ; commandes élec/carburant conditionnelles. Les champs présents mais
   * non prévus par la motorisation seront créés paresseusement par ensureCommand() au 1er /status.
   */
  public function createCommands(): void {
    $motorisation = trim((string) $this->getConfiguration('energy', ''));
    $aCreer = array('mileage', 'doors_locked', 'position', 'last_update');
    if ($motorisation == 'Electric' || $motorisation == 'Hybrid') {
      $aCreer = array_merge($aCreer, array('battery_soc', 'autonomy', 'charging_status', 'charging_plugged'));
    }
    if ($motorisation == 'Thermal' || $motorisation == 'Hybrid') {
      $aCreer[] = 'fuel_level';
      if (!in_array('autonomy', $aCreer)) {
        $aCreer[] = 'autonomy';
      }
    }
    foreach ($aCreer as $logicalId) {
      $this->ensureCommand($logicalId);
    }
  }

  /**
   * Retourne la commande info $logicalId de ce véhicule, la créant si absente (idempotent).
   * @throws stellantisException si $logicalId n'est pas une commande connue (ne devrait pas arriver)
   */
  private function ensureCommand(string $logicalId): stellantisCmd {
    $cmd = $this->getCmd('info', $logicalId);
    if (is_object($cmd)) {
      return $cmd;
    }
    $definitions = self::definitionsCommandes();
    if (!isset($definitions[$logicalId])) {
      throw new stellantisException('Commande info inconnue : ' . $logicalId);
    }
    // $nom est déjà traduit par __() dans definitionsCommandes() (chaîne littérale extractible)
    list($nom, $subType, $genericType, $unite, $historiser) = $definitions[$logicalId];
    $cmd = new stellantisCmd();
    $cmd->setEqLogic_id($this->getId());
    $cmd->setLogicalId($logicalId);
    $cmd->setName($nom);
    $cmd->setType('info');
    $cmd->setSubType($subType);
    if ($genericType != '') {
      $cmd->setGeneric_type($genericType);
    }
    if ($unite != '') {
      $cmd->setUnite($unite);
    }
    $cmd->setIsVisible(1);
    $cmd->setIsHistorized($historiser ? 1 : 0);
    $cmd->save();
    return $cmd;
  }

  /**
   * Rafraîchit la télémétrie de CE véhicule : GET /status (+ /lastPosition si privacy le permet),
   * self-heal de la motorisation, parseStatus, puis mise à jour des commandes info (création paresseuse
   * des champs présents). Un seul véhicule — la boucle périodique est en UC08.
   * @throws stellantisException
   */
  public function refreshTelemetry(): void {
    $apiId = trim((string) $this->getConfiguration('apiId', ''));
    if ($apiId == '') {
      log::add('stellantis', 'warning', 'Rafraîchissement ignoré : apiId absent (relancer une synchronisation)');
      return;
    }
    $status = stellantisApi::callWithToken('GET', '/user/vehicles/' . $apiId . '/status');
    // Position indisponible si mode privacy actif (cf. data-model § 2.6 / UC75)
    $position = null;
    $privacy = isset($status['privacy']['state']) ? (string) $status['privacy']['state'] : 'None';
    // UC09 : mémoriser l'état privacy pour la page Santé (lecture locale, sans réappel API). Cache 2 j
    // (auto-purge des équipements supprimés) — préféré à une clé de config effaçable au Sauvegarder du
    // formulaire desktop/php (qui ne renvoie que les champs présents).
    cache::set('stellantis::privacy::' . $this->getId(), $privacy, 172800);
    if (strcasecmp($privacy, 'None') == 0) {
      try {
        $position = stellantisApi::callWithToken('GET', '/user/vehicles/' . $apiId . '/lastPosition');
      } catch (stellantisException $e) {
        log::add('stellantis', 'info', 'Position non récupérée (' . $e->getApiType() . ') — poursuite sans position');
        $position = null;
      }
    }
    // Self-heal motorisation : le /status fait foi (data-model § 1), mais on n'écrase QUE si la valeur
    // est strictement plus précise (rang '' < Electric/Thermal < Hybrid). Évite qu'un /status PHEV
    // ponctuellement partiel (1 seule entrée energies[]) ne fasse régresser 'Hybrid' → 'Electric'.
    $motorStatus = self::energieDepuisStatus(self::energiesDepuisStatus($status));
    $motorActuel = trim((string) $this->getConfiguration('energy', ''));
    if (self::rangMotorisation($motorStatus) > self::rangMotorisation($motorActuel)) {
      $this->setConfiguration('energy', $motorStatus);
      $this->save();
      $this->createCommands();
    }
    $valeurs = self::parseStatus($status, $position);
    foreach ($valeurs as $logicalId => $valeur) {
      $cmd = $this->ensureCommand($logicalId);
      $this->checkAndUpdateCmd($cmd, $valeur);
    }
  }

  // Extrait le tableau d'énergies du /status : energies[] (v4.15+) prioritaire, fallback energy[].
  private static function energiesDepuisStatus(array $_status): array {
    if (isset($_status['energies']) && is_array($_status['energies'])) {
      return $_status['energies'];
    }
    if (isset($_status['energy']) && is_array($_status['energy'])) {
      return $_status['energy'];
    }
    return array();
  }

  // Motorisation normalisée (Electric|Thermal|Hybrid|'') d'après les types du /status (Electric/Fuel).
  // Même vocabulaire que energieDepuisEngine() (UC05) : une seule table de correspondance.
  private static function energieDepuisStatus(array $_energies): string {
    $aElectrique = false;
    $aThermique = false;
    foreach ($_energies as $energie) {
      $type = (is_array($energie) && isset($energie['type'])) ? strtolower((string) $energie['type']) : '';
      if ($type == 'electric') {
        $aElectrique = true;
      } elseif ($type == 'fuel') {
        $aThermique = true;
      }
    }
    if ($aElectrique && $aThermique) {
      return 'Hybrid';
    }
    if ($aElectrique) {
      return 'Electric';
    }
    if ($aThermique) {
      return 'Thermal';
    }
    return '';
  }

  // Rang de précision d'une motorisation pour le self-heal : '' (inconnu) < Electric = Thermal < Hybrid.
  // On ne remplace la config que par une valeur de rang strictement supérieur (jamais de régression ni
  // de bascule latérale Electric↔Thermal, qui pourrait venir d'un /status momentanément partiel).
  private static function rangMotorisation(string $_motorisation): int {
    if ($_motorisation == 'Hybrid') {
      return 2;
    }
    if ($_motorisation == 'Electric' || $_motorisation == 'Thermal') {
      return 1;
    }
    return 0;
  }

  /**
   * Mapping PUR et défensif du /status (+ position GeoJSON) vers logicalId => valeur. Champ absent =>
   * clé absente (jamais d'exception). SEUL endroit où vivent les chemins de champs API (data-model) :
   * à faire évoluer ici si le schéma PSA change. Statique et sans effet de bord => testable.
   */
  public static function parseStatus(array $_status, ?array $_position = null): array {
    $valeurs = array();
    // Énergie : router par type. PHEV = 2 entrées ([0] Electric, [1] Fuel).
    foreach (self::energiesDepuisStatus($_status) as $energie) {
      if (!is_array($energie)) {
        continue;
      }
      $type = isset($energie['type']) ? strtolower((string) $energie['type']) : '';
      if ($type == 'electric') {
        if (isset($energie['level']) && is_numeric($energie['level'])) {
          $valeurs['battery_soc'] = (float) $energie['level'];
        }
        if (isset($energie['autonomy']) && is_numeric($energie['autonomy'])) {
          $valeurs['autonomy'] = (float) $energie['autonomy'];
        }
        if (isset($energie['charging']['status']) && is_scalar($energie['charging']['status'])) {
          // Défense en profondeur : seule valeur texte non contrainte du mapping. Les enums de charge
          // sont des mots (Disconnected/InProgress/Finished/Failure/Stopped…) → on ne garde que les
          // lettres (neutralise toute injection sans perte pour les valeurs connues).
          $valeurs['charging_status'] = preg_replace('/[^A-Za-z]/', '', (string) $energie['charging']['status']);
        }
        if (isset($energie['charging']['plugged'])) {
          $valeurs['charging_plugged'] = $energie['charging']['plugged'] ? 1 : 0;
        }
      } elseif ($type == 'fuel') {
        if (isset($energie['level']) && is_numeric($energie['level'])) {
          $valeurs['fuel_level'] = (float) $energie['level'];
        }
        // Autonomie carburant : ne pas écraser l'autonomie électrique déjà posée (PHEV : élec prioritaire ;
        // scission autonomy_fuel = post-MVP/23)
        if (!isset($valeurs['autonomy']) && isset($energie['autonomy']) && is_numeric($energie['autonomy'])) {
          $valeurs['autonomy'] = (float) $energie['autonomy'];
        }
      }
    }
    // Odomètre (racine depuis v4.15)
    if (isset($_status['odometer']['mileage']) && is_numeric($_status['odometer']['mileage'])) {
      $valeurs['mileage'] = (float) $_status['odometer']['mileage'];
    }
    // Verrouillage
    $verrouillage = self::extraireVerrouillage($_status);
    if ($verrouillage !== null) {
      $valeurs['doors_locked'] = $verrouillage;
    }
    // Position : GeoJSON coordinates = [lon, lat, alt] → "lat,lon" (PAS [lat,lon] !)
    if ($_position !== null) {
      $coords = (isset($_position['geometry']['coordinates']) && is_array($_position['geometry']['coordinates']))
        ? $_position['geometry']['coordinates'] : null;
      if (is_array($coords) && count($coords) >= 2 && is_numeric($coords[0]) && is_numeric($coords[1])) {
        $valeurs['position'] = ((float) $coords[1]) . ',' . ((float) $coords[0]);
      }
    }
    // Fraîcheur : horodatage API (conforme UC09 — la donnée peut être ancienne sans wakeup)
    $valeurs['last_update'] = self::extraireFraicheur($_status, $_position);
    return $valeurs;
  }

  /**
   * Verrouillage global d'après doors_state.locked_state (liste d'enums). Règle : un élément contenant
   * « Unlocked » => 0 (déverrouillé) ; sinon un élément contenant « Locked » => 1 (verrouillé) ;
   * liste vide/absente => null (commande non renseignée). Insensible à la casse.
   */
  private static function extraireVerrouillage(array $_status): ?int {
    if (!isset($_status['doors_state']['locked_state'])) {
      return null;
    }
    $etatBrut = $_status['doors_state']['locked_state'];
    $etats = is_array($etatBrut) ? $etatBrut : array($etatBrut);
    $deverrouille = false;
    $verrouille = false;
    foreach ($etats as $etat) {
      $s = strtolower((string) $etat);
      if (strpos($s, 'unlocked') !== false) {
        $deverrouille = true;
      } elseif (strpos($s, 'locked') !== false) {
        $verrouille = true;
      }
    }
    if ($deverrouille) {
      return 0;
    }
    if ($verrouille) {
      return 1;
    }
    return null;
  }

  /**
   * Horodatage de fraîcheur de la donnée (UC09) : champ racine updatedAt/lastUpdate, sinon le plus
   * récent des energies[]/energy[].updated_at et de la position (properties.createdAt). Repli sur
   * l'heure du fetch UNIQUEMENT si aucune date API (cas défensif loggué en debug).
   */
  private static function extraireFraicheur(array $_status, ?array $_position): string {
    $candidats = array();
    foreach (array('updatedAt', 'lastUpdate') as $cle) {
      if (isset($_status[$cle]) && is_string($_status[$cle]) && $_status[$cle] != '') {
        $candidats[] = $_status[$cle];
      }
    }
    foreach (self::energiesDepuisStatus($_status) as $energie) {
      if (is_array($energie) && isset($energie['updated_at']) && is_string($energie['updated_at']) && $energie['updated_at'] != '') {
        $candidats[] = $energie['updated_at'];
      }
    }
    if ($_position !== null && isset($_position['properties']['createdAt']) && is_string($_position['properties']['createdAt']) && $_position['properties']['createdAt'] != '') {
      $candidats[] = $_position['properties']['createdAt'];
    }
    $plusRecent = null;
    foreach ($candidats as $candidat) {
      $ts = strtotime($candidat);
      if ($ts !== false && ($plusRecent === null || $ts > $plusRecent)) {
        $plusRecent = $ts;
      }
    }
    if ($plusRecent === null) {
      log::add('stellantis', 'debug', 'Fraîcheur : aucun horodatage API dans le /status, repli sur l\'heure du fetch');
      return date('Y-m-d H:i:s');
    }
    return date('Y-m-d H:i:s', $plusRecent);
  }

  // Traduit une erreur API typée en message utilisateur actionnable (chaîne UI → enveloppée __()).
  // Pas de cas « privacy » : jamais produit avant UC07. Le corps brut des réponses d'erreur reste
  // dans les logs debug, il n'est pas réinjecté dans l'UI.
  private static function messageDepuisException(stellantisException $_e): string {
    switch ($_e->getApiType()) {
      // Garde défensive : aujourd'hui l'AJAX bloque en amont (isConfigured) avant d'atteindre
      // buildAuthUrl() ; ce cas couvre un futur appelant qui n'aurait pas ce pré-check. Ne pas retirer.
      case 'not_configured':
        return __('Plugin non configuré : renseignez la marque, le Client ID et le Client Secret puis sauvegardez', __FILE__);
      case 'auth_required':
        return __('Aucune connexion au compte ou session expirée : (ré)authentifiez-vous depuis la configuration du plugin', __FILE__);
      case 'token_expired':
        return __('Token invalide malgré un rafraîchissement : réessayez, puis ré-authentifiez-vous si le problème persiste', __FILE__);
      case 'rate_limited':
        return __('Trop de requêtes vers l\'API : réessayez plus tard', __FILE__);
      case 'transport':
        return __('API injoignable : vérifiez la connexion Internet de votre Jeedom', __FILE__);
      default:
        return sprintf(__('Erreur API (HTTP %d) : consultez les logs du plugin pour le détail', __FILE__), $_e->getHttpCode());
    }
  }

  /* * ******************* UC09 — État de connexion & fraîcheur ******************* */

  /**
   * État global du LIEN au compte (auth), dérivé du cache — AUCUN appel réseau (guardrail anti-ban).
   * Retourne ['state' => 'ok'|'unauthenticated'|'error', 'detail' => <message UI déjà traduit>].
   * Le mode privacy est PAR VÉHICULE (cf. health()), volontairement absent de cet état global qui
   * reflète le seul lien compte/auth (cf. 09-tech). Consommé par le bandeau page plugin et par health().
   */
  public static function connectionState(): array {
    if (!self::isConfigured()) {
      return array('state' => 'unauthenticated',
        'detail' => __('Plugin non configuré : renseignez la marque, le Client ID et le Client Secret puis sauvegardez', __FILE__));
    }
    // getTokenInfo()['authenticated'] reste vrai tant qu'un token existe MÊME expiré (pas de contrôle
    // d'expiration pour ce booléen) — l'expiration réelle est couverte par le flag link_error ci-dessous.
    if (!stellantisApi::getTokenInfo()['authenticated']) {
      return array('state' => 'unauthenticated',
        'detail' => __('Aucune connexion au compte ou session expirée : (ré)authentifiez-vous depuis la configuration du plugin', __FILE__));
    }
    // Backoff 429 (UC10) : cooldown anti rate-limit actif = état error avec le temps restant. Placé
    // AVANT link_error car c'est le signal autoritatif (timing) posé sur tout vrai 429.
    $resteRateLimit = stellantisApi::rateLimitRemaining();
    if ($resteRateLimit > 0) {
      return array('state' => 'error',
        'detail' => sprintf(__('Trop de requêtes vers l\'API : pause de protection, reprise dans ~%d min', __FILE__), max(1, (int) ceil($resteRateLimit / 60))));
    }
    $erreur = (string) cache::byKey(self::LINK_ERROR_KEY)->getValue('');
    // Ne sert plus qu'au cas « quota local de refresh (REFRESH_QUOTA) épuisé » : celui-ci lève un
    // rate_limited synthétique sans passer par httpRequest, donc SANS armer le cooldown ci-dessus.
    // Ne pas supprimer cette branche en la croyant morte.
    if ($erreur == 'rate_limited') {
      return array('state' => 'error', 'detail' => __('Trop de requêtes vers l\'API : réessayez plus tard', __FILE__));
    }
    if ($erreur == 'transport') {
      return array('state' => 'error', 'detail' => __('API injoignable : vérifiez la connexion Internet de votre Jeedom', __FILE__));
    }
    if ($erreur != '') {
      return array('state' => 'error', 'detail' => __('Erreur au dernier rafraîchissement : consultez les logs du plugin', __FILE__));
    }
    return array('state' => 'ok', 'detail' => __('Connecté au compte Stellantis', __FILE__));
  }

  /**
   * Page Santé du plugin. Contrat core (vérifié dans plugin.class.php : method_exists($id, 'health')) :
   * méthode STATIQUE retournant des lignes ['test','result','advice','state' => bool].
   * Une ligne « Connexion au compte » (état global) puis une ligne de fraîcheur par véhicule activé.
   * AUCUN appel réseau : lecture du cache + valeur courante de la commande last_update.
   */
  public static function health(): array {
    $lignes = array();
    $etat = self::connectionState();
    $lignes[] = array(
      'test' => __('Connexion au compte', __FILE__),
      'result' => $etat['detail'],
      'advice' => ($etat['state'] == 'ok') ? '' : __('Reconnectez-vous depuis la configuration du plugin', __FILE__),
      'state' => ($etat['state'] == 'ok'),
    );
    foreach (eqLogic::byType('stellantis', true) as $eqLogic) {
      $nom = $eqLogic->getName();
      // Privacy connu du dernier /status (cf. refreshTelemetry) : signalé sans être une erreur dure.
      $privacy = (string) cache::byKey('stellantis::privacy::' . $eqLogic->getId())->getValue('');
      if ($privacy != '' && strcasecmp($privacy, 'None') != 0) {
        $lignes[] = array(
          'test' => $nom,
          'result' => __('Mode privacy actif — données de localisation restreintes', __FILE__),
          'advice' => __('Le véhicule masque ses données (mode vie privée) : ce n\'est pas une erreur', __FILE__),
          'state' => true,
        );
        continue;
      }
      // Fraîcheur : valeur courante de la commande info last_update (peuplée par extraireFraicheur, UC07).
      // Lecture via execCmd() (cmd n'expose pas de getValue()) ; défensif si jamais peuplée.
      $cmd = $eqLogic->getCmd('info', 'last_update');
      $valeur = is_object($cmd) ? (string) $cmd->execCmd() : '';
      $ts = ($valeur != '') ? strtotime($valeur) : false;
      if ($ts !== false) {
        $lignes[] = array(
          'test' => $nom,
          'result' => sprintf(__('Dernière donnée reçue %s', __FILE__), self::formaterAge($ts)),
          'advice' => '',
          'state' => true,
        );
      } else {
        $lignes[] = array(
          'test' => $nom,
          'result' => __('Aucune donnée remontée pour le moment', __FILE__),
          'advice' => __('Les données seront renseignées au prochain rafraîchissement', __FILE__),
          'state' => false,
        );
      }
    }
    return $lignes;
  }

  // Âge lisible d'un timestamp Unix (chaînes UI LITTÉRALES pour l'extracteur i18n — cf. definitionsCommandes).
  private static function formaterAge(int $_ts): string {
    $delta = time() - $_ts;
    if ($delta < 0) {
      $delta = 0;
    }
    if ($delta < 60) {
      return __('à l\'instant', __FILE__);
    }
    if ($delta < 3600) {
      return sprintf(__('il y a %d min', __FILE__), intdiv($delta, 60));
    }
    if ($delta < 86400) {
      return sprintf(__('il y a %d h', __FILE__), intdiv($delta, 3600));
    }
    return sprintf(__('il y a %d j', __FILE__), intdiv($delta, 86400));
  }

  /* * ******************* UC11 — Socle démon MQTT (pilotage à distance) ******************* */

  // Broker MQTT par défaut (confirmé par le code de référence psa_car_controller, 2026-07-08).
  // Surchargeable par la config 'broker_host' (forme par marque mw-{code}-m2c.mym.awsmpsa.com si besoin).
  const MQTT_BROKER_DEFAUT = 'mwa.mpsa.com';
  const MQTT_PORT = 8885;
  const MQTT_USERNAME = 'IMA_OAUTH_ACCESS_TOKEN';
  const DAEMON_TOKEN_MARKER = 'stellantis::daemon_token_marker';

  // Hôte du broker MQTT (config 'broker_host' sinon défaut). Le port est constant (8885 TLS).
  public static function brokerHost(): string {
    $host = trim((string) config::byKey('broker_host', 'stellantis'));
    return ($host != '') ? $host : self::MQTT_BROKER_DEFAUT;
  }

  // Customer id (CID) nécessaire aux topics psa/RemoteServices/.../cid/{CID}/... . Source API non figée
  // (relève de UC12/OTP) → lu en config au socle ; vide = abonnement différé, sans échec.
  public static function getCustomerId(): string {
    return trim((string) config::byKey('customer_id', 'stellantis'));
  }

  // Topics de réponse à souscrire (les deux du contrat). Vide si CID inconnu (abonnement différé).
  public static function subscribeTopics(): array {
    $cid = self::getCustomerId();
    if ($cid == '') {
      return array();
    }
    return array(
      'psa/RemoteServices/to/cid/' . $cid . '/#',
      'psa/RemoteServices/events/MPHRTServices/',
    );
  }

  // URL de callback démon→Jeedom, source UNIQUE (évite toute divergence de casse, fatale sous Linux).
  public static function callbackUrl(): string {
    return network::getNetworkAccess('internal') . '/plugins/stellantis/core/php/jeeStellantis.php';
  }

  private static function pidFile(): string {
    return jeedom::getTmpFolder('stellantis') . '/demond.pid';
  }

  private static function socketPort(): int {
    return (int) config::byKey('socketport', 'stellantis', 55009);
  }

  /**
   * Contrat core (plugin.class.php) : retourne l'état du démon. Clés : state ('ok'|'nok'), launchable
   * ('ok'|'nok'), launchable_message, log. Le démon n'est lançable que si le plugin est configuré ET
   * authentifié (sinon le client MQTT n'aurait pas de mot de passe = access_token OAuth2).
   */
  public static function deamon_info(): array {
    $return = array('log' => 'stellantis', 'state' => 'nok', 'launchable' => 'ok');
    $pidFile = self::pidFile();
    if (file_exists($pidFile)) {
      if (@posix_getsid((int) trim((string) file_get_contents($pidFile)))) {
        $return['state'] = 'ok';
      } else {
        @unlink($pidFile);
      }
    }
    if (!self::isConfigured()) {
      $return['launchable'] = 'nok';
      $return['launchable_message'] = __('Plugin non configuré : renseignez et sauvegardez la configuration', __FILE__);
    } elseif (!stellantisApi::getTokenInfo()['authenticated']) {
      $return['launchable'] = 'nok';
      $return['launchable_message'] = __('Compte non connecté : authentifiez-vous avant de démarrer le démon', __FILE__);
    }
    return $return;
  }

  /**
   * Démarre le démon Python MQTT, puis lui pousse la connexion + l'abonnement. Interpréteur résolu via
   * system::getCmdPython3 (respecte virtualenv / Debian 12). Retourne false si le démarrage échoue.
   */
  public static function deamon_start(bool $_debug = false): bool {
    self::deamon_stop();
    $deamon_info = self::deamon_info();
    if ($deamon_info['launchable'] != 'ok') {
      // Message de log en français brut (convention : log::add non traduit)
      log::add('stellantis', 'error', 'Démon non lançable : ' . ($deamon_info['launchable_message'] ?? ''));
      return false;
    }
    $path = realpath(dirname(__FILE__) . '/../../resources/demond');
    $cmd = system::getCmdPython3('stellantis') . ' ' . $path . '/demond.py';
    // Bouton « Démarrer en debug » du core → force le niveau debug, sinon niveau de log configuré
    $niveauLog = $_debug ? 'debug' : log::convertLogLevel(log::getLogLevel('stellantis'));
    $cmd .= ' --loglevel ' . escapeshellarg($niveauLog);
    $cmd .= ' --socketport ' . escapeshellarg((string) self::socketPort());
    $cmd .= ' --callback ' . escapeshellarg(self::callbackUrl());
    $cmd .= ' --apikey ' . escapeshellarg(jeedom::getApiKey('stellantis'));
    $cmd .= ' --pid ' . escapeshellarg(self::pidFile());
    log::add('stellantis', 'info', 'Démarrage du démon MQTT');
    $result = exec($cmd . ' >> ' . log::getPathToLog('stellantis_daemon') . ' 2>&1 &');
    $i = 0;
    while ($i < 20) {
      $deamon_info = self::deamon_info();
      if ($deamon_info['state'] == 'ok') {
        break;
      }
      sleep(1);
      $i++;
    }
    if ($i >= 20) {
      log::add('stellantis', 'error', 'Le démon MQTT n\'a pas démarré dans le délai imparti');
      return false;
    }
    message::removeAll('stellantis', 'unableStartDeamon');
    // Laisse le socket s'ouvrir, puis pousse la connexion MQTT + l'abonnement (best-effort)
    sleep(1);
    self::pushDaemonConnect();
    return true;
  }

  // Arrête le démon (kill du PID + libération du port socket).
  public static function deamon_stop(): void {
    $pidFile = self::pidFile();
    if (file_exists($pidFile)) {
      $pid = (int) trim((string) file_get_contents($pidFile));
      if ($pid > 0) {
        system::kill((string) $pid);
      }
      @unlink($pidFile);
    }
    system::fuserk(self::socketPort());
    cache::delete(self::DAEMON_TOKEN_MARKER);
    sleep(1);
  }

  /**
   * Pousse au démon la connexion au broker (avec le token OAuth2 courant comme mot de passe MQTT) puis
   * l'abonnement aux topics de réponse. Best-effort : toute erreur (token indisponible, socket) est
   * logguée sans interrompre. Le CID inconnu → pas d'abonnement (le broker reste connecté).
   */
  private static function pushDaemonConnect(): void {
    try {
      $token = stellantisApi::getToken();
    } catch (\Throwable $e) {
      log::add('stellantis', 'warning', 'Démon : token indisponible pour la connexion MQTT (' . $e->getMessage() . ')');
      return;
    }
    self::sendToDaemon('connect', array(
      'host' => self::brokerHost(),
      'port' => self::MQTT_PORT,
      'username' => self::MQTT_USERNAME,
      'password' => $token,
    ));
    self::memoriserMarqueurToken($token);
    $topics = self::subscribeTopics();
    if (count($topics) > 0) {
      self::sendToDaemon('subscribe', array('topics' => $topics));
    } else {
      log::add('stellantis', 'info', 'Démon : abonnement différé (customer id inconnu — sera défini en UC12)');
    }
  }

  /**
   * Propage le token OAuth2 courant au démon (nouveau mot de passe MQTT) SEULEMENT s'il a changé depuis
   * le dernier envoi et si le démon tourne. Best-effort, silencieux si démon arrêté. Appelé par le cron
   * (refresh proactif) pour éviter que le démon travaille avec un token périmé jusqu'à un rejet 400.
   */
  public static function syncDaemonToken(): void {
    try {
      if (self::deamon_info()['state'] != 'ok') {
        return;
      }
      $token = stellantisApi::getToken();
      if ((string) cache::byKey(self::DAEMON_TOKEN_MARKER)->getValue('') === self::marqueurToken($token)) {
        return; // token inchangé depuis le dernier push
      }
      self::sendToDaemon('set_token', array('password' => $token));
      self::memoriserMarqueurToken($token);
      log::add('stellantis', 'debug', 'Démon : token MQTT mis à jour');
    } catch (\Throwable $e) {
      log::add('stellantis', 'debug', 'Démon : synchro token ignorée (' . $e->getMessage() . ')');
    }
  }

  // Marqueur non réversible (hash court, jamais le token en clair) servant à détecter un changement de
  // token sans jamais mettre le token en cache. Source unique pour comparaison ET stockage.
  private static function marqueurToken(string $_token): string {
    return substr(hash('sha256', $_token), 0, 16);
  }

  // Mémorise le marqueur du dernier token poussé au démon.
  private static function memoriserMarqueurToken(string $_token): void {
    cache::set(self::DAEMON_TOKEN_MARKER, self::marqueurToken($_token), 0);
  }

  /**
   * Envoie une action au démon via le socket TCP local (protocole {apikey, action, ...params}).
   * Best-effort : toute erreur est logguée en warning, jamais propagée (le démon peut être arrêté).
   */
  public static function sendToDaemon(string $_action, array $_params = array()): void {
    $params = $_params;
    $params['action'] = $_action;
    $params['apikey'] = jeedom::getApiKey('stellantis');
    $payload = json_encode($params);
    $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($socket === false) {
      log::add('stellantis', 'warning', 'Démon : création du socket impossible pour l\'action ' . $_action);
      return;
    }
    try {
      if (@socket_connect($socket, '127.0.0.1', self::socketPort()) === false) {
        log::add('stellantis', 'warning', 'Démon : connexion au socket impossible (démon arrêté ?) pour l\'action ' . $_action);
        return;
      }
      @socket_write($socket, $payload, strlen($payload));
    } finally {
      @socket_close($socket);
    }
  }

  /**
   * Traite un message remonté par le démon (callback jeeStellantis.php). Dispatch défensif sur 'type' :
   * - token_expired : rafraîchit le token OAuth2 (une fois, borné — pas de boucle) et le repousse ;
   * - connected/disconnected/message : trace (le parsing métier des acks est en UC18) ;
   * - inconnu : ignoré silencieusement (types futurs UC13-18).
   * Jamais de levée : appelé depuis un point d'entrée externe qui doit toujours répondre 200.
   */
  public static function handleDaemonMessage(array $_data): void {
    $type = isset($_data['type']) ? (string) $_data['type'] : '';
    switch ($type) {
      case 'token_expired':
        // Rafraîchissement réactif borné : un seul getToken(true) ; s'il échoue, on n'insiste pas
        // (pas de boucle refresh→set_token→400→refresh). Le nouveau token est repoussé au démon.
        try {
          $token = stellantisApi::getToken(true);
          self::sendToDaemon('set_token', array('password' => $token));
          self::memoriserMarqueurToken($token);
          log::add('stellantis', 'info', 'Démon : token rafraîchi après rejet 400 du broker');
        } catch (\Throwable $e) {
          log::add('stellantis', 'warning', 'Démon : échec du rafraîchissement de token après 400 (' . $e->getMessage() . ')');
        }
        break;
      case 'connected':
        log::add('stellantis', 'info', 'Démon : connecté au broker MQTT');
        break;
      case 'disconnected':
        log::add('stellantis', 'info', 'Démon : déconnecté du broker MQTT');
        break;
      case 'message':
        // Socle : on trace la réception ; le décodage métier des acks/états arrive en UC18.
        $topic = isset($_data['topic']) ? (string) $_data['topic'] : '?';
        log::add('stellantis', 'debug', 'Démon : message MQTT reçu sur ' . $topic);
        break;
      default:
        // Type inconnu (futurs UC) : ignoré sans erreur.
        break;
    }
  }

  /**
   * Hook cron Jeedom (chaque minute) : rafraîchit la télémétrie des véhicules dus. On utilise cron()
   * (pas cron5) car isDue() teste une correspondance EXACTE à la minute ; sous cron5 (multiples de 5),
   * une expression autorefresh non alignée (ex. « toutes les 7 min ») ne matcherait jamais. Chaque
   * minute est visitée →
   * toute expression valide finit par être honorée. Cadence par défaut 5 min via CRON_DEFAUT.
   * Lecture REST seule : PAS de wakeup (cf. spec 08 / analyse § 1.4).
   */
  public static function cron() {
    $vehicules = eqLogic::byType('stellantis', true); // activés seulement
    if (count($vehicules) == 0) {
      // Aucun véhicule à suivre : purge un éventuel flag d'erreur résiduel (sinon bloqué en « error »).
      cache::delete(self::LINK_ERROR_KEY);
      return;
    }
    // Backoff 429 (UC10) : un cooldown anti rate-limit court → on saute TOUTE la passe, sans même primer
    // le token (zéro appel réseau). C'est le respect du cooldown côté cron (anti-ban).
    $resteRateLimit = stellantisApi::rateLimitRemaining();
    if ($resteRateLimit > 0) {
      log::add('stellantis', 'info', 'Cron : passe sautée, cooldown anti rate-limit actif (~' . $resteRateLimit . ' s restantes)');
      return;
    }
    // Prime le token une seule fois par passe : si le refresh OAuth échoue, inutile que chaque véhicule
    // retente son propre refreshToken() et épuise le quota anti-ban (REFRESH_QUOTA_MAX) en une passe.
    try {
      stellantisApi::getToken();
      // Lien au compte OK pour cette passe (UC09) : efface tout flag d'erreur antérieur.
      cache::delete(self::LINK_ERROR_KEY);
      cache::delete('stellantis::degraded_warn');
      // UC11 : propager proactivement le token courant au démon MQTT (si lancé et token changé),
      // sans attendre un rejet réactif du broker. Best-effort, jamais bloquant pour le polling.
      self::syncDaemonToken();
    } catch (\Throwable $e) {
      // link_error ne couvre QUE le priming du token : rate-limit/transport = token présent mais refresh
      // KO (connectionState resterait faussement « ok » sans ce flag). auth_required supprime déjà le
      // token → connectionState = unauthenticated (pas de flag). Les échecs /status par véhicule (boucle
      // ci-dessous) restent hors périmètre de l'état global compte/auth.
      $type = ($e instanceof stellantisException) ? $e->getApiType() : 'error';
      if ($type == 'rate_limited' || $type == 'transport') {
        cache::set(self::LINK_ERROR_KEY, $type, 3600);
      } else {
        cache::delete(self::LINK_ERROR_KEY);
      }
      // Mode dégradé (UC10) : auth cassée = les commandes gardent leurs dernières valeurs, aucun appel
      // véhicule tenté jusqu'à ré-auth. Alerte en warning THROTTLÉE 1×/h (cron chaque minute → sinon on
      // noie les logs) ; les autres types (transport/rate-limit transitoires) restent en info.
      if ($type == 'auth_required') {
        $cleWarn = 'stellantis::degraded_warn';
        if (cache::byKey($cleWarn)->getValue('') == '') {
          log::add('stellantis', 'warning', 'Mode dégradé : authentification requise — (ré)authentifiez-vous depuis la configuration du plugin. Les dernières valeurs des commandes sont conservées.');
          cache::set($cleWarn, '1', 3600);
        } else {
          log::add('stellantis', 'debug', 'Cron : authentification requise (warning throttlé) : ' . $e->getMessage());
        }
      } else {
        log::add('stellantis', 'info', 'Cron : rafraîchissement ignoré (' . $e->getMessage() . ') — réessai à la prochaine passe');
      }
      return;
    }
    foreach ($vehicules as $eqLogic) {
      $autorefresh = trim((string) $eqLogic->getConfiguration('autorefresh', ''));
      $expression = ($autorefresh != '') ? $autorefresh : self::CRON_DEFAUT;
      try {
        $cron = new Cron\CronExpression(checkAndFixCron($expression), new Cron\FieldFactory());
        if (!$cron->isDue()) {
          continue;
        }
      } catch (\Throwable $e) {
        // Expression invalide : on NE rafraîchit PAS (le repli agressif contredirait l'anti-ban). Warning
        // throttlé (1×/h/véhicule) pour ne pas noyer les logs (cron chaque minute) ; détail en debug.
        $cleWarn = 'stellantis::cron_warn::' . $eqLogic->getId();
        if (cache::byKey($cleWarn)->getValue('') == '') {
          // eqLogic #id (jamais getHumanName/logicalId : le nom peut valoir le VIN si brand+label vides, cf. UC06)
          log::add('stellantis', 'warning', 'Cron : « Auto-actualisation » invalide pour l\'équipement #' . $eqLogic->getId() . ', véhicule non rafraîchi');
          cache::set($cleWarn, '1', 3600);
        }
        log::add('stellantis', 'debug', 'Cron : expression « Auto-actualisation » rejetée pour l\'équipement #' . $eqLogic->getId() . ' : ' . $e->getMessage());
        continue;
      }
      // Robustesse : un véhicule en erreur (API injoignable, statut illisible…) n'interrompt pas la boucle
      try {
        $eqLogic->refreshTelemetry();
      } catch (\Throwable $e) {
        log::add('stellantis', 'warning', 'Cron : erreur de rafraîchissement de l\'équipement #' . $eqLogic->getId() . ' : ' . $e->getMessage());
      }
    }
  }

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
 * Types possibles : not_configured | token_expired | auth_required | rate_limited | no_vehicle |
 * privacy | api_error | transport.
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
    // L'API renvoie un 404 « métier » (pas une erreur d'endpoint) quand le compte n'a aucun véhicule
    if ($_httpCode == 404 && $_body !== null && isset($_body['code']) && (int) $_body['code'] === 40400) {
      return 'no_vehicle';
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
  // Backoff anti-ban (UC10) : sur un vrai HTTP 429 serveur, on pose un cooldown pendant lequel AUCUN
  // appel n'est tenté (cron + appels métier). Distinct du quota de refresh ci-dessus (compteur local
  // proactif). 900 s = ~3 passes de cron au défaut 5 min ; valeur calibrable en UC72 (idéalement
  // remplacée par l'en-tête Retry-After, non exposé par httpRequest aujourd'hui).
  const RATELIMIT_KEY = 'stellantis::ratelimit_until';
  const RATELIMIT_COOLDOWN = 900;

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
      throw new stellantisException('Plugin non configuré : renseignez le Client ID et le Client Secret', 0, 'not_configured');
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
    try {
      $reponse = self::requestToken(array(
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $config['redirectUri'],
        'code_verifier' => $pending['verifier'],
      ));
    } catch (stellantisException $e) {
      // Un 400/invalid_grant à l'échange = code d'autorisation refusé par l'IdP, quasi toujours
      // parce qu'il est éphémère (expiré/déjà utilisé) ou copié partiellement. Le flow OAuth de PSA
      // s'est durci (le navigateur ne peut pas ouvrir le scheme mymap://…, l'utilisateur copie l'URL
      // à la main) → message actionnable plutôt que « HTTP 400 : invalid_grant ». On conserve le
      // pending (verifier/state encore valides ~10 min) pour un nouvel essai sans tout régénérer.
      if ($e->getApiType() == 'auth_required' || $e->getHttpCode() == 400) {
        throw new stellantisException('Code d\'autorisation refusé (invalide, expiré ou déjà utilisé). '
          . 'Le code n\'est valable que quelques instants : régénérez l\'URL, reconnectez-vous et collez '
          . 'la nouvelle URL de redirection sans attendre.', $e->getHttpCode(), 'auth_required');
      }
      throw $e;
    }
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
    // Backoff 429 (UC10) : court-circuit AVANT getToken() — sinon un token expiré déclencherait un
    // refresh réseau (et consommerait REFRESH_QUOTA) pendant le cooldown. Protège aussi les véhicules
    // #2..N d'une même passe cron (la boucle avale l'erreur par véhicule sans re-tester le cooldown).
    // Ne pas retirer : c'est une protection fonctionnelle, pas seulement défensive.
    $reste = self::rateLimitRemaining();
    if ($reste > 0) {
      throw new stellantisException('Appel court-circuité : cooldown anti rate-limit actif (~' . $reste . ' s restantes)', 429, 'rate_limited');
    }
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

  // Secondes restantes du cooldown anti rate-limit (0 si aucun). Recalculé depuis le timestamp absolu
  // stocké → robuste quelle que soit la sémantique d'expiration du cache. Consommé par cron(),
  // callWithToken() et stellantis::connectionState().
  public static function rateLimitRemaining(): int {
    return max(0, (int) cache::byKey(self::RATELIMIT_KEY)->getValue(0) - time());
  }

  // Pose le cooldown anti rate-limit (timestamp de fin + TTL = durée). Appelé par httpRequest() sur un
  // vrai HTTP 429 (jamais par le rate_limited SYNTHÉTIQUE du quota de refresh, levé avant le réseau).
  private static function enterRateLimitCooldown(): void {
    cache::set(self::RATELIMIT_KEY, time() + self::RATELIMIT_COOLDOWN, self::RATELIMIT_COOLDOWN);
    log::add('stellantis', 'warning', 'Rate-limit API (HTTP 429) : pause anti-blocage de ' . self::RATELIMIT_COOLDOWN . ' s, appels suspendus');
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
    // Backoff 429 (UC10) : un vrai rate-limit serveur (métier OU OAuth, les deux passent ici) arme le
    // cooldown avant de lever, quel que soit le chemin d'appel (une seule fois, hors branches ci-dessous).
    if ($type == 'rate_limited') {
      self::enterRateLimitCooldown();
    }
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

  /**
   * Télécharge un gros fichier binaire (UC61 : l'APK d'une marque, ~100 Mo) vers $_destPath, en flux
   * (jamais en mémoire). Distinct de httpRequest() (qui décode du JSON en RAM) : ici la réponse est
   * écrite directement sur disque via CURLOPT_FILE. Suit les redirections (GitHub raw → CDN) mais
   * uniquement en HTTPS — anti-SSRF sur override d'URL en config avancée (pas de rétrogradation HTTP,
   * pas de file://, gopher://…).
   * ⚠️ Ne porte JAMAIS le header Authorization: Bearer de call()/callWithToken() : l'URL est un dépôt
   * public GitHub, un token n'a rien à y faire (barrière contre un futur refactor de mutualisation).
   * @throws stellantisException type 'transport' sur erreur réseau / fichier / HTTP non-2xx
   */
  public static function downloadToFile(string $_url, string $_destPath): void {
    // Défense en profondeur : n'accepter que du HTTPS avant même d'armer cURL (l'URL peut venir d'un
    // champ de config libre). Les protocoles cURL sont en plus restreints ci-dessous.
    $scheme = strtolower((string) parse_url($_url, PHP_URL_SCHEME));
    if ($scheme !== 'https') {
      throw new stellantisException('URL de téléchargement refusée (HTTPS obligatoire)', 0, 'transport');
    }
    $urlSansQuery = strtok($_url, '?');
    $fp = @fopen($_destPath, 'wb');
    if ($fp === false) {
      throw new stellantisException('Impossible d\'ouvrir le fichier temporaire en écriture', 0, 'transport');
    }
    // HTTPS STRICT (initial + redirections) : une réponse 302 vers http://169.254.169.254 ou file://
    // serait sinon suivie malgré le contrôle de schéma initial (FOLLOWLOCATION actif).
    $protos = defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 0;
    $ch = curl_init();
    $options = array(
      CURLOPT_URL => $_url,
      CURLOPT_HTTPGET => true,
      CURLOPT_FILE => $fp,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_TIMEOUT => 300,
      CURLOPT_CONNECTTIMEOUT => 15,
      // Abandonne un transfert quasi bloqué (< 1 Ko/s pendant 60 s) plutôt que d'attendre 300 s
      CURLOPT_LOW_SPEED_LIMIT => 1024,
      CURLOPT_LOW_SPEED_TIME => 60,
      // Anti-DoS disque : coupe si le contenu dépasse le plafond
      CURLOPT_MAXFILESIZE => stellantis::APK_TAILLE_MAX,
      CURLOPT_HTTPHEADER => array('Accept: application/octet-stream'),
    );
    // Anti-SSRF : cantonner cURL (y compris sur redirection) au seul HTTPS — pas de HTTP, file://, gopher://…
    if ($protos !== 0) {
      $options[CURLOPT_PROTOCOLS] = $protos;
      $options[CURLOPT_REDIR_PROTOCOLS] = $protos;
    }
    curl_setopt_array($ch, $options);
    $ok = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if ($ok === false || $curlErrno !== 0) {
      @unlink($_destPath);
      log::add('stellantis', 'warning', 'GET ' . $urlSansQuery . ' → erreur de transport cURL #' . $curlErrno . ' : ' . $curlError);
      throw new stellantisException('Erreur de transport au téléchargement (cURL #' . $curlErrno . ' : ' . $curlError . ')', 0, 'transport');
    }
    log::add('stellantis', 'debug', 'GET ' . $urlSansQuery . ' → HTTP ' . $httpCode . ' (téléchargement fichier)');
    if ($httpCode < 200 || $httpCode >= 300) {
      @unlink($_destPath);
      throw new stellantisException('Téléchargement refusé par le serveur (HTTP ' . $httpCode . ')', $httpCode, 'transport');
    }
  }
}
