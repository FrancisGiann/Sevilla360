<?php
require '../../config/db_connect.php';

// Auto-cleanup locks
$conn->query("DELETE FROM booking_locks WHERE expires_at <= NOW()");

if (isset($_GET['room_type']) && isset($_GET['room_name'])) {
    $room_type = trim($_GET['room_type']);
    $room_name = trim($_GET['room_name']);
    $blocked_dates = [];

    // SMART QUERY: Check category for counting inventory correctly
    if ($room_type === 'Event Hall' || $room_type === 'Resort Villa') {
        $stmt = $conn->prepare("
            SELECT b.start_date, b.end_date 
            FROM bookings b JOIN venues v ON b.venue_id = v.id
            WHERE v.category = ? AND v.name = ? AND b.booking_status IN ('Pending', 'Confirmed')
            GROUP BY b.start_date, b.end_date
            HAVING COUNT(b.id) >= (SELECT COUNT(id) FROM venues WHERE category = ? AND name = ? AND status = 'Available')
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT b.start_date, b.end_date 
            FROM bookings b JOIN venues v ON b.venue_id = v.id JOIN hotel_rooms h ON v.id = h.venue_id
            WHERE h.room_type = ? AND v.name = ? AND b.booking_status IN ('Pending', 'Confirmed')
            GROUP BY b.start_date, b.end_date
            HAVING COUNT(b.id) >= (SELECT COUNT(v2.id) FROM venues v2 JOIN hotel_rooms h2 ON v2.id = h2.venue_id WHERE h2.room_type = ? AND v2.name = ? AND v2.status = 'Available')
        ");
    }
    
    $stmt->bind_param("ssss", $room_type, $room_name, $room_type, $room_name);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $start = new DateTime($row['start_date']);
        $end = new DateTime($row['end_date']);
        while ($start < $end) {
            $blocked_dates[] = $start->format('Y-m-d');
            $start->modify('+1 day');
        }
        if ($row['start_date'] === $row['end_date']) {
            $blocked_dates[] = $start->format('Y-m-d');
        }
    }
    $stmt->close();
    echo json_encode(array_values(array_unique($blocked_dates)));

} else {
    echo json_encode([]);
}
$conn->close();
?>