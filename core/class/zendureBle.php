<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Bluetooth de la box Jeedom : recherche des appareils Zendure « anciens » et redirection vers le broker local.
 * Facultatif : nécessite un adaptateur Bluetooth sur la box, à portée de l'appareil, et l'installation
 * (bouton de la configuration du plugin : BlueZ + Python + bleak dans resources/ble/venv).
 * Sans Bluetooth sur la box, utiliser l'outil Windows fourni avec le plugin.
 */
class zendureBle {

    private static function dir() {
        return realpath(__DIR__ . '/../../resources/ble');
    }

    private static function python() {
        return self::dir() . '/venv/bin/python3';
    }

    private static function progressFile() {
        return jeedom::getTmpFolder('zendure') . '/ble_progress';
    }

    /** État : adaptateur présent, outils installés, installation en cours. */
    public static function status() {
        $adapters = glob('/sys/class/bluetooth/hci*');
        $installed = is_file(self::python()) && trim((string) shell_exec(escapeshellarg(self::python()) . ' -c "import bleak; print(1)" 2>/dev/null')) == '1';
        $progress = is_file(self::progressFile()) ? (int) trim((string) file_get_contents(self::progressFile())) : null;
        return array('adapter' => is_array($adapters) && count($adapters) > 0, 'adapters' => is_array($adapters) ? array_map('basename', $adapters) : array(),
            'installed' => $installed, 'installing' => $progress !== null && $progress < 100, 'progress' => $progress);
    }

    /** Lance l'installation en arrière-plan (journal zendure_ble). */
    public static function install() {
        $script = realpath(__DIR__ . '/../../resources/install_ble.sh');
        file_put_contents(self::progressFile(), '0');
        exec(system::getCmdSudo() . ' /bin/bash ' . escapeshellarg($script) . ' ' . escapeshellarg(self::progressFile())
            . ' >> ' . escapeshellarg(log::getPathToLog('zendure_ble')) . ' 2>&1 &');
        return true;
    }

    /**
     * Exécute le script Bluetooth. $_stdin : ligne transmise sur l'entrée standard (mot de passe Wi-Fi), jamais journalisée.
     * Lancé avec sudo : l'accès à BlueZ (D-Bus) est souvent réservé au groupe bluetooth.
     */
    private static function run($_args, $_stdin = null, $_timeout = 60) {
        $st = self::status();
        if (!$st['adapter']) {
            throw new Exception(__('Aucun adaptateur Bluetooth sur la box Jeedom : utilisez l\'outil Windows', __FILE__));
        }
        if (!$st['installed']) {
            throw new Exception(__('Bluetooth non installé : cliquez sur « Installer le Bluetooth » dans la configuration du plugin', __FILE__));
        }
        $cmd = system::getCmdSudo() . ' timeout ' . (int) $_timeout . ' ' . escapeshellarg(self::python()) . ' '
            . escapeshellarg(self::dir() . '/zendure_ble.py');
        foreach ($_args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $proc = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($proc)) {
            throw new Exception(__('Impossible de lancer le script Bluetooth', __FILE__));
        }
        fwrite($pipes[0], $_stdin !== null ? $_stdin . "\n" : "\n");
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $lines = array_filter(explode("\n", trim((string) $out)));
        $j = json_decode((string) end($lines), true);
        if (!is_array($j)) {
            log::add('zendure', 'warning', 'Bluetooth : ' . trim($err . ' ' . $out));
            throw new Exception(__('Réponse du script Bluetooth illisible, voir le log zendure', __FILE__));
        }
        if (empty($j['ok'])) {
            throw new Exception('Bluetooth : ' . (isset($j['error']) ? $j['error'] : '?'));
        }
        return $j;
    }

    public static function scan() {
        $j = self::run(array('scan', '--timeout', '10'), null, 40);
        return $j['devices'];
    }

    /**
     * Redirige un appareil vers le broker (ou le cloud si $_broker = 'cloud').
     * Le mot de passe Wi-Fi n'est ni journalisé ni enregistré.
     */
    public static function setBroker($_address, $_broker, $_ssid, $_password) {
        if (!preg_match('/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/', $_address)) {
            throw new Exception(__('Adresse Bluetooth invalide', __FILE__));
        }
        if (trim($_ssid) === '' || $_password === '') {
            throw new Exception(__('SSID et mot de passe Wi-Fi obligatoires (réseau 2,4 GHz)', __FILE__));
        }
        $args = $_broker == 'cloud' ? array('cloud', '--address', $_address, '--ssid', $_ssid)
            : array('setbroker', '--address', $_address, '--ssid', $_ssid, '--broker', $_broker);
        log::add('zendure', 'info', 'Bluetooth : ' . $_address . ' -> ' . ($_broker == 'cloud' ? 'cloud Zendure' : $_broker) . ' (Wi-Fi ' . $_ssid . ')');
        return self::run($args, $_password, 90);
    }

    /** IPv4 de la box Jeedom sur le réseau local, proposée comme broker. */
    public static function localIp() {
        // adresses réellement présentes sur la box (l'adresse interne réglée dans Jeedom peut être périmée)
        $local = array_values(array_filter(preg_split('/\s+/', trim((string) shell_exec('hostname -I 2>/dev/null'))), function ($a) {
            return filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && strpos($a, '172.17.') !== 0 && strpos($a, '169.254.') !== 0;
        }));
        $ip = network::getNetworkAccess('internal', 'ip');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && (count($local) == 0 || in_array($ip, $local))) {
            return $ip;
        }
        return count($local) ? $local[0] : '';
    }
}
