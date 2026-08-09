<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
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

$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

if (!isset($data['id']) || !isset($data['slot_assignment'])) {
    echo json_encode(['success' => false, 'message' => 'Missing data.']);
    exit;
}

$media_id = intval($data['id']);
$slot = $data['slot_assignment'];

try {
    $conn->begin_transaction();

    // 1. Remove "Primary" status from ALL photos in this specific slot
    $stmt_reset = $conn->prepare("UPDATE media_cms SET is_primary = 0 WHERE slot_assignment = ?");
    $stmt_reset->bind_param("s", $slot);
    $stmt_reset->execute();

    // 2. Set "Primary" status to the clicked photo
    $stmt_set = $conn->prepare("UPDATE media_cms SET is_primary = 1 WHERE id = ?");
    $stmt_set->bind_param("i", $media_id);
    $stmt_set->execute();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Primary image updated successfully!']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>