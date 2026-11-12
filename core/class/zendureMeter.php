<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Compteurs lus en HTTP sur le réseau local.
 *
 * - Compteur Zendure (Smart Meter 1CT / 3CT / D0, zenSDK) : GET /properties/report. « total_power » (W) est la
 *   puissance au compteur ; les autres valeurs numériques sont créées telles quelles (champs bruts), le format
 *   n'étant pas documenté par Zendure.
 * - Shelly : détection de la génération par GET /shelly, puis
 *     Gen2+ triphasé (Pro 3EM, 3EM-63…) : /rpc/EM.GetStatus?id=0 et /rpc/EMData.GetStatus?id=0
 *     Gen2+ monophasé (Pro EM, EM Gen3…) : /rpc/EM1.GetStatus?id=0 et /rpc/EM1Data.GetStatus?id=0
 *     Gen2+ prise / relais avec mesure (Plus Plug, 1PM, PM Mini…) : /rpc/Switch.GetStatus?id=0 ou PM1.GetStatus
 *     Gen1 (3EM, EM) : /status, tableau « emeters »
 *   Sans mot de passe sur le Shelly. Puissance positive = soutirage, négative = injection (pinces côté réseau).
 */
class zendureMeter {

    /** logicalId => [nom, unité, historiser, ordre] */
    const CMDS = array(
        'power'        => array('Puissance réseau', 'W', 1, 1),
        'power_a'      => array('Puissance phase 1', 'W', 0, 2),
        'power_b'      => array('Puissance phase 2', 'W', 0, 3),
        'power_c'      => array('Puissance phase 3', 'W', 0, 4),
        'voltage_a'    => array('Tension phase 1', 'V', 0, 5),
        'voltage_b'    => array('Tension phase 2', 'V', 0, 6),
        'voltage_c'    => array('Tension phase 3', 'V', 0, 7),
        'energyImport' => array('Énergie soutirée', 'kWh', 1, 8),
        'energyExport' => array('Énergie injectée', 'kWh', 1, 9),
    );

    public static function createCommands($_eq) {
        $c = $_eq->getCmd('info', 'online');
        if (!is_object($c)) {
            $c = new zendureCmd();
            $c->setEqLogic_id($_eq->getId());
            $c->setLogicalId('online');
            $c->setName(__('En ligne', __FILE__));
            $c->setType('info');
            $c->setSubType('binary');
            $c->setIsHistorized(1);
            $c->setOrder(0);
            $c->save();
        }
        self::ensure($_eq, 'power');
        $r = $_eq->getCmd('action', 'refresh');
        if (!is_object($r)) {
            $r = new zendureCmd();
            $r->setEqLogic_id($_eq->getId());
            $r->setLogicalId('refresh');
            $r->setName(__('Rafraîchir', __FILE__));
            $r->setType('action');
            $r->setSubType('other');
            $r->setOrder(50);
            $r->save();
        }
    }

    private static function ensure($_eq, $_logical, $_name = null, $_unit = '') {
        $c = $_eq->getCmd('info', $_logical);
        if (is_object($c)) {
            return $c;
        }
        $def = isset(self::CMDS[$_logical]) ? self::CMDS[$_logical] : array($_name !== null ? $_name : $_logical, $_unit, 0, 60);
        $c = new zendureCmd();
        $c->setEqLogic_id($_eq->getId());
        $c->setLogicalId($_logical);
        $c->setName(__($def[0], __FILE__));
        $c->setType('info');
        $c->setSubType('numeric');
        $c->setUnite($def[1]);
        $c->setIsHistorized($def[2]);
        $c->setIsVisible(isset(self::CMDS[$_logical]) ? 1 : 0);
        $c->setOrder($def[3] + (isset(self::CMDS[$_logical]) ? 0 : count($_eq->getCmd('info'))));
        $c->save();
        return $c;
    }

    private static function set($_eq, $_logical, $_value) {
        if ($_value === null || !is_numeric($_value)) {
            return;
        }
        self::ensure($_eq, $_logical);
        $_eq->checkAndUpdateCmd($_logical, round((float) $_value, 3));
    }

    /**
     * Lecture du compteur (démon, toutes les « interval » s de l'équipement, 5 par défaut ; cron sinon).
     * @param bool $_force lecture immédiate (bouton Rafraîchir)
     */
    public static function poll($_eq, $_force = false) {
        $interval = max(1, (int) $_eq->getConfiguration('interval', 5));
        if (!$_force && microtime(true) - (float) $_eq->getCache('nextPoll', 0) < 0) {
            return;
        }
        $_eq->setCache('nextPoll', microtime(true) + $interval);
        if ($_eq->getConfiguration('ip') == '') {
            return;
        }
        try {
            if ($_eq->getConnection() == 'zmeter') {
                self::pollZendure($_eq);
            } else {
                self::pollShelly($_eq);
            }
            $_eq->setCache('lastSeen', time());
            $_eq->checkAndUpdateCmd('online', 1);
            $_eq->setCache('failures', 0);
        } catch (Exception $e) {
            $n = (int) $_eq->getCache('failures', 0) + 1;
            $_eq->setCache('failures', $n);
            if ($n == 3) { // une erreur isolée (Wi-Fi) n'est pas signalée
                log::add('zendure', 'warning', $_eq->getHumanName() . ' : ' . $e->getMessage());
            }
            throw $e;
        }
    }

    private static function pollZendure($_eq) {
        $j = $_eq->httpRequest('GET', 'properties/report');
        log::add('zendure', 'debug', $_eq->getHumanName() . ' report : ' . json_encode($j));
        $props = isset($j['properties']) && is_array($j['properties']) ? array_merge($j, $j['properties']) : $j;
        if (isset($props['total_power'])) {
            self::set($_eq, 'power', $props['total_power']);
        }
        foreach ($props as $k => $v) {
            if ($k == 'total_power' || is_array($v) || !is_numeric($v) || !preg_match('/^[A-Za-z0-9_]{1,40}$/', $k)) {
                continue;
            }
            $logical = 'raw_' . $k;
            if (!is_object($_eq->getCmd('info', $logical))) {
                self::ensure($_eq, $logical, $k, preg_match('/power/i', $k) ? 'W' : (preg_match('/volt/i', $k) ? 'V' : ''));
            }
            $_eq->checkAndUpdateCmd($logical, $v);
        }
    }

    /** Profil du Shelly (détecté une fois, gardé en configuration) : em | em1 | switch | pm1 | gen1. */
    private static function shellyProfile($_eq) {
        $p = $_eq->getConfiguration('shellyProfile', '');
        if ($p !== '') {
            return $p;
        }
        $info = $_eq->httpRequest('GET', 'shelly');
        $gen = isset($info['gen']) ? (int) $info['gen'] : 1;
        if ($gen < 2) {
            $p = 'gen1';
        } else {
            foreach (array('em' => 'rpc/EM.GetStatus?id=0', 'em1' => 'rpc/EM1.GetStatus?id=0', 'pm1' => 'rpc/PM1.GetStatus?id=0', 'switch' => 'rpc/Switch.GetStatus?id=0') as $k => $path) {
                try {
                    $_eq->httpRequest('GET', $path);
                    $p = $k;
                    break;
                } catch (Exception $e) {
                    continue;
                }
            }
        }
        if ($p === '') {
            throw new Exception(__('Shelly sans mesure de puissance reconnue', __FILE__));
        }
        $_eq->setConfiguration('shellyProfile', $p);
        $_eq->setConfiguration('shellyModel', isset($info['model']) ? $info['model'] : (isset($info['type']) ? $info['type'] : ''));
        $_eq->save(true);
        log::add('zendure', 'info', $_eq->getHumanName() . ' : Shelly ' . $_eq->getConfiguration('shellyModel') . ' (profil ' . $p . ')');
        return $p;
    }

    private static function pollShelly($_eq) {
        $p = self::shellyProfile($_eq);
        $energyDue = time() - (int) $_eq->getCache('energyTs', 0) >= 60; // index : une fois par minute
        switch ($p) {
            case 'em':
                $s = $_eq->httpRequest('GET', 'rpc/EM.GetStatus?id=0');
                self::set($_eq, 'power', isset($s['total_act_power']) ? $s['total_act_power'] : null);
                foreach (array('a', 'b', 'c') as $ph) {
                    self::set($_eq, 'power_' . $ph, isset($s[$ph . '_act_power']) ? $s[$ph . '_act_power'] : null);
                    self::set($_eq, 'voltage_' . $ph, isset($s[$ph . '_voltage']) ? $s[$ph . '_voltage'] : null);
                }
                if ($energyDue) {
                    $e = $_eq->httpRequest('GET', 'rpc/EMData.GetStatus?id=0');
                    self::set($_eq, 'energyImport', isset($e['total_act']) ? $e['total_act'] / 1000 : null);
                    self::set($_eq, 'energyExport', isset($e['total_act_ret']) ? $e['total_act_ret'] / 1000 : null);
                }
                break;
            case 'em1':
                $s = $_eq->httpRequest('GET', 'rpc/EM1.GetStatus?id=0');
                self::set($_eq, 'power', isset($s['act_power']) ? $s['act_power'] : null);
                self::set($_eq, 'voltage_a', isset($s['voltage']) ? $s['voltage'] : null);
                if ($energyDue) {
                    $e = $_eq->httpRequest('GET', 'rpc/EM1Data.GetStatus?id=0');
                    self::set($_eq, 'energyImport', isset($e['total_act_energy']) ? $e['total_act_energy'] / 1000 : null);
                    self::set($_eq, 'energyExport', isset($e['total_act_ret_energy']) ? $e['total_act_ret_energy'] / 1000 : null);
                }
                break;
            case 'pm1':
            case 'switch':
                $s = $_eq->httpRequest('GET', $p == 'pm1' ? 'rpc/PM1.GetStatus?id=0' : 'rpc/Switch.GetStatus?id=0');
                self::set($_eq, 'power', isset($s['apower']) ? $s['apower'] : null);
                self::set($_eq, 'voltage_a', isset($s['voltage']) ? $s['voltage'] : null);
                if (isset($s['aenergy']['total'])) {
                    self::set($_eq, 'energyImport', $s['aenergy']['total'] / 1000);
                }
                if (isset($s['ret_aenergy']['total'])) {
                    self::set($_eq, 'energyExport', $s['ret_aenergy']['total'] / 1000);
                }
                break;
            default: // Gen1
                $s = $_eq->httpRequest('GET', 'status');
                $m = isset($s['emeters']) && is_array($s['emeters']) ? $s['emeters'] : array();
                if (count($m) == 0) {
                    throw new Exception(__('Shelly Gen1 sans « emeters » : modèle non pris en charge', __FILE__));
                }
                $sum = 0;
                $imp = 0;
                $exp = 0;
                foreach (array_values($m) as $i => $e) {
                    $sum += isset($e['power']) ? (float) $e['power'] : 0;
                    $imp += isset($e['total']) ? (float) $e['total'] : 0;
                    $exp += isset($e['total_returned']) ? (float) $e['total_returned'] : 0;
                    if ($i < 3 && count($m) > 1) {
                        $ph = array('a', 'b', 'c')[$i];
                        self::set($_eq, 'power_' . $ph, isset($e['power']) ? $e['power'] : null);
                        self::set($_eq, 'voltage_' . $ph, isset($e['voltage']) ? $e['voltage'] : null);
                    }
                }
                self::set($_eq, 'power', $sum);
                self::set($_eq, 'energyImport', $imp / 1000);
                self::set($_eq, 'energyExport', $exp / 1000);
        }
        if ($energyDue) {
            $_eq->setCache('energyTs', time());
        }
    }
}
