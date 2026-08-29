<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once '../../config/db_connect.php';
require_once '../../includes/request_context.php';
require_once '../../includes/refund_helper.php';
require_once '../../includes/realtime.php';
require_once '../../includes/booking_lifecycle.php';
$booking_completion_sql = booking_completion_sql('b');

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
$reason = trim((string)$data['reason']);
if ($booking_id < 1 || $reason === '' || strlen($reason) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)) {
    echo json_encode(['success' => false, 'message' => 'A valid cancellation reason is required.']);
    exit;
}

// 1. Verify this booking actually belongs to this user! (Security Check)
$stmt_check = $conn->prepare("
    SELECT b.id, b.amount_paid, b.booking_status FROM bookings b
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
if ($booking['booking_status'] === 'Completed') {
    echo json_encode(['success' => false, 'message' => 'This booking is complete and can no longer be cancelled or refunded.']);
    exit;
}
if (!in_array($booking['booking_status'], ['Pending', 'Confirmed'], true)) {
    echo json_encode(['success' => false, 'message' => 'This booking is no longer eligible for cancellation.']);
    exit;
}

// 2. Snapshot the current fee and refund values on each request.
$refund = calculate_refund_breakdown($conn, $amount_paid);
$fee = $refund['fee'];
$refund_amount = $refund['refund'];
$fee_percent = $refund['fee_percent'];

try {
    $conn->begin_transaction();

    $stmt_booking_lock = $conn->prepare("SELECT b.amount_paid, b.booking_status, b.start_date, b.end_date, b.reference_no, CASE WHEN $booking_completion_sql THEN 1 ELSE 0 END AS is_completed, v.category FROM bookings b INNER JOIN venues v ON v.id = b.venue_id WHERE b.id = ? FOR UPDATE");
    $stmt_booking_lock->bind_param('i', $booking_id);
    $stmt_booking_lock->execute();
    $locked_booking = $stmt_booking_lock->get_result()->fetch_assoc();
    if (!$locked_booking) throw new Exception('This booking is no longer available.');
    if (booking_is_completed($locked_booking)) throw new Exception('This booking is complete and can no longer be cancelled or refunded.');
    if (!in_array($locked_booking['booking_status'], ['Pending', 'Confirmed'], true)) throw new Exception('This booking is no longer eligible for cancellation.');
    $amount_paid = (float)$locked_booking['amount_paid'];
    $refund = calculate_refund_breakdown($conn, $amount_paid);
    $fee = $refund['fee'];
    $refund_amount = $refund['refund'];
    $fee_percent = $refund['fee_percent'];

    $stmt_existing = $conn->prepare("SELECT id, status FROM cancellations WHERE booking_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $stmt_existing->bind_param('i', $booking_id);
    $stmt_existing->execute();
    $existing = $stmt_existing->get_result()->fetch_assoc();
    if ($existing && $existing['status'] === 'Pending') throw new Exception('A cancellation request is already pending for this booking.');
    if ($existing && $existing['status'] === 'Processed') throw new Exception('This booking has already been refunded.');

    if ($amount_paid > 0) {
        // SCENARIO A: Reopen the single current row after rejection; every
        // prior state remains in cancellation_history.
        if ($existing) {
            if ($existing['status'] !== 'Rejected') throw new Exception('This booking is not eligible for another refund request.');
            $stmt_cx = $conn->prepare("UPDATE cancellations SET reason = ?, refund_amount = ?, fee_deducted = ?, fee_percent = ?, refund_transaction_id = NULL, status = 'Pending', admin_reply = NULL WHERE id = ? AND status = 'Rejected'");
            $stmt_cx->bind_param("sdddi", $reason, $refund_amount, $fee, $fee_percent, $existing['id']);
            if (!$stmt_cx->execute() || $stmt_cx->affected_rows !== 1) throw new Exception('The prior refund request changed before it could be reopened.');
            $cancellation_id = (int)$existing['id'];
            $history_action = 'reopened';
        } else {
            $stmt_cx = $conn->prepare("INSERT INTO cancellations (booking_id, reason, refund_amount, fee_deducted, fee_percent, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
            $stmt_cx->bind_param("isddd", $booking_id, $reason, $refund_amount, $fee, $fee_percent);
            if (!$stmt_cx->execute()) throw new Exception('Unable to submit the refund request.');
            $cancellation_id = (int)$conn->insert_id;
            $history_action = 'requested';
        }
        record_cancellation_history($conn, $booking_id, $cancellation_id, $history_action, $reason, $refund_amount, $fee, $fee_percent, null, (int)$_SESSION['user_id']);

        $message = "Cancellation request submitted successfully. Our team will process your refund shortly.";

    } else {
        // SCENARIO B: They haven't paid anything yet! Instantly cancel it.
        $stmt_cancel = $conn->prepare("UPDATE bookings SET booking_status = 'Cancelled', updated_at = NOW() WHERE id = ?");
        $stmt_cancel->bind_param("i", $booking_id);
        $stmt_cancel->execute();
        record_cancellation_history($conn, $booking_id, $existing ? (int)$existing['id'] : null, 'cancelled', $reason, 0.0, 0.0, $fee_percent, null, (int)$_SESSION['user_id']);

        $message = "Booking cancelled successfully. No refund necessary.";
    }

    // Clean up any pending reschedule request for this booking
    $stmt_rr = $conn->prepare("UPDATE reschedule_requests SET status = 'Rejected', admin_reply = 'Cancelled by Customer' WHERE booking_id = ? AND status = 'Pending'");
    $stmt_rr->bind_param("i", $booking_id);
    $stmt_rr->execute();

    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Customer Cancellation', ?, ?)");
    $audit_action = "Requested cancellation for booking #{$booking_id}";
    $audit_ip = request_client_ip();
    if ($audit) {
        $audit->bind_param('iss', $_SESSION['user_id'], $audit_action, $audit_ip);
        $audit->execute();
    }

    realtime_enqueue_event($conn, 'admin', 'cancellation.requested', [
        'booking_id' => $booking_id,
        'reference_no' => (string)($locked_booking['reference_no'] ?? ''),
        'venue_category' => (string)($locked_booking['category'] ?? ''),
        'status' => $amount_paid > 0 ? 'Pending Refund' : 'Cancelled',
    ]);
    
    $conn->commit();

    // 3. Dispatch customer messages only after the transaction has committed.
    // These failures must never turn a successful cancellation into a client
    // error or expose provider/internal details.
    try {
        require_once '../../includes/mailer.php';
        require_once '../../includes/notifications.php';
        $stmt_info = $conn->prepare("SELECT c.first_name,c.last_name,c.email,c.user_id,b.reference_no,v.name AS venue_name FROM bookings b JOIN customers c ON b.customer_id=c.id JOIN venues v ON b.venue_id=v.id WHERE b.id=?");
        if (!$stmt_info) throw new RuntimeException('customer message lookup unavailable');
        $stmt_info->bind_param('i', $booking_id);
        if (!$stmt_info->execute()) throw new RuntimeException('customer message lookup failed');
        $info = $stmt_info->get_result()->fetch_assoc();
        if ($info) {
            $c_name = $info['first_name'] . ' ' . $info['last_name'];
            $c_email = $info['email'];
            $c_user_id = $info['user_id'];
            $ref_no = $info['reference_no'];
            $v_name = $info['venue_name'];

            try {
                if ($amount_paid > 0) {
                    create_user_notification($conn, $c_user_id, "Pending Refund", "Your refund request for $v_name (Ref: $ref_no) has been submitted for processing.");
                } else {
                    create_user_notification($conn, $c_user_id, "Booking Cancelled", "Your booking for $v_name (Ref: $ref_no) has been cancelled as requested.");
                }
            } catch (Throwable $notificationError) {
                error_log('User cancellation notification failed: ' . get_class($notificationError) . ' booking_id=' . (int)$booking_id);
            }

            try {
                send_booking_cancellation_email($c_email, $c_name, $booking_id, ($amount_paid > 0 ? 'cancellation_requested' : 'customer_cancelled'), $refund_amount, $reason);
            } catch (Throwable $mail_err) {
                error_log('User cancellation email delivery failed: ' . get_class($mail_err) . ' booking_id=' . (int)$booking_id);
            }
        }
    } catch (Throwable $postCommitError) {
        error_log('Customer cancellation post-commit dispatch failed: ' . get_class($postCommitError) . ' booking_id=' . (int)$booking_id);
    }

    echo json_encode(['success' => true, 'message' => $message]);

} catch (Exception $e) {
    $conn->rollback();
    $errorMessage = strtolower((string)$e->getMessage());
    if (str_contains($errorMessage, 'complete')) {
        $safeMessage = 'This booking is complete and can no longer be cancelled or refunded.';
    } elseif ($conn->errno == 1062) {
        $safeMessage = 'A cancellation request is already pending for this booking.';
    } else {
        $safeMessage = 'Unable to submit the cancellation request.';
    }
    echo json_encode(['success' => false, 'message' => $safeMessage]);
}
$conn->close();
?>
