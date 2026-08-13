<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

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

$media_id = intval($data['media_id']);
$type = $data['type'] === 'nav' ? 'nav' : 'info';
$title = trim($data['title']);
$description = trim($data['description'] ?? '');
$x = floatval($data['x']);
$y = floatval($data['y']);
$z = floatval($data['z']);
$target_index = isset($data['target_pano_index']) && $data['target_pano_index'] !== null 
    ? intval($data['target_pano_index']) 
    : null;

if (empty($title) || empty($media_id)) {
    echo json_encode(['success' => false, 'message' => 'Missing title or media reference.']);
    exit;
}

try {
    $stmt = $conn->prepare("
        INSERT INTO showroom_hotspots (media_id, type, title, description, position_x, position_y, position_z, target_pano_index)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isssdddi", $media_id, $type, $title, $description, $x, $y, $z, $target_index);
    $stmt->execute();

    echo json_encode(['success' => true, 'id' => $conn->insert_id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}