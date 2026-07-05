<?php
session_start();
require '../../config/db_connect.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $room_type = $_POST['room_type'];
    $room_name = $_POST['room_name'];
    $sDate = $_POST['start_date'];
    $eDate = $_POST['end_date'];
    $session_id = session_id(); // Gets the unique ID of the user's current browser session

    // 1. Find an available room that is NOT booked AND NOT locked by someone else
    $stmt = $conn->prepare("
        SELECT v.id 
        FROM venues v 
        JOIN hotel_rooms h ON v.id = h.venue_id 
        WHERE h.room_type = ? AND v.name = ? AND v.status = 'Available'
        AND v.id NOT IN (
            SELECT venue_id FROM bookings 
            WHERE booking_status IN ('Pending', 'Confirmed') 
            AND (start_date < ? AND end_date > ?)
        )
        AND v.id NOT IN (
            SELECT venue_id FROM booking_locks 
            WHERE expires_at > NOW() 
            AND (start_date < ? AND end_date > ?)
        )
        LIMIT 1
    ");
    $stmt->bind_param("ssssss", $room_type, $room_name, $eDate, $sDate, $eDate, $sDate);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo "Error|No rooms available for these dates. Someone else might be booking it right now!";
        exit();
    }

    $venue_id = $result->fetch_assoc()['id'];

    // 2. Lock it for 30 minutes!
    $expires_at = date('Y-m-d H:i:s', strtotime('+30 minutes'));
    
    $lock_stmt = $conn->prepare("INSERT INTO booking_locks (venue_id, session_id, start_date, end_date, expires_at) VALUES (?, ?, ?, ?, ?)");
    $lock_stmt->bind_param("issss", $venue_id, $session_id, $sDate, $eDate, $expires_at);
    
    if ($lock_stmt->execute()) {
        // Save the locked venue_id in the session so submit_walkin.php knows which room we reserved!
        $_SESSION['locked_venue_id'] = $venue_id;
        echo "Success|Locked";
    } else {
        echo "Error|Failed to lock dates.";
    }
}
?>