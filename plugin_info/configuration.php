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
if (!isConnect()) {
  include_file('desktop', '404', 'php');
  die();
}
?>
<form class="form-horizontal">
  <fieldset>
    <legend><i class="fas fa-key"></i> {{Connexion API Stellantis / PSA}}</legend>
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
        <input type="password" class="configKey form-control" data-l1key="client_secret"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{URL de redirection}}
        <sup><i class="fas fa-question-circle tooltips" title="{{redirect_uri OAuth2 (schéma de l'app mobile de la marque, ex. mymap://oauth2redirect/fr)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="redirect_uri"/>
      </div>
    </div>
  </fieldset>
</form>
