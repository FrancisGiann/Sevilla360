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
        
        // 1. Get the Venue ID for this booking
        $stmt_venue = $conn->prepare("SELECT venue_id FROM bookings WHERE id = ?");
        $stmt_venue->bind_param("i", $booking_id);
        $stmt_venue->execute();
        $venue_id = $stmt_venue->get_result()->fetch_assoc()['venue_id'];

        // 2. THE COLLISION CHECK: Are these new dates already taken by someone else?
        $check_overlap = $conn->prepare("
            SELECT id FROM bookings 
            WHERE venue_id = ? 
            AND booking_status IN ('Pending', 'Confirmed')
            AND id != ? 
            AND (start_date < ? AND end_date > ?)
        ");
        // We pass: venue_id, booking_id (to ignore itself), new_end, new_start
        $check_overlap->bind_param("iiss", $venue_id, $booking_id, $new_end, $new_start);
        $check_overlap->execute();
        
        if ($check_overlap->get_result()->num_rows > 0) {
            throw new Exception("Collision Error: Those dates were just taken by another customer. Cannot reschedule.");
        }

        // 3. If safe, update the booking dates!
        $stmt = $conn->prepare("UPDATE bookings SET start_date = ?, end_date = ? WHERE id = ?");
        $stmt->bind_param("ssi", $new_start, $new_end, $booking_id);
        $stmt->execute();

        // 4. If this came from a customer request, mark the request as Approved
        $stmt_req = $conn->prepare("UPDATE reschedule_requests SET status = 'Approved' WHERE booking_id = ? AND status = 'Pending'");
        $stmt_req->bind_param("i", $booking_id);
        $stmt_req->execute();
        
        $message = "Booking #$booking_id successfully rescheduled to $new_start!";
        
    }
    elseif ($action === 'reject_reschedule') {
        $admin_reply = isset($data['admin_reply']) ? trim($data['admin_reply']) : "No reason provided.";

        // Mark the request as Rejected and save the reason!
        $stmt_req = $conn->prepare("UPDATE reschedule_requests SET status = 'Rejected', admin_reply = ? WHERE booking_id = ? AND status = 'Pending'");
        $stmt_req->bind_param("si", $admin_reply, $booking_id);
        $stmt_req->execute();
        
        $message = "Reschedule request rejected successfully.";
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
    elseif ($action === 'admin_force_cancel') {
        if (!isset($data['reason'])) throw new Exception("Reason is required.");
        
        $reason = trim($data['reason']);
        $refund_amount = floatval($data['refund_amount']);
        $fee = 0.00; // Resort shoulders the fee

        // 1. Insert into cancellations as 'Processed' (since Admin is forcing it)
        $stmt_cx = $conn->prepare("INSERT INTO cancellations (booking_id, reason, refund_amount, fee_deducted, status, admin_reply) VALUES (?, ?, ?, ?, 'Processed', 'Admin Initiated (Force Majeure)')");
        $stmt_cx->bind_param("isdd", $booking_id, $reason, $refund_amount, $fee);
        $stmt_cx->execute();

        // 2. Mark Booking as Cancelled & Refunded
        $stmt = $conn->prepare("UPDATE bookings SET booking_status = 'Cancelled', payment_status = 'Refunded' WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        
        $message = "Booking #$booking_id forcefully cancelled. 100% refund recorded.";
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