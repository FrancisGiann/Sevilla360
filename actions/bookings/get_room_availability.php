<?php
/**
 * SEVILLA360 - Get Hotel Room Availability
 * Returns available unit count for a given building+room_type group for a date range.
 */
session_start();
header('Content-Type: application/json');
require '../../config/db_connect.php';

try {
    $conn->query("DELETE FROM booking_locks WHERE expires_at <= NOW()");
    $current_session = session_id();
    $building_name = trim($_GET['building_name'] ?? '');
    $room_type     = trim($_GET['room_type'] ?? '');
    $start_date    = trim($_GET['start_date'] ?? '');
    $end_date      = trim($_GET['end_date'] ?? '');

    if (empty($building_name) || empty($room_type) || empty($start_date) || empty($end_date)) {
        echo json_encode(['success' => false, 'message' => 'Missing parameters.']);
        exit;
    }

    // Validate date formats
    $start_dt = DateTime::createFromFormat('Y-m-d', $start_date);
    $end_dt   = DateTime::createFromFormat('Y-m-d', $end_date);
    if (!$start_dt || !$end_dt || $end_dt <= $start_dt) {
        echo json_encode(['success' => false, 'message' => 'Invalid date range.']);
        exit;
    }

    // Count rooms in this group NOT booked for these dates
    $stmt = $conn->prepare("
        SELECT COUNT(v.id) AS available
        FROM venues v
        JOIN hotel_rooms h ON v.id = h.venue_id
        WHERE v.name = ?
          AND h.room_type = ?
          AND v.status = 'Available'
          AND v.id NOT IN (
              SELECT venue_id FROM bookings
              WHERE booking_status IN ('Pending', 'Confirmed', 'Completed')
                AND source <> 'Maintenance'
                AND (start_date < ? AND end_date > ?)
          )
          AND v.id NOT IN (
              SELECT br.venue_id FROM booking_rooms br
              JOIN bookings b2 ON br.booking_id = b2.id
              JOIN venues parent_v ON parent_v.id = b2.venue_id
              WHERE b2.booking_status IN ('Pending', 'Confirmed', 'Completed')
                AND NOT (b2.booking_status = 'Pending' AND parent_v.category = 'Event Hall')
                AND b2.source <> 'Maintenance'
                AND (br.start_date < ? AND br.end_date > ?)
          )
          AND v.id NOT IN (
              SELECT venue_id FROM maintenance
              WHERE is_blocking = 1 AND (status = 'Scheduled' OR status IS NULL)
                AND (start_date <= ? AND end_date >= ?)
          )
          AND v.id NOT IN (
              SELECT venue_id FROM booking_locks
              WHERE session_id != ? AND expires_at > NOW()
                AND (start_date < ? AND end_date > ?)
          )
    ");
    $stmt->bind_param('sssssssssss', $building_name, $room_type, $end_date, $start_date, $end_date, $start_date, $end_date, $start_date, $current_session, $end_date, $start_date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $available = (int)($row['available'] ?? 0);

    // Also fetch nightly_rate and base_capacity for the group
    $stmt_rate = $conn->prepare("
        SELECT h.nightly_rate, h.base_capacity
        FROM hotel_rooms h
        JOIN venues v ON v.id = h.venue_id
        WHERE v.name = ? AND h.room_type = ? AND v.status = 'Available'
        LIMIT 1
    ");
    $stmt_rate->bind_param('ss', $building_name, $room_type);
    $stmt_rate->execute();
    $rate_row = $stmt_rate->get_result()->fetch_assoc();

    echo json_encode([
        'success'       => true,
        'available'     => $available,
        'nightly_rate'  => $rate_row ? floatval($rate_row['nightly_rate']) : 0,
        'base_capacity' => $rate_row ? (int)$rate_row['base_capacity'] : 0
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
