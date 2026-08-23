<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = filter_var(is_array($data) ? ($data['id'] ?? null) : null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { http_response_code(422); echo json_encode(['success' => false, 'message' => 'A valid hotspot is required.']); exit; }

$stmt = $conn->prepare("DELETE FROM showroom_hotspots WHERE id = ?");
$stmt->bind_param("i", $id);
if (!$stmt->execute()) { echo json_encode(['success' => false, 'message' => 'Unable to delete hotspot.']); exit; }

echo json_encode(['success' => true]);
