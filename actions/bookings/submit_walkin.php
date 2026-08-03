<?php
session_start();
require '../../config/db_connect.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    try {
        $conn->begin_transaction();

        $ref_no = "SV-" . mt_rand(10000, 99999);
        $sDate = $_POST['start_date'];
        $eDate = $_POST['end_date'];
        
        $total_amount = floatval($_POST['total_amount']);
        $scheme = $_POST['payment_scheme'];

        $amount_paid = 0;
        if ($scheme === '100% Full') $amount_paid = $total_amount;
        elseif ($scheme === '50% Downpayment') $amount_paid = $total_amount * 0.5;
        elseif ($scheme === '20% Reservation') $amount_paid = $total_amount * 0.2;

        $payment_status = ($amount_paid >= $total_amount) ? 'Paid' : 'Partial';

        $raw_method = strtolower($_POST['payment_method']);
        $pay_method = 'Cash';
        if ($raw_method === 'gcash') $pay_method = 'GCash';
        if ($raw_method === 'maya') $pay_method = 'Maya';
        if ($raw_method === 'bank') $pay_method = 'Bank Transfer';

        $stmt_cust = $conn->prepare("INSERT INTO customers (first_name, last_name, email, phone) VALUES (?, 'Walk-in', ?, ?)");
        $stmt_cust->bind_param("sss", $_POST['guest_name'], $_POST['guest_email'], $_POST['guest_phone']);
        $stmt_cust->execute();
        $customer_id = $conn->insert_id;

        if ($_POST['room_type'] === 'Event Hall' || $_POST['room_type'] === 'Resort Villa') {
            $stmt_room = $conn->prepare("
                SELECT id FROM venues 
                WHERE category = ? AND name = ? AND status = 'Available'
                AND id NOT IN (SELECT venue_id FROM bookings WHERE booking_status IN ('Pending', 'Confirmed') AND (start_date < ? AND end_date > ?))
                LIMIT 1
            ");
        } else {
            $stmt_room = $conn->prepare("
                SELECT v.id FROM venues v JOIN hotel_rooms h ON v.id = h.venue_id 
                WHERE h.room_type = ? AND v.name = ? AND v.status = 'Available'
                AND v.id NOT IN (SELECT venue_id FROM bookings WHERE booking_status IN ('Pending', 'Confirmed') AND (start_date < ? AND end_date > ?))
                LIMIT 1
            ");
        }
        $stmt_room->bind_param("ssss", $_POST['room_type'], $_POST['room_name'], $eDate, $sDate);
        $stmt_room->execute();
        $room_result = $stmt_room->get_result();
        
        if ($room_result->num_rows === 0) {
            throw new Exception("All units for this specific room were just booked by someone online! Please select different dates.");
        }
        $venue_id = $room_result->fetch_assoc()['id'];

        $stmt_book = $conn->prepare("
            INSERT INTO bookings (reference_no, customer_id, venue_id, start_date, end_date, guests_count, base_amount, total_amount, amount_paid, payment_scheme, booking_status, payment_status, source) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Confirmed', ?, 'Walk-in')
        ");
        $stmt_book->bind_param("siissidddss", 
            $ref_no, $customer_id, $venue_id, $sDate, $eDate, 
            $_POST['guests'], $_POST['base_amount'], $total_amount, $amount_paid, $scheme, $payment_status
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

        // FIXED: SAVE VILLA DETAILS
        if ($_POST['room_type'] === 'Resort Villa' && isset($_POST['stay_type'])) {
            $stay_type = $_POST['stay_type'];
            $stmt_villa = $conn->prepare("INSERT INTO booking_villa_details (booking_id, stay_type) VALUES (?, ?)");
            $stmt_villa->bind_param("is", $booking_id, $stay_type);
            $stmt_villa->execute();
        }

        $transaction_id = !empty($_POST['transaction_id']) ? $_POST['transaction_id'] : null;
        $stmt_pay = $conn->prepare("INSERT INTO payments (booking_id, transaction_id, payment_method, amount, status) VALUES (?, ?, ?, ?, 'Success')");
        $stmt_pay->bind_param("issd", $booking_id, $transaction_id, $pay_method, $amount_paid);
        $stmt_pay->execute();

        // audit log
        if (isset($_SESSION['user_id'])) {
            $log_user = $_SESSION['user_id'];
            $log_module = 'Walk-in Bookings';
            $log_action = "Created Walk-in Booking #$booking_id for $guestName";
            $log_ip = $_SERVER['REMOTE_ADDR'];

            $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, ?, ?, ?)");
            $audit_stmt->bind_param("isss", $log_user, $log_module, $log_action, $log_ip);
            $audit_stmt->execute();
        }
        $conn->commit();
        echo "Success|" . $ref_no;

    } catch (Exception $e) {
        $conn->rollback();
        echo "Error|" . $e->getMessage();
    }
}
?>