<?php
session_start();
header('Content-Type: application/json'); // Force JSON output so JS never crashes
require '../../config/db_connect.php';

try {
    // 1. AUTOMATED CLEANUP: Cancel abandoned "Pending" online bookings older than 30 minutes
    $conn->query("
        UPDATE bookings 
        SET booking_status = 'Cancelled' 
        WHERE booking_status = 'Pending' 
          AND payment_status = 'Unpaid' 
          AND source = 'Online' 
          AND created_at < NOW() - INTERVAL 30 MINUTE
    ");

    // Safely accept both GET and POST requests
    $room_type = $_REQUEST['room_type'] ?? '';
    $room_name = $_REQUEST['room_name'] ?? '';

    $bookedDates = [];

    // If no room is selected yet, just return an empty array safely
    if (empty($room_type) || empty($room_name)) {
        echo json_encode([]);
        exit;
    }

    // Find the Venue ID
    if ($room_type === 'Event Hall' || $room_type === 'Resort Villa' || $room_type === 'Hotel Room') {
        $stmt = $conn->prepare("SELECT id FROM venues WHERE category = ? AND name = ? LIMIT 1");
    } else {
        $stmt = $conn->prepare("
            SELECT v.id FROM venues v JOIN hotel_rooms h ON v.id = h.venue_id 
            WHERE h.room_type = ? AND v.name = ? LIMIT 1
        ");
    }
    $stmt->bind_param("ss", $room_type, $room_name);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $venue_id = $result->fetch_assoc()['id'];

        // If it is an Event Hall, ONLY block 'Confirmed' dates. 
        // If it's a Hotel/Villa, block both 'Pending' and 'Confirmed'.
        if ($room_type === 'Event Hall') {
            $stmt_bookings = $conn->prepare("SELECT start_date, end_date FROM bookings WHERE venue_id = ? AND booking_status IN ('Confirmed', 'Completed')");
        } else {
            $stmt_bookings = $conn->prepare("SELECT start_date, end_date FROM bookings WHERE venue_id = ? AND booking_status IN ('Pending', 'Confirmed', 'Completed')");
        }
        
        $stmt_bookings->bind_param("i", $venue_id);
        $stmt_bookings->execute();
        $res_bookings = $stmt_bookings->get_result();

        while ($row = $res_bookings->fetch_assoc()) {
            $currentDate = new DateTime($row['start_date']);
            $endDate = new DateTime($row['end_date']);

            while ($currentDate <= $endDate) {
                $bookedDates[] = $currentDate->format('Y-m-d');
                $currentDate->modify('+1 day');
            }
        }

        // Also fetch active maintenance blocks
        $stmt_maint = $conn->prepare("SELECT start_date, end_date FROM maintenance WHERE venue_id = ? AND is_blocking = 1");
        $stmt_maint->bind_param("i", $venue_id);
        $stmt_maint->execute();
        $res_maint = $stmt_maint->get_result();

        while ($row = $res_maint->fetch_assoc()) {
            $currentDate = new DateTime($row['start_date']);
            $endDate = new DateTime($row['end_date']);
            while ($currentDate <= $endDate) {
                $bookedDates[] = $currentDate->format('Y-m-d');
                $currentDate->modify('+1 day');
            }
        }
    }

    // Always output valid JSON
    echo json_encode(array_values(array_unique($bookedDates)));

} catch (Exception $e) {
    // If database fails, return empty array so calendar doesn't crash
    echo json_encode([]);
}
?>