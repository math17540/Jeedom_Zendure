<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

function zendure_install() {
    zendure::registerTopics();
    zendure::cron15(); // première recherche mDNS
}

function zendure_update() {
    zendure::registerTopics();
    foreach (eqLogic::byType('zendure') as $eqLogic) {
        try {
            $eqLogic->save(); // recrée les commandes manquantes
        } catch (Exception $e) {
            log::add('zendure', 'warning', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
}

function zendure_remove() {
    if (class_exists('mqtt2')) {
        mqtt2::removePluginTopicByPlugin('zendure');
    }
}
