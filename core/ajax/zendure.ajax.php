<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    ajax::init();
    class_exists('zendure');

    if (init('action') == 'add') {
        $model = init('model');
        if (!isset(zendure::MODELS[$model])) {
            throw new Exception(__('Type de produit inconnu', __FILE__) . ' : ' . $model);
        }
        $def = zendure::MODELS[$model];
        $name = trim((string) init('name'));
        if ($name == '') {
            $name = $def['name'];
        }
        $eqLogic = new zendure();
        $eqLogic->setEqType_name('zendure');
        $eqLogic->setIsEnable(1);
        $eqLogic->setIsVisible(1);
        $eqLogic->setConfiguration('model', $model);
        $needCredentials = false;
        if ($def['connection'] == 'mqtt') {
            $deviceId = trim((string) init('deviceId'));
            if (!preg_match('/^[A-Za-z0-9_-]{4,32}$/', $deviceId)) {
                throw new Exception(__('deviceId obligatoire (4 à 32 caractères, lettres et chiffres)', __FILE__));
            }
            if (is_object(zendure::byLogicalId($deviceId, 'zendure'))) {
                throw new Exception(__('Un équipement existe déjà pour ce deviceId', __FILE__) . ' : ' . $deviceId);
            }
            $eqLogic->setLogicalId($deviceId);
            $known = config::byKey('knownDevices', 'zendure', array());
            if (is_array($known) && isset($known[$deviceId])) {
                $eqLogic->setConfiguration('productKey', $known[$deviceId]);
            }
            if ($name == $def['name']) {
                $name .= ' ' . $deviceId;
            }
            $needCredentials = class_exists('mqtt2') && !zendure::hasCredentials($deviceId);
        } else {
            $ip = trim((string) init('ip'));
            if ($ip != '') {
                $eqLogic->setConfiguration('ip', $ip);
            }
            $eqLogic->setConfiguration('port', 80);
            if ($def['connection'] == 'http') {
                $eqLogic->setConfiguration('smartMode', 1);
            }
        }
        $eqLogic->setName($name);
        $eqLogic->save();
        if ($eqLogic->getConfiguration('ip') != '') {
            try {
                $eqLogic->requestAll();
            } catch (Exception $e) {
                log::add('zendure', 'warning', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
        ajax::success(array('id' => $eqLogic->getId(), 'needCredentials' => $needCredentials, 'deviceId' => $eqLogic->getLogicalId()));
    }

    if (init('action') == 'discover') {
        ajax::success(zendure::discoverMdns());
    }

    if (init('action') == 'addCredentials') {
        $ids = preg_split('/[\s,;]+/', (string) init('deviceIds'), -1, PREG_SPLIT_NO_EMPTY);
        ajax::success(zendure::addCredentials($ids));
    }

    if (init('action') == 'allowTls') {
        ajax::success(zendure::allowTlsWithoutClientCert());
    }

    if (init('action') == 'brokerStatus') {
        ajax::success(array_merge(zendure::brokerStatus(), array('ip' => zendureBle::localIp())));
    }

    if (init('action') == 'refresh') {
        $eqLogic = zendure::byId(init('id'));
        if (!is_object($eqLogic)) {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        $eqLogic->requestAll();
        ajax::success();
    }

    if (init('action') == 'bleStatus') {
        ajax::success(zendureBle::status());
    }

    if (init('action') == 'bleInstall') {
        ajax::success(zendureBle::install());
    }

    if (init('action') == 'bleScan') {
        ajax::success(zendureBle::scan());
    }

    if (init('action') == 'bleSetBroker') {
        // le mot de passe Wi-Fi n'est transmis qu'au script Bluetooth (entrée standard), ni journalisé ni enregistré
        ajax::success(zendureBle::setBroker(init('address'), init('broker'), init('ssid'), (string) init('password')));
    }

    throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
