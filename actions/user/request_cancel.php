<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

// Auth Guard: Must be a logged-in customer
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

if (!isset($data['booking_id']) || !isset($data['reason'])) {
    echo json_encode(['success' => false, 'message' => 'Missing data.']);
    exit;
}

$booking_id = intval($data['booking_id']);
$reason = trim($data['reason']);

// 1. Verify this booking actually belongs to this user! (Security Check)
$stmt_check = $conn->prepare("
    SELECT b.id, b.amount_paid FROM bookings b 
    JOIN customers c ON b.customer_id = c.id 
    WHERE b.id = ? AND c.user_id = ?
");
$stmt_check->bind_param("ii", $booking_id, $_SESSION['user_id']);
$stmt_check->execute();
$res = $stmt_check->get_result();

if ($res->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Booking not found or access denied.']);
    exit;
}

$booking = $res->fetch_assoc();
$amount_paid = floatval($booking['amount_paid']);

// 2. Calculate Refund Logic (If they paid, deduct the ₱461 fee. If they didn't pay, refund is 0)
$fee = 461.00;
$refund_amount = ($amount_paid > 0) ? ($amount_paid - $fee) : 0;
if ($refund_amount < 0) $refund_amount = 0;
$actual_fee = ($amount_paid > 0) ? $fee : 0;

try {
    $conn->begin_transaction();

    if ($amount_paid > 0) {
        // SCENARIO A: They paid money. We must queue a Refund Request for the Admin.
        $fee = 461.00;
        $refund_amount = $amount_paid - $fee;
        if ($refund_amount < 0) $refund_amount = 0;

        $stmt_cx = $conn->prepare("INSERT INTO cancellations (booking_id, reason, refund_amount, fee_deducted, status) VALUES (?, ?, ?, ?, 'Pending')");
        $stmt_cx->bind_param("isdd", $booking_id, $reason, $refund_amount, $fee);
        $stmt_cx->execute();

        $message = "Cancellation request submitted successfully. Our team will process your refund shortly.";

    } else {
        // SCENARIO B: They haven't paid anything yet! Instantly cancel it.
        $stmt_cancel = $conn->prepare("UPDATE bookings SET booking_status = 'Cancelled', updated_at = NOW() WHERE id = ?");
        $stmt_cancel->bind_param("i", $booking_id);
        $stmt_cancel->execute();

        $message = "Booking cancelled successfully. No refund necessary.";
    }
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => $message]);

} catch (Exception $e) {
    $conn->rollback();
    if ($conn->errno == 1062) {
        echo json_encode(['success' => false, 'message' => 'A cancellation request is already pending for this booking.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
$conn->close();
?>