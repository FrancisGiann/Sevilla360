<?php
session_start();
header('Content-Type: application/json');

// 1. Auth Guard: Ensure only admins can execute this
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superadmin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

require_once '../../config/db_connect.php'; 

// 2. Get POST JSON Data
$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

if (!isset($data['booking_id']) || !isset($data['action'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request. Missing data.']);
    exit;
}

$booking_id = intval($data['booking_id']);
$action = $data['action'];

try {
    // We will use a transaction so if anything fails, it rolls back safely
    $conn->begin_transaction();

    if ($action === 'confirm') {
        $stmt = $conn->prepare("UPDATE bookings SET booking_status = 'Confirmed' WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $message = "Booking #$booking_id has been confirmed!";
        
    } 
    elseif ($action === 'cancel') {
        $stmt = $conn->prepare("UPDATE bookings SET booking_status = 'Cancelled' WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $message = "Booking #$booking_id has been cancelled!";
        
    } 
    elseif ($action === 'add_payment') {
        $amount_to_add = floatval($data['amount']);
        $method = $data['method'];
        $trans_id = !empty($data['transaction_id']) ? $data['transaction_id'] : 'CASH-' . time();

        // Check current balance
        $stmt_check = $conn->prepare("SELECT total_amount, amount_paid FROM bookings WHERE id = ?");
        $stmt_check->bind_param("i", $booking_id);
        $stmt_check->execute();
        $res = $stmt_check->get_result();
        
        if ($res->num_rows === 0) throw new Exception('Booking not found.');
        $booking = $res->fetch_assoc();
        
        $new_amount_paid = floatval($booking['amount_paid']) + $amount_to_add;
        $new_payment_status = ($new_amount_paid >= floatval($booking['total_amount'])) ? 'Paid' : 'Partial';

        // Insert Payment Record
        $stmt_pay = $conn->prepare("INSERT INTO payments (booking_id, transaction_id, payment_method, amount, status) VALUES (?, ?, ?, ?, 'Success')");
        $stmt_pay->bind_param("issd", $booking_id, $trans_id, $method, $amount_to_add);
        $stmt_pay->execute();

        // Update Booking Status
        $stmt_update = $conn->prepare("UPDATE bookings SET payment_status = ?, amount_paid = ?, booking_status = 'Confirmed' WHERE id = ?");
        $stmt_update->bind_param("sdi", $new_payment_status, $new_amount_paid, $booking_id);
        $stmt_update->execute();
        
        $message = "Payment of ₱" . number_format($amount_to_add, 2) . " received successfully!";
        
    } 
    elseif ($action === 'reschedule') {
        if (!isset($data['new_start_date']) || !isset($data['new_end_date'])) {
            throw new Exception("Missing new dates for reschedule.");
        }
        $new_start = $data['new_start_date'];
        $new_end = $data['new_end_date'];
        
        // Update the booking dates
        $stmt = $conn->prepare("UPDATE bookings SET start_date = ?, end_date = ? WHERE id = ?");
        $stmt->bind_param("ssi", $new_start, $new_end, $booking_id);
        $stmt->execute();
        
        $message = "Booking #$booking_id rescheduled to $new_start!";
        
    } 
    elseif ($action === 'refund') {
        // Mark Booking as Cancelled and Refunded
        $stmt = $conn->prepare("UPDATE bookings SET booking_status = 'Cancelled', payment_status = 'Refunded' WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        
        // Mark the cancellation request as Processed
        $stmt_cx = $conn->prepare("UPDATE cancellations SET status = 'Processed' WHERE booking_id = ?");
        $stmt_cx->bind_param("i", $booking_id);
        $stmt_cx->execute();
        
        $message = "Refund processed and booking cancelled!";
        
    } 
    else {
        throw new Exception('Invalid action provided.');
    }

    // If everything worked, commit it to the database!
    $conn->commit();
    echo json_encode(['success' => true, 'message' => $message]);

} catch (Exception $e) {
    // If anything fails, rollback so we don't break the database
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>