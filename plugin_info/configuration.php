<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
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
        <div class="alert alert-info" style="margin-bottom:10px;">{{Version du plugin}} : <strong><?php echo zendure::pluginVersion(); ?></strong></div>
        <?php if (!class_exists('mqtt2')) { ?>
            <div class="alert alert-warning">{{MQTT Manager (mqtt2) n'est pas installé : il est nécessaire pour les Hyper 2000, Hub, AIO et ACE (MQTT local). Les SolarFlow zenSDK et les compteurs fonctionnent sans.}}</div>
        <?php } ?>

        <legend><i class="fas fa-search"></i> {{Détection}}</legend>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Recherche automatique}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Recherche mDNS des SolarFlow et compteurs Zendure toutes les 15 min : crée les nouveaux appareils et met à jour leur IP si elle change}}"></i></sup></label>
            <div class="col-md-4"><input type="checkbox" class="configKey" data-l1key="autodiscover" checked></div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Créer automatiquement les appareils MQTT détectés}}</label>
            <div class="col-md-4"><input type="checkbox" class="configKey" data-l1key="autocreate" checked></div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Interroger les appareils MQTT muets (getAll)}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Évite que certains firmwares passent hors ligne et rafraîchit toutes les valeurs}}"></i></sup></label>
            <div class="col-md-4"><input type="checkbox" class="configKey" data-l1key="poll" checked></div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Hors ligne après (secondes sans donnée)}}</label>
            <div class="col-md-2"><input class="configKey form-control" data-l1key="offline_after" placeholder="180"></div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Lecture des appareils zenSDK (secondes)}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Intervalle de lecture par le démon (5 s minimum). Démon arrêté : lecture chaque minute par le cron. Les compteurs ont leur propre intervalle dans leur équipement.}}"></i></sup></label>
            <div class="col-md-2"><input class="configKey form-control" data-l1key="refresh_interval" placeholder="15"></div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Reconnexion forcée d'un appareil MQTT muet après (secondes)}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Un appareil publie environ une fois par seconde. S'il reste connecté mais muet plus longtemps, sa session MQTT est figée côté broker : le démon coupe 3 s sa seule adresse pour qu'il se reconnecte. 0 = désactivé.}}"></i></sup></label>
            <div class="col-md-2"><input class="configKey form-control" data-l1key="watchdog_mute" placeholder="90"></div>
        </div>

        <legend><i class="fas fa-server"></i> {{Broker MQTT local (MQTT Manager)}}</legend>
        <div class="form-group">
            <label class="col-md-4 control-label">{{État}}</label>
            <div class="col-md-8" id="zendure_broker" style="padding-top:7px;">…</div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Autoriser des appareils sur le broker}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Un deviceId par ligne : le mot de passe est calculé. Autre appareil (ex. SolarFlow en MQTT) : identifiant:motdepasse. Le plugin les ajoute dans l'authentification de MQTT Manager et redémarre Mosquitto.}}"></i></sup></label>
            <div class="col-md-4"><textarea class="form-control" id="zendure_deviceIds" rows="3" placeholder="Ab12Cd34"></textarea></div>
            <div class="col-md-4"><a class="btn btn-success" id="bt_zendureAddCredentials"><i class="fas fa-user-plus"></i> {{Ajouter à MQTT Manager}}</a></div>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Port 8883 (TLS) sans certificat client}}
                <sup><i class="fas fa-question-circle tooltips" title="{{Les firmwares récents se connectent en TLS sur 8883 sans certificat client (log Mosquitto : « peer did not return a certificate »). Ce bouton retire l'exigence de certificat client du listener 8883 de MQTT Manager ; le mot de passe reste obligatoire.}}"></i></sup></label>
            <div class="col-md-4"><a class="btn btn-warning" id="bt_zendureAllowTls"><i class="fas fa-lock-open"></i> {{Autoriser les appareils en TLS (8883)}}</a></div>
        </div>

        <legend><i class="fab fa-bluetooth-b"></i> {{Redirection Bluetooth vers le broker local}}</legend>
        <div class="alert alert-info">
            {{Les Hyper 2000, Hub 1200 / 2000, AIO 2400 et ACE 1500 doivent être redirigés une fois vers le broker de la box, par Bluetooth. Deux possibilités :}}
            <ul style="margin:6px 0 0 0;">
                <li>{{depuis un PC Windows avec Bluetooth}} : <a href="plugins/zendure/core/php/tool.php"><i class="fas fa-download"></i> {{télécharger l'outil Windows}}</a> ({{aucune installation, fermez l'application Zendure sur le téléphone}}) ;</li>
                <li>{{depuis la box Jeedom, si elle a un adaptateur Bluetooth à portée de l'appareil}} : {{ci-dessous}}.</li>
            </ul>
        </div>
        <div class="form-group">
            <label class="col-md-4 control-label">{{Bluetooth de la box}}</label>
            <div class="col-md-8" style="padding-top:7px;"><span id="zendure_bleStatus">…</span>
                <a class="btn btn-default btn-xs" id="bt_zendureBleInstall" style="display:none;margin-left:8px;"><i class="fas fa-download"></i> {{Installer le Bluetooth}}</a></div>
        </div>
        <div id="zendure_bleTools" style="display:none;">
            <div class="form-group">
                <label class="col-md-4 control-label">{{Appareils à proximité}}</label>
                <div class="col-md-6"><select class="form-control" id="zendure_bleDevice"><option value="">{{Lancez une recherche}}</option></select></div>
                <div class="col-md-2"><a class="btn btn-default" id="bt_zendureBleScan"><i class="fas fa-search"></i> {{Rechercher}}</a></div>
            </div>
            <div class="form-group">
                <label class="col-md-4 control-label">{{Adresse du broker (IP de la box)}}</label>
                <div class="col-md-3"><input class="form-control" id="zendure_bleBroker" placeholder="192.168.1.10"></div>
            </div>
            <div class="form-group">
                <label class="col-md-4 control-label">{{Wi-Fi 2,4 GHz : nom / mot de passe}}
                    <sup><i class="fas fa-question-circle tooltips" title="{{Le mot de passe n'est transmis qu'à l'appareil, ni enregistré ni journalisé}}"></i></sup></label>
                <div class="col-md-3"><input class="form-control" id="zendure_bleSsid" autocomplete="off"></div>
                <div class="col-md-3"><input class="form-control" type="password" id="zendure_blePassword" autocomplete="new-password"></div>
            </div>
            <div class="form-group">
                <label class="col-md-4 control-label"></label>
                <div class="col-md-8">
                    <a class="btn btn-success" id="bt_zendureBleLocal"><i class="fas fa-home"></i> {{Basculer vers le broker local}}</a>
                    <a class="btn btn-default" id="bt_zendureBleCloud"><i class="fas fa-cloud"></i> {{Revenir au cloud Zendure}}</a>
                </div>
            </div>
        </div>
    </fieldset>
</form>
<script>
(function() {
  function call(action, data, done, fail) {
    $.ajax({
      type: 'POST', url: 'plugins/zendure/core/ajax/zendure.ajax.php', dataType: 'json',
      data: Object.assign({ action: action }, data || {}),
      error: function(r, s, e) { handleAjaxError(r, s, e); if (fail) fail() },
      success: function(d) {
        if (d.state != 'ok') { $.fn.showAlert({ message: d.result, level: 'danger' }); if (fail) fail(); return }
        done(d.result)
      }
    })
  }
  function esc(t) { return $('<div>').text(t == null ? '' : String(t)).html() }

  call('brokerStatus', {}, function(b) {
    var h
    if (!b.installed) h = '<span class="label label-warning">{{MQTT Manager absent}}</span>'
    else {
      h = '<span class="label label-success">MQTT Manager · ' + esc(b.mode) + '</span> '
      if (b.tls8883) h += b.certRequired ? '<span class="label label-warning">{{8883 : certificat client exigé}}</span>' : '<span class="label label-success">{{8883 : sans certificat client}}</span>'
    }
    if (b.ip) { h += ' · {{IP de la box}} : <strong>' + esc(b.ip) + '</strong>'; if (!$('#zendure_bleBroker').val()) $('#zendure_bleBroker').val(b.ip) }
    $('#zendure_broker').html(h)
  })

  $('#bt_zendureAddCredentials').off('click').on('click', function() {
    var ids = $('#zendure_deviceIds').val().trim()
    if (ids == '') return
    call('addCredentials', { deviceIds: ids }, function(added) {
      $.fn.showAlert({ message: added.length ? '{{Ajoutés, Mosquitto redémarré}} : ' + esc(added.join(', ')) : '{{Rien à ajouter : ces comptes existent déjà}}', level: 'success' })
    })
  })
  $('#bt_zendureAllowTls').off('click').on('click', function() {
    bootbox.confirm('{{Retirer l\'exigence de certificat client du listener 8883 et redémarrer Mosquitto ? Le mot de passe reste obligatoire.}}', function(ok) {
      if (ok) call('allowTls', {}, function(msg) { $.fn.showAlert({ message: esc(msg), level: 'success' }) })
    })
  })

  var bleTimer = null
  function bleStatus() {
    call('bleStatus', {}, function(s) {
      var h
      if (!s.adapter) h = '<span class="label label-default">{{aucun adaptateur Bluetooth}}</span> {{utilisez l\'outil Windows}}'
      else if (s.installing) h = '<span class="label label-info">{{installation en cours}} ' + (s.progress || 0) + ' %</span>'
      else if (!s.installed) h = '<span class="label label-warning">' + esc(s.adapters.join(', ')) + ' · {{outils non installés}}</span>'
      else h = '<span class="label label-success">' + esc(s.adapters.join(', ')) + ' · {{prêt}}</span>'
      $('#zendure_bleStatus').html(h)
      $('#bt_zendureBleInstall').toggle(s.adapter && !s.installed && !s.installing)
      $('#zendure_bleTools').toggle(s.adapter && s.installed)
      clearTimeout(bleTimer)
      if (s.installing) bleTimer = setTimeout(bleStatus, 3000)
    })
  }
  bleStatus()
  $('#bt_zendureBleInstall').off('click').on('click', function() {
    call('bleInstall', {}, function() { $.fn.showAlert({ message: '{{Installation lancée (quelques minutes, log zendure_ble)}}', level: 'info' }); bleStatus() })
  })
  $('#bt_zendureBleScan').off('click').on('click', function() {
    var btn = $(this).addClass('disabled')
    $.fn.showAlert({ message: '{{Recherche Bluetooth (10 s)… fermez l\'application Zendure sur le téléphone}}', level: 'info' })
    call('bleScan', {}, function(list) {
      btn.removeClass('disabled')
      var o = list.length ? '' : '<option value="">{{Aucun appareil Zendure à portée}}</option>'
      list.forEach(function(d) {
        o += '<option value="' + esc(d.address) + '">' + esc(d.model + ' · ' + d.name + (d.sn ? ' · …' + d.sn : '') + ' (' + d.rssi + ' dBm)') + '</option>'
      })
      $('#zendure_bleDevice').html(o)
    }, function() { btn.removeClass('disabled') })
  })
  function send(target) {
    var address = $('#zendure_bleDevice').val(), ssid = $('#zendure_bleSsid').val().trim(), pwd = $('#zendure_blePassword').val()
    var broker = target == 'cloud' ? 'cloud' : $('#zendure_bleBroker').val().trim()
    if (!address || !ssid || !pwd || !broker) {
      $.fn.showAlert({ message: '{{Choisissez un appareil et renseignez le broker, le nom et le mot de passe du Wi-Fi}}', level: 'warning' })
      return
    }
    var label = target == 'cloud' ? '{{le cloud Zendure}}' : broker
    bootbox.confirm('{{Envoyer la configuration à l\'appareil ? Il se reconnectera à}} ' + esc(label) + ' {{en 15 à 60 s.}}', function(ok) {
      if (!ok) return
      call('bleSetBroker', { address: address, broker: broker, ssid: ssid, password: pwd }, function(r) {
        $('#zendure_blePassword').val('')
        $.fn.showAlert({ message: '{{Configuration envoyée.}}' + (r.deviceId ? ' deviceId : <strong>' + esc(r.deviceId) + '</strong>.' : '')
          + ' {{Si rien n\'arrive sur le broker d\'ici 2 minutes, éteignez puis rallumez l\'appareil.}}', level: 'success' })
        if (r.deviceId && target != 'cloud') $('#zendure_deviceIds').val(r.deviceId)
      })
    })
  }
  $('#bt_zendureBleLocal').off('click').on('click', function() { send('local') })
  $('#bt_zendureBleCloud').off('click').on('click', function() { send('cloud') })
})()
</script>
