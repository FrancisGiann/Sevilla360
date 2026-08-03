<?php
session_start();
require '../../config/db_connect.php';

// Security check
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superadmin')) {
    echo "Error|Unauthorized access.";
    exit();
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

        // 2. If Blocking Calendar, insert a "Maintenance Booking"
        if ($is_blocking) {
            // Check if dummy "Maintenance" customer exists, if not create it
            $cust_res = $conn->query("SELECT id FROM customers WHERE first_name = 'SYSTEM' AND last_name = 'MAINTENANCE'");
            if ($cust_res->num_rows > 0) {
                $customer_id = $cust_res->fetch_assoc()['id'];
            } else {
                $conn->query("INSERT INTO customers (first_name, last_name, email, phone) VALUES ('SYSTEM', 'MAINTENANCE', 'admin@sevilla360.com', '00000000000')");
                $customer_id = $conn->insert_id;
            }

            // Check if dates are already booked by a real customer to prevent overlap
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

         if (isset($_SESSION['user_id'])) {
            $log_user = $_SESSION['user_id'];
            $log_module = 'Maintenance';
            // Assuming you have variables $venue_name, $start_date, $end_date in your script
            $log_action = "Scheduled maintenance for Venue ID #$venue_id from $start_date to $end_date"; 
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