<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST is required.']);
    exit;
}
if (!in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true) || (int)($_SESSION['user_id'] ?? 0) < 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrator or staff access is required.']);
    exit;
}
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !is_string($csrf) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
    exit;
}
$key = $_POST['key'] ?? '';
if (!is_string($key) || strlen($key) > 191 || !preg_match('/\A(?:booking:[1-9][0-9]*:new|cancellation:[1-9][0-9]*|reschedule:[1-9][0-9]*|payment-proof:[1-9][0-9]*:[1-9][0-9]*:[a-f0-9]{64}:[1-9][0-9]*)\z/', $key)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid notification key.']);
    exit;
}
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/admin_notifications.php';
try {
    if (!admin_notification_key_is_active($conn, $key)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This action is no longer active.']);
        exit;
    }
    $userId = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare('INSERT INTO admin_notification_reads (user_id, notification_key, read_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)');
    if (!$stmt) throw new RuntimeException('Unable to save notification state.');
    $stmt->bind_param('is', $userId, $key);
    if (!$stmt->execute()) throw new RuntimeException('Unable to save notification state.');
    $stmt->close();
    echo json_encode(['success' => true, 'key' => $key]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Notification state could not be saved.']);
}
