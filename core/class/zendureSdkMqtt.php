<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Réception MQTT des appareils zenSDK (SolarFlow 2400 AC+…) configurés par HA.Mqtt.SetConfig.
 *
 * L'appareil publie au format « Home Assistant discovery » :
 *   homeassistant/<type>/<id>/config                 description (ignorée ici)
 *   Zendure/<type>/<sn>/<propriété>                  valeur (texte), ~ toutes les 6 s
 *   Zendure/sensor/<sn_batterie>/<sn_batterie>_<p>   valeurs des batteries
 *   .../<propriété>/availability                      online / offline
 * Valeurs déjà converties par l'appareil (%, W, K, libellés) : on les remet au format du plugin.
 */
class zendureSdkMqtt {

    /** Libellés publiés => valeur numérique du plugin */
    const ENUMS = array(
        'packState'    => array('idle' => 0, 'charging' => 1, 'discharging' => 2),
        'state'        => array('idle' => 0, 'charging' => 1, 'discharging' => 2),
        'acMode'       => array('input mode' => 1, 'output mode' => 2),
        'gridReverse'  => array('allow backflow' => 1, 'disallow backflow' => 2),
        'gridOffMode'  => array('normal mode' => 0, 'economic mode' => 1, 'off' => 2),
        'heatState'    => array('not_heating' => 0, 'heating' => 1),
        'pass'         => array('no' => 0, 'yes' => 1),
        'reverseState' => array('no' => 0, 'yes' => 1),
    );

    /** Arbre MQTT Manager sous la racine « Zendure » : [type][sn][propriété] = valeur */
    public static function handle($_tree) {
        if (!is_array($_tree)) {
            return;
        }
        foreach ($_tree as $component => $devices) {
            if (!is_array($devices)) {
                continue;
            }
            foreach ($devices as $sn => $props) {
                if (!is_array($props)) {
                    continue;
                }
                $sn = (string) $sn;
                $device = self::deviceBySn($sn);
                if (is_object($device)) {
                    self::applyDevice($device, $props);
                    continue;
                }
                $parent = self::parentOfPack($sn);
                if (is_object($parent)) {
                    self::applyPack($parent, $sn, $props);
                } else {
                    log::add('zendure', 'debug', 'MQTT zenSDK : numéro de série inconnu ' . $sn);
                }
            }
        }
    }

    private static function deviceBySn($_sn) {
        foreach (zendure::byType('zendure') as $eq) {
            if ($eq->getConnection() == 'http' && $eq->getConfiguration('sn') == $_sn) {
                return $eq;
            }
        }
        return null;
    }

    /** Batterie : équipement qui a déjà ses commandes, sinon le seul appareil zenSDK. */
    private static function parentOfPack($_sn) {
        $candidates = array();
        foreach (zendure::byType('zendure', true) as $eq) {
            if ($eq->getConnection() != 'http') {
                continue;
            }
            if (is_object($eq->getCmd('info', 'pack_' . $_sn . '_socLevel'))) {
                return $eq;
            }
            $candidates[] = $eq;
        }
        return (count($candidates) == 1) ? $candidates[0] : null;
    }

    public static function value($_prop, $_raw) {
        if (is_bool($_raw)) {
            return $_raw ? 1 : 0;
        }
        $s = strtolower(trim((string) $_raw));
        if (isset(self::ENUMS[$_prop]) && isset(self::ENUMS[$_prop][$s])) {
            return self::ENUMS[$_prop][$s];
        }
        if ($s === 'on') {
            return 1;
        }
        if ($s === 'off') {
            return 0;
        }
        return is_numeric($_raw) ? (float) $_raw : $_raw;
    }

    private static function applyDevice($_eq, $_props) {
        if ($_eq->getIsEnable() != 1) {
            return;
        }
        $seen = false;
        foreach ($_props as $prop => $raw) {
            if (is_array($raw)) {
                continue; // .../<prop>/availability
            }
            $prop = (string) $prop;
            if (!isset(zendure::ALL_INFOS[$prop]) || $prop == 'online') {
                continue;
            }
            $v = self::value($prop, $raw);
            if ($prop == 'hyperTmp' && is_numeric($v) && $v > 200) {
                $v = round($v - 273.15, 1); // kelvins -> °C
            }
            if (!is_numeric($v)) {
                continue;
            }
            if (!is_object($_eq->getCmd('info', $prop))) {
                $_eq->createInfo($prop);
            }
            $_eq->checkAndUpdateCmd($prop, $v);
            $seen = true;
        }
        if ($seen) {
            $_eq->setCache('lastSeen', time());
            $_eq->setCache('mqttSeen', time());
            $_eq->checkAndUpdateCmd('online', 1);
        }
    }

    private static function applyPack($_eq, $_sn, $_props) {
        $values = array();
        foreach ($_props as $key => $raw) {
            if (is_array($raw)) {
                continue;
            }
            $key = (string) $key;
            $prop = (strpos($key, $_sn . '_') === 0) ? substr($key, strlen($_sn) + 1) : $key;
            $v = self::value($prop, $raw);
            if (!is_numeric($v)) {
                continue;
            }
            switch ($prop) {
                case 'maxTemp':
                    $v = ($v > 200) ? round($v - 273.15, 1) : $v; // kelvins -> °C
                    break;
                case 'totalVol':
                    $v = ($v > 1000) ? round($v / 100, 2) : $v;
                    break;
                case 'batcur':
                    $v = zendure::convertPack('batcur', $v * 10); // A -> même calcul signé que le HTTP
                    break;
            }
            $values[$prop] = $v;
        }
        if (count($values) > 0) {
            $_eq->updatePack($_sn, $values, true);
        }
    }

    /**
     * Commandes publiées par l'appareil (HA discovery) : propriété => [type, valeurs]
     * number : valeur numérique ; select : libellé ; switch : ON / OFF.
     * L'ordre compte : smartMode d'abord (pas d'écriture en flash), puis le mode, puis les limites.
     */
    const COMMANDS = array(
        'smartMode'       => array('switch', null),
        'acMode'          => array('select', array(1 => 'Input mode', 2 => 'Output mode')),
        'outputLimit'     => array('number', null),
        'inputLimit'      => array('number', null),
        'socSet'          => array('number', 'x10'),
        'minSoc'          => array('number', 'x10'),
        'inverseMaxPower' => array('number', null),
        'gridReverse'     => array('select', array(1 => 'Allow backflow', 2 => 'Disallow backflow')),
        'gridOffMode'     => array('select', array(0 => 'Normal mode', 1 => 'Economic mode', 2 => 'OFF')),
        'lampSwitch'      => array('switch', null),
    );

    /** Faut-il envoyer les commandes de cet appareil zenSDK en MQTT ? */
    public static function useMqttCommands($_eq) {
        if (!class_exists('mqtt2') || $_eq->getConfiguration('sn') == '') {
            return false;
        }
        switch ($_eq->getConfiguration('cmdMode', 'auto')) {
            case 'mqtt':
                return true;
            case 'http':
                return false;
        }
        return self::isFresh($_eq); // automatique : MQTT si l'appareil publie
    }

    /**
     * Publie les propriétés sur Zendure/<type>/<sn>/<prop>/set.
     * Valeurs au format du plugin (socSet/minSoc x10, acMode 1/2…).
     * @return array propriétés non prises en charge en MQTT (à envoyer en HTTP)
     */
    public static function publishProperties($_eq, $_properties) {
        $sn = $_eq->getConfiguration('sn');
        $rest = $_properties;
        foreach (self::COMMANDS as $prop => $def) {
            if (!array_key_exists($prop, $_properties)) {
                continue;
            }
            $v = $_properties[$prop];
            switch ($def[0]) {
                case 'switch':
                    $payload = ((int) $v == 1) ? 'ON' : 'OFF';
                    break;
                case 'select':
                    if (!isset($def[1][(int) $v])) {
                        continue 2; // valeur inconnue : laissée au HTTP
                    }
                    $payload = $def[1][(int) $v];
                    break;
                default:
                    $payload = (string) (($def[1] == 'x10') ? round($v / 10) : (int) round($v));
            }
            mqtt2::publish('Zendure/' . $def[0] . '/' . $sn . '/' . $prop . '/set', $payload);
            unset($rest[$prop]);
        }
        log::add('zendure', 'info', $_eq->getHumanName() . ' MQTT : ' . json_encode(array_diff_key($_properties, $rest)));
        return $rest;
    }

    /** Données MQTT récentes : inutile d'interroger l'appareil en HTTP. */
    public static function isFresh($_eq, $_maxAge = 60) {
        return (time() - (int) $_eq->getCache('mqttSeen', 0)) < $_maxAge;
    }
}
