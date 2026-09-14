<?php
/** Read-only availability count for a hotel commercial group. */
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/hotel_rooms.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $checkIn = $_GET['check_in'] ?? $_GET['start_date'] ?? null;
    $checkOut = $_GET['check_out'] ?? $_GET['end_date'] ?? null;
    [$start, $end] = hotel_validate_recommendation_dates($checkIn, $checkOut);
    $groupIdRaw = $_GET['room_group_id'] ?? '';
    $groupId = $groupIdRaw === '' ? null : filter_var($groupIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($groupIdRaw !== '' && $groupId === false) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid room selection.']);
        exit;
    }

    if (hotel_group_schema_ready($conn)) {
        if ($groupId === null) {
            $building = trim((string)($_GET['building_name'] ?? ''));
            $roomType = trim((string)($_GET['room_type'] ?? ''));
            if ($building === '' || $roomType === '') {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Room selection is required.']);
                exit;
            }
            $stmt = $conn->prepare('SELECT g.id FROM hotel_room_groups g
                INNER JOIN hotel_room_types t ON t.type_code = g.room_type_code AND t.active = 1
                WHERE g.building_name = ? AND (g.room_type_code = ? OR g.legacy_room_type = ?) ORDER BY t.sort_order, g.sort_order, g.id');
            $stmt->bind_param('sss', $building, $roomType, $roomType);
            $stmt->execute();
            $groupIds = array_map(static fn(array $row): int => (int)$row['id'], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
            $stmt->close();
        } else {
            $groupIds = [(int)$groupId];
        }
        $available = hotel_available_group_units($conn, $start->format('Y-m-d'), $end->format('Y-m-d'), session_id(), $groupIds);
        $count = 0;
        foreach ($available as $units) $count += count($units);
        $rate = 0.0;
        $baseCapacity = 0;
        if ($groupIds) {
            $stmt = $conn->prepare('SELECT g.nightly_rate, g.base_capacity FROM hotel_room_groups g
                INNER JOIN hotel_room_types t ON t.type_code = g.room_type_code AND t.active = 1 WHERE g.id = ? LIMIT 1');
            $firstId = (int)$groupIds[0];
            $stmt->bind_param('i', $firstId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $rate = (float)$row['nightly_rate'];
                $baseCapacity = (int)$row['base_capacity'];
            }
        }
        echo json_encode(['success' => true, 'available' => $count, 'nightly_rate' => $rate, 'base_capacity' => $baseCapacity]);
        exit;
    }

    // Compatibility for installations that have not applied migration 023.
    $building = trim((string)($_GET['building_name'] ?? ''));
    $roomType = trim((string)($_GET['room_type'] ?? ''));
    if ($building === '' || $roomType === '') {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Room recommendations are temporarily unavailable.']);
        exit;
    }
    $overlap = booking_overlap_sql('Hotel Room', 'b.start_date', 'b.end_date');
    $addonOverlap = booking_overlap_sql('Hotel Room', 'br.start_date', 'br.end_date');
    $maintOverlap = maintenance_overlap_sql('m.start_date', 'm.end_date');
    $lockOverlap = booking_overlap_sql('Hotel Room', 'bl.start_date', 'bl.end_date');
    $sql = "SELECT COUNT(*) AS available, MAX(h.nightly_rate) AS nightly_rate, MAX(h.base_capacity) AS base_capacity
        FROM venues v INNER JOIN hotel_rooms h ON h.venue_id = v.id
        WHERE v.name = ? AND h.room_type = ? AND v.status = 'Available'
          AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.venue_id = v.id
            AND b.booking_status IN ('Pending','Confirmed','Completed') AND COALESCE(b.source,'') <> 'Maintenance' AND {$overlap})
          AND NOT EXISTS (SELECT 1 FROM booking_rooms br JOIN bookings b2 ON b2.id = br.booking_id
            JOIN venues parent_v ON parent_v.id = b2.venue_id WHERE br.venue_id = v.id
            AND b2.booking_status IN ('Pending','Confirmed','Completed')
            AND NOT (b2.booking_status = 'Pending' AND parent_v.category = 'Event Hall')
            AND COALESCE(b2.source,'') <> 'Maintenance' AND {$addonOverlap})
          AND NOT EXISTS (SELECT 1 FROM maintenance m WHERE m.venue_id = v.id AND m.is_blocking = 1
            AND (m.status = 'Scheduled' OR m.status IS NULL) AND {$maintOverlap})
          AND NOT EXISTS (SELECT 1 FROM booking_locks bl WHERE bl.venue_id = v.id
            AND bl.session_id <> ? AND bl.expires_at > NOW() AND {$lockOverlap})";
    $stmt = $conn->prepare($sql);
    $checkOut = $end->format('Y-m-d');
    $checkIn = $start->format('Y-m-d');
    $session = session_id();
    $stmt->bind_param('sssssssssss', $building, $roomType, $checkOut, $checkIn, $checkOut, $checkIn,
        $checkOut, $checkIn, $session, $checkOut, $checkIn);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    echo json_encode(['success' => true, 'available' => (int)($row['available'] ?? 0),
        'nightly_rate' => (float)($row['nightly_rate'] ?? 0), 'base_capacity' => (int)($row['base_capacity'] ?? 0)]);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Hotel availability lookup failed: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Availability could not be loaded. Please try again.']);
}
