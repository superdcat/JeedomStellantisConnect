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

/* Permet la réorganisation des commandes dans l'équipement */
$("#table_cmd").sortable({
  axis: "y",
  cursor: "move",
  items: ".cmd",
  placeholder: "ui-state-highlight",
  tolerance: "intersect",
  forcePlaceholderSize: true
})

/* Test de connexion à l'API Stellantis (1 vrai appel API : bouton désactivé pendant la requête) */
$('#stellantis_btTestConnexion').off('click').on('click', function () {
  var bouton = $(this)
  if (bouton.hasClass('disabled')) {
    return
  }
  $.ajax({
    type: 'POST',
    url: 'plugins/stellantis/core/ajax/stellantis.ajax.php',
    data: { action: 'testConnection' },
    dataType: 'json',
    beforeSend: function () {
      bouton.addClass('disabled')
    },
    complete: function () {
      bouton.removeClass('disabled')
    },
    error: function (request, status, error) {
      handleAjaxError(request, status, error)
    },
    success: function (data) {
      // data.state != 'ok' → data.result est une chaîne (ajax::error), pas un objet
      if (data.state != 'ok') {
        $('#div_alert').showAlert({ message: data.result, level: 'danger' })
        return
      }
      $('#div_alert').showAlert({ message: data.result.message, level: data.result.ok ? 'success' : 'warning' })
    }
  })
})

/* Synchronisation des véhicules du compte → équipements (1 vrai appel API : bouton désactivé pendant la requête).
   Délégation sur body : la page de config plugin peut être rechargée en AJAX (évite les handlers dupliqués). */
$('body').off('click', '#stellantis_btSync').on('click', '#stellantis_btSync', function () {
  var bouton = $(this)
  if (bouton.hasClass('disabled')) {
    return
  }
  $.ajax({
    type: 'POST',
    url: 'plugins/stellantis/core/ajax/stellantis.ajax.php',
    data: { action: 'sync' },
    dataType: 'json',
    beforeSend: function () {
      bouton.addClass('disabled')
    },
    complete: function () {
      bouton.removeClass('disabled')
    },
    error: function (request, status, error) {
      handleAjaxError(request, status, error)
    },
    success: function (data) {
      // data.state != 'ok' → data.result est une chaîne (ajax::error), pas un objet
      if (data.state != 'ok') {
        $('#div_alert').showAlert({ message: data.result, level: 'danger' })
        return
      }
      $('#div_alert').showAlert({ message: data.result.message, level: data.result.ok ? 'success' : 'warning' })
      // Rafraîchit la liste des cartes véhicules après une synchro réussie.
      // Dette technique MVP assumée : reload complet (perte scroll/tri) — à remplacer par un
      // re-render AJAX quand un endpoint de listing existera (post-MVP). Léger délai pour laisser
      // l'utilisateur voir l'alerte de succès avant le rechargement.
      if (data.result.ok) {
        setTimeout(function () { window.location.reload() }, 1200)
      }
    }
  })
})

/* Fonction permettant l'affichage des commandes dans l'équipement */
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }
  var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">'
  tr += '<td class="hidden-xs">'
  tr += '<span class="cmdAttr" data-l1key="id"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom de la commande}}">'
  tr += '<span class="input-group-btn"><a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a></span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '<select class="cmdAttr form-control input-sm" data-l1key="value" style="display:none;margin-top:5px;" title="{{Commande info liée}}">'
  tr += '<option value="">{{Aucune}}</option>'
  tr += '</select>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label> '
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked/>{{Historiser}}</label> '
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="display" data-l2key="invertBinary"/>{{Inverser}}</label> '
  tr += '<div style="margin-top:7px;">'
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="minValue" placeholder="{{Min}}" title="{{Min}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">'
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="maxValue" placeholder="{{Max}}" title="{{Max}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">'
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="unite" placeholder="{{Unité}}" title="{{Unité}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>';
  tr += '<span class="cmdAttr" data-l1key="htmlstate"></span>';
  tr += '</td>';
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>'
  }
  tr += '<i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove" title="{{Supprimer la commande}}"></i></td>'
  tr += '</tr>'
  $('#table_cmd tbody').append(tr)
  var tr = $('#table_cmd tbody tr').last()
  jeedom.eqLogic.buildSelectCmd({
    id: $('.eqLogicAttr[data-l1key=id]').value(),
    filter: { type: 'info' },
    error: function (error) {
      $('#div_alert').showAlert({ message: error.message, level: 'danger' })
    },
    success: function (result) {
      tr.find('.cmdAttr[data-l1key=value]').append(result)
      tr.setValues(_cmd, '.cmdAttr')
      jeedom.cmd.changeType(tr, init(_cmd.subType))
    }
  })
}
