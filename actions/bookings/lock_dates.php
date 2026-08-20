<?php
session_start();
require '../../config/db_connect.php';

// ==========================================
// CSRF PROTECTION GUARD (TEXT)
// ==========================================
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo "Error|CSRF validation failed. Unauthorized request.";
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $start_date = $_POST['start_date'];
    $end_date   = $_POST['end_date'];
    $session_id = session_id();

    // Validate date formats
    $start_dt = DateTime::createFromFormat('Y-m-d', $start_date);
    $end_dt   = DateTime::createFromFormat('Y-m-d', $end_date);
    if (!$start_dt || !$end_dt || $end_dt < $start_dt) {
        echo "Error|Invalid date range.";
        exit;
    }

    try {
        // HYGIENE: clean up expired locks
        $conn->query("DELETE FROM booking_locks WHERE expires_at < NOW()");

        $venue_id = null;
        $room_type = $_POST['room_type'] ?? '';
        $room_name = $_POST['room_name'] ?? '';

        if ($room_type === 'Event Hall' || $room_type === 'Resort Villa') {
            $stmt = $conn->prepare("SELECT id FROM venues WHERE category = ? AND name = ? LIMIT 1");
            $stmt->bind_param("ss", $room_type, $room_name);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 0) throw new Exception("Venue not found.");
            $venue_id = $result->fetch_assoc()['id'];

            // 1. Check Maintenance
            $chk_maint = $conn->prepare("SELECT id FROM maintenance WHERE venue_id = ? AND is_blocking = 1 AND (start_date <= ? AND end_date >= ?)");
            $chk_maint->bind_param("iss", $venue_id, $end_date, $start_date);
            $chk_maint->execute();
            if ($chk_maint->get_result()->num_rows > 0) throw new Exception("These dates are currently under maintenance.");

            // 2. Check existing bookings
            $status_filter = ($room_type === 'Event Hall') ? "IN ('Confirmed', 'Completed')" : "IN ('Pending', 'Confirmed', 'Completed')";
            $chk_booking = $conn->query("SELECT id FROM bookings WHERE venue_id = $venue_id AND booking_status $status_filter AND (start_date <= '$end_date' AND end_date >= '$start_date')");
            if ($chk_booking->num_rows > 0) throw new Exception("These dates are already booked.");

            // 3. Check active locks
            if ($room_type !== 'Event Hall') {
                $chk_lock = $conn->prepare("SELECT id FROM booking_locks WHERE venue_id = ? AND session_id != ? AND expires_at > NOW() AND (start_date <= ? AND end_date >= ?)");
                $chk_lock->bind_param("isss", $venue_id, $session_id, $end_date, $start_date);
                $chk_lock->execute();
                if ($chk_lock->get_result()->num_rows > 0) throw new Exception("Another user is currently booking these dates.");
            }
        } else {
            // HOTEL ROOM: Find any available unit in the group
            $stmt_inv = $conn->prepare("
                SELECT v.id 
                FROM venues v 
                JOIN hotel_rooms h ON v.id = h.venue_id 
                WHERE h.room_type = ? AND v.name = ? AND v.status = 'Available'
            ");
            $stmt_inv->bind_param("ss", $room_type, $room_name);
            $stmt_inv->execute();
            $res_inv = $stmt_inv->get_result();
            
            if ($res_inv->num_rows === 0) throw new Exception("Room group not found.");
            
            $assigned_venue_id = null;
            while ($row = $res_inv->fetch_assoc()) {
                $vid = $row['id'];
                
                // Check Maintenance
                $maint = $conn->query("SELECT id FROM maintenance WHERE venue_id = $vid AND is_blocking = 1 AND (start_date <= '$end_date' AND end_date >= '$start_date')");
                if ($maint->num_rows > 0) continue;
                
                // Check Direct Bookings
                $bk = $conn->query("SELECT id FROM bookings WHERE venue_id = $vid AND booking_status IN ('Pending', 'Confirmed', 'Completed') AND (start_date <= '$end_date' AND end_date >= '$start_date')");
                if ($bk->num_rows > 0) continue;

                // Check Add-on Bookings
                $addons = $conn->query("
                    SELECT br.id FROM booking_rooms br
                    JOIN bookings b ON br.booking_id = b.id
                    WHERE br.venue_id = $vid AND b.booking_status IN ('Pending', 'Confirmed', 'Completed') 
                    AND (br.start_date <= '$end_date' AND br.end_date >= '$start_date')
                ");
                if ($addons->num_rows > 0) continue;

                // Check Locks
                $lk = $conn->prepare("SELECT id FROM booking_locks WHERE venue_id = ? AND session_id != ? AND expires_at > NOW() AND (start_date <= ? AND end_date >= ?)");
                $lk->bind_param("isss", $vid, $session_id, $end_date, $start_date);
                $lk->execute();
                if ($lk->get_result()->num_rows > 0) continue;

                $assigned_venue_id = $vid;
                break; // Found an available room!
            }

            if (!$assigned_venue_id) {
                throw new Exception("All rooms in this category are fully booked or locked on these dates.");
            }
            $venue_id = $assigned_venue_id;
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