<?php
declare(strict_types=1);
// Deliberately never include app config, .env, customer data, or notification services.
function pm_connect(): mysqli {
    $socket = getenv('SEVILLA_PM_SOCKET');
    $dir = is_string($socket) ? realpath(dirname($socket)) : false;
    if (!$dir || !preg_match('~\A/tmp/sevilla360-paymongo\.[A-Za-z0-9]+\z~D', $dir)
        || basename($socket) !== 'server.sock' || !is_file($dir . '/SYNTHETIC_ONLY')) {
        throw new RuntimeException('Use scripts/paymongo/local.sh; an isolated synthetic socket is required.');
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli('localhost', 'root', '', 'paymongo_prototype', 0, $socket);
    $db->set_charset('utf8mb4');
    $db->query("SET time_zone = '+08:00'");
    $db->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $db->query('SET SESSION innodb_lock_wait_timeout=5');
    return $db;
}
