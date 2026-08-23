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
if (!is_array($data)) { http_response_code(422); echo json_encode(['success' => false, 'message' => 'Invalid JSON request.']); exit; }

$media_id = filter_var($data['media_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$hotspot_id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$type = (string)($data['type'] ?? '');
$title = trim((string)($data['title'] ?? ''));
$description = trim((string)($data['description'] ?? ''));
$x = filter_var($data['x'] ?? null, FILTER_VALIDATE_FLOAT);
$y = filter_var($data['y'] ?? null, FILTER_VALIDATE_FLOAT);
$z = filter_var($data['z'] ?? null, FILTER_VALIDATE_FLOAT);
$target_media_id = filter_var($data['target_media_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$target_index = filter_var($data['target_pano_index'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

if (!$media_id || !in_array($type, ['nav', 'info'], true) || $title === '' || strlen($title) > 150 || strlen($description) > 5000 || $x === false || $y === false || $z === false || !is_finite((float)$x) || !is_finite((float)$y) || !is_finite((float)$z) || max(abs((float)$x), abs((float)$y), abs((float)$z)) > 100000) {
    echo json_encode(['success' => false, 'message' => 'Invalid hotspot fields.']);
    exit;
}

try {
    $conn->begin_transaction();
    if ($hotspot_id) {
        $existing_hotspot = $conn->prepare('SELECT media_id FROM showroom_hotspots WHERE id = ? FOR UPDATE');
        $existing_hotspot->bind_param('i', $hotspot_id); $existing_hotspot->execute();
        $existing_row = $existing_hotspot->get_result()->fetch_assoc();
        if (!$existing_row) throw new RuntimeException('Hotspot not found.');
        if ((int)$existing_row['media_id'] !== (int)$media_id) throw new RuntimeException('A hotspot cannot be moved to another panorama during edit.');
    }
    $media = $conn->prepare("SELECT id, slot_assignment, media_type FROM media_cms WHERE id = ? LIMIT 1 FOR UPDATE");
    $media->bind_param('i', $media_id); $media->execute();
    $media_row = $media->get_result()->fetch_assoc();
    if (!$media_row || $media_row['media_type'] !== '360') throw new RuntimeException('Hotspots require a 360 panorama.');
    if ($type === 'nav') {
        if ($target_media_id) {
            $target = $conn->prepare("SELECT id FROM media_cms WHERE id = ? AND media_type = '360' AND slot_assignment = ? LIMIT 1 FOR UPDATE");
            $target->bind_param('is', $target_media_id, $media_row['slot_assignment']); $target->execute();
            if ($target->get_result()->num_rows !== 1 || $target_media_id === (int)$media_id) throw new RuntimeException('Navigation target must be another panorama in this venue.');
            $target_index = null;
        } elseif ($target_index !== false && $target_index !== null) {
            $target = $conn->prepare("SELECT id FROM media_cms WHERE media_type = '360' AND slot_assignment = ? ORDER BY id ASC LIMIT 1 OFFSET ? FOR UPDATE");
            $target->bind_param('si', $media_row['slot_assignment'], $target_index); $target->execute();
            $target_media_id = (int)($target->get_result()->fetch_assoc()['id'] ?? 0);
            if (!$target_media_id || $target_media_id === (int)$media_id) throw new RuntimeException('Invalid navigation target.');
        } else throw new RuntimeException('Navigation target is required.');
    } else { $target_media_id = null; $target_index = null; }
    if ($hotspot_id) {
        if ($type === 'nav' && $target_media_id && $target_index !== null) {
            $stmt = $conn->prepare("UPDATE showroom_hotspots SET media_id = ?, type = ?, title = ?, description = ?, position_x = ?, position_y = ?, position_z = ?, target_pano_index = ?, target_media_id = ? WHERE id = ?");
            $stmt->bind_param("isssdddiii", $media_id, $type, $title, $description, $x, $y, $z, $target_index, $target_media_id, $hotspot_id);
        } elseif ($type === 'nav' && $target_media_id) {
            $stmt = $conn->prepare("UPDATE showroom_hotspots SET media_id = ?, type = ?, title = ?, description = ?, position_x = ?, position_y = ?, position_z = ?, target_pano_index = NULL, target_media_id = ? WHERE id = ?");
            $stmt->bind_param("isssdddii", $media_id, $type, $title, $description, $x, $y, $z, $target_media_id, $hotspot_id);
        } else {
            $stmt = $conn->prepare("UPDATE showroom_hotspots SET media_id = ?, type = ?, title = ?, description = ?, position_x = ?, position_y = ?, position_z = ?, target_pano_index = NULL, target_media_id = NULL WHERE id = ?");
            $stmt->bind_param("isssdddi", $media_id, $type, $title, $description, $x, $y, $z, $hotspot_id);
        }
        if (!$stmt->execute() || $stmt->affected_rows < 0) throw new RuntimeException('Unable to update hotspot.');
        if (!$conn->commit()) throw new RuntimeException('Unable to commit hotspot update.');
        echo json_encode(['success' => true, 'id' => (int)$hotspot_id]);
    } else {
        if ($type === 'nav' && $target_media_id && $target_index !== null) {
            $stmt = $conn->prepare("INSERT INTO showroom_hotspots (media_id, type, title, description, position_x, position_y, position_z, target_pano_index, target_media_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssdddii", $media_id, $type, $title, $description, $x, $y, $z, $target_index, $target_media_id);
        } elseif ($type === 'nav' && $target_media_id) {
            $stmt = $conn->prepare("INSERT INTO showroom_hotspots (media_id, type, title, description, position_x, position_y, position_z, target_pano_index, target_media_id) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)");
            $stmt->bind_param("isssdddi", $media_id, $type, $title, $description, $x, $y, $z, $target_media_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO showroom_hotspots (media_id, type, title, description, position_x, position_y, position_z, target_pano_index, target_media_id) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL)");
            $stmt->bind_param("isssddd", $media_id, $type, $title, $description, $x, $y, $z);
        }
        if (!$stmt->execute()) throw new RuntimeException('Unable to save hotspot.');
        if (!$conn->commit()) throw new RuntimeException('Unable to commit hotspot.');
        echo json_encode(['success' => true, 'id' => $conn->insert_id]);
    }
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Hotspot save failed: ' . get_class($e));
    $message = $e->getMessage();
    $known = [
        'Hotspot not found.',
        'A hotspot cannot be moved to another panorama during edit.',
        'Hotspots require a 360 panorama.',
        'Navigation target must be another panorama in this venue.',
        'Invalid navigation target.',
        'Navigation target is required.'
    ];
    echo json_encode(['success' => false, 'message' => in_array($message, $known, true) ? $message : 'Unable to save the hotspot.']);
}
