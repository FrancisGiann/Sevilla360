<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

// ==========================================
// CSRF PROTECTION GUARD (JSON)
// ==========================================
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed. Unauthorized request.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['user_id']) || !isset($data['action'])) {
    echo json_encode(['success' => false, 'message' => 'Missing data']); exit;
}

$target_user_id = intval($data['user_id']);
$new_status = $data['action']; // Will be 'active' or 'suspended'

try {
    // Update the status in the database
    $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $target_user_id);
    $stmt->execute();

    // Log the action
    $log_action = ucfirst($new_status) === 'Suspended' ? "Suspended customer account ID: $target_user_id" : "Re-activated customer account ID: $target_user_id";
    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'User Management', ?, ?)");
    $audit->bind_param("iss", $_SESSION['user_id'], $log_action, $_SERVER['REMOTE_ADDR']);
    $audit->execute();

    echo json_encode(['success' => true, 'message' => "Account successfully $new_status!"]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>