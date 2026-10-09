<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Use POST to update a customer account.']);
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$clientCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionCsrf = $_SESSION['csrf_token'] ?? '';
if (!is_string($clientCsrf) || !is_string($sessionCsrf) || $sessionCsrf === '' || !hash_equals($sessionCsrf, $clientCsrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
    exit;
}
require_once __DIR__ . '/../../includes/customer_suspension.php';
$actorUserId = customer_suspension_positive_id($_SESSION['user_id'] ?? null);
if ($actorUserId === null || ($_SESSION['logged_in'] ?? false) !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$rawBody = file_get_contents('php://input', false, null, 0, 4097);
if (!is_string($rawBody)) $rawBody = '';
$request = customer_suspension_parse_request('POST', $rawBody);
if (!$request['success']) {
    http_response_code($request['status']);
    echo json_encode(['success' => false, 'message' => $request['message']]);
    exit;
}

try {
    define('SEVILLA_CUSTOMER_SUSPENSION_ENDPOINT', true);
    require_once __DIR__ . '/../../config/db_connect.php';
    if (!customer_suspension_admin_is_active($conn, $actorUserId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
        exit;
    }
    require_once __DIR__ . '/../../includes/request_context.php';
    $result = customer_suspension_change_status($conn, $request['user_id'], $request['action'], $actorUserId, request_client_ip());
    http_response_code($result['status']);
    unset($result['status']);
    echo json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $exception) {
    error_log('Customer account status update failed: ' . get_class($exception));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Customer account status could not be updated.']);
}
