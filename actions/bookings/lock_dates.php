<?php
session_start();
require '../../config/db_connect.php';

$requested_source = $_POST['source'] ?? 'online';
$is_staff_booking = ($requested_source === 'walkin');
$is_authorized = $is_staff_booking
    ? isset($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['staff', 'admin'], true)
    : isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'customer';
if (!$is_authorized) {
    http_response_code(401);
    echo "Error|Your session has expired. Please sign in again.";
    exit;
}

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
    $source     = $is_staff_booking ? 'walkin' : 'online'; // walkin or online
    $lock_mins  = ($source === 'walkin') ? 60 : 30; // 1 hour for walk-in, 30 min for online

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
        $is_hotel = !($room_type === 'Event Hall' || $room_type === 'Resort Villa');
        
        $overlap_cond = $is_hotel ? "(start_date < ? AND end_date > ?)" : "(start_date <= ? AND end_date >= ?)";

        if (!$is_hotel) {
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
            $chk_booking = $conn->prepare("SELECT id FROM bookings WHERE venue_id = ? AND booking_status $status_filter AND (start_date <= ? AND end_date >= ?)");
            $chk_booking->bind_param("iss", $venue_id, $end_date, $start_date);
            $chk_booking->execute();
            if ($chk_booking->get_result()->num_rows > 0) throw new Exception("These dates are already booked.");

            // 3. Check active locks (Event Halls allow multiple locks)
            if ($room_type !== 'Event Hall') {
                $chk_lock = $conn->prepare("SELECT id FROM booking_locks WHERE venue_id = ? AND session_id != ? AND expires_at > NOW() AND (start_date <= ? AND end_date >= ?)");
                $chk_lock->bind_param("isss", $venue_id, $session_id, $end_date, $start_date);
                $chk_lock->execute();
                if ($chk_lock->get_result()->num_rows > 0) throw new Exception("Another user is currently booking these dates.");
            }
        } else {
            // HOTEL ROOM: Find any available unit in the group
            // For Walk-in, venue_id might be explicitly provided
            if (!empty($_POST['venue_id'])) {
                $explicit_vid = (int)$_POST['venue_id'];
                $stmt_inv = $conn->prepare("SELECT id FROM venues WHERE id = ? AND status = 'Available'");
                $stmt_inv->bind_param("i", $explicit_vid);
            } else {
                $stmt_inv = $conn->prepare("
                    SELECT v.id 
                    FROM venues v 
                    JOIN hotel_rooms h ON v.id = h.venue_id 
                    WHERE h.room_type = ? AND v.name = ? AND v.status = 'Available'
                ");
                $stmt_inv->bind_param("ss", $room_type, $room_name);
            }
            $stmt_inv->execute();
            $res_inv = $stmt_inv->get_result();
            
            if ($res_inv->num_rows === 0) throw new Exception("Room not found or unavailable.");
            
            $assigned_venue_id = null;
            while ($row = $res_inv->fetch_assoc()) {
                $vid = $row['id'];
                
                // Check Maintenance (maintenance ALWAYS blocks inclusively)
                $maint = $conn->prepare("SELECT id FROM maintenance WHERE venue_id = ? AND is_blocking = 1 AND (start_date <= ? AND end_date >= ?)");
                $maint->bind_param("iss", $vid, $end_date, $start_date);
                $maint->execute();
                if ($maint->get_result()->num_rows > 0) continue;
                
                // Check Direct Bookings
                $bk = $conn->prepare("SELECT id FROM bookings WHERE venue_id = ? AND booking_status IN ('Pending', 'Confirmed', 'Completed') AND $overlap_cond");
                $bk->bind_param("iss", $vid, $end_date, $start_date);
                $bk->execute();
                if ($bk->get_result()->num_rows > 0) continue;

                // Check Add-on Bookings
                $addons_overlap = str_replace(["start_date", "end_date"], ["br.start_date", "br.end_date"], $overlap_cond);
                $addons = $conn->prepare("
                    SELECT br.id FROM booking_rooms br
                    JOIN bookings b ON br.booking_id = b.id
                    WHERE br.venue_id = ? AND b.booking_status IN ('Pending', 'Confirmed', 'Completed') 
                    AND $addons_overlap
                ");
                $addons->bind_param("iss", $vid, $end_date, $start_date);
                $addons->execute();
                if ($addons->get_result()->num_rows > 0) continue;

                // Check Locks
                $lk = $conn->prepare("SELECT id FROM booking_locks WHERE venue_id = ? AND session_id != ? AND expires_at > NOW() AND $overlap_cond");
                $lk->bind_param("isss", $vid, $session_id, $end_date, $start_date);
                $lk->execute();
                if ($lk->get_result()->num_rows > 0) continue;

                $assigned_venue_id = $vid;
                break; // Found an available room!
            }

            if (!$assigned_venue_id) {
                if ($source === 'walkin' && !empty($_POST['venue_id'])) {
                    throw new Exception("This specific unit is fully booked or locked on these dates.");
                } else {
                    throw new Exception("All rooms in this category are fully booked or locked on these dates.");
                }
            }
            $venue_id = $assigned_venue_id;
        }

        // Insert or Update the temporary lock for this user's session
        $expires_at = date('Y-m-d H:i:s', strtotime("+$lock_mins minutes"));
        $stmt_lock = $conn->prepare("
            INSERT INTO booking_locks (venue_id, session_id, source, start_date, end_date, expires_at) 
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE venue_id = VALUES(venue_id), start_date = VALUES(start_date), end_date = VALUES(end_date), expires_at = VALUES(expires_at), source = VALUES(source)
        ");
        $stmt_lock->bind_param("isssss", $venue_id, $session_id, $source, $start_date, $end_date, $expires_at);
        $stmt_lock->execute();

        $_SESSION['locked_venue_id'] = $venue_id;
        echo "Success|Dates locked.";

    } catch (Exception $e) {
        echo "Error|" . $e->getMessage();
    }
}
?>
