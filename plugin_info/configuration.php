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
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) {
  include_file('desktop', '404', 'php');
  die();
}
?>
<?php
// stellantis:: appelé en premier → charge stellantis.class.php avant tout stellantisApi:: (autoload)
$stellantisConfigure = stellantis::isConfigured();
$infoToken = $stellantisConfigure ? stellantisApi::getTokenInfo() : array('authenticated' => false, 'expiresIn' => null);
$otpState = $stellantisConfigure ? stellantis::otpState() : 'inactive';
$otpSmsCount = stellantis::otpSmsCount();
?>
<form class="form-horizontal">
  <fieldset>
    <legend><i class="fas fa-key"></i> {{Compte principal (pilotage à distance)}}</legend>
    <?php if (!$stellantisConfigure) { ?>
    <div class="alert alert-warning">{{Plugin non configuré : renseignez au minimum le Client ID et le Client Secret.}}</div>
    <?php } ?>
    <div class="alert alert-info">{{Les identifiants Client ID et Client Secret ne sont pas fournis par Stellantis aux particuliers. Utilisez le bouton « Extraire automatiquement » ci-dessous pour les récupérer directement depuis l'application mobile de votre marque (APK). En cas d'échec, ou sur un Raspberry Pi à carte SD, une extraction manuelle avec un outil externe reste possible : consultez la documentation du plugin pour la procédure.}}</div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Marque}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Détermine le serveur d'authentification (idpcvs) et le realm utilisés}}"></i></sup>
      </label>
      <div class="col-md-4">
        <select class="configKey form-control" data-l1key="brand">
          <option value="peugeot">Peugeot</option>
          <option value="citroen">Citroën</option>
          <option value="ds">DS</option>
          <option value="opel">Opel</option>
          <option value="vauxhall">Vauxhall</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Client ID}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Identifiant client OAuth2 de l'application mobile de la marque (rempli automatiquement par le bouton « Extraire automatiquement » ci-dessous, ou manuellement via un outil externe en repli)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="client_id"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Client Secret}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Secret client OAuth2 (obtenu de la même façon que le Client ID ; stocké chiffré, jamais affiché en clair)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input type="password" autocomplete="new-password" class="configKey form-control" data-l1key="client_secret"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Extraction automatique}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Télécharge l'application mobile de la marque sélectionnée (~100 Mo) et en extrait automatiquement le Client ID et le Client Secret, sans installer d'outil externe. Sélectionnez d'abord la marque et le pays.}}"></i></sup>
      </label>
      <div class="col-md-6">
        <a class="btn btn-default" id="stellantis_btExtraireApk"><i class="fas fa-download"></i> {{Extraire automatiquement}}</a>
        <div class="help-block">{{Renseigne le Client ID et le Client Secret à partir de l'application mobile de votre marque. En cas d'échec, la saisie manuelle reste possible (voir la documentation du plugin).}}</div>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Pays}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Code pays à 2 lettres (ex. fr) utilisé pour construire l'URL de redirection par défaut}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="country" placeholder="fr"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{URL de redirection}}
        <sup><i class="fas fa-question-circle tooltips" title="{{redirect_uri OAuth2 (schéma de l'app mobile de la marque, ex. mymap://oauth2redirect/fr)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="redirect_uri" placeholder="{{Laisser vide pour utiliser le défaut de la marque}}"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{URL de l'application mobile (avancé)}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Facultatif : URL complète de l'archive .apk.bz2 utilisée par l'extraction automatique. À ne renseigner que si le dépôt communautaire par défaut est indisponible ou a été déplacé.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="apk_url" placeholder="{{Laisser vide pour utiliser le dépôt par défaut}}"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Hôte du broker MQTT (avancé)}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Facultatif : serveur MQTT utilisé par le démon de pilotage à distance. À ne renseigner que si le serveur par défaut ne fonctionne pas pour votre marque.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="broker_host" placeholder="mwa.mpsa.com"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Port du démon (avancé)}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Facultatif : port du socket local entre Jeedom et le démon. À modifier uniquement en cas de conflit de port avec un autre plugin.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="socketport" placeholder="55009"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{URL du service de tuiles carte (optionnel)}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Laisser vide pour utiliser le service de cartographie par défaut (OpenStreetMap)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="map_tile_url" placeholder="{{Laisser vide pour utiliser le service par défaut}}"/>
      </div>
    </div>
  </fieldset>
  <fieldset>
    <legend><i class="fas fa-map-marker-alt"></i> {{Zone domicile (geofencing)}}</legend>
    <div class="alert alert-info">{{Coordonnées de votre domicile : chaque véhicule exposera une info « Au domicile » (1/0) utilisable dans vos scénarios. Laissez vide pour désactiver.}}</div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Latitude du domicile}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Latitude en degrés décimaux (ex. 48.8566). Laissez vide pour désactiver le geofencing.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input type="number" step="any" class="configKey form-control" data-l1key="home_lat"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Longitude du domicile}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Longitude en degrés décimaux (ex. 2.3522). Laissez vide pour désactiver le geofencing.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input type="number" step="any" class="configKey form-control" data-l1key="home_lon"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Rayon (m)}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Rayon en mètres autour du domicile pour considérer le véhicule « au domicile ». Laissez vide pour utiliser le défaut (150 m).}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input type="number" step="1" class="configKey form-control" data-l1key="home_radius" placeholder="150"/>
      </div>
    </div>
  </fieldset>
  <fieldset>
    <legend><i class="fas fa-link"></i> {{Connexion au compte}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{État de la connexion}}</label>
      <div class="col-md-6">
        <?php if ($infoToken['authenticated']) { ?>
        <span class="label label-success">{{Connecté au compte}}</span>
        <?php } else { ?>
        <span class="label label-warning">{{Non connecté : suivez les deux étapes ci-dessous après avoir sauvegardé la configuration}}</span>
        <?php } ?>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{1. URL d'autorisation}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Ouvrez cette URL dans votre navigateur et connectez-vous avec le compte de l'application mobile de votre marque}}"></i></sup>
      </label>
      <div class="col-md-6">
        <a class="btn btn-default" id="stellantis_btGenererAuthUrl"><i class="fas fa-key"></i> {{Générer l'URL d'autorisation}}</a>
        <div id="stellantis_zoneAuthUrl" style="display:none;margin-top:5px;">
          <a id="stellantis_lienAuthUrl" href="#" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> {{Ouvrir la page de connexion de votre marque}}</a>
          <div class="help-block">{{URL de redirection attendue}} : <code id="stellantis_redirectUriInfo"></code></div>
        </div>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{2. Code d'autorisation}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Après connexion, le navigateur affiche une page d'erreur (c'est normal) : copiez l'URL complète (commençant par mymap://... et contenant code=). Si rien n'apparaît, récupérez le paramètre code (36 caractères) dans l'onglet Réseau (F12). Collez-le sans attendre : le code expire vite.}}"></i></sup>
      </label>
      <div class="col-md-6">
        <input class="form-control" id="stellantis_codeAuth" placeholder="{{Collez l'URL de redirection complète (recommandé) ou le code seul}}"/>
        <a class="btn btn-success" id="stellantis_btValiderCode" style="margin-top:5px;"><i class="fas fa-check"></i> {{Valider le code}}</a>
      </div>
    </div>
  </fieldset>
  <?php
  // UC54 — Comptes secondaires (2..MAX_ACCOUNTS), LECTURE SEULE (pas de pilotage à distance : OTP/commandes
  // restent slot 1 uniquement). Rendus SEULEMENT si le compte principal est déjà configuré (évite le cas
  // « slot 1 vide, seul un compte secondaire configuré » — cf. 54-tech.md § UI). Bornes dérivées de la
  // constante stellantis::MAX_ACCOUNTS (jamais de « 2, 3 » en dur : suit la constante si elle évolue).
  // Boucle plutôt qu'un helper PHP dédié : évite toute déclaration de fonction nommée dans ce fichier
  // (inclus tel quel, jamais de risque de redéclaration).
  if ($stellantisConfigure) {
    foreach (range(2, stellantis::MAX_ACCOUNTS) as $slotN) {
      $infoTokenSlot = stellantis::isConfigured($slotN) ? stellantisApi::getTokenInfo($slotN) : array('authenticated' => false, 'expiresIn' => null);
      ?>
  <fieldset>
    <legend>
      <a data-toggle="collapse" href="#stellantis_compteSecondaire<?php echo $slotN; ?>" aria-expanded="false">
        <i class="fas fa-key"></i> <?php echo sprintf(__('Compte secondaire %s (lecture seule)', __FILE__), $slotN); ?> <i class="fas fa-chevron-down"></i>
      </a>
    </legend>
    <div class="collapse" id="stellantis_compteSecondaire<?php echo $slotN; ?>">
      <div class="alert alert-info">{{Ce compte est en lecture seule : le pilotage à distance (commandes) n'est disponible que sur le compte principal}}</div>
      <div class="form-group">
        <label class="col-md-4 control-label">{{Marque}}
          <sup><i class="fas fa-question-circle tooltips" title="{{Détermine le serveur d'authentification (idpcvs) et le realm utilisés}}"></i></sup>
        </label>
        <div class="col-md-4">
          <select class="configKey form-control" data-l1key="brand_<?php echo $slotN; ?>">
            <option value="peugeot">Peugeot</option>
            <option value="citroen">Citroën</option>
            <option value="ds">DS</option>
            <option value="opel">Opel</option>
            <option value="vauxhall">Vauxhall</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="col-md-4 control-label">{{Client ID}}
          <sup><i class="fas fa-question-circle tooltips" title="{{Identifiant client OAuth2 de l'application mobile de la marque (rempli automatiquement par le bouton « Extraire automatiquement » ci-dessous, ou manuellement via un outil externe en repli)}}"></i></sup>
        </label>
        <div class="col-md-4">
          <input class="configKey form-control" data-l1key="client_id_<?php echo $slotN; ?>"/>
        </div>
      </div>
      <div class="form-group">
        <label class="col-md-4 control-label">{{Client Secret}}
          <sup><i class="fas fa-question-circle tooltips" title="{{Secret client OAuth2 (obtenu de la même façon que le Client ID ; stocké chiffré, jamais affiché en clair)}}"></i></sup>
        </label>
        <div class="col-md-4">
          <input type="password" autocomplete="new-password" class="configKey form-control" data-l1key="client_secret_<?php echo $slotN; ?>"/>
        </div>
      </div>
      <div class="form-group">
        <label class="col-md-4 control-label">{{Extraction automatique}}</label>
        <div class="col-md-6">
          <a class="btn btn-default stellantis_btExtraireApkN" data-slot="<?php echo $slotN; ?>"><i class="fas fa-download"></i> {{Extraire automatiquement}}</a>
          <div class="help-block">{{Renseigne le Client ID et le Client Secret à partir de l'application mobile de votre marque. En cas d'échec, la saisie manuelle reste possible (voir la documentation du plugin).}}</div>
        </div>
      </div>
      <div class="form-group">
        <label class="col-md-4 control-label">{{Pays}}
          <sup><i class="fas fa-question-circle tooltips" title="{{Code pays à 2 lettres (ex. fr) utilisé pour construire l'URL de redirection par défaut}}"></i></sup>
        </label>
        <div class="col-md-4">
          <input class="configKey form-control" data-l1key="country_<?php echo $slotN; ?>" placeholder="fr"/>
        </div>
      </div>
      <div class="form-group">
        <label class="col-md-4 control-label">{{URL de redirection}}
          <sup><i class="fas fa-question-circle tooltips" title="{{redirect_uri OAuth2 (schéma de l'app mobile de la marque, ex. mymap://oauth2redirect/fr)}}"></i></sup>
        </label>
        <div class="col-md-4">
          <input class="configKey form-control" data-l1key="redirect_uri_<?php echo $slotN; ?>" placeholder="{{Laisser vide pour utiliser le défaut de la marque}}"/>
        </div>
      </div>
      <div class="form-group">
        <label class="col-md-4 control-label">{{État de la connexion}}</label>
        <div class="col-md-6">
          <?php if ($infoTokenSlot['authenticated']) { ?>
          <span class="label label-success">{{Connecté au compte}}</span>
          <?php } else { ?>
          <span class="label label-warning">{{Non connecté : suivez les deux étapes ci-dessous après avoir sauvegardé la configuration}}</span>
          <?php } ?>
        </div>
      </div>
      <div class="form-group">
        <label class="col-md-4 control-label">{{1. URL d'autorisation}}
          <sup><i class="fas fa-question-circle tooltips" title="{{Ouvrez cette URL dans votre navigateur et connectez-vous avec le compte de l'application mobile de votre marque}}"></i></sup>
        </label>
        <div class="col-md-6">
          <a class="btn btn-default stellantis_btGenererAuthUrlN" data-slot="<?php echo $slotN; ?>"><i class="fas fa-key"></i> {{Générer l'URL d'autorisation}}</a>
          <div class="stellantis_zoneAuthUrlN" style="display:none;margin-top:5px;">
            <a class="stellantis_lienAuthUrlN" href="#" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> {{Ouvrir la page de connexion de votre marque}}</a>
            <div class="help-block">{{URL de redirection attendue}} : <code class="stellantis_redirectUriInfoN"></code></div>
          </div>
        </div>
      </div>
      <div class="form-group">
        <label class="col-md-4 control-label">{{2. Code d'autorisation}}
          <sup><i class="fas fa-question-circle tooltips" title="{{Après connexion, le navigateur affiche une page d'erreur (c'est normal) : copiez l'URL complète (commençant par mymap://... et contenant code=). Si rien n'apparaît, récupérez le paramètre code (36 caractères) dans l'onglet Réseau (F12). Collez-le sans attendre : le code expire vite.}}"></i></sup>
        </label>
        <div class="col-md-6">
          <input class="form-control stellantis_codeAuthN" placeholder="{{Collez l'URL de redirection complète (recommandé) ou le code seul}}"/>
          <a class="btn btn-success stellantis_btValiderCodeN" data-slot="<?php echo $slotN; ?>" style="margin-top:5px;"><i class="fas fa-check"></i> {{Valider le code}}</a>
        </div>
      </div>
      <div class="form-group">
        <label class="col-md-4 control-label">{{Tester la connexion}}</label>
        <div class="col-md-6">
          <a class="btn btn-default stellantis_btTestConnexionN" data-slot="<?php echo $slotN; ?>"><i class="fas fa-plug"></i> {{Tester la connexion}}</a>
        </div>
      </div>
    </div>
  </fieldset>
      <?php
    }
  } else {
    ?>
  <div class="alert alert-info">{{Configurez d'abord le compte principal ci-dessus}}</div>
  <?php } ?>
  <fieldset>
    <legend><i class="fas fa-satellite-dish"></i> {{Pilotage à distance (activation OTP)}}</legend>
    <div class="alert alert-warning">{{Le pilotage à distance (réveil, charge, verrouillage…) nécessite une activation unique par SMS + le code PIN de votre application mobile. Attention : les quotas sont stricts et définitifs côté Stellantis (6 codes par 24 h, 20 activations SMS par compte à vie). N'activez que lorsque vous êtes prêt.}}</div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{État du pilotage à distance}}</label>
      <div class="col-md-6">
        <?php if ($otpState == 'active') { ?>
        <span class="label label-success">{{Activé}}</span>
        <?php } elseif ($otpState == 'expired') { ?>
        <span class="label label-warning">{{Expiré — renouvellement nécessaire}}</span>
        <?php } elseif ($otpState == 'pending') { ?>
        <span class="label label-info">{{SMS envoyé — en attente d'activation}}</span>
        <?php } else { ?>
        <span class="label label-default">{{Non activé}}</span>
        <?php } ?>
        <div class="help-block"><?php echo sprintf(__('Activations SMS utilisées : %1$s / %2$s', __FILE__), $otpSmsCount, stellantis::OTP_SMS_MAX); ?></div>
      </div>
    </div>
    <?php if ($otpState == 'expired') { ?>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Renouveler sans SMS}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Réutilise l'appareil OTP déjà enregistré pour obtenir un nouveau jeton distant, sans consommer d'activation SMS. À privilégier tant que possible.}}"></i></sup>
      </label>
      <div class="col-md-6">
        <a class="btn btn-default" id="stellantis_btRenewRemote"><i class="fas fa-sync"></i> {{Renouveler le jeton distant}}</a>
        <div class="help-block">{{Sans nouveau SMS. Si le renouvellement échoue, refaites l'activation complète ci-dessous.}}</div>
      </div>
    </div>
    <?php } ?>
    <?php if ($otpState == 'active') { ?>
    <div class="alert alert-info">{{Le pilotage à distance est déjà activé. Une nouvelle activation par SMS n'est nécessaire qu'après expiration (utilisez alors « Renouveler ») : elle consommerait une activation du quota définitif (20 par compte).}}</div>
    <?php } ?>
    <div class="form-group">
      <label class="col-md-4 control-label">{{1. Recevoir le SMS}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Déclenche l'envoi d'un SMS contenant un code au numéro de téléphone associé à votre compte de marque.}}"></i></sup>
      </label>
      <div class="col-md-6">
        <a class="btn btn-default" id="stellantis_btOtpSms"><i class="fas fa-sms"></i> {{Envoyer le SMS d'activation}}</a>
        <div class="help-block">{{Connectez d'abord le compte (ci-dessus). Un SMS sera envoyé au numéro associé à votre compte.}}</div>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{2. Code reçu par SMS}}</label>
      <div class="col-md-4">
        <input class="form-control" id="stellantis_otpSms" placeholder="{{Code reçu par SMS}}"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{3. Code PIN de l'application}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Le code PIN à 4 chiffres que vous utilisez dans l'application mobile de votre marque.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input type="password" autocomplete="off" class="form-control" id="stellantis_otpPin" placeholder="{{Code PIN à 4 chiffres}}"/>
        <a class="btn btn-success" id="stellantis_btOtpActivate" style="margin-top:5px;"><i class="fas fa-check"></i> {{Activer le pilotage à distance}}</a>
      </div>
    </div>
  </fieldset>
  <fieldset>
    <legend><i class="fas fa-file-export"></i> {{Sauvegarde & restauration de l'authentification}}</legend>
    <div class="alert alert-warning">{{Ce fichier contient vos identifiants et votre appareil OTP (les « clés » de votre compte). Chiffrez-le avec une passphrase forte, conservez-le en lieu sûr, et ne transmettez jamais le fichier et la passphrase par le même canal.}}</div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Passphrase}}</label>
      <div class="col-md-4">
        <input type="password" autocomplete="new-password" class="form-control" id="stellantis_authPassphrase" placeholder="{{Passphrase}}"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Confirmer la passphrase}}</label>
      <div class="col-md-4">
        <input type="password" autocomplete="new-password" class="form-control" id="stellantis_authPassphraseConfirm" placeholder="{{Confirmer la passphrase}}"/>
        <div class="help-block">{{La confirmation ne s'applique qu'à la sauvegarde.}}</div>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label"></label>
      <div class="col-md-6">
        <a class="btn btn-default" id="stellantis_btAuthExport"><i class="fas fa-download"></i> {{Sauvegarder la configuration d'authentification}}</a>
        <div class="help-block">{{La passphrase n'est jamais stockée : sans elle, le fichier est inutilisable.}}</div>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Fichier de sauvegarde}}</label>
      <div class="col-md-6">
        <input type="file" class="form-control" id="stellantis_authFile" accept=".json"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label"></label>
      <div class="col-md-6">
        <label class="checkbox-inline"><input type="checkbox" id="stellantis_authRenew"/> {{Tenter de réactiver le pilotage à distance maintenant (consomme 1 code du quota journalier)}}</label>
        <div class="help-block">{{Renouvelle le jeton distant sans consommer de SMS d'activation, contrairement à une nouvelle activation complète.}}</div>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label"></label>
      <div class="col-md-6">
        <a class="btn btn-warning" id="stellantis_btAuthRestore"><i class="fas fa-upload"></i> {{Restaurer}}</a>
      </div>
    </div>
  </fieldset>
</form>
<script>
  $('body').off('click', '#stellantis_btExtraireApk').on('click', '#stellantis_btExtraireApk', function () {
    var brand = $('.configKey[data-l1key=brand]').value();
    var country = $('.configKey[data-l1key=country]').value();
    var apkUrl = $('.configKey[data-l1key=apk_url]').value();
    if (!brand) {
      $('#div_alert').showAlert({ message: "{{Sélectionnez d'abord la marque de votre véhicule}}", level: 'warning' });
      return;
    }
    bootbox.confirm(
      "{{Le plugin va télécharger (~100 Mo) et analyser l'application mobile de votre marque, hébergée sur un dépôt communautaire tiers, pour en extraire vos identifiants. Cette API n'est pas officielle. Continuer ?}}",
      function (resultat) {
        if (!resultat) {
          return;
        }
        $('#div_alert').showAlert({ message: "{{Téléchargement de l'application mobile en cours (~100 Mo), veuillez patienter…}}", level: 'info' });
        $.ajax({
          type: 'POST',
          url: 'plugins/stellantis/core/ajax/stellantis.ajax.php',
          data: { action: 'extractCredentials', brand: brand, country: country, apk_url: apkUrl },
          dataType: 'json',
          error: function (request, status, error) {
            handleAjaxError(request, status, error);
          },
          success: function (data) {
            if (data.state != 'ok') {
              $('#div_alert').showAlert({ message: data.result, level: 'danger' });
              return;
            }
            if (!data.result.ok) {
              $('#div_alert').showAlert({ message: data.result.message, level: 'danger' });
              return;
            }
            $('.configKey[data-l1key=client_id]').value(data.result.client_id);
            $('.configKey[data-l1key=client_secret]').value(data.result.client_secret);
            $('#div_alert').showAlert({ message: data.result.message, level: 'success' });
          }
        });
      }
    );
  });
  $('body').off('click', '#stellantis_btGenererAuthUrl').on('click', '#stellantis_btGenererAuthUrl', function () {
    $.ajax({
      type: 'POST',
      url: 'plugins/stellantis/core/ajax/stellantis.ajax.php',
      data: { action: 'getAuthUrl' },
      dataType: 'json',
      error: function (request, status, error) {
        handleAjaxError(request, status, error);
      },
      success: function (data) {
        if (data.state != 'ok') {
          $('#div_alert').showAlert({ message: data.result, level: 'danger' });
          return;
        }
        $('#stellantis_lienAuthUrl').attr('href', data.result.url);
        $('#stellantis_redirectUriInfo').text(data.result.redirectUri);
        $('#stellantis_zoneAuthUrl').show();
      }
    });
  });
  $('body').off('click', '#stellantis_btValiderCode').on('click', '#stellantis_btValiderCode', function () {
    var code = $('#stellantis_codeAuth').val().trim();
    if (code == '') {
      $('#div_alert').showAlert({ message: "{{Collez d'abord l'URL de redirection (ou le code) dans le champ}}", level: 'warning' });
      return;
    }
    $.ajax({
      type: 'POST',
      url: 'plugins/stellantis/core/ajax/stellantis.ajax.php',
      data: { action: 'submitAuthCode', code: code },
      dataType: 'json',
      error: function (request, status, error) {
        handleAjaxError(request, status, error);
      },
      success: function (data) {
        if (data.state != 'ok') {
          $('#div_alert').showAlert({ message: data.result, level: 'danger' });
          return;
        }
        $('#stellantis_codeAuth').val('');
        $('#div_alert').showAlert({ message: data.result, level: 'success' });
      }
    });
  });
  // UC12 — Envoi du SMS d'activation OTP (confirmation : le SMS consomme un quota définitif du compte).
  $('body').off('click', '#stellantis_btOtpSms').on('click', '#stellantis_btOtpSms', function () {
    bootbox.confirm(
      "{{Un SMS d'activation va être envoyé au numéro de votre compte. Ce quota est limité (20 par compte à vie) et définitif côté Stellantis. Continuer ?}}",
      function (resultat) {
        if (!resultat) {
          return;
        }
        $.ajax({
          type: 'POST',
          url: 'plugins/stellantis/core/ajax/stellantis.ajax.php',
          data: { action: 'requestOtpSms' },
          dataType: 'json',
          error: function (request, status, error) {
            handleAjaxError(request, status, error);
          },
          success: function (data) {
            if (data.state != 'ok') {
              $('#div_alert').showAlert({ message: data.result, level: 'danger' });
              return;
            }
            $('#div_alert').showAlert({ message: data.result.message, level: data.result.ok ? 'success' : 'danger' });
          }
        });
      }
    );
  });
  // UC12 — Activation (code SMS + code PIN).
  $('body').off('click', '#stellantis_btOtpActivate').on('click', '#stellantis_btOtpActivate', function () {
    var sms = $('#stellantis_otpSms').val().trim();
    var pin = $('#stellantis_otpPin').val().trim();
    if (sms == '' || pin == '') {
      $('#div_alert').showAlert({ message: "{{Renseignez le code reçu par SMS et le code PIN de votre application mobile}}", level: 'warning' });
      return;
    }
    $('#div_alert').showAlert({ message: "{{Activation en cours, veuillez patienter…}}", level: 'info' });
    $.ajax({
      type: 'POST',
      url: 'plugins/stellantis/core/ajax/stellantis.ajax.php',
      data: { action: 'activateOtp', sms: sms, pin: pin },
      dataType: 'json',
      error: function (request, status, error) {
        handleAjaxError(request, status, error);
      },
      success: function (data) {
        if (data.state != 'ok') {
          $('#div_alert').showAlert({ message: data.result, level: 'danger' });
          return;
        }
        if (data.result.ok) {
          $('#stellantis_otpSms').val('');
          $('#stellantis_otpPin').val('');
        }
        $('#div_alert').showAlert({ message: data.result.message, level: data.result.ok ? 'success' : 'danger' });
      }
    });
  });
  // UC12 — Renouvellement sans SMS (réutilise l'appareil OTP enregistré).
  $('body').off('click', '#stellantis_btRenewRemote').on('click', '#stellantis_btRenewRemote', function () {
    $('#div_alert').showAlert({ message: "{{Renouvellement en cours, veuillez patienter…}}", level: 'info' });
    $.ajax({
      type: 'POST',
      url: 'plugins/stellantis/core/ajax/stellantis.ajax.php',
      data: { action: 'renewRemoteToken' },
      dataType: 'json',
      error: function (request, status, error) {
        handleAjaxError(request, status, error);
      },
      success: function (data) {
        if (data.state != 'ok') {
          $('#div_alert').showAlert({ message: data.result, level: 'danger' });
          return;
        }
        $('#div_alert').showAlert({ message: data.result.message, level: data.result.ok ? 'success' : 'danger' });
      }
    });
  });
  // UC54 — Comptes secondaires (2/3), lecture seule. Handlers GÉNÉRIQUES par délégation + data-slot
  // (ids non uniques : 2 fieldsets identiques peuvent coexister dans le DOM, contrairement au compte
  // principal ci-dessus qui garde ses ids fixes). Chaque handler retrouve son fieldset via .closest('.collapse')
  // pour ne mettre à jour QUE la section du compte concerné.
  $('body').off('click', '.stellantis_btExtraireApkN').on('click', '.stellantis_btExtraireApkN', function () {
    var bouton = $(this);
    var slot = bouton.data('slot');
    var conteneur = bouton.closest('.collapse');
    var brand = conteneur.find('.configKey[data-l1key="brand_' + slot + '"]').value();
    var country = conteneur.find('.configKey[data-l1key="country_' + slot + '"]').value();
    if (!brand) {
      $('#div_alert').showAlert({ message: "{{Sélectionnez d'abord la marque de votre véhicule}}", level: 'warning' });
      return;
    }
    bootbox.confirm(
      "{{Le plugin va télécharger (~100 Mo) et analyser l'application mobile de votre marque, hébergée sur un dépôt communautaire tiers, pour en extraire vos identifiants. Cette API n'est pas officielle. Continuer ?}}",
      function (resultat) {
        if (!resultat) {
          return;
        }
        $('#div_alert').showAlert({ message: "{{Téléchargement de l'application mobile en cours (~100 Mo), veuillez patienter…}}", level: 'info' });
        $.ajax({
          type: 'POST',
          url: 'plugins/stellantis/core/ajax/stellantis.ajax.php',
          data: { action: 'extractCredentials', brand: brand, country: country, slot: slot },
          dataType: 'json',
          error: function (request, status, error) {
            handleAjaxError(request, status, error);
          },
          success: function (data) {
            if (data.state != 'ok') {
              $('#div_alert').showAlert({ message: data.result, level: 'danger' });
              return;
            }
            if (!data.result.ok) {
              $('#div_alert').showAlert({ message: data.result.message, level: 'danger' });
              return;
            }
            conteneur.find('.configKey[data-l1key="client_id_' + slot + '"]').value(data.result.client_id);
            conteneur.find('.configKey[data-l1key="client_secret_' + slot + '"]').value(data.result.client_secret);
            $('#div_alert').showAlert({ message: data.result.message, level: 'success' });
          }
        });
      }
    );
  });
  $('body').off('click', '.stellantis_btGenererAuthUrlN').on('click', '.stellantis_btGenererAuthUrlN', function () {
    var bouton = $(this);
    var slot = bouton.data('slot');
    var conteneur = bouton.closest('.collapse');
    $.ajax({
      type: 'POST',
      url: 'plugins/stellantis/core/ajax/stellantis.ajax.php',
      data: { action: 'getAuthUrl', slot: slot },
      dataType: 'json',
      error: function (request, status, error) {
        handleAjaxError(request, status, error);
      },
      success: function (data) {
        if (data.state != 'ok') {
          $('#div_alert').showAlert({ message: data.result, level: 'danger' });
          return;
        }
        conteneur.find('.stellantis_lienAuthUrlN').attr('href', data.result.url);
        conteneur.find('.stellantis_redirectUriInfoN').text(data.result.redirectUri);
        conteneur.find('.stellantis_zoneAuthUrlN').show();
      }
    });
  });
  $('body').off('click', '.stellantis_btValiderCodeN').on('click', '.stellantis_btValiderCodeN', function () {
    var bouton = $(this);
    var slot = bouton.data('slot');
    var conteneur = bouton.closest('.collapse');
    var code = conteneur.find('.stellantis_codeAuthN').val().trim();
    if (code == '') {
      $('#div_alert').showAlert({ message: "{{Collez d'abord l'URL de redirection (ou le code) dans le champ}}", level: 'warning' });
      return;
    }
    $.ajax({
      type: 'POST',
      url: 'plugins/stellantis/core/ajax/stellantis.ajax.php',
      data: { action: 'submitAuthCode', code: code, slot: slot },
      dataType: 'json',
      error: function (request, status, error) {
        handleAjaxError(request, status, error);
      },
      success: function (data) {
        if (data.state != 'ok') {
          $('#div_alert').showAlert({ message: data.result, level: 'danger' });
          return;
        }
        conteneur.find('.stellantis_codeAuthN').val('');
        $('#div_alert').showAlert({ message: data.result, level: 'success' });
      }
    });
  });
  $('body').off('click', '.stellantis_btTestConnexionN').on('click', '.stellantis_btTestConnexionN', function () {
    var bouton = $(this);
    var slot = bouton.data('slot');
    if (bouton.hasClass('disabled')) {
      return;
    }
    $.ajax({
      type: 'POST',
      url: 'plugins/stellantis/core/ajax/stellantis.ajax.php',
      data: { action: 'testConnection', slot: slot },
      dataType: 'json',
      beforeSend: function () {
        bouton.addClass('disabled');
      },
      complete: function () {
        bouton.removeClass('disabled');
      },
      error: function (request, status, error) {
        handleAjaxError(request, status, error);
      },
      success: function (data) {
        if (data.state != 'ok') {
          $('#div_alert').showAlert({ message: data.result, level: 'danger' });
          return;
        }
        $('#div_alert').showAlert({ message: data.result.message, level: data.result.ok ? 'success' : 'warning' });
      }
    });
  });
  // UC62 — Sauvegarde de la configuration d'authentification : téléchargement navigateur (Blob + <a
  // download>), aucun fichier temporaire côté serveur. content = base64 du fichier JSON complet.
  $('body').off('click', '#stellantis_btAuthExport').on('click', '#stellantis_btAuthExport', function () {
    var passphrase = $('#stellantis_authPassphrase').val();
    var confirmPassphrase = $('#stellantis_authPassphraseConfirm').val();
    if (passphrase.length < 12) {
      $('#div_alert').showAlert({ message: "{{Passphrase trop courte (12 caractères minimum)}}", level: 'warning' });
      return;
    }
    if (passphrase !== confirmPassphrase) {
      $('#div_alert').showAlert({ message: "{{Les deux passphrases sont différentes}}", level: 'warning' });
      return;
    }
    $('#div_alert').showAlert({ message: "{{Sauvegarde en cours…}}", level: 'info' });
    $.ajax({
      type: 'POST',
      url: 'plugins/stellantis/core/ajax/stellantis.ajax.php',
      data: { action: 'exportAuth', passphrase: passphrase },
      dataType: 'json',
      error: function (request, status, error) {
        handleAjaxError(request, status, error);
      },
      success: function (data) {
        if (data.state != 'ok') {
          $('#div_alert').showAlert({ message: data.result, level: 'danger' });
          return;
        }
        if (!data.result.ok) {
          $('#div_alert').showAlert({ message: data.result.message, level: 'danger' });
          return;
        }
        var octets = atob(data.result.content);
        var tableauOctets = new Uint8Array(octets.length);
        for (var i = 0; i < octets.length; i++) {
          tableauOctets[i] = octets.charCodeAt(i);
        }
        var blob = new Blob([tableauOctets], { type: 'application/json' });
        var lienTelechargement = document.createElement('a');
        lienTelechargement.href = URL.createObjectURL(blob);
        lienTelechargement.download = data.result.filename;
        document.body.appendChild(lienTelechargement);
        lienTelechargement.click();
        document.body.removeChild(lienTelechargement);
        URL.revokeObjectURL(lienTelechargement.href);
        $('#stellantis_authPassphrase').val('');
        $('#stellantis_authPassphraseConfirm').val('');
        $('#div_alert').showAlert({ message: data.result.message, level: 'success' });
      }
    });
  });
  // UC62 — Restauration : lit le fichier sélectionné (FileReader.readAsText, jamais de disque serveur),
  // l'encode en base64 côté client (UTF-8 safe), confirme l'écrasement, puis POST classique ($.ajax
  // encode correctement +/// = via encodeURIComponent : pas besoin de base64url).
  $('body').off('click', '#stellantis_btAuthRestore').on('click', '#stellantis_btAuthRestore', function () {
    var passphrase = $('#stellantis_authPassphrase').val();
    var fichiers = document.getElementById('stellantis_authFile').files;
    if (passphrase.length < 12) {
      $('#div_alert').showAlert({ message: "{{Passphrase trop courte (12 caractères minimum)}}", level: 'warning' });
      return;
    }
    if (!fichiers || fichiers.length === 0) {
      $('#div_alert').showAlert({ message: "{{Sélectionnez d'abord un fichier de sauvegarde}}", level: 'warning' });
      return;
    }
    var renew = $('#stellantis_authRenew').is(':checked');
    bootbox.confirm(
      "{{La configuration d'authentification actuelle sera remplacée par le contenu du fichier. Continuer ?}}",
      function (resultat) {
        if (!resultat) {
          return;
        }
        var lecteur = new FileReader();
        lecteur.onload = function (evenement) {
          var contenuBase64 = btoa(unescape(encodeURIComponent(evenement.target.result)));
          $('#div_alert').showAlert({ message: "{{Restauration en cours…}}", level: 'info' });
          $.ajax({
            type: 'POST',
            url: 'plugins/stellantis/core/ajax/stellantis.ajax.php',
            data: { action: 'restoreAuth', file: contenuBase64, passphrase: passphrase, renew: renew ? '1' : '0' },
            dataType: 'json',
            error: function (request, status, error) {
              handleAjaxError(request, status, error);
            },
            success: function (data) {
              if (data.state != 'ok') {
                $('#div_alert').showAlert({ message: data.result, level: 'danger' });
                return;
              }
              $('#div_alert').showAlert({ message: data.result.message, level: data.result.ok ? 'success' : 'danger' });
              if (data.result.ok) {
                $('#stellantis_authPassphrase').val('');
                $('#stellantis_authPassphraseConfirm').val('');
                $('#stellantis_authFile').val('');
              }
            }
          });
        };
        lecteur.readAsText(fichiers[0]);
      }
    );
  });
</script>
