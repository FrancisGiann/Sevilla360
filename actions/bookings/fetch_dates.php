<?php
session_start();
require '../../config/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $room_type = $_POST['room_type'] ?? '';
    $room_name = $_POST['room_name'] ?? '';

    $bookedDates = [];

    // Find the Venue ID
    if ($room_type === 'Event Hall' || $room_type === 'Resort Villa') {
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

        // THE FIX: If it is an Event Hall, ONLY block 'Confirmed' dates. 
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

    echo json_encode(array_values(array_unique($bookedDates)));
}
?>