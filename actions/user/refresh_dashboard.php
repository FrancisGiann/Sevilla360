<?php
define('SEVILLA_CUSTOMER_DASHBOARD_REFRESH', true);
require_once __DIR__ . '/../../includes/session_init.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, no-cache, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('Vary: Cookie');
ini_set('display_errors', '0');

$respond = static function (int $status, string $message): never {
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    $respond(405, 'Dashboard updates are read-only.');
}

$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (($_SESSION['logged_in'] ?? false) !== true || $userId === false || ($_SESSION['role'] ?? '') !== 'customer') {
    $respond(401, 'Your customer session is no longer available. Sign in again to continue.');
}

$clientToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !is_string($clientToken) || !hash_equals((string)$_SESSION['csrf_token'], $clientToken)) {
    $respond(403, 'Your dashboard session changed. Refresh the page and sign in again if needed.');
}

$requestedPage = filter_var($_GET['booking_page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
if ($requestedPage === false) {
    $respond(422, 'The requested booking page is invalid.');
}

try {
    require_once __DIR__ . '/../../config/db_connect.php';
    $accountStatement = $conn->prepare('SELECT role, status FROM users WHERE id = ? LIMIT 1');
    if (!$accountStatement) $respond(503, 'Dashboard updates are temporarily unavailable.');
    $accountStatement->bind_param('i', $userId);
    if (!$accountStatement->execute()) {
        $accountStatement->close();
        $respond(503, 'Dashboard updates are temporarily unavailable.');
    }
    $account = $accountStatement->get_result()->fetch_assoc();
    $accountStatement->close();
    if (!$account || $account['role'] !== 'customer' || strcasecmp((string)$account['status'], 'active') !== 0) {
        session_policy_expire('Your customer account is unavailable. Please sign in again or contact support.', 'Account unavailable', 'error');
        $respond(403, 'Your customer account is unavailable. Sign in again or contact support.');
    }

    // The existing dashboard performs the customer-owned booking queries and renders
    // every status, amount, and action from the same SSR partials used on first load.
    chdir(dirname(__DIR__, 2));
    $responseBufferBaseLevel = ob_get_level();
    ob_start();
    require __DIR__ . '/../../user_dashboard.php';
    $responseBody = ob_get_clean();
    $decoded = json_decode((string)$responseBody, true);
    if (!is_array($decoded)) {
        error_log('Customer dashboard refresh returned an invalid internal response.');
        $respond(500, 'Dashboard updates are temporarily unavailable.');
    }
    echo $responseBody;
} catch (Throwable $error) {
    // Discard only buffers opened by this response renderer. A hosting/runtime
    // buffer belongs to the caller and must not be drained by this endpoint.
    while (isset($responseBufferBaseLevel) && ob_get_level() > $responseBufferBaseLevel) {
        ob_end_clean();
    }
    error_log('Customer dashboard refresh failed: ' . get_class($error));
    $respond(500, 'Dashboard updates are temporarily unavailable.');
}
