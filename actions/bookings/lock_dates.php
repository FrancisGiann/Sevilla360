<?php
session_start();
require '../../config/db_connect.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $room_type = $_POST['room_type'];
    $room_name = $_POST['room_name'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $session_id = session_id();

    try {
        if ($room_type === 'Event Hall' || $room_type === 'Resort Villa') {
            $stmt = $conn->prepare("SELECT id FROM venues WHERE category = ? AND name = ? LIMIT 1");
        } else {
            $stmt = $conn->prepare("SELECT v.id FROM venues v JOIN hotel_rooms h ON v.id = h.venue_id WHERE h.room_type = ? AND v.name = ? LIMIT 1");
        }
        $stmt->bind_param("ss", $room_type, $room_name);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) throw new Exception("Venue not found.");
        $venue_id = $result->fetch_assoc()['id'];

        // 1. Check Maintenance (Always blocks)
        $chk_maint = $conn->prepare("SELECT id FROM maintenance WHERE venue_id = ? AND is_blocking = 1 AND (start_date <= ? AND end_date >= ?)");
        $chk_maint->bind_param("iss", $venue_id, $end_date, $start_date);
        $chk_maint->execute();
        if ($chk_maint->get_result()->num_rows > 0) throw new Exception("These dates are currently under maintenance.");

        // 2. Check existing bookings
        // THE FIX: For Event Halls, only block if Confirmed. For others, block if Pending or Confirmed.
        if ($room_type === 'Event Hall') {
            $chk_booking = $conn->prepare("SELECT id FROM bookings WHERE venue_id = ? AND booking_status IN ('Confirmed', 'Completed') AND (start_date <= ? AND end_date >= ?)");
        } else {
            $chk_booking = $conn->prepare("SELECT id FROM bookings WHERE venue_id = ? AND booking_status IN ('Pending', 'Confirmed', 'Completed') AND (start_date <= ? AND end_date >= ?)");
        }
        $chk_booking->bind_param("iss", $venue_id, $end_date, $start_date);
        $chk_booking->execute();
        if ($chk_booking->get_result()->num_rows > 0) throw new Exception("These dates are already booked.");

        // 3. Check active temporary Locks (Only strict block if it's NOT an Event Hall)
        if ($room_type !== 'Event Hall') {
            $chk_lock = $conn->prepare("SELECT id FROM booking_locks WHERE venue_id = ? AND session_id != ? AND expires_at > NOW() AND (start_date <= ? AND end_date >= ?)");
            $chk_lock->bind_param("isss", $venue_id, $session_id, $end_date, $start_date);
            $chk_lock->execute();
            if ($chk_lock->get_result()->num_rows > 0) throw new Exception("Another user is currently booking these dates.");
        }

        // Insert or Update the temporary lock for this user's session
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        $stmt_lock = $conn->prepare("
            INSERT INTO booking_locks (venue_id, session_id, start_date, end_date, expires_at) 
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE start_date = VALUES(start_date), end_date = VALUES(end_date), expires_at = VALUES(expires_at)
        ");
        $stmt_lock->bind_param("issss", $venue_id, $session_id, $start_date, $end_date, $expires_at);
        $stmt_lock->execute();

        $_SESSION['locked_venue_id'] = $venue_id;
        echo "Success|Dates locked.";

    } catch (Exception $e) {
        echo "Error|" . $e->getMessage();
    }
}
?>