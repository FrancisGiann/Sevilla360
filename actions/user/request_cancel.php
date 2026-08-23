<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

// Auth Guard: Must be a logged-in customer
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// ==========================================
// CSRF PROTECTION GUARD (JSON)
// ==========================================
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed. Unauthorized request.']);
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

    // Clean up any pending reschedule request for this booking
    $stmt_rr = $conn->prepare("UPDATE reschedule_requests SET status = 'Rejected', admin_reply = 'Cancelled by Customer' WHERE booking_id = ? AND status = 'Pending'");
    $stmt_rr->bind_param("i", $booking_id);
    $stmt_rr->execute();

    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Customer Cancellation', ?, ?)");
    $audit_action = "Requested cancellation for booking #{$booking_id}";
    $audit_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if ($audit) {
        $audit->bind_param('iss', $_SESSION['user_id'], $audit_action, $audit_ip);
        $audit->execute();
    }
    
    $conn->commit();

    // 3. SEND EMAIL & IN-APP NOTIFICATION TO USER
    require_once '../../includes/mailer.php';
    require_once '../../includes/notifications.php';

    $stmt_info = $conn->prepare("
        SELECT c.first_name, c.last_name, c.email, c.user_id, b.reference_no, v.name AS venue_name
        FROM bookings b
        JOIN customers c ON b.customer_id = c.id
        JOIN venues v ON b.venue_id = v.id
        WHERE b.id = ?
    ");
    $stmt_info->bind_param("i", $booking_id);
    $stmt_info->execute();
    $info = $stmt_info->get_result()->fetch_assoc();

    if ($info) {
        $c_name = $info['first_name'] . ' ' . $info['last_name'];
        $c_email = $info['email'];
        $c_user_id = $info['user_id'];
        $ref_no = $info['reference_no'];
        $v_name = $info['venue_name'];

        // In-App Notification
        if ($amount_paid > 0) {
            create_user_notification($conn, $c_user_id, "Cancellation Requested", "Your cancellation request for $v_name (Ref: $ref_no) has been submitted for refund processing.");
        } else {
            create_user_notification($conn, $c_user_id, "Booking Cancelled", "Your booking for $v_name (Ref: $ref_no) has been cancelled as requested.");
        }

        // Email Notification (Reusing existing mailer implementation)
        try {
            send_booking_cancellation_email($c_email, $c_name, $booking_id, ($amount_paid > 0 ? 'cancellation_requested' : 'cancelled'), $refund_amount, $reason);
        } catch (Exception $mail_err) {
            error_log("Failed to send user cancellation email: " . $mail_err->getMessage());
        }
    }

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
