<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$media_id = filter_var($_GET['media_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$media_id) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid panorama is required.']);
    exit;
}

$stmt = $conn->prepare("SELECT h.id, h.type, h.title, h.description, h.position_x, h.position_y, h.position_z, h.target_pano_index, h.target_media_id, h.arrow_rotation,
    CASE
        WHEN h.type <> 'nav' THEN 1
        WHEN h.target_media_id IS NOT NULL THEN IF(target.id IS NOT NULL AND target.id <> h.media_id, 1, 0)
        WHEN legacy_target.id IS NOT NULL AND legacy_target.id <> h.media_id THEN 1
        ELSE 0
    END AS target_valid,
    COALESCE(target.id, legacy_target.id) AS resolved_target_media_id,
    COALESCE(target.file_name, legacy_target.file_name) AS target_file_name
    FROM showroom_hotspots h
    JOIN media_cms source ON source.id = h.media_id
    LEFT JOIN media_cms target ON target.id = h.target_media_id AND target.media_type = '360' AND target.slot_assignment = source.slot_assignment
    LEFT JOIN (
        SELECT id, file_name, slot_assignment, ROW_NUMBER() OVER (PARTITION BY slot_assignment ORDER BY id ASC) - 1 AS pano_index
        FROM media_cms WHERE media_type = '360'
    ) legacy_target ON h.target_media_id IS NULL AND legacy_target.slot_assignment = source.slot_assignment AND legacy_target.pano_index = h.target_pano_index
    WHERE h.media_id = ? ORDER BY h.id DESC");
$stmt->bind_param("i", $media_id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Hotspots could not be loaded.']);
    exit;
}
$hotspots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo json_encode(['success' => true, 'hotspots' => $hotspots]);
