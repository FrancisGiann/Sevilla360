<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/session_account_guard.php';
require_once __DIR__ . '/../../includes/request_context.php';
require_once __DIR__ . '/../../includes/media_helper.php';
require_once __DIR__ . '/../../includes/hotel_rooms.php';
require_once __DIR__ . '/../../includes/media_cleanup.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST is required.']);
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}
if (!session_account_require_active_json($conn, ['admin'], ['success' => false, 'message' => 'Unauthorized access.'], ['success' => false, 'message' => 'Account status could not be verified.'])) exit;

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
$data = is_array($data) ? $data : [];
try {
    $ids = media_cms_parse_delete_ids($data);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
    exit;
}

$action = is_string($data['action'] ?? null) ? $data['action'] : '';
if ($action !== '' && !in_array($action, ['preview_missing', 'remove_missing'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'The requested media action is invalid.']);
    exit;
}

if (in_array($action, ['preview_missing', 'remove_missing'], true)) {
    $transactionStarted = false;
    try {
        if (!$conn->begin_transaction()) throw new RuntimeException('The missing media could not be checked.');
        $transactionStarted = true;
        $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $idTypes = str_repeat('i', count($ids));
        $mediaStmt = $conn->prepare("SELECT id, file_path, slot_assignment, media_type FROM media_cms WHERE id IN ($idPlaceholders) ORDER BY id ASC FOR UPDATE");
        if (!$mediaStmt) throw new RuntimeException('The selected media records could not be loaded.');
        $mediaStmt->bind_param($idTypes, ...$ids);
        if (!$mediaStmt->execute()) throw new RuntimeException('The selected media records could not be loaded.');
        $rows = $mediaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$row) $row['source_status'] = media_cms_source_file_status((string)$row['file_path']);
        unset($row);
        media_cms_validate_delete_rows($rows, $ids, true);

        foreach ($rows as $row) {
            $optionGroupId = hotel_room_group_option_media_slot_group_id((string)($row['slot_assignment'] ?? ''));
            if ($optionGroupId !== null && !hotel_room_group_option_media_identity($conn, $optionGroupId, true)) {
                throw new RuntimeException('The room-option gallery is no longer available.');
            }
        }

        $columnCheck = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'showroom_hotspots' AND column_name = 'target_media_id'");
        if (!$columnCheck || !$columnCheck->execute()) throw new RuntimeException('Tour links could not be checked.');
        $hasStableTargetIds = (int)($columnCheck->get_result()->fetch_assoc()['c'] ?? 0) === 1;
        $slots = [];
        foreach ($rows as $row) {
            if (($row['media_type'] ?? '') === '360' && (string)($row['slot_assignment'] ?? '') !== '') {
                $slots[(string)$row['slot_assignment']] = (string)$row['slot_assignment'];
            }
        }
        $slotValues = array_values($slots);
        $slotPlaceholders = $slotValues ? implode(',', array_fill(0, count($slotValues), '?')) : '';
        $targetColumn = $hasStableTargetIds ? 'h.target_media_id' : 'NULL';
        $where = "h.media_id IN ($idPlaceholders)";
        $params = $ids;
        $types = $idTypes;
        if ($hasStableTargetIds) {
            $where .= " OR (h.type = 'nav' AND h.target_media_id IN ($idPlaceholders))";
            $params = array_merge($params, $ids);
            $types .= $idTypes;
        }
        if ($slotValues) {
            $where .= " OR (h.type = 'nav' AND " . ($hasStableTargetIds ? 'h.target_media_id IS NULL AND ' : '') . "source_media.slot_assignment IN ($slotPlaceholders))";
            $params = array_merge($params, $slotValues);
            $types .= str_repeat('s', count($slotValues));
        }
        $hotspotQuery = "SELECT h.id, h.media_id, h.type, $targetColumn AS target_media_id, h.target_pano_index, source_media.slot_assignment AS source_slot
                         FROM showroom_hotspots h LEFT JOIN media_cms source_media ON source_media.id = h.media_id
                         WHERE $where FOR UPDATE";
        $hotspotStmt = $conn->prepare($hotspotQuery);
        if (!$hotspotStmt) throw new RuntimeException('Tour links could not be checked.');
        $bindValues = $params;
        $bindReferences = [];
        foreach ($bindValues as &$bindValue) $bindReferences[] = &$bindValue;
        unset($bindValue);
        if (!$hotspotStmt->bind_param($types, ...$bindReferences) || !$hotspotStmt->execute()) {
            throw new RuntimeException('Tour links could not be checked.');
        }
        $hotspots = $hotspotStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $plan = media_cms_missing_cleanup_plan($ids, $rows, $hotspots, $hasStableTargetIds);

        if ($action === 'preview_missing') {
            if (!$conn->commit()) throw new RuntimeException('The missing media check could not be completed.');
            $transactionStarted = false;
            echo json_encode([
                'success' => true,
                'can_remove' => $plan['can_remove'],
                'plan' => $plan,
                'message' => $plan['can_remove'] ? '' : 'This slot has legacy navigation links that depend on panorama order. Migrate those links before removing missing records.',
            ]);
            exit;
        }

        if (!$plan['can_remove']) throw new RuntimeException('This slot has legacy navigation links that depend on panorama order. Migrate those links before removing missing records.');
        $expected = is_array($data['expected'] ?? null) ? $data['expected'] : [];
        foreach (['source_hotspots', 'incoming_hotspots', 'legacy_references'] as $key) {
            $expectedValue = filter_var($expected[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($expectedValue === false || $expectedValue !== (int)$plan[$key]) {
                throw new RuntimeException('Tour links changed after the preview. Review the cleanup details again before continuing.');
            }
        }
        $expectedFingerprint = $expected['fingerprint'] ?? null;
        if (!is_string($expectedFingerprint) || preg_match('/\A[a-f0-9]{64}\z/', $expectedFingerprint) !== 1
            || !hash_equals((string)$plan['fingerprint'], $expectedFingerprint)) {
            throw new RuntimeException('Tour links changed after the preview. Review the cleanup details again before continuing.');
        }
        $deleteHotspots = $hasStableTargetIds
            ? $conn->prepare("DELETE FROM showroom_hotspots WHERE media_id IN ($idPlaceholders) OR (type = 'nav' AND target_media_id IN ($idPlaceholders))")
            : $conn->prepare("DELETE FROM showroom_hotspots WHERE media_id IN ($idPlaceholders)");
        if (!$deleteHotspots) throw new RuntimeException('Related tour links could not be removed.');
        if ($hasStableTargetIds) {
            $deleteValues = array_merge($ids, $ids);
            $deleteReferences = [];
            foreach ($deleteValues as &$deleteValue) $deleteReferences[] = &$deleteValue;
            unset($deleteValue);
            $deleteHotspots->bind_param($idTypes . $idTypes, ...$deleteReferences);
        } else {
            $deleteHotspots->bind_param($idTypes, ...$ids);
        }
        if (!$deleteHotspots->execute()) throw new RuntimeException('Related tour links could not be removed.');

        foreach ($rows as $row) {
            if (media_cms_source_file_status((string)$row['file_path']) !== 'missing') {
                throw new RuntimeException('A selected source is present or cannot be safely checked. Refresh the gallery and choose only files that are missing.');
            }
        }
        $deleteMedia = $conn->prepare("DELETE FROM media_cms WHERE id IN ($idPlaceholders)");
        if (!$deleteMedia) throw new RuntimeException('Missing media records could not be removed.');
        $deleteMedia->bind_param($idTypes, ...$ids);
        if (!$deleteMedia->execute() || $deleteMedia->affected_rows !== count($ids)) {
            throw new RuntimeException('Missing media records changed during cleanup. Refresh the gallery and try again.');
        }
        if (isset($_SESSION['user_id'])) {
            $logUser = (int)$_SESSION['user_id'];
            $module = 'Media CMS';
            $actionText = 'Removed ' . count($ids) . ' missing media record(s); removed ' . ($plan['source_hotspots'] + $plan['incoming_hotspots']) . ' dependent hotspot(s).';
            $ip = request_client_ip();
            $auditStmt = $conn->prepare('INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, ?, ?, ?)');
            if (!$auditStmt) throw new RuntimeException('The cleanup audit record could not be saved.');
            $auditStmt->bind_param('isss', $logUser, $module, $actionText, $ip);
            if (!$auditStmt->execute()) throw new RuntimeException('The cleanup audit record could not be saved.');
        }
        if (!$conn->commit()) throw new RuntimeException('The cleanup transaction could not be committed.');
        $transactionStarted = false;
        echo json_encode(['success' => true, 'message' => count($ids) . ' missing media record(s) removed. No files or cached previews were deleted.']);
        exit;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            try { $conn->rollback(); } catch (Throwable $rollbackError) { error_log('Missing media cleanup rollback failed: ' . get_class($rollbackError)); }
        }
        error_log('Missing media cleanup failed: ' . get_class($error));
        $message = $error->getMessage();
        $known = [
            'One or more media records were not found. Refresh the gallery and try again.',
            'The selected media records changed. Refresh the gallery and try again.',
            'The Homepage Hero Banner cannot be deleted, it can only be replaced.',
            'A selected source is present or cannot be safely checked. Refresh the gallery and choose only files that are missing.',
            'The room-option gallery is no longer available.',
            'This slot has legacy navigation links that depend on panorama order. Migrate those links before removing missing records.',
            'Tour links changed after the preview. Review the cleanup details again before continuing.',
            'The selected source is present or cannot be safely checked. Refresh the gallery and choose only files that are missing.',
            'Missing media records changed during cleanup. Refresh the gallery and try again.'
        ];
        http_response_code(in_array($message, $known, true) ? 409 : 500);
        echo json_encode(['success' => false, 'message' => in_array($message, $known, true) ? $message : 'Missing media could not be removed. No files were deleted.']);
        exit;
    }
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
    
    if ($res->num_rows !== count($ids)) throw new Exception("Media not found.");

    $files_to_delete = [];
    while ($media = $res->fetch_assoc()) {
        // Prevent deletion of the ONE core system slot that breaks the website layout if missing
        if ($media['slot_assignment'] === 'home-hero') {
            throw new Exception("The Homepage Hero Banner cannot be deleted, it can only be replaced.");
        }
        $optionGroupId = hotel_room_group_option_media_slot_group_id((string)($media['slot_assignment'] ?? ''));
        if ($optionGroupId !== null && !hotel_room_group_option_media_identity($conn, $optionGroupId, true)) {
            throw new Exception('The room-option gallery is no longer available.');
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
        $media['_hero_path'] = media_cms_existing_hero_derivative_file_path((string)$media['file_path']);
        $media['_card_paths'] = array_values(array_filter(array_map(
            static fn(int $width): ?string => media_cms_existing_card_derivative_file_path((string)$media['file_path'], $width),
            MEDIA_CMS_CARD_DERIVATIVE_WIDTHS
        ), static fn(?string $path): bool => $path !== null));
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
        foreach (array_merge(
            [$media['_physical_path'] ?? null, $media['_thumbnail_path'] ?? null, $media['_hero_path'] ?? null],
            $media['_card_paths'] ?? []
        ) as $physicalPath) {
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
