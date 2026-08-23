<?php
session_start();
header('Content-Type: application/json');
require '../../config/db_connect.php';

function addon_bind_params(mysqli_stmt $statement, string $types, array $values): void
{
    $params = [$types];
    foreach ($values as $index => $value) $params[] = &$values[$index];
    call_user_func_array([$statement, 'bind_param'], $params);
}

function addon_lock_response(bool $success, string $message, int $held_count = 0, int $status = 200, ?int $expires_at = null): void
{
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'held_count' => $held_count,
        'expires_at' => $expires_at
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    addon_lock_response(false, 'POST is required.', 0, 405);
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['staff', 'admin'], true)) {
    addon_lock_response(false, 'Your staff session has expired. Please sign in again.', 0, 401);
}

$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    addon_lock_response(false, 'CSRF validation failed. Unauthorized request.', 0, 403);
}

$session_id = session_id();
$tracked_ids = array_values(array_filter(array_map('intval', (array)($_SESSION['walkin_addon_lock_ids'] ?? [])), static fn(int $id): bool => $id > 0));
$transaction_started = false;

try {
    $conn->begin_transaction();
    $transaction_started = true;
    if (!$conn->query('DELETE FROM booking_locks WHERE expires_at <= NOW()')) {
        throw new RuntimeException('Temporary room holds could not be refreshed.');
    }

    if ((string)($_POST['release_only'] ?? '') === '1') {
        if ($tracked_ids) {
            $placeholders = implode(',', array_fill(0, count($tracked_ids), '?'));
            $types = 's' . str_repeat('i', count($tracked_ids));
            $stmt_release = $conn->prepare("DELETE FROM booking_locks WHERE session_id = ? AND id IN ($placeholders)");
            addon_bind_params($stmt_release, $types, [$session_id, ...$tracked_ids]);
            if (!$stmt_release->execute()) throw new RuntimeException('Room holds could not be released.');
        }
        if (!$conn->commit()) throw new RuntimeException('Room holds could not be released.');
        $_SESSION['walkin_addon_lock_ids'] = [];
        addon_lock_response(true, 'Add-on room holds released.', 0);
    }

    $start_date = trim((string)($_POST['start_date'] ?? ''));
    $end_date = trim((string)($_POST['end_date'] ?? ''));
    $start_dt = DateTime::createFromFormat('!Y-m-d', $start_date);
    $end_dt = DateTime::createFromFormat('!Y-m-d', $end_date);
    $today = new DateTime('today');
    if (!$start_dt || !$end_dt || $start_dt->format('Y-m-d') !== $start_date || $end_dt->format('Y-m-d') !== $end_date || $start_dt < $today || $end_dt <= $start_dt || $start_dt->diff($end_dt)->days > 365) {
        throw new InvalidArgumentException('Please select a valid future hotel stay of at least one night.');
    }

    $groups_json = $_POST['groups'] ?? '';
    $groups = json_decode((string)$groups_json, true);
    if (!is_array($groups) || count($groups) < 1 || count($groups) > 20) {
        throw new InvalidArgumentException('Select between one and twenty hotel room groups.');
    }

    $normalized_groups = [];
    $total_quantity = 0;
    foreach ($groups as $group) {
        if (!is_array($group)) throw new InvalidArgumentException('Invalid hotel room group.');
        $building = trim((string)($group['building_name'] ?? ''));
        $room_type = trim((string)($group['room_type'] ?? ''));
        $quantity = filter_var($group['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
        if ($building === '' || $room_type === '' || !$quantity || strlen($building) > 120 || strlen($room_type) > 120 || preg_match('/[\x00-\x1F\x7F]/', $building . $room_type)) {
            throw new InvalidArgumentException('Invalid hotel room group details.');
        }
        $key = $building . "\0" . $room_type;
        if (isset($normalized_groups[$key])) throw new InvalidArgumentException('Duplicate hotel room groups are not allowed.');
        $normalized_groups[$key] = ['building_name' => $building, 'room_type' => $room_type, 'quantity' => $quantity];
        $total_quantity += $quantity;
        if ($total_quantity > 50) throw new InvalidArgumentException('The total temporary room hold is limited to 50 rooms.');
    }
    ksort($normalized_groups, SORT_STRING);

    // Replace only this session's previously allocated add-on rooms. The primary
    // venue lock is scalar-tracked separately and is never deleted here.
    if ($tracked_ids) {
        $placeholders = implode(',', array_fill(0, count($tracked_ids), '?'));
        $types = 's' . str_repeat('i', count($tracked_ids));
        $stmt_release = $conn->prepare("DELETE FROM booking_locks WHERE session_id = ? AND id IN ($placeholders)");
        addon_bind_params($stmt_release, $types, [$session_id, ...$tracked_ids]);
        if (!$stmt_release->execute()) throw new RuntimeException('Previous room holds could not be replaced.');
    }

    $stmt_allocate = $conn->prepare(<<<'SQL'
        SELECT v.id
        FROM venues v
        INNER JOIN hotel_rooms h ON h.venue_id = v.id
        WHERE v.name = ?
          AND h.room_type = ?
          AND v.status = 'Available'
          AND v.id NOT IN (
              SELECT b.venue_id FROM bookings b
              WHERE b.booking_status IN ('Pending', 'Confirmed', 'Completed')
                AND b.source <> 'Maintenance'
                AND (b.start_date < ? AND b.end_date > ?)
          )
          AND v.id NOT IN (
            SELECT br.venue_id FROM booking_rooms br
              INNER JOIN bookings b2 ON b2.id = br.booking_id
              INNER JOIN venues parent_v ON parent_v.id = b2.venue_id
              WHERE b2.booking_status IN ('Pending', 'Confirmed', 'Completed')
                AND NOT (b2.booking_status = 'Pending' AND parent_v.category = 'Event Hall')
                AND b2.source <> 'Maintenance'
                AND (br.start_date < ? AND br.end_date > ?)
          )
          AND v.id NOT IN (
              SELECT m.venue_id FROM maintenance m
              WHERE m.is_blocking = 1
                AND (m.status = 'Scheduled' OR m.status IS NULL)
                AND (m.start_date <= ? AND m.end_date >= ?)
          )
          AND v.id NOT IN (
              SELECT bl.venue_id FROM booking_locks bl
              WHERE bl.session_id <> ?
                AND bl.expires_at > NOW()
                AND (bl.start_date < ? AND bl.end_date > ?)
          )
        ORDER BY v.id
        LIMIT ? FOR UPDATE
    SQL);
    $stmt_insert = $conn->prepare('INSERT INTO booking_locks (venue_id, session_id, source, start_date, end_date, expires_at) VALUES (?, ?, ?, ?, ?, ?)');
    $source = 'walkin';
    $expires_at = date('Y-m-d H:i:s', strtotime('+60 minutes'));
    $new_lock_ids = [];

    foreach ($normalized_groups as $group) {
        $building = $group['building_name'];
        $room_type = $group['room_type'];
        $quantity = $group['quantity'];
        $stmt_allocate->bind_param('sssssssssssi', $building, $room_type, $end_date, $start_date, $end_date, $start_date, $end_date, $start_date, $session_id, $end_date, $start_date, $quantity);
        if (!$stmt_allocate->execute()) throw new RuntimeException('Room availability could not be checked.');
        $result = $stmt_allocate->get_result();
        if ($result->num_rows < $quantity) {
            throw new RuntimeException("Not enough inventory is available for {$building} - {$room_type}.");
        }
        while ($room = $result->fetch_assoc()) {
            $venue_id = (int)$room['id'];
            $stmt_insert->bind_param('isssss', $venue_id, $session_id, $source, $start_date, $end_date, $expires_at);
            if (!$stmt_insert->execute()) throw new RuntimeException('Room holds could not be created.');
            $new_lock_ids[] = (int)$conn->insert_id;
        }
    }

    if (!$conn->commit()) throw new RuntimeException('Room holds could not be committed.');
    $_SESSION['walkin_addon_lock_ids'] = $new_lock_ids;
    addon_lock_response(true, 'Selected hotel rooms are temporarily held for 60 minutes.', count($new_lock_ids), 200, strtotime($expires_at));
} catch (Throwable $error) {
    if ($transaction_started) $conn->rollback();
    addon_lock_response(false, $error instanceof InvalidArgumentException ? $error->getMessage() : 'The selected hotel rooms could not be temporarily held. Please try again.', 0, $error instanceof InvalidArgumentException ? 422 : 409);
}
