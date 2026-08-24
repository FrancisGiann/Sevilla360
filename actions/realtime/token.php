<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../includes/realtime.php';
require_once __DIR__ . '/../../config/db_connect.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$role = (string)($_SESSION['role'] ?? '');
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (empty($_SESSION['logged_in']) || $user_id < 1 || !in_array($role, ['customer', 'staff', 'admin'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'enabled' => false, 'message' => 'Authentication required.']);
    exit;
}

$account_stmt = $conn->prepare('SELECT u.role, u.status AS user_status, s.status AS staff_status FROM users u LEFT JOIN staff s ON s.user_id = u.id WHERE u.id = ? LIMIT 1');
$account_stmt->bind_param('i', $user_id);
$account_stmt->execute();
$account = $account_stmt->get_result()->fetch_assoc();
$current_status = in_array((string)($account['role'] ?? ''), ['staff', 'admin'], true) ? ($account['staff_status'] ?? '') : ($account['user_status'] ?? '');
if (!$account || (string)$account['role'] !== $role || strcasecmp((string)$current_status, 'active') !== 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'enabled' => false, 'message' => 'Account unavailable.']);
    exit;
}

if (!realtime_is_configured()) {
    echo json_encode(['success' => true, 'enabled' => false]);
    exit;
}

try {
    echo json_encode([
        'success' => true,
        'enabled' => true,
        'token' => realtime_issue_token($user_id, $role),
        'ws_url' => realtime_env('REALTIME_WS_URL'),
        // Derived entirely from the authenticated session; the browser never
        // chooses a tenant/channel value.
        'channel' => $role === 'customer' ? 'customer:' . $user_id : 'admin',
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Realtime token issuance failed: ' . get_class($error));
    http_response_code(503);
    echo json_encode(['success' => false, 'enabled' => false, 'message' => 'Realtime service unavailable.']);
}
