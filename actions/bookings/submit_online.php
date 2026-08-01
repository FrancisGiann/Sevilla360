<?php
session_start();
require '../../config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    echo "Error|You must be logged in to make a booking.";
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    try {
        $conn->begin_transaction();

        $ref_no = "SV-" . mt_rand(10000, 99999);
        $sDate = $_POST['start_date'];
        $eDate = $_POST['end_date'];
        $total_amount = floatval($_POST['total_amount']);
        $scheme = $_POST['payment_scheme'];

        // 1. Get Customer ID
        $stmt_cust = $conn->prepare("SELECT id FROM customers WHERE user_id = ?");
        $stmt_cust->bind_param("i", $_SESSION['user_id']);
        $stmt_cust->execute();
        $cust_result = $stmt_cust->get_result();
        if ($cust_result->num_rows === 0) throw new Exception("Customer profile not found.");
        $customer_id = $cust_result->fetch_assoc()['id'];

        // ========================================================
        // THE FIX: SAVE THE PHONE NUMBER TO THE DATABASE!
        // ========================================================
        $contact_phone = isset($_POST['contact_phone']) ? trim($_POST['contact_phone']) : null;
        if (!empty($contact_phone)) {
            $stmt_phone = $conn->prepare("UPDATE customers SET phone = ? WHERE id = ?");
            $stmt_phone->bind_param("si", $contact_phone, $customer_id);
            $stmt_phone->execute();
        }
        // ========================================================

        if (!isset($_SESSION['locked_venue_id'])) {
            throw new Exception("Session expired or dates were not locked properly. Please select dates again.");
        }
        $venue_id = $_SESSION['locked_venue_id'];

        // 2. Save Booking
        $stmt_book = $conn->prepare("
            INSERT INTO bookings (reference_no, customer_id, venue_id, start_date, end_date, guests_count, base_amount, total_amount, payment_scheme, booking_status, payment_status, source) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'Unpaid', 'Online')
        ");
        $stmt_book->bind_param("siissidds", 
            $ref_no, $customer_id, $venue_id, $sDate, $eDate, 
            $_POST['guests'], $_POST['base_amount'], $total_amount, $scheme
        );
        $stmt_book->execute();
        $booking_id = $conn->insert_id;

        // 3. Save Event Details and Notes
        $custom_notes = isset($_POST['custom_notes']) ? trim($_POST['custom_notes']) : null;
        $event_type = isset($_POST['event_type']) ? trim($_POST['event_type']) : null;
        $event_style = isset($_POST['event_style']) ? trim($_POST['event_style']) : null;

        if (!empty($custom_notes) || !empty($event_type) || !empty($event_style)) {
            $stmt_notes = $conn->prepare("INSERT INTO booking_event_details (booking_id, event_style, event_type, custom_notes) VALUES (?, ?, ?, ?)");
            $stmt_notes->bind_param("isss", $booking_id, $event_style, $event_type, $custom_notes);
            $stmt_notes->execute();
        }

        // 4. Save Villa Details
        if ($_POST['room_type'] === 'Resort Villa' && isset($_POST['stay_type'])) {
            $stay_type = $_POST['stay_type'];
            $stmt_villa = $conn->prepare("INSERT INTO booking_villa_details (booking_id, stay_type) VALUES (?, ?)");
            $stmt_villa->bind_param("is", $booking_id, $stay_type);
            $stmt_villa->execute();
        }

        // 5. Unlock Dates
        $session_id = session_id();
        $stmt_unlock = $conn->prepare("DELETE FROM booking_locks WHERE venue_id = ? AND session_id = ?");
        $stmt_unlock->bind_param("is", $venue_id, $session_id);
        $stmt_unlock->execute();
        unset($_SESSION['locked_venue_id']);

        $conn->commit();
        echo "Success|" . $ref_no;

    } catch (Exception $e) {
        $conn->rollback();
        echo "Error|" . $e->getMessage();
    }
}
?>