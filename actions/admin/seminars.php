<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/seminars.php';
require_once __DIR__ . '/../../includes/seminar_payments.php';

header('Content-Type: application/json; charset=UTF-8');
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
    http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized access.']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
        http_response_code(403); echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']); exit;
    }
}

function seminar_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) throw new InvalidArgumentException('Invalid request data.');
    return $data;
}

function seminar_id(mixed $value, string $label = 'seminar'): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new InvalidArgumentException("Choose a valid {$label}.");
    return (int)$id;
}

function seminar_load(int $seminarId): array
{
    global $conn;
    $stmt = $conn->prepare('SELECT s.*, v.name AS hall_name FROM seminars s JOIN venues v ON v.id=s.hall_venue_id WHERE s.id=?');
    $stmt->bind_param('i', $seminarId); $stmt->execute();
    $seminar = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$seminar) throw new InvalidArgumentException('Seminar not found.');
    $stmt = $conn->prepare("SELECT sr.venue_id, sr.resource_kind, sr.start_date, sr.end_date,
            COALESCE(NULLIF(TRIM(h.floor_label), ''), sr.floor_label) AS floor_label, sr.allow_mixed_gender,
            v.name, h.room_number, h.room_type, h.max_capacity
        FROM seminar_reservations sr JOIN venues v ON v.id=sr.venue_id LEFT JOIN hotel_rooms h ON h.venue_id=v.id
        WHERE sr.seminar_id=? ORDER BY sr.resource_kind DESC, v.name, h.room_number, v.id");
    $stmt->bind_param('i', $seminarId); $stmt->execute();
    $rooms = []; $hall = null; $roomResult = $stmt->get_result();
    while ($row = $roomResult->fetch_assoc()) {
        if ($row['resource_kind'] === 'hall') $hall = $row;
        else $rooms[] = $row;
    }
    $stmt->close();
    $stmt = $conn->prepare('SELECT id, full_name, gender, location, contact, assigned_venue_id, solo_flag FROM seminar_attendees WHERE seminar_id=? ORDER BY full_name COLLATE utf8mb4_unicode_ci, id');
    $stmt->bind_param('i', $seminarId); $stmt->execute(); $attendees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $seminar['hall'] = $hall; $seminar['rooms'] = $rooms; $seminar['attendees'] = $attendees;
    $seminar['payment_summary'] = seminar_payment_summary($conn, $seminarId);
    $seminar['payments'] = seminar_payment_history($conn, $seminarId);
    $seminar['payment_methods'] = seminar_payment_methods($conn);
    return $seminar;
}

function seminar_assert_inventory(mysqli $conn, int $venueId, string $kind, string $start, string $end, ?int $excludeSeminarId = null): void
{
    $stmt = $conn->prepare('SELECT v.category,v.status,h.max_capacity,e.capacity_theater,e.capacity_classroom,e.capacity_banquet FROM venues v LEFT JOIN hotel_rooms h ON h.venue_id=v.id LEFT JOIN event_halls e ON e.venue_id=v.id WHERE v.id=?');
    $stmt->bind_param('i', $venueId); $stmt->execute(); $venue = $stmt->get_result()->fetch_assoc(); $stmt->close();
    $hallCapacity = $venue ? max((int)$venue['capacity_theater'], (int)$venue['capacity_classroom'], (int)$venue['capacity_banquet']) : 0;
    if (!$venue || $venue['status'] !== 'Available' || ($kind === 'hall' && ($venue['category'] !== 'Event Hall' || $hallCapacity < 1)) || ($kind === 'room' && ($venue['category'] !== 'Hotel Room' || (int)$venue['max_capacity'] < 1))) throw new InvalidArgumentException('The selected venue is not a valid available seminar resource.');
    $hotel = $kind === 'room';
    $overlap = $hotel ? '(start_date < ? AND end_date > ?)' : '(start_date <= ? AND end_date >= ?)';
    $statuses = $hotel ? "('Pending','Confirmed','Completed')" : "('Confirmed','Completed')";
    $stmt = $conn->prepare("SELECT id FROM bookings WHERE venue_id=? AND booking_status IN $statuses AND COALESCE(source,'') <> 'Maintenance' AND $overlap LIMIT 1");
    $stmt->bind_param('iss', $venueId, $end, $start); $stmt->execute();
    if ($stmt->get_result()->num_rows) { $stmt->close(); throw new InvalidArgumentException('The selected resource conflicts with an existing booking.'); }
    $stmt->close();
    if ($hotel) {
        $stmt = $conn->prepare("SELECT br.id FROM booking_rooms br JOIN bookings b ON b.id=br.booking_id JOIN venues parent_v ON parent_v.id=b.venue_id
            WHERE br.venue_id=? AND b.booking_status IN ('Pending','Confirmed','Completed')
            AND NOT (b.booking_status='Pending' AND parent_v.category='Event Hall') AND COALESCE(b.source,'') <> 'Maintenance'
            AND br.start_date < ? AND br.end_date > ? LIMIT 1");
        $stmt->bind_param('iss', $venueId, $end, $start); $stmt->execute();
        if ($stmt->get_result()->num_rows) { $stmt->close(); throw new InvalidArgumentException('The selected room conflicts with a hotel add-on booking.'); }
        $stmt->close();
    }
    $maintenanceOverlap = $hotel ? 'start_date < ? AND end_date > ?' : 'start_date <= ? AND end_date >= ?';
    $stmt = $conn->prepare("SELECT id FROM maintenance WHERE venue_id=? AND is_blocking=1 AND (status='Scheduled' OR status IS NULL) AND $maintenanceOverlap LIMIT 1");
    $stmt->bind_param('iss', $venueId, $end, $start); $stmt->execute();
    if ($stmt->get_result()->num_rows) { $stmt->close(); throw new InvalidArgumentException('The selected resource is under maintenance during those dates.'); }
    $stmt->close();
    $lockOverlap = $hotel ? 'start_date < ? AND end_date > ?' : 'start_date <= ? AND end_date >= ?';
    $stmt = $conn->prepare("SELECT id FROM booking_locks WHERE venue_id=? AND expires_at > NOW() AND $lockOverlap LIMIT 1");
    $stmt->bind_param('iss', $venueId, $end, $start); $stmt->execute();
    if ($stmt->get_result()->num_rows) { $stmt->close(); throw new InvalidArgumentException('The selected resource is currently held by another booking.'); }
    $stmt->close();
    if (seminar_has_resource_conflict($conn, $venueId, $start, $end, $excludeSeminarId)) throw new InvalidArgumentException('The selected resource is held by another seminar.');
}

function seminar_lock_resources(mysqli $conn, array $resourceIds): void
{
    $resourceIds = array_values(array_unique(array_map('intval', $resourceIds)));
    sort($resourceIds, SORT_NUMERIC);
    foreach ($resourceIds as $venueId) {
        $stmt = $conn->prepare('SELECT id FROM venues WHERE id=? FOR UPDATE');
        $stmt->bind_param('i', $venueId); $stmt->execute();
        if (!$stmt->get_result()->num_rows) { $stmt->close(); throw new InvalidArgumentException('A selected venue could not be found.'); }
        $stmt->close();
    }
}

function seminar_selected_room_ids(mixed $value): array
{
    return seminar_normalize_room_ids($value, 500);
}

function seminar_suggest_mapping(array $headers): array
{
    $suggested = [];
    foreach (['name' => ['name','full name','attendee'], 'gender' => ['gender','sex'], 'location' => ['location','city','address'], 'contact' => ['contact','phone','email']] as $field => $matches) {
        foreach ($headers as $index => $header) if (in_array(strtolower(trim((string)$header)), $matches, true)) { $suggested[$field] = (string)$index; break; }
    }
    return $suggested;
}

function seminar_mutate_reservation(mysqli $conn, array $input, ?int $seminarId, ?array $initialRoster = null, ?array &$initialAllocation = null): int
{
    $name = seminar_text($input['name'] ?? '', 180);
    $agreedPrice = seminar_validate_agreed_price($input['agreed_price'] ?? null);
    [$hallStart, $hallEnd, $checkIn, $checkOut] = seminar_validate_dates($input['hall_start'] ?? null, $input['hall_end'] ?? null, $input['hotel_check_in'] ?? null, $input['hotel_check_out'] ?? null);
    $hallId = seminar_id($input['hall_venue_id'] ?? null, 'Event Hall');
    $roomIds = seminar_selected_room_ids($input['room_ids'] ?? null);
    $ids = array_merge([$hallId], $roomIds);
    $retainedFloors = [];
    $conn->begin_transaction();
    try {
        if ($seminarId !== null) {
            $stmt = $conn->prepare("SELECT id,agreed_price FROM seminars WHERE id=? AND status='draft' FOR UPDATE");
            $stmt->bind_param('i', $seminarId); $stmt->execute();
            $lockedSeminar = $stmt->get_result()->fetch_assoc();
            if (!$lockedSeminar) throw new InvalidArgumentException('Reopen the finalized seminar before changing its reservation.');
            $stmt->close();
            seminar_payment_assert_price_floor($conn, $seminarId, $agreedPrice);
        }
        seminar_lock_resources($conn, $ids);
        foreach ($ids as $venueId) {
            $isHall = $venueId === $hallId;
            seminar_assert_inventory($conn, $venueId, $isHall ? 'hall' : 'room', $isHall ? $hallStart : $checkIn, $isHall ? $hallEnd : $checkOut, $seminarId);
        }
        if ($seminarId === null) {
            $userId = (int)$_SESSION['user_id'];
            $stmt = $conn->prepare("INSERT INTO seminars (name,agreed_price,status,hall_venue_id,hall_start_date,hall_end_date,hotel_check_in,hotel_check_out,created_by) VALUES (?,?, 'draft',?,?,?,?,?,?)");
            $stmt->bind_param('ssissssi', $name, $agreedPrice, $hallId, $hallStart, $hallEnd, $checkIn, $checkOut, $userId); $stmt->execute(); $seminarId = (int)$conn->insert_id; $stmt->close();
        } else {
            $floorStmt = $conn->prepare("SELECT venue_id,floor_label FROM seminar_reservations WHERE seminar_id=? AND resource_kind='room' FOR UPDATE");
            $floorStmt->bind_param('i', $seminarId); $floorStmt->execute();
            foreach ($floorStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $floorRow) {
                $retainedFloors[(int)$floorRow['venue_id']] = (string)($floorRow['floor_label'] ?? '');
            }
            $floorStmt->close();
            $stmt = $conn->prepare('UPDATE seminars SET name=?,agreed_price=?,hall_venue_id=?,hall_start_date=?,hall_end_date=?,hotel_check_in=?,hotel_check_out=? WHERE id=?');
            $stmt->bind_param('ssissssi', $name, $agreedPrice, $hallId, $hallStart, $hallEnd, $checkIn, $checkOut, $seminarId); $stmt->execute(); $stmt->close();
            $stmt = $conn->prepare('UPDATE seminar_attendees SET assigned_venue_id=NULL,solo_flag=0 WHERE seminar_id=? AND assigned_venue_id NOT IN (' . implode(',', $roomIds) . ')');
            $stmt->bind_param('i', $seminarId); $stmt->execute(); $stmt->close();
            $stmt = $conn->prepare('DELETE FROM seminar_reservations WHERE seminar_id=?'); $stmt->bind_param('i', $seminarId); $stmt->execute(); $stmt->close();
        }
        $stmt = $conn->prepare("INSERT INTO seminar_reservations (seminar_id,venue_id,resource_kind,start_date,end_date,floor_label) VALUES (?,?,'hall',?,?,NULL)");
        $stmt->bind_param('iiss', $seminarId, $hallId, $hallStart, $hallEnd); $stmt->execute(); $stmt->close();
        $stmt = $conn->prepare("INSERT INTO seminar_reservations (seminar_id,venue_id,resource_kind,start_date,end_date,floor_label) VALUES (?,?,'room',?,?,?)");
        foreach ($roomIds as $roomId) {
            $floor = $retainedFloors[$roomId] ?? '';
            $stmt->bind_param('iisss', $seminarId, $roomId, $checkIn, $checkOut, $floor); $stmt->execute();
        }
        $stmt->close();
        if ($initialRoster !== null) {
            if (count($initialRoster) < 1 || count($initialRoster) > 1000) throw new InvalidArgumentException('The imported roster must contain between 1 and 1,000 attendees.');
            $attendeeStmt = $conn->prepare('INSERT INTO seminar_attendees (seminar_id,full_name,gender,location,contact) VALUES (?,?,?,?,?)');
            if (!$attendeeStmt) throw new RuntimeException('Unable to save imported attendees.');
            $persistedRoster = [];
            foreach ($initialRoster as $person) {
                $fullName = seminar_text($person['full_name'] ?? '', 180);
                $gender = seminar_normalize_gender($person['gender'] ?? '');
                $location = seminar_text($person['location'] ?? '', 180);
                $contact = seminar_text($person['contact'] ?? '', 100, false);
                $attendeeStmt->bind_param('issss', $seminarId, $fullName, $gender, $location, $contact);
                if (!$attendeeStmt->execute()) throw new RuntimeException('Unable to save imported attendees.');
                $persistedRoster[] = ['id' => (int)$conn->insert_id, 'gender' => $gender, 'location' => $location];
            }
            $attendeeStmt->close();
            $roomStmt = $conn->prepare("SELECT venue_id,max_capacity FROM hotel_rooms WHERE venue_id IN (" . implode(',', $roomIds) . ") ORDER BY venue_id");
            if (!$roomStmt) throw new RuntimeException('Unable to load reserved room capacities.');
            if (!$roomStmt->execute()) throw new RuntimeException('Unable to load reserved room capacities.');
            $allocationRooms = array_map(static fn($room) => ['venue_id' => (int)$room['venue_id'], 'max_capacity' => (int)$room['max_capacity']], $roomStmt->get_result()->fetch_all(MYSQLI_ASSOC));
            $roomStmt->close();
            $initialAllocation = seminar_allocate_attendees($persistedRoster, $allocationRooms);
            $assignmentStmt = $conn->prepare('UPDATE seminar_attendees SET assigned_venue_id=?,solo_flag=? WHERE id=? AND seminar_id=?');
            if (!$assignmentStmt) throw new RuntimeException('Unable to create initial room assignments.');
            foreach ($initialAllocation['assignments'] as $attendeeId => $roomId) {
                $solo = isset($initialAllocation['solo'][$attendeeId]) ? 1 : 0;
                $assignmentStmt->bind_param('iiii', $roomId, $solo, $attendeeId, $seminarId);
                if (!$assignmentStmt->execute()) throw new RuntimeException('Unable to create initial room assignments.');
            }
            $assignmentStmt->close();
        }
        seminar_write_audit($conn, (int)$_SESSION['user_id'], ($seminarId ? 'Reserved resources for seminar #' . $seminarId : 'Created seminar reservation'));
        $conn->commit();
        return $seminarId;
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}

function seminar_finalize(mysqli $conn, int $seminarId): array
{
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM seminars WHERE id=? AND status='draft' FOR UPDATE"); $stmt->bind_param('i', $seminarId); $stmt->execute(); $seminar = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$seminar) throw new InvalidArgumentException('Only a draft seminar can be finalized.');
        $stmt = $conn->prepare('SELECT venue_id,resource_kind,start_date,end_date FROM seminar_reservations WHERE seminar_id=? ORDER BY venue_id');
        $stmt->bind_param('i', $seminarId); $stmt->execute(); $resources = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        seminar_lock_resources($conn, array_column($resources, 'venue_id'));
        foreach ($resources as $resource) seminar_assert_inventory($conn, (int)$resource['venue_id'], $resource['resource_kind'], $resource['start_date'], $resource['end_date'], $seminarId);
        $stmt = $conn->prepare('SELECT a.id,a.gender,a.assigned_venue_id FROM seminar_attendees a WHERE a.seminar_id=? ORDER BY a.id FOR UPDATE');
        $stmt->bind_param('i', $seminarId); $stmt->execute(); $attendees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        $stmt = $conn->prepare("SELECT sr.venue_id,h.max_capacity,sr.allow_mixed_gender FROM seminar_reservations sr JOIN hotel_rooms h ON h.venue_id=sr.venue_id WHERE sr.seminar_id=? AND sr.resource_kind='room'");
        $stmt->bind_param('i', $seminarId); $stmt->execute(); $roomRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        $roomCapacities = []; $allowMixedRoomIds = [];
        foreach ($roomRows as $room) { $roomId = (int)$room['venue_id']; $roomCapacities[$roomId] = (int)$room['max_capacity']; if ((int)$room['allow_mixed_gender'] === 1) $allowMixedRoomIds[] = $roomId; }
        seminar_check_final_assignments($attendees, $roomCapacities, $allowMixedRoomIds);
        $stmt = $conn->prepare('SELECT GREATEST(COALESCE(capacity_theater,0),COALESCE(capacity_classroom,0),COALESCE(capacity_banquet,0)) AS capacity FROM event_halls WHERE venue_id=?');
        $stmt->bind_param('i', $seminar['hall_venue_id']); $stmt->execute(); $hallCapacity = (int)($stmt->get_result()->fetch_assoc()['capacity'] ?? 0); $stmt->close();
        $stmt = $conn->prepare("UPDATE seminars SET status='finalized',finalized_at=NOW() WHERE id=?"); $stmt->bind_param('i', $seminarId); $stmt->execute(); $stmt->close();
        seminar_write_audit($conn, (int)$_SESSION['user_id'], 'Finalized seminar #' . $seminarId); $conn->commit();
        return ['warning' => $hallCapacity > 0 && count($attendees) > $hallCapacity, 'hall_capacity' => $hallCapacity, 'attendee_count' => count($attendees)];
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $op = $_GET['op'] ?? 'list';
        if ($op === 'options') {
            $halls = $conn->query("SELECT v.id,v.name,COALESCE(GREATEST(e.capacity_theater,e.capacity_classroom,e.capacity_banquet),0) AS capacity FROM venues v JOIN event_halls e ON e.venue_id=v.id WHERE v.category='Event Hall' AND v.status='Available' ORDER BY v.name")->fetch_all(MYSQLI_ASSOC);
            $rooms = $conn->query("SELECT v.id,v.name,v.name AS building_name,h.room_number,h.room_type AS room_label,h.floor_label,h.max_capacity FROM venues v JOIN hotel_rooms h ON h.venue_id=v.id WHERE v.category='Hotel Room' AND v.status='Available' ORDER BY v.name,h.floor_label,h.room_number,v.id")->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'halls' => $halls, 'rooms' => $rooms]); exit;
        }
        if ($op === 'room_options') {
            $checkIn = seminar_strict_date($_GET['check_in'] ?? null, 'hotel check-in');
            $checkOut = seminar_strict_date($_GET['check_out'] ?? null, 'hotel checkout');
            if ($checkOut <= $checkIn) throw new InvalidArgumentException('Hotel checkout must be after check-in.');
            $excludeId = isset($_GET['exclude_id']) && $_GET['exclude_id'] !== '' ? seminar_id($_GET['exclude_id']) : null;
            $rooms = seminar_available_room_units($conn, $checkIn, $checkOut, $excludeId);
            echo json_encode(['success' => true, 'rooms' => $rooms]); exit;
        }
        if ($op === 'hall_options') {
            $hallStart = seminar_strict_date($_GET['hall_start'] ?? null, 'hall start');
            $hallEnd = seminar_strict_date($_GET['hall_end'] ?? null, 'hall end');
            if ($hallEnd < $hallStart) throw new InvalidArgumentException('Hall end date must be on or after the start date.');
            $excludeId = isset($_GET['exclude_id']) && $_GET['exclude_id'] !== '' ? seminar_id($_GET['exclude_id']) : null;
            echo json_encode(['success' => true, 'halls' => seminar_available_halls($conn, $hallStart, $hallEnd, $excludeId)]); exit;
        }
        if ($op === 'get') { echo json_encode(['success' => true, 'seminar' => seminar_load(seminar_id($_GET['id'] ?? null))]); exit; }
        $result = $conn->query("SELECT s.id,s.name,s.agreed_price,s.status,s.hall_start_date,s.hall_end_date,s.hotel_check_in,s.hotel_check_out,v.name AS hall_name,
                (SELECT COUNT(*) FROM seminar_attendees a WHERE a.seminar_id=s.id) AS attendee_count,
                (SELECT COALESCE(SUM(p.amount),0) FROM seminar_payments p WHERE p.seminar_id=s.id AND p.status='posted') AS amount_paid
            FROM seminars s JOIN venues v ON v.id=s.hall_venue_id WHERE s.status <> 'cancelled' ORDER BY s.updated_at DESC,s.id DESC");
        echo json_encode(['success' => true, 'seminars' => $result->fetch_all(MYSQLI_ASSOC)]); exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)$file['size'] > 5 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Choose a valid file no larger than 5 MB.');
        $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $sheets = seminar_parse_upload($file['tmp_name'], $extension);
        $token = bin2hex(random_bytes(24));
        $_SESSION['seminar_upload_preview'] = ['token' => $token, 'created' => time(), 'sheets' => $sheets];
        $sheetMetadata = [];
        foreach ($sheets as $sheet) {
            $headers = array_values($sheet['rows'][0] ?? []);
            $sheetMetadata[] = ['name' => $sheet['name'], 'headers' => $headers, 'sample' => array_slice($sheet['rows'], 1, 10), 'mapping' => seminar_suggest_mapping($headers)];
        }
        echo json_encode(['success' => true, 'token' => $token, 'sheets' => $sheetMetadata]); exit;
    }

    $data = seminar_json_body(); $op = (string)($data['op'] ?? '');
    if ($op === 'create') { $id = seminar_mutate_reservation($conn, $data, null); echo json_encode(['success' => true, 'seminar' => seminar_load($id)]); exit; }
    if ($op === 'update_reservation') { $id = seminar_mutate_reservation($conn, $data, seminar_id($data['id'] ?? null)); echo json_encode(['success' => true, 'seminar' => seminar_load($id)]); exit; }
    if ($op === 'create_from_import') {
        $preview = $_SESSION['seminar_upload_preview'] ?? null;
        if (!$preview || !is_array($preview) || time() - (int)$preview['created'] > 1800
            || !hash_equals((string)$preview['token'], (string)($data['token'] ?? ''))) throw new InvalidArgumentException('Import preview expired. Upload the roster again.');
        $sheetIndex = filter_var($data['sheet'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => count($preview['sheets']) - 1]]);
        if ($sheetIndex === false) throw new InvalidArgumentException('Choose a valid worksheet.');
        $validated = seminar_validate_import_rows($preview['sheets'][$sheetIndex]['rows'], is_array($data['mapping'] ?? null) ? $data['mapping'] : []);
        if ($validated['errors']) throw new InvalidArgumentException('Fix the highlighted roster rows before creating the seminar.');
        if (!$validated['attendees']) throw new InvalidArgumentException('The worksheet contains no attendee rows.');
        $allocation = null;
        $seminarId = seminar_mutate_reservation($conn, $data, null, $validated['attendees'], $allocation);
        unset($_SESSION['seminar_upload_preview']);
        echo json_encode(['success' => true, 'seminar' => seminar_load($seminarId), 'allocation' => $allocation, 'summary' => seminar_import_summary($validated['attendees'])]); exit;
    }
    if ($op === 'preview_validate' || $op === 'import_replace') {
        $preview = $_SESSION['seminar_upload_preview'] ?? null;
        if (!$preview || !is_array($preview) || time() - (int)$preview['created'] > 1800 || !hash_equals((string)$preview['token'], (string)($data['token'] ?? ''))) throw new InvalidArgumentException('Import preview expired. Upload the file again.');
        $sheetIndex = filter_var($data['sheet'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => count($preview['sheets']) - 1]]);
        if ($sheetIndex === false) throw new InvalidArgumentException('Choose a valid worksheet.');
        $validated = seminar_validate_import_rows($preview['sheets'][$sheetIndex]['rows'], is_array($data['mapping'] ?? null) ? $data['mapping'] : []);
        if ($op === 'preview_validate') {
            echo json_encode(['success' => true, 'count' => count($validated['attendees']), 'errors' => $validated['errors'],
                'sample' => array_slice($validated['attendees'], 0, 10), 'summary' => seminar_import_summary($validated['attendees'])]); exit;
        }
        if ($validated['errors']) throw new InvalidArgumentException('Fix the highlighted import rows before replacing the draft roster.');
        if (!$validated['attendees']) throw new InvalidArgumentException('The worksheet contains no attendee rows.');
        $seminarId = seminar_id($data['id'] ?? null);
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT id FROM seminars WHERE id=? AND status='draft' FOR UPDATE"); $stmt->bind_param('i', $seminarId); $stmt->execute();
            if (!$stmt->get_result()->num_rows) throw new InvalidArgumentException('Reopen the finalized seminar before importing attendees.'); $stmt->close();
            $stmt = $conn->prepare('DELETE FROM seminar_attendees WHERE seminar_id=?'); $stmt->bind_param('i', $seminarId); $stmt->execute(); $stmt->close();
            $stmt = $conn->prepare('INSERT INTO seminar_attendees (seminar_id,full_name,gender,location,contact) VALUES (?,?,?,?,?)');
            foreach ($validated['attendees'] as $attendee) { $stmt->bind_param('issss', $seminarId, $attendee['full_name'], $attendee['gender'], $attendee['location'], $attendee['contact']); $stmt->execute(); }
            $stmt->close(); seminar_write_audit($conn, (int)$_SESSION['user_id'], 'Replaced seminar roster #' . $seminarId); $conn->commit(); unset($_SESSION['seminar_upload_preview']);
        } catch (Throwable $error) { $conn->rollback(); throw $error; }
        echo json_encode(['success' => true, 'seminar' => seminar_load($seminarId)]); exit;
    }

    $seminarId = seminar_id($data['id'] ?? null);
    if ($op === 'attendee_add' || $op === 'attendee_update') {
        $name = seminar_text($data['full_name'] ?? '', 180); $gender = seminar_normalize_gender($data['gender'] ?? '');
        $location = seminar_text($data['location'] ?? '', 180); $contact = seminar_text($data['contact'] ?? '', 100, false);
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT id FROM seminars WHERE id=? AND status='draft' FOR UPDATE"); $stmt->bind_param('i', $seminarId); $stmt->execute(); if (!$stmt->get_result()->num_rows) throw new InvalidArgumentException('Reopen the finalized seminar before editing attendees.'); $stmt->close();
            if ($op === 'attendee_add') { $stmt = $conn->prepare('INSERT INTO seminar_attendees (seminar_id,full_name,gender,location,contact) VALUES (?,?,?,?,?)'); $stmt->bind_param('issss', $seminarId, $name, $gender, $location, $contact); }
            else { $attendeeId = seminar_id($data['attendee_id'] ?? null, 'attendee'); $stmt = $conn->prepare('UPDATE seminar_attendees SET full_name=?,gender=?,location=?,contact=? WHERE id=? AND seminar_id=?'); $stmt->bind_param('ssssii', $name, $gender, $location, $contact, $attendeeId, $seminarId); }
            $stmt->execute(); if ($stmt->affected_rows < 1 && $op === 'attendee_update') throw new InvalidArgumentException('Attendee not found.'); $stmt->close();
            $conn->commit();
        } catch (Throwable $error) { $conn->rollback(); throw $error; }
        echo json_encode(['success' => true, 'seminar' => seminar_load($seminarId)]); exit;
    }
    if ($op === 'attendee_delete') {
        $attendeeId = seminar_id($data['attendee_id'] ?? null, 'attendee');
        $stmt = $conn->prepare("DELETE a FROM seminar_attendees a JOIN seminars s ON s.id=a.seminar_id WHERE a.id=? AND a.seminar_id=? AND s.status='draft'");
        $stmt->bind_param('ii', $attendeeId, $seminarId); $stmt->execute(); if (!$stmt->affected_rows) throw new InvalidArgumentException('Attendee not found or seminar is finalized.'); $stmt->close();
        $stmt = $conn->prepare('UPDATE seminar_attendees SET solo_flag=0 WHERE seminar_id=?'); $stmt->bind_param('i', $seminarId); $stmt->execute(); $stmt->close();
        $stmt = $conn->prepare('UPDATE seminar_attendees a JOIN (SELECT assigned_venue_id,COUNT(*) AS occupants FROM seminar_attendees WHERE seminar_id=? AND assigned_venue_id IS NOT NULL GROUP BY assigned_venue_id HAVING COUNT(*)=1) lone ON lone.assigned_venue_id=a.assigned_venue_id SET a.solo_flag=1 WHERE a.seminar_id=?');
        $stmt->bind_param('ii', $seminarId, $seminarId); $stmt->execute(); $stmt->close();
        echo json_encode(['success' => true, 'seminar' => seminar_load($seminarId)]); exit;
    }
    if ($op === 'allocate') {
        $seminar = seminar_load($seminarId); if ($seminar['status'] !== 'draft') throw new InvalidArgumentException('Reopen the finalized seminar before allocating rooms.');
        $allocation = seminar_allocate_attendees($seminar['attendees'], array_map(static fn($r) => ['venue_id' => (int)$r['venue_id'], 'max_capacity' => (int)$r['max_capacity']], $seminar['rooms']));
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT id FROM seminars WHERE id=? AND status='draft' FOR UPDATE"); $stmt->bind_param('i', $seminarId); $stmt->execute();
            if (!$stmt->get_result()->num_rows) throw new InvalidArgumentException('Reopen the finalized seminar before allocating rooms.'); $stmt->close();
            $stmt = $conn->prepare('UPDATE seminar_attendees SET assigned_venue_id=NULL,solo_flag=0 WHERE seminar_id=?'); $stmt->bind_param('i', $seminarId); $stmt->execute(); $stmt->close();
            $stmt = $conn->prepare('UPDATE seminar_reservations SET allow_mixed_gender=0 WHERE seminar_id=? AND resource_kind=\'room\''); $stmt->bind_param('i', $seminarId); $stmt->execute(); $stmt->close();
            $stmt = $conn->prepare('UPDATE seminar_attendees SET assigned_venue_id=?,solo_flag=? WHERE id=? AND seminar_id=?');
            foreach ($allocation['assignments'] as $attendeeId => $roomId) { $solo = isset($allocation['solo'][$attendeeId]) ? 1 : 0; $stmt->bind_param('iiii', $roomId, $solo, $attendeeId, $seminarId); $stmt->execute(); }
            $stmt->close(); $conn->commit();
        } catch (Throwable $error) { $conn->rollback(); throw $error; }
        echo json_encode(['success' => true, 'allocation' => $allocation, 'seminar' => seminar_load($seminarId)]); exit;
    }
    if ($op === 'save_assignments') {
        $assignments = is_array($data['assignments'] ?? null) ? $data['assignments'] : [];
        $mixedIds = array_map('intval', is_array($data['allow_mixed'] ?? null) ? $data['allow_mixed'] : []);
        $edits = is_array($data['attendee_edits'] ?? null) ? $data['attendee_edits'] : [];
        if (count($assignments) > 1000 || count($edits) > 1000) throw new InvalidArgumentException('A seminar can contain at most 1,000 attendees.');
        $normalizedEdits = [];
        foreach ($edits as $edit) {
            if (!is_array($edit)) throw new InvalidArgumentException('Invalid attendee edit.');
            $attendeeId = seminar_id($edit['id'] ?? null, 'attendee');
            $normalizedEdits[$attendeeId] = [
                'full_name' => seminar_text($edit['full_name'] ?? '', 180),
                'gender' => seminar_normalize_gender($edit['gender'] ?? ''),
                'location' => seminar_text($edit['location'] ?? '', 180),
                'contact' => seminar_text($edit['contact'] ?? '', 100, false),
            ];
        }
        $seminar = seminar_load($seminarId); if ($seminar['status'] !== 'draft') throw new InvalidArgumentException('Reopen the finalized seminar before editing assignments.');
        $roomIds = array_map(static fn($room) => (int)$room['venue_id'], $seminar['rooms']);
        foreach ($assignments as $attendeeId => $roomId) if (!in_array((int)$roomId, $roomIds, true) && (int)$roomId !== 0) throw new InvalidArgumentException('Choose a room reserved by this seminar.');
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT id FROM seminars WHERE id=? AND status='draft' FOR UPDATE"); $stmt->bind_param('i', $seminarId); $stmt->execute();
            if (!$stmt->get_result()->num_rows) throw new InvalidArgumentException('Reopen the finalized seminar before editing assignments.'); $stmt->close();
            $stmt = $conn->prepare("SELECT venue_id FROM seminar_reservations WHERE seminar_id=? AND resource_kind='room' ORDER BY venue_id FOR UPDATE"); $stmt->bind_param('i', $seminarId); $stmt->execute();
            $roomIds = array_map(static fn($row) => (int)$row['venue_id'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC)); $stmt->close();
            foreach ($assignments as $roomId) if ((int)$roomId > 0 && !in_array((int)$roomId, $roomIds, true)) throw new InvalidArgumentException('The reserved rooms changed while this page was open. Reload and try again.');
            $stmt = $conn->prepare('SELECT id FROM seminar_attendees WHERE seminar_id=? ORDER BY id FOR UPDATE'); $stmt->bind_param('i', $seminarId); $stmt->execute(); $attendeeRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            $knownAttendees = array_fill_keys(array_map(static fn($row) => (int)$row['id'], $attendeeRows), true);
            foreach (array_merge(array_keys($assignments), array_keys($normalizedEdits)) as $attendeeId) if (!isset($knownAttendees[(int)$attendeeId])) throw new InvalidArgumentException('An attendee changed while this page was open. Reload and try again.');
            if (count($assignments) !== count($knownAttendees)) throw new InvalidArgumentException('Save an assignment choice for every attendee.');
            $stmt = $conn->prepare('UPDATE seminar_attendees SET full_name=?,gender=?,location=?,contact=? WHERE id=? AND seminar_id=?');
            foreach ($normalizedEdits as $attendeeId => $edit) { $stmt->bind_param('ssssii', $edit['full_name'], $edit['gender'], $edit['location'], $edit['contact'], $attendeeId, $seminarId); $stmt->execute(); }
            $stmt->close();
            $stmt = $conn->prepare('UPDATE seminar_reservations SET allow_mixed_gender=0 WHERE seminar_id=? AND resource_kind=\'room\''); $stmt->bind_param('i', $seminarId); $stmt->execute(); $stmt->close();
            foreach ($mixedIds as $roomId) { if (!in_array($roomId, $roomIds, true)) throw new InvalidArgumentException('Mixed-gender approval references a room outside this seminar.'); $stmt = $conn->prepare("UPDATE seminar_reservations SET allow_mixed_gender=1 WHERE seminar_id=? AND venue_id=? AND resource_kind='room'"); $stmt->bind_param('ii', $seminarId, $roomId); $stmt->execute(); $stmt->close(); }
            $stmt = $conn->prepare('UPDATE seminar_attendees SET assigned_venue_id=NULL,solo_flag=0 WHERE seminar_id=?'); $stmt->bind_param('i', $seminarId); $stmt->execute(); $stmt->close();
            $counts = [];
            foreach ($assignments as $attendeeId => $roomId) if ((int)$roomId > 0) $counts[(int)$roomId] = ($counts[(int)$roomId] ?? 0) + 1;
            $stmt = $conn->prepare('UPDATE seminar_attendees SET assigned_venue_id=?,solo_flag=? WHERE id=? AND seminar_id=?');
            foreach ($assignments as $attendeeId => $roomId) {
                $attendeeId = seminar_id((string)$attendeeId, 'attendee'); $roomId = (int)$roomId; if ($roomId <= 0) continue;
                $countStmt = $conn->prepare('SELECT max_capacity FROM hotel_rooms WHERE venue_id=?'); $countStmt->bind_param('i', $roomId); $countStmt->execute(); $capacity = (int)($countStmt->get_result()->fetch_assoc()['max_capacity'] ?? 0); $countStmt->close();
                if ($capacity <= 0) throw new InvalidArgumentException('Choose an available hotel room with a valid capacity.');
                $solo = ($counts[$roomId] ?? 0) === 1 ? 1 : 0; $stmt->bind_param('iiii', $roomId, $solo, $attendeeId, $seminarId); $stmt->execute(); if (!$stmt->affected_rows && $stmt->errno) throw new RuntimeException('Unable to save attendee assignments.');
            }
            $stmt->close(); $conn->commit();
        } catch (Throwable $error) { $conn->rollback(); throw $error; }
        echo json_encode(['success' => true, 'seminar' => seminar_load($seminarId)]); exit;
    }
    if ($op === 'floor') {
        $roomId = seminar_id($data['room_id'] ?? null, 'room'); $floor = seminar_text($data['floor'] ?? '', 80, false);
        $stmt = $conn->prepare("UPDATE seminar_reservations sr JOIN seminars s ON s.id=sr.seminar_id SET sr.floor_label=? WHERE sr.seminar_id=? AND sr.venue_id=? AND sr.resource_kind='room' AND s.status='draft'"); $stmt->bind_param('sii', $floor, $seminarId, $roomId); $stmt->execute(); if (!$stmt->affected_rows) throw new InvalidArgumentException('Room not found or seminar is finalized.'); $stmt->close();
        echo json_encode(['success' => true, 'seminar' => seminar_load($seminarId)]); exit;
    }
    if ($op === 'finalize') { $result = seminar_finalize($conn, $seminarId); echo json_encode(['success' => true, 'warning' => $result['warning'], 'warning_message' => $result['warning'] ? 'Attendee count exceeds Event Hall capacity.' : '', 'hall_capacity' => $result['hall_capacity'], 'attendee_count' => $result['attendee_count'], 'seminar' => seminar_load($seminarId)]); exit; }
    if ($op === 'reopen' || $op === 'cancel') {
        $target = $op === 'reopen' ? 'draft' : 'cancelled';
        $from = $op === 'reopen' ? "status='finalized'" : "status IN ('draft','finalized')";
        $stmt = $conn->prepare("UPDATE seminars SET status=?, finalized_at=IF(?='draft',NULL,finalized_at) WHERE id=? AND $from"); $stmt->bind_param('ssi', $target, $target, $seminarId); $stmt->execute(); if (!$stmt->affected_rows) throw new InvalidArgumentException($op === 'reopen' ? 'Only finalized seminars can be reopened.' : 'Seminar is already cancelled.'); $stmt->close();
        seminar_write_audit($conn, (int)$_SESSION['user_id'], ($op === 'cancel' ? 'Cancelled' : 'Reopened') . ' seminar #' . $seminarId);
        echo json_encode(['success' => true, 'seminar' => seminar_load($seminarId)]); exit;
    }
    throw new InvalidArgumentException('Unknown seminar action.');
} catch (InvalidArgumentException $error) {
    http_response_code(422); echo json_encode(['success' => false, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Seminar operation failed: ' . get_class($error));
    http_response_code(500); echo json_encode(['success' => false, 'message' => 'Unable to save seminar changes. Check that migrations 028, 030, and 031 have been applied.']);
}
