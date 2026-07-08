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
?>
<form class="form-horizontal">
  <fieldset>
    <legend><i class="fas fa-key"></i> {{Connexion API Stellantis / PSA}}</legend>
    <?php if (!$stellantisConfigure) { ?>
    <div class="alert alert-warning">{{Plugin non configuré : renseignez au minimum le Client ID et le Client Secret.}}</div>
    <?php } ?>
    <div class="alert alert-info">{{Les identifiants Client ID et Client Secret ne sont pas fournis par Stellantis aux particuliers : ils doivent être extraits de l'application mobile de votre marque (APK) à l'aide d'un outil externe. Consultez la documentation du plugin pour la procédure.}}</div>
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
        <sup><i class="fas fa-question-circle tooltips" title="{{Identifiant client OAuth2 de l'application mobile de la marque (extrait via un outil externe)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="client_id"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Client Secret}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Secret client OAuth2 (stocké chiffré, jamais affiché en clair)}}"></i></sup>
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
</script>
