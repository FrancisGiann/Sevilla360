<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$media_id = intval($_GET['media_id'] ?? 0);

$stmt = $conn->prepare("SELECT id, type, title, description, position_x, position_y, position_z, target_pano_index, target_media_id FROM showroom_hotspots WHERE media_id = ? ORDER BY id DESC");
$stmt->bind_param("i", $media_id);
$stmt->execute();
$hotspots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo json_encode(['success' => true, 'hotspots' => $hotspots]);
