<?php
define('SEVILLA_SESSION_STATUS_CHECK', true);
require_once __DIR__ . '/../../includes/session_init.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, no-cache, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('Vary: Cookie');

$respond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    $respond(405, ['success' => false, 'reason' => 'method_not_allowed']);
}

if (($_SESSION['logged_in'] ?? false) !== true) {
    $respond(401, ['success' => false, 'reason' => 'signed_out']);
}

try {
    require_once __DIR__ . '/../../config/db_connect.php';
    require_once __DIR__ . '/../../includes/session_account_guard.php';
    $reason = null;
    $state = session_account_revalidation_state($conn, [], $reason);
} catch (Throwable $error) {
    http_response_code(503);
    $respond(503, ['success' => false, 'reason' => 'unavailable']);
}

if ($state === 'active') {
    $respond(200, ['success' => true]);
}
if ($state === 'unavailable') {
    $respond(503, ['success' => false, 'reason' => 'unavailable']);
}

$alert = session_account_invalid_alert((string)$reason);
session_policy_expire($alert['message'], $alert['title'], $alert['type']);
$respond(401, ['success' => false, 'reason' => $reason === 'version_changed' ? 'version_changed' : 'invalid']);
