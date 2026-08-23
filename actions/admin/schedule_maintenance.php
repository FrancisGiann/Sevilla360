<?php
session_start();
require '../../config/db_connect.php';
require_once '../../includes/booking_rules.php';

// Security check
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'staff' && $_SESSION['role'] !== 'admin')) {
    echo "Error|Unauthorized access.";
    exit();
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
    try {
        $conn->begin_transaction();

        $category = $_POST['category'];
        $venue_id = (int)$_POST['venue_id']; // Passed from JS
        $area = trim($_POST['area']);
        $type = $_POST['type'];
        $notes = trim($_POST['notes']);
        $is_blocking = $_POST['block_unit'] === 'true';
        $sDate = $_POST['start_date'];
        $eDate = $_POST['end_date'];
        
        if ($venue_id <= 0) throw new Exception("Invalid venue ID.");

        $start_dt = DateTime::createFromFormat('!Y-m-d', $sDate);
        $end_dt = DateTime::createFromFormat('!Y-m-d', $eDate);
        if (!$start_dt || !$end_dt || $start_dt->format('Y-m-d') !== $sDate || $end_dt->format('Y-m-d') !== $eDate || $end_dt < $start_dt) {
            throw new Exception("Invalid maintenance date range.");
        }

        // Fetch actual venue name and category for the logs and messages
        $stmt_venue = $conn->prepare("SELECT category, name FROM venues WHERE id = ? FOR UPDATE");
        $stmt_venue->bind_param("i", $venue_id);
        $stmt_venue->execute();
        $v_row = $stmt_venue->get_result()->fetch_assoc();
        if (!$v_row) throw new Exception("Venue not found.");
        
        $real_venue_name = $v_row['name'];
        $is_overnight = in_array($v_row['category'], ['Hotel Room', 'Resort Villa'], true);

        // Check against existing scheduled maintenance
        $maint_chk = $conn->prepare("
            SELECT id FROM maintenance 
            WHERE venue_id = ? AND status = 'Scheduled' 
            AND (start_date <= ? AND end_date >= ?)
        ");
        $maint_chk->bind_param("iss", $venue_id, $eDate, $sDate);
        $maint_chk->execute();
        if ($maint_chk->get_result()->num_rows > 0) {
            throw new Exception("There is already scheduled maintenance for this unit during these dates.");
        }

        // Maintenance records are the single source of truth for maintenance
        // availability. Do not create a fake customer booking here: it would
        // be counted twice and could remain blocking after completion.
        if ($is_blocking) {
            // Check if dates are already booked by customers
            // If hotel room, use exclusive checkout interval (start_date < eDate AND end_date > sDate)
            // If event hall, use inclusive checkout interval (start_date <= eDate AND end_date >= sDate)
            $overlap_condition = booking_overlap_sql($v_row['category'], 'b.start_date', 'b.end_date');
            // A pending Event Hall row is an inquiry, not an exclusive
            // reservation. Hotel/Villa pending bookings retain their current
            // blocking behavior.
            $booking_status_filter = $v_row['category'] === 'Event Hall'
                ? "IN ('Confirmed', 'Completed')"
                : "IN ('Pending', 'Confirmed', 'Completed')";
            
            $check_stmt = $conn->prepare("
                SELECT id FROM bookings b
                WHERE b.venue_id = ? AND b.booking_status $booking_status_filter AND b.source <> 'Maintenance'
                AND $overlap_condition
            ");
            $check_stmt->bind_param("iss", $venue_id, $eDate, $sDate);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                throw new Exception("Cannot block these dates. A customer already has a direct booking overlapping this schedule!");
            }

            // For hotel rooms, also check add-on rooms
            if ($is_overnight && $v_row['category'] === 'Hotel Room') {
                $check_addon = $conn->prepare("
                    SELECT br.id FROM booking_rooms br
                    JOIN bookings b ON br.booking_id = b.id
                    JOIN venues parent_v ON parent_v.id = b.venue_id
                    WHERE br.venue_id = ? AND b.booking_status IN ('Pending', 'Confirmed')
                    AND NOT (b.booking_status = 'Pending' AND parent_v.category = 'Event Hall')
                    AND b.source <> 'Maintenance'
                    AND " . booking_overlap_sql('Hotel Room', 'br.start_date', 'br.end_date') . "
                ");
                $check_addon->bind_param("iss", $venue_id, $eDate, $sDate);
                $check_addon->execute();
                if ($check_addon->get_result()->num_rows > 0) {
                    throw new Exception("Cannot block these dates. A customer has booked this room as an add-on overlapping this schedule!");
                }
            }

        }

        // ==========================================
        // ACTUALLY INSERT INTO MAINTENANCE TABLE
        // ==========================================
        $maint_stmt = $conn->prepare("INSERT INTO maintenance (venue_id, start_date, end_date, maintenance_type, notes, is_blocking) VALUES (?, ?, ?, ?, ?, ?)");
        $maint_block_val = $is_blocking ? 1 : 0;
        $maint_stmt->bind_param("issssi", $venue_id, $sDate, $eDate, $type, $notes, $maint_block_val);
        $maint_stmt->execute();

         if (isset($_SESSION['user_id'])) {
            $log_user = $_SESSION['user_id'];
            $log_module = 'Maintenance';
            $log_action = "Scheduled maintenance for $real_venue_name from $sDate to $eDate"; 
            $log_ip = $_SERVER['REMOTE_ADDR'];

            $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, ?, ?, ?)");
            $audit_stmt->bind_param("isss", $log_user, $log_module, $log_action, $log_ip);
            $audit_stmt->execute();
        }

        $conn->commit();
        echo "Success|Maintenance successfully scheduled for $real_venue_name.\nDates: $sDate to $eDate.";

    } catch (Exception $e) {
        $conn->rollback();
        echo "Error|" . $e->getMessage();
    }
}
?>
