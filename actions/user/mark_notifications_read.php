<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST is required.']);
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? null;
if ($user_id < 1 || $role !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../config/db_connect.php';

$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
$session_csrf_token = $_SESSION['csrf_token'] ?? null;
if (!is_string($session_csrf_token) || !is_string($client_csrf_token) || $session_csrf_token === '' || !hash_equals($session_csrf_token, $client_csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
    exit;
}

$has_notif_id = array_key_exists('id', $_POST);
$notif_id = null;
if ($has_notif_id) {
    $raw_notif_id = $_POST['id'];
    if (!is_string($raw_notif_id) || !preg_match('/\A[1-9][0-9]*\z/', $raw_notif_id)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid notification id.']);
        exit;
    }
    $notif_id = filter_var($raw_notif_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($notif_id === false) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid notification id.']);
        exit;
    }
}

if ($has_notif_id) {
    $stmt = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND id = ? AND is_read = 0");
    $stmt->bind_param("ii", $user_id, $notif_id);
} else {
    $stmt = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
}
if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
$stmt->close();
$conn->close();
?>
