<?php
require_once __DIR__ . '/../../includes/session_init.php';
require '../../config/db_connect.php';
require_once '../../includes/booking_rules.php';

function lock_dates_bind_params(mysqli_stmt $statement, string $types, array $values): void
{
    $params = [$types];
    foreach ($values as $index => $value) $params[] = &$values[$index];
    call_user_func_array([$statement, 'bind_param'], $params);
}

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

    $room_type = trim((string)($_POST['room_type'] ?? ''));
    $room_name = trim((string)($_POST['room_name'] ?? ''));
    $explicit_venue_raw = trim((string)($_POST['venue_id'] ?? ''));
    $explicit_venue_id = null;
    if ($explicit_venue_raw !== '') {
        $validated_venue_id = filter_var($explicit_venue_raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($validated_venue_id === false) {
            http_response_code(422);
            echo "Error|Invalid venue selection.";
            exit;
        }
        $explicit_venue_id = (int)$validated_venue_id;
    }
    // Validate date formats
    $start_dt = DateTimeImmutable::createFromFormat('!Y-m-d', $start_date);
    $end_dt   = DateTimeImmutable::createFromFormat('!Y-m-d', $end_date);
    $is_hotel_request = !in_array($room_type, ['Event Hall', 'Resort Villa'], true);
    if (!$start_dt || !$end_dt || $end_dt < $start_dt || ($is_hotel_request && $end_dt <= $start_dt)) {
        echo "Error|Invalid date range.";
        exit;
    }
    try {
        validate_villa_stay_dates($room_type, trim((string)($_POST['stay_type'] ?? 'Day Time Stay')), $start_dt, $end_dt);
    } catch (InvalidArgumentException $e) {
        echo "Error|" . $e->getMessage();
        exit;
    }

    // Event Hall customer submissions are inquiries, not reservations. They
    // are checked again when submitted and must never create a primary hold.
    // Staff walk-in bookings retain their operational hold flow.
    if (!$is_staff_booking && $room_type === 'Event Hall') {
        http_response_code(422);
        echo "Error|Event Hall inquiries do not create date locks.";
        exit;
    }

    // Customers may select a concrete hotel unit, but the posted ID is only
    // an input to this authoritative lookup. Never allow an Event Hall,
    // villa, unavailable unit, or mismatched room type/name to be locked.
    if (!$is_staff_booking && $explicit_venue_id !== null) {
        $customer_venue = $conn->prepare(
            "SELECT v.id
             FROM venues v
             INNER JOIN hotel_rooms h ON h.venue_id = v.id
             WHERE v.id = ?
               AND v.category = 'Hotel Room'
               AND v.status = 'Available'
               AND v.name = ?
               AND h.room_type = ?
             LIMIT 1"
        );
        $customer_venue->bind_param('iss', $explicit_venue_id, $room_name, $room_type);
        $customer_venue->execute();
        if ($customer_venue->get_result()->num_rows === 0) {
            http_response_code(422);
            echo "Error|The selected hotel room is invalid or unavailable.";
            exit;
        }
    }

    $transaction_started = false;
    try {
        $conn->begin_transaction();
        $transaction_started = true;
        // HYGIENE: clean up expired locks
        $conn->query("DELETE FROM booking_locks WHERE expires_at < NOW()");

        $venue_id = null;
        $is_hotel = !($room_type === 'Event Hall' || $room_type === 'Resort Villa');
        
        $overlap_cond = booking_overlap_sql($is_hotel ? 'Hotel Room' : $room_type);

        // Remove only the session's previous primary lock before taking venue
        // row locks. Add-on locks are tracked separately and must survive this
        // refresh; keeping this order aligned with the add-on synchronizer avoids
        // lock-order inversions between the two endpoints.
        $previous_primary_id = (int)($_SESSION['locked_venue_id'] ?? 0);
        $tracked_addon_ids = array_values(array_filter(array_map('intval', (array)($_SESSION['walkin_addon_lock_ids'] ?? [])), static fn(int $id): bool => $id > 0));
        if ($previous_primary_id > 0) {
            if ($tracked_addon_ids) {
                $placeholders = implode(',', array_fill(0, count($tracked_addon_ids), '?'));
                $stmt_previous = $conn->prepare("DELETE FROM booking_locks WHERE venue_id = ? AND session_id = ? AND id NOT IN ($placeholders)");
                lock_dates_bind_params($stmt_previous, 'is' . str_repeat('i', count($tracked_addon_ids)), [$previous_primary_id, $session_id, ...$tracked_addon_ids]);
            } else {
                $stmt_previous = $conn->prepare('DELETE FROM booking_locks WHERE venue_id = ? AND session_id = ?');
                $stmt_previous->bind_param('is', $previous_primary_id, $session_id);
            }
            $stmt_previous->execute();
        }

        if (!$is_hotel) {
            $stmt = $conn->prepare("SELECT id FROM venues WHERE category = ? AND name = ? LIMIT 1 FOR UPDATE");
            $stmt->bind_param("ss", $room_type, $room_name);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 0) throw new Exception("Venue not found.");
            $venue_id = $result->fetch_assoc()['id'];

            // 1. Check Maintenance
            $chk_maint = $conn->prepare("SELECT id FROM maintenance WHERE venue_id = ? AND is_blocking = 1 AND status = 'Scheduled' AND " . maintenance_overlap_sql());
            $chk_maint->bind_param("iss", $venue_id, $end_date, $start_date);
            $chk_maint->execute();
            if ($chk_maint->get_result()->num_rows > 0) throw new Exception("These dates are currently under maintenance.");

            // 2. Check existing bookings
            $status_filter = ($room_type === 'Event Hall') ? "IN ('Confirmed', 'Completed')" : "IN ('Pending', 'Confirmed', 'Completed')";
            $chk_booking = $conn->prepare("SELECT id FROM bookings WHERE venue_id = ? AND booking_status $status_filter AND source <> 'Maintenance' AND " . booking_overlap_sql($room_type));
            $chk_booking->bind_param("iss", $venue_id, $end_date, $start_date);
            $chk_booking->execute();
            if ($chk_booking->get_result()->num_rows > 0) throw new Exception("These dates are already booked.");

            // 3. Check active locks (Event Halls allow multiple locks)
            if ($room_type !== 'Event Hall') {
                $chk_lock = $conn->prepare("SELECT id FROM booking_locks WHERE venue_id = ? AND session_id != ? AND expires_at > NOW() AND " . booking_overlap_sql($room_type));
                $chk_lock->bind_param("isss", $venue_id, $session_id, $end_date, $start_date);
                $chk_lock->execute();
                if ($chk_lock->get_result()->num_rows > 0) throw new Exception("Another user is currently booking these dates.");
            }
        } else {
            // HOTEL ROOM: Find any available unit in the group
            // For Walk-in, venue_id might be explicitly provided
            if ($explicit_venue_id !== null) {
                $explicit_vid = $explicit_venue_id;
                if ($is_staff_booking) {
                    $stmt_inv = $conn->prepare("SELECT id FROM venues WHERE id = ? AND status = 'Available' FOR UPDATE");
                    $stmt_inv->bind_param("i", $explicit_vid);
                } else {
                    // Re-check the full customer selection under the row lock
                    // so a concurrent status/category change cannot turn a
                    // previously valid request into a lock on another unit.
                    $stmt_inv = $conn->prepare(
                        "SELECT v.id
                         FROM venues v
                         INNER JOIN hotel_rooms h ON h.venue_id = v.id
                         WHERE v.id = ?
                           AND v.category = 'Hotel Room'
                           AND v.status = 'Available'
                           AND v.name = ?
                           AND h.room_type = ?
                         LIMIT 1
                         FOR UPDATE"
                    );
                    $stmt_inv->bind_param("iss", $explicit_vid, $room_name, $room_type);
                }
            } else {
                $stmt_inv = $conn->prepare("
                    SELECT v.id 
                    FROM venues v 
                    JOIN hotel_rooms h ON v.id = h.venue_id 
                    WHERE h.room_type = ? AND v.name = ? AND v.status = 'Available'
                    ORDER BY v.id
                    FOR UPDATE
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
                $maint = $conn->prepare("SELECT id FROM maintenance WHERE venue_id = ? AND is_blocking = 1 AND status = 'Scheduled' AND " . maintenance_overlap_sql());
                $maint->bind_param("iss", $vid, $end_date, $start_date);
                $maint->execute();
                if ($maint->get_result()->num_rows > 0) continue;
                
                // Check Direct Bookings
                $bk = $conn->prepare("SELECT id FROM bookings WHERE venue_id = ? AND booking_status IN ('Pending', 'Confirmed', 'Completed') AND source <> 'Maintenance' AND $overlap_cond");
                $bk->bind_param("iss", $vid, $end_date, $start_date);
                $bk->execute();
                if ($bk->get_result()->num_rows > 0) continue;

                // Check Add-on Bookings
                $addons_overlap = str_replace(["start_date", "end_date"], ["br.start_date", "br.end_date"], $overlap_cond);
                $addons = $conn->prepare("
                    SELECT br.id FROM booking_rooms br
                    JOIN bookings b ON br.booking_id = b.id
                    JOIN venues parent_v ON parent_v.id = b.venue_id
                    WHERE br.venue_id = ? AND b.booking_status IN ('Pending', 'Confirmed', 'Completed')
                    AND NOT (b.booking_status = 'Pending' AND parent_v.category = 'Event Hall')
                    AND b.source <> 'Maintenance'
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
                if ($source === 'walkin' && $explicit_venue_id !== null) {
                    throw new Exception("This specific unit is fully booked or locked on these dates.");
                } else {
                    throw new Exception("All rooms in this category are fully booked or locked on these dates.");
                }
            }
            $venue_id = $assigned_venue_id;
        }

        // Insert the temporary primary lock for this user's session.
        $expires_at = date('Y-m-d H:i:s', strtotime("+$lock_mins minutes"));
        $stmt_lock = $conn->prepare("
            INSERT INTO booking_locks (venue_id, session_id, source, start_date, end_date, expires_at) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt_lock->bind_param("isssss", $venue_id, $session_id, $source, $start_date, $end_date, $expires_at);
        $stmt_lock->execute();

        if (!$conn->commit()) throw new RuntimeException('Dates could not be held.');
        $_SESSION['locked_venue_id'] = $venue_id;
        // Keep the legacy pipe-delimited response fields intact and append the
        // authoritative expiry as a Unix timestamp for countdown consumers.
        echo "Success|Dates locked|" . strtotime($expires_at);

    } catch (Exception $e) {
        if ($transaction_started) $conn->rollback();
        echo "Error|" . $e->getMessage();
    }
}
?>
