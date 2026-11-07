<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrator access is required.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Use POST to save payment instructions.']);
    exit;
}
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/session_account_guard.php';
if (!session_account_require_active_json($conn, ['admin'], ['success' => false, 'message' => 'Administrator access is required.'], ['success' => false, 'message' => 'Account status could not be verified.'], 403)) exit;

http_response_code(410);
echo json_encode(['success' => false, 'message' => 'Customer payment settings are no longer supported.']);
exit;
