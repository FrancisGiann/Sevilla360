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
if (!is_array($request)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON request.']);
    exit;
}
$submissionId = filter_var($request['submission_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$decisionInput = $request['decision'] ?? null;
$decision = is_string($decisionInput) ? $decisionInput : '';
if (!$submissionId || !in_array($decision, ['approve', 'reject'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Choose an approval decision for a valid submission.']);
    exit;
}
$reasonInput = $request['rejection_reason'] ?? '';
$reason = is_string($reasonInput) ? trim($reasonInput) : '';
if ($decision === 'reject' && ($reason === '' || strlen($reason) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason))) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter a rejection reason between 1 and 500 characters.']);
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/session_account_guard.php';
if (!session_account_require_active_json($conn, ['admin', 'staff'], ['success' => false, 'message' => 'Staff access is required.'], ['success' => false, 'message' => 'Account status could not be verified.'], 403)) exit;
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
    if (!$conn->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') || !$conn->begin_transaction()) throw new RuntimeException('Unable to start payment review.');

    $completionSql = booking_completion_sql('b');
    $lockBooking = $conn->prepare("SELECT b.id, b.reference_no, b.venue_id, b.start_date, b.end_date, b.total_amount, b.amount_paid, b.payment_scheme, b.booking_status, b.payment_status, b.payment_due_at, b.proof_first_submitted_at, b.proof_rejections_count, b.proof_retry_submitted_at, b.proof_inventory_conflict, CASE WHEN b.end_date < CURDATE() THEN 1 ELSE 0 END AS event_ended, b.source, c.user_id, c.email, c.first_name, c.last_name, v.name AS venue_name, v.category AS venue_category, CASE WHEN {$completionSql} THEN 1 ELSE 0 END AS is_completed FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN venues v ON v.id = b.venue_id WHERE b.id = ? FOR UPDATE");
    if (!$lockBooking) throw new RuntimeException('Unable to lock booking for payment review.');
    $lockBooking->bind_param('i', $bookingId);
    if (!$lockBooking->execute()) throw new RuntimeException('Unable to lock booking for payment review.');
    $booking = $lockBooking->get_result()->fetch_assoc();
    if (!$booking) throw new RuntimeException('Booking not found.');
    $eventEnded = (int)$booking['event_ended'] === 1;

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
        if ($booking['booking_status'] === 'Awaiting Proof' || (int)$booking['proof_inventory_conflict'] === 1) {
            if (!manual_payment_claim_inventory_locked($conn, $booking)) throw new RuntimeException('This date is occupied. Resolve the transferred payment with the customer; do not approve a conflicting reservation.');
            $claimOnApproval = $conn->prepare("UPDATE bookings SET booking_status = 'Pending', proof_inventory_conflict = 0 WHERE id = ? AND booking_status = 'Awaiting Proof'");
            if (!$claimOnApproval) throw new RuntimeException('Unable to claim inventory during payment review.');
            $claimOnApproval->bind_param('i', $bookingId);
            if (!$claimOnApproval->execute() || $claimOnApproval->affected_rows !== 1) throw new RuntimeException('Inventory claim changed during review.');
            $booking['booking_status'] = 'Pending';
            $booking['proof_inventory_conflict'] = 0;
        }
        $acknowledgedLateVerification = ($request['acknowledge_late_verification'] ?? false) === true;
        manual_payment_assert_approval_eligible($booking, $eventEnded, $acknowledgedLateVerification);
        $currentExpectedCents = manual_payment_expected_amount_cents($booking);
        $submittedExpectedCents = (int)round((float)$submission['expected_amount'] * 100);
        if ($currentExpectedCents !== $submittedExpectedCents) throw new RuntimeException('The booking balance changed after this proof was submitted. Use Collect Payment to resolve it.');
        $fingerprintCheck = $conn->prepare('SELECT id FROM manual_payment_submissions WHERE reference_fingerprint = ? AND id <> ? LIMIT 1 FOR UPDATE');
        if (!$fingerprintCheck) throw new RuntimeException('Unable to verify transaction-reference uniqueness.');
        $fingerprintCheck->bind_param('si', $submission['reference_fingerprint'], $submissionId);
        if (!$fingerprintCheck->execute()) throw new RuntimeException('Unable to verify transaction-reference uniqueness.');
        if ($fingerprintCheck->get_result()->num_rows > 0) throw new RuntimeException('This transaction reference has already been submitted.');
        $historyCheck = $conn->prepare('SELECT booking_id FROM manual_payment_submission_history WHERE reference_fingerprint = ? AND booking_id <> ? LIMIT 1');
        if (!$historyCheck) throw new RuntimeException('Unable to verify historical payment references.');
        $historyCheck->bind_param('si', $submission['reference_fingerprint'], $bookingId);
        if (!$historyCheck->execute()) throw new RuntimeException('Unable to verify historical payment references.');
        if ($historyCheck->get_result()->num_rows > 0) throw new RuntimeException('This transaction reference was previously used for another booking.');
        $methodCode = manual_payment_transaction_method_code((string)$submission['payment_method']);
        $transactionId = 'MANUAL-' . $methodCode . ':' . strtoupper(trim((string)$submission['transaction_reference']));
        $credit = manual_payment_credit_locked($conn, $booking, $currentExpectedCents / 100, (string)$submission['payment_method'], $transactionId, (int)$submissionId, $eventEnded);
        manual_payment_archive_submission_locked($conn, $submissionId);
        $update = $conn->prepare("UPDATE manual_payment_submissions SET status = 'approved', reviewer_user_id = ?, reviewed_at = NOW(), rejection_reason = NULL, payment_id = ? WHERE id = ? AND status = 'pending'");
        if (!$update) throw new RuntimeException('Unable to approve payment proof.');
        $update->bind_param('iii', $reviewerId, $credit['payment_id'], $submissionId);
        if (!$update->execute() || $update->affected_rows !== 1) throw new RuntimeException('Payment proof was already reviewed.');
        $message = 'Your ' . $submission['payment_method'] . ' payment of ₱' . number_format($credit['amount'], 2) . ' for booking ' . $referenceNo . ' has been verified.';
        create_user_notification($conn, $userId, 'Payment Verified', $message);
        realtime_enqueue_event($conn, 'admin', 'payment.received', ['booking_id' => $bookingId, 'reference_no' => $referenceNo, 'payment_status' => $credit['payment_status'], 'amount_paid' => $credit['amount_paid']]);
        realtime_enqueue_event($conn, 'customer:' . $userId, 'payment.received', ['booking_id' => $bookingId, 'reference_no' => $referenceNo, 'payment_status' => $credit['payment_status'], 'amount_paid' => $credit['amount_paid']]);
        $mailAfterCommit = ['email' => (string)$booking['email'], 'name' => $customerName, 'reference' => $referenceNo, 'venue' => (string)$booking['venue_name'], 'paid' => $credit['amount_paid'], 'status' => $credit['payment_status'] === 'Paid' ? 'Fully Paid' : 'Partially Paid (Manual Payment)'];
        $auditAction = ($eventEnded ? 'Approved overdue ' : 'Approved ') . $submission['payment_method'] . ' proof for Booking ' . $referenceNo . ' (submission ' . $submissionId . ')';
        $auditEventType = 'payment.manual_proof_approved';
        $auditDetails = [
            'booking_reference' => $referenceNo,
            'payment_method' => (string)$submission['payment_method'],
            'amount' => (float)$credit['amount'],
            'submission_id' => (int)$submissionId,
            'payment_id' => (int)$credit['payment_id'],
            'late_verification' => $eventEnded,
            'booking_status_preserved' => $eventEnded,
            'booking_status' => (string)$booking['booking_status'],
        ];
    } else {
        manual_payment_archive_submission_locked($conn, $submissionId);
        $update = $conn->prepare("UPDATE manual_payment_submissions SET status = 'rejected', reviewer_user_id = ?, reviewed_at = NOW(), rejection_reason = ? WHERE id = ? AND status = 'pending'");
        if (!$update) throw new RuntimeException('Unable to reject payment proof.');
        $update->bind_param('isi', $reviewerId, $reason, $submissionId);
        if (!$update->execute() || $update->affected_rows !== 1) throw new RuntimeException('Payment proof was already reviewed.');
        $cancelOverduePending = manual_payment_rejection_cancels_overdue_pending($booking, $eventEnded);
        $unpaid = (float)$booking['amount_paid'] === 0.0 && $booking['payment_status'] === 'Unpaid';
        $proofClaim = $unpaid && $booking['proof_first_submitted_at'] !== null && $booking['venue_category'] !== 'Event Hall';
        if ($proofClaim && (int)$booking['proof_rejections_count'] >= 1) {
            $cancelOverduePending = true;
            $close = $conn->prepare("UPDATE bookings SET booking_status = 'Cancelled', proof_rejections_count = 2, payment_due_at = NULL WHERE id = ? AND proof_rejections_count = 1 AND payment_status = 'Unpaid'");
            if (!$close) throw new RuntimeException('Unable to release rejected booking claim.');
            $close->bind_param('i', $bookingId);
            if (!$close->execute() || $close->affected_rows !== 1) throw new RuntimeException('Booking rejection state changed.');
            $notice = 'Your second payment proof for booking ' . $referenceNo . ' was rejected: ' . $reason . ' The date has been released. Contact the resort about any transferred funds.';
        } elseif ($proofClaim && !$eventEnded) {
            $reset = $conn->prepare("UPDATE bookings SET proof_rejections_count = 1, payment_due_at = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id = ? AND proof_rejections_count = 0");
            if (!$reset) throw new RuntimeException('Unable to reset the one-time correction window.');
            $reset->bind_param('i', $bookingId);
            if (!$reset->execute() || $reset->affected_rows !== 1) throw new RuntimeException('Booking rejection state changed.');
            $notice = 'Your payment proof for booking ' . $referenceNo . ' was rejected: ' . $reason . ' You may submit one corrected proof within a fresh 24-hour window.';
            $notice .= $booking['booking_status'] === 'Awaiting Proof'
                ? ' Your original proof conflicted with another reservation; this date is not held. Staff must resolve any transferred funds.'
                : ' The date remains held during this window.';
        } elseif ($eventEnded) {
            $cancelOverduePending = manual_payment_apply_overdue_rejection_locked($conn, $booking, true);
            $notice = $cancelOverduePending
                ? 'Your payment proof for booking ' . $referenceNo . ' was not accepted: ' . $reason . ' The event has ended, so the unpaid Pending booking was cancelled and no payment window was reopened.'
                : 'Your payment proof for booking ' . $referenceNo . ' was not accepted: ' . $reason . ' The event has ended, so the payment window was not reopened.';
        } elseif ($unpaid) {
            $hours = manual_payment_deadline_hours($conn);
            $dueSql = "DATE_ADD(NOW(), INTERVAL {$hours} HOUR)";
            $notice = 'Your payment proof for booking ' . $referenceNo . ' was not accepted: ' . $reason . ' A fresh ' . $hours . '-hour payment window has started.';
        } else {
            $dueSql = 'NULL';
            $notice = 'Your payment proof for booking ' . $referenceNo . ' was not accepted: ' . $reason . ' Submit a corrected proof for the remaining balance.';
        }
        if (!$eventEnded && !$proofClaim) {
            $setDue = $conn->prepare("UPDATE bookings SET payment_due_at = {$dueSql} WHERE id = ?");
            if (!$setDue) throw new RuntimeException('Unable to reopen the payment window.');
            $setDue->bind_param('i', $bookingId);
            if (!$setDue->execute()) throw new RuntimeException('Unable to reopen the payment window.');
        }
        create_user_notification($conn, $userId, 'Payment Proof Rejected', $notice);
        realtime_enqueue_event($conn, 'admin', 'payment.proof_rejected', ['booking_id' => $bookingId, 'submission_id' => (int)$submissionId, 'reference_no' => $referenceNo]);
        realtime_enqueue_event($conn, 'customer:' . $userId, 'payment.proof_rejected', ['booking_id' => $bookingId, 'submission_id' => (int)$submissionId, 'reference_no' => $referenceNo]);
        $auditAction = 'Rejected ' . $submission['payment_method'] . ' proof for Booking ' . $referenceNo . ' (submission ' . $submissionId . ')';
        $auditEventType = 'payment.manual_proof_rejected';
        $auditDetails = [
            'booking_reference' => $referenceNo,
            'payment_method' => (string)$submission['payment_method'],
            'amount' => (float)$submission['expected_amount'],
            'submission_id' => (int)$submissionId,
            'reason' => $reason,
            'late_verification' => $eventEnded,
            'booking_status_preserved' => !$cancelOverduePending,
            'booking_status' => $cancelOverduePending ? 'Cancelled' : (string)$booking['booking_status'],
        ];
    }

    $auditEntityType = 'booking';
    $auditDetailsJson = json_encode($auditDetails, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address, event_type, entity_type, entity_id, details_json) VALUES (?, 'Manual Payment', ?, ?, ?, ?, ?, ?)");
    if (!$audit) throw new RuntimeException('Unable to record payment review.');
    $audit->bind_param('issssis', $reviewerId, $auditAction, $ip, $auditEventType, $auditEntityType, $bookingId, $auditDetailsJson);
    if (!$audit->execute()) throw new RuntimeException('Unable to record payment review.');
    if (!$conn->commit()) throw new RuntimeException('Unable to commit payment review.');

    if ($mailAfterCommit !== null) {
        try {
            send_booking_receipt($mailAfterCommit['email'], $mailAfterCommit['name'], $mailAfterCommit['reference'], $mailAfterCommit['venue'], $mailAfterCommit['paid'], $mailAfterCommit['status']);
        } catch (Throwable $mailError) {
            error_log('Manual payment receipt delivery failed: ' . get_class($mailError) . ' booking_id=' . $bookingId);
        }
    }
    echo json_encode(['success' => true, 'duplicate' => false, 'late_verification' => $eventEnded && $decision === 'approve', 'message' => $decision === 'approve' ? 'Payment proof verified and payment recorded.' : 'Payment proof rejected.']);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    http_response_code(422);
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'Payment review could not be completed.';
    echo json_encode(['success' => false, 'message' => $message]);
}
