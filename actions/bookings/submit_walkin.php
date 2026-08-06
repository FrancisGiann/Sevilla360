<?php
session_start();
require '../../config/db_connect.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    try {
        $conn->begin_transaction();

        $ref_no = "SV-" . mt_rand(10000, 99999);
        $sDate = $_POST['start_date'];
        $eDate = $_POST['end_date'];
        $scheme = $_POST['payment_scheme'];
        $room_type = $_POST['room_type'];
        $room_name = $_POST['room_name'];
        $guests = (int)$_POST['guests'];

        // =========================================================================
        // SECURITY FIX: BACKEND PRICE VERIFICATION
        // Ensures the Walk-in price is 100% accurate based on the Database
        // =========================================================================
        if ($room_type === 'Event Hall' || $room_type === 'Resort Villa') {
            $stmt_room = $conn->prepare("
                SELECT id, category FROM venues 
                WHERE category = ? AND name = ? AND status = 'Available'
                AND id NOT IN (SELECT venue_id FROM bookings WHERE booking_status IN ('Pending', 'Confirmed') AND (start_date < ? AND end_date > ?))
                LIMIT 1
            ");
        } else {
            $stmt_room = $conn->prepare("
                SELECT v.id, v.category FROM venues v JOIN hotel_rooms h ON v.id = h.venue_id 
                WHERE h.room_type = ? AND v.name = ? AND v.status = 'Available'
                AND v.id NOT IN (SELECT venue_id FROM bookings WHERE booking_status IN ('Pending', 'Confirmed') AND (start_date < ? AND end_date > ?))
                LIMIT 1
            ");
        }
        $stmt_room->bind_param("ssss", $room_type, $room_name, $eDate, $sDate);
        $stmt_room->execute();
        $room_result = $stmt_room->get_result();
        
        if ($room_result->num_rows === 0) {
            throw new Exception("All units for this specific room were just booked by someone online! Please select different dates.");
        }
        $v_row = $room_result->fetch_assoc();
        $venue_id = $v_row['id'];
        $venue_category = $v_row['category'];

        // Math Calculation
        $start_dt = new DateTime($sDate);
        $end_dt = new DateTime($eDate);
        $nights = $start_dt->diff($end_dt)->days;
        if ($nights === 0) $nights = 1; 

        $true_total = 0;
        $base_amount = 0;

        if ($venue_category === 'Hotel Room') {
            $stmt = $conn->prepare("SELECT nightly_rate, base_capacity, extra_pax_rate FROM hotel_rooms WHERE venue_id = ?");
            $stmt->bind_param("i", $venue_id);
            $stmt->execute();
            $room = $stmt->get_result()->fetch_assoc();
            $base_amount = floatval($room['nightly_rate']);
            $true_total = $base_amount * $nights;
            if ($guests > $room['base_capacity']) {
                $true_total += (($guests - $room['base_capacity']) * floatval($room['extra_pax_rate']) * $nights);
            }
        } 
        elseif ($venue_category === 'Resort Villa') {
            $stmt = $conn->prepare("SELECT day_rate, base_capacity, extra_pax_rate FROM villas WHERE venue_id = ?");
            $stmt->bind_param("i", $venue_id);
            $stmt->execute();
            $villa = $stmt->get_result()->fetch_assoc();
            $base_amount = floatval($villa['day_rate']);
            $stay_type = $_POST['stay_type'] ?? 'Day Time Stay';
            $stay_upgrade = ($stay_type === 'Overnight') ? (3000 * $nights) : 0;
            $true_total = ($base_amount * $nights) + $stay_upgrade;
            if ($guests > $villa['base_capacity']) {
                $true_total += (($guests - $villa['base_capacity']) * floatval($villa['extra_pax_rate']) * $nights);
            }
        } 
        elseif ($venue_category === 'Event Hall') {
            $stmt = $conn->prepare("SELECT base_rate FROM event_halls WHERE venue_id = ?");
            $stmt->bind_param("i", $venue_id);
            $stmt->execute();
            $hall = $stmt->get_result()->fetch_assoc();
            $base_amount = floatval($hall['base_rate']);
            $true_total = $base_amount * $nights; 
        }

        // Calculate Downpayment using True Total
        $amount_paid = 0;
        if ($scheme === '100% Full') $amount_paid = $true_total;
        elseif (strpos($scheme, '50%') !== false) $amount_paid = $true_total * 0.5;
        elseif (strpos($scheme, '20%') !== false) $amount_paid = $true_total * 0.2;

        $payment_status = ($amount_paid >= $true_total) ? 'Paid' : 'Partial';
        // =========================================================================

        $raw_method = strtolower($_POST['payment_method']);
        $pay_method = 'Cash';
        if ($raw_method === 'gcash') $pay_method = 'GCash';
        if ($raw_method === 'maya') $pay_method = 'Maya';
        if ($raw_method === 'bank') $pay_method = 'Bank Transfer';

        // =========================================================================
        // FIX: FIND OR CREATE CUSTOMER (Prevents Duplicate Email Error)
        // =========================================================================
        $guest_email = trim($_POST['guest_email']);
        $guest_phone = trim($_POST['guest_phone']);
        $guest_name = trim($_POST['guest_name']);
        
        $name_parts = explode(' ', $guest_name, 2);
        $first_name = $name_parts[0];
        $last_name = $name_parts[1] ?? 'Walk-in';

        $stmt_check_cust = $conn->prepare("SELECT id FROM customers WHERE email = ?");
        $stmt_check_cust->bind_param("s", $guest_email);
        $stmt_check_cust->execute();
        $res_cust = $stmt_check_cust->get_result();

        if ($res_cust->num_rows > 0) {
            // Use existing customer ID and update their phone number
            $customer_id = $res_cust->fetch_assoc()['id'];
            $stmt_update_cust = $conn->prepare("UPDATE customers SET phone = ? WHERE id = ?");
            $stmt_update_cust->bind_param("si", $guest_phone, $customer_id);
            $stmt_update_cust->execute();
        } else {
            // New Customer
            $stmt_cust = $conn->prepare("INSERT INTO customers (first_name, last_name, email, phone) VALUES (?, ?, ?, ?)");
            $stmt_cust->bind_param("ssss", $first_name, $last_name, $guest_email, $guest_phone);
            $stmt_cust->execute();
            $customer_id = $conn->insert_id;
        }
        // =========================================================================

        $stmt_book = $conn->prepare("
            INSERT INTO bookings (reference_no, customer_id, venue_id, start_date, end_date, guests_count, base_amount, total_amount, amount_paid, payment_scheme, booking_status, payment_status, source) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Confirmed', ?, 'Walk-in')
        ");
        $stmt_book->bind_param("siissidddss", 
            $ref_no, $customer_id, $venue_id, $sDate, $eDate, 
            $guests, $base_amount, $true_total, $amount_paid, $scheme, $payment_status
        );
        $stmt_book->execute();
        $booking_id = $conn->insert_id;

        // SAVE EVENT DETAILS AND NOTES
        $custom_notes = isset($_POST['custom_notes']) ? trim($_POST['custom_notes']) : null;
        $event_type = isset($_POST['event_type']) ? trim($_POST['event_type']) : null;
        $event_style = isset($_POST['event_style']) ? trim($_POST['event_style']) : null;

        if (!empty($custom_notes) || !empty($event_type) || !empty($event_style)) {
            $stmt_notes = $conn->prepare("INSERT INTO booking_event_details (booking_id, event_style, event_type, custom_notes) VALUES (?, ?, ?, ?)");
            $stmt_notes->bind_param("isss", $booking_id, $event_style, $event_type, $custom_notes);
            $stmt_notes->execute();
        }

        if ($venue_category === 'Resort Villa' && isset($_POST['stay_type'])) {
            $stay_type = $_POST['stay_type'];
            $stmt_villa = $conn->prepare("INSERT INTO booking_villa_details (booking_id, stay_type) VALUES (?, ?)");
            $stmt_villa->bind_param("is", $booking_id, $stay_type);
            $stmt_villa->execute();
        }

        $transaction_id = !empty($_POST['transaction_id']) ? $_POST['transaction_id'] : null;
        $stmt_pay = $conn->prepare("INSERT INTO payments (booking_id, transaction_id, payment_method, amount, status) VALUES (?, ?, ?, ?, 'Success')");
        $stmt_pay->bind_param("issd", $booking_id, $transaction_id, $pay_method, $amount_paid);
        $stmt_pay->execute();

        if (isset($_SESSION['user_id'])) {
            $log_user = $_SESSION['user_id'];
            $log_module = 'Walk-in Bookings';
            $log_action = "Created Walk-in Booking #$booking_id for " . $_POST['guest_name'];
            $log_ip = $_SERVER['REMOTE_ADDR'];

            $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, ?, ?, ?)");
            $audit_stmt->bind_param("isss", $log_user, $log_module, $log_action, $log_ip);
            $audit_stmt->execute();
        }

        // EMAIL MAILER: Wrapped in a try-catch so if the email fails, the booking still saves!
        try {
            require_once '../../includes/mailer.php';
            send_booking_receipt($_POST['guest_email'], $_POST['guest_name'], $ref_no, $_POST['room_name'], $amount_paid, 'Confirmed (Walk-in)');
        } catch (Exception $mail_e) {
            // Log silently, don't crash
        }

        $conn->commit();
        echo "Success|" . $ref_no;

    } catch (Exception $e) {
        $conn->rollback();
        echo "Error|" . $e->getMessage();
    }
}
?>