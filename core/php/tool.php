<?php
/*
 * Téléchargement de l'outil Windows de redirection Bluetooth (resources/windows), réservé aux administrateurs
 * (le .htaccess de Jeedom interdit l'accès direct à /resources/).
 */
require_once __DIR__ . '/../../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) {
    http_response_code(403);
    echo 'Accès refusé';
    exit;
}
$file = realpath(__DIR__ . '/../../resources/windows/ZendureMqttTool.exe');
if ($file === false || !is_file($file)) {
    http_response_code(404);
    echo 'Outil absent de cette installation : téléchargez-le depuis la page GitHub du plugin (Releases).';
    exit;
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/vnd.microsoft.portable-executable');
header('Content-Disposition: attachment; filename="ZendureMqttTool.exe"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-store');
readfile($file);
