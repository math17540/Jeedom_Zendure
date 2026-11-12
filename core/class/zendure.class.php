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
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/zendureSdkMqtt.php';
require_once __DIR__ . '/zendureMeter.php';
require_once __DIR__ . '/zendureBle.php';

/**
 * Appareils Zendure en local, sans cloud.
 *
 * - Modèles « anciens » (Hyper 2000, SolarFlow Hub 1200 / 2000, AIO 2400, ACE 1500) : MQTT via MQTT Manager.
 *     rapports  /<productKey>/<deviceId>/properties/report
 *     commandes iot/<productKey>/<deviceId>/properties/{read,write}, function/invoke
 *   L'appareil est redirigé vers le broker local par Bluetooth (outil Windows fourni, ou Bluetooth de la box).
 *
 * - Modèles zenSDK (SolarFlow 800 / 800 Plus / 800 Pro / 1600 AC+ / 2400 AC / AC+ / Pro / 3000 Mix AC+ / 4000) : API HTTP locale.
 *     GET  http://<ip>/properties/report
 *     POST http://<ip>/properties/write   {"sn", "id", "properties": {...}}
 *   Option : données et commandes par MQTT si l'appareil publie sur le broker (HA.Mqtt).
 *
 * - Compteurs : Zendure Smart Meter 3CT (zenSDK) et Shelly (EM, 3EM, Pro EM, Pro 3EM…), en HTTP (zendureMeter).
 *
 * Limites de puissance par modèle reprises de l'intégration officielle Zendure-HA et de l'adaptateur ioBroker de nograx.
 */
class zendure extends eqLogic {

    /** Propriétés exprimées x10 par le firmware (1000 = 100 %). */
    const SCALED_X10 = array('socSet', 'minSoc');

    /** Sous-topics publiés par un appareil sous /<productKey>/<deviceId>/ */
    const DEVICE_TOPICS = array('properties', 'register', 'time-sync', 'event', 'firmware', 'log', 'config', 'function');

    /** Propriétés pour lesquelles smartMode=1 évite l'écriture en flash. */
    const SMARTMODE_PROPS = array('outputLimit', 'inputLimit', 'acMode');

    /**
     * productKey => modèle (table de l'adaptateur ioBroker zendure-solarflow de nograx ; B3Dxda vérifié sur un Hyper réel).
     * Un productKey inconnu donne un « Appareil Zendure MQTT » dont on choisit le modèle dans l'équipement.
     */
    const KNOWN_PRODUCT_KEYS = array('73bkTV' => 'hub1200', 'A8yh63' => 'hub2000', 'yWF7hV' => 'aio2400',
        'ja72U0ha' => 'hyper2000', 'B3Dxda' => 'hyper2000', 'gDa3tb' => 'hyper2000', '8bM93H' => 'ace1500');

    /** Début du nom Bluetooth => modèle (outil Zendure Cloud Disconnector de nograx). */
    const BLE_PREFIXES = array('zenp' => 'hub1200', 'zenh' => 'hub2000', 'zenr' => 'aio2400', 'zene' => 'hyper2000', 'zenf' => 'ace1500');

    /**
     * Modèles pris en charge.
     * connection : mqtt (MQTT Manager) | http (API zenSDK) | meter3ct | shelly
     * control    : pilotage direct de la puissance pour les modèles MQTT (hyper | hub | aio | none)
     * infos      : commandes info créées d'office (les autres de ALL_INFOS le sont à la première valeur reçue)
     */
    const MODELS = array(
        'hyper2000' => array('name' => 'Hyper 2000', 'connection' => 'mqtt', 'outputMax' => 1200, 'inputMax' => 1200, 'control' => 'hyper',
            'infos' => array('solarPower1', 'solarPower2', 'pass', 'heatState')),
        'hub1200' => array('name' => 'SolarFlow Hub 1200', 'connection' => 'mqtt', 'outputMax' => 1200, 'inputMax' => 0, 'control' => 'hub',
            'infos' => array('solarPower1', 'solarPower2', 'pass')),
        'hub2000' => array('name' => 'SolarFlow Hub 2000', 'connection' => 'mqtt', 'outputMax' => 1200, 'inputMax' => 0, 'control' => 'hub',
            'infos' => array('solarPower1', 'solarPower2', 'pass')),
        'aio2400' => array('name' => 'SolarFlow AIO 2400', 'connection' => 'mqtt', 'outputMax' => 1200, 'inputMax' => 0, 'control' => 'aio',
            'infos' => array('solarPower1', 'solarPower2')),
        'ace1500' => array('name' => 'ACE 1500', 'connection' => 'mqtt', 'outputMax' => 800, 'inputMax' => 900, 'control' => 'none',
            'infos' => array()),
        'mqttgeneric' => array('name' => 'Appareil Zendure MQTT (modèle à choisir)', 'connection' => 'mqtt', 'outputMax' => 1200, 'inputMax' => 1200, 'control' => 'none',
            'infos' => array()),
        'sf800' => array('name' => 'SolarFlow 800', 'connection' => 'http', 'outputMax' => 800, 'inputMax' => 1000, 'infos' => array('rssi', 'smartMode')),
        'sf800plus' => array('name' => 'SolarFlow 800 Plus', 'connection' => 'http', 'outputMax' => 800, 'inputMax' => 1000, 'infos' => array('rssi', 'smartMode')),
        'sf800pro' => array('name' => 'SolarFlow 800 Pro', 'connection' => 'http', 'outputMax' => 800, 'inputMax' => 1000,
            'infos' => array('rssi', 'smartMode', 'gridOffPower')),
        'sf1600acplus' => array('name' => 'SolarFlow 1600 AC+', 'connection' => 'http', 'outputMax' => 1600, 'inputMax' => 1600,
            'infos' => array('rssi', 'smartMode', 'gridOffPower')),
        'sf2400ac' => array('name' => 'SolarFlow 2400 AC', 'connection' => 'http', 'outputMax' => 2400, 'inputMax' => 2400,
            'infos' => array('rssi', 'smartMode', 'gridOffPower', 'heatState')),
        'sf2400acplus' => array('name' => 'SolarFlow 2400 AC+', 'connection' => 'http', 'outputMax' => 2400, 'inputMax' => 2400,
            'infos' => array('rssi', 'smartMode', 'gridOffPower', 'heatState', 'inverseMaxPower', 'gridReverse', 'gridOffMode')),
        'sf2400pro' => array('name' => 'SolarFlow 2400 Pro', 'connection' => 'http', 'outputMax' => 2400, 'inputMax' => 3200,
            'infos' => array('rssi', 'smartMode', 'gridOffPower', 'heatState')),
        'sf3000mixacplus' => array('name' => 'SolarFlow 3000 Mix AC+', 'connection' => 'http', 'outputMax' => 3000, 'inputMax' => 3000,
            'infos' => array('rssi', 'smartMode', 'gridOffPower', 'heatState')),
        'sf4000acplus' => array('name' => 'SolarFlow 4000 (Mix AC+ / Mix Pro)', 'connection' => 'http', 'outputMax' => 2400, 'inputMax' => 3200,
            'infos' => array('rssi', 'smartMode', 'gridOffPower', 'heatState')),
        'zmeter' => array('name' => 'Compteur Zendure (Smart Meter 1CT / 3CT / D0)', 'connection' => 'zmeter', 'outputMax' => 0, 'inputMax' => 0, 'infos' => array()),
        'shelly' => array('name' => 'Compteur Shelly', 'connection' => 'shelly', 'outputMax' => 0, 'inputMax' => 0, 'infos' => array()),
    );

    /** Actions supplémentaires des appareils zenSDK (propriétés documentées par Zendure). */
    const HTTP_ACTIONS = array(
        'setInverseMaxPower' => array('Régler puissance max onduleur', 'slider', 'inverseMaxPower', 'inverseMaxPower', 200, 'outputMax', ''),
        'setGridReverse'     => array('Régler renvoi vers le réseau', 'select', 'gridReverse', 'gridReverse', null, null, '1|Autorisé;2|Interdit'),
        'setGridOffMode'     => array('Régler mode prise secours', 'select', 'gridOffMode', 'gridOffMode', null, null, '0|Normal;1|Économique;2|Arrêt'),
    );

    /** Infos communes à toutes les batteries. */
    const COMMON_INFOS = array(
        'online', 'electricLevel', 'solarInputPower', 'outputHomePower', 'gridInputPower',
        'outputPackPower', 'packInputPower', 'outputLimit', 'inputLimit', 'socSet', 'minSoc',
        'acMode', 'packState', 'packNum', 'remainOutTime', 'hyperTmp',
    );

    /** logicalId => [nom, sous-type, unité, min, max, historiser] */
    const ALL_INFOS = array(
        'online'          => array('En ligne', 'binary', '', null, null, 1),
        'electricLevel'   => array('Batterie', 'numeric', '%', 0, 100, 1),
        'solarInputPower' => array('Production solaire', 'numeric', 'W', 0, 4000, 1),
        'solarPower1'     => array('PV 1', 'numeric', 'W', 0, 1500, 1),
        'solarPower2'     => array('PV 2', 'numeric', 'W', 0, 1500, 1),
        'solarPower3'     => array('PV 3', 'numeric', 'W', 0, 1500, 1),
        'solarPower4'     => array('PV 4', 'numeric', 'W', 0, 1500, 1),
        'solarPower5'     => array('PV 5', 'numeric', 'W', 0, 1500, 1),
        'solarPower6'     => array('PV 6', 'numeric', 'W', 0, 1500, 1),
        'outputHomePower' => array('Injection maison', 'numeric', 'W', 0, 2400, 1),
        'gridInputPower'  => array('Charge réseau', 'numeric', 'W', 0, 3200, 1),
        'outputPackPower' => array('Charge batterie', 'numeric', 'W', 0, 3200, 1),
        'packInputPower'  => array('Décharge batterie', 'numeric', 'W', 0, 3200, 1),
        'gridOffPower'    => array('Prise secours', 'numeric', 'W', 0, 2400, 1),
        'outputLimit'     => array('Limite injection', 'numeric', 'W', 0, 2400, 0),
        'inputLimit'      => array('Limite charge réseau', 'numeric', 'W', 0, 3200, 0),
        'socSet'          => array('SOC max', 'numeric', '%', 70, 100, 0),
        'minSoc'          => array('SOC min', 'numeric', '%', 0, 50, 0),
        'acMode'          => array('Mode AC', 'numeric', '', 1, 2, 0),
        'smartMode'       => array('Smart mode (sans flash)', 'binary', '', null, null, 0),
        'packState'       => array('État batterie', 'numeric', '', 0, 2, 0),
        'packNum'         => array('Nombre de batteries', 'numeric', '', 0, 6, 0),
        'remainOutTime'   => array('Autonomie restante', 'numeric', 'min', null, null, 0),
        'hyperTmp'        => array('Température', 'numeric', '°C', -20, 80, 1),
        'pass'            => array('Bypass', 'binary', '', null, null, 0),
        'heatState'       => array('Chauffage', 'binary', '', null, null, 0),
        'rssi'            => array('Signal WiFi', 'numeric', 'dBm', -100, 0, 0),
        'inverseMaxPower' => array('Puissance max onduleur', 'numeric', 'W', 0, 2400, 0),
        'gridReverse'     => array('Renvoi vers le réseau', 'numeric', '', 0, 2, 0),
        'gridOffMode'     => array('Mode prise secours', 'numeric', '', 0, 2, 0),
        'lampSwitch'      => array('Voyant', 'binary', '', null, null, 0),
    );

    /** logicalId => [nom, sous-type, propriété firmware, info liée, min, max (chaîne = clé du modèle), liste] */
    const ACTIONS = array(
        'setOutputLimit' => array('Régler injection', 'slider', 'outputLimit', 'outputLimit', 0, 'outputMax', ''),
        'setInputLimit'  => array('Régler charge réseau', 'slider', 'inputLimit', 'inputLimit', 0, 'inputMax', ''),
        'setSocSet'      => array('Régler SOC max', 'slider', 'socSet', 'socSet', 70, 100, ''),
        'setMinSoc'      => array('Régler SOC min', 'slider', 'minSoc', 'minSoc', 0, 50, ''),
        'setAcMode'      => array('Régler mode AC', 'select', 'acMode', 'acMode', null, null, '1|Charger;2|Injecter'),
        'refresh'        => array('Rafraîchir', 'other', '', '', null, null, ''),
        // pilotage direct (comme Zendure-HA) : deviceAutomation en MQTT, propriétés groupées en zenSDK
        'chargeGrid'     => array('Charger depuis le réseau', 'slider', '', 'gridInputPower', 0, 'inputMax', ''),
        'dischargeHome'  => array('Injecter', 'slider', '', 'outputHomePower', 0, 'outputMax', ''),
        'stopPower'      => array('Arrêter charge et injection', 'other', '', '', null, null, ''),
    );

    /** Données de chaque batterie : propriété => [nom, unité, historiser] */
    const PACK_INFOS = array(
        'socLevel' => array('SOC', '%', 1),
        'maxTemp'  => array('Température', '°C', 1),
        'totalVol' => array('Tension', 'V', 0),
        'batcur'   => array('Courant', 'A', 0),
        'soh'      => array('Santé', '%', 0),
        'power'    => array('Puissance', 'W', 0),
    );

    /* ------------------------------------------------------------ MQTT Manager */

    public static function registerTopics() {
        if (!class_exists('mqtt2')) {
            log::add(__CLASS__, 'warning', __('MQTT Manager absent : seuls les appareils HTTP (zenSDK, compteurs) fonctionneront', __FILE__));
            return;
        }
        mqtt2::addPluginTopic(__CLASS__, '');        // /<pk>/<deviceId>/...
        mqtt2::addPluginTopic(__CLASS__, 'iot');     // accusés de réception
        mqtt2::addPluginTopic(__CLASS__, 'Zendure'); // zenSDK en MQTT (HA.Mqtt)
    }

    /** Appelée par MQTT Manager : array(premierNiveau => arbre du topic). */
    public static function handleMqttMessage($_message) {
        if (!is_array($_message)) {
            return;
        }
        foreach ($_message as $root => $tree) {
            if ($root === 'iot') {
                log::add(__CLASS__, 'debug', 'iot : ' . json_encode($tree));
                continue;
            }
            if ($root === 'Zendure') {
                zendureSdkMqtt::handle($tree);
                continue;
            }
            if ($root !== '' || !is_array($tree)) {
                self::forwardToMqtt2(array($root => $tree));
                continue;
            }
            foreach ($tree as $productKey => $devices) {
                if (!is_array($devices)) {
                    self::forwardToMqtt2(array('' => array($productKey => $devices)));
                    continue;
                }
                foreach ($devices as $deviceId => $kinds) {
                    if (!is_array($kinds) || count(array_intersect(array_keys($kinds), self::DEVICE_TOPICS)) == 0) {
                        self::forwardToMqtt2(array('' => array($productKey => array($deviceId => $kinds))));
                        continue;
                    }
                    $productKey = (string) $productKey;
                    $deviceId = (string) $deviceId;
                    self::rememberDevice($productKey, $deviceId);
                    if (isset($kinds['register'])) {
                        // l'appareil s'annonce : on lui répond comme Zendure-HA (message retenu vide)
                        log::add(__CLASS__, 'info', $deviceId . ' register : ' . json_encode($kinds['register']));
                        try {
                            mqtt2::publish('iot/' . $productKey . '/' . $deviceId . '/register/replay', '', array('retain' => true, 'qos' => 1));
                        } catch (Exception $e) {
                            log::add(__CLASS__, 'warning', $deviceId . ' register/replay : ' . $e->getMessage());
                        }
                        self::sendMqttRaw($productKey, $deviceId, 'properties/read', array('properties' => array('getAll')));
                    }
                    if (isset($kinds['properties']['report']) && is_array($kinds['properties']['report'])) {
                        self::handleMqttReport($productKey, $deviceId, $kinds['properties']['report']);
                    }
                }
            }
        }
    }

    /** Appareils vus sur le broker (deviceId => productKey), même sans équipement. */
    public static function rememberDevice($_productKey, $_deviceId) {
        $known = config::byKey('knownDevices', __CLASS__, array());
        if (!is_array($known)) {
            $known = array();
        }
        if (!isset($known[$_deviceId]) || $known[$_deviceId] != $_productKey) {
            $known[$_deviceId] = $_productKey;
            config::save('knownDevices', $known, __CLASS__);
            log::add(__CLASS__, 'info', __('Appareil vu sur le broker', __FILE__) . ' : ' . $_deviceId . ' (productKey ' . $_productKey . ')');
        }
    }

    /** Publie vers un appareil MQTT, avec ou sans équipement. */
    public static function sendMqttRaw($_productKey, $_deviceId, $_kind, $_body) {
        if (!class_exists('mqtt2')) {
            throw new Exception(__('MQTT Manager n\'est pas installé', __FILE__));
        }
        $id = ((int) cache::byKey('zendure::msgid::' . $_deviceId)->getValue(0) % 100000) + 1;
        cache::set('zendure::msgid::' . $_deviceId, $id);
        $_body['deviceId'] = $_deviceId;
        $_body['messageId'] = $id;
        $_body['timestamp'] = time();
        mqtt2::publish('iot/' . $_productKey . '/' . $_deviceId . '/' . $_kind, $_body);
    }

    /** Topics commençant par '/' qui ne viennent pas d'un Zendure : on les rend à MQTT Manager. */
    private static function forwardToMqtt2($_message) {
        if (class_exists('mqtt2')) {
            mqtt2::handleMqttMessage($_message);
        }
    }

    public static function handleMqttReport($_productKey, $_deviceId, $_payload) {
        log::add(__CLASS__, 'debug', $_deviceId . ' report : ' . json_encode($_payload));
        $eqLogic = self::byLogicalId($_deviceId, __CLASS__);
        if (!is_object($eqLogic)) {
            if (config::byKey('autocreate', __CLASS__, 1) != 1) {
                log::add(__CLASS__, 'info', __('Rapport d\'un appareil inconnu ignoré (création automatique désactivée)', __FILE__) . ' : ' . $_deviceId);
                return;
            }
            $model = isset(self::KNOWN_PRODUCT_KEYS[$_productKey]) ? self::KNOWN_PRODUCT_KEYS[$_productKey] : 'mqttgeneric';
            $eqLogic = new zendure();
            $eqLogic->setEqType_name(__CLASS__);
            $eqLogic->setLogicalId($_deviceId);
            $eqLogic->setName(($model == 'mqttgeneric' ? 'Zendure' : self::MODELS[$model]['name']) . ' ' . $_deviceId);
            $eqLogic->setIsEnable(1);
            $eqLogic->setIsVisible(1);
            $eqLogic->setConfiguration('model', $model);
            $eqLogic->setConfiguration('productKey', $_productKey);
            $eqLogic->save();
            log::add(__CLASS__, 'info', __('Nouvel appareil MQTT créé', __FILE__) . ' : ' . $_deviceId . ' (productKey ' . $_productKey . ')');
            event::add('jeedom::alert', array(
                'level' => 'success',
                'page' => 'zendure',
                'message' => __('Nouvel appareil Zendure détecté', __FILE__) . ' : ' . $_deviceId
                    . ($model == 'mqttgeneric' ? ' — ' . __('choisissez son modèle dans l\'équipement', __FILE__) : ''),
            ));
        }
        if ($eqLogic->getIsEnable() != 1) {
            return;
        }
        if ($eqLogic->getConfiguration('productKey') != $_productKey) {
            $eqLogic->setConfiguration('productKey', $_productKey);
            $eqLogic->save(true);
        }
        $eqLogic->applyReport($_payload);
    }

    /* ------------------------------------------------------------- mDNS (zenSDK) */

    /**
     * Cherche les appareils zenSDK (_zendure._tcp : SolarFlow, compteurs Zendure) et crée ceux qui manquent.
     * @return array liste de ['name', 'ip', 'port', 'status']
     */
    public static function discoverMdns() {
        if (trim((string) shell_exec('command -v avahi-browse')) == '') {
            throw new Exception(__('avahi-browse introuvable : installez le paquet avahi-utils', __FILE__));
        }
        $out = (string) shell_exec('timeout 10 avahi-browse -rtp _zendure._tcp 2>/dev/null');
        $results = array();
        foreach (explode("\n", $out) as $line) {
            $f = explode(';', $line);
            // =;interface;IPv4;nom;_zendure._tcp;local;hôte;adresse;port;txt
            if (count($f) < 9 || $f[0] != '=' || $f[2] != 'IPv4' || $f[1] == 'lo') {
                continue;
            }
            $name = str_replace('\\032', ' ', $f[3]);
            $ip = $f[7];
            $port = (int) $f[8];
            if (isset($results[$name])) {
                continue;
            }
            $results[$name] = array('name' => $name, 'ip' => $ip, 'port' => $port, 'status' => '');
            $model = self::modelFromServiceName($name);
            if ($model === null) {
                $results[$name]['status'] = __('modèle non reconnu', __FILE__);
                continue;
            }
            $eqLogic = self::byLogicalId($name, __CLASS__);
            if (!is_object($eqLogic)) {
                // équipement ajouté à la main (sans nom mDNS) : même IP et même modèle
                foreach (self::byType(__CLASS__) as $eq) {
                    if ($eq->getConfiguration('ip') == $ip && $eq->getConfiguration('model') == $model && $eq->getLogicalId() == '') {
                        $eqLogic = $eq;
                        $eqLogic->setLogicalId($name);
                        $eqLogic->save(true);
                        break;
                    }
                }
            }
            if (is_object($eqLogic)) {
                if ($eqLogic->getConfiguration('ip') != $ip || (int) $eqLogic->getConfiguration('port', 80) != $port) {
                    $eqLogic->setConfiguration('ip', $ip);
                    $eqLogic->setConfiguration('port', $port);
                    $eqLogic->save();
                    $results[$name]['status'] = __('IP mise à jour', __FILE__);
                } else {
                    $results[$name]['status'] = __('déjà présent', __FILE__);
                }
                continue;
            }
            $eqLogic = new zendure();
            $eqLogic->setEqType_name(__CLASS__);
            $eqLogic->setLogicalId($name);
            $eqLogic->setName(self::shortName($model) . ' ' . substr($name, -4));
            $eqLogic->setIsEnable(1);
            $eqLogic->setIsVisible(1);
            $eqLogic->setConfiguration('model', $model);
            $eqLogic->setConfiguration('ip', $ip);
            $eqLogic->setConfiguration('port', $port);
            if (self::MODELS[$model]['connection'] == 'http') {
                $eqLogic->setConfiguration('smartMode', 1);
            }
            $eqLogic->save();
            $results[$name]['status'] = __('créé', __FILE__);
            log::add(__CLASS__, 'info', __('Appareil zenSDK créé', __FILE__) . ' : ' . $name . ' (' . $ip . ':' . $port . ')');
            try {
                $eqLogic->requestAll();
            } catch (Exception $e) {
                log::add(__CLASS__, 'warning', $name . ' : ' . $e->getMessage());
            }
        }
        return array_values($results);
    }

    /** Nom de modèle sans la précision entre parenthèses, pour nommer un équipement créé automatiquement. */
    public static function shortName($_model) {
        return trim(preg_replace('/\s*\(.*\)\s*$/', '', self::MODELS[$_model]['name']));
    }

    /** Nom de service mDNS « Zendure-<Modèle>-<MAC> » -> modèle du plugin. */
    public static function modelFromServiceName($_name) {
        $n = strtolower(str_replace(array(' ', '-', '_'), '', $_name));
        if (strpos($n, 'meterreader') !== false || strpos($n, 'smartmeter') !== false) {
            return 'zmeter';
        }
        $plus = strpos($n, 'plus') !== false || strpos($n, 'ac+') !== false;
        if (strpos($n, '3000') !== false) {
            return 'sf3000mixacplus';
        }
        if (strpos($n, '4000') !== false) {
            return 'sf4000acplus';
        }
        if (strpos($n, '2400') !== false) {
            return strpos($n, 'pro') !== false ? 'sf2400pro' : ($plus ? 'sf2400acplus' : 'sf2400ac');
        }
        if (strpos($n, '1600') !== false) {
            return 'sf1600acplus';
        }
        if (strpos($n, '800') !== false) {
            return strpos($n, 'pro') !== false ? 'sf800pro' : ($plus ? 'sf800plus' : 'sf800');
        }
        return null;
    }

    /* ------------------------------------------------------------- conversions */

    public static function convert($_key, $_value) {
        if (!is_numeric($_value)) {
            return $_value;
        }
        if (in_array($_key, self::SCALED_X10)) {
            return $_value / 10;
        }
        if ($_key == 'hyperTmp' && $_value > 1000) {
            return round(($_value - 2731) / 10, 1); // décikelvin -> °C
        }
        return $_value;
    }

    public static function convertPack($_key, $_value) {
        if (!is_numeric($_value)) {
            return $_value;
        }
        switch ($_key) {
            case 'maxTemp':
                return ($_value > 1000) ? round(($_value - 2731) / 10, 1) : $_value;
            case 'totalVol':
                return ($_value > 1000) ? round($_value / 100, 2) : $_value; // centivolts -> V
            case 'batcur':
                $v = (int) $_value;
                if ($v >= 0x8000) {
                    $v -= 0x10000;
                }
                return round($v / 10, 1); // négatif = décharge
            case 'soh':
                return ($_value > 100) ? round($_value / 10, 1) : $_value;
        }
        return $_value;
    }

    /** Nom de batterie d'après son numéro de série (table de l'intégration Zendure-HA). */
    public static function packModel($_sn) {
        switch (substr($_sn, 0, 1)) {
            case 'A':
                return (substr($_sn, 3, 1) == '3') ? 'AIO2400' : 'AB1000';
            case 'B':
                return 'AB1000S';
            case 'C':
                $c = substr($_sn, 3, 1);
                return 'AB2000' . ($c == 'F' ? 'S' : ($c == 'E' ? 'X' : ''));
            case 'F':
                return 'AB3000';
            case 'G':
                return 'AB3000L';
            case 'J':
                return 'I2400';
        }
        return 'Batterie';
    }

    /* ------------------------------------------------------- identifiants MQTT */

    /** Mot de passe MQTT d'un appareil « ancien » : MD5(deviceId) en majuscules, caractères 8 à 24. */
    public static function devicePassword($_deviceId) {
        return substr(strtoupper(md5($_deviceId)), 8, 16);
    }

    /** Le deviceId a-t-il déjà un compte dans l'authentification de MQTT Manager ? */
    public static function hasCredentials($_deviceId) {
        foreach (explode("\n", (string) config::byKey('mqtt::password', 'mqtt2')) as $line) {
            if (explode(':', trim($line))[0] === $_deviceId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ajoute deviceId:motdepasse dans l'authentification de MQTT Manager et redémarre Mosquitto.
     * Une entrée « identifiant:motdepasse » crée un compte libre (ex. SolarFlow en MQTT).
     * @return array identifiants ajoutés
     */
    public static function addCredentials($_deviceIds) {
        if (!class_exists('mqtt2')) {
            throw new Exception(__('MQTT Manager n\'est pas installé', __FILE__));
        }
        $mode = config::byKey('mode', 'mqtt2');
        if (!in_array($mode, array('local', 'docker'))) {
            throw new Exception(__('MQTT Manager doit être en mode broker local ou Docker', __FILE__));
        }
        $lines = array_filter(array_map('trim', explode("\n", (string) config::byKey('mqtt::password', 'mqtt2'))));
        $users = array();
        foreach ($lines as $line) {
            $users[explode(':', $line)[0]] = true;
        }
        $added = array();
        foreach ($_deviceIds as $id) {
            $id = trim($id);
            $password = null;
            if (strpos($id, ':') !== false) {
                list($id, $password) = explode(':', $id, 2);
                $id = trim($id);
                $password = trim($password);
                if (!preg_match('/^[A-Za-z0-9._@+=!*-]{6,64}$/', $password)) {
                    throw new Exception(__('Mot de passe invalide pour', __FILE__) . ' ' . $id . ' : ' . __('6 à 64 caractères, sans espace ni deux-points', __FILE__));
                }
            }
            if ($id == 'jeedom') {
                throw new Exception(__('Le compte jeedom est réservé à MQTT Manager', __FILE__));
            }
            if ($id == '' || isset($users[$id])) {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_-]{4,32}$/', $id)) {
                throw new Exception(__('Identifiant invalide', __FILE__) . ' : ' . $id);
            }
            $lines[] = $id . ':' . ($password !== null ? $password : self::devicePassword($id));
            $users[$id] = true;
            $added[] = $id;
        }
        if (count($added) == 0) {
            return $added;
        }
        config::save('mqtt::password', implode("\n", $lines), 'mqtt2');
        mqtt2::setPassword($mode);
        mqtt2::restartMosquitto();
        log::add(__CLASS__, 'info', __('Identifiants ajoutés dans MQTT Manager', __FILE__) . ' : ' . implode(', ', $added));
        return $added;
    }

    /**
     * Les firmwares récents se connectent en TLS sur 8883, sans certificat client. Le listener 8883 de MQTT Manager
     * exige un certificat (require_certificate true) : on le passe à false, le mot de passe restant obligatoire.
     * Modifie le paramètre de MQTT Manager (persistant) et le fichier généré, puis redémarre Mosquitto.
     */
    public static function allowTlsWithoutClientCert() {
        if (!class_exists('mqtt2')) {
            throw new Exception(__('MQTT Manager n\'est pas installé', __FILE__));
        }
        if (!in_array(config::byKey('mode', 'mqtt2'), array('local', 'docker'))) {
            throw new Exception(__('MQTT Manager doit être en mode broker local ou Docker', __FILE__));
        }
        $pattern = '/^(\s*require_certificate\s+)true\s*$/mi';
        $params = (string) config::byKey('mosquitto::parameters', 'mqtt2');
        $changed = false;
        if (preg_match($pattern, $params)) {
            config::save('mosquitto::parameters', preg_replace($pattern, '${1}false', $params), 'mqtt2');
            $changed = true;
        }
        $file = __DIR__ . '/../../../mqtt2/data/mosquitto.conf';
        if (file_exists($file)) {
            $content = (string) file_get_contents($file);
            if (preg_match($pattern, $content)) {
                $tmp = jeedom::getTmpFolder(__CLASS__) . '/mosquitto.conf';
                file_put_contents($tmp, preg_replace($pattern, '${1}false', $content));
                shell_exec(system::getCmdSudo() . ' cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($file));
                unlink($tmp);
                $changed = true;
            }
        }
        if (!$changed) {
            return __('Déjà autorisé : aucun certificat client exigé', __FILE__);
        }
        mqtt2::restartMosquitto();
        log::add(__CLASS__, 'info', __('Listener 8883 : certificat client plus exigé (mot de passe toujours requis), Mosquitto redémarré', __FILE__));
        return __('Appareils autorisés en TLS sur 8883, Mosquitto redémarré', __FILE__);
    }

    /** État du broker pour la configuration : MQTT Manager, mode, listener 8883 sans certificat client. */
    public static function brokerStatus() {
        if (!class_exists('mqtt2')) {
            return array('installed' => false);
        }
        $file = __DIR__ . '/../../../mqtt2/data/mosquitto.conf';
        $conf = file_exists($file) ? (string) file_get_contents($file) : (string) config::byKey('mosquitto::parameters', 'mqtt2');
        return array('installed' => true, 'mode' => config::byKey('mode', 'mqtt2'),
            'tls8883' => preg_match('/^\s*listener\s+8883\b/mi', $conf) === 1,
            'certRequired' => preg_match('/^\s*require_certificate\s+true\s*$/mi', $conf) === 1);
    }

    /* ------------------------------------------------------------------ crons */

    /** Recherche automatique des appareils zenSDK (nouveaux appareils, changement d'IP). */
    public static function cron15() {
        if (config::byKey('autodiscover', __CLASS__, 1) != 1) {
            return;
        }
        try {
            foreach (self::discoverMdns() as $r) {
                if (in_array($r['status'], array(__('créé', __FILE__), __('IP mise à jour', __FILE__)))) {
                    event::add('jeedom::alert', array(
                        'level' => 'success',
                        'page' => 'zendure',
                        'message' => 'Zendure : ' . $r['name'] . ' (' . $r['ip'] . ') ' . $r['status'],
                    ));
                }
            }
        } catch (Exception $e) {
            log::add(__CLASS__, 'debug', 'mDNS : ' . $e->getMessage());
        }
    }

    /**
     * Installation par fichier .zip : Jeedom met la date dans le champ « Version » de la page plugin ; on y met le
     * numéro de version. Rien n'est changé pour les autres sources (GitHub, Market), qui s'en servent pour les mises à jour.
     */
    public static function syncUpdateVersion() {
        $version = self::pluginVersion();
        $update = update::byLogicalId(__CLASS__);
        if ($version == '' || !is_object($update) || $update->getSource() != 'file' || $update->getLocalVersion() == $version) {
            return;
        }
        $update->setLocalVersion($version);
        $update->save();
    }

    public static function cron() {
        try {
            self::syncUpdateVersion();
        } catch (Exception $e) {
            log::add(__CLASS__, 'debug', 'version : ' . $e->getMessage());
        }
        $offlineAfter = (int) config::byKey('offline_after', __CLASS__, 180);
        $daemon = self::deamon_info()['state'] == 'ok'; // démon actif : il fait les lectures
        // appareils connus sans équipement : on les relance pour qu'ils republient (recréation automatique)
        if (config::byKey('poll', __CLASS__, 1) == 1 && config::byKey('autocreate', __CLASS__, 1) == 1) {
            $known = config::byKey('knownDevices', __CLASS__, array());
            foreach ((is_array($known) ? $known : array()) as $deviceId => $productKey) {
                if (is_object(self::byLogicalId($deviceId, __CLASS__))) {
                    continue;
                }
                try {
                    self::sendMqttRaw($productKey, $deviceId, 'properties/read', array('properties' => array('getAll')));
                } catch (Exception $e) {
                    log::add(__CLASS__, 'debug', $deviceId . ' getAll : ' . $e->getMessage());
                }
            }
        }
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            $conn = $eqLogic->getConnection();
            try {
                if ($daemon) {
                    // rien : le démon lit les appareils
                } elseif ($conn == 'http') {
                    if ($eqLogic->getConfiguration('ip') != '' && !zendureSdkMqtt::isFresh($eqLogic)) {
                        $eqLogic->pollHttp();
                    }
                } elseif ($conn == 'zmeter' || $conn == 'shelly') {
                    zendureMeter::poll($eqLogic);
                } elseif (config::byKey('poll', __CLASS__, 1) == 1 && $eqLogic->getConfiguration('productKey') != '') {
                    $eqLogic->requestAll();
                }
            } catch (Exception $e) {
                log::add(__CLASS__, 'warning', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
            $lastSeen = (int) $eqLogic->getCache('lastSeen', 0);
            if ($lastSeen > 0 && (time() - $lastSeen) > $offlineAfter) {
                $eqLogic->checkAndUpdateCmd('online', 0);
            }
        }
    }

    /* ----------------------------------------------------------------- démon */

    private static function pidFile() {
        return jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
    }

    /** Page Santé de Jeedom : démon, broker, chaque appareil. */
    public static function health() {
        $r = array();
        $d = self::deamon_info();
        $r[] = array('test' => __('Démon', __FILE__), 'result' => $d['state'] == 'ok' ? __('OK', __FILE__) : __('arrêté', __FILE__),
            'advice' => $d['state'] == 'ok' ? '' : __('Démarrez le démon depuis la page du plugin (lecture rapide, surveillance MQTT)', __FILE__), 'state' => $d['state'] == 'ok');
        $b = self::brokerStatus();
        $hasMqtt = false;
        foreach (eqLogic::byType(__CLASS__, true) as $eq) {
            if ($eq->getConnection() == 'mqtt') {
                $hasMqtt = true;
            }
        }
        if ($hasMqtt) {
            $r[] = array('test' => __('MQTT Manager', __FILE__), 'result' => $b['installed'] ? $b['mode'] : __('absent', __FILE__),
                'advice' => $b['installed'] ? '' : __('Installez MQTT Manager en mode broker local', __FILE__), 'state' => $b['installed']);
        }
        foreach (eqLogic::byType(__CLASS__, true) as $eq) {
            $c = $eq->getCmd('info', 'online');
            $online = is_object($c) && $c->execCmd() == 1;
            $last = (int) $eq->getCache('lastSeen', 0);
            $conn = $eq->getConnection();
            $advice = array('mqtt' => __('Vérifiez le Wi-Fi de l\'appareil, son compte sur le broker et le log mqtt2', __FILE__),
                'http' => __('Vérifiez l\'IP et l\'API locale (HEMS dans l\'application Zendure)', __FILE__),
                'zmeter' => __('Vérifiez l\'IP du compteur', __FILE__), 'shelly' => __('Vérifiez l\'IP du Shelly (sans mot de passe)', __FILE__));
            $r[] = array('test' => $eq->getName(),
                'result' => ($online ? __('en ligne', __FILE__) : __('hors ligne', __FILE__)) . ($last ? ' · ' . sprintf(__('dernière donnée il y a %d s', __FILE__), time() - $last) : ''),
                'advice' => $online ? '' : (isset($advice[$conn]) ? $advice[$conn] : ''), 'state' => $online);
        }
        return $r;
    }

    public static function deamon_info() {
        $return = array('log' => __CLASS__, 'state' => 'nok', 'launchable' => 'ok');
        $pidFile = self::pidFile();
        if (file_exists($pidFile)) {
            $pid = (int) trim((string) file_get_contents($pidFile));
            if ($pid > 0 && file_exists('/proc/' . $pid)) {
                $return['state'] = 'ok';
            } else {
                @unlink($pidFile);
            }
        }
        return $return;
    }

    public static function deamon_start() {
        self::deamon_stop();
        $script = realpath(__DIR__ . '/../../resources/zendured.php');
        $cmd = 'nohup php ' . escapeshellarg($script) . ' ' . escapeshellarg(self::pidFile())
            . ' >> ' . escapeshellarg(log::getPathToLog(__CLASS__ . '_daemon')) . ' 2>&1 &';
        exec($cmd);
        for ($i = 0; $i < 20; $i++) {
            if (self::deamon_info()['state'] == 'ok') {
                return true;
            }
            usleep(500000);
        }
        log::add(__CLASS__, 'error', __('Impossible de démarrer le démon, voir le log zendure_daemon', __FILE__));
        return false;
    }

    public static function deamon_stop() {
        $pidFile = self::pidFile();
        if (!file_exists($pidFile)) {
            return;
        }
        $pid = (int) trim((string) file_get_contents($pidFile));
        // sudo : Jeedom peut avoir lancé le démon en root (cron système)
        if ($pid > 0) {
            exec(system::getCmdSudo() . 'kill ' . $pid . ' 2>/dev/null');
            for ($i = 0; $i < 10 && file_exists('/proc/' . $pid); $i++) {
                usleep(300000);
            }
            if (file_exists('/proc/' . $pid)) {
                exec(system::getCmdSudo() . 'kill -9 ' . $pid . ' 2>/dev/null');
            }
        }
        // démon orphelin (pid perdu) : on le cherche par son script. « [z] » : le motif ne correspond pas
        // à la ligne de commande du shell qui lance pkill (sinon pkill tuerait aussi ce shell)
        exec(system::getCmdSudo() . 'pkill -f ' . escapeshellarg('zendure/resources/[z]endured\.php') . ' 2>/dev/null');
        @unlink($pidFile);
    }

    /** Version lue dans plugin_info/info.json */
    public static function pluginVersion() {
        $info = json_decode((string) @file_get_contents(__DIR__ . '/../../plugin_info/info.json'), true);
        return (is_array($info) && isset($info['pluginVersion'])) ? $info['pluginVersion'] : '';
    }

    /**
     * Chien de garde MQTT (démon). Un appareil publie environ une fois par seconde ; s'il reste connecté mais muet plus
     * de « watchdog_mute » s (90), sa session est figée côté broker : on coupe 3 s sa seule adresse pour forcer une
     * reconnexion propre. Au plus une tentative toutes les 2 min, puis délai doublé jusqu'à 15 min. 0 = désactivé.
     */
    public static function watchdog($_eq) {
        $mute = (int) config::byKey('watchdog_mute', __CLASS__, 90);
        if ($mute <= 0 || $_eq->getConnection() != 'mqtt') {
            return;
        }
        $seen = (int) $_eq->getCache('lastSeen', 0);
        $strikes = (int) $_eq->getCache('wdStrikes', 0);
        if ($seen == 0 || time() - $seen < $mute) {
            if ($strikes > 0 && $seen > (int) $_eq->getCache('wdLast', 0)) {
                log::add(__CLASS__, 'info', $_eq->getHumanName() . ' : ' . __('de nouveau joignable après reconnexion forcée', __FILE__));
                $_eq->setCache('wdStrikes', 0);
            }
            return;
        }
        $wait = min(900, 120 * pow(2, max(0, $strikes - 1)));
        if (time() - (int) $_eq->getCache('wdLast', 0) < $wait) {
            return;
        }
        $_eq->setCache('wdLast', time());
        $_eq->setCache('wdStrikes', $strikes + 1);
        self::reconnect($_eq);
        log::add(__CLASS__, 'warning', $_eq->getHumanName() . ' : ' . sprintf(__('muet depuis %d s : reconnexion MQTT forcée (tentative %d)', __FILE__),
            time() - $seen, $strikes + 1));
    }

    /** Force un appareil MQTT à rouvrir sa session (coupure TCP de 3 s, en arrière-plan). */
    public static function reconnect($_eq) {
        if (!is_object($_eq) || $_eq->getEqType_name() != __CLASS__ || $_eq->getConnection() != 'mqtt') {
            return false;
        }
        $script = realpath(__DIR__ . '/../../resources/mqtt_reconnect.sh');
        $logFile = log::getPathToLog('zendure_watchdog');
        exec(system::getCmdSudo() . ' /bin/bash ' . escapeshellarg($script) . ' ' . escapeshellarg($_eq->getLogicalId())
            . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &');
        return true;
    }

    /* ---------------------------------------------------------- équipement */

    public function getModel() {
        $model = $this->getConfiguration('model');
        return isset(self::MODELS[$model]) ? $model : 'mqttgeneric';
    }

    public function getModelInfo($_key) {
        $m = self::MODELS[$this->getModel()];
        return isset($m[$_key]) ? $m[$_key] : null;
    }

    public function getConnection() {
        return $this->getModelInfo('connection');
    }

    public function isBattery() {
        return in_array($this->getConnection(), array('mqtt', 'http'));
    }

    public function preSave() {
        if (!isset(self::MODELS[$this->getConfiguration('model')])) {
            $this->setConfiguration('model', ($this->getLogicalId() != '' && $this->getConfiguration('ip') == '') ? 'mqttgeneric' : 'sf2400ac');
        }
        $conn = $this->getConnection();
        if ($conn == 'mqtt') {
            $this->setLogicalId(trim((string) $this->getLogicalId()));
            if (!preg_match('/^[A-Za-z0-9_-]{4,32}$/', $this->getLogicalId())) {
                throw new Exception(__('Le deviceId est obligatoire. Les appareils MQTT se créent automatiquement dès qu\'ils sont autorisés sur le broker.', __FILE__));
            }
        } else {
            $ip = trim((string) $this->getConfiguration('ip'));
            if ($ip != '' && !filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('/^[A-Za-z0-9.-]+$/', $ip)) {
                throw new Exception(__('Adresse IP invalide', __FILE__) . ' : ' . $ip);
            }
            $this->setConfiguration('ip', $ip);
            $port = (int) $this->getConfiguration('port', 80);
            $this->setConfiguration('port', ($port > 0 && $port < 65536) ? $port : 80);
        }
    }

    public function postSave() {
        if (!$this->isBattery()) {
            zendureMeter::createCommands($this);
            return;
        }
        $order = 0;
        foreach (array_merge(self::COMMON_INFOS, $this->getModelInfo('infos')) as $logicalId) {
            $this->createInfo($logicalId, $order++);
        }
        $order = 50;
        $actions = self::ACTIONS;
        if ($this->getConnection() == 'http') {
            $actions = array_merge($actions, self::HTTP_ACTIONS);
        }
        $noAc = (int) $this->getModelInfo('inputMax') == 0; // pas de charge depuis le réseau (Hub, AIO)
        $noDirect = $this->getConnection() == 'mqtt' && $this->getModelInfo('control') == 'none'; // ACE, modèle non choisi
        foreach ($actions as $logicalId => $def) {
            $cmd = $this->getCmd(null, $logicalId);
            if (($noAc && in_array($logicalId, array('setInputLimit', 'chargeGrid', 'setAcMode')))
                || ($noDirect && in_array($logicalId, array('chargeGrid', 'dischargeHome', 'stopPower')))) {
                if (is_object($cmd)) {
                    $cmd->remove(); // modèle changé : la commande n'a plus de sens
                }
                continue;
            }
            if (!is_object($cmd)) {
                $cmd = new zendureCmd();
                $cmd->setLogicalId($logicalId);
                $cmd->setName(__($def[0], __FILE__));
                $cmd->setIsVisible(1);
                $cmd->setOrder($order);
            }
            $cmd->setEqLogic_id($this->getId());
            $cmd->setType('action');
            $cmd->setSubType($def[1]);
            $cmd->setConfiguration('property', $def[2]);
            if ($def[4] !== null) {
                $cmd->setConfiguration('minValue', $def[4]);
                $cmd->setConfiguration('maxValue', is_string($def[5]) ? $this->getModelInfo($def[5]) : $def[5]);
            }
            if ($def[6] != '') {
                $cmd->setConfiguration('listValue', $def[6]);
            }
            if ($def[3] != '') {
                $info = $this->getCmd('info', $def[3]);
                if (is_object($info)) {
                    $cmd->setValue($info->getId());
                }
            }
            $cmd->save();
            $order++;
        }
    }

    /** Crée (si besoin) une commande info de ALL_INFOS. */
    public function createInfo($_logicalId, $_order = null) {
        if (!isset(self::ALL_INFOS[$_logicalId])) {
            return null;
        }
        $def = self::ALL_INFOS[$_logicalId];
        $cmd = $this->getCmd('info', $_logicalId);
        if (is_object($cmd)) {
            return $cmd;
        }
        $cmd = new zendureCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_logicalId);
        $cmd->setName(__($def[0], __FILE__));
        $cmd->setType('info');
        $cmd->setSubType($def[1]);
        $cmd->setUnite($def[2]);
        $cmd->setIsHistorized($def[5]);
        $cmd->setIsVisible(1);
        if ($def[3] !== null) {
            $cmd->setConfiguration('minValue', $def[3]);
            $cmd->setConfiguration('maxValue', $def[4]);
        }
        $cmd->setOrder($_order === null ? 30 + count($this->getCmd('info')) : $_order);
        $cmd->save();
        return $cmd;
    }

    /** Applique un rapport {properties: {...}, packData: [...]} (MQTT ou HTTP). */
    public function applyReport($_payload) {
        $this->setCache('lastSeen', time());
        $this->checkAndUpdateCmd('online', 1);
        $props = (isset($_payload['properties']) && is_array($_payload['properties'])) ? $_payload['properties'] : array();
        foreach ($props as $key => $value) {
            if (!isset(self::ALL_INFOS[$key]) || is_array($value) || $key == 'online') {
                continue;
            }
            if (!is_object($this->getCmd('info', $key))) {
                $this->createInfo($key);
            }
            $this->checkAndUpdateCmd($key, self::convert($key, $value));
        }
        if (isset($_payload['packData']) && is_array($_payload['packData'])) {
            foreach ($_payload['packData'] as $pack) {
                if (is_array($pack) && !empty($pack['sn'])) {
                    $this->updatePack((string) $pack['sn'], $pack);
                }
            }
        }
    }

    /** Crée au besoin puis met à jour les commandes d'une batterie. */
    public function updatePack($_sn, $_pack, $_converted = false) {
        $label = self::packModel($_sn) . ' ' . substr($_sn, -4);
        foreach (self::PACK_INFOS as $prop => $def) {
            if (!array_key_exists($prop, $_pack)) {
                continue;
            }
            $logicalId = 'pack_' . $_sn . '_' . $prop;
            $cmd = $this->getCmd('info', $logicalId);
            if (!is_object($cmd)) {
                $cmd = new zendureCmd();
                $cmd->setEqLogic_id($this->getId());
                $cmd->setLogicalId($logicalId);
                $cmd->setName($label . ' ' . __($def[0], __FILE__));
                $cmd->setType('info');
                $cmd->setSubType('numeric');
                $cmd->setUnite($def[1]);
                $cmd->setIsHistorized($def[2]);
                $cmd->setConfiguration('packSn', $_sn);
                $cmd->setOrder(100 + count($this->getCmd('info')));
                $cmd->save();
                log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . __('nouvelle batterie', __FILE__) . ' ' . $_sn . ' (' . $prop . ')');
            }
            $this->checkAndUpdateCmd($logicalId, $_converted ? $_pack[$prop] : self::convertPack($prop, $_pack[$prop]));
        }
    }

    /** Lecture complète : getAll en MQTT, GET /properties/report en HTTP, mesure pour un compteur. */
    public function requestAll() {
        $conn = $this->getConnection();
        if ($conn == 'http') {
            $this->pollHttp();
        } elseif ($conn == 'zmeter' || $conn == 'shelly') {
            zendureMeter::poll($this, true);
        } else {
            $this->sendMqtt('properties/read', array('properties' => array('getAll')));
        }
    }

    public function writeProperties($_properties) {
        if ($this->getConnection() == 'http') {
            if ($this->getConfiguration('smartMode', 1) == 1 && !isset($_properties['smartMode'])
                && count(array_intersect(array_keys($_properties), self::SMARTMODE_PROPS)) > 0) {
                $_properties['smartMode'] = 1;
            }
            if (zendureSdkMqtt::useMqttCommands($this)) {
                try {
                    $_properties = zendureSdkMqtt::publishProperties($this, $_properties);
                } catch (Exception $e) {
                    log::add(__CLASS__, 'warning', $this->getHumanName() . ' MQTT : ' . $e->getMessage() . ' -> HTTP');
                }
                if (count($_properties) == 0) {
                    return; // l'état revient par MQTT en quelques secondes
                }
            }
            log::add(__CLASS__, 'info', $this->getHumanName() . ' write : ' . json_encode($_properties));
            $sn = $this->getConfiguration('sn');
            if ($sn == '') {
                throw new Exception(__('Numéro de série inconnu : renseignez-le ou attendez la première lecture', __FILE__));
            }
            $this->httpRequest('POST', 'properties/write', array('properties' => $_properties, 'id' => $this->nextMessageId(), 'sn' => $sn));
            sleep(3); // l'appareil met quelques secondes à appliquer la consigne
            try {
                $this->pollHttp();
            } catch (Exception $e) {
                log::add(__CLASS__, 'debug', $this->getHumanName() . ' relecture : ' . $e->getMessage());
            }
            return;
        }
        log::add(__CLASS__, 'info', $this->getHumanName() . ' write : ' . json_encode($_properties));
        $this->sendMqtt('properties/write', array('properties' => $_properties));
    }

    /**
     * Pilotage de la puissance, comme Zendure-HA :
     *  - Hyper 2000 : function/invoke « deviceAutomation » autoModel 8 (programme 1 = charge réseau, 2 = injection), 0 = arrêt ;
     *  - Hub 1200 / 2000 : injection seulement (autoModelValue = puissance) ;
     *  - AIO 2400 : injection seulement ;
     *  - zenSDK : mode AC + limites + smartMode en une seule écriture.
     * @param string $_mode charge | discharge | stop
     */
    public function setPower($_mode, $_watts = 0) {
        $w = max(0, (int) round($_watts));
        log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . $_mode . ' ' . $w . ' W');
        if ($this->getConnection() == 'http') {
            switch ($_mode) {
                case 'charge':
                    $props = array('acMode' => 1, 'inputLimit' => $w, 'outputLimit' => 0, 'smartMode' => 1);
                    break;
                case 'discharge':
                    $props = array('acMode' => 2, 'outputLimit' => $w, 'inputLimit' => 0, 'smartMode' => 1);
                    break;
                default:
                    $props = array('acMode' => 2, 'outputLimit' => 0, 'inputLimit' => 0, 'smartMode' => 0);
            }
            $this->writeProperties($props);
            return;
        }
        $control = $this->getModelInfo('control');
        if ($control == 'none' || $control === null) {
            throw new Exception(__('Pilotage direct non disponible pour ce modèle : utilisez les limites d\'injection et de charge', __FILE__));
        }
        if ($_mode == 'charge' && $control != 'hyper') {
            throw new Exception(__('Ce modèle ne se charge pas depuis le réseau', __FILE__));
        }
        $stop = array('autoModelProgram' => 0, 'autoModelValue' => array('chargingType' => 0, 'chargingPower' => 0, 'freq' => 0, 'outPower' => 0),
            'msgType' => 1, 'autoModel' => 0);
        if ($control == 'hub') {
            $arg = $_mode == 'discharge' ? array('autoModelProgram' => 2, 'autoModelValue' => $w, 'msgType' => 1, 'autoModel' => 8)
                : array('autoModelProgram' => 0, 'autoModelValue' => 0, 'msgType' => 1, 'autoModel' => 0);
        } elseif ($_mode == 'charge') {
            $arg = array('autoModelProgram' => 1, 'autoModelValue' => array(
                'chargingType' => 1, 'price' => 2, 'chargingPower' => $w, 'prices' => array_fill(0, 24, 1), 'outPower' => 0, 'freq' => 0,
            ), 'msgType' => 1, 'autoModel' => 8);
        } elseif ($_mode == 'discharge') {
            $arg = array('autoModelProgram' => 2, 'autoModelValue' => array('chargingType' => 0, 'chargingPower' => 0, 'freq' => 0, 'outPower' => $w),
                'msgType' => 1, 'autoModel' => 8);
        } else {
            $arg = $stop;
        }
        $productKey = $this->getConfiguration('productKey');
        if ($productKey == '') {
            throw new Exception(__('productKey inconnu : attendez le premier rapport de l\'appareil', __FILE__));
        }
        self::sendMqttRaw($productKey, $this->getLogicalId(), 'function/invoke', array(
            'arguments' => array($arg),
            'function' => 'deviceAutomation',
            'deviceKey' => $this->getLogicalId(),
        ));
        try {
            $this->requestAll();
        } catch (Exception $e) {
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' getAll : ' . $e->getMessage());
        }
    }

    private function nextMessageId() {
        $id = ((int) $this->getCache('messageId', 0) % 100000) + 1;
        $this->setCache('messageId', $id);
        return $id;
    }

    private function sendMqtt($_kind, $_body) {
        $productKey = $this->getConfiguration('productKey');
        if ($productKey == '') {
            throw new Exception(__('productKey inconnu : attendez le premier rapport de l\'appareil', __FILE__));
        }
        self::sendMqttRaw($productKey, $this->getLogicalId(), $_kind, $_body);
    }

    /* ------------------------------------------------------------ HTTP zenSDK */

    public function pollHttp() {
        $json = $this->httpRequest('GET', 'properties/report');
        log::add(__CLASS__, 'debug', $this->getHumanName() . ' report : ' . json_encode($json));
        if (isset($json['sn']) && is_string($json['sn']) && $json['sn'] != '' && $this->getConfiguration('sn') != $json['sn']) {
            $this->setConfiguration('sn', $json['sn']);
            $this->save(true);
        }
        $this->applyReport($json);
    }

    /** Requête HTTP vers l'appareil (zenSDK, compteurs). $_path sans « / » initial, avec sa requête éventuelle. */
    public function httpRequest($_method, $_path, $_body = null) {
        $ip = $this->getConfiguration('ip');
        if ($ip == '') {
            throw new Exception(__('Adresse IP non renseignée', __FILE__));
        }
        $port = (int) $this->getConfiguration('port', 80);
        $url = 'http://' . $ip . ($port != 80 ? ':' . $port : '') . '/' . $_path;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=UTF-8'));
        if ($_method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($_body));
        }
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new Exception($url . ' : ' . $error);
        }
        if ($code >= 400) {
            throw new Exception($url . ' : HTTP ' . $code . ' ' . substr($response, 0, 200));
        }
        $json = json_decode($response, true);
        if ($_method == 'GET' && !is_array($json)) {
            throw new Exception($url . ' : ' . __('réponse non JSON', __FILE__));
        }
        return is_array($json) ? $json : array();
    }
}

class zendureCmd extends cmd {

    public function dontRemoveCmd() {
        return true;
    }

    public function execute($_options = array()) {
        if ($this->getType() != 'action') {
            return;
        }
        $eqLogic = $this->getEqLogic();
        if ($this->getLogicalId() == 'refresh') {
            $eqLogic->requestAll();
            return;
        }
        if ($this->getLogicalId() == 'stopPower') {
            $eqLogic->setPower('stop');
            return;
        }
        $property = $this->getConfiguration('property');
        $direct = in_array($this->getLogicalId(), array('chargeGrid', 'dischargeHome'));
        if ($property == '' && !$direct) {
            return;
        }
        switch ($this->getSubType()) {
            case 'slider':
                $value = isset($_options['slider']) ? $_options['slider'] : null;
                break;
            case 'select':
                $value = isset($_options['select']) ? $_options['select'] : null;
                break;
            default:
                $value = isset($_options['message']) ? $_options['message'] : null;
        }
        if ($value === null || $value === '' || !is_numeric($value)) {
            throw new Exception(__('Valeur invalide pour', __FILE__) . ' ' . $this->getHumanName() . ' : ' . json_encode($value));
        }
        $value = (float) $value;
        $min = $this->getConfiguration('minValue', '');
        $max = $this->getConfiguration('maxValue', '');
        if ($min !== '' && $value < (float) $min) {
            $value = (float) $min;
        }
        if ($max !== '' && $value > (float) $max) {
            $value = (float) $max;
        }
        if ($direct) {
            $eqLogic->setPower($this->getLogicalId() == 'chargeGrid' ? 'charge' : 'discharge', $value);
            return;
        }
        $raw = in_array($property, zendure::SCALED_X10) ? (int) round($value * 10) : (int) round($value);
        $eqLogic->writeProperties(array($property => $raw));
    }
}
