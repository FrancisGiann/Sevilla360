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

        $stmt_cust = $conn->prepare("SELECT id FROM customers WHERE user_id = ?");
        $stmt_cust->bind_param("i", $_SESSION['user_id']);
        $stmt_cust->execute();
        $cust_result = $stmt_cust->get_result();
        if ($cust_result->num_rows === 0) throw new Exception("Customer profile not found.");
        $customer_id = $cust_result->fetch_assoc()['id'];

        if (!isset($_SESSION['locked_venue_id'])) {
            throw new Exception("Session expired or dates were not locked properly. Please select dates again.");
        }
        $venue_id = $_SESSION['locked_venue_id'];

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

        // SAVE EVENT DETAILS AND NOTES
        $custom_notes = isset($_POST['custom_notes']) ? trim($_POST['custom_notes']) : null;
        $event_type = isset($_POST['event_type']) ? trim($_POST['event_type']) : null;
        $event_style = isset($_POST['event_style']) ? trim($_POST['event_style']) : null;

        if (!empty($custom_notes) || !empty($event_type) || !empty($event_style)) {
            $stmt_notes = $conn->prepare("INSERT INTO booking_event_details (booking_id, event_style, event_type, custom_notes) VALUES (?, ?, ?, ?)");
            $stmt_notes->bind_param("isss", $booking_id, $event_style, $event_type, $custom_notes);
            $stmt_notes->execute();
        }

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