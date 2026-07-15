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

  // Champs de configuration du plugin chiffrés automatiquement par le core (config::save/byKey).
  // UC34 : home_lat/home_lon chiffrés au repos (données de localisation sensibles — domicile) ; home_radius
  // délibérément EXCLU (pas une donnée de localisation). Transparent pour suivreGeofencing()/le formulaire
  // (lu/écrit via config::byKey/save comme n'importe quelle autre clé). Clés neuves ⇒ aucune migration.
  public static $_encryptConfigKey = array('client_secret', 'home_lat', 'home_lon');

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

  // UC32 — Panneau carte « Mes véhicules » : fournisseur de tuile statique PNG par défaut (sans clé),
  // surchargeable par la config avancée 'map_tile_url'. Zoom/taille calibrés pour une vignette de widget.
  const MAP_TILE_URL = 'https://staticmap.openstreetmap.de/staticmap.php';
  const MAP_ZOOM = 15;
  const MAP_SIZE = '400x260';
  // Cache FICHIER (pas cache:: DB, cf. § dédié) : succès 10 min, négative-cache 1 min (évite de retenter
  // en boucle un fournisseur en panne tout en restant réactif si la position bouge).
  const MAP_CACHE_TTL = 600;
  const MAP_CACHE_TTL_ECHEC = 60;
  // Identifiant obligatoire dans les requêtes (sinon risque de 429/ban du fournisseur tiers, communautaire
  // et gratuit — cf. downloadToFile/UC61 pour le même souci sur un autre tiers).
  // ⚠️ Version figée en dur (pas de lecture dynamique de pluginVersion — aucun précédent vérifié dans ce
  // projet pour une lecture fiable de la version courante depuis le code du plugin lui-même) : PENSER À
  // LA SYNCHRONISER MANUELLEMENT avec plugin_info/info.json ("pluginVersion") à chaque release.
  const MAP_USER_AGENT = 'Jeedom-Stellantis/0.1 (+https://github.com/superdcat/JeedomStellantisConnect)';
  // Anti-DoS mémoire : une tuile PNG 400x260 fait quelques dizaines de Ko, 512 Ko laisse une marge large.
  const MAP_TUILE_TAILLE_MAX = 524288;

  // UC52 — Image / vignette du modèle : plafond de taille de la photo modèle téléchargée depuis
  // 'pictures' (2 Mo — largement suffisant pour une vignette véhicule, borne le download best-effort).
  const IMAGE_MODELE_TAILLE_MAX = 2097152;
  // Table marque (normalisée sans accent, minuscule, alnum) → fichier asset bundlé dans
  // plugin_info/brands/. 'ds'/'dsautomobiles' pointent le même fichier (l'API peut renvoyer "DS" ou
  // "DS AUTOMOBILES" selon les cas — cf. cheminIconeMarque()).
  const ICONES_MARQUES = array(
    'peugeot' => 'peugeot.png',
    'citroen' => 'citroen.png',
    'ds' => 'ds.png',
    'dsautomobiles' => 'ds.png',
    'opel' => 'opel.png',
    'vauxhall' => 'vauxhall.png',
  );

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
      // UC12 : le device OTP + remote token sont liés au compte/credentials → devenus incohérents.
      self::purgeOtp();
    }
    return $_value;
  }

  public static function preConfig_brand($_value) {
    if ((string) $_value !== (string) config::byKey('brand', 'stellantis')) {
      stellantisApi::purgeTokenCache();
      self::purgeOtp();
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
      // UC52 : log debug de la forme brute de 'pictures' (shape runtime NON vérifiée contre le
      // Swagger 'Url' — stub vide — ni lue par aucune implémentation de référence, cf. 52-tech.md).
      // Diagnostic recette/beta uniquement, aseptisé + borné.
      if (isset($brut['pictures'])) {
        log::add('stellantis', 'debug', 'Découverte : forme brute pictures = ' . self::aseptiser((string) json_encode($brut['pictures']), 500));
      }
      $vehicules[] = array(
        'id' => trim((string) $brut['id']),
        'vin' => trim((string) $brut['vin']),
        'brand' => (isset($brut['brand']) && is_scalar($brut['brand'])) ? (string) $brut['brand'] : '',
        'label' => (isset($brut['label']) && is_scalar($brut['label'])) ? (string) $brut['label'] : '',
        'energy' => self::energieDepuisEngine((isset($brut['engine']) && is_array($brut['engine'])) ? $brut['engine'] : array()),
        // UC52 : shape runtime NON vérifiée (cf. 52-tech.md) → repli défensif tableau vide, jamais fatal.
        'pictures' => (isset($brut['pictures']) && is_array($brut['pictures'])) ? $brut['pictures'] : array(),
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
        // UC51 : libellé du véhicule (découverte = autorité, réécrit à chaque sync, comme 'brand' — jamais
        // conditionné, contrairement à 'energy' qui est raffinée par le /status).
        $eqLogic->setConfiguration('label', $v['label']);
        // energy : écrit seulement si absent — ne jamais écraser une valeur raffinée par le /status (UC07)
        if (trim((string) $eqLogic->getConfiguration('energy', '')) == '' && $v['energy'] != '') {
          $eqLogic->setConfiguration('energy', $v['energy']);
        }
        // UC32 : backfill du défaut « visible dans le panneau carte » (création ET équipements existants
        // qui n'ont jamais reçu cette clé) — posé AVANT l'unique save() ci-dessous (pas de second write).
        self::assurerVisiblePanelParDefaut($eqLogic);
        $eqLogic->save();
        if ($creation) {
          $crees++;
        } else {
          $majs++;
        }
        // UC52 : image d'équipement (photo modèle best-effort → sinon icône de marque). Best-effort, ne
        // lève jamais (try/catch interne) : un échec image ne doit pas perturber la synchro (précédent
        // suivreMaintenance). Appelée au sync uniquement (pas au cron — pas de fetch d'image périodique).
        self::assurerImageVehicule($eqLogic, $v['brand'], $v['pictures']);
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
      'autonomy'         => array(__('Autonomie électrique', __FILE__), 'numeric', '', 'km', true),
      'charging_status'  => array(__('État de charge', __FILE__), 'string', '', '', false),
      'charging_plugged' => array(__('Câble branché', __FILE__), 'binary', 'PRESENCE', '', false),
      'fuel_level'       => array(__('Carburant', __FILE__), 'numeric', '', '%', true),
      'mileage'          => array(__('Kilométrage', __FILE__), 'numeric', '', 'km', true),
      'doors_locked'     => array(__('Verrouillage', __FILE__), 'binary', '', '', false),
      'position'         => array(__('Position', __FILE__), 'string', 'GEOLOC', '', false),
      'last_update'      => array(__('Dernière MAJ', __FILE__), 'string', '', '', false),
      // UC51 : identité du véhicule. Valeur STATIQUE issue de la config 'label' (jamais du /status, qui
      // n'a ni 'model' ni 'motorization' — cf. 51-tech.md). Non historisée (donnée quasi immuable).
      // generic_type='' : pas de constante core « libellé/modèle » fiable. Pas de commande 'vin' (décision
      // produit : éviter d'exposer le VIN sur les widgets dashboard).
      'label'            => array(__('Libellé', __FILE__), 'string', '', '', false),
      'precond_status'   => array(__('Préconditionnement', __FILE__), 'string', '', '', false),
      // UC18 : retour d'état asynchrone des commandes à distance. Alimentée UNIQUEMENT par le callback
      // démon (traiterRetourCommande) — jamais par parseStatus/refreshTelemetry, donc jamais écrasée par
      // un refresh REST. Universelle (toute commande à distance existe sur tout véhicule).
      'last_command_result' => array(__('Dernier résultat commande', __FILE__), 'string', '', '', false),
      // UC21 : détail batterie & charge. Les 4 premières (charging.*) VE/PHEV uniquement (mappées dans la
      // branche 'electric' de parseStatus) ; 'battery_12v' UNIVERSELLE (battery.voltage racine — batterie
      // de servitude, distincte de energy[].battery.* qui est la batterie de traction/SOH, hors scope).
      'charging_rate'      => array(__('Vitesse de charge', __FILE__), 'numeric', '', 'km/h', true),
      'charging_remaining' => array(__('Temps de charge restant', __FILE__), 'numeric', '', 'min', true),
      'charging_mode'      => array(__('Mode de charge', __FILE__), 'string', '', '', false),
      'charge_next_time'   => array(__('Prochaine charge programmée', __FILE__), 'string', '', '', false),
      'battery_12v'        => array(__('Batterie 12 V', __FILE__), 'numeric', '', 'V', true),
      // UC23 : carburant & hybrides. 'autonomy_fuel' = mapping direct (branche 'fuel' de parseStatus,
      // clé propre — ne partage plus 'autonomy' avec l'électrique). 'autonomy_total' = valeur DÉRIVÉE
      // (somme élec+carburant), créée paresseusement uniquement quand les deux coexistent (hybride).
      'autonomy_fuel'      => array(__('Autonomie carburant', __FILE__), 'numeric', '', 'km', true),
      'autonomy_total'     => array(__('Autonomie totale', __FILE__), 'numeric', '', 'km', true),
      // UC24 : suivi des sessions de charge. Créées PARESSEUSEMENT (jamais dans createCommands, précédent
      // UC21/23) — naissent à la 1re session close. Libellés « (est.) » = marquage estimation (AC2). VE/PHEV
      // uniquement par construction (suivreSessionCharge gated sur charging_status, branche 'electric').
      'charge_session_energy'   => array(__('Énergie dernière charge (est.)', __FILE__), 'numeric', '', 'kWh', true),
      'charge_session_duration' => array(__('Durée dernière charge', __FILE__), 'numeric', '', 'min', true),
      'charge_session_cost'     => array(__('Coût dernière charge (est.)', __FILE__), 'numeric', '', '€', true),
      // UC31 : position GPS structurée, dérivée du MÊME GeoJSON /lastPosition que 'position' (MVP07).
      // generic_type '' : Jeedom n'expose pas de type générique par axe lat/lon (seul GEOLOC existe, déjà
      // porté par 'position') — convention projet « on ne devine pas de constante core non vérifiée ».
      // Non historisées (cohérent avec 'position'/'last_update' ; l'historisation d'une trace relève des
      // UC trajets 32+). Création PARESSEUSE (cf. createCommands) : naissent au 1er /lastPosition exploitable.
      'latitude'         => array(__('Latitude', __FILE__), 'numeric', '', '', false),
      'longitude'        => array(__('Longitude', __FILE__), 'numeric', '', '', false),
      'heading'          => array(__('Cap', __FILE__), 'numeric', '', '°', false),
      'position_updated' => array(__('Position (mise à jour)', __FILE__), 'string', '', '', false),
      'gps_signal'       => array(__('Qualité du signal GPS', __FILE__), 'numeric', '', '', false),
      // UC33 : historique des trajets. Reconstruction 100 % locale (endpoints « trips » de l'API dépréciés
      // et inaccessibles côté consommateur, cf. 33-tech.md) à partir de kinetic.moving/ignition.type
      // (détection arrêt→départ) + odometer.mileage/latitude/longitude (déjà collectés par parseStatus).
      // 'moving'/'ignition' sont alimentées à CHAQUE poll (création paresseuse, comme UC21/23/31) ; les 6
      // autres décrivent le DERNIER trajet clos, écrites uniquement à sa clôture (suivreTrajet).
      // generic_type='' partout : pas de GEOLOC sur les positions de trajet (réservé à 'position', la
      // position « live »). trip_distance/trip_duration HISTORISÉES : c'est l'historique des trajets (AC1).
      'moving'              => array(__('En mouvement', __FILE__), 'binary', '', '', false),
      'ignition'            => array(__('Contact', __FILE__), 'string', '', '', false),
      'trip_distance'       => array(__('Distance dernier trajet', __FILE__), 'numeric', '', 'km', true),
      'trip_duration'       => array(__('Durée dernier trajet', __FILE__), 'numeric', '', 'min', true),
      'trip_start'          => array(__('Départ (heure)', __FILE__), 'string', '', '', false),
      'trip_end'            => array(__('Arrivée (heure)', __FILE__), 'string', '', '', false),
      'trip_start_position' => array(__('Départ (position)', __FILE__), 'string', '', '', false),
      'trip_end_position'   => array(__('Arrivée (position)', __FILE__), 'string', '', '', false),
      // UC34 : geofencing zone domicile UNIQUE (config PLUGIN home_lat/home_lon/home_radius, partagée par
      // tous les véhicules ; cf. suivreGeofencing) — dérivé de latitude/longitude (UC31), AUCUN appel réseau
      // neuf. Création PARESSEUSE (jamais dans createCommands, précédent UC21/23/24/31/33) : naissent au
      // 1er poll où la zone domicile est configurée ET une position disponible. generic_type='PRESENCE' sur
      // at_home (précédent charging_plugged, binaire de présence) : meilleur widget natif + déclencheur de
      // scénario (AC2, historisée). home_distance NON historisée : évite une trace persistante
      // distance+position exploitable par trilatération (confidentialité du domicile).
      'at_home'        => array(__('Au domicile', __FILE__), 'binary', 'PRESENCE', '', true),
      'home_distance'  => array(__('Distance au domicile', __FILE__), 'numeric', '', 'm', false),
      // UC41 : kilométrage & entretien. 'mileage' (socle MVP07) reste eager/historisée, inchangée. Les 3
      // suivantes sont dérivées de l'endpoint dédié GET /maintenance (disponibilité NON garantie selon
      // véhicule/forfait, cf. 41-tech.md § contrat) → création PARESSEUSE (jamais dans createCommands,
      // précédent UC21/23/24/31/33/34), naissent au 1er /maintenance exploitable (suivreMaintenance).
      // Toutes HISTORISÉES : service_distance/service_days pour visualiser la décroissance (non redondant
      // avec 'mileage' = odomètre ABSOLU, ici distance RESTANTE) ; service_due (binaire) = déclencheur de
      // scénario (précédent at_home UC34). generic_type='' (pas de constante core fiable pour l'entretien).
      'service_distance' => array(__('Distance avant révision', __FILE__), 'numeric', '', 'km', true),
      'service_days'     => array(__('Jours avant révision', __FILE__), 'numeric', '', 'j', true),
      'service_due'      => array(__('Révision proche', __FILE__), 'binary', '', '', true),
      // UC42 : alertes TPMS (pression pneus). La pression numérique en bar/PSI est ABSENTE de l'API
      // consommateur (cf. 42-pression-pneus.md) → seule une info BINAIRE globale existe, dérivée de
      // GET /alerts (endpoint distinct de /maintenance, disponibilité NON garantie, cf. 42-tech.md § 1).
      // Création PARESSEUSE (jamais dans createCommands, précédent UC21/23/24/31/33/34/41), naît au 1er
      // /alerts exploitable (suivreAlertes). generic_type='' (pas de constante core TPMS fiable).
      // HISTORISÉE : déclencheur de scénario natif (précédent service_due UC41 / at_home UC34).
      'tyre_alert' => array(__('Alerte pression pneus', __FILE__), 'binary', '', '', true),
      // UC43 : alertes véhicule (AdBlue, lave-glace, voyants, révision…), catalogue GÉNÉRIQUE AlertMsgEnum
      // (~80 types, variable/non figé) dérivé du MÊME /alerts qu'UC42 (suivreAlertes/parseAlertes, aucun
      // nouvel appel réseau). 'alerts_count' est l'unique entrée STATIQUE (agrégat) : les binaires PAR TYPE
      // (alert_<slug>) sont créées DYNAMIQUEMENT hors de cette table (ensureAlertCommand, catalogue non
      // figé) — cf. synchroniserCommandesAlertes(). Création PARESSEUSE (jamais dans createCommands),
      // HISTORISÉE : déclencheur de scénario natif « le véhicule a une alerte » (#alerts_count# > 0).
      'alerts_count' => array(__('Nombre d\'alertes actives', __FILE__), 'numeric', '', '', true),
      // UC44 : ouvrants détaillés (portes/fenêtres/coffre/capot), dérivés du MÊME /status qu'UC07/UC15
      // (doors_state.opening, aucun nouvel appel réseau). Enum PETIT et CONNU (≠ UC43 dynamique) ⇒ approche
      // STATIQUE idiomatique, miroir de 'doors_locked' (extraireVerrouillage). Création PARESSEUSE (jamais
      // dans createCommands, précédent UC21/23/24/31/33/34/41/42/43) : chaque door_<id> naît au 1er /status
      // qui expose cet ouvrant précis (mapping dans extraireOuvrants). NON historisées (états, cohérent avec
      // 'doors_locked') : l'historisation par ouvrant reste activable manuellement par l'utilisateur.
      // generic_type OPENING (convention core « Ouvrant », polarité 1=ouvert à confirmer en recette).
      'door_driver'      => array(__('Porte conducteur', __FILE__), 'binary', 'OPENING', '', false),
      'door_passenger'   => array(__('Porte passager', __FILE__), 'binary', 'OPENING', '', false),
      'door_rear_left'   => array(__('Porte arrière gauche', __FILE__), 'binary', 'OPENING', '', false),
      'door_rear_right'  => array(__('Porte arrière droite', __FILE__), 'binary', 'OPENING', '', false),
      'door_trunk'       => array(__('Coffre', __FILE__), 'binary', 'OPENING', '', false),
      'door_rear_window' => array(__('Fenêtre arrière', __FILE__), 'binary', 'OPENING', '', false),
      'door_roof_window' => array(__('Toit ouvrant', __FILE__), 'binary', 'OPENING', '', false),
      // 'hood' NON confirmé côté /status consommateur (cf. 44-tech.md § contrat) : déclaré par anticipation,
      // coût nul si l'identifiant API n'apparaît jamais (création paresseuse ⇒ aucune commande créée).
      'door_hood'        => array(__('Capot', __FILE__), 'binary', 'OPENING', '', false),
      // Agrégat (OR de TOUS les ouvrants, connus+inconnus) : generic_type='' (précédent tyre_alert/
      // alerts_count — un agrégat n'est pas un ouvrant précis), HISTORISÉ = déclencheur de scénario natif
      // (précédent service_due/at_home/tyre_alert/alerts_count).
      'opening_alert' => array(__('Ouvrant ouvert', __FILE__), 'binary', '', '', true),
    );
  }

  // UC44 : map identifiant API doors_state.opening[].identifier (EN MINUSCULES, comparaison insensible à la
  // casse) => logicalId. ⚠️ INVARIANT (pas d'infra de test unitaire dans le projet — validation CI Jeedom) :
  // TOUTE valeur DOIT être un logicalId déclaré dans definitionsCommandes() ci-dessus, sinon ensureCommand()
  // lèverait et casserait tout le refresh du véhicule concerné. Un identifiant absent de cette map est
  // ignoré comme logicalId (compté seulement dans l'agrégat opening_alert + loggué debug) — cf.
  // extraireOuvrants(). 'hood'/'bonnet' : spéculatif, capot NON confirmé côté /status (cf. 44-tech.md).
  const OPENING_IDENTIFIERS = array(
    'driver'     => 'door_driver',
    'passenger'  => 'door_passenger',
    'rearleft'   => 'door_rear_left',
    'rearright'  => 'door_rear_right',
    'trunk'      => 'door_trunk',
    'rearwindow' => 'door_rear_window',
    'roofwindow' => 'door_roof_window',
    'hood'       => 'door_hood',   // spéculatif (capot NON confirmé côté /status) — cf. 44-tech.md § contrat
    'bonnet'     => 'door_hood',   // idem, alias anticipé
  );

  /**
   * Crée (idempotent) les commandes info de CE véhicule selon sa motorisation (config `energy`).
   * Socle universel toujours créé ; commandes élec/carburant conditionnelles. Les champs présents mais
   * non prévus par la motorisation seront créés paresseusement par ensureCommand() au 1er /status.
   */
  public function createCommands(): void {
    $motorisation = trim((string) $this->getConfiguration('energy', ''));
    // 'last_command_result' (UC18) : universelle (toute commande à distance existe sur tout véhicule).
    // UC31 : asymétrie eager/lazy ASSUMÉE — 'position' reste eager ici (socle MVP/07), tandis que ses 5
    // dérivées (latitude/longitude/heading/position_updated/gps_signal) sont créées PARESSEUSEMENT par
    // ensureCommand() au 1er /lastPosition exploitable (même pattern que le détail UC21). Un véhicule en
    // privacy permanente affiche donc 'Position' (vide) mais jamais les 5 autres — pas un oubli.
    $aCreer = array('mileage', 'doors_locked', 'position', 'last_update', 'precond_status', 'label', self::CMD_RESULT_LOGICAL_ID);
    if ($motorisation == 'Electric' || $motorisation == 'Hybrid') {
      $aCreer = array_merge($aCreer, array('battery_soc', 'autonomy', 'charging_status', 'charging_plugged'));
    }
    if ($motorisation == 'Thermal' || $motorisation == 'Hybrid') {
      // UC23 : carburant/hybride. Clé propre 'autonomy_fuel' (ne collisionne plus avec 'autonomy'
      // électrique) ; 'autonomy_total' reste en création paresseuse (jamais déclarée ici).
      $aCreer[] = 'fuel_level';
      $aCreer[] = 'autonomy_fuel';
    }
    foreach ($aCreer as $logicalId) {
      $this->ensureCommand($logicalId);
    }
    // UC51 : identité — valeur STATIQUE issue de la config (jamais du /status). Peuplée ici (pas dans
    // parseStatus) : createCommands() est appelé au sync (après réécriture de la config d'identité) et au
    // self-heal de motorisation — jamais à chaque cron. Compromis identique à l'affichage config de
    // brand/energy (rafraîchis seulement à la synchro) : la donnée étant quasi immuable, c'est suffisant.
    // Neutraliser le libellé (texte libre externe : surnom éditable dans l'app mobile) AVANT de le poser
    // en valeur de commande info rendue sur le dashboard — même convention que last_command_result (UC18)
    // et les noms d'alerte (UC43). aseptiser() borne aussi la longueur (anti-DoS d'affichage).
    $this->checkAndUpdateCmd($this->ensureCommand('label'), htmlspecialchars(self::aseptiser((string) $this->getConfiguration('label', '')), ENT_QUOTES, 'UTF-8'));
    // UC32 : pose le widget carte sur la commande 'position', SI VIDE uniquement (idempotent — n'écrase
    // jamais un template choisi manuellement par l'utilisateur, cf. jeedom-widgets-commandes.md § 6).
    // Un seul save() si au moins un des deux templates a été posé. Logique mutualisée avec install.php
    // via assurerTemplatePositionParDefaut() (helper partagé, cf. section UC32 plus bas).
    $cmdPosition = $this->getCmd('info', 'position');
    if (is_object($cmdPosition) && self::assurerTemplatePositionParDefaut($cmdPosition)) {
      $cmdPosition->save();
    }
    // UC13 : commande action « Réveiller » (première commande MQTT). Universelle (tout véhicule), mais son
    // exécution vérifie à chaud les pré-requis (OTP activé, CID, démon lancé) — cf. wakeup().
    $this->ensureActionCommand('wakeup');
    // UC14 : commandes de charge (start/stop), UNIQUEMENT sur véhicule rechargeable (jamais sur thermique).
    if ($motorisation == 'Electric' || $motorisation == 'Hybrid') {
      $this->ensureActionCommand('charge_start');
      $this->ensureActionCommand('charge_stop');
      // UC22 : programmation de l'heure de charge différée (HHMM), même périmètre que charge_start/stop.
      $this->ensureActionCommand('charge_set_time');
    }
    // UC15 : commandes de préconditionnement, UNIVERSELLES (chauffage habitacle pertinent aussi sur thermique).
    $this->ensureActionCommand('precond_on');
    $this->ensureActionCommand('precond_off');
    // UC16 : verrouillage/déverrouillage, UNIVERSELS (les portes existent sur tout véhicule). « unlock »
    // porte actionConfirm=1 (confirmation core anti-fausse-manip) via definitionsActions.
    $this->ensureActionCommand('lock');
    $this->ensureActionCommand('unlock');
    // UC17 : klaxon / feux (retrouver le véhicule), UNIVERSELS (tout véhicule a klaxon + feux).
    $this->ensureActionCommand('horn');
    $this->ensureActionCommand('lights');
  }

  // Table des commandes ACTION (source de vérité, miroir de definitionsCommandes) : logicalId =>
  // [nom FR littéral __(), subType, generic_type, confirmRequis?]. ⚠️ Nom enveloppé en __() avec chaîne
  // LITTÉRALE pour l'extracteur i18n. generic_type vide = pas de métadonnée widget (on ne devine pas de
  // constante core non vérifiée) ; la liaison widget on/off charge → info charging_status (string) est
  // différée. 4e élément OPTIONNEL (lu via isset, défaut false) : true => confirmation core avant
  // exécution (config actionConfirm=1, UC16 unlock) — dialog natif géré par le core, cf. ensureActionCommand.
  private static function definitionsActions(): array {
    return array(
      'wakeup'           => array(__('Réveiller', __FILE__), 'other', ''),
      'charge_start'     => array(__('Démarrer la charge', __FILE__), 'other', ''),
      'charge_stop'      => array(__('Arrêter la charge', __FILE__), 'other', ''),
      // UC22 : 1ère commande action PARAMÉTRÉE du plugin. subType 'message' = widget natif champ texte
      // (saisie libre HHMM) ; le nom porte le format attendu, faute de contrainte de saisie du widget natif.
      'charge_set_time'  => array(__('Programmer l\'heure de charge (HHMM)', __FILE__), 'message', ''),
      'precond_on'       => array(__('Activer le préconditionnement', __FILE__), 'other', ''),
      'precond_off'      => array(__('Désactiver le préconditionnement', __FILE__), 'other', ''),
      'lock'             => array(__('Verrouiller', __FILE__), 'other', '', false),
      'unlock'           => array(__('Déverrouiller', __FILE__), 'other', '', true),
      'horn'             => array(__('Klaxonner', __FILE__), 'other', ''),
      'lights'           => array(__('Allumer les feux', __FILE__), 'other', ''),
    );
  }

  /**
   * Retourne la commande info $logicalId de ce véhicule, la créant si absente (idempotent).
   * @throws stellantisException si $logicalId n'est pas une commande connue (ne devrait pas arriver)
   */
  private function ensureCommand(string $logicalId): stellantisCmd {
    $definitions = self::definitionsCommandes();
    $cmd = $this->trouverOuInstancierCmd('info', $logicalId, $definitions);
    if ($cmd->getId() != '') {
      return $cmd; // déjà existante : rien à (re)configurer
    }
    // $nom est déjà traduit par __() dans definitionsCommandes() (chaîne littérale extractible)
    list(, , $genericType, $unite, $historiser) = $definitions[$logicalId];
    if ($genericType != '') {
      $cmd->setGeneric_type($genericType);
    }
    if ($unite != '') {
      $cmd->setUnite($unite);
    }
    $cmd->setIsHistorized($historiser ? 1 : 0);
    $cmd->save();
    return $cmd;
  }

  /**
   * Retourne la commande action $logicalId de ce véhicule, la créant si absente (idempotent).
   * @throws stellantisException si $logicalId n'est pas une action connue (ne devrait pas arriver)
   */
  private function ensureActionCommand(string $logicalId): stellantisCmd {
    $definitions = self::definitionsActions();
    $cmd = $this->trouverOuInstancierCmd('action', $logicalId, $definitions);
    if ($cmd->getId() != '') {
      return $cmd; // déjà existante : rien à (re)configurer
    }
    $genericType = isset($definitions[$logicalId][2]) ? (string) $definitions[$logicalId][2] : '';
    if ($genericType != '') {
      $cmd->setGeneric_type($genericType);
    }
    // Confirmation avant exécution (UC16 unlock) : le core intercepte toute action portant
    // actionConfirm=1 et affiche un dialog natif (code -32006 → jeeDialog.confirm), rejouant l'appel avec
    // confirmAction=1 si l'utilisateur accepte. Posé AVANT l'unique save() (pas de second write), à la
    // création seulement (branche getId()=='') → n'écrase pas un réglage manuel sur une cmd existante.
    if (!empty($definitions[$logicalId][3])) {
      $cmd->setConfiguration('actionConfirm', 1);
    }
    $cmd->save();
    return $cmd;
  }

  /**
   * Mutualise la plomberie commune de création de commande : retourne la commande $type/$logicalId
   * EXISTANTE (getId() != '') si présente, sinon une NOUVELLE instance non sauvegardée avec ses attributs
   * communs (nom/subType/visible). Le caller distingue les deux via getId() et renseigne les champs
   * spécifiques avant save(). @throws stellantisException si $logicalId est absent des définitions.
   */
  private function trouverOuInstancierCmd(string $type, string $logicalId, array $definitions): stellantisCmd {
    $cmd = $this->getCmd($type, $logicalId);
    if (is_object($cmd)) {
      return $cmd;
    }
    if (!isset($definitions[$logicalId])) {
      throw new stellantisException('Commande ' . $type . ' inconnue : ' . $logicalId);
    }
    list($nom, $subType) = array($definitions[$logicalId][0], $definitions[$logicalId][1]);
    $cmd = new stellantisCmd();
    $cmd->setEqLogic_id($this->getId());
    $cmd->setLogicalId($logicalId);
    $cmd->setName($nom);
    $cmd->setType($type);
    $cmd->setSubType($subType);
    $cmd->setIsVisible(1);
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
    // UC14 : mémoriser l'heure de charge différée brute (hors parseStatus, qui est pur et ne renvoie que
    // des valeurs de commandes) pour la réutiliser lors d'un « Arrêter la charge » (type delayed) sans
    // reprogrammer le véhicule. En cache (pattern privacy) — jamais en config (effaçable au Sauvegarder).
    $heureDifferee = self::extraireHeureChargeDifferee($status);
    if ($heureDifferee != '') {
      cache::set(self::CHARGE_NEXT_TIME_KEY . $this->getId(), $heureDifferee, self::CHARGE_NEXT_TIME_TTL);
    } else {
      // Champ absent du /status frais (programmation de charge différée annulée depuis l'app mobile…) :
      // purger le cache pour que heureChargeDifferee() retombe sur [0,0] et ne republie PAS une heure périmée.
      cache::delete(self::CHARGE_NEXT_TIME_KEY . $this->getId());
    }
    // UC24 : suivi des sessions de charge (VE/PHEV). Best-effort, robuste — ne lève jamais (try/catch interne),
    // posé APRÈS le reste du refresh pour ne rien interrompre.
    $this->suivreSessionCharge($valeurs, $status);
    // UC33 : suivi des trajets (universel). Best-effort, robuste — ne lève jamais (try/catch interne),
    // posé APRÈS suivreSessionCharge() (robustesse cron, même convention que le suivi de charge).
    $this->suivreTrajet($valeurs, $status);
    // UC34 : geofencing zone domicile. Best-effort, robuste — ne lève jamais (try/catch interne), posé
    // avant UC41 (même convention que le suivi de charge/trajet UC24/33).
    $this->suivreGeofencing($valeurs);
    // UC41 : échéances d'entretien (endpoint dédié, throttlé séparément). Best-effort, robuste — ne lève
    // jamais (try/catch interne), posé EN DERNIER dans refreshTelemetry() (robustesse cron, même
    // convention que le suivi de charge/trajet/geofencing UC24/33/34).
    $this->suivreMaintenance($apiId);
    // UC42 : alertes TPMS (endpoint dédié /alerts, throttlé séparément). Best-effort, robuste — ne lève
    // jamais (try/catch interne), posé EN DERNIER dans refreshTelemetry() (après suivreMaintenance, même
    // convention robustesse cron que UC24/33/34/41).
    $this->suivreAlertes($apiId);
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
        // UC21 : détail de charge (VE/PHEV uniquement — branche 'electric', respecte AC3).
        if (isset($energie['charging']['charging_rate']) && is_numeric($energie['charging']['charging_rate'])) {
          $valeurs['charging_rate'] = (float) $energie['charging']['charging_rate'];
        }
        if (isset($energie['charging']['remaining_time']) && is_scalar($energie['charging']['remaining_time'])) {
          $minutesRestantes = self::dureeIsoEnMinutes((string) $energie['charging']['remaining_time']);
          if ($minutesRestantes !== null) {
            $valeurs['charging_remaining'] = $minutesRestantes;
          }
        }
        if (isset($energie['charging']['charging_mode']) && is_scalar($energie['charging']['charging_mode'])) {
          // Enum texte (No/Slow/Quick) : même assainissement que charging_status (défense en profondeur).
          $valeurs['charging_mode'] = preg_replace('/[^A-Za-z]/', '', (string) $energie['charging']['charging_mode']);
        }
        if (isset($energie['charging']['next_delayed_time']) && is_scalar($energie['charging']['next_delayed_time'])) {
          $brutHeure = trim((string) $energie['charging']['next_delayed_time']);
          // Garde de format AVANT parseHeureIso() : un format inconnu renverrait [0,0] → "00:00" fabriqué
          // (valeur inventée, interdite par le contrat de parseStatus). N'émettre que sur un format reconnu.
          if (preg_match('/^\s*PT\d/', $brutHeure) || preg_match('/T\d{2}:\d{2}/', $brutHeure)) {
            list($heureProg, $minuteProg) = self::parseHeureIso($brutHeure);
            $valeurs['charge_next_time'] = sprintf('%02d:%02d', $heureProg, $minuteProg);
          }
        }
      } elseif ($type == 'fuel') {
        if (isset($energie['level']) && is_numeric($energie['level'])) {
          $valeurs['fuel_level'] = (float) $energie['level'];
        }
        if (isset($energie['autonomy']) && is_numeric($energie['autonomy'])) {
          $valeurs['autonomy_fuel'] = (float) $energie['autonomy'];
        }
      }
    }
    // UC23 : autonomie totale combinée = somme élec + carburant. VALEUR DÉRIVÉE (aucun champ API natif,
    // cf. data-model § 2.1) — seule exception au pattern "1 champ /status → 1 clé" de parseStatus. Émise
    // UNIQUEMENT si les DEUX autonomies sont présentes dans ce même /status ⇒ impossible hors hybride
    // (satisfait AC1/AC2 par construction). Création paresseuse : jamais déclarée dans createCommands.
    if (isset($valeurs['autonomy']) && isset($valeurs['autonomy_fuel'])) {
      $valeurs['autonomy_total'] = $valeurs['autonomy'] + $valeurs['autonomy_fuel'];
    }
    // Odomètre (racine depuis v4.15)
    if (isset($_status['odometer']['mileage']) && is_numeric($_status['odometer']['mileage'])) {
      $valeurs['mileage'] = (float) $_status['odometer']['mileage'];
    }
    // UC33 : signaux de détection de trajet (racine depuis v4.15, comme odometer ci-dessus). kinetic.moving
    // = vélocité instantanée à l'instant du poll ; ignition.type PERSISTE pendant tout le trajet (y compris
    // arrêt momentané moteur tournant) — signal robuste contre la fragmentation. La machine à états qui les
    // consomme est suivreTrajet() (fin de refreshTelemetry), pas ce mapping (pur).
    if (isset($_status['kinetic']['moving'])) {
      $valeurs['moving'] = $_status['kinetic']['moving'] ? 1 : 0;
    }
    if (isset($_status['ignition']['type']) && is_scalar($_status['ignition']['type'])) {
      // Enum assaini (même convention que charging_status/precond_status) : ne garder que les lettres.
      $valeurs['ignition'] = preg_replace('/[^A-Za-z]/', '', (string) $_status['ignition']['type']);
    }
    // UC21 : batterie 12 V de servitude — champ racine `battery.voltage`, UNIVERSEL (décision validée
    // 2026-07-11) : présente sur tout véhicule (y compris thermique pur), donc SANS garde de motorisation,
    // à la différence des 4 champs charging.* ci-dessus. DISTINCT de energy[].battery.* (capacité/SOH de
    // la batterie de TRACTION, hors périmètre UC21). Exposée brute (unité incertaine, cf. data-model § 2.6
    // — pas de borne de vraisemblance, elle produirait des faux positifs).
    if (isset($_status['battery']['voltage']) && is_numeric($_status['battery']['voltage'])) {
      $valeurs['battery_12v'] = (float) $_status['battery']['voltage'];
    }
    // Verrouillage
    $verrouillage = self::extraireVerrouillage($_status);
    if ($verrouillage !== null) {
      $valeurs['doors_locked'] = $verrouillage;
    }
    // UC44 : ouvrants détaillés (portes/fenêtres/coffre/capot) + agrégat opening_alert — même objet
    // doors_state que le verrouillage ci-dessus, sous-clé 'opening' (cf. extraireOuvrants).
    $valeurs = array_merge($valeurs, self::extraireOuvrants($_status));
    // Préconditionnement (UC15) : orthographe API réelle « preconditionning » (double n, data-model piège 3).
    if (isset($_status['preconditionning']['airConditioning']['status']) && is_scalar($_status['preconditionning']['airConditioning']['status'])) {
      $statutPrecond = preg_replace('/[^A-Za-z]/', '', (string) $_status['preconditionning']['airConditioning']['status']);
      $valeurs['precond_status'] = $statutPrecond;
      if (strcasecmp($statutPrecond, 'Failure') == 0) {
        $causeBrute = isset($_status['preconditionning']['airConditioning']['failure_cause']) && is_scalar($_status['preconditionning']['airConditioning']['failure_cause'])
          ? (string) $_status['preconditionning']['airConditioning']['failure_cause'] : 'inconnue';
        // Aseptisation avant log (helper partagé) : donnée API externe, jamais réinjectée brute dans les logs.
        $cause = self::aseptiser($causeBrute);
        log::add('stellantis', 'warning', 'Préconditionnement refusé par le véhicule (cause : ' . $cause . ')');
      }
    }
    // Position : GeoJSON coordinates = [lon, lat, alt] → "lat,lon" (PAS [lat,lon] !)
    if ($_position !== null) {
      $coords = (isset($_position['geometry']['coordinates']) && is_array($_position['geometry']['coordinates']))
        ? $_position['geometry']['coordinates'] : null;
      if (is_array($coords) && count($coords) >= 2 && is_numeric($coords[0]) && is_numeric($coords[1])) {
        $lat = (float) $coords[1];
        $lon = (float) $coords[0];
        $valeurs['position'] = $lat . ',' . $lon;
        // UC31 : latitude/longitude structurées, dérivées des MÊMES coordonnées que 'position'.
        $valeurs['latitude'] = $lat;
        $valeurs['longitude'] = $lon;
      }
      // UC31 : propriétés du point GPS — garde INDÉPENDANT de celui des coordonnées ci-dessus (ne pas
      // imbriquer : un GeoJSON peut porter des properties sans geometry exploitable, ou l'inverse).
      $proprietesPosition = (isset($_position['properties']) && is_array($_position['properties']))
        ? $_position['properties'] : null;
      if ($proprietesPosition !== null) {
        // is_numeric (pas de test de vérité) : 0 est une valeur de cap/qualité valide, pas une absence.
        if (isset($proprietesPosition['heading']) && is_numeric($proprietesPosition['heading'])) {
          $valeurs['heading'] = (float) $proprietesPosition['heading'];
        }
        if (isset($proprietesPosition['signalQuality']) && is_numeric($proprietesPosition['signalQuality'])) {
          $valeurs['gps_signal'] = (int) $proprietesPosition['signalQuality'];
        }
        // Horodatage PROPRE à la position (distinct de last_update global, cf. extraireFraicheur ci-dessous).
        if (isset($proprietesPosition['createdAt']) && is_string($proprietesPosition['createdAt']) && $proprietesPosition['createdAt'] != '') {
          $dateFormatee = self::formaterDateApi($proprietesPosition['createdAt']);
          if ($dateFormatee !== null) {
            $valeurs['position_updated'] = $dateFormatee;
          }
        }
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
   * UC44 : mapping PUR et défensif de doors_state.opening[] (liste d'ouvrants {identifier, state}) vers
   * logicalId => 0/1, + agrégat 'opening_alert'. Miroir de extraireVerrouillage() ci-dessus (même style,
   * même contrat pur/défensif). Shape absente/non-tableau => array() (aucune commande créée, « si
   * présentes »). Fail-closed sur `state` : SEUL « Open » explicite (insensible casse/espaces) => ouvert ;
   * tout le reste (Closed, valeur inconnue type « Ajar »…) => 0, jamais de fausse alerte. N'émet QUE des
   * logicalId STATIQUES déclarés dans definitionsCommandes() via OPENING_IDENTIFIERS (jamais dynamique) :
   * un identifiant absent de la map est ignoré comme logicalId (compté dans l'agrégat + loggué debug),
   * étanchéité au throw d'ensureCommand(). 'opening_alert' émis (0 ou 1) dès que doors_state.opening est
   * un tableau exploitable, même vide (0 tous fermés => AC2).
   */
  private static function extraireOuvrants(array $_status): array {
    if (!isset($_status['doors_state']['opening']) || !is_array($_status['doors_state']['opening'])) {
      return array(); // ouvrants non exposés par ce modèle => aucune commande (« si présentes »)
    }
    $valeurs = array();
    $auMoinsUnOuvert = false;
    foreach ($_status['doors_state']['opening'] as $ouvrant) {
      if (!is_array($ouvrant) || !isset($ouvrant['identifier'], $ouvrant['state'])
          || !is_scalar($ouvrant['identifier']) || !is_scalar($ouvrant['state'])) {
        continue; // entrée malformée : ignorée (défensif, jamais de warning PHP)
      }
      $ouvert = (strcasecmp(trim((string) $ouvrant['state']), 'Open') == 0);
      if ($ouvert) {
        $auMoinsUnOuvert = true; // agrégat sur TOUS les ouvrants (connus + inconnus)
      }
      $cle = strtolower(trim((string) $ouvrant['identifier']));
      if (isset(self::OPENING_IDENTIFIERS[$cle])) {
        $valeurs[self::OPENING_IDENTIFIERS[$cle]] = $ouvert ? 1 : 0; // logicalId STATIQUE déclaré
      } else {
        log::add('stellantis', 'debug', 'Ouvrant non catalogué (identifier ' . self::aseptiser((string) $ouvrant['identifier'], 40) . ') — compté dans opening_alert');
      }
    }
    $valeurs['opening_alert'] = $auMoinsUnOuvert ? 1 : 0;
    return $valeurs;
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

  /**
   * UC31 : formate un horodatage API (RFC3339, ex. properties.createdAt de /lastPosition) en
   * 'Y-m-d H:i:s' pour affichage dans une commande info. Garde `=== false` OBLIGATOIRE avant date() :
   * PHP 8 lève un TypeError sur date(false, ...) et PHP 7.4 fabriquerait une epoch 1970 trompeuse ;
   * parseStatus() n'a PAS de try/catch par champ, une exception ici ferait échouer TOUT le refresh du
   * véhicule. `null` si la chaîne n'est pas exploitable (jamais de valeur fabriquée). Distinct
   * d'extraireFraicheur() (compare des timestamps int pour ne garder que le plus récent) : pas de
   * mutualisation, le point commun est trop mince pour justifier un refactor (churn non justifiée).
   */
  private static function formaterDateApi(string $_iso): ?string {
    $ts = strtotime($_iso);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
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
    // Pilotage à distance (UC12) : état du remote token OTP. « Non activé » n'est PAS une erreur (le
    // pilotage à distance est optionnel) → state=true dans ce cas ; « expiré » invite à renouveler.
    $otp = self::otpState();
    $lignes[] = array(
      'test' => __('Pilotage à distance (OTP)', __FILE__),
      'result' => ($otp == 'active')
        ? __('Activé', __FILE__)
        : (($otp == 'expired') ? __('Expiré — renouvellement nécessaire', __FILE__) : __('Non activé (optionnel)', __FILE__)),
      'advice' => ($otp == 'expired') ? __('Renouvelez le jeton distant depuis la configuration du plugin (sans SMS si possible)', __FILE__) : '',
      'state' => ($otp != 'expired'),
    );
    // UC19 : état de la connexion démon↔broker (FSM de reconnexion). AUCUN appel réseau (lecture cache
    // seule, alimentée par handleDaemonMessage). Affichée seulement si le démon tourne ET le pilotage à
    // distance est actif — sinon c'est du bruit (démon non lancé, ou OTP non activé : rien à signaler ici).
    if (self::deamon_info()['state'] == 'ok' && $otp == 'active') {
      $connState = (string) cache::byKey(self::DAEMON_CONN_STATE_KEY)->getValue('');
      if ($connState == 'auth_failed') {
        $lignes[] = array(
          'test' => __('Connexion du démon (pilotage à distance)', __FILE__),
          'result' => __('Authentification refusée — reconnexion suspendue', __FILE__),
          'advice' => __('Réactivez le pilotage à distance (OTP) puis redémarrez le démon', __FILE__),
          'state' => false,
        );
      } elseif ($connState == 'connected') {
        $lignes[] = array(
          'test' => __('Connexion du démon (pilotage à distance)', __FILE__),
          'result' => __('Connecté au broker', __FILE__),
          'advice' => '',
          'state' => true,
        );
      } elseif ($connState == 'retrying') {
        $lignes[] = array(
          'test' => __('Connexion du démon (pilotage à distance)', __FILE__),
          'result' => __('Reconnexion en cours', __FILE__),
          'advice' => '',
          'state' => true,
        );
      }
      // $connState == '' (non initialisé, ex. juste après démarrage) : ligne omise, jamais interprétée
      // comme un échec (cf. pattern otpState — le vide n'est pas une erreur).
    }
    foreach (eqLogic::byType('stellantis', true) as $eqLogic) {
      $nom = $eqLogic->getName();
      // UC24 : capacité requise pour estimer l'énergie de charge (VE/PHEV). Absente ⇒ énergie jamais estimée →
      // nudge actionnable. state=true : fonction estimative optionnelle, PAS une erreur dure (cf. ligne privacy).
      $motor = trim((string) $eqLogic->getConfiguration('energy', ''));
      if (($motor == 'Electric' || $motor == 'Hybrid') && trim((string) $eqLogic->getConfiguration('battery_capacity', '')) == '') {
        $lignes[] = array(
          'test'   => $nom,
          'result' => __('Capacité batterie non renseignée — énergie de charge non estimée', __FILE__),
          'advice' => __('Renseignez la capacité (kWh) dans la configuration du véhicule pour estimer l\'énergie de charge', __FILE__),
          'state'  => true,
        );
      }
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

  // UC19 — Résilience de la connexion démon (backoff + arrêt sur échec d'auth, cf. resources/demond/demond.py).
  const DAEMON_AUTH_ALERT_KEY = 'stellantis::daemon_auth_alert'; // cache : dédup/throttle de l'alerte auth démon
  const DAEMON_AUTH_COOLDOWN = 3600;                              // s : throttle de l'alerte (1x/h max)
  const DAEMON_CONN_STATE_KEY = 'stellantis::daemon_conn_state';  // cache (lifetime 0) : dernier état conn démon reçu ('connected'|'retrying'|'auth_failed')

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
  // - Réponses aux commandes : `.../to/cid/{CID}/#` (wildcard multi-niveaux, tous services). Porte les
  //   acks corrélés (correlation_id) consommés par traiterRetourCommande (UC18).
  // - Événements poussés (états charge/précond…) : `.../events/MPHRTServices/#`. ⚠️ Le code de référence
  //   s'abonne PAR VIN (`.../MPHRTServices/{vin}`) ; on utilise le wildcard `#` qui couvre tous les
  //   véhicules du compte sans dépendre de la liste des VIN. Un préfixe NU (`.../MPHRTServices/`, sans
  //   `#` ni VIN) ne matcherait AUCUNE publication (bug corrigé lors de l'audit UC11-16, 2026-07-10).
  public static function subscribeTopics(): array {
    $cid = self::getCustomerId();
    if ($cid == '') {
      return array();
    }
    return array(
      'psa/RemoteServices/to/cid/' . $cid . '/#',
      'psa/RemoteServices/events/MPHRTServices/#',
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
   * authentifié : l'OAuth2 est le pré-requis pour obtenir/rafraîchir le remote token OTP, qui est le
   * mot de passe MQTT réel (UC12) — sans OAuth2, pas de remote token, donc pas de connexion MQTT.
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
    cache::delete(self::DAEMON_CONN_STATE_KEY); // UC19 : l'état de connexion démon ne survit pas à l'arrêt
    sleep(1);
  }

  /**
   * Pousse au démon la connexion au broker (avec le REMOTE token OTP comme mot de passe MQTT — décision
   * UC12, alignée sur le code de référence RemoteClient ; ce n'est PAS le token OAuth2 REST) puis
   * l'abonnement aux topics de réponse. Best-effort. Sans remote token (pilotage non activé) → connexion
   * différée sans erreur. Le CID inconnu → pas d'abonnement (le broker reste connecté).
   */
  private static function pushDaemonConnect(): void {
    try {
      if (!stellantisApi::hasRemoteToken()) {
        log::add('stellantis', 'info', 'Démon : connexion MQTT différée (pilotage à distance non activé — activation OTP requise)');
        return;
      }
      $token = stellantisApi::getRemoteToken();
    } catch (stellantisException $e) {
      if ($e->getApiType() == 'otp_required') {
        log::add('stellantis', 'info', 'Démon : connexion MQTT différée (activation OTP requise)');
      } else {
        log::add('stellantis', 'warning', 'Démon : remote token indisponible pour la connexion MQTT (' . $e->getMessage() . ')');
      }
      return;
    } catch (\Throwable $e) {
      log::add('stellantis', 'warning', 'Démon : remote token indisponible pour la connexion MQTT (' . $e->getMessage() . ')');
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
      log::add('stellantis', 'info', 'Démon : abonnement différé (customer id inconnu)');
    }
  }

  // (Re)connecte le démon MQTT avec le remote token courant, uniquement s'il est lancé. Utilisé après
  // activation/renouvellement OTP pour établir la connexion sans attendre le prochain cron.
  private static function reconnecterDemonSiLance(): void {
    if (self::deamon_info()['state'] == 'ok') {
      self::pushDaemonConnect();
    }
  }

  /**
   * Propage le REMOTE token OTP au démon (mot de passe MQTT) s'il a changé et si le démon tourne. Rafraîchit
   * proactivement le remote token (TTL court ~890 s) via getRemoteToken(). Appelé par le cron. Sans
   * activation OTP → ne fait rien (pas d'alerte proactive répétée). En cas d'expiration non
   * rafraîchissable → alerte otp_required throttlée. Best-effort, jamais bloquant pour le polling REST.
   */
  public static function syncDaemonToken(): void {
    try {
      if (self::deamon_info()['state'] != 'ok') {
        return;
      }
      if (!stellantisApi::hasRemoteToken()) {
        return; // pilotage non activé (ou déjà purgé après otp_required) : rien à synchroniser
      }
      $token = stellantisApi::getRemoteToken(); // refresh proactif si proche de l'expiration
    } catch (stellantisException $e) {
      if ($e->getApiType() == 'otp_required') {
        self::alerterOtpRequired();
      } else {
        log::add('stellantis', 'debug', 'Démon : synchro remote token ignorée (' . $e->getMessage() . ')');
      }
      return;
    } catch (\Throwable $e) {
      log::add('stellantis', 'debug', 'Démon : synchro remote token ignorée (' . $e->getMessage() . ')');
      return;
    }
    if ((string) cache::byKey(self::DAEMON_TOKEN_MARKER)->getValue('') === self::marqueurToken($token)) {
      return; // remote token inchangé depuis le dernier push
    }
    self::sendToDaemon('set_token', array('password' => $token));
    self::memoriserMarqueurToken($token);
    log::add('stellantis', 'debug', 'Démon : remote token MQTT mis à jour');
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
   * @return bool true si le message a été écrit sur le socket, false sinon (démon injoignable / écriture KO).
   */
  public static function sendToDaemon(string $_action, array $_params = array()): bool {
    $params = $_params;
    $params['action'] = $_action;
    $params['apikey'] = jeedom::getApiKey('stellantis');
    $payload = json_encode($params);
    $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($socket === false) {
      log::add('stellantis', 'warning', 'Démon : création du socket impossible pour l\'action ' . $_action);
      return false;
    }
    try {
      if (@socket_connect($socket, '127.0.0.1', self::socketPort()) === false) {
        log::add('stellantis', 'warning', 'Démon : connexion au socket impossible (démon arrêté ?) pour l\'action ' . $_action);
        return false;
      }
      return @socket_write($socket, $payload, strlen($payload)) !== false;
    } finally {
      @socket_close($socket);
    }
  }

  /**
   * Traite un message remonté par le démon (callback jeeStellantis.php). Dispatch défensif sur 'type' :
   * - token_expired : rafraîchit le token OAuth2 (une fois, borné — pas de boucle) et le repousse ;
   * - connected/disconnected : trace + MAJ de l'état de connexion (UC19) ;
   * - auth_failed (UC19) : le démon a cessé de retenter après N échecs d'auth → alerte utilisateur throttlée ;
   * - message : retour d'état des commandes (UC18, traiterRetourCommande) ;
   * - inconnu : ignoré silencieusement (types futurs).
   * Jamais de levée : appelé depuis un point d'entrée externe qui doit toujours répondre 200.
   */
  public static function handleDaemonMessage(array $_data): void {
    $type = isset($_data['type']) ? (string) $_data['type'] : '';
    switch ($type) {
      case 'token_expired':
        // Rejet 400 du broker = REMOTE token expiré. Rafraîchissement réactif borné (refresh_token du
        // remote token, PAS de régénération OTP silencieuse). Échec non rafraîchissable → alerte
        // otp_required throttlée (pas de boucle refresh→set_token→400→refresh).
        try {
          stellantisApi::refreshRemoteToken();
          $token = stellantisApi::getRemoteToken();
          self::sendToDaemon('set_token', array('password' => $token));
          self::memoriserMarqueurToken($token);
          log::add('stellantis', 'info', 'Démon : remote token rafraîchi après rejet 400 du broker');
        } catch (stellantisException $e) {
          if ($e->getApiType() == 'otp_required') {
            self::alerterOtpRequired();
          } else {
            log::add('stellantis', 'warning', 'Démon : échec du rafraîchissement du remote token après 400 (' . $e->getMessage() . ')');
          }
        } catch (\Throwable $e) {
          log::add('stellantis', 'warning', 'Démon : échec du rafraîchissement du remote token après 400 (' . $e->getMessage() . ')');
        }
        break;
      case 'connected':
        // UC19 : une connexion réussie efface toute alerte/état « auth requise » précédent — la FSM du
        // démon vient de démontrer que l'authentification fonctionne à nouveau.
        log::add('stellantis', 'info', 'Démon : connecté au broker MQTT');
        message::removeAll('stellantis', 'daemon_auth_failed');
        cache::delete(self::DAEMON_AUTH_ALERT_KEY);
        cache::set(self::DAEMON_CONN_STATE_KEY, 'connected', 0);
        break;
      case 'disconnected':
        // UC19 : déconnexion transitoire (le démon retente déjà avec backoff, déjà throttlé côté démon —
        // pas de message::add ici, juste l'état de santé).
        log::add('stellantis', 'info', 'Démon : déconnecté du broker MQTT');
        cache::set(self::DAEMON_CONN_STATE_KEY, 'retrying', 0);
        break;
      case 'auth_failed':
        // UC19 : N échecs d'authentification consécutifs → le démon a cessé de retenter (reste vivant).
        // Jamais de levée (point d'entrée externe) : alerte utilisateur throttlée + état de santé.
        log::add('stellantis', 'warning', 'Démon : authentification refusée par le broker après plusieurs tentatives — reconnexion suspendue');
        cache::set(self::DAEMON_CONN_STATE_KEY, 'auth_failed', 0);
        self::alerterDaemonAuthFailed();
        break;
      case 'message':
        // On trace la réception, puis on interprète l'ack (UC18, traiterRetourCommande).
        // Le topic vient du broker (donnée externe) → aseptiser avant log (anti log-injection) : retrait des
        // caractères de contrôle + troncature.
        $topic = isset($_data['topic']) ? (string) $_data['topic'] : '';
        $topicLog = self::aseptiser($topic);
        log::add('stellantis', 'debug', 'Démon : message MQTT reçu sur ' . ($topicLog != '' ? $topicLog : '?'));
        // UC18 : interpréter l'ack d'une commande qu'on a émise (wakeup/charge/précond/portes/klaxon/feux),
        // remonter un retour d'état exploitable (last_command_result), signaler les échecs et programmer le
        // refresh REST au prochain cron. Aucun appel réseau ici (le callback doit répondre 200 vite).
        // On passe le topic BRUT (le filtre de préfixe est une décision de routage : il ne doit pas dépendre
        // de l'aseptisation de présentation du log — cf. review sécurité UC18).
        self::traiterRetourCommande($_data, $topic);
        break;
      default:
        // Type inconnu (futurs UC) : ignoré sans erreur.
        break;
    }
  }

  // Aseptise une chaîne d'origine externe (topic/payload broker, cause de refus API…) avant journalisation :
  // retrait des caractères de contrôle (anti log-injection) + troncature. NE protège PAS contre le HTML —
  // pour un usage UI, envelopper le retour dans htmlspecialchars() en plus (cf. traiterRetourCommande).
  private static function aseptiser(string $_texte, int $_max = 200): string {
    return mb_substr(preg_replace('/[[:cntrl:]]/', '', $_texte), 0, $_max);
  }

  /**
   * UC18 — Retour d'état asynchrone des commandes à distance. À réception d'un message MQTT sur le topic
   * d'ACK (`.../to/cid/...`), interprète le code (return_code prioritaire sinon process_code) et :
   *  - met à jour l'info `last_command_result` du véhicule concerné (retour utilisateur : succès, états
   *    intermédiaires, échec — jamais figé sur « Accepté » car le message TERMINAL, résolu par vin,
   *    réactualise l'info) ;
   *  - signale les échecs / le renouvellement de session dans le centre de messages (jamais silencieux) ;
   *  - programme un refresh REST au prochain cron() pour les commandes CORRÉLÉES (stateful) atteignant le
   *    véhicule (flag consommé par cron() — aucun appel réseau ici, le callback doit répondre 200 vite).
   * Corrélation ack→véhicule : correlation_id (précis, distingue deux commandes) puis repli vin
   * (per-véhicule). Best-effort, jamais de levée (point d'entrée externe). Les events/MPHRTServices poussés
   * (états spontanés, sans commande émise) sont hors périmètre : filtrés par le préfixe de topic.
   */
  private static function traiterRetourCommande(array $_data, string $_topic): void {
    // 1) Filtre topic (décision de routage, sur le topic BRUT reçu du démon) : seuls les ACK de commande
    //    (.../to/cid/...) portent un retour d'état. Les events poussés (events/MPHRTServices) n'ont pas de
    //    lien avec une commande émise → ignorés (hors UC18).
    if (strpos($_topic, self::MQTT_RESP_TOPIC_PREFIX) !== 0) {
      return;
    }
    $payload = isset($_data['payload']) ? $_data['payload'] : null;
    if (!is_array($payload)) {
      return;
    }
    // 2) Parse défensif (données broker externes, NON FIABLES). return_code prioritaire sur process_code
    //    (aligné code de référence : `data.get("return_code") or data.get("process_code")`). Toutes les
    //    valeurs réinjectées dans un log / la valeur d'une commande / le centre de messages sont aseptisées
    //    (caractères de contrôle + troncature) PUIS échappées HTML (défense en profondeur XSS stocké, review
    //    sécurité UC18 — l'échappement est transparent pour les codes/raisons légitimes). L'échappement des
    //    codes rc/pc est inoffensif pour les comparaisons ci-dessous (les codes légitimes sont numériques).
    $rc = htmlspecialchars(self::aseptiser((isset($payload['return_code']) && is_scalar($payload['return_code'])) ? (string) $payload['return_code'] : '', 40), ENT_QUOTES, 'UTF-8');
    $pc = htmlspecialchars(self::aseptiser((isset($payload['process_code']) && is_scalar($payload['process_code'])) ? (string) $payload['process_code'] : '', 40), ENT_QUOTES, 'UTF-8');
    $corr = (isset($payload['correlation_id']) && is_scalar($payload['correlation_id'])) ? (string) $payload['correlation_id'] : '';
    $vin = (isset($payload['vin']) && is_scalar($payload['vin'])) ? (string) $payload['vin'] : '';
    $reasonBrut = '';
    if (isset($payload['reason']) && is_scalar($payload['reason'])) {
      $reasonBrut = (string) $payload['reason'];
    } elseif (isset($payload['process_message']) && is_scalar($payload['process_message'])) {
      $reasonBrut = (string) $payload['process_message'];
    }
    $reason = htmlspecialchars(self::aseptiser($reasonBrut), ENT_QUOTES, 'UTF-8');
    // 3) Interprétation. known=false ⇒ message to/cid sans code exploitable → on ne touche à rien (pas de
    //    faux « Échec »).
    $interp = self::interpreterAck($rc, $pc, $reason);
    if (!$interp['known']) {
      return;
    }
    // 4) Résolution du véhicule : correlation_id (précis) puis repli vin (per-véhicule).
    $eqId = 0;
    $corrMapped = false;
    if ($corr != '') {
      $eqId = (int) cache::byKey(self::CMD_CORR_KEY . $corr)->getValue(0);
      if ($eqId > 0) {
        $corrMapped = true;
      }
    }
    $eqLogic = null;
    if ($eqId > 0) {
      $eqLogic = eqLogic::byId($eqId);
    } elseif ($vin != '') {
      $eqLogic = eqLogic::byLogicalId($vin, 'stellantis');
    }
    // eqLogic introuvable ou désactivé (véhicule disparu du compte, désactivé par la sync) → on ignore.
    if (!is_object($eqLogic) || !($eqLogic instanceof stellantis) || !$eqLogic->getIsEnable()) {
      return;
    }
    $eqId = (int) $eqLogic->getId();
    // 5) MAJ de l'info retour d'état (création paresseuse pour les véhicules antérieurs à UC18).
    try {
      $eqLogic->checkAndUpdateCmd($eqLogic->ensureCommand(self::CMD_RESULT_LOGICAL_ID), $interp['result']);
    } catch (\Throwable $e) {
      log::add('stellantis', 'warning', 'Retour d\'état : MAJ de last_command_result impossible pour l\'équipement #' . $eqId . ' (' . $e->getMessage() . ')');
    }
    // 6) Notif centre de messages (jamais silencieux). removeAll avant add ⇒ au plus un message actif par
    //    véhicule, actualisé (pas d'empilement — même pattern que otp_required).
    $cleMessage = 'command_failed::' . $eqId;
    if ($interp['notify'] == 'failure') {
      message::removeAll('stellantis', $cleMessage);
      $detail = ($reason != '') ? $reason : ('code ' . ($rc != '' ? $rc : $pc));
      message::add('stellantis', sprintf(__('Commande à distance en échec pour l\'équipement #%1$d : %2$s', __FILE__), $eqId, $detail), '', $cleMessage);
      log::add('stellantis', 'warning', 'Retour d\'état : commande en échec pour l\'équipement #' . $eqId . ' (' . $detail . ')');
    } elseif ($interp['notify'] == 'token') {
      message::removeAll('stellantis', $cleMessage);
      message::add('stellantis', sprintf(__('Commande à distance : session renouvelée pour l\'équipement #%d, veuillez réessayer', __FILE__), $eqId), '', $cleMessage);
      log::add('stellantis', 'info', 'Retour d\'état : session renouvelée (token) pour l\'équipement #' . $eqId . ' — commande à rejouer');
    } elseif ($interp['notify'] == 'clear') {
      // Succès : lever une éventuelle notif d'échec antérieure de ce véhicule.
      message::removeAll('stellantis', $cleMessage);
    }
    // 7) Refresh REST au prochain cron : uniquement pour une commande CORRÉLÉE (stateful) atteignant le
    //    véhicule. Le repli vin (klaxon/feux stateless, message terminal sans corr) ne programme rien
    //    (anti-ban). TTL du flag > backoff 429 : survit si le cron sort tôt.
    if ($corrMapped && $interp['refresh']) {
      cache::set(self::CMD_PENDING_KEY . $eqId, '1', self::CMD_PENDING_TTL);
      log::add('stellantis', 'info', 'Retour d\'état : ack corrélé (équipement #' . $eqId . '), rafraîchissement programmé au prochain cron');
    }
    // 8) Cycle de vie du mapping : conservé sur les états intermédiaires (900/903/901) pour capter un
    //    éventuel message terminal encore corrélé ; supprimé sur un état terminal (succès/échec/400).
    if ($corr != '' && !$interp['keepMapping']) {
      cache::delete(self::CMD_CORR_KEY . $corr);
    }
  }

  /**
   * UC18 — Interprète les codes d'ack MQTT (return_code prioritaire sinon process_code) en instructions de
   * traitement. Sémantique (analyse § 1.3 + code de référence psa_car_controller) : return_code 0=succès /
   * 400=token expiré / autre=échec ; process_code 900=acceptée / 903=transmise / 901=en veille / autre
   * (113/300/500)=échec. 900/903/901 sont INTERMÉDIAIRES (mapping conservé) ; le terminal est un succès ou
   * un échec. Retourne ['result'(string UI), 'refresh'(bool), 'keepMapping'(bool),
   * 'notify'('none'|'failure'|'token'|'clear'), 'known'(bool)]. known=false ⇒ aucun code exploitable.
   * ⚠️ i18n : toutes les chaînes UI sont des __() LITTÉRAUX ici (scan statique de l'extracteur, piège UC07).
   */
  private static function interpreterAck(string $_rc, string $_pc, string $_reason): array {
    $echec = ($_reason != '')
      ? sprintf(__('Échec : %s', __FILE__), $_reason)
      : sprintf(__('Échec (code %s)', __FILE__), ($_rc != '' ? $_rc : $_pc));
    if ($_rc !== '') {
      if ($_rc === '0') {
        return array('result' => __('Succès', __FILE__), 'refresh' => true, 'keepMapping' => false, 'notify' => 'clear', 'known' => true);
      }
      if ($_rc === '400') {
        return array('result' => __('Session renouvelée, veuillez réessayer', __FILE__), 'refresh' => false, 'keepMapping' => false, 'notify' => 'token', 'known' => true);
      }
      return array('result' => $echec, 'refresh' => false, 'keepMapping' => false, 'notify' => 'failure', 'known' => true);
    }
    if ($_pc !== '') {
      if ($_pc === '900') {
        return array('result' => __('Acceptée par le véhicule', __FILE__), 'refresh' => true, 'keepMapping' => true, 'notify' => 'none', 'known' => true);
      }
      if ($_pc === '903') {
        return array('result' => __('Transmise au véhicule', __FILE__), 'refresh' => true, 'keepMapping' => true, 'notify' => 'none', 'known' => true);
      }
      if ($_pc === '901') {
        return array('result' => __('Véhicule en veille', __FILE__), 'refresh' => false, 'keepMapping' => true, 'notify' => 'none', 'known' => true);
      }
      return array('result' => $echec, 'refresh' => false, 'keepMapping' => false, 'notify' => 'failure', 'known' => true);
    }
    return array('result' => '', 'refresh' => false, 'keepMapping' => true, 'notify' => 'none', 'known' => false);
  }

  /* * ******************* UC12 — Activation OTP & remote token ******************* */

  const OTP_DEVICE_KEY = 'otp_device';           // config (chiffré utils::encrypt) : device OTP provisionné (long-lived, survit à cache::flush)
  const OTP_SMS_COUNT_KEY = 'otp_sms_count';     // config : compteur d'activations SMS 0..20 À VIE (jamais remis à 0 auto ; survit à cache::flush)
  const OTP_SMS_MAX = 20;                          // quota dur serveur : 20 activations SMS / compte (blocage définitif au-delà)
  const OTP_SMS_PENDING_KEY = 'otp_sms_pending'; // config : '1' entre l'envoi du SMS et l'activation réussie
  const OTP_CODE_QUOTA_KEY = 'stellantis::otp_code_quota'; // cache (fenêtre glissante 24 h)
  const OTP_CODE_QUOTA_MAX = 6;                    // quota dur serveur : 6 générations de code OTP / 24 h
  const OTP_CODE_QUOTA_FENETRE = 86400;
  const OTP_ALERT_KEY = 'stellantis::otp_alert'; // cache : cooldown anti-spam de l'alerte otp_required
  const OTP_ALERT_COOLDOWN = 86400;

  /**
   * Déclenche l'envoi du SMS d'activation OTP. Garde le quota « 20 / compte à vie » (config, survit à un
   * cache::flush) AVANT l'appel : le compteur est incrémenté avant l'envoi car le SMS consomme le quota
   * serveur même si le process meurt ensuite (conservateur : mieux vaut sur-compter que dépasser 20).
   * Retour uniforme {ok, message}, jamais de levée.
   */
  public static function requestOtpSms(): array {
    if (!stellantisApi::getTokenInfo()['authenticated']) {
      return array('ok' => false, 'message' => __('Connectez d\'abord le plugin à votre compte (étapes 1 et 2 ci-dessus) avant d\'activer le pilotage à distance.', __FILE__));
    }
    $count = (int) config::byKey(self::OTP_SMS_COUNT_KEY, 'stellantis', 0);
    if ($count >= self::OTP_SMS_MAX) {
      return array('ok' => false, 'message' => sprintf(__('Quota d\'activations SMS atteint (%d / compte). Ce quota est définitif côté Stellantis : aucun nouveau SMS ne sera demandé pour éviter de bloquer votre compte.', __FILE__), self::OTP_SMS_MAX));
    }
    try {
      config::save(self::OTP_SMS_COUNT_KEY, (string) ($count + 1), 'stellantis');
      config::save(self::OTP_SMS_PENDING_KEY, '1', 'stellantis');
      stellantisApi::requestSmsOtp();
      return array('ok' => true, 'message' => __('SMS envoyé. Saisissez le code reçu et le code PIN de votre application mobile, puis activez.', __FILE__));
    } catch (\Throwable $e) {
      log::add('stellantis', 'warning', 'UC12 : échec de la demande de SMS OTP (' . $e->getMessage() . ')');
      return array('ok' => false, 'message' => __('Échec de l\'envoi du SMS d\'activation. Vérifiez la connexion du compte et réessayez.', __FILE__));
    }
  }

  /**
   * Réalise l'activation OTP complète : code SMS + code PIN → provisionne le device (helper Python) →
   * obtient le remote token. Garde le quota de génération de code (6/24 h). Retour uniforme {ok, message}.
   */
  public static function activateOtp(string $_sms, string $_pin): array {
    $sms = trim($_sms);
    $pin = trim($_pin);
    if ($sms == '' || $pin == '') {
      return array('ok' => false, 'message' => __('Renseignez le code reçu par SMS et le code PIN de votre application mobile.', __FILE__));
    }
    try {
      self::consommerQuotaCodeOtp();
    } catch (stellantisException $e) {
      return array('ok' => false, 'message' => $e->getMessage());
    }
    $res = self::runOtpHelper(array('action' => 'activate', 'sms' => $sms, 'pin' => $pin));
    if (($res['status'] ?? '') != 'ok' || ($res['device_secret'] ?? '') == '' || ($res['otp_code'] ?? '') == '') {
      return array('ok' => false, 'message' => self::messageStatutOtp((string) ($res['status'] ?? 'error')));
    }
    self::storeOtpDevice((string) $res['device_secret']);
    try {
      stellantisApi::requestRemoteToken((string) $res['otp_code']);
    } catch (\Throwable $e) {
      // L'appareil OTP est déjà provisionné et stocké (otpState() = 'expired') : le rattrapage le moins
      // coûteux est « Renouveler » (sans nouveau SMS), pas une activation complète. On oriente vers ça.
      log::add('stellantis', 'warning', 'UC12 : échec d\'obtention du remote token après activation (' . $e->getMessage() . ')');
      return array('ok' => false, 'message' => __('L\'appareil OTP a été enregistré mais l\'obtention du jeton distant a échoué. Rechargez la page et utilisez « Renouveler le jeton distant » (sans nouveau SMS).', __FILE__));
    }
    config::save(self::OTP_SMS_PENDING_KEY, '', 'stellantis');
    self::resolveCustomerId();
    self::reconnecterDemonSiLance();
    cache::delete(self::OTP_ALERT_KEY);
    message::removeAll('stellantis', 'otp_required');
    log::add('stellantis', 'info', 'UC12 : pilotage à distance activé (remote token obtenu)');
    return array('ok' => true, 'message' => __('Pilotage à distance activé.', __FILE__));
  }

  /**
   * Renouvelle le remote token à partir du device DÉJÀ provisionné, SANS nouveau SMS (génère un code OTP
   * — garde le quota 6/24 h). Utile après expiration (état « expiré ») pour éviter de consommer un SMS.
   * Retour uniforme {ok, message}.
   */
  public static function renewRemoteToken(): array {
    if (!self::hasOtpDevice()) {
      return array('ok' => false, 'message' => __('Aucun appareil OTP enregistré : réalisez l\'activation complète (SMS + code PIN).', __FILE__));
    }
    $code = self::generateOtpCode();
    if ($code === null) {
      return array('ok' => false, 'message' => __('Impossible de générer un code OTP (quota atteint ou appareil invalide). Refaites au besoin l\'activation complète (SMS + code PIN).', __FILE__));
    }
    try {
      stellantisApi::requestRemoteToken($code);
    } catch (\Throwable $e) {
      log::add('stellantis', 'warning', 'UC12 : échec du renouvellement du remote token (' . $e->getMessage() . ')');
      return array('ok' => false, 'message' => __('Le renouvellement a échoué : refaites l\'activation complète (SMS + code PIN).', __FILE__));
    }
    self::reconnecterDemonSiLance();
    cache::delete(self::OTP_ALERT_KEY);
    message::removeAll('stellantis', 'otp_required');
    log::add('stellantis', 'info', 'UC12 : remote token renouvelé (sans SMS)');
    return array('ok' => true, 'message' => __('Jeton distant renouvelé.', __FILE__));
  }

  // Génère un code OTP à partir du device stocké (appel réseau via le helper → consomme 1 unité du quota
  // 6/24 h). null si device absent, quota atteint, ou échec. Device invalide → purge (force ré-activation).
  private static function generateOtpCode(): ?string {
    $device = self::readOtpDevice();
    if ($device === null) {
      return null;
    }
    try {
      self::consommerQuotaCodeOtp();
    } catch (stellantisException $e) {
      log::add('stellantis', 'warning', 'UC12 : génération de code OTP refusée (' . $e->getMessage() . ')');
      return null;
    }
    $res = self::runOtpHelper(array('action' => 'code', 'device_secret' => $device));
    if (($res['status'] ?? '') != 'ok' || ($res['otp_code'] ?? '') == '') {
      if (($res['status'] ?? '') == 'device_invalid') {
        config::save(self::OTP_DEVICE_KEY, '', 'stellantis');
        log::add('stellantis', 'warning', 'UC12 : appareil OTP invalide → purgé (ré-activation complète nécessaire)');
      }
      return null;
    }
    // L'état du device roule côté serveur : re-stocker le device renvoyé pour la prochaine génération.
    if (($res['device_secret'] ?? '') != '') {
      self::storeOtpDevice((string) $res['device_secret']);
    }
    return (string) $res['otp_code'];
  }

  // Quota local de génération de code OTP (fenêtre glissante 24 h) — même gabarit que
  // stellantisApi::consommerQuotaRefresh. @throws stellantisException 'rate_limited' si atteint.
  private static function consommerQuotaCodeOtp(): void {
    $quota = json_decode((string) cache::byKey(self::OTP_CODE_QUOTA_KEY)->getValue(''), true);
    $maintenant = time();
    if (!is_array($quota) || !isset($quota['windowStart']) || $maintenant - $quota['windowStart'] > self::OTP_CODE_QUOTA_FENETRE) {
      $quota = array('windowStart' => $maintenant, 'count' => 0);
    }
    if ($quota['count'] >= self::OTP_CODE_QUOTA_MAX) {
      throw new stellantisException(sprintf(__('Quota de génération de codes OTP atteint (%d / 24 h). Réessayez plus tard.', __FILE__), self::OTP_CODE_QUOTA_MAX), 429, 'rate_limited');
    }
    $quota['count']++;
    cache::set(self::OTP_CODE_QUOTA_KEY, json_encode($quota), self::OTP_CODE_QUOTA_FENETRE);
  }

  /**
   * Exécute le helper OTP Python (resources/otp_helper.py) via proc_open : requête JSON sur STDIN (jamais
   * argv : sms/pin/device sensibles), réponse JSON sur STDOUT. Retourne le tableau décodé (au minimum
   * {status}) ; {status:'error'} si le helper est injoignable/illisible. Jamais de secret loggué.
   */
  private static function runOtpHelper(array $_request): array {
    $dossier = realpath(__DIR__ . '/../../resources');
    if ($dossier === false) {
      log::add('stellantis', 'warning', 'UC12 : dossier resources/ introuvable pour le helper OTP');
      return array('status' => 'error');
    }
    $cmd = system::getCmdPython3('stellantis') . ' ' . escapeshellarg($dossier . '/otp_helper.py');
    $descriptors = array(
      0 => array('pipe', 'r'),           // STDIN : requête JSON
      1 => array('pipe', 'w'),           // STDOUT : réponse JSON
      2 => array('file', '/dev/null', 'w'), // STDERR : ignoré (jamais de secret ; traceback éventuel écarté)
    );
    $pipes = array();
    $process = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
      log::add('stellantis', 'warning', 'UC12 : impossible de lancer le helper OTP');
      return array('status' => 'error');
    }
    fwrite($pipes[0], json_encode($_request));
    fclose($pipes[0]);
    $sortie = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);
    $resultat = json_decode((string) $sortie, true);
    if (!is_array($resultat) || !isset($resultat['status'])) {
      log::add('stellantis', 'warning', 'UC12 : helper OTP injoignable ou sortie illisible (dépendances Python manquantes ?)');
      return array('status' => 'error');
    }
    return $resultat;
  }

  // Traduit un status d'échec du helper OTP en message utilisateur i18n (jamais de détail technique brut).
  private static function messageStatutOtp(string $_status): string {
    switch ($_status) {
      case 'deps_missing':
        return __('Dépendances Python manquantes : installez les dépendances du plugin, puis réessayez.', __FILE__);
      case 'bad_input':
        return __('Code SMS ou code PIN manquant ou invalide.', __FILE__);
      case 'device_invalid':
        return __('Appareil OTP invalide : refaites l\'activation complète (SMS + code PIN).', __FILE__);
      case 'otp_error':
      default:
        return __('Activation refusée : vérifiez le code SMS et le code PIN, puis réessayez. Attention au quota (6 codes / 24 h).', __FILE__);
    }
  }

  // Stockage chiffré du device OTP en config (survit à cache::flush, contrairement au cache).
  // ⚠️ INVARIANT DE SÉCURITÉ : ce blob est désérialisé par pickle.loads() dans otp_helper.py. Il ne doit
  // JAMAIS provenir d'une autre source que readOtpDevice() (cache config chiffré) : ne jamais alimenter
  // storeOtpDevice()/runOtpHelper('code') avec une donnée d'origine externe non chiffrée par ce plugin.
  private static function storeOtpDevice(string $_blob): void {
    config::save(self::OTP_DEVICE_KEY, utils::encrypt($_blob), 'stellantis');
  }

  private static function readOtpDevice(): ?string {
    $brut = (string) config::byKey(self::OTP_DEVICE_KEY, 'stellantis', '');
    if ($brut == '') {
      return null;
    }
    $clair = utils::decrypt($brut);
    return ($clair !== '' && $clair !== false) ? $clair : null;
  }

  public static function hasOtpDevice(): bool {
    return self::readOtpDevice() !== null;
  }

  // État du pilotage à distance pour l'UI/santé : 'active' (remote token présent), 'expired' (device
  // présent mais plus de token → renouvelable sans SMS), 'pending' (SMS envoyé, activation pas encore
  // finalisée — l'utilisateur doit saisir code+PIN, sans re-consommer de SMS), 'inactive' (jamais activé).
  public static function otpState(): string {
    if (stellantisApi::hasRemoteToken()) {
      return 'active';
    }
    if (self::hasOtpDevice()) {
      return 'expired';
    }
    return (config::byKey(self::OTP_SMS_PENDING_KEY, 'stellantis', '') == '1') ? 'pending' : 'inactive';
  }

  // Nombre d'activations SMS déjà consommées (compteur à vie), pour l'UI.
  public static function otpSmsCount(): int {
    return (int) config::byKey(self::OTP_SMS_COUNT_KEY, 'stellantis', 0);
  }

  /**
   * Résout le customer id (CID) nécessaire aux topics MQTT via GET /user (best-effort, jamais bloquant).
   * Cherche une valeur de forme AP-ACNT…/OV-ACNT… dans la réponse (structure non figée). Ne loggue jamais
   * le corps /user (PII) — seulement le CID trouvé. Échec → repli sur la saisie manuelle (champ config).
   */
  private static function resolveCustomerId(): void {
    if (self::getCustomerId() != '') {
      return; // déjà connu (config manuelle ou résolution antérieure)
    }
    try {
      $reponse = stellantisApi::callWithToken('GET', '/user');
    } catch (\Throwable $e) {
      log::add('stellantis', 'info', 'UC12 : résolution du customer id ignorée (' . $e->getMessage() . ')');
      return;
    }
    $cid = self::extraireCustomerId($reponse);
    if ($cid == '') {
      log::add('stellantis', 'info', 'UC12 : customer id introuvable dans /user (repli : saisie manuelle dans la config)');
      return;
    }
    config::save('customer_id', $cid, 'stellantis');
    log::add('stellantis', 'info', 'UC12 : customer id résolu (' . $cid . ')');
    // Le CID débloque l'abonnement démon (UC11) : (ré)abonner si le démon tourne.
    if (self::deamon_info()['state'] == 'ok') {
      $topics = self::subscribeTopics();
      if (count($topics) > 0) {
        self::sendToDaemon('subscribe', array('topics' => $topics));
      }
    }
  }

  // Motif du customer id MQTT (AP-ACNT… Peugeot/Citroën/DS, OV-ACNT… Opel/Vauxhall). Pleinement ancré :
  // le CID est ensuite interpolé dans les topics MQTT (subscribeTopics) → jamais de valeur non conforme.
  const OTP_CID_REGEX = '/^(AP|OV)-ACNT[A-Za-z0-9._-]*$/';

  // Recherche récursive d'une valeur de forme AP-ACNT…/OV-ACNT… (customer id MQTT) dans la réponse /user.
  private static function extraireCustomerId($_data): string {
    if (is_string($_data)) {
      return preg_match(self::OTP_CID_REGEX, $_data) ? $_data : '';
    }
    if (is_array($_data)) {
      foreach (array('customer_id', 'customerId', 'id') as $cle) {
        if (isset($_data[$cle]) && is_string($_data[$cle]) && preg_match(self::OTP_CID_REGEX, $_data[$cle])) {
          return $_data[$cle];
        }
      }
      foreach ($_data as $valeur) {
        $trouve = self::extraireCustomerId($valeur);
        if ($trouve != '') {
          return $trouve;
        }
      }
    }
    return '';
  }

  // Alerte utilisateur « refaire l'activation OTP », THROTTLÉE (1×/24 h) : appelée depuis le cron
  // (chaque minute) et les callbacks démon → sans garde, elle noierait notifications et logs.
  private static function alerterOtpRequired(): void {
    if (cache::byKey(self::OTP_ALERT_KEY)->getValue('') != '') {
      return;
    }
    cache::set(self::OTP_ALERT_KEY, '1', self::OTP_ALERT_COOLDOWN);
    message::add('stellantis', __('Le jeton de pilotage à distance a expiré. Renouvelez-le (sans SMS si un appareil OTP est encore enregistré) ou refaites l\'activation OTP depuis la configuration du plugin.', __FILE__), '', 'otp_required');
    log::add('stellantis', 'warning', 'UC12 : remote token expiré → renouvellement/ré-activation OTP requis (alerte utilisateur)');
  }

  // UC19 — Alerte utilisateur « le démon n'arrive plus à s'authentifier auprès du broker », THROTTLÉE
  // (calquée sur alerterOtpRequired) : appelée depuis handleDaemonMessage (cas auth_failed) → sans garde,
  // elle noierait le centre de messages si le démon repassait plusieurs fois en état bloqué.
  private static function alerterDaemonAuthFailed(): void {
    if (cache::byKey(self::DAEMON_AUTH_ALERT_KEY)->getValue('') != '') {
      return;
    }
    cache::set(self::DAEMON_AUTH_ALERT_KEY, '1', self::DAEMON_AUTH_COOLDOWN);
    message::removeAll('stellantis', 'daemon_auth_failed');
    message::add('stellantis', __('Le démon de pilotage à distance n\'a pas pu s\'authentifier auprès du broker après plusieurs tentatives. Vérifiez votre connexion et, si nécessaire, réactivez le pilotage à distance (OTP) depuis la configuration du plugin, puis redémarrez le démon.', __FILE__), '', 'daemon_auth_failed');
    log::add('stellantis', 'warning', 'UC19 : démon bloqué après plusieurs échecs d\'authentification MQTT (alerte utilisateur)');
  }

  // Purge le remote token + le device OTP + l'état d'alerte/pending. Appelée sur changement de
  // credentials/marque (device devenu incohérent) et à la désinstallation. NE réinitialise PAS le
  // compteur SMS à vie (sécurité : ne jamais rouvrir un quota potentiellement épuisé côté serveur).
  public static function purgeOtp(): void {
    stellantisApi::purgeRemoteTokenCache();
    config::save(self::OTP_DEVICE_KEY, '', 'stellantis');
    config::save(self::OTP_SMS_PENDING_KEY, '', 'stellantis');
    cache::delete(self::OTP_ALERT_KEY);
    message::removeAll('stellantis', 'otp_required');
    // UC19 : purge l'état/alerte de résilience démon (cohérence cycle de vie avec la purge OTP, dont
    // dépend l'authentification MQTT — cf. -tech UC19 § Cycle de vie).
    message::removeAll('stellantis', 'daemon_auth_failed');
    cache::delete(self::DAEMON_AUTH_ALERT_KEY);
    cache::delete(self::DAEMON_CONN_STATE_KEY);
  }

  /* * ******************* UC13 — Wakeup / rafraîchissement à la demande ******************* */

  const WAKEUP_SERVICE = '/VehCharge/state';                     // segment de service du topic MQTT (contrat RemoteClient.wakeup)
  const WAKEUP_ACTION = 'state';                                  // valeur de req_parameters.action
  const WAKEUP_COOLDOWN = 300;                                    // cooldown per-véhicule (s) : ~5 min mini entre deux réveils
  const WAKEUP_COOLDOWN_KEY = 'stellantis::wakeup_cooldown::';   // + eqId (cache) : dernier réveil de CE véhicule
  const CMD_PENDING_KEY = 'stellantis::cmd_pending::';           // + eqId (cache) : refresh REST dû après l'ack d'UNE commande MQTT (wakeup/charge/…)
  const CMD_PENDING_TTL = 1200;                                  // TTL du flag pending : > backoff 429 (RATELIMIT_COOLDOWN) pour ne pas perdre le refresh si le cron sort tôt
  const CMD_CORR_KEY = 'stellantis::cmd_corr::';                 // + correlation_id (cache) => eqId : corrélation ack→véhicule (toute commande MQTT)
  const CMD_CORR_TTL = 300;                                      // TTL du mapping corrélation→eqId (s) : fenêtre d'attente de l'ack d'une commande (wakeup/charge/…)
  const CMD_RESULT_LOGICAL_ID = 'last_command_result';          // logicalId de l'info « Dernier résultat commande » (UC18), alimentée par le callback démon
  const MQTT_RESP_TOPIC_PREFIX = 'psa/RemoteServices/to/cid/';  // préfixe des topics d'ACK de commande (UC18) ; les events/MPHRTServices sont hors périmètre du retour d'état
  const WAKEUP_QUOTA_KEY = 'stellantis::wakeup_quota';           // quota GLOBAL compte (fenêtre glissante JSON)
  const WAKEUP_QUOTA_MAX = 5;                                     // marge sous le ban serveur (~6 wakeups / 20 min, niveau COMPTE)
  const WAKEUP_QUOTA_FENETRE = 1200;                             // fenêtre du quota global (s) = 20 min

  /**
   * Force une remontée d'état fraîche de CE véhicule (batterie/charge/position…) via une commande MQTT
   * wakeup — ce que le polling REST seul ne peut pas faire. Action DÉLIBÉRÉE (bouton), jamais déclenchée
   * par le cron. Garde-fous stricts anti-ban / batterie 12 V : cooldown per-véhicule + quota global compte.
   * L'ack asynchrone (topic to/cid) programmera un refresh REST au prochain cron (handleDaemonMessage).
   * @throws stellantisException 'rate_limited' (cooldown/quota), 'otp_required' (OTP non activé), sinon api_error
   */
  public function wakeup(): void {
    // Note : cooldown et quota reposent sur un cycle lecture→écriture cache non atomique (pas de primitive
    // atomique dans le core). Une race entre deux déclenchements quasi simultanés pourrait franchir la garde
    // d'un ou deux réveils — acceptable (action bouton, concurrence improbable ; marge WAKEUP_QUOTA_MAX<6).
    // 1) Cooldown per-véhicule : refus net (pas de retry) si un réveil est trop récent sur CE véhicule.
    $cleCooldown = self::WAKEUP_COOLDOWN_KEY . $this->getId();
    $dernier = (int) cache::byKey($cleCooldown)->getValue(0);
    if ($dernier > 0) {
      $reste = self::WAKEUP_COOLDOWN - (time() - $dernier);
      if ($reste > 0) {
        throw new stellantisException(sprintf(__('Réveil déjà demandé récemment ; patientez %d s (protection anti-ban / batterie 12 V)', __FILE__), $reste), 429, 'rate_limited');
      }
    }
    // 2) Pré-requis OTP : sans remote token, pas de canal MQTT. Alerte cohérente avec les autres call sites.
    if (!stellantisApi::hasRemoteToken()) {
      self::alerterOtpRequired();
      throw new stellantisException(__('Pilotage à distance non activé : activez l\'OTP dans la configuration du plugin', __FILE__), 0, 'otp_required');
    }
    // 3) Publication (gère quota global compte + CID + démon + alignement token). Retourne le correlation_id.
    $correlationId = $this->publishRemoteCommand(self::WAKEUP_SERVICE, array('action' => self::WAKEUP_ACTION));
    // 4) Pose le cooldown et le mapping ack→véhicule (la remontée d'état sera programmée à réception de l'ack).
    cache::set($cleCooldown, time(), self::WAKEUP_COOLDOWN);
    cache::set(self::CMD_CORR_KEY . $correlationId, $this->getId(), self::CMD_CORR_TTL);
    log::add('stellantis', 'info', 'Wakeup demandé pour l\'équipement #' . $this->getId());
  }

  /**
   * Point UNIQUE de publication d'une commande MQTT de CE véhicule (réutilisable UC14-17). Applique le quota
   * GLOBAL compte (anti-ban serveur), vérifie CID + démon lancé, aligne le token de session MQTT, construit
   * le message complet et le publie via le démon. Retourne le correlation_id (corrélation de l'ack).
   * @throws stellantisException 'rate_limited' (quota), 'not_configured' (CID/démon), sinon selon buildMqttRequest
   */
  public function publishRemoteCommand(string $_service, array $_reqParameters): string {
    // Pré-requis d'abord : un échec (CID/démon) ne doit PAS consommer le quota anti-ban (compté seulement
    // pour une publication réellement émise).
    if (self::getCustomerId() == '') {
      throw new stellantisException(__('Identifiant client (CID) inconnu : refaites l\'activation OTP', __FILE__), 0, 'not_configured');
    }
    if (self::deamon_info()['state'] != 'ok') {
      throw new stellantisException(__('Démon MQTT non démarré : impossible d\'envoyer la commande', __FILE__), 0, 'not_configured');
    }
    // Quota global au niveau COMPTE : le ban serveur (~6/20 min) frappe le compte, pas le véhicule → un
    // cooldown par véhicule ne suffit pas sur un compte multi-véhicules. Fenêtre glissante, sans appel réseau.
    self::consommerQuotaWakeup();
    // Aligne le mot de passe MQTT de la session (démon) sur le remote token courant AVANT de publier : le
    // getRemoteToken() de buildMqttRequest peut déclencher un refresh proactif, le payload et la session
    // doivent porter le même token. Idempotent (no-op si inchangé).
    self::syncDaemonToken();
    $requete = $this->buildMqttRequest($_service, $_reqParameters);
    // sendToDaemon est best-effort : s'il échoue (démon crashé après le check state==ok), la commande n'a
    // pas été transmise → on lève 'transport' pour NE PAS poser le cooldown/mapping d'une commande fantôme.
    if (!self::sendToDaemon('publish', array('topic' => $requete['topic'], 'payload' => $requete['payload']))) {
      throw new stellantisException(__('Envoi de la commande au démon MQTT échoué (démon indisponible ?)', __FILE__), 0, 'transport');
    }
    return $requete['correlation_id'];
  }

  /**
   * Construit une requête MQTT PSA complète pour CE véhicule (contrat MQTTRequest du code de référence) :
   * topic psa/RemoteServices/from/cid/{CID}{service} + message {access_token, customer_id, correlation_id,
   * req_date, vin, req_parameters}. Le remote token est RÉINJECTÉ dans le payload (en plus d'être le mot de
   * passe MQTT de la session). @throws stellantisException si le remote token est indisponible.
   */
  public function buildMqttRequest(string $_service, array $_reqParameters): array {
    $cid = self::getCustomerId();
    $token = stellantisApi::getRemoteToken(); // remote token OTP (refresh proactif si proche expiration)
    $correlationId = self::genererCorrelationId();
    return array(
      'topic' => 'psa/RemoteServices/from/cid/' . $cid . $_service,
      'correlation_id' => $correlationId,
      'payload' => array(
        'access_token' => $token,
        'customer_id' => $cid,
        'correlation_id' => $correlationId,
        'req_date' => gmdate('Y-m-d\TH:i:s\Z'),
        'vin' => $this->getLogicalId(),
        'req_parameters' => $_reqParameters,
      ),
    );
  }

  // Identifiant de corrélation opaque, unique par requête (repris dans l'ack). Reproduit le format du code
  // de référence : uuid4().hex (sans tirets) + horodatage UTC à la milliseconde. Seule l'unicité compte.
  private static function genererCorrelationId(): string {
    $ms = (int) (explode(' ', microtime())[0] * 1000); // fraction de seconde → millisecondes (0..999)
    return bin2hex(random_bytes(16)) . gmdate('YmdHis') . sprintf('%03d', $ms);
  }

  // Quota global anti-ban des commandes MQTT (fenêtre glissante fixe) : dépassement → rate_limited SANS
  // appel réseau. Gabarit identique à consommerQuotaRefresh (niveau COMPTE, partagé par tous les véhicules).
  private static function consommerQuotaWakeup(): void {
    $quota = json_decode((string) cache::byKey(self::WAKEUP_QUOTA_KEY)->getValue(''), true);
    $maintenant = time();
    if (!is_array($quota) || !isset($quota['windowStart']) || $maintenant - $quota['windowStart'] > self::WAKEUP_QUOTA_FENETRE) {
      $quota = array('windowStart' => $maintenant, 'count' => 0);
    }
    if ($quota['count'] >= self::WAKEUP_QUOTA_MAX) {
      $reste = (int) $quota['windowStart'] + self::WAKEUP_QUOTA_FENETRE - $maintenant;
      throw new stellantisException(sprintf(__('Trop de réveils récents sur le compte ; patientez %d s (protection anti-ban)', __FILE__), max(1, $reste)), 429, 'rate_limited');
    }
    $quota['count']++;
    cache::set(self::WAKEUP_QUOTA_KEY, json_encode($quota), self::WAKEUP_QUOTA_FENETRE);
  }

  /* * ******************* UC14 — Commande de charge (démarrer / arrêter) ******************* */

  const CHARGE_SERVICE = '/VehCharge';                           // segment de service du topic MQTT (⚠️ distinct du wakeup /VehCharge/state)
  const CHARGE_TYPE_IMMEDIATE = 'immediate';                     // type req_parameters : démarrer la charge maintenant (constante IMMEDIATE_CHARGE du code de réf.)
  const CHARGE_TYPE_DELAYED = 'delayed';                         // type req_parameters : repasser en charge différée (= « arrêter » ; constante DELAYED_CHARGE)
  const CHARGE_NEXT_TIME_KEY = 'stellantis::charge_next_time::'; // + eqId (cache) : next_delayed_time brut du dernier /status (repli si refresh-avant-stop échoue)
  const CHARGE_NEXT_TIME_TTL = 172800;                           // TTL du cache d'heure différée (2 j, pattern privacy) — jamais en config (effaçable au Sauvegarder)
  const CHARGE_DEBOUNCE = 10;                                    // anti-boucle per-véhicule (s) : autorise un vrai toggle start→stop, bloque un scénario en boucle
  const CHARGE_DEBOUNCE_KEY = 'stellantis::charge_debounce::';   // + eqId (cache) : dernière commande de charge de CE véhicule

  /**
   * Démarre (immediate) ou arrête (delayed) la charge de CE véhicule via une commande MQTT. Action
   * DÉLIBÉRÉE (bouton). Anti-boucle per-véhicule court (pas de cooldown long : un vrai toggle start→stop
   * doit passer) ; le garde-fou anti-ban est le quota GLOBAL compte dans publishRemoteCommand().
   * ⚠️ « Arrêter » (delayed) rafraîchit d'abord l'état RÉEL du véhicule (l'heure de charge différée peut
   * avoir changé depuis l'app mobile) pour ne PAS reprogrammer la charge différée en envoyant une valeur
   * périmée. L'ack asynchrone (topic to/cid) programmera un refresh REST au prochain cron.
   * @throws stellantisException 'rate_limited' (debounce/quota), 'otp_required' (OTP non activé), sinon api_error
   */
  public function chargeControl(bool $_demarrer): void {
    // 0) Garde métier : commande non pertinente sur un véhicule non rechargeable (les commandes ne sont
    //    créées que pour Electric/Hybrid, mais on revérifie côté serveur — une cmd peut subsister après une
    //    requalification de motorisation, ou être appelée par scénario). Aucun appel réseau vers le véhicule.
    $motorisation = trim((string) $this->getConfiguration('energy', ''));
    if ($motorisation != 'Electric' && $motorisation != 'Hybrid') {
      throw new stellantisException(__('Commande de charge indisponible : véhicule non rechargeable', __FILE__), 0, 'not_configured');
    }
    // 1) Anti-boucle per-véhicule : refus net si une commande de charge est trop récente sur CE véhicule.
    $cleDebounce = self::CHARGE_DEBOUNCE_KEY . $this->getId();
    $dernier = (int) cache::byKey($cleDebounce)->getValue(0);
    if ($dernier > 0) {
      $reste = self::CHARGE_DEBOUNCE - (time() - $dernier);
      if ($reste > 0) {
        throw new stellantisException(sprintf(__('Commande de charge déjà envoyée à l\'instant ; patientez %d s', __FILE__), $reste), 429, 'rate_limited');
      }
    }
    // 2) Pré-requis OTP : sans remote token, pas de canal MQTT. Alerte cohérente avec les autres call sites.
    if (!stellantisApi::hasRemoteToken()) {
      self::alerterOtpRequired();
      throw new stellantisException(__('Pilotage à distance non activé : activez l\'OTP dans la configuration du plugin', __FILE__), 0, 'otp_required');
    }
    // 3) Pose le debounce AVANT tout appel réseau (GET /status de pré-arrêt + publish MQTT) : borne les
    //    tentatives répétées quel qu'en soit le résultat. Sans ça, un charge_stop rejoué en échec (démon
    //    down, quota MQTT…) enchaînerait des GET /status non bornées → risque de 429/ban compte (anti-ban).
    cache::set($cleDebounce, time(), self::CHARGE_DEBOUNCE);
    // 4) « Arrêter » (delayed) : rafraîchir l'état RÉEL avant de publier, dans tous les cas — l'heure de
    //    charge différée peut avoir été modifiée depuis l'app mobile ; on ne se fie jamais au seul cache.
    //    Best-effort : un échec (429/auth/hors-ligne) ne bloque pas le stop (repli cache/0:0 dans heureChargeDifferee).
    if (!$_demarrer) {
      try {
        $this->refreshTelemetry();
      } catch (\Throwable $e) {
        log::add('stellantis', 'warning', 'Charge : rafraîchissement pré-arrêt en erreur pour l\'équipement #' . $this->getId() . ' (' . $e->getMessage() . ') — poursuite avec la dernière heure connue');
      }
    }
    // 5) Heure de charge différée : pour « démarrer » (immediate) l'heure est ignorée par le véhicule → 0:0
    //    direct (ni refresh ni lecture) ; pour « arrêter » (delayed), valeur fraîche préservant la programmation.
    list($heure, $minute) = $_demarrer ? array(0, 0) : $this->heureChargeDifferee();
    $type = $_demarrer ? self::CHARGE_TYPE_IMMEDIATE : self::CHARGE_TYPE_DELAYED;
    // 6) Publication (gère quota global compte + CID + démon + alignement token). Retourne le correlation_id.
    $correlationId = $this->publishRemoteCommand(self::CHARGE_SERVICE, array(
      'program' => array('hour' => $heure, 'minute' => $minute),
      'type' => $type,
    ));
    // 7) Mapping ack→véhicule (refresh d'état au prochain cron). Le debounce a déjà été posé en 3).
    cache::set(self::CMD_CORR_KEY . $correlationId, $this->getId(), self::CMD_CORR_TTL);
    log::add('stellantis', 'info', 'Charge ' . ($_demarrer ? 'démarrée' : 'arrêtée') . ' (demande) pour l\'équipement #' . $this->getId());
  }

  /**
   * UC22 : programme une heure de charge différée (delayed) SAISIE PAR L'UTILISATEUR sur CE véhicule via
   * une commande MQTT (widget action paramétré `message`, saisie HHMM). Gabarit de chargeControl() — mêmes
   * garde-fous (motorisation, debounce PARTAGÉ avec charge_start/charge_stop car même service MQTT
   * `/VehCharge`, pré-requis OTP) — mais SANS refresh-avant-envoi : l'utilisateur impose délibérément une
   * heure, il n'y a rien à préserver (contrairement à « Arrêter la charge »).
   * ⚠️ Effet de bord assumé : `type:"delayed"` repasse le véhicule en charge différée, ce qui INTERROMPT
   * une charge immédiate en cours (même mécanisme que chargeControl(false)) — décision 2026-07-11, pas de
   * confirmation native (action de routine à faible enjeu, contrairement à `unlock`).
   * @throws stellantisException 'not_configured' (véhicule non rechargeable), 'invalid_input' (heure
   *   saisie invalide), 'rate_limited' (debounce/quota), 'otp_required' (OTP non activé), sinon api_error
   */
  public function chargeSetTime(string $_heure): void {
    // 1) Garde métier (même garde que chargeControl) : aucun appel réseau si le véhicule n'est pas rechargeable.
    $motorisation = trim((string) $this->getConfiguration('energy', ''));
    if ($motorisation != 'Electric' && $motorisation != 'Hybrid') {
      throw new stellantisException(__('Commande de charge indisponible : véhicule non rechargeable', __FILE__), 0, 'not_configured');
    }
    // 2) Parse + validation AVANT tout effet de bord réseau : une saisie invalide échoue proprement.
    list($heure, $minute) = self::parserHeureSaisie($_heure);
    // 3) Anti-boucle per-véhicule : clé PARTAGÉE avec charge_start/charge_stop (même service MQTT /VehCharge).
    $cleDebounce = self::CHARGE_DEBOUNCE_KEY . $this->getId();
    $dernier = (int) cache::byKey($cleDebounce)->getValue(0);
    if ($dernier > 0) {
      $reste = self::CHARGE_DEBOUNCE - (time() - $dernier);
      if ($reste > 0) {
        throw new stellantisException(sprintf(__('Commande de charge déjà envoyée à l\'instant ; patientez %d s', __FILE__), $reste), 429, 'rate_limited');
      }
    }
    // 4) Pré-requis OTP : sans remote token, pas de canal MQTT. Alerte cohérente avec les autres call sites.
    if (!stellantisApi::hasRemoteToken()) {
      self::alerterOtpRequired();
      throw new stellantisException(__('Pilotage à distance non activé : activez l\'OTP dans la configuration du plugin', __FILE__), 0, 'otp_required');
    }
    // 5) Pose le debounce AVANT tout appel réseau (publish MQTT) : borne les tentatives répétées.
    cache::set($cleDebounce, time(), self::CHARGE_DEBOUNCE);
    // 6) Publication : PAS de refresh-avant-envoi (contrairement à chargeControl(false)) — l'heure vient
    //    délibérément de l'utilisateur, il n'y a aucune programmation existante à préserver.
    $correlationId = $this->publishRemoteCommand(self::CHARGE_SERVICE, array(
      'program' => array('hour' => $heure, 'minute' => $minute),
      'type' => self::CHARGE_TYPE_DELAYED,
    ));
    // 7) Mapping ack→véhicule (commande stateful : refresh REST au prochain cron → charge_next_time à jour).
    cache::set(self::CMD_CORR_KEY . $correlationId, $this->getId(), self::CMD_CORR_TTL);
    log::add('stellantis', 'info', sprintf('Programmation de charge (%02d:%02d) demandée pour l\'équipement #%d', $heure, $minute, $this->getId()));
  }

  /**
   * Heure de charge différée [heure, minute] de CE véhicule d'après le dernier /status (cache alimenté par
   * refreshTelemetry). Sert à préserver la programmation lors d'un « Arrêter la charge » (delayed). Cache
   * vide → repli [0,0] avec warning (effet de bord potentiel : reprogrammation à minuit — mais donnée
   * fraîche via le refresh-avant-stop de chargeControl() ⇒ en pratique rien à écraser).
   */
  public function heureChargeDifferee(): array {
    $brut = trim((string) cache::byKey(self::CHARGE_NEXT_TIME_KEY . $this->getId())->getValue(''));
    if ($brut == '') {
      log::add('stellantis', 'warning', 'Charge : heure de charge différée inconnue pour l\'équipement #' . $this->getId() . ' — repli à 00:00');
      return array(0, 0);
    }
    return self::parseHeureIso($brut);
  }

  /**
   * Parse une heure de charge différée en [heure, minute], TOLÉRANT aux deux formats attestés du champ
   * next_delayed_time selon la version d'API/véhicule : durée ISO8601 « PTxxHxxMxxS » (miroir de parse_hour
   * du code de référence) OU timestamp RFC3339 « ...THH:MM... » (déclaré par le modèle swagger). Aucun
   * match → [0,0]. Clamp défensif (heure 0..23, minute 0..59).
   */
  public static function parseHeureIso(string $_iso): array {
    $iso = trim($_iso);
    $heure = 0;
    $minute = 0;
    if (preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?/', $iso, $m)) {
      $heure = isset($m[1]) ? (int) $m[1] : 0;
      $minute = isset($m[2]) ? (int) $m[2] : 0;
    } elseif (preg_match('/T(\d{2}):(\d{2})/', $iso, $m)) {
      // RFC3339 : on extrait HH:MM tel quel (best-effort, comme le code de référence qui ne convertit pas
      // le fuseau) — sans impact pour immediate ; pour delayed c'est la préservation au mieux.
      $heure = (int) $m[1];
      $minute = (int) $m[2];
    }
    return array(max(0, min(23, $heure)), max(0, min(59, $minute)));
  }

  /**
   * UC22 : parse une heure SAISIE PAR L'UTILISATEUR (widget action `message`) au format Jeedom `HHMM` sans
   * séparateur (ex. "2030" = 20h30, "730" = 07h30, "0030" = 00h30). Tolère par robustesse les séparateurs
   * `:`/`h`/`H` et les espaces (copier-coller), mais le format canonique documenté (nom de la commande)
   * reste `HHMM`. Distinct de parseHeureIso() (qui parse une donnée SERVEUR et clampe en repli silencieux) :
   * ici c'est une saisie UTILISATEUR → REJET NET (exception 'invalid_input'), jamais de clamp silencieux,
   * jamais de valeur devinée sur une saisie ambiguë.
   * @return array{0:int,1:int} [heure 0..23, minute 0..59]
   * @throws stellantisException 'invalid_input' si le format ou la plage est invalide
   */
  private static function parserHeureSaisie(string $_saisie): array {
    $saisie = trim($_saisie);
    // Deux branches distinctes (pas de strip de séparateur suivi d'une lecture longueur-3/4 générique) :
    // stripper le séparateur AVANT de tester la longueur ferait accepter SILENCIEUSEMENT une heure fausse
    // pour une saisie combinant séparateur + heure 2 chiffres + minute 1 chiffre (ex. "20h5" strippé en
    // "205", longueur 3, lu à tort comme HMM = 02:05 au lieu de rejeter une saisie ambiguë).
    if (preg_match('/[\s:hH]/', $saisie)) {
      // Saisie AVEC séparateur (ex. "20h30", "20:30", "20 30") : minute EXIGÉE sur 2 chiffres pour lever
      // toute ambiguïté ("20h5" est rejeté, jamais interprété 02:05 ni 20:05 — on ne devine pas).
      if (!preg_match('/^(\d{1,2})[\s:hH]+(\d{2})$/', $saisie, $m)) {
        throw new stellantisException(__('Format d\'heure invalide (attendu HHMM, ex. 2030)', __FILE__), 0, 'invalid_input');
      }
      $heure = (int) $m[1];
      $minute = (int) $m[2];
    } else {
      // Format canonique Jeedom HHMM SANS séparateur : 3 chiffres = HMM, 4 = HHMM. Rejet net
      // (invalid_input) si non exploitable — JAMAIS de clamp d'une saisie utilisateur.
      if (!ctype_digit($saisie) || strlen($saisie) < 3 || strlen($saisie) > 4) {
        throw new stellantisException(__('Format d\'heure invalide (attendu HHMM, ex. 2030)', __FILE__), 0, 'invalid_input');
      }
      $minute = (int) substr($saisie, -2);
      $heure = (int) substr($saisie, 0, strlen($saisie) - 2);
    }
    if ($heure > 23 || $minute > 59) {
      throw new stellantisException(__('Heure hors plage (00h00 à 23h59)', __FILE__), 0, 'invalid_input');
    }
    return array($heure, $minute);
  }

  /**
   * UC21 : convertit une DURÉE ISO 8601 (`PT1H30M`, `remaining_time`) en minutes. Distinct de
   * parseHeureIso() (qui parse une HEURE ponctuelle, PT ou RFC3339, avec clamp 0..23/0..59) : ici c'est
   * une durée, potentiellement > 24 h (ex. `PT25H` = 1500 min) → AUCUN clamp. `null` si la chaîne ne
   * correspond à aucun format de durée ISO8601 reconnu (jamais de "0" fabriqué).
   */
  private static function dureeIsoEnMinutes(string $_iso): ?int {
    $iso = trim($_iso);
    if (!preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $iso, $m)) {
      return null;
    }
    // isset() (jamais accès direct à $m[n]) : les groupes optionnels non capturés sont OMIS du tableau
    // par PHP (pas de "" ni de warning) ⇒ null distinct de "0 explicite" (ex. "PT0S" reste une durée
    // valable de 0 min, alors que "PT" seul — aucun composant — n'en est pas une).
    $jours = (isset($m[1]) && $m[1] !== '') ? (int) $m[1] : null;
    $heures = (isset($m[2]) && $m[2] !== '') ? (int) $m[2] : null;
    $minutes = (isset($m[3]) && $m[3] !== '') ? (int) $m[3] : null;
    $secondes = (isset($m[4]) && $m[4] !== '') ? (int) $m[4] : null;
    if ($jours === null && $heures === null && $minutes === null && $secondes === null) {
      return null; // "P" ou "PT" seuls (aucun composant numérique) : pas de durée exploitable.
    }
    return ($jours ?? 0) * 1440 + ($heures ?? 0) * 60 + ($minutes ?? 0) + intdiv($secondes ?? 0, 60);
  }

  // Extrait l'heure de charge différée brute (charging.next_delayed_time) de l'énergie électrique du
  // /status, ou '' si absente. SEUL endroit avec ce chemin de champ (data-model), hors parseStatus (pur).
  private static function extraireHeureChargeDifferee(array $_status): string {
    foreach (self::energiesDepuisStatus($_status) as $energie) {
      if (!is_array($energie) || !isset($energie['type']) || strtolower((string) $energie['type']) != 'electric') {
        continue;
      }
      if (isset($energie['charging']['next_delayed_time']) && is_scalar($energie['charging']['next_delayed_time'])) {
        return (string) $energie['charging']['next_delayed_time'];
      }
    }
    return '';
  }

  /* * ******************* UC15 — Préconditionnement climatique (immédiat) ******************* */

  const PRECOND_SERVICE = '/ThermalPrecond';                     // segment de service du topic MQTT (contrat RemoteClient.preconditioning)
  const PRECOND_DEBOUNCE = 10;                                    // anti-boucle per-véhicule (s) : autorise un vrai toggle on→off, bloque un scénario en boucle
  const PRECOND_DEBOUNCE_KEY = 'stellantis::precond_debounce::'; // + eqId (cache) : dernière commande de préconditionnement de CE véhicule

  /**
   * Active (asap=activate) ou désactive (asap=deactivate) le préconditionnement climatique de CE
   * véhicule via une commande MQTT. Action DÉLIBÉRÉE (bouton). Anti-boucle per-véhicule court (comme
   * chargeControl) ; le garde-fou anti-ban est le quota GLOBAL compte dans publishRemoteCommand().
   * ⚠️ Aucune garde motorisation/batterie proactive côté plugin : un refus véhicule (batterie/non
   * branché) remonte via l'info precond_status (Failure/failure_cause) au prochain cron, cf. UC18 pour
   * une validation stricte. L'ack asynchrone (topic to/cid) programmera un refresh REST au prochain cron.
   * @throws stellantisException 'rate_limited' (debounce/quota), 'otp_required' (OTP non activé), sinon api_error
   */
  public function precondControl(bool $_activer): void {
    // 1) Anti-boucle per-véhicule : refus net si une commande de préconditionnement est trop récente sur CE véhicule.
    $cleDebounce = self::PRECOND_DEBOUNCE_KEY . $this->getId();
    $dernier = (int) cache::byKey($cleDebounce)->getValue(0);
    if ($dernier > 0) {
      $reste = self::PRECOND_DEBOUNCE - (time() - $dernier);
      if ($reste > 0) {
        throw new stellantisException(sprintf(__('Commande de préconditionnement déjà envoyée à l\'instant ; patientez %d s', __FILE__), $reste), 429, 'rate_limited');
      }
    }
    // 2) Pré-requis OTP : sans remote token, pas de canal MQTT. Alerte cohérente avec les autres call sites.
    if (!stellantisApi::hasRemoteToken()) {
      self::alerterOtpRequired();
      throw new stellantisException(__('Pilotage à distance non activé : activez l\'OTP dans la configuration du plugin', __FILE__), 0, 'otp_required');
    }
    // 3) Pose le debounce AVANT tout appel réseau (publish MQTT) : borne les tentatives répétées quel qu'en soit le résultat.
    cache::set($cleDebounce, time(), self::PRECOND_DEBOUNCE);
    // 4) Publication (gère quota global compte + CID + démon + alignement token). Retourne le correlation_id.
    $correlationId = $this->publishRemoteCommand(self::PRECOND_SERVICE, array(
      'asap' => $_activer ? 'activate' : 'deactivate',
      'programs' => self::precondProgramDefaut(),
    ));
    // 5) Mapping ack→véhicule (refresh d'état au prochain cron). Le debounce a déjà été posé en 3).
    cache::set(self::CMD_CORR_KEY . $correlationId, $this->getId(), self::CMD_CORR_TTL);
    log::add('stellantis', 'info', 'Préconditionnement ' . ($_activer ? 'activé' : 'désactivé') . ' (demande) pour l\'équipement #' . $this->getId());
  }

  /**
   * Programmes de préconditionnement par défaut (littéral du code de référence RemoteClient), envoyés
   * quand le plugin n'a appris AUCUN programme réel du véhicule (events MQTT programs) — ce qui est
   * TOUJOURS notre cas, le suivi des programmes étant hors scope UC15 (variante avancée future). Les 4
   * créneaux sont désactivés (on=0) : c'est le même comportement par défaut que le code de référence
   * applique tant qu'il n'a rien appris (usage réel multi-années, aucun signalement de programmation
   * écrasée) — cf. 15-tech.md § risque documenté. hour=34/minute=7 = valeurs littérales du code de
   * référence, sans effet puisque on=0.
   */
  private static function precondProgramDefaut(): array {
    $creneauInactif = array('day' => array(0, 0, 0, 0, 0, 0, 0), 'hour' => 34, 'minute' => 7, 'on' => 0);
    return array(
      'program1' => $creneauInactif,
      'program2' => $creneauInactif,
      'program3' => $creneauInactif,
      'program4' => $creneauInactif,
    );
  }

  /* * ******************* UC16 — Verrouillage / déverrouillage des portes ******************* */

  const DOOR_SERVICE = '/Doors';                                 // segment de service du topic MQTT (contrat RemoteClient.lock_door)
  const DOOR_DEBOUNCE = 10;                                       // anti-boucle per-véhicule (s) : autorise un vrai toggle lock→unlock, bloque un scénario en boucle
  const DOOR_DEBOUNCE_KEY = 'stellantis::door_debounce::';       // + eqId (cache) : dernière commande de verrouillage de CE véhicule

  /**
   * Verrouille (action=lock) ou déverrouille (action=unlock) les portes de CE véhicule via une commande
   * MQTT. Action DÉLIBÉRÉE (bouton) ; le déverrouillage porte une confirmation core (actionConfirm=1,
   * posée à la création par ensureActionCommand). ⚠️ Cette confirmation est un garde-fou ANTI-FAUSSE-MANIP
   * de l'UI web (dialog vérifié par core/ajax/cmd.ajax.php AVANT execute()), PAS une frontière
   * d'autorisation : un scénario Jeedom, l'API JSON-RPC (apikey) ou un autre plugin appelant execCmd()
   * directement déverrouillent SANS dialog. La vraie protection contre un déverrouillage non désiré repose
   * sur la maîtrise des droits Jeedom (création de scénarios, clé API) — comportement générique du core,
   * non spécifique à ce plugin. Anti-boucle per-véhicule court
   * (comme chargeControl/precondControl) ; garde-fou anti-ban = quota GLOBAL compte dans publishRemoteCommand().
   * ⚠️ Aucune garde motorisation/équipement proactive côté plugin, ET l'API MQTT /Doors n'expose AUCUN
   * failure_cause dédié (≠ précond UC15) : un refus véhicule (thermique/équipement non compatible) se
   * traduit seulement par l'info doors_locked qui ne change pas après l'ack, sans message explicite. Le
   * retour d'état fin (statut/timeout d'ack) est renvoyé à UC18. L'ack asynchrone (topic to/cid) programme
   * un refresh REST au prochain cron (doors_locked reflétera alors l'état réel).
   * @throws stellantisException 'rate_limited' (debounce/quota), 'otp_required' (OTP non activé), sinon api_error
   */
  public function doorControl(bool $_verrouiller): void {
    // 1) Anti-boucle per-véhicule : refus net si une commande de verrouillage est trop récente sur CE véhicule.
    $cleDebounce = self::DOOR_DEBOUNCE_KEY . $this->getId();
    $dernier = (int) cache::byKey($cleDebounce)->getValue(0);
    if ($dernier > 0) {
      $reste = self::DOOR_DEBOUNCE - (time() - $dernier);
      if ($reste > 0) {
        throw new stellantisException(sprintf(__('Commande de verrouillage déjà envoyée à l\'instant ; patientez %d s', __FILE__), $reste), 429, 'rate_limited');
      }
    }
    // 2) Pré-requis OTP : sans remote token, pas de canal MQTT. Alerte cohérente avec les autres call sites.
    if (!stellantisApi::hasRemoteToken()) {
      self::alerterOtpRequired();
      throw new stellantisException(__('Pilotage à distance non activé : activez l\'OTP dans la configuration du plugin', __FILE__), 0, 'otp_required');
    }
    // 3) Pose le debounce AVANT tout appel réseau (publish MQTT) : borne les tentatives répétées quel qu'en soit le résultat.
    cache::set($cleDebounce, time(), self::DOOR_DEBOUNCE);
    // 4) Publication (gère quota global compte + CID + démon + alignement token). Retourne le correlation_id.
    $correlationId = $this->publishRemoteCommand(self::DOOR_SERVICE, array('action' => $_verrouiller ? 'lock' : 'unlock'));
    // 5) Mapping ack→véhicule (refresh d'état au prochain cron). Le debounce a déjà été posé en 3).
    cache::set(self::CMD_CORR_KEY . $correlationId, $this->getId(), self::CMD_CORR_TTL);
    log::add('stellantis', 'info', ($_verrouiller ? 'Verrouillage' : 'Déverrouillage') . ' demandé pour l\'équipement #' . $this->getId());
  }

  /* * ******************* UC17 — Klaxon & feux (retrouver le véhicule) ******************* */

  const HORN_SERVICE = '/Horn';                                  // segment de service du topic MQTT (contrat RemoteClient.horn)
  const HORN_COUNT = 2;                                          // nombre de coups de klaxon par défaut (paramètre nb_horn) — configurabilité par commande différée
  const HORN_DEBOUNCE = 10;                                      // anti-boucle per-véhicule (s) : autorise un enchaînement klaxon→feux, bloque un scénario en boucle
  const HORN_DEBOUNCE_KEY = 'stellantis::horn_debounce::';       // + eqId (cache) : dernier klaxon de CE véhicule
  const LIGHTS_SERVICE = '/Lights';                              // segment de service du topic MQTT (contrat RemoteClient.lights)
  const LIGHTS_DURATION = 10;                                    // durée d'allumage des feux par défaut (s, paramètre duration) — configurabilité par commande différée
  const LIGHTS_DEBOUNCE = 10;                                    // anti-boucle per-véhicule (s) : constante séparée du klaxon (convention « une par domaine »)
  const LIGHTS_DEBOUNCE_KEY = 'stellantis::lights_debounce::';   // + eqId (cache) : dernier allumage des feux de CE véhicule

  /**
   * Déclenche le KLAXON de CE véhicule via une commande MQTT (nb_horn coups) pour le retrouver/signaler.
   * Action DÉLIBÉRÉE (bouton) « sans état » : aucune télémétrie klaxon à relire. Anti-boucle per-véhicule
   * court ; le garde-fou anti-ban est le quota GLOBAL compte dans publishRemoteCommand().
   * @throws stellantisException 'rate_limited' (debounce/quota), 'otp_required' (OTP non activé), sinon api_error
   */
  public function horn(): void {
    $this->declencherSignal(
      self::HORN_SERVICE,
      array('nb_horn' => self::HORN_COUNT, 'action' => 'activate'),
      self::HORN_DEBOUNCE_KEY . $this->getId(),
      self::HORN_DEBOUNCE,
      __('Klaxon déjà déclenché à l\'instant ; patientez %d s', __FILE__)
    );
    log::add('stellantis', 'info', 'Klaxon demandé pour l\'équipement #' . $this->getId());
  }

  /**
   * Allume les FEUX de CE véhicule via une commande MQTT (pendant `duration` s) pour le retrouver/signaler.
   * Action DÉLIBÉRÉE (bouton) « sans état » : aucune télémétrie feux à relire. Anti-boucle per-véhicule
   * court ; le garde-fou anti-ban est le quota GLOBAL compte dans publishRemoteCommand().
   * @throws stellantisException 'rate_limited' (debounce/quota), 'otp_required' (OTP non activé), sinon api_error
   */
  public function lights(): void {
    $this->declencherSignal(
      self::LIGHTS_SERVICE,
      array('action' => 'activate', 'duration' => self::LIGHTS_DURATION),
      self::LIGHTS_DEBOUNCE_KEY . $this->getId(),
      self::LIGHTS_DEBOUNCE,
      __('Feux déjà déclenchés à l\'instant ; patientez %d s', __FILE__)
    );
    log::add('stellantis', 'info', 'Feux demandés pour l\'équipement #' . $this->getId());
  }

  /**
   * Point commun des commandes de SIGNALEMENT « sans état » (klaxon/feux, UC17) : debounce per-véhicule →
   * pré-requis OTP → pose du debounce AVANT réseau → publication MQTT. Volontairement PLUS SIMPLE que le
   * gabarit UC13-16 : commandes stateless, donc AUCUN mapping CMD_CORR_KEY posé.
   * ⚠️ Décision (validée) : CMD_CORR_KEY sert à programmer un refresh REST au prochain cron pour les
   * commandes STATEFUL — inutile ici (rien de frais à relire pour klaxon/feux) et gaspilleur d'un slot de
   * quota anti-ban. UC18 (traiterRetourCommande) remonte tout de même un retour d'état
   * (last_command_result) pour klaxon/feux via le REPLI VIN de l'ack, sans mapping et sans refresh REST.
   * ⚠️ i18n : $_refusMsg DOIT être une chaîne déjà traduite (produite par un __() LITTÉRAL au point
   * d'appel — horn()/lights()), jamais reconstruite ici : l'extracteur i18n est un scan statique (piège UC07).
   * @throws stellantisException 'rate_limited' (debounce/quota), 'otp_required' (OTP non activé), sinon api_error
   */
  private function declencherSignal(string $_service, array $_reqParameters, string $_cleDebounce, int $_debounce, string $_refusMsg): void {
    // 1) Anti-boucle per-véhicule : refus net si une commande de même type est trop récente sur CE véhicule.
    $dernier = (int) cache::byKey($_cleDebounce)->getValue(0);
    if ($dernier > 0) {
      $reste = $_debounce - (time() - $dernier);
      if ($reste > 0) {
        throw new stellantisException(sprintf($_refusMsg, $reste), 429, 'rate_limited');
      }
    }
    // 2) Pré-requis OTP : sans remote token, pas de canal MQTT. Alerte cohérente avec les autres call sites.
    if (!stellantisApi::hasRemoteToken()) {
      self::alerterOtpRequired();
      throw new stellantisException(__('Pilotage à distance non activé : activez l\'OTP dans la configuration du plugin', __FILE__), 0, 'otp_required');
    }
    // 3) Pose le debounce AVANT tout appel réseau (publish MQTT) : borne les tentatives répétées quel qu'en soit le résultat.
    cache::set($_cleDebounce, time(), $_debounce);
    // 4) Publication (gère quota global compte + CID + démon + alignement token). Le correlation_id retourné
    //    n'est volontairement PAS mémorisé (commande stateless, cf. docblock).
    $this->publishRemoteCommand($_service, $_reqParameters);
  }

  /* * ******************* UC24 — Suivi & statistiques de charge ******************* */

  const CHARGE_SESSION_KEY = 'stellantis::charge_session::';     // + eqId (cache) : session en cours {start_ts,start_soc,cap_kwh}
  const CHARGE_SESSION_TTL = 604800;                              // 7 j — RÉÉCRIT à chaque poll InProgress → jamais d'expiration en cours de charge
  const CHARGE_STATUTS_TERMINAUX = array('Finished', 'Stopped', 'Disconnected', 'Failure'); // fins de session (≠ InProgress)
  const CHARGE_LAST_STATUT_KEY = 'stellantis::charge_last_statut::'; // + eqId (cache) : dernier charging_status observé, pour ne loguer QUE sur transition

  /**
   * UC24 : machine à états mono-passe qui détecte les sessions de charge (transition charging_status
   * InProgress → statut terminal) et produit un récapitulatif (énergie/durée/coût estimés) via les 3
   * commandes info charge_session_*. Best-effort, robuste — NE LÈVE JAMAIS (try/catch enveloppant + log
   * warning), posé APRÈS le reste de refreshTelemetry() pour ne rien interrompre (robustesse cron).
   * Gating : uniquement si le /status porte un état de charge (⇒ VE/PHEV ; thermique pur = clé absente).
   */
  private function suivreSessionCharge(array $_valeurs, array $_status): void {
    try {
      if (!isset($_valeurs['charging_status'])) {
        return;
      }
      $statut = (string) $_valeurs['charging_status']; // déjà assaini (lettres) par parseStatus
      // Dernier statut observé (toutes issues confondues) : sert UNIQUEMENT à distinguer une vraie
      // TRANSITION vers un terminal d'un état terminal STABLE qui persiste poll après poll (ex. Disconnected
      // au repos, ou Finished qui reste jusqu'au débranchement) — sans quoi le log ci-dessous spammerait à
      // chaque poll (~288×/j/véhicule). Réécrit AVANT toute branche/return : tous les chemins de sortie
      // laissent le cache à jour pour le prochain appel.
      $cleDernierStatut = self::CHARGE_LAST_STATUT_KEY . $this->getId();
      $dernierStatut = (string) cache::byKey($cleDernierStatut)->getValue('');
      cache::set($cleDernierStatut, $statut, self::CHARGE_SESSION_TTL);
      $cleSession = self::CHARGE_SESSION_KEY . $this->getId();
      $brut = (string) cache::byKey($cleSession)->getValue('');
      $session = ($brut !== '') ? json_decode($brut, true) : null;
      if (!is_array($session)) {
        $session = null; // auto-guérison : JSON invalide / absent ⇒ pas de session
      }
      $soc = $this->socCourant($_valeurs); // ?float : /status courant, sinon dernière valeur connue (execCmd)

      // ── Début de session ────────────────────────────────────────────────
      if (strcasecmp($statut, 'InProgress') == 0) {
        if ($session === null) {
          if ($soc === null) {
            return; // début vu mais SOC inconnu : attendre un poll avec SOC (pas de start_soc fiable inventé)
          }
          $session = array(
            'start_ts'  => time(),
            'start_soc' => $soc,
            'cap_kwh'   => $this->capaciteBatterieKwh($_status), // snapshot de repli (config prioritaire, sinon extension API)
          );
        }
        // (Ré)écrit systématiquement → rafraîchit le TTL. start_ts/start_soc restent figés (première détection).
        cache::set($cleSession, json_encode($session), self::CHARGE_SESSION_TTL);
        return;
      }

      // ── Fin de session ──────────────────────────────────────────────────
      if (self::estStatutTerminal($statut)) {
        if ($session === null) {
          // Terminal sans session : charge trop courte entre 2 polls (cadence ≥ 5 min), ou cache::flush admin.
          // Logué UNIQUEMENT sur TRANSITION vers ce terminal (dernierStatut différent) — un terminal STABLE
          // qui persiste poll après poll (Disconnected au repos, Finished non débranché…) ne reloggue pas
          // (sinon spam permanent, cf. limite documentée 24-tech). Jamais de récap fabriqué (convention UC18).
          if ($dernierStatut !== '' && strcasecmp($dernierStatut, $statut) != 0) {
            log::add('stellantis', 'info', 'Charge : fin détectée sans session enregistrée (charge courte ou cache vidé) pour l\'équipement #' . $this->getId() . ' — non comptabilisée');
          }
          return;
        }
        // Idempotence : CONSOMMER (purger) la session AVANT d'écrire les commandes. Si le statut reste
        // terminal au poll suivant → session absente → no-op (aucun doublon d'historique). Un échec d'écriture
        // rarissime coûte 1 récap perdu, préféré à un doublon silencieux.
        cache::delete($cleSession);
        $capKwh = $this->capaciteBatterieKwh($_status); // relu à la FIN (config fraîche prioritaire)…
        if ($capKwh === null && isset($session['cap_kwh']) && is_numeric($session['cap_kwh'])) {
          $capKwh = (float) $session['cap_kwh'];       // …repli sur le snapshot de début
        }
        $recap = self::calculerRecapSession($session, time(), $soc, $capKwh, $this->tarifCharge());
        foreach ($recap as $logicalId => $valeur) {
          $cmd = $this->ensureCommand($logicalId); // création paresseuse ici
          $this->checkAndUpdateCmd($cmd, $valeur);
        }
        return;
      }
      // Autre statut (ni InProgress ni terminal, ou charging_status momentanément absent traité plus haut) :
      // no-op — ne ferme JAMAIS une session (anti-fragmentation).
    } catch (\Throwable $e) {
      log::add('stellantis', 'warning', 'Charge : suivi de session en erreur pour l\'équipement #' . $this->getId() . ' : ' . $e->getMessage());
    }
  }

  // Détection terminale insensible à la casse (harmonisée avec le strcasecmp du début de session — convention
  // du fichier pour les enums API, cf. privacy/precond). Évite l'asymétrie stricte vs insensible.
  private static function estStatutTerminal(string $_statut): bool {
    foreach (self::CHARGE_STATUTS_TERMINAUX as $terminal) {
      if (strcasecmp($_statut, $terminal) == 0) {
        return true;
      }
    }
    return false;
  }

  /**
   * UC24 : calcule le récapitulatif d'une session close. PUR (aucun accès cache/cmd/config — tout est
   * passé en argument, y compris l'instant de fin) → testable. Durée toujours calculée ; énergie/coût
   * seulement si les données requises sont connues. ΔSOC clampé ≥ 0 (jamais d'énergie négative).
   * @return array logicalId => valeur (clés absentes = non calculables, contrat façon parseStatus)
   */
  private static function calculerRecapSession(array $_session, int $_endTs, ?float $_endSoc, ?float $_capKwh, ?float $_tarif): array {
    $recap = array();
    $startTs = (isset($_session['start_ts']) && is_numeric($_session['start_ts'])) ? (int) $_session['start_ts'] : null;
    if ($startTs !== null) {
      // Durée bornée par la cadence de polling → ESTIMATION (documenté). max(0,…) contre une horloge qui recule.
      $recap['charge_session_duration'] = (int) round(max(0, $_endTs - $startTs) / 60);
    }
    $startSoc = (isset($_session['start_soc']) && is_numeric($_session['start_soc'])) ? (float) $_session['start_soc'] : null;
    if ($startSoc !== null && $_endSoc !== null && $_capKwh !== null && $_capKwh > 0) {
      $deltaSoc = max(0.0, $_endSoc - $startSoc);
      $kwh = round($deltaSoc / 100.0 * $_capKwh, 2);
      $recap['charge_session_energy'] = $kwh;
      if ($_tarif !== null && $_tarif >= 0) {
        $recap['charge_session_cost'] = round($kwh * $_tarif, 2);
      }
    }
    return $recap;
  }

  // SOC courant : /status frais prioritaire, sinon dernière valeur connue de la commande battery_soc
  // (parseStatus est défensif : le SOC peut manquer au poll exact de la transition). ?float.
  private function socCourant(array $_valeurs): ?float {
    if (isset($_valeurs['battery_soc']) && is_numeric($_valeurs['battery_soc'])) {
      return (float) $_valeurs['battery_soc'];
    }
    $cmd = $this->getCmd('info', 'battery_soc');
    if (is_object($cmd)) {
      $val = $cmd->execCmd();
      if (is_numeric($val)) {
        return (float) $val;
      }
    }
    return null;
  }

  // Capacité batterie en kWh : config `battery_capacity` (autoritaire) sinon repli API best-effort. ?float.
  private function capaciteBatterieKwh(array $_status): ?float {
    $config = trim((string) $this->getConfiguration('battery_capacity', ''));
    if ($config != '' && is_numeric($config) && (float) $config > 0) {
      return (float) $config;
    }
    return self::extraireCapaciteBatterieKwh($_status);
  }

  // Repli API : energies[].extension.electric.battery.load.capacity (Wh → kWh). Via energiesDepuisStatus()
  // (une seule source de vérité pour energies[] v4.15+/energy[], comme UC21/23). PUR. ?float.
  private static function extraireCapaciteBatterieKwh(array $_status): ?float {
    foreach (self::energiesDepuisStatus($_status) as $energie) {
      if (!is_array($energie) || !isset($energie['type']) || strtolower((string) $energie['type']) != 'electric') {
        continue;
      }
      $capaciteWh = $energie['extension']['electric']['battery']['load']['capacity'] ?? null; // ?? tolère chemins absents (PHP7+)
      if (is_numeric($capaciteWh) && $capaciteWh > 0) {
        return (float) $capaciteWh / 1000.0;
      }
    }
    return null;
  }

  // Tarif électricité (€/kWh) depuis la config véhicule. Vide/non numérique ⇒ null (coût non estimé). ?float.
  private function tarifCharge(): ?float {
    $config = trim((string) $this->getConfiguration('charge_tarif', ''));
    if ($config != '' && is_numeric($config) && (float) $config >= 0) {
      return (float) $config;
    }
    return null;
  }

  /* * ******************* UC33 — Historique des trajets ******************* */

  const TRIP_SESSION_KEY = 'stellantis::trip_session::';  // + eqId (cache) : session en cours JSON
  const TRIP_SESSION_TTL = 86400;                          // 24 h — RÉÉCRIT à chaque poll en trajet
  const TRIP_IGNITION_ON = array('Start', 'StartUp');      // ignition.type => "en trajet" (insensible casse)

  /**
   * UC33 : machine à états mono-passe qui détecte les trajets (arrêt→départ→arrêt) à partir de
   * kinetic.moving/ignition.type/odometer.mileage/latitude/longitude, TOUS déjà collectés par le MÊME
   * /status (+ /lastPosition) au cron par refreshTelemetry() — aucun appel réseau neuf (les endpoints
   * « trips » de l'API officielle sont dépréciés et inaccessibles côté consommateur, cf. 33-tech.md).
   * Best-effort, robuste — NE LÈVE JAMAIS (try/catch enveloppant + log warning), posé APRÈS
   * suivreSessionCharge() (robustesse cron, même convention que le suivi de charge UC24). Universelle
   * (contrairement à UC24 : moving/ignition/mileage existent sur tout véhicule, thermique compris).
   */
  private function suivreTrajet(array $_valeurs, array $_status): void {
    try {
      // 1) Garde signal absent : sans kinetic.moving ce poll-ci, on ne décide rien (ni ouverture ni
      // clôture) — évite de clôturer à tort un trajet réel sur un trou transitoire de ce seul champ.
      if (!isset($_valeurs['moving'])) {
        return;
      }
      // 2) Prédicat « en trajet » : vélocité instantanée OU contact resté sur un état de conduite (persiste
      // pendant tout le trajet, y compris arrêt momentané moteur tournant — réduit fortement la
      // fragmentation). On ne clôture que quand LES DEUX signaux disent « arrêté ».
      $enTrajet = ((int) $_valeurs['moving'] === 1);
      if (!$enTrajet && isset($_valeurs['ignition'])) {
        foreach (self::TRIP_IGNITION_ON as $etatConduite) {
          if (strcasecmp((string) $_valeurs['ignition'], $etatConduite) == 0) {
            $enTrajet = true;
            break;
          }
        }
      }
      // 3) Session courante en cache (auto-guérison : JSON invalide/absent ⇒ pas de session en cours).
      $cleSession = self::TRIP_SESSION_KEY . $this->getId();
      $brut = (string) cache::byKey($cleSession)->getValue('');
      $session = ($brut !== '') ? json_decode($brut, true) : null;
      if (!is_array($session)) {
        $session = null;
      }
      $mileage = (isset($_valeurs['mileage']) && is_numeric($_valeurs['mileage'])) ? (float) $_valeurs['mileage'] : null;

      // 4) Ouverture.
      if ($enTrajet && $session === null) {
        if ($mileage === null) {
          return; // départ vu mais odomètre inconnu : attendre un poll avec mileage (pas de start_mileage inventé, mirror UC24 « SOC inconnu »)
        }
        $session = array(
          'start_ts'      => time(),
          'start_mileage' => $mileage,
        );
        if (isset($_valeurs['latitude']) && is_numeric($_valeurs['latitude']) && isset($_valeurs['longitude']) && is_numeric($_valeurs['longitude'])) {
          $session['start_lat'] = (float) $_valeurs['latitude'];
          $session['start_lon'] = (float) $_valeurs['longitude'];
        }
        cache::set($cleSession, json_encode($session), self::TRIP_SESSION_TTL);
        return;
      }

      // 5) Poursuite : réécrit systématiquement → rafraîchit le TTL. start_* restent figés (1re détection).
      if ($enTrajet && $session !== null) {
        cache::set($cleSession, json_encode($session), self::TRIP_SESSION_TTL);
        return;
      }

      // 6) Clôture.
      if (!$enTrajet && $session !== null) {
        if ($mileage === null) {
          // Pas de mileage ce poll-ci : NE PAS clôturer (session conservée) — évite de perdre un trajet réel
          // sur un trou transitoire d'odomètre ; on attend un prochain poll qui en porte un (cf. advisor #3).
          return;
        }
        $lat = (isset($_valeurs['latitude']) && is_numeric($_valeurs['latitude'])) ? (float) $_valeurs['latitude'] : null;
        $lon = (isset($_valeurs['longitude']) && is_numeric($_valeurs['longitude'])) ? (float) $_valeurs['longitude'] : null;
        $recap = self::calculerRecapTrajet($session, time(), $mileage, $lat, $lon);
        if (count($recap) == 0) {
          // Trajet-fantôme (distance ≤ 0 : flicker moving/bruit GPS à l'arrêt) : purger sans écrire.
          cache::delete($cleSession);
          log::add('stellantis', 'debug', 'Trajet : clôture ignorée (distance nulle/négative, probable flicker) pour l\'équipement #' . $this->getId());
          return;
        }
        // Idempotence (comme UC24) : CONSOMMER (purger) la session AVANT d'écrire les commandes. Si l'état
        // reste « arrêté » au poll suivant → session absente → no-op (aucun doublon d'historique).
        cache::delete($cleSession);
        foreach ($recap as $logicalId => $valeur) {
          $cmd = $this->ensureCommand($logicalId); // création paresseuse ici
          $this->checkAndUpdateCmd($cmd, $valeur);
        }
        return;
      }
      // 7) !$enTrajet && $session === null : rien à faire (no-op silencieux).
    } catch (\Throwable $e) {
      log::add('stellantis', 'warning', 'Trajet : suivi en erreur pour l\'équipement #' . $this->getId() . ' : ' . $e->getMessage());
    }
  }

  /**
   * UC33 : calcule le récapitulatif d'un trajet clos. PUR/STATIQUE (aucun accès cache/cmd/config — tout
   * est passé en argument) → testable, en parallèle de calculerRecapSession (UC24). Garde-fou maître
   * (anti-fantôme) : un trajet n'est enregistrable que si start_mileage ET $_endMileage sont connus ET que
   * la distance calculée est strictement positive — sinon AUCUN champ n'est écrit (même pas la durée, cf.
   * 33-tech.md § 5 : distance > 0 est le seul critère de « vrai trajet »).
   * @return array logicalId => valeur (clés absentes = non calculables, contrat façon parseStatus/calculerRecapSession)
   */
  private static function calculerRecapTrajet(array $_session, int $_endTs, ?float $_endMileage, ?float $_endLat, ?float $_endLon): array {
    $recap = array();
    $startMileage = (isset($_session['start_mileage']) && is_numeric($_session['start_mileage'])) ? (float) $_session['start_mileage'] : null;
    if ($startMileage === null || $_endMileage === null) {
      return $recap; // pas de trajet enregistrable sans les deux relevés d'odomètre
    }
    $distance = $_endMileage - $startMileage;
    if ($distance <= 0) {
      return $recap; // anti-fantôme : flicker moving/bruit GPS à l'arrêt, pas un vrai trajet
    }
    $recap['trip_distance'] = round($distance, 1);
    $startTs = (isset($_session['start_ts']) && is_numeric($_session['start_ts'])) ? (int) $_session['start_ts'] : null;
    if ($startTs !== null) {
      $recap['trip_duration'] = (int) round(max(0, $_endTs - $startTs) / 60);
      $recap['trip_start'] = date('Y-m-d H:i:s', $startTs);
    }
    $recap['trip_end'] = date('Y-m-d H:i:s', $_endTs);
    if (isset($_session['start_lat']) && is_numeric($_session['start_lat']) && isset($_session['start_lon']) && is_numeric($_session['start_lon'])) {
      $recap['trip_start_position'] = self::formaterCoordonnee((float) $_session['start_lat']) . ',' . self::formaterCoordonnee((float) $_session['start_lon']);
    }
    if ($_endLat !== null && $_endLon !== null) {
      $recap['trip_end_position'] = self::formaterCoordonnee($_endLat) . ',' . self::formaterCoordonnee($_endLon);
    }
    return $recap;
  }

  /* * ******************* UC34 — Geofencing & alertes de zone ******************* */

  const HOME_RADIUS_DEFAUT = 150; // m — rayon par défaut si config plugin 'home_radius' vide/invalide
  const HOME_HYSTERESIS_M  = 50;  // m — marge de SORTIE ajoutée au rayon (anti-clignotement au bord, GPS variable)

  /**
   * UC34 : geofencing zone domicile UNIQUE, en config PLUGIN (home_lat/home_lon/home_radius — partagée
   * par TOUS les véhicules, décision validée : « le domicile » est une notion du foyer, pas du véhicule,
   * précédent broker_host/customer_id/map_tile_url). Produit 2 commandes info PAR VÉHICULE (at_home
   * binaire, home_distance en mètres) dérivées de latitude/longitude déjà collectées par refreshTelemetry()
   * (UC31, /lastPosition) — AUCUN appel réseau neuf (AC2 satisfait par construction). Best-effort, robuste
   * — NE LÈVE JAMAIS (try/catch enveloppant + log warning), posé EN DERNIER dans refreshTelemetry() (après
   * suivreTrajet(), robustesse cron, même convention que le suivi de charge/trajet UC24/33).
   * Hystérésis ASYMÉTRIQUE (répond au « À confirmer » de la spec fonctionnelle) : seuil d'ENTRÉE = rayon
   * nominal, seuil de SORTIE = rayon + HOME_HYSTERESIS_M — évite le clignotement au bord de zone malgré un
   * GPS de précision variable.
   * ⚠️ Confidentialité : ne JAMAIS loguer les coordonnées domicile ni la position véhicule (seulement
   * getId() de l'eqLogic si besoin, comme UC24/33).
   */
  private function suivreGeofencing(array $_valeurs): void {
    try {
      // 1) Zone domicile configurée ? Seuls lat/lon sont BLOQUANTS (absent/non numérique ⇒ geofencing off,
      // aucune commande créée/mise à jour — pas de faux « au domicile »/« parti » sans zone valide).
      $homeLat = trim((string) config::byKey('home_lat', 'stellantis'));
      $homeLon = trim((string) config::byKey('home_lon', 'stellantis'));
      if (!is_numeric($homeLat) || !is_numeric($homeLon)) {
        return;
      }
      // 2) Rayon : vide ⇒ défaut (silencieux, config volontairement incomplète) ; présent mais invalide
      // (≤0 / non numérique) ⇒ défaut + log debug (sinon at_home se figerait à 0 en silence sur une config
      // fautive, advisor #3).
      $radiusBrut = trim((string) config::byKey('home_radius', 'stellantis'));
      if ($radiusBrut == '') {
        $radius = self::HOME_RADIUS_DEFAUT;
      } elseif (is_numeric($radiusBrut) && (float) $radiusBrut > 0) {
        $radius = (float) $radiusBrut;
      } else {
        $radius = self::HOME_RADIUS_DEFAUT;
        log::add('stellantis', 'debug', 'Geofencing : rayon domicile configuré invalide, repli sur le défaut (' . self::HOME_RADIUS_DEFAUT . ' m) pour l\'équipement #' . $this->getId());
      }
      // 3) Position courante absente/non numérique (privacy active ou pas de fix GPS ce poll-ci) ⇒ on GÈLE
      // at_home/home_distance (ne fabrique jamais un faux « parti » sur un simple trou de position).
      if (!isset($_valeurs['latitude']) || !is_numeric($_valeurs['latitude']) || !isset($_valeurs['longitude']) || !is_numeric($_valeurs['longitude'])) {
        return;
      }
      $lat = (float) $_valeurs['latitude'];
      $lon = (float) $_valeurs['longitude'];
      // 4) Distance haversine (m), PURE.
      $distance = self::distanceHaversineM((float) $homeLat, (float) $homeLon, $lat, $lon);
      // 5) État précédent AVANT d'écrire (advisor #2) : création paresseuse ici (1re fois où la zone est
      // configurée ET une position est disponible). Cast défensif : cmd neuve ('' /null) ⇒ inconnu = 0
      // (advisor #5, mirror socCourant()/dernierStatut UC24).
      $cmd = $this->ensureCommand('at_home');
      $prec = $cmd->execCmd();
      $etaitAuDomicile = is_numeric($prec) ? (int) $prec : 0;
      // 6) Hystérésis asymétrique : sortie « collante » (rayon + marge) si déjà au domicile, entrée au
      // rayon nominal sinon.
      if ($etaitAuDomicile === 1) {
        $nouvelEtat = ($distance <= $radius + self::HOME_HYSTERESIS_M) ? 1 : 0;
      } else {
        $nouvelEtat = ($distance <= $radius) ? 1 : 0;
      }
      // 7) Écriture (jamais les coordonnées/la distance en clair dans un log, cf. docblock).
      $this->checkAndUpdateCmd($cmd, $nouvelEtat);
      $this->checkAndUpdateCmd($this->ensureCommand('home_distance'), (int) round($distance));
    } catch (\Throwable $e) {
      log::add('stellantis', 'warning', 'Geofencing : suivi en erreur pour l\'équipement #' . $this->getId() . ' : ' . $e->getMessage());
    }
  }

  /**
   * UC34 : distance haversine entre 2 points (degrés décimaux), en MÈTRES. PUR/STATIQUE (aucun accès
   * cache/cmd/config) → testable indépendamment, en parallèle de calculerRecapTrajet/calculerRecapSession.
   */
  private static function distanceHaversineM(float $_lat1, float $_lon1, float $_lat2, float $_lon2): float {
    $rayonTerreM = 6371000.0;
    $dLat = deg2rad($_lat2 - $_lat1);
    $dLon = deg2rad($_lon2 - $_lon1);
    $a = sin($dLat / 2) * sin($dLat / 2)
      + cos(deg2rad($_lat1)) * cos(deg2rad($_lat2)) * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $rayonTerreM * $c;
  }

  /* * ******************* UC41 — Kilométrage & entretien ******************* */

  const MAINTENANCE_NEXT_KEY   = 'stellantis::maintenance_next::'; // + eqId (cache) : timestamp de prochaine interrogation /maintenance autorisée
  const MAINTENANCE_TTL_OK     = 86400;   // 24 h — cadence nominale (donnée à évolution lente, anti-ban)
  const MAINTENANCE_TTL_ABSENT = 604800;  // 7 j  — endpoint 404 (non supporté par ce véhicule/forfait)
  const MAINTENANCE_TTL_ERREUR = 10800;   // 3 h  — erreur transitoire (réessai le jour même, sans storm)
  const SERVICE_ALERT_KM_DEFAUT   = 1000; // km   — seuil « révision proche » par défaut (repli si config véhicule vide/invalide)
  const SERVICE_ALERT_DAYS_DEFAUT = 30;   // j    — idem, jours

  /**
   * UC41 : mapping PUR et défensif de la réponse GET /maintenance vers logicalId => valeur. Champ absent
   * => clé absente (jamais d'exception), même contrat que parseStatus(). SEUL endroit portant les chemins
   * JSON /maintenance (cf. 41-tech.md § contrat).
   * Parsing défensif multi-shape : le wrapper de réponse runtime n'est PAS garanti côté référence (pas de
   * maintenance_embedded.py, contrairement à alerts_embedded.py/status_embedded.py) → on tente
   * _embedded.maintenance (shape HAL probable) puis la racine du document (repli).
   * @return array logicalId => valeur ('service_distance'/'service_days', selon ce que l'API expose)
   */
  public static function parseMaintenance(array $_maint): array {
    $valeurs = array();
    $obj = (isset($_maint['_embedded']['maintenance']) && is_array($_maint['_embedded']['maintenance']))
      ? $_maint['_embedded']['maintenance']
      : $_maint;
    if (isset($obj['mileageBeforeMaintenance']) && is_numeric($obj['mileageBeforeMaintenance'])) {
      $valeurs['service_distance'] = (float) $obj['mileageBeforeMaintenance'];
    }
    // ⚠️ Faute de frappe RÉELLE de l'API (attribute_map du modèle de référence) : un seul 'n'. Repli sur
    // l'orthographe correcte en second, au cas où un millésime la corrige. is_numeric() STRICT (jamais une
    // vérité) : 0 (révision due aujourd'hui) et les valeurs négatives (révision dépassée) sont valides,
    // JAMAIS clampées (cf. 41-tech.md § 2).
    if (isset($obj['daysBeforeMaintenace']) && is_numeric($obj['daysBeforeMaintenace'])) {
      $valeurs['service_days'] = (int) $obj['daysBeforeMaintenace'];
    } elseif (isset($obj['daysBeforeMaintenance']) && is_numeric($obj['daysBeforeMaintenance'])) {
      $valeurs['service_days'] = (int) $obj['daysBeforeMaintenance'];
    }
    return $valeurs;
  }

  /**
   * UC41 : détermine si une révision est proche. PUR/STATIQUE (aucun accès cache/cmd/config), en parallèle
   * de calculerRecapSession/calculerRecapTrajet/distanceHaversineM. Retourne null si AUCUN des deux champs
   * n'est disponible (⇒ suivreMaintenance() n'émet pas service_due), sinon 1/0.
   * ⚠️ Comparaison <= (et non < de la spec fonctionnelle) : déviation CONSCIENTE — une valeur exactement au
   * seuil doit déclencher l'alerte (cf. 41-tech.md § 3).
   */
  private static function calculerRevisionProche(array $_valeurs, int $_seuilKm, int $_seuilJours): ?int {
    if (!isset($_valeurs['service_distance']) && !isset($_valeurs['service_days'])) {
      return null;
    }
    if (isset($_valeurs['service_distance']) && (float) $_valeurs['service_distance'] <= $_seuilKm) {
      return 1;
    }
    if (isset($_valeurs['service_days']) && (float) $_valeurs['service_days'] <= $_seuilJours) {
      return 1;
    }
    return 0;
  }

  // Seuil d'alerte « révision proche » (km) : config VÉHICULE 'service_alert_km', repli sur le défaut si
  // vide/non numérique/négatif — précédent capaciteBatterieKwh/tarifCharge (UC24) : le formulaire desktop
  // soumet toujours la clé, une valeur vide ne doit PAS être interprétée comme un seuil de 0.
  private function seuilAlerteKm(): int {
    $config = trim((string) $this->getConfiguration('service_alert_km', ''));
    if ($config != '' && is_numeric($config) && (float) $config >= 0) {
      return (int) $config;
    }
    return self::SERVICE_ALERT_KM_DEFAUT;
  }

  // Idem, seuil en jours (config véhicule 'service_alert_days').
  private function seuilAlerteJours(): int {
    $config = trim((string) $this->getConfiguration('service_alert_days', ''));
    if ($config != '' && is_numeric($config) && (float) $config >= 0) {
      return (int) $config;
    }
    return self::SERVICE_ALERT_DAYS_DEFAUT;
  }

  /**
   * UC41 : interroge l'endpoint dédié GET /maintenance (throttlé séparément du /status, cadence 24 h
   * nominale) et met à jour service_distance/service_days/service_due. Best-effort, robuste — NE LÈVE
   * JAMAIS (try/catch enveloppant + log info), posé EN DERNIER dans refreshTelemetry() (même convention
   * que le suivi de charge/trajet/geofencing UC24/33/34). Disponibilité NON garantie (cf. 41-tech.md § 1) :
   * un throttle LONG est posé sur 404 pour ne pas réinterroger un endpoint mort à chaque cycle.
   * Un throttle est TOUJOURS posé (succès + toute branche catch), sauf le court-circuit rate-limit
   * ci-dessous (lui-même borné) ⇒ aucun retry-storm possible.
   * ⚠️ Catch élargi à \Throwable (et non le seul stellantisException du pseudo-code de 41-tech.md § 4) :
   * garantit le « ne lève jamais » promis par la doc, en cohérence avec suivreSessionCharge/suivreTrajet/
   * suivreGeofencing (UC24/33/34) qui protègent déjà chacune leur propre corps de méthode.
   */
  private function suivreMaintenance(string $_apiId): void {
    $cle = self::MAINTENANCE_NEXT_KEY . $this->getId();
    if ((int) cache::byKey($cle)->getValue(0) > time()) {
      return; // throttle actif : rien à faire ce cycle-ci
    }
    if (stellantisApi::rateLimitRemaining() > 0) {
      return; // cooldown anti rate-limit actif : réessai après, SANS poser de throttle maintenance (déjà borné par le cooldown lui-même)
    }
    try {
      $maint = stellantisApi::callWithToken('GET', '/user/vehicles/' . $_apiId . '/maintenance');
      cache::set($cle, time() + self::MAINTENANCE_TTL_OK, self::MAINTENANCE_TTL_OK);
      $valeurs = self::parseMaintenance($maint);
      if (empty($valeurs)) {
        return; // endpoint présent mais sans échéance exploitable (forfait/modèle) : rien à créer (AC2)
      }
      $due = self::calculerRevisionProche($valeurs, $this->seuilAlerteKm(), $this->seuilAlerteJours());
      if ($due !== null) {
        $valeurs['service_due'] = $due;
      }
      foreach ($valeurs as $logicalId => $valeur) {
        $cmd = $this->ensureCommand($logicalId); // création paresseuse ici
        $this->checkAndUpdateCmd($cmd, $valeur);
      }
    } catch (\Throwable $e) {
      // 404 = endpoint non supporté par CE véhicule/forfait (cf. 41-tech.md § 1.3) → throttle LONG (évite
      // d'interroger un endpoint mort + du bruit de log). Erreur transitoire (5xx/timeout/token/exception
      // inattendue) → throttle COURT (réessai le jour même, sans storm).
      $httpCode = ($e instanceof stellantisException) ? $e->getHttpCode() : 0;
      $apiType = ($e instanceof stellantisException) ? $e->getApiType() : 'error';
      $ttl = ($httpCode == 404) ? self::MAINTENANCE_TTL_ABSENT : self::MAINTENANCE_TTL_ERREUR;
      cache::set($cle, time() + $ttl, $ttl);
      log::add('stellantis', 'info', 'Échéances d\'entretien non récupérées (' . $apiType . ') — poursuite');
    }
  }

  /* * ******************* UC42/UC43 — Alertes véhicule (pression pneus + catalogue générique) ******************* */

  const ALERTS_NEXT_KEY   = 'stellantis::alerts_next::'; // + eqId (cache) : timestamp de prochaine interrogation /alerts autorisée
  const ALERTS_TTL_OK     = 3600;    // 1 h  — cadence nominale (donnée à évolution lente côté véhicule, déjà signalée au conducteur au tableau de bord ; cf. 42-tech.md § 5)
  const ALERTS_TTL_ABSENT = 604800;  // 7 j  — endpoint 403/404 (non supporté par ce véhicule/forfait)
  const ALERTS_TTL_ERREUR = 10800;   // 3 h  — erreur transitoire (réessai le jour même, sans storm)
  // Catalogue best-effort des types AlertMsgEnum liés aux pneus (42-tech.md § contrat), EN MINUSCULES
  // (comparaison insensible à la casse : la casse API réelle est incohérente/non vérifiée, ex.
  // 'frontright...' vs 'frontLeft...'). Extensible si un dump runtime révèle d'autres types.
  const TYRE_ALERT_TYPES = array(
    'tyreunderinflation', 'underinflationtyrefault', 'wheelpressurefault', 'adjusttyrepressure',
    'frontlefttyrenotmonitored', 'frontrighttyrenotmonitored', 'rearlefttyrenotmonitored', 'rearrighttyrenotmonitored',
  );
  // UC43 : préfixe logicalId des binaires d'alerte PAR TYPE (création dynamique, catalogue AlertMsgEnum non
  // figé). ⚠️ Ni 'alerts_count' (pluriel + suffixe '_count') ni 'tyre_alert' (préfixe 'tyre_') ne commencent
  // par 'alert_' (vérifié caractère par caractère) : jamais capturés par le filtre de remise à 0.
  const ALERT_CMD_PREFIX = 'alert_';
  // UC43 (review sécurité) : plafond anti-prolifération — une réponse /alerts anormale (bug amont/API
  // compromise) ne doit pas créer un nombre illimité de commandes PERSISTANTES. Généreux vs le catalogue
  // AlertMsgEnum réel (~80 types, cf. 43-tech.md § contrat) : ne borne jamais un usage légitime.
  const ALERT_MAX_TYPES = 100;

  /**
   * UC42 : mapping PUR et défensif de la réponse GET /alerts vers la liste des `type` d'alertes ACTIVES
   * (chaînes brutes, NON filtrées pneus — GÉNÉRIQUE et forward-compatible : UC43 doit RÉUTILISER cette
   * méthode telle quelle pour son catalogue complet, jamais en écrire une seconde). Shape/champ absent =>
   * liste vide, jamais d'exception, même contrat que parseStatus()/parseMaintenance(). SEUL endroit portant
   * les chemins JSON /alerts (cf. 42-tech.md § 2). Parsing multi-shape défensif : _embedded.alerts (shape
   * HAL probable, modèle AlertsEmbedded de référence) puis 'alerts' puis repli racine=liste.
   * @return string[] types d'alertes actives (chaînes brutes telles que reçues de l'API)
   */
  public static function parseAlertes(array $_resp): array {
    if (isset($_resp['_embedded']['alerts']) && is_array($_resp['_embedded']['alerts'])) {
      $liste = $_resp['_embedded']['alerts'];
    } elseif (isset($_resp['alerts']) && is_array($_resp['alerts'])) {
      $liste = $_resp['alerts'];
    } elseif (function_exists('array_is_list') ? array_is_list($_resp) : array_keys($_resp) === range(0, count($_resp) - 1)) {
      $liste = $_resp; // repli racine=liste
    } else {
      $liste = array();
    }
    $types = array();
    foreach ($liste as $a) {
      if (!is_array($a) || !isset($a['type']) || !is_string($a['type'])) {
        continue;
      }
      // ⚠️ FAIL-CLOSED (décision validée, 42-tech.md § décisions) : 'active' absente ⇒ INACTIVE. Sur un
      // endpoint dont la shape runtime n'est PAS vérifiée, on ne conclut JAMAIS « alerte active » par
      // défaut (éviter le faux positif « crie au loup »). Log debug (best-effort, jamais error) pour
      // confirmer la vraie sémantique en recette.
      if (!isset($a['active'])) {
        log::add('stellantis', 'debug', '/alerts : entrée sans valeur active exploitable (type ' . self::aseptiser($a['type'], 60) . ') — ignorée (fail-closed)');
        continue;
      }
      if (!filter_var($a['active'], FILTER_VALIDATE_BOOLEAN)) {
        continue;
      }
      $types[] = $a['type'];
    }
    return $types;
  }

  /**
   * UC42 : détermine si au moins un type d'alerte pneu est actif. PUR/STATIQUE (aucun accès cache/cmd/
   * config), en parallèle de calculerRevisionProche/calculerRecapSession/calculerRecapTrajet/
   * distanceHaversineM. Retourne TOUJOURS 0 ou 1 (jamais null) : sur une réponse /alerts réussie, la valeur
   * est systématiquement émise (satisfait AC3 « absence d'alerte pneus ⇒ info à 0 »).
   */
  private static function calculerAlertePneu(array $_typesActifs): int {
    foreach ($_typesActifs as $t) {
      if (in_array(strtolower(trim($t)), self::TYRE_ALERT_TYPES, true)) {
        return 1;
      }
    }
    return 0;
  }

  /**
   * UC43 : assainit un type d'alerte brut (chaîne AlertMsgEnum reçue de l'API) en slug de logicalId
   * (minuscule, [^a-z0-9]→'_', bornes '_' retirées). PUR/STATIQUE, unique endroit portant cette règle
   * (réutilisé par ensureAlertCommand() et synchroniserCommandesAlertes() — pas de duplication).
   * Peut renvoyer '' (type inexploitable : vide ou composé uniquement de caractères non alphanumériques) ;
   * c'est au CALLER de décider quoi faire d'un slug vide (garde anti-vide, cf. les deux appelants).
   */
  private static function sluggifierTypeAlerte(string $_typeBrut): string {
    return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($_typeBrut))), '_');
  }

  /**
   * UC43 : retourne la commande info binaire dédiée au type d'alerte $_typeBrut (chaîne AlertMsgEnum brute
   * telle que reçue de l'API), la créant si absente (idempotent). Le catalogue (~80 types, non figé) ne
   * peut pas être déclaré statiquement dans definitionsCommandes() → création DYNAMIQUE ici, réutilisant
   * la plomberie commune trouverOuInstancierCmd() avec une définition SYNTHÉTIQUE (pas de duplication du
   * setEqLogic_id/type/subType/visible, aucun changement de signature du core).
   * logicalId = ALERT_CMD_PREFIX + slug (sluggifierTypeAlerte()). Garde anti-vide : un type qui s'assainit
   * en chaîne vide ne crée PAS de commande 'alert_' (type inexploitable) — chemin normalement inatteignable
   * depuis synchroniserCommandesAlertes() (qui pré-filtre déjà les slugs vides), conservé en défense
   * (même pattern que trouverOuInstancierCmd() : contrat de retour non-nullable ⇒ échec = exception, pas
   * un retour partiel silencieux), capturé par le try/catch \Throwable interne de suivreAlertes().
   * Nom = libellé BRUT sécurisé (aseptiser + htmlspecialchars) — donnée RUNTIME, jamais __() (piège i18n
   * UC07 : __() n'accepte qu'une chaîne LITTÉRALE, sans quoi l'extracteur i18n ne la capture pas).
   */
  private function ensureAlertCommand(string $_typeBrut): stellantisCmd {
    $slug = self::sluggifierTypeAlerte($_typeBrut);
    if ($slug == '') {
      // Type inexploitable (vide/caractères non alphanumériques uniquement) : ne jamais créer 'alert_'.
      throw new stellantisException('Type d\'alerte inexploitable (slug vide) : ' . htmlspecialchars(self::aseptiser($_typeBrut, 60), ENT_QUOTES, 'UTF-8'));
    }
    $logicalId = self::ALERT_CMD_PREFIX . $slug;
    $nom = htmlspecialchars(self::aseptiser($_typeBrut, 60), ENT_QUOTES, 'UTF-8');
    $cmd = $this->trouverOuInstancierCmd('info', $logicalId, array($logicalId => array($nom, 'binary')));
    if ($cmd->getId() != '') {
      return $cmd; // déjà existante : rien à (re)configurer
    }
    $cmd->setGeneric_type('');
    $cmd->setIsHistorized(0); // binaires PAR TYPE non historisées (isHistorized ≠ trigger de scénario) : cf. 43-tech.md
    $cmd->save();
    // Traçabilité recette : mapping type assaini → logicalId à la 1re création (détection de collision d'assainissement).
    log::add('stellantis', 'info', 'Nouvelle alerte véhicule détectée : ' . $nom . ' (commande ' . $logicalId . ')');
    return $cmd;
  }

  /**
   * UC43 : synchronise les commandes d'alerte PAR TYPE (une binaire par type AlertMsgEnum rencontré) +
   * l'agrégat 'alerts_count', à partir du $_typesActifs DÉJÀ parsé par parseAlertes() (aucun re-fetch, aucun
   * re-parse — cf. 43-tech.md § réutilisation UC42). Appelée UNIQUEMENT depuis le bloc d'écriture isolé de la
   * branche succès de suivreAlertes() (jamais d'accès réseau/cache de throttle ici).
   * - Actifs → 1 (création paresseuse via ensureAlertCommand), plafonnés à ALERT_MAX_TYPES (review sécurité :
   *   borne la création de commandes PERSISTANTES si /alerts renvoie un nombre anormal de types distincts).
   * - Plus actifs → 0 (les commandes PERSISTENT, jamais supprimées — « une par type rencontré »).
   * - 'alerts_count' = nombre de types DISTINCTS actifs RETENUS (après plafond), émis à chaque succès.
   */
  private function synchroniserCommandesAlertes(array $_typesActifs): void {
    // 1) Ensemble actif dédupliqué par slug (clé slug → type brut), slugs vides ignorés.
    $actifs = array();
    foreach ($_typesActifs as $typeBrut) {
      if (!is_string($typeBrut)) {
        continue;
      }
      $slug = self::sluggifierTypeAlerte($typeBrut);
      if ($slug == '') {
        continue;
      }
      $actifs[$slug] = $typeBrut;
    }
    // Plafond anti-prolifération (review sécurité) : une réponse /alerts anormale (bug amont/API compromise)
    // ne doit pas créer un nombre illimité de commandes PERSISTANTES. Troncature AVANT toute création — la
    // suite (création, remise à 0, alerts_count) ne voit plus que les ALERT_MAX_TYPES premiers types.
    if (count($actifs) > self::ALERT_MAX_TYPES) {
      log::add('stellantis', 'warning', '/alerts : ' . count($actifs) . ' types actifs > plafond ' . self::ALERT_MAX_TYPES . ' — création limitée aux ' . self::ALERT_MAX_TYPES . ' premiers');
      $actifs = array_slice($actifs, 0, self::ALERT_MAX_TYPES, true);
    }
    // 2) Actifs → 1.
    foreach ($actifs as $typeBrut) {
      $cmd = $this->ensureAlertCommand($typeBrut);
      $this->checkAndUpdateCmd($cmd, 1);
    }
    // 3) Plus actifs → 0 (énumération DB, pas un cache d'état : survit à cache::flush(), pas de staleness
    //    silencieuse). Garde reserved-ids : le préfixe ALERT_CMD_PREFIX exclut déjà 'alerts_count'/'tyre_alert'
    //    (vérifié caractère par caractère, cf. commentaire de la constante) ; liste explicite ci-dessous en
    //    plus (belt & suspenders), pour rester sûr même si un futur logicalId réservé venait à changer.
    $logicalIdsActifs = array();
    foreach (array_keys($actifs) as $slug) {
      $logicalIdsActifs[self::ALERT_CMD_PREFIX . $slug] = true;
    }
    $commandesInfo = cmd::byEqLogicId($this->getId(), 'info');
    if (is_array($commandesInfo)) {
      foreach ($commandesInfo as $cmdInfo) {
        $lid = $cmdInfo->getLogicalId();
        if (in_array($lid, array('alerts_count', 'tyre_alert'), true)) {
          continue; // reserved-ids explicite : jamais touchées ici, déjà exclues par le préfixe ci-dessous
        }
        if (strpos($lid, self::ALERT_CMD_PREFIX) !== 0) {
          continue; // pas une commande d'alerte PAR TYPE
        }
        if (isset($logicalIdsActifs[$lid])) {
          continue; // toujours actif : déjà remis à 1 ci-dessus
        }
        $this->checkAndUpdateCmd($cmdInfo, 0); // type revu inactif : retombe à 0 SANS être supprimé
      }
    }
    // 4) Agrégat 'alerts_count' (types DISTINCTS RETENUS après plafond), émis à chaque succès (0 si aucun ⇒ AC2).
    $this->checkAndUpdateCmd($this->ensureCommand('alerts_count'), count($actifs));
  }

  /**
   * UC42/UC43 : interroge l'endpoint dédié GET /alerts (throttlé séparément du /status et de /maintenance,
   * cadence 1 h nominale) et met à jour tyre_alert (0/1, UC42) + le catalogue complet des alertes par type
   * et l'agrégat alerts_count (UC43). Best-effort, robuste — NE LÈVE JAMAIS (try/catch \Throwable
   * enveloppant + log info), posé EN DERNIER dans refreshTelemetry() (après suivreMaintenance, même
   * convention que le suivi de charge/trajet/geofencing/entretien UC24/33/34/41).
   * Disponibilité NON garantie (cf. 42-tech.md § 1) : un throttle LONG est posé sur 403/404 pour ne pas
   * réinterroger un endpoint mort à chaque cycle. Un throttle est TOUJOURS posé (succès + toute branche
   * catch), sauf le court-circuit rate-limit ci-dessous (lui-même borné) ⇒ aucun retry-storm possible.
   * Universel (tout véhicule a des pneus — aucune garde de motorisation).
   *
   * ⚠️ Le throttle succès est posé AVANT les écritures de commandes, elles-mêmes isolées dans un
   * try/catch \Throwable INTERNE (43-tech.md § restructuration) : un aléa d'écriture (DB…) ne doit PAS
   * étiqueter à tort un succès réseau comme « échec » et écraser le throttle 1 h par le throttle 3 h
   * (défaut préexistant UC42, corrigé ici en passant). Le catch EXTERNE (403/404 → 7 j, transitoire → 3 h)
   * ne capture donc plus que les vrais échecs de fetch/parse /alerts.
   *
   * parseAlertes() renvoie déjà la liste GÉNÉRIQUE des types actifs (pas seulement pneus), réutilisée TELLE
   * QUELLE par synchroniserCommandesAlertes() (UC43) — AUCUN second appel/cache sur /alerts (cf. 43-tech.md
   * § Architecture : interdiction formelle d'un 2e poller, budget anti-ban doublé sinon).
   */
  private function suivreAlertes(string $_apiId): void {
    $cle = self::ALERTS_NEXT_KEY . $this->getId();
    if ((int) cache::byKey($cle)->getValue(0) > time()) {
      return; // throttle actif : rien à faire ce cycle-ci
    }
    if (stellantisApi::rateLimitRemaining() > 0) {
      return; // cooldown anti rate-limit actif : réessai après, SANS poser de throttle alerts (déjà borné par le cooldown lui-même)
    }
    try {
      $resp = stellantisApi::callWithToken('GET', '/user/vehicles/' . $_apiId . '/alerts');
      // Throttle succès posé AVANT les écritures (l'appel /alerts a réussi, quoi qu'il arrive ensuite).
      cache::set($cle, time() + self::ALERTS_TTL_OK, self::ALERTS_TTL_OK);
      try {
        $typesActifs = self::parseAlertes($resp);
        // UC42 : tyre_alert (agrégat pneus dédié), TOUJOURS émis sur succès (AC3).
        $this->checkAndUpdateCmd($this->ensureCommand('tyre_alert'), self::calculerAlertePneu($typesActifs));
        // UC43 : binaires par type + alerts_count (réutilise le MÊME $typesActifs, aucun re-fetch/re-parse).
        $this->synchroniserCommandesAlertes($typesActifs);
      } catch (\Throwable $e2) {
        // Aléa d'écriture de commande (DB…) : ne PAS toucher au throttle succès déjà posé (le /alerts a
        // réussi) — sinon un incident local serait mal étiqueté « échec réseau ». Best-effort, poursuite.
        log::add('stellantis', 'warning', 'Écriture des commandes d\'alerte échouée — poursuite : ' . $e2->getMessage());
      }
    } catch (\Throwable $e) {
      // 403/404 = endpoint non supporté par CE véhicule/forfait (cf. 42-tech.md § 1) → throttle LONG (évite
      // d'interroger un endpoint mort + du bruit de log). Erreur transitoire (5xx/timeout/token/exception
      // inattendue) → throttle COURT (réessai le jour même, sans storm).
      $httpCode = ($e instanceof stellantisException) ? $e->getHttpCode() : 0;
      $apiType = ($e instanceof stellantisException) ? $e->getApiType() : 'error';
      $ttl = ($httpCode == 403 || $httpCode == 404) ? self::ALERTS_TTL_ABSENT : self::ALERTS_TTL_ERREUR;
      cache::set($cle, time() + $ttl, $ttl);
      log::add('stellantis', 'info', 'Alertes véhicule non récupérées (' . $apiType . ') — poursuite');
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
      // UC13/UC14 : une commande MQTT (wakeup, charge…) a été acquittée depuis la dernière passe (flag posé
      // par traiterRetourCommande, UC18) → remontée d'état FORCÉE (hors cadence autorefresh), avec les
      // garde-fous 429 de refreshTelemetry(). Consommé une seule fois. Le backoff rate-limit ci-dessus a
      // déjà court-circuité la passe si besoin.
      $clePending = self::CMD_PENDING_KEY . $eqLogic->getId();
      if (cache::byKey($clePending)->getValue('') != '') {
        cache::delete($clePending);
        try {
          $eqLogic->refreshTelemetry();
        } catch (\Throwable $e) {
          log::add('stellantis', 'warning', 'Cron : rafraîchissement post-commande en erreur pour l\'équipement #' . $eqLogic->getId() . ' : ' . $e->getMessage());
        }
        continue;
      }
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

  /* * ******************* UC32 — Panneau carte « Mes véhicules » ******************* */

  /**
   * Backfill du défaut « visible dans le panneau carte » : ne pose la clé QUE si absente (ne réécrase
   * jamais un choix utilisateur explicite, y compris un décochage à 0). Retourne true si modifié (pour
   * mutualiser un éventuel save() unique côté appelant). Appelée par syncVehicles() (avant le save() de
   * la boucle de découverte) ET par install.php::stellantis_update() (backfill des installs antérieures
   * à UC32) — d'où la visibilité PUBLIQUE malgré son rôle de pure plomberie interne (install.php est un
   * point d'entrée externe qui ne peut appeler qu'une méthode publique de la classe stellantis, cf.
   * CLAUDE.md § autoload).
   */
  public static function assurerVisiblePanelParDefaut(eqLogic $_eqLogic): bool {
    if (trim((string) $_eqLogic->getConfiguration('isVisiblePanel', '')) === '') {
      $_eqLogic->setConfiguration('isVisiblePanel', 1);
      return true;
    }
    return false;
  }

  /**
   * Pose le widget carte 'stellantis::stellantisMap' sur la commande info 'position' donnée, SI VIDE
   * uniquement (idempotent — n'écrase jamais un template choisi manuellement par l'utilisateur, cf.
   * jeedom-widgets-commandes.md § 6), dashboard ET mobile. Retourne true si modifié (le caller décide du
   * save() — un seul, mutualisé, jamais fait ICI). Mutualisée entre createCommands() (instance) et
   * install.php::stellantis_update() (backfill des installs antérieures à UC32) — PUBLIQUE pour la même
   * raison qu'assurerVisiblePanelParDefaut() (install.php est un point d'entrée externe, cf. CLAUDE.md §
   * autoload).
   */
  public static function assurerTemplatePositionParDefaut(cmd $_cmdPosition): bool {
    $modifie = false;
    if ($_cmdPosition->getTemplate('dashboard', '') === '') {
      $_cmdPosition->setTemplate('dashboard', 'stellantis::stellantisMap');
      $modifie = true;
    }
    if ($_cmdPosition->getTemplate('mobile', '') === '') {
      $_cmdPosition->setTemplate('mobile', 'stellantis::stellantisMap');
      $modifie = true;
    }
    return $modifie;
  }

  // Dossier de cache FICHIER des tuiles carte (PAS cache:: DB — des images binaires n'ont rien à faire en
  // base). Sous-dossier dédié du tmp du plugin (déjà utilisé par le démon pour son .pid, cf. pidFile()) ;
  // créé paresseusement au besoin (best-effort, @mkdir).
  private static function dossierCacheTuile(): string {
    $dossier = jeedom::getTmpFolder('stellantis') . '/maptiles';
    if (!is_dir($dossier)) {
      @mkdir($dossier, 0775, true);
    }
    return $dossier;
  }

  // Chemin du fichier de cache (succès) pour l'URL de tuile déjà construite (arrondie lat/lon incluse,
  // cf. renderStaticMap). Le marqueur d'échec (négative-cache) est ce même chemin suffixé '.fail'.
  private static function cheminCacheTuile(string $_url): string {
    return self::dossierCacheTuile() . '/maptile_' . md5($_url) . '.png';
  }

  // Nettoyage opportuniste (PAS de cron dédié — nombre de véhicules faible) : purge à l'écriture les
  // fichiers de cache (succès ET marqueurs d'échec) plus vieux que leur TTL respectif.
  private static function nettoyerCacheTuiles(): void {
    $fichiers = @glob(self::dossierCacheTuile() . '/maptile_*');
    if (!is_array($fichiers)) {
      return;
    }
    $maintenant = time();
    foreach ($fichiers as $fichier) {
      if (!is_file($fichier)) {
        continue;
      }
      $ttl = (substr($fichier, -5) === '.fail') ? self::MAP_CACHE_TTL_ECHEC : self::MAP_CACHE_TTL;
      $mtime = @filemtime($fichier);
      if ($mtime !== false && ($maintenant - $mtime) > $ttl) {
        @unlink($fichier);
      }
    }
  }

  /**
   * Formate une coordonnée (latitude/longitude) en notation décimale US à 4 décimales, INDÉPENDANTE de
   * la locale serveur (LC_NUMERIC). Une simple concaténation/`(string) $float` produirait "48,8566" (au
   * lieu de "48.8566") sur un Jeedom configuré en locale fr_FR — cassant les URLs de tuile et les liens
   * OSM/`geo:`. `number_format()` ignore TOUJOURS la locale (elle prend ses séparateurs en paramètres
   * explicites), contrairement à un cast/concat de float. PUBLIQUE : réutilisée par desktop/php/panel.php
   * (point d'entrée externe, cf. CLAUDE.md § autoload) pour le même besoin d'affichage/URL.
   */
  public static function formaterCoordonnee(float $_valeur): string {
    return number_format($_valeur, 4, '.', '');
  }

  /**
   * Construit l'URL de la tuile statique (PURE, testable indépendamment) : fournisseur par défaut ou
   * surchargé par la config avancée 'map_tile_url', centré sur lat/lon (déjà arrondis par l'appelant),
   * avec un repère (marker) sur la position. Contrat query-string best-effort (fournisseur communautaire,
   * cf. § « À confirmer » de la spec fonctionnelle) : maptype=mapnik, markers=lat,lon,red-pushpin.
   */
  public static function construireUrlTuile(float $_lat, float $_lon, int $_zoom, string $_size): string {
    $base = trim((string) config::byKey('map_tile_url', 'stellantis', self::MAP_TILE_URL));
    if ($base === '') {
      $base = self::MAP_TILE_URL;
    }
    // Notation décimale US indépendante de la locale (cf. formaterCoordonnee) : sinon LC_NUMERIC=fr_FR
    // produirait "48,8566" et casserait la query string du fournisseur de tuiles.
    $centre = self::formaterCoordonnee($_lat) . ',' . self::formaterCoordonnee($_lon);
    $query = http_build_query(array(
      'center' => $centre,
      'zoom' => $_zoom,
      'size' => $_size,
      'maptype' => 'mapnik',
      'markers' => $centre . ',red-pushpin',
    ));
    return $base . '?' . $query;
  }

  /**
   * Télécharge la tuile statique EN MÉMOIRE (image de quelques dizaines de Ko, pas de flux disque comme
   * downloadToFile/UC61). Retourne les octets PNG/JPEG bruts, ou null sur tout échec (jamais d'exception :
   * cette méthode est un simple enrichissement, le repli placeholder est géré par l'appelant).
   * Durci anti-ban / anti-SSRF : User-Agent identifiant obligatoire, HTTPS strict, PAS de redirection
   * suivie, timeout court, taille bornée, PAS de header Authorization (serveur tiers public, sans rapport
   * avec l'API Stellantis — cf. downloadToFile pour la même règle sur l'APK).
   */
  private static function telechargerTuile(string $_url): ?string {
    $scheme = strtolower((string) parse_url($_url, PHP_URL_SCHEME));
    if ($scheme !== 'https') {
      log::add('stellantis', 'warning', 'Tuile carte : URL refusée (HTTPS obligatoire)');
      return null;
    }
    $protos = defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 0;
    $ch = curl_init();
    $options = array(
      CURLOPT_URL => $_url,
      CURLOPT_HTTPGET => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HEADER => false,
      // Anti-SSRF : jamais de redirection suivie (contrairement à downloadToFile — ici l'URL peut venir
      // d'une config avancée libre, on ne veut pas qu'un 3xx pointe ailleurs sans revalidation de schéma).
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_MAXFILESIZE => self::MAP_TUILE_TAILLE_MAX,
      CURLOPT_USERAGENT => self::MAP_USER_AGENT,
      CURLOPT_HTTPHEADER => array('Accept: image/png,image/*;q=0.8'),
    );
    if ($protos !== 0) {
      $options[CURLOPT_PROTOCOLS] = $protos;
    }
    curl_setopt_array($ch, $options);
    $corps = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($corps === false || $curlErrno !== 0) {
      log::add('stellantis', 'info', 'Tuile carte : erreur de transport cURL #' . $curlErrno . ' : ' . $curlError);
      return null;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
      log::add('stellantis', 'info', 'Tuile carte : refusée par le fournisseur (HTTP ' . $httpCode . ')');
      return null;
    }
    // Allow-list stricte aux formats raster attendus (PAS un simple préfixe 'image/' générique — ça
    // laisserait passer 'image/svg+xml', un SVG pouvant embarquer du script/markup). Ne relaie JAMAIS
    // autre chose (page d'erreur HTML, SVG…) comme si c'était l'image attendue.
    $typeNormalise = strtolower($contentType);
    if (strpos($typeNormalise, 'image/png') !== 0 && strpos($typeNormalise, 'image/jpeg') !== 0) {
      log::add('stellantis', 'info', 'Tuile carte : type de contenu inattendu (' . $contentType . ')');
      return null;
    }
    return (string) $corps;
  }

  // PNG 1×1 transparent minimal : repli en cas de tuile indisponible/désactivée. Le panel/widget affichent
  // de toute façon les coordonnées + un lien texte en dur (défense en profondeur, cf. spec fonctionnelle) —
  // cette image n'est qu'un enrichissement, jamais la seule source d'information.
  private static function tuilePlaceholder(): string {
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
  }

  /**
   * Rend la tuile carte du véhicule $_eqLogicId, pour le proxy AJAX (widget dashboard) ET pour l'inline
   * data: URI du panel (même code, cf. § « data: URI vs proxy » de la spec technique). NE LÈVE JAMAIS :
   * chaque cas attendu (équipement absent/désactivé, droit manquant, position indisponible/invalide,
   * fournisseur injoignable) retombe sur le placeholder — cette méthode est un simple enrichissement
   * visuel, jamais une source d'erreur fatale pour l'appelant (endpoint AJAX ou page HTML).
   * @return array{type:string,body:string}
   */
  public static function renderStaticMap(int $_eqLogicId): array {
    $placeholder = array('type' => 'image/png', 'body' => self::tuilePlaceholder());
    $eqLogic = eqLogic::byId($_eqLogicId);
    if (!is_object($eqLogic) || $eqLogic->getEqType_name() !== 'stellantis' || !$eqLogic->getIsEnable()) {
      return $placeholder;
    }
    if (!$eqLogic->hasRight('r')) {
      return $placeholder;
    }
    $cmd = $eqLogic->getCmd('info', 'position');
    $valeur = is_object($cmd) ? trim((string) $cmd->execCmd()) : '';
    if ($valeur === '') {
      return $placeholder;
    }
    $parties = explode(',', $valeur);
    if (count($parties) !== 2 || !is_numeric(trim($parties[0])) || !is_numeric(trim($parties[1]))) {
      return $placeholder;
    }
    $lat = (float) trim($parties[0]);
    $lon = (float) trim($parties[1]);
    if (abs($lat) > 90 || abs($lon) > 180) {
      return $placeholder;
    }
    // Arrondi à 4 décimales (~11 m de précision) AVANT de construire la clé de cache ET l'URL : sans ça,
    // le jitter GPS d'un poll à l'autre changerait la clé à chaque refresh et le cache ne serait jamais
    // réellement exploité (cf. spec technique).
    $lat = round($lat, 4);
    $lon = round($lon, 4);
    $url = self::construireUrlTuile($lat, $lon, self::MAP_ZOOM, self::MAP_SIZE);
    $cheminCache = self::cheminCacheTuile($url);
    $cheminEchec = $cheminCache . '.fail';
    $mtimeCache = @filemtime($cheminCache);
    if ($mtimeCache !== false && (time() - $mtimeCache) < self::MAP_CACHE_TTL) {
      $corps = @file_get_contents($cheminCache);
      if ($corps !== false && $corps !== '') {
        return array('type' => 'image/png', 'body' => $corps);
      }
    }
    $mtimeEchec = @filemtime($cheminEchec);
    if ($mtimeEchec !== false && (time() - $mtimeEchec) < self::MAP_CACHE_TTL_ECHEC) {
      // Négative-cache : ne retente pas le fournisseur à chaque affichage pendant la fenêtre courte.
      return $placeholder;
    }
    $corps = self::telechargerTuile($url);
    if ($corps === null) {
      @file_put_contents($cheminEchec, '1');
      self::nettoyerCacheTuiles();
      return $placeholder;
    }
    @file_put_contents($cheminCache, $corps);
    @unlink($cheminEchec);
    self::nettoyerCacheTuiles();
    return array('type' => 'image/png', 'body' => $corps);
  }

  /* * ******************* UC52 — Image / vignette du modèle du véhicule ******************* */

  /**
   * Assure que l'équipement $_eq a une image cohérente : photo modèle du compte (best-effort, depuis
   * 'pictures') en priorité, sinon icône de la marque cataloguée, sinon repli NATIF (icône du plugin —
   * rien à faire ici). Best-effort, NE LÈVE JAMAIS (try/catch interne — précédent suivreMaintenance()/
   * suivreAlertes()) : un échec d'image ne doit jamais perturber syncVehicles(). Appelée UNIQUEMENT au
   * clic « Synchroniser » (jamais au cron() — pas de fetch d'image périodique, cf. 52-tech.md).
   * Respecte une image posée manuellement par l'utilisateur (upload via la page core standard,
   * 'image::source' ∉ {'model','brand'}) : ne l'écrase jamais.
   */
  private static function assurerImageVehicule(eqLogic $_eq, string $_brand, array $_pictures): void {
    try {
      $source = trim((string) $_eq->getConfiguration('image::source', ''));
      $sha512Actuel = trim((string) $_eq->getConfiguration('image::sha512', ''));
      if ($sha512Actuel !== '' && $source !== 'model' && $source !== 'brand') {
        // Image personnalisée par l'utilisateur (upload manuel core) : ne jamais l'écraser.
        return;
      }
      // 1) Photo modèle (best-effort, priorité — plus spécifique que l'icône de marque générique)
      $poseModele = false;
      $url = self::extraireUrlImageModele($_pictures);
      if ($url !== null) {
        if ($source === 'model' && trim((string) $_eq->getConfiguration('image::model_url', '')) === $url) {
          // Déjà à jour (même URL) : aucun re-download (idempotence + self-heal au changement d'URL).
          return;
        }
        $img = self::telechargerImageModele($url);
        if ($img !== null && self::poserImageEqLogic($_eq, $img['body'], $img['type'])) {
          $_eq->setConfiguration('image::source', 'model');
          $_eq->setConfiguration('image::model_url', $url);
          $_eq->save();
          $poseModele = true;
        }
        // Échec (download ou écriture) : PAS de cache « known-bad » — re-tenté au prochain sync (manuel
        // et rare, cooldown 15 s) ; on continue vers le repli marque ci-dessous.
      }
      if ($poseModele) {
        return;
      }
      // Échec de (re)téléchargement modèle : si une photo modèle avait déjà été posée avec succès, la
      // CONSERVER (échec probablement transitoire — timeout, 5xx, URL signée instable) plutôt que de
      // régresser vers l'icône de marque générique. Le repli marque ne s'applique donc QUE si aucune
      // photo modèle n'a jamais été posée pour ce véhicule.
      if ($source === 'model') {
        return;
      }
      // 2) Repli catalogue marque
      $chemin = self::cheminIconeMarque($_brand);
      if ($chemin === null) {
        // Marque non cataloguée : ne rien poser (repli natif = icône du plugin). Ne touche à rien
        // (ne régresse pas une image 'model'/'brand' déjà posée par un sync précédent).
        return;
      }
      $contenu = @file_get_contents($chemin);
      if ($contenu === false || $contenu === '') {
        log::add('stellantis', 'warning', 'Image véhicule : asset de marque illisible (' . basename($chemin) . ')');
        return;
      }
      $sha = sha512($contenu);
      if ($sha512Actuel === $sha && $source === 'brand') {
        // Déjà l'icône de marque courante : rien à réécrire (idempotence).
        return;
      }
      if (self::poserImageEqLogic($_eq, $contenu, 'png')) {
        $_eq->setConfiguration('image::source', 'brand');
        // Efface une URL modèle périmée (sinon un futur sync sans photo modèle croirait à tort être
        // encore à jour sur une ancienne URL, cf. garde du bloc 1 ci-dessus).
        $_eq->setConfiguration('image::model_url', '');
        $_eq->save();
      }
    } catch (\Throwable $e) {
      log::add('stellantis', 'debug', 'Image véhicule : échec best-effort (' . $e->getMessage() . ')');
    }
  }

  /**
   * Extrait une URL d'image de modèle exploitable depuis le champ 'pictures' brut de /user/vehicles
   * (PUR — seul endroit du parsing de cette shape NON vérifiée au runtime, cf. 52-tech.md : le type
   * Swagger 'Url' est un stub vide, aucune implémentation de référence ne lit ce champ). Parsing
   * défensif : ignore tout élément non exploitable, ne devine pas de « meilleure taille » (shape
   * inconnue). Retient la PREMIÈRE URL https valide trouvée ; retourne null si rien d'exploitable.
   */
  private static function extraireUrlImageModele(array $_pictures): ?string {
    foreach ($_pictures as $element) {
      $candidat = null;
      $cleRetenue = '';
      if (is_string($element)) {
        $candidat = $element;
        $cleRetenue = '(chaîne directe)';
      } elseif (is_array($element)) {
        if (isset($element['href']) && is_string($element['href'])) {
          $candidat = $element['href'];
          $cleRetenue = 'href';
        } elseif (isset($element['url']) && is_string($element['url'])) {
          $candidat = $element['url'];
          $cleRetenue = 'url';
        } elseif (isset($element['_links']['self']['href']) && is_string($element['_links']['self']['href'])) {
          $candidat = $element['_links']['self']['href'];
          $cleRetenue = '_links.self.href';
        }
      }
      if ($candidat === null || trim($candidat) === '') {
        continue;
      }
      $candidat = trim($candidat);
      if (strtolower((string) parse_url($candidat, PHP_URL_SCHEME)) === 'https') {
        log::add('stellantis', 'debug', 'Image modèle : URL retenue depuis pictures, clé ' . $cleRetenue);
        return $candidat;
      }
    }
    return null;
  }

  /**
   * Télécharge la photo modèle EN MÉMOIRE (best-effort, calqué sur telechargerTuile()/UC32). Renvoie
   * ['body'=>string,'type'=>'png'|'jpg'] ou null sur TOUT échec (jamais d'exception — simple
   * enrichissement, le repli catalogue marque est géré par l'appelant assurerImageVehicule()). Durci
   * anti-SSRF / anti-fuite token : HTTPS strict, PAS de redirection suivie, timeout court, taille
   * bornée, User-Agent identifiant, content-type allow-list stricte png/jpeg (jamais SVG — script/
   * markup), JAMAIS de header Authorization/Bearer (l'URL vient de notre API mais est traitée comme non
   * fiable, cf. 52-tech.md § Sécurité).
   */
  private static function telechargerImageModele(string $_url): ?array {
    $scheme = strtolower((string) parse_url($_url, PHP_URL_SCHEME));
    if ($scheme !== 'https') {
      log::add('stellantis', 'info', 'Image modèle : URL refusée (HTTPS obligatoire)');
      return null;
    }
    $protos = defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 0;
    $ch = curl_init();
    $options = array(
      CURLOPT_URL => $_url,
      CURLOPT_HTTPGET => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HEADER => false,
      // Anti-SSRF : jamais de redirection suivie (comme telechargerTuile — l'URL est non fiable).
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT => 15,
      CURLOPT_MAXFILESIZE => self::IMAGE_MODELE_TAILLE_MAX,
      CURLOPT_USERAGENT => self::MAP_USER_AGENT,
      CURLOPT_HTTPHEADER => array('Accept: image/png,image/jpeg'),
      // Jamais de header Authorization/Bearer (cf. downloadToFile/telechargerTuile : tiers non fiable).
    );
    if ($protos !== 0) {
      $options[CURLOPT_PROTOCOLS] = $protos;
    }
    curl_setopt_array($ch, $options);
    $corps = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($corps === false || $curlErrno !== 0) {
      log::add('stellantis', 'info', 'Image modèle : erreur de transport cURL #' . $curlErrno . ' : ' . $curlError);
      return null;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
      log::add('stellantis', 'info', 'Image modèle : refusée par le serveur (HTTP ' . $httpCode . ')');
      return null;
    }
    // Allow-list stricte aux formats raster attendus (type dérivé du content-type, JAMAIS de
    // l'extension d'URL) : jamais 'image/svg+xml' (SVG pouvant embarquer du script/markup) ni page
    // d'erreur HTML relayée comme si c'était l'image attendue.
    $typeNormalise = strtolower($contentType);
    if (strpos($typeNormalise, 'image/png') === 0) {
      $type = 'png';
    } elseif (strpos($typeNormalise, 'image/jpeg') === 0) {
      $type = 'jpg';
    } else {
      log::add('stellantis', 'info', 'Image modèle : type de contenu inattendu (' . $contentType . ')');
      return null;
    }
    // 2e garde (contenu) en défense en profondeur du Content-Type déclaré (1re garde ci-dessus) : un
    // serveur distant compromis pourrait mentir avec 'Content-Type: image/png' tout en renvoyant du
    // HTML/JS. getimagesizefromstring() décode les entêtes réels du fichier (magic bytes) — un échec
    // signifie que ce n'est PAS une image raster valide, jamais persisté sous data/eqLogic/.
    if (getimagesizefromstring((string) $corps) === false) {
      log::add('stellantis', 'info', 'Image modèle : contenu non reconnu comme image valide');
      return null;
    }
    return array('body' => (string) $corps, 'type' => $type);
  }

  /**
   * Résout le chemin absolu de l'asset marque bundlé (plugin_info/brands/) pour $_brand, ou null si la
   * marque n'est pas cataloguée ou si le fichier est absent (repli natif icône du plugin dans ce cas,
   * géré par l'appelant assurerImageVehicule()). PUR/STATIQUE.
   * ⚠️ Normalisation : minuscule MULTIBYTE (mb_strtolower — un simple strtolower() ne réduit pas un
   * caractère accentué majuscule comme « Ë », octets non-ASCII laissés tels quels) PUIS suppression des
   * accents, dans cet ordre, AVANT le sluggage — « Citroën »/« CITROËN »/« citroën » doivent tous
   * résoudre 'citroen', jamais un slug tronqué par un tréma qui aurait survécu en majuscule (cf.
   * 52-tech.md). iconv TRANSLIT en primaire ; repli str_replace des accents français courants (déjà en
   * minuscule à ce stade) si iconv est indisponible/échoue (extension non garantie partout).
   */
  private static function cheminIconeMarque(string $_brand): ?string {
    $normalise = mb_strtolower(trim($_brand), 'UTF-8');
    $translitere = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalise);
    if (is_string($translitere) && trim($translitere) !== '') {
      $normalise = $translitere;
    } else {
      $accents = array(
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
      );
      $normalise = strtr($normalise, $accents);
    }
    $slug = (string) preg_replace('/[^a-z0-9]/', '', strtolower($normalise));
    if (!isset(self::ICONES_MARQUES[$slug])) {
      return null;
    }
    // __DIR__ = core/class → 2 niveaux plus haut = racine du plugin.
    $chemin = dirname(__DIR__, 2) . '/plugin_info/brands/' . self::ICONES_MARQUES[$slug];
    return file_exists($chemin) ? $chemin : null;
  }

  /**
   * Pose (ou remplace) l'image de l'équipement $_eq avec le binaire $_binaire (type 'png'|'jpg') : IL
   * N'EXISTE AUCUNE méthode setImage() côté core (cf. 52-tech.md) — mécanisme réel du core :
   * data/eqLogic/eqLogic{ID}-{sha512}.{type} + configuration 'image::sha512'/'image::type' (lues par
   * eqLogic::getImage()/getCustomImage() ; repli natif = icône du plugin si 'image::sha512' est vide).
   * Ordre ÉCRIRE-PUIS-PURGER (jamais de fenêtre sans image) : n'écrit QUE le fichier + pose la config ;
   * le save() est laissé à L'APPELANT (mutualisé avec les autres clés 'image::source'/'image::model_url'
   * — un seul save(), comme le reste de syncVehicles()). IO défensive, NE LÈVE JAMAIS : renvoie false
   * sur tout échec (dossier inaccessible, écriture KO) — la synchro continue (log warning).
   */
  private static function poserImageEqLogic(eqLogic $_eq, string $_binaire, string $_type): bool {
    $dir = self::dossierImageEqLogic();
    if ($dir === '' || !is_dir($dir)) {
      log::add('stellantis', 'warning', 'Image véhicule : dossier data/eqLogic inaccessible');
      return false;
    }
    $sha = sha512($_binaire);
    $fichier = 'eqLogic' . $_eq->getId() . '-' . $sha . '.' . $_type;
    $ok = @file_put_contents($dir . '/' . $fichier, $_binaire);
    if ($ok === false) {
      log::add('stellantis', 'warning', 'Image véhicule : échec d\'écriture du fichier image');
      return false;
    }
    $_eq->setConfiguration('image::sha512', $sha);
    $_eq->setConfiguration('image::type', $_type);
    // Purge des anciens fichiers de CET équipement, APRÈS l'écriture réussie (jamais de fenêtre sans
    // image). Glob ANCRÉ sur le séparateur '-' : 'eqLogic12-*' ne doit jamais matcher 'eqLogic123-…'.
    $anciens = @glob($dir . '/eqLogic' . $_eq->getId() . '-*');
    if (is_array($anciens)) {
      foreach ($anciens as $ancien) {
        if (basename($ancien) !== $fichier) {
          @unlink($ancien);
        }
      }
    }
    return true;
  }

  // Dossier data/eqLogic/ de la racine Jeedom (PAS un dossier du plugin : mécanisme standard du core
  // pour les images custom d'équipement, cf. 52-tech.md). __DIR__ = core/class → 4 niveaux plus haut =
  // racine Jeedom. Créé paresseusement si absent (best-effort) ; l'appelant gère l'échec via le retour
  // de file_put_contents (pas d'exception ici).
  private static function dossierImageEqLogic(): string {
    $dir = dirname(__DIR__, 4) . '/data/eqLogic';
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }
    return $dir;
  }

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
  // UC52 : purge best-effort des fichiers image posés par assurerImageVehicule()/poserImageEqLogic()
  // (sinon fuite disque : les fichiers data/eqLogic/eqLogic{ID}-* d'un équipement supprimé ne sont
  // sinon JAMAIS nettoyés). getId() reste résolvable à ce stade du cycle de vie. Glob ANCRÉ sur le '-'
  // séparateur (même garde qu'en poserImageEqLogic — 'eqLogic12-*' ne matche pas 'eqLogic123-…'). Ne
  // lève jamais (hook de cycle de vie core).
  public function preRemove() {
    $dir = self::dossierImageEqLogic();
    $fichiers = @glob($dir . '/eqLogic' . $this->getId() . '-*');
    if (is_array($fichiers)) {
      foreach ($fichiers as $fichier) {
        @unlink($fichier);
      }
    }
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

  // Exécution d'une commande action. Le core enveloppe l'appel dans un try/catch et remonte le message
  // d'exception à l'UI (toast du bouton) → on laisse remonter les stellantisException (cooldown, OTP…).
  public function execute($_options = array()) {
    $eqLogic = $this->getEqLogic();
    if (!is_object($eqLogic)) {
      return;
    }
    switch ($this->getLogicalId()) {
      case 'wakeup':
        // UC13 : force une remontée d'état fraîche via MQTT (garde-fous cooldown/quota dans wakeup()).
        $eqLogic->wakeup();
        break;
      case 'charge_start':
        // UC14 : démarre la charge (immediate). Garde-fous debounce/quota + pré-requis OTP dans chargeControl().
        $eqLogic->chargeControl(true);
        break;
      case 'charge_stop':
        // UC14 : arrête la charge (delayed). Rafraîchit d'abord l'état réel pour ne pas reprogrammer la charge différée.
        $eqLogic->chargeControl(false);
        break;
      case 'charge_set_time':
        // UC22 : programme l'heure de charge différée (HHMM). Valeur saisie via le widget action 'message'
        // (title en repli défensif ; is_string garde contre un $_options non-scalaire d'un appel scénario/API).
        $heure = (isset($_options['message']) && is_string($_options['message']) && $_options['message'] !== '')
          ? $_options['message']
          : ((isset($_options['title']) && is_string($_options['title'])) ? $_options['title'] : '');
        $eqLogic->chargeSetTime($heure);
        break;
      case 'precond_on':
        // UC15 : active le préconditionnement climatique (immédiat). Garde-fous debounce/quota + pré-requis OTP dans precondControl().
        $eqLogic->precondControl(true);
        break;
      case 'precond_off':
        // UC15 : désactive le préconditionnement climatique (immédiat).
        $eqLogic->precondControl(false);
        break;
      case 'lock':
        // UC16 : verrouille les portes. Garde-fous debounce/quota + pré-requis OTP dans doorControl().
        $eqLogic->doorControl(true);
        break;
      case 'unlock':
        // UC16 : déverrouille les portes (confirmation core actionConfirm=1 avant d'arriver ici).
        $eqLogic->doorControl(false);
        break;
      case 'horn':
        // UC17 : klaxonne pour retrouver le véhicule. Garde-fous debounce/quota + pré-requis OTP dans horn().
        $eqLogic->horn();
        break;
      case 'lights':
        // UC17 : allume les feux pour retrouver le véhicule. Garde-fous debounce/quota + pré-requis OTP dans lights().
        $eqLogic->lights();
        break;
      default:
        log::add('stellantis', 'warning', 'Commande action inconnue : ' . $this->getLogicalId());
        break;
    }
  }

  /*     * **********************Getteur Setteur*************************** */
}

/**
 * Exception typée du plugin pour toute erreur d'appel à l'API Stellantis/PSA.
 * Types possibles : not_configured | token_expired | auth_required | rate_limited | no_vehicle |
 * privacy | invalid_input | api_error | transport.
 * ⚠️ « privacy » n'est jamais produit par typeFromResponse() : réservé au code métier (UC07), qui le
 * construira lui-même face à une réponse 2xx vide de statut (mode privé activé sur le véhicule).
 * ⚠️ « invalid_input » (UC22) n'est jamais produit par typeFromResponse() non plus : réservé à la
 * validation d'une SAISIE UTILISATEUR côté code métier (ex. parserHeureSaisie), jamais à une réponse API.
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

  // UC12 — Remote token OTP (canal des commandes MQTT), DISTINCT du token OAuth2 REST ci-dessus.
  // Base « mobile » (≠ connectedcar/v4) : smsCode + token (grants password/refresh_token).
  const REMOTE_API_BASE = 'https://api.groupe-psa.com/applications/cvs/v4/mobile';
  const REMOTE_TOKEN_CACHE_KEY = 'stellantis::remote_token';
  const REMOTE_TOKEN_TTL = 890; // TTL constaté du remote token (code de référence : MQTT_TOKEN_TTL)
  const REMOTE_TOKEN_MARGE = 120; // refresh proactif quand il reste moins que cette marge

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

  /* * ******************* UC12 — Remote token OTP (canal des commandes MQTT) ******************* */

  // Headers communs des appels « mobile » (remote) : Bearer OAuth2 courant + realm + UA de l'app.
  // getToken() peut lever auth_required (pas d'OAuth → pas d'OTP possible) : on laisse remonter.
  private static function remoteHeaders(bool $_withJson): array {
    $config = stellantis::getApiConfig();
    $headers = array(
      'Accept: application/hal+json',
      'x-introspect-realm: ' . $config['realm'],
      'User-Agent: okhttp/4.8.0',
      'Authorization: Bearer ' . self::getToken(),
    );
    if ($_withJson) {
      $headers[] = 'Content-Type: application/json';
    }
    return $headers;
  }

  // URL « mobile » avec client_id en query (comme le code de référence RemoteClient).
  private static function remoteUrl(string $_endpoint): string {
    $config = stellantis::getApiConfig();
    return self::REMOTE_API_BASE . $_endpoint . '?' . http_build_query(array('client_id' => $config['clientId']));
  }

  /**
   * Déclenche l'envoi du SMS d'activation OTP au numéro du compte. Corps vide (comme la référence).
   * ⚠️ Consomme une unité du quota « 20 SMS / compte à vie » côté serveur : l'appelant (stellantis)
   * garde ce quota AVANT d'appeler. @throws stellantisException
   */
  public static function requestSmsOtp(): void {
    self::httpRequest('POST', self::remoteUrl('/smsCode'), self::remoteHeaders(false), '');
    log::add('stellantis', 'info', 'SMS d\'activation OTP demandé');
  }

  /**
   * Échange un code OTP (grant password) contre le remote token + remote refresh token (stockés
   * chiffrés, séparés du token OAuth2). @throws stellantisException
   */
  public static function requestRemoteToken(string $_otpCode): void {
    $reponse = self::httpRequest('POST', self::remoteUrl('/token'), self::remoteHeaders(true),
      json_encode(array('grant_type' => 'password', 'password' => $_otpCode)), true);
    self::storeRemoteTokenResponse($reponse, null);
    log::add('stellantis', 'info', 'Remote token OTP obtenu');
  }

  /**
   * Rafraîchit le remote token via le remote refresh token. N'auto-régénère JAMAIS de code OTP (respect
   * du quota) : tout échec → otp_required (ré-activation manuelle nécessaire) + purge du remote token.
   * @throws stellantisException type 'otp_required' si le refresh échoue.
   */
  public static function refreshRemoteToken(): void {
    $token = self::readRemoteTokenCache();
    if ($token === null || !isset($token['refresh_token']) || $token['refresh_token'] == '') {
      throw new stellantisException('Aucun remote refresh token : ré-activation OTP requise', 0, 'otp_required');
    }
    // Headers construits AVANT le try : getToken() peut lever auth_required si le Bearer OAuth2 est mort
    // — c'est un problème OAuth (pas OTP), on le laisse remonter tel quel. Une fois les headers obtenus,
    // TOUT échec de l'appel /token = remote refresh_token invalide/expiré → otp_required (le refresh
    // distant renvoie « invalid_grant », que typeFromResponse classerait à tort en auth_required).
    $headers = self::remoteHeaders(true);
    try {
      $reponse = self::httpRequest('POST', self::remoteUrl('/token'), $headers,
        json_encode(array('grant_type' => 'refresh_token', 'refresh_token' => $token['refresh_token'])), true);
    } catch (stellantisException $e) {
      cache::delete(self::REMOTE_TOKEN_CACHE_KEY);
      log::add('stellantis', 'warning', 'Échec du refresh du remote token (' . $e->getMessage() . ') : ré-activation OTP requise');
      throw new stellantisException('Remote token expiré ou révoqué : ré-activation OTP requise', $e->getHttpCode(), 'otp_required');
    }
    self::storeRemoteTokenResponse($reponse, $token['refresh_token']);
    log::add('stellantis', 'info', 'Remote token rafraîchi');
  }

  /**
   * Rend un remote token valide (refresh proactif si proche de l'expiration). @throws stellantisException
   * ('otp_required' si absent/non rafraîchissable).
   */
  public static function getRemoteToken(): string {
    $token = self::readRemoteTokenCache();
    if ($token === null) {
      throw new stellantisException('Pilotage à distance non activé : réalisez l\'activation OTP', 0, 'otp_required');
    }
    if (time() < $token['exp'] - self::REMOTE_TOKEN_MARGE) {
      return $token['access_token'];
    }
    self::refreshRemoteToken();
    $nouveau = self::readRemoteTokenCache();
    if ($nouveau === null) {
      throw new stellantisException('Remote token indisponible après rafraîchissement', 0, 'otp_required');
    }
    return $nouveau['access_token'];
  }

  public static function hasRemoteToken(): bool {
    return self::readRemoteTokenCache() !== null;
  }

  // Statut non sensible pour l'UI/santé (aucun token exposé).
  public static function getRemoteTokenInfo(): array {
    $token = self::readRemoteTokenCache();
    if ($token === null) {
      return array('active' => false, 'expiresIn' => null);
    }
    return array('active' => true, 'expiresIn' => max(0, $token['exp'] - time()));
  }

  public static function purgeRemoteTokenCache(): void {
    cache::delete(self::REMOTE_TOKEN_CACHE_KEY);
  }

  // Persiste la réponse remote token (chiffrée) ; conserve l'ancien refresh si non renvoyé. TTL par
  // défaut = REMOTE_TOKEN_TTL (la réponse ne fournit pas toujours expires_in). Plancher 120 s.
  private static function storeRemoteTokenResponse(array $_reponse, ?string $_ancienRefresh): void {
    if (!isset($_reponse['access_token']) || $_reponse['access_token'] == '') {
      throw new stellantisException('Réponse remote token invalide (access_token absent)');
    }
    $refresh = (isset($_reponse['refresh_token']) && $_reponse['refresh_token'] != '') ? $_reponse['refresh_token'] : (string) $_ancienRefresh;
    $expiresIn = (int) (isset($_reponse['expires_in']) ? $_reponse['expires_in'] : self::REMOTE_TOKEN_TTL);
    $exp = time() + max(120, $expiresIn);
    cache::set(self::REMOTE_TOKEN_CACHE_KEY, utils::encrypt(json_encode(array(
      'access_token' => $_reponse['access_token'],
      'refresh_token' => $refresh,
      'exp' => $exp,
    ))), 0);
  }

  private static function readRemoteTokenCache(): ?array {
    $brut = (string) cache::byKey(self::REMOTE_TOKEN_CACHE_KEY)->getValue('');
    if ($brut == '') {
      return null;
    }
    $token = json_decode(utils::decrypt($brut), true);
    return (is_array($token) && isset($token['access_token']) && isset($token['exp'])) ? $token : null;
  }
}
