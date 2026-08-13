<?php
session_start();
require '../../config/db_connect.php';

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
        $venue_name = $_POST['venue_name'];
        $area = trim($_POST['area']);
        $type = $_POST['type'];
        $notes = trim($_POST['notes']);
        $is_blocking = $_POST['block_unit'] === 'true';
        $sDate = $_POST['start_date'];
        $eDate = $_POST['end_date'];

        // 1. Find the specific Venue ID
        $stmt_venue = $conn->prepare("SELECT id FROM venues WHERE category = ? AND name = ? LIMIT 1");
        $stmt_venue->bind_param("ss", $category, $venue_name);
        $stmt_venue->execute();
        $venue_res = $stmt_venue->get_result();
        
        if ($venue_res->num_rows === 0) throw new Exception("Venue not found in database.");
        $venue_id = $venue_res->fetch_assoc()['id'];

        // 2. If Blocking Calendar, insert a "Maintenance Booking" lock
        if ($is_blocking) {
            $cust_res = $conn->query("SELECT id FROM customers WHERE first_name = 'SYSTEM' AND last_name = 'MAINTENANCE'");
            if ($cust_res->num_rows > 0) {
                $customer_id = $cust_res->fetch_assoc()['id'];
            } else {
                $conn->query("INSERT INTO customers (first_name, last_name, email, phone) VALUES ('SYSTEM', 'MAINTENANCE', 'admin@sevilla360.com', '00000000000')");
                $customer_id = $conn->insert_id;
            }

            // Check if dates are already booked
            $check_stmt = $conn->prepare("
                SELECT id FROM bookings 
                WHERE venue_id = ? AND booking_status IN ('Pending', 'Confirmed') 
                AND (start_date <= ? AND end_date >= ?)
            ");
            $check_stmt->bind_param("iss", $venue_id, $eDate, $sDate);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                throw new Exception("Cannot block these dates. A customer already has a booking overlapping this schedule!");
            }

            // Insert Maintenance Block
            $ref_no = "MAINT-" . mt_rand(1000, 9999);
            $book_stmt = $conn->prepare("
                INSERT INTO bookings (reference_no, customer_id, venue_id, start_date, end_date, guests_count, base_amount, total_amount, payment_scheme, booking_status, payment_status, source) 
                VALUES (?, ?, ?, ?, ?, 0, 0, 0, '100% Full', 'Confirmed', 'Paid', 'Maintenance')
            ");
            $book_stmt->bind_param("siiss", $ref_no, $customer_id, $venue_id, $sDate, $eDate);
            $book_stmt->execute();
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
            $log_action = "Scheduled maintenance for Venue ID #$venue_id from $sDate to $eDate"; 
            $log_ip = $_SERVER['REMOTE_ADDR'];

            $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, ?, ?, ?)");
            $audit_stmt->bind_param("isss", $log_user, $log_module, $log_action, $log_ip);
            $audit_stmt->execute();
        }

        $conn->commit();
        echo "Success|Maintenance scheduled successfully.";

    } catch (Exception $e) {
        $conn->rollback();
        echo "Error|" . $e->getMessage();
    }
}
?>