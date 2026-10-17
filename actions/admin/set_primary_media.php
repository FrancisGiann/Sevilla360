<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');

function set_primary_media_response(array $payload, int $status_code = 200): void
{
    http_response_code($status_code);
    echo json_encode($payload);
}

final class SetPrimaryMediaException extends RuntimeException
{
    public function __construct(string $message, private int $statusCode = 500)
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}

function set_primary_media_bind_values(mysqli_stmt $stmt, string $types, array $values): void
{
    $arguments = [$types];
    foreach ($values as $index => $_value) $arguments[] = &$values[$index];
    if (!call_user_func_array([$stmt, 'bind_param'], $arguments)) {
        throw new RuntimeException('Could not bind media update values.');
    }
}

/** Resolve the gallery's media slots from its validated hotel identity, never from client-supplied aliases. */
function set_primary_media_hotel_gallery_slots(
    mysqli $conn,
    string $buildingName,
    string $roomTypeCode,
    string $roomType,
    string $canonicalGallerySlot
): array {
    $groups = [];
    if (hotel_group_schema_ready($conn)) {
        if ($roomTypeCode !== '') {
            try {
                $roomTypeCode = hotel_validate_active_room_type_code($conn, $roomTypeCode);
            } catch (InvalidArgumentException $error) {
                throw new SetPrimaryMediaException('The shared room gallery was not found.', 404);
            }
            $sql = "SELECT g.id AS room_group_id, g.building_name, g.room_type_code, g.legacy_room_type,
                           g.media_slot_key, COALESCE(t.display_name, g.legacy_room_type, 'Hotel Room') AS room_type,
                           h.room_type AS legacy_media_room_type
                    FROM hotel_room_groups g
                    INNER JOIN hotel_room_types t ON t.type_code = g.room_type_code AND t.active = 1
                    INNER JOIN hotel_rooms h ON h.room_group_id = g.id
                    INNER JOIN venues v ON v.id = h.venue_id AND v.category = 'Hotel Room' AND v.status <> 'Inactive'
                    WHERE g.building_name = ? AND g.room_type_code = ?";
            $types = 'ss';
            $values = [$buildingName, $roomTypeCode];
        } else {
            $sql = "SELECT g.id AS room_group_id, g.building_name, g.room_type_code, g.legacy_room_type,
                           g.media_slot_key, COALESCE(t.display_name, g.legacy_room_type, 'Hotel Room') AS room_type,
                           h.room_type AS legacy_media_room_type
                    FROM hotel_room_groups g
                    LEFT JOIN hotel_room_types t ON t.type_code = g.room_type_code
                    INNER JOIN hotel_rooms h ON h.room_group_id = g.id
                    INNER JOIN venues v ON v.id = h.venue_id AND v.category = 'Hotel Room' AND v.status <> 'Inactive'
                    WHERE g.building_name = ? AND (g.room_type_code IS NULL OR g.room_type_code = '')
                      AND g.legacy_room_type = ?";
            $types = 'ss';
            $values = [$buildingName, $roomType];
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException('Could not resolve hotel gallery.');
        set_primary_media_bind_values($stmt, $types, $values);
        if (!$stmt->execute()) throw new RuntimeException('Could not resolve hotel gallery.');
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $groupId = (int)$row['room_group_id'];
            if (!isset($groups[$groupId])) {
                $groups[$groupId] = [
                    'building_name' => (string)$row['building_name'],
                    'room_type_code' => (string)($row['room_type_code'] ?? ''),
                    'legacy_room_type' => (string)($row['legacy_room_type'] ?? ''),
                    'room_type' => (string)$row['room_type'],
                    'media_slot_key' => (string)($row['media_slot_key'] ?? ''),
                    'legacy_media_room_types' => [],
                ];
            }
            $legacyLabel = trim((string)($row['legacy_media_room_type'] ?? ''));
            if ($legacyLabel !== '') $groups[$groupId]['legacy_media_room_types'][] = $legacyLabel;
        }
        $stmt->close();
    } else {
        if ($roomTypeCode !== '') throw new SetPrimaryMediaException('The shared room gallery was not found.', 404);
        $stmt = $conn->prepare("SELECT DISTINCT v.name AS building_name, h.room_type
            FROM venues v INNER JOIN hotel_rooms h ON h.venue_id = v.id
            WHERE v.category = 'Hotel Room' AND v.status <> 'Inactive' AND v.name = ? AND h.room_type = ?");
        if (!$stmt) throw new RuntimeException('Could not resolve hotel gallery.');
        set_primary_media_bind_values($stmt, 'ss', [$buildingName, $roomType]);
        if (!$stmt->execute()) throw new RuntimeException('Could not resolve hotel gallery.');
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $groups[] = [
                'building_name' => (string)$row['building_name'],
                'room_type_code' => '',
                'legacy_room_type' => (string)$row['room_type'],
                'room_type' => (string)$row['room_type'],
                'legacy_media_room_types' => [(string)$row['room_type']],
            ];
        }
        $stmt->close();
    }

    $slotKeys = [];
    $identityFound = false;
    foreach ($groups as $group) {
        $canonicalSlot = hotel_room_group_canonical_media_slot_key($group);
        if ($canonicalSlot !== $canonicalGallerySlot
            || trim((string)$group['building_name']) !== $buildingName
            || trim((string)$group['room_type']) !== $roomType) continue;
        $identityFound = true;
        $slotKeys = array_merge($slotKeys, hotel_room_group_media_slot_keys($group));
    }
    $slotKeys = array_values(array_unique(array_filter($slotKeys, static fn(string $key): bool =>
        preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,99}\z/D', $key) === 1
    )));
    if (!$identityFound || !$slotKeys) {
        throw new SetPrimaryMediaException('The shared room gallery was not found.', 404);
    }
    return $slotKeys;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    set_primary_media_response(['success' => false, 'message' => 'Invalid request method.'], 405);
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    set_primary_media_response(['success' => false, 'message' => 'Unauthorized access.'], 401);
    exit;
}

// ==========================================
// CSRF PROTECTION GUARD (JSON)
// ==========================================
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$session_csrf_token = $_SESSION['csrf_token'] ?? null;
if (!is_string($session_csrf_token) || !is_string($client_csrf_token) || !hash_equals($session_csrf_token, $client_csrf_token)) {
    set_primary_media_response(['success' => false, 'message' => 'CSRF validation failed. Unauthorized request.'], 403);
    exit;
}

$rawData = file_get_contents('php://input');
$decodedData = null;
try {
    $decodedData = json_decode($rawData ?: '', false, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    set_primary_media_response(['success' => false, 'message' => 'Invalid JSON request.'], 400);
    exit;
}

if (!is_object($decodedData)) {
    set_primary_media_response(['success' => false, 'message' => 'A JSON object is required.'], 400);
    exit;
}

$data = get_object_vars($decodedData);
if (!array_key_exists('id', $data) || !array_key_exists('slot_assignment', $data)) {
    set_primary_media_response(['success' => false, 'message' => 'Missing data.'], 422);
    exit;
}

if (!is_int($data['id']) || $data['id'] < 1) {
    set_primary_media_response(['success' => false, 'message' => 'A valid media ID is required.'], 422);
    exit;
}

$media_id = $data['id'];
$slot = is_string($data['slot_assignment']) ? trim($data['slot_assignment']) : '';
if ($slot === '' || preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', $slot) !== 1) {
    set_primary_media_response(['success' => false, 'message' => 'A valid media slot is required.'], 422);
    exit;
}

$sharedGalleryFields = ['canonical_gallery_slot', 'building_name', 'room_type_code', 'room_type'];
$hasSharedGalleryField = count(array_intersect($sharedGalleryFields, array_keys($data))) > 0;
$sharedGallery = null;
if ($hasSharedGalleryField) {
    foreach ($sharedGalleryFields as $field) {
        if (!array_key_exists($field, $data) || !is_string($data[$field])) {
            set_primary_media_response(['success' => false, 'message' => 'A valid shared room gallery identity is required.'], 422);
            exit;
        }
    }
    $canonicalGallerySlot = trim($data['canonical_gallery_slot']);
    $buildingName = trim($data['building_name']);
    $roomTypeCode = trim($data['room_type_code']);
    $roomType = trim($data['room_type']);
    if (preg_match('/\Ahotel_type_[a-f0-9]{40}\z/D', $canonicalGallerySlot) !== 1
        || $buildingName === '' || mb_strlen($buildingName) > 150
        || $roomType === '' || mb_strlen($roomType) > 150
        || ($roomTypeCode !== '' && preg_match('/\A[a-z0-9_]{1,40}\z/D', $roomTypeCode) !== 1)) {
        set_primary_media_response(['success' => false, 'message' => 'A valid shared room gallery identity is required.'], 422);
        exit;
    }
    $sharedGallery = [
        'canonical_slot' => $canonicalGallerySlot,
        'building_name' => $buildingName,
        'room_type_code' => $roomTypeCode,
        'room_type' => $roomType,
    ];
}

$transaction_started = false;
$conn = null;
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once __DIR__ . '/../../config/db_connect.php';
    require_once __DIR__ . '/../../includes/session_account_guard.php';
    if (!session_account_require_active($conn, ['admin'], static function (string $state): never { set_primary_media_response(['success' => false, 'message' => $state === 'invalid' ? 'Unauthorized access.' : 'Account status could not be verified.'], $state === 'invalid' ? 401 : 503); })) exit;
    require_once __DIR__ . '/../../includes/hotel_rooms.php';
    require_once __DIR__ . '/../../includes/media_helper.php';
    if (!($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection unavailable.');
    }
    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Could not start transaction.');
    }
    $transaction_started = true;

    $optionGroupId = hotel_room_group_option_media_slot_group_id($slot);
    if ($optionGroupId !== null) {
        if (str_ends_with($slot, '_360') || !hotel_room_group_option_media_identity($conn, $optionGroupId, true)) {
            throw new SetPrimaryMediaException('The selected room-option photo gallery was not found.', 404);
        }
    }
    $primarySlots = $sharedGallery
        ? set_primary_media_hotel_gallery_slots(
            $conn,
            $sharedGallery['building_name'],
            $sharedGallery['room_type_code'],
            $sharedGallery['room_type'],
            $sharedGallery['canonical_slot']
        )
        : [$slot];
    if ($sharedGallery && !in_array($slot, $primarySlots, true)) {
        throw new SetPrimaryMediaException('Media was not found in the selected room gallery.', 404);
    }
    $slotPlaceholders = implode(',', array_fill(0, count($primarySlots), '?'));

    // Lock every row in the resolved gallery before changing its cover.
    $stmt_lock = $conn->prepare("SELECT id FROM media_cms WHERE slot_assignment IN ({$slotPlaceholders}) ORDER BY id FOR UPDATE");
    if (!$stmt_lock) throw new RuntimeException('Could not lock media gallery.');
    set_primary_media_bind_values($stmt_lock, str_repeat('s', count($primarySlots)), $primarySlots);
    if (!$stmt_lock->execute()) throw new RuntimeException('Could not lock media gallery.');
    $stmt_lock->close();

    $optionStandardFilter = $optionGroupId !== null ? " AND media_type = 'standard'" : '';
    $targetSql = "SELECT id FROM media_cms WHERE id = ? AND slot_assignment IN ({$slotPlaceholders}){$optionStandardFilter} LIMIT 1 FOR UPDATE";
    $stmt_target = $conn->prepare($targetSql);
    if (!$stmt_target) throw new RuntimeException('Could not verify media.');
    set_primary_media_bind_values($stmt_target, 'i' . str_repeat('s', count($primarySlots)), [$media_id, ...$primarySlots]);
    if (!$stmt_target->execute()) throw new RuntimeException('Could not verify media.');
    $target_result = $stmt_target->get_result();
    if (!$target_result || $target_result->num_rows !== 1) {
        throw new SetPrimaryMediaException('Media was not found in the selected slot.', 404);
    }

    // Clear all source slots for a canonical hotel gallery; legacy requests remain slot scoped.
    $stmt_reset = $conn->prepare("UPDATE media_cms SET is_primary = 0 WHERE slot_assignment IN ({$slotPlaceholders})");
    if (!$stmt_reset) throw new RuntimeException('Could not reset media primary state.');
    set_primary_media_bind_values($stmt_reset, str_repeat('s', count($primarySlots)), $primarySlots);
    if (!$stmt_reset->execute()) throw new RuntimeException('Could not reset media primary state.');

    // Set the verified photo as the one primary in the resolved gallery.
    $stmt_set = $conn->prepare("UPDATE media_cms SET is_primary = 1 WHERE id = ? AND slot_assignment IN ({$slotPlaceholders})");
    if (!$stmt_set) throw new RuntimeException('Could not set media primary state.');
    set_primary_media_bind_values($stmt_set, 'i' . str_repeat('s', count($primarySlots)), [$media_id, ...$primarySlots]);
    if (!$stmt_set->execute()) throw new RuntimeException('Could not set media primary state.');

    $stmt_verify = $conn->prepare("SELECT is_primary FROM media_cms WHERE id = ? AND slot_assignment IN ({$slotPlaceholders}) LIMIT 1");
    if (!$stmt_verify) throw new RuntimeException('Could not verify media primary state.');
    set_primary_media_bind_values($stmt_verify, 'i' . str_repeat('s', count($primarySlots)), [$media_id, ...$primarySlots]);
    if (!$stmt_verify->execute()) throw new RuntimeException('Could not verify media primary state.');
    $verify_result = $stmt_verify->get_result();
    $verified_row = $verify_result ? $verify_result->fetch_assoc() : null;
    if (!$verified_row || (int)$verified_row['is_primary'] !== 1) {
        throw new RuntimeException('Media primary state could not be verified.');
    }

    $stmt_count = $conn->prepare("SELECT COUNT(*) AS primary_count FROM media_cms WHERE slot_assignment IN ({$slotPlaceholders}) AND is_primary = 1");
    if (!$stmt_count) throw new RuntimeException('Could not verify slot primary state.');
    set_primary_media_bind_values($stmt_count, str_repeat('s', count($primarySlots)), $primarySlots);
    if (!$stmt_count->execute()) throw new RuntimeException('Could not verify slot primary state.');
    $count_result = $stmt_count->get_result();
    $count_row = $count_result ? $count_result->fetch_assoc() : null;
    if (!$count_row || (int)$count_row['primary_count'] !== 1) {
        throw new RuntimeException('Slot primary state could not be verified.');
    }

    if (!$conn->commit()) {
        throw new RuntimeException('Could not commit media primary state.');
    }
    $transaction_started = false;
    set_primary_media_response(['success' => true, 'message' => 'Primary image updated successfully!', 'primary_id' => $media_id]);
} catch (Throwable $e) {
    if ($transaction_started && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $rollback_error) {
            // Preserve the generic response if rollback itself fails.
        }
    }
    error_log('Primary media update failed: ' . get_class($e));
    $status_code = $e instanceof SetPrimaryMediaException ? $e->statusCode() : 500;
    $message = $e instanceof SetPrimaryMediaException ? $e->getMessage() : 'Primary image could not be updated.';
    set_primary_media_response(['success' => false, 'message' => $message], $status_code);
}
?>
