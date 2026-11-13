<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Démon du plugin Zendure :
 *  - appareils zenSDK : GET /properties/report toutes les « refresh_interval » s (15 par défaut), sauf si des données
 *    MQTT récentes arrivent déjà ;
 *  - appareils MQTT : getAll s'ils se taisent plus de 60 s, et chien de garde (session figée => reconnexion) ;
 *  - compteurs (Zendure, Shelly) : lecture toutes les « interval » s de l'équipement (5 par défaut).
 * Lancé par zendure::deamon_start(), arrêté par zendure::deamon_stop().
 */
require_once __DIR__ . '/../../../core/php/core.inc.php';

$pidFile = isset($argv[1]) ? $argv[1] : jeedom::getTmpFolder('zendure') . '/deamon.pid';
file_put_contents($pidFile, getmypid());
log::add('zendure', 'info', 'Démon démarré (pid ' . getmypid() . ')');

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$running) {
        $running = false;
    });
}

$nextPoll = 0;
while ($running) {
    $now = time();
    $devicesDue = $now >= $nextPoll;
    if ($devicesDue) {
        $nextPoll = $now + max(5, (int) config::byKey('refresh_interval', 'zendure', 15));
    }
    foreach (zendure::byType('zendure', true) as $eqLogic) {
        try {
            $conn = $eqLogic->getConnection();
            if ($conn == 'zmeter' || $conn == 'shelly') {
                zendureMeter::poll($eqLogic); // chaque équipement a son propre intervalle
                continue;
            }
            if (!$devicesDue) {
                continue;
            }
            if ($conn == 'http' && $eqLogic->getConfiguration('ip') != '') {
                if (!zendureSdkMqtt::isFresh($eqLogic)) {
                    $eqLogic->pollHttp();
                }
            } elseif ($conn == 'mqtt') {
                if ($eqLogic->getConfiguration('productKey') != '' && config::byKey('poll', 'zendure', 1) == 1
                    && time() - (int) $eqLogic->getCache('lastSeen', 0) > 60) {
                    $eqLogic->requestAll(); // l'appareil publie de lui-même (~1/s) : getAll seulement s'il se tait
                }
                zendure::watchdog($eqLogic);
            }
        } catch (Exception $e) {
            log::add('zendure', 'debug', 'Démon : ' . $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
    sleep(1);
}
log::add('zendure', 'info', 'Démon arrêté');
// n'efface le fichier que s'il est encore le nôtre (un nouveau démon a pu démarrer entre-temps)
if ((int) @file_get_contents($pidFile) == getmypid()) {
    @unlink($pidFile);
}
