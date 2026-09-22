<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/request_context.php';
require_once __DIR__ . '/../../includes/media_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
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

// Support both single id (existing code) and bulk ids (new code)
$ids = [];
if (isset($data['ids']) && is_array($data['ids'])) {
    $ids = array_map('intval', $data['ids']);
} elseif (isset($data['id'])) {
    $ids = [intval($data['id'])];
}

if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No Media IDs provided.']);
    exit;
}

try {
    $conn->begin_transaction();

    // Dynamically build the IN (...) placeholders
    $inQuery = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    // Get the file paths first. Physical cleanup happens only after the
    // database transaction commits, so a failed DELETE never loses media.
    $stmt = $conn->prepare("SELECT id, file_path, slot_assignment, media_type FROM media_cms WHERE id IN ($inQuery) FOR UPDATE");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows === 0) throw new Exception("Media not found.");

    $files_to_delete = [];
    while ($media = $res->fetch_assoc()) {
        // Prevent deletion of the ONE core system slot that breaks the website layout if missing
        if ($media['slot_assignment'] === 'home-hero') {
            throw new Exception("The Homepage Hero Banner cannot be deleted, it can only be replaced.");
        }
        $files_to_delete[] = $media;
    }

    // A 360 panorama cannot be removed while a navigation hotspot points at
    // it.  After migration 012, stable target_media_id references are checked
    // exactly; legacy positional rows are conservatively blocked for the
    // affected slot so deletion cannot silently repoint their destination.
    $selectedSlots = [];
    foreach ($files_to_delete as $media) {
        if (($media['media_type'] ?? '') === '360' && ($media['slot_assignment'] ?? '') !== '' && isset($media['id'])) {
            $selectedSlots[(string)$media['slot_assignment']] = true;
        }
    }
    if ($selectedSlots) {
        $slotValues = array_keys($selectedSlots);
        $slotPlaceholders = implode(',', array_fill(0, count($slotValues), '?'));
        $slotTypes = str_repeat('s', count($slotValues));
        $columnCheck = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'showroom_hotspots' AND column_name = 'target_media_id'");
        $columnCheck->execute();
        $hasTargetMediaId = (int)($columnCheck->get_result()->fetch_assoc()['c'] ?? 0) === 1;
        if ($hasTargetMediaId) {
            $selectedIds = array_values(array_filter(array_map(static function ($media) {
                return ($media['media_type'] ?? '') === '360' ? (int)($media['id'] ?? 0) : 0;
            }, $files_to_delete)));
            if ($selectedIds) {
                $idPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
                $idTypes = str_repeat('i', count($selectedIds));
                $incoming = $conn->prepare("SELECT COUNT(*) AS c FROM showroom_hotspots WHERE type = 'nav' AND target_media_id IN ($idPlaceholders)");
                $incoming->bind_param($idTypes, ...$selectedIds);
                $incoming->execute();
                if ((int)($incoming->get_result()->fetch_assoc()['c'] ?? 0) > 0) {
                    throw new Exception('This panorama is targeted by a navigation hotspot and cannot be deleted.');
                }
            }
            // Rows not yet backfilled retain positional targets.  Do not allow
            // a slot-changing deletion until those references are migrated.
            $legacy = $conn->prepare("SELECT COUNT(*) AS c FROM showroom_hotspots h JOIN media_cms source_media ON source_media.id = h.media_id WHERE h.type = 'nav' AND h.target_media_id IS NULL AND source_media.slot_assignment IN ($slotPlaceholders)");
            $legacy->bind_param($slotTypes, ...$slotValues);
            $legacy->execute();
            if ((int)($legacy->get_result()->fetch_assoc()['c'] ?? 0) > 0) {
                throw new Exception('This panorama has legacy navigation references that must be migrated before deletion.');
            }
        } else {
            // Pre-migration databases have only positional navigation targets.
            // Blocking the slot is the only safe backwards-compatible choice.
            $legacy = $conn->prepare("SELECT COUNT(*) AS c FROM showroom_hotspots h JOIN media_cms source_media ON source_media.id = h.media_id WHERE h.type = 'nav' AND source_media.slot_assignment IN ($slotPlaceholders)");
            $legacy->bind_param($slotTypes, ...$slotValues);
            $legacy->execute();
            if ((int)($legacy->get_result()->fetch_assoc()['c'] ?? 0) > 0) {
                throw new Exception('This panorama is in a slot with navigation hotspots and cannot be deleted safely.');
            }
        }
    }

    // Resolve source and deterministic thumbnail paths before deleting the DB
    // rows. Both paths stay rooted under assets/uploads and reject symlinks.
    foreach (array_keys($files_to_delete) as $media_index) {
        $media = $files_to_delete[$media_index];
        $media['_physical_path'] = media_cms_upload_file_path((string)$media['file_path']);
        $media['_thumbnail_path'] = media_cms_thumbnail_file_path((string)$media['file_path']);
        $files_to_delete[$media_index] = $media;
    }

    // Delete from database
    $stmt_del = $conn->prepare("DELETE FROM media_cms WHERE id IN ($inQuery)");
    $stmt_del->bind_param($types, ...$ids);
    $stmt_del->execute();

    // AUDIT LOG
    if (isset($_SESSION['user_id'])) {
        $log_user = $_SESSION['user_id'];
        $log_module = 'Media CMS';
        
        $action_msg = count($ids) > 1 
            ? "Bulk deleted " . count($ids) . " media files."
            : "Deleted media file: " . basename($files_to_delete[0]['file_path']);

        $log_ip = request_client_ip();
        $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, ?, ?, ?)");
        $audit_stmt->bind_param("isss", $log_user, $log_module, $action_msg, $log_ip);
        $audit_stmt->execute();
    }

    if (!$conn->commit()) throw new Exception('Database transaction could not be committed.');
    $cleanupWarnings = [];
    $uploadRoot = media_cms_upload_root();
    foreach ($files_to_delete as $media) {
        foreach ([$media['_physical_path'] ?? null, $media['_thumbnail_path'] ?? null] as $physicalPath) {
            if ($physicalPath !== null && !media_cms_unlink_safe($physicalPath, $uploadRoot)) {
                $cleanupWarnings[] = basename((string)$media['file_path']);
            }
        }
    }
    echo json_encode(['success' => true, 'message' => count($ids) . ' file(s) deleted successfully.', 'cleanup_warnings' => $cleanupWarnings]);

} catch (Throwable $e) {
    $conn->rollback();
    error_log('Media deletion failed: ' . get_class($e));
    $message = $e->getMessage();
    $known = [
        'Media not found.',
        'The Homepage Hero Banner cannot be deleted, it can only be replaced.',
        'This panorama is targeted by a navigation hotspot and cannot be deleted.',
        'This panorama has legacy navigation references that must be migrated before deletion.',
        'This panorama is in a slot with navigation hotspots and cannot be deleted safely.',
        'Media file path is invalid.'
    ];
    echo json_encode(['success' => false, 'message' => in_array($message, $known, true) ? $message : 'Media could not be deleted. No files were removed.']);
}
?>
