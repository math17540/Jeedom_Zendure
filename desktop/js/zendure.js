/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

function zendureAjax(_data, _success) {
  $.ajax({
    type: 'POST',
    url: 'plugins/zendure/core/ajax/zendure.ajax.php',
    data: _data,
    dataType: 'json',
    error: function(request, status, error) {
      handleAjaxError(request, status, error)
    },
    success: function(data) {
      if (data.state != 'ok') {
        $.fn.showAlert({ message: data.result, level: 'danger' })
        return
      }
      _success(data.result)
    }
  })
}

function zendureShowConnection() {
  var model = $('#sel_zendureModel').value()
  var conn = (zendureModels[model]) ? zendureModels[model].connection : 'mqtt'
  $('.zendureConn').each(function() {
    $(this).toggle($(this).attr('data-connection').split(' ').indexOf(conn) >= 0)
  })
}

$('#sel_zendureModel').on('change', zendureShowConnection)

function printEqLogic(_eqLogic) {
  var pwd = (typeof zendurePasswords !== 'undefined' && zendurePasswords[_eqLogic.id]) ? zendurePasswords[_eqLogic.id] : ''
  $('#span_zendurePassword').text(pwd)
  zendureShowConnection()
}

$('#bt_zendureAdd').on('click', function() {
  var options = []
  for (var id in zendureModels) {
    options.push({ text: zendureModels[id].name, value: id })
  }
  bootbox.prompt({
    title: '{{Type d\'appareil}}',
    inputType: 'select',
    inputOptions: options,
    value: 'sf2400ac',
    callback: function(model) {
      if (model === null) {
        return
      }
      var conn = zendureModels[model].connection
      var create = function(extra) {
        bootbox.prompt({
          title: '{{Nom de l\'équipement}}',
          value: zendureModels[model].name + (extra.deviceId ? ' ' + extra.deviceId : ''),
          callback: function(name) {
            if (name === null) {
              return
            }
            zendureAjax(Object.assign({ action: 'add', model: model, name: name }, extra), function(result) {
              var go = function() { loadPage('index.php?v=d&m=zendure&p=zendure&id=' + result.id) }
              if (!result.needCredentials) {
                go()
                return
              }
              bootbox.confirm('{{Ce deviceId n\'a pas encore de compte dans MQTT Manager. L\'ajouter maintenant ? (Mosquitto sera redémarré quelques secondes)}}', function(ok) {
                if (!ok) {
                  go()
                  return
                }
                zendureAjax({ action: 'addCredentials', deviceIds: result.deviceId }, go)
              })
            })
          }
        })
      }
      if (conn == 'mqtt') {
        bootbox.prompt({
          title: '{{deviceId de l\'appareil}}',
          message: '<p>{{Les appareils MQTT se créent automatiquement dès qu\'ils sont autorisés sur le broker et redirigés : l\'ajout manuel est rarement nécessaire.}}</p>',
          callback: function(deviceId) {
            if (deviceId === null) {
              return
            }
            deviceId = deviceId.trim()
            if (!/^[A-Za-z0-9_-]{4,32}$/.test(deviceId)) {
              $.fn.showAlert({ message: '{{deviceId invalide : 4 à 32 caractères, lettres et chiffres}}', level: 'danger' })
              return
            }
            create({ deviceId: deviceId })
          }
        })
        return
      }
      bootbox.prompt({
        title: '{{Adresse IP de l\'appareil}}',
        callback: function(ip) {
          if (ip === null) {
            return
          }
          create({ ip: ip.trim() })
        }
      })
    }
  })
})

$('#bt_zendureDiscover').on('click', function() {
  $.fn.showAlert({ message: '{{Recherche des appareils zenSDK et compteurs Zendure sur le réseau (10 s)...}}', level: 'info' })
  zendureAjax({ action: 'discover' }, function(result) {
    if (result.length == 0) {
      $.fn.showAlert({ message: '{{Aucun appareil Zendure (_zendure._tcp) trouvé. Vérifiez que l\'API locale est activée (HEMS) et que la box Jeedom est sur le même réseau.}}', level: 'warning' })
      return
    }
    var msg = result.map(function(r) { return $('<div>').text(r.name + ' (' + r.ip + ') : ' + r.status).html() }).join('<br>')
    $.fn.showAlert({ message: msg, level: 'success' })
    setTimeout(function() { loadPage('index.php?v=d&m=zendure&p=zendure') }, 3000)
  })
})

$('#bt_zendureRefresh').on('click', function() {
  zendureAjax({ action: 'refresh', id: $('.eqLogicAttr[data-l1key=id]').value() }, function() {
    $.fn.showAlert({ message: '{{Lecture demandée}}', level: 'success' })
  })
})

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }
  var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">'
  tr += '<td class="hidden-xs"><span class="cmdAttr" data-l1key="id"></span></td>'
  tr += '<td>'
  tr += '<input class="cmdAttr form-control input-sm" data-l1key="name" placeholder="{{Nom de la commande}}">'
  tr += '<span class="cmdAttr label label-default" data-l1key="logicalId" style="margin-top:3px;display:inline-block;"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td><span class="cmdAttr" data-l1key="htmlstate"></span></td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label> '
  tr += '<label class="checkbox-inline cmdType info"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label> '
  tr += '<div style="margin-top:7px;">'
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="minValue" placeholder="{{Min}}" title="{{Min}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">'
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="maxValue" placeholder="{{Max}}" title="{{Max}}" style="width:30%;max-width:80px;display:inline-block;margin-right:2px;">'
  tr += '<input class="tooltips cmdAttr form-control input-sm" data-l1key="unite" placeholder="{{Unité}}" title="{{Unité}}" style="width:30%;max-width:80px;display:inline-block;">'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>'
  }
  tr += '</td>'
  tr += '</tr>'
  $('#table_cmd tbody').append(tr)
  var tr = $('#table_cmd tbody tr').last()
  tr.setValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(tr, init(_cmd.subType))
}
