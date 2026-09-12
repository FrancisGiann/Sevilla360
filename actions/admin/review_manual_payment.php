<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json; charset=utf-8');
if (!in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Staff access is required.']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Use POST to review payment proof.']);
    exit;
}
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
    exit;
}
$request = json_decode((string)file_get_contents('php://input'), true);
$submissionId = filter_var($request['submission_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$decision = (string)($request['decision'] ?? '');
if (!$submissionId || !in_array($decision, ['approve', 'reject'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Choose an approval decision for a valid submission.']);
    exit;
}
$reason = trim((string)($request['rejection_reason'] ?? ''));
if ($decision === 'reject' && ($reason === '' || strlen($reason) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason))) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter a rejection reason between 1 and 500 characters.']);
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/booking_lifecycle.php';
require_once __DIR__ . '/../../includes/manual_payment.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/realtime.php';
require_once __DIR__ . '/../../includes/request_context.php';
require_once __DIR__ . '/../../includes/mailer.php';

$mailAfterCommit = null;
try {
    $lookup = $conn->prepare('SELECT booking_id FROM manual_payment_submissions WHERE id = ? LIMIT 1');
    if (!$lookup) throw new RuntimeException('Unable to load payment submission.');
    $lookup->bind_param('i', $submissionId);
    if (!$lookup->execute()) throw new RuntimeException('Unable to load payment submission.');
    $bookingId = (int)($lookup->get_result()->fetch_assoc()['booking_id'] ?? 0);
    if ($bookingId < 1) throw new RuntimeException('Payment submission not found.');
    if (!$conn->begin_transaction()) throw new RuntimeException('Unable to start payment review.');

    $completionSql = booking_completion_sql('b');
    $lockBooking = $conn->prepare("SELECT b.id, b.reference_no, b.total_amount, b.amount_paid, b.payment_scheme, b.booking_status, b.payment_status, b.payment_due_at, b.source, c.user_id, c.email, c.first_name, c.last_name, v.name AS venue_name, v.category AS venue_category, CASE WHEN {$completionSql} THEN 1 ELSE 0 END AS is_completed FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN venues v ON v.id = b.venue_id WHERE b.id = ? FOR UPDATE");
    if (!$lockBooking) throw new RuntimeException('Unable to lock booking for payment review.');
    $lockBooking->bind_param('i', $bookingId);
    if (!$lockBooking->execute()) throw new RuntimeException('Unable to lock booking for payment review.');
    $booking = $lockBooking->get_result()->fetch_assoc();
    if (!$booking) throw new RuntimeException('Booking not found.');

    $lockSubmission = $conn->prepare('SELECT * FROM manual_payment_submissions WHERE id = ? AND booking_id = ? FOR UPDATE');
    if (!$lockSubmission) throw new RuntimeException('Unable to lock payment proof.');
    $lockSubmission->bind_param('ii', $submissionId, $bookingId);
    if (!$lockSubmission->execute()) throw new RuntimeException('Unable to lock payment proof.');
    $submission = $lockSubmission->get_result()->fetch_assoc();
    if (!$submission) throw new RuntimeException('Payment submission not found.');

    $targetState = $decision === 'approve' ? 'approved' : 'rejected';
    if ($submission['status'] === $targetState) {
        $conn->commit();
        echo json_encode(['success' => true, 'duplicate' => true, 'message' => 'This payment proof has already been reviewed.']);
        exit;
    }
    if ($submission['status'] !== 'pending') throw new RuntimeException('This payment proof has already received a different decision.');

    $reviewerId = (int)$_SESSION['user_id'];
    $ip = request_client_ip();
    $referenceNo = (string)$booking['reference_no'];
    $userId = (int)$booking['user_id'];
    $customerName = trim((string)$booking['first_name'] . ' ' . (string)$booking['last_name']);

    if ($decision === 'approve') {
        if (!in_array($booking['source'], ['Online'], true)) throw new RuntimeException('Only online bookings can use customer-submitted proof.');
        if (!in_array($booking['booking_status'], ['Pending', 'Confirmed'], true) || $booking['payment_status'] === 'Refunded' || (int)$booking['is_completed'] === 1) throw new RuntimeException('This booking is no longer eligible for payment.');
        if ($booking['venue_category'] === 'Event Hall' && $booking['booking_status'] === 'Pending') throw new RuntimeException('Finalize the Event Hall quotation before approving payment.');
        $currentExpectedCents = manual_payment_expected_amount_cents($booking);
        $submittedExpectedCents = (int)round((float)$submission['expected_amount'] * 100);
        if ($currentExpectedCents !== $submittedExpectedCents) throw new RuntimeException('The booking balance changed after this proof was submitted. Use Collect Payment to resolve it.');
        $fingerprintCheck = $conn->prepare('SELECT id FROM manual_payment_submissions WHERE reference_fingerprint = ? AND id <> ? LIMIT 1 FOR UPDATE');
        if (!$fingerprintCheck) throw new RuntimeException('Unable to verify transaction-reference uniqueness.');
        $fingerprintCheck->bind_param('si', $submission['reference_fingerprint'], $submissionId);
        if (!$fingerprintCheck->execute()) throw new RuntimeException('Unable to verify transaction-reference uniqueness.');
        if ($fingerprintCheck->get_result()->num_rows > 0) throw new RuntimeException('This transaction reference has already been submitted.');
        $methodCode = match ($submission['payment_method']) { 'GCash' => 'GCASH', 'Maya' => 'MAYA', 'Bank Transfer' => 'BANKTRANSFER', default => throw new RuntimeException('Unsupported payment method.') };
        $transactionId = 'MANUAL-' . $methodCode . ':' . strtoupper(trim((string)$submission['transaction_reference']));
        $credit = manual_payment_credit_locked($conn, $booking, $currentExpectedCents / 100, (string)$submission['payment_method'], $transactionId, (int)$submissionId);
        $update = $conn->prepare("UPDATE manual_payment_submissions SET status = 'approved', reviewer_user_id = ?, reviewed_at = NOW(), rejection_reason = NULL, payment_id = ? WHERE id = ? AND status = 'pending'");
        if (!$update) throw new RuntimeException('Unable to approve payment proof.');
        $update->bind_param('iii', $reviewerId, $credit['payment_id'], $submissionId);
        if (!$update->execute() || $update->affected_rows !== 1) throw new RuntimeException('Payment proof was already reviewed.');
        $message = 'Your ' . $submission['payment_method'] . ' payment of ₱' . number_format($credit['amount'], 2) . ' for booking ' . $referenceNo . ' has been verified.';
        create_user_notification($conn, $userId, 'Payment Verified', $message);
        realtime_enqueue_event($conn, 'admin', 'payment.received', ['booking_id' => $bookingId, 'reference_no' => $referenceNo, 'payment_status' => $credit['payment_status'], 'amount_paid' => $credit['amount_paid']]);
        realtime_enqueue_event($conn, 'customer:' . $userId, 'payment.received', ['booking_id' => $bookingId, 'reference_no' => $referenceNo, 'payment_status' => $credit['payment_status'], 'amount_paid' => $credit['amount_paid']]);
        $mailAfterCommit = ['email' => (string)$booking['email'], 'name' => $customerName, 'reference' => $referenceNo, 'venue' => (string)$booking['venue_name'], 'paid' => $credit['amount_paid'], 'status' => $credit['payment_status'] === 'Paid' ? 'Fully Paid' : 'Partially Paid (Manual Payment)'];
        $auditAction = 'Approved ' . $submission['payment_method'] . ' proof for Booking ' . $referenceNo . ' (submission ' . $submissionId . ')';
    } else {
        $update = $conn->prepare("UPDATE manual_payment_submissions SET status = 'rejected', reviewer_user_id = ?, reviewed_at = NOW(), rejection_reason = ? WHERE id = ? AND status = 'pending'");
        if (!$update) throw new RuntimeException('Unable to reject payment proof.');
        $update->bind_param('isi', $reviewerId, $reason, $submissionId);
        if (!$update->execute() || $update->affected_rows !== 1) throw new RuntimeException('Payment proof was already reviewed.');
        $unpaid = (float)$booking['amount_paid'] === 0.0 && $booking['payment_status'] === 'Unpaid';
        if ($unpaid) {
            $hours = manual_payment_deadline_hours($conn);
            $dueSql = "DATE_ADD(NOW(), INTERVAL {$hours} HOUR)";
            $notice = 'Your payment proof for booking ' . $referenceNo . ' was not accepted: ' . $reason . ' A fresh ' . $hours . '-hour payment window has started.';
        } else {
            $dueSql = 'NULL';
            $notice = 'Your payment proof for booking ' . $referenceNo . ' was not accepted: ' . $reason . ' Submit a corrected proof for the remaining balance.';
        }
        $setDue = $conn->prepare("UPDATE bookings SET payment_due_at = {$dueSql} WHERE id = ?");
        if (!$setDue) throw new RuntimeException('Unable to reopen the payment window.');
        $setDue->bind_param('i', $bookingId);
        if (!$setDue->execute()) throw new RuntimeException('Unable to reopen the payment window.');
        create_user_notification($conn, $userId, 'Payment Proof Rejected', $notice);
        realtime_enqueue_event($conn, 'admin', 'payment.proof_rejected', ['booking_id' => $bookingId, 'submission_id' => (int)$submissionId, 'reference_no' => $referenceNo]);
        realtime_enqueue_event($conn, 'customer:' . $userId, 'payment.proof_rejected', ['booking_id' => $bookingId, 'submission_id' => (int)$submissionId, 'reference_no' => $referenceNo]);
        $auditAction = 'Rejected ' . $submission['payment_method'] . ' proof for Booking ' . $referenceNo . ' (submission ' . $submissionId . ')';
    }

    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Manual Payment', ?, ?)");
    if (!$audit) throw new RuntimeException('Unable to record payment review.');
    $audit->bind_param('iss', $reviewerId, $auditAction, $ip);
    if (!$audit->execute()) throw new RuntimeException('Unable to record payment review.');
    if (!$conn->commit()) throw new RuntimeException('Unable to commit payment review.');

    if ($mailAfterCommit !== null) {
        try {
            send_booking_receipt($mailAfterCommit['email'], $mailAfterCommit['name'], $mailAfterCommit['reference'], $mailAfterCommit['venue'], $mailAfterCommit['paid'], $mailAfterCommit['status']);
        } catch (Throwable $mailError) {
            error_log('Manual payment receipt delivery failed: ' . get_class($mailError) . ' booking_id=' . $bookingId);
        }
    }
    echo json_encode(['success' => true, 'duplicate' => false, 'message' => $decision === 'approve' ? 'Payment proof verified and payment recorded.' : 'Payment proof rejected.']);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    http_response_code(422);
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'Payment review could not be completed.';
    echo json_encode(['success' => false, 'message' => $message]);
}
