<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../includes/manual_payment.php';
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SESSION['role'] ?? '') !== 'customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sign in with your customer account to submit payment proof.']);
    exit;
}
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
    exit;
}
$bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$methodKey = (string)($_POST['method'] ?? '');
$method = MANUAL_PAYMENT_METHODS[$methodKey] ?? null;
if (!$bookingId || $method === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Choose a valid booking and payment method.']);
    exit;
}
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/booking_lifecycle.php';
require_once __DIR__ . '/../../includes/manual_payment.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/realtime.php';
require_once __DIR__ . '/../../includes/request_context.php';

$proof = $_FILES['receipt_image'] ?? null;
$stored = null;
try {
    if (!is_array($proof) || (int)($proof['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($proof['tmp_name'] ?? ''))) {
        throw new RuntimeException('Choose a receipt image before submitting.');
    }
    $stored = manual_payment_store_proof((string)$proof['tmp_name']);
    $reference = trim((string)($_POST['transaction_reference'] ?? ''));
    $normalizedReference = manual_payment_validate_reference($reference);
    $fingerprint = hash('sha256', strtoupper($method) . '|' . $normalizedReference);
    $instructions = manual_payment_load_instructions($conn);
    if (!$instructions[$methodKey]['enabled']) throw new RuntimeException('That payment method is no longer available. Refresh and choose another method.');

    if (!$conn->begin_transaction()) throw new RuntimeException('Unable to start payment submission.');
    $completionSql = booking_completion_sql('b');
    $sql = "SELECT b.id, b.reference_no, b.total_amount, b.amount_paid, b.payment_scheme, b.booking_status, b.payment_status, b.payment_due_at, b.source, v.category AS venue_category, CASE WHEN {$completionSql} THEN 1 ELSE 0 END AS is_completed, (b.payment_due_at IS NULL OR b.payment_due_at > NOW()) AS deadline_open FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN venues v ON v.id = b.venue_id WHERE b.id = ? AND c.user_id = ? FOR UPDATE";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to validate this booking.');
    $userId = (int)$_SESSION['user_id'];
    $stmt->bind_param('ii', $bookingId, $userId);
    if (!$stmt->execute()) throw new RuntimeException('Unable to validate this booking.');
    $booking = $stmt->get_result()->fetch_assoc();
    if (!$booking) throw new RuntimeException('Booking not found or access denied.');
    if (booking_is_completed($booking) || $booking['booking_status'] === 'Cancelled' || $booking['payment_status'] === 'Paid' || $booking['payment_status'] === 'Refunded' || $booking['source'] !== 'Online' || !in_array($booking['booking_status'], ['Pending', 'Confirmed'], true)) {
        throw new RuntimeException('This booking can no longer accept payment proof.');
    }
    if ($booking['venue_category'] === 'Event Hall' && $booking['booking_status'] === 'Pending') throw new RuntimeException('Your Event Hall inquiry must be quoted before payment.');
    if ((int)$booking['is_completed'] === 1) throw new RuntimeException('Completed bookings cannot accept payment proof.');
    if ((float)$booking['amount_paid'] === 0.0 && $booking['payment_status'] === 'Unpaid' && (int)$booking['deadline_open'] !== 1) throw new RuntimeException('The payment window has expired. Contact the resort for help.');
    $expectedAmount = manual_payment_expected_amount($booking);

    $pending = $conn->prepare("SELECT id FROM manual_payment_submissions WHERE booking_id = ? AND status = 'pending' LIMIT 1 FOR UPDATE");
    if (!$pending) throw new RuntimeException('Unable to check existing payment proof.');
    $pending->bind_param('i', $bookingId);
    if (!$pending->execute()) throw new RuntimeException('Unable to check existing payment proof.');
    if ($pending->get_result()->num_rows > 0) throw new RuntimeException('A payment proof is already awaiting review.');

    $existingStmt = $conn->prepare('SELECT id, booking_id, status, proof_filename FROM manual_payment_submissions WHERE reference_fingerprint = ? LIMIT 1 FOR UPDATE');
    if (!$existingStmt) throw new RuntimeException('Unable to check transaction reference history.');
    $existingStmt->bind_param('s', $fingerprint);
    if (!$existingStmt->execute()) throw new RuntimeException('Unable to check transaction reference history.');
    $existing = $existingStmt->get_result()->fetch_assoc() ?: null;
    $decision = manual_payment_submission_retry_decision($existing, (int)$bookingId);
    $previousProofFilename = null;
    if ($decision === 'resubmit') {
        $submissionId = (int)$existing['id'];
        $previousProofFilename = (string)$existing['proof_filename'];
        $stmt = $conn->prepare("UPDATE manual_payment_submissions SET customer_user_id = ?, reviewer_user_id = NULL, payment_id = NULL, payment_method = ?, expected_amount = ?, transaction_reference = ?, proof_filename = ?, proof_mime = ?, proof_size_bytes = ?, proof_sha256 = ?, status = 'pending', rejection_reason = NULL, submitted_at = NOW(), reviewed_at = NULL WHERE id = ? AND status = 'rejected'");
        if (!$stmt) throw new RuntimeException('Unable to update corrected payment proof.');
        $stmt->bind_param('isdsssisi', $userId, $method, $expectedAmount, $reference, $stored['filename'], $stored['mime'], $stored['size'], $stored['sha256'], $submissionId);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) throw new RuntimeException('The rejected payment proof changed before correction could be saved.');
    } else {
        $stmt = $conn->prepare("INSERT INTO manual_payment_submissions (booking_id, customer_user_id, payment_method, expected_amount, transaction_reference, reference_fingerprint, proof_filename, proof_mime, proof_size_bytes, proof_sha256, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
        if (!$stmt) throw new RuntimeException('Unable to save payment proof.');
        $stmt->bind_param('iisdssssis', $bookingId, $userId, $method, $expectedAmount, $reference, $fingerprint, $stored['filename'], $stored['mime'], $stored['size'], $stored['sha256']);
        if (!$stmt->execute()) throw new RuntimeException('Unable to save payment proof.');
        $submissionId = (int)$conn->insert_id;
    }

    $action = $decision === 'resubmit'
        ? 'Customer corrected rejected ' . $method . ' proof for Booking ' . $booking['reference_no']
        : 'Customer submitted ' . $method . ' proof for Booking ' . $booking['reference_no'];
    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Manual Payment', ?, ?)");
    if (!$audit) throw new RuntimeException('Unable to record payment proof audit.');
    $ip = request_client_ip();
    $audit->bind_param('iss', $userId, $action, $ip);
    if (!$audit->execute()) throw new RuntimeException('Unable to record payment proof audit.');
    $notificationTitle = $decision === 'resubmit' ? 'Corrected Payment Proof Received' : 'Payment Proof Received';
    create_user_notification($conn, $userId, $notificationTitle, 'Your ' . $method . ' payment proof for booking ' . $booking['reference_no'] . ' is awaiting review.');
    realtime_enqueue_event($conn, 'admin', 'payment.proof_submitted', ['booking_id' => (int)$bookingId, 'submission_id' => $submissionId, 'reference_no' => (string)$booking['reference_no']]);
    if (!$conn->commit()) throw new RuntimeException('Unable to save payment proof.');
    $stored = null;
    if ($previousProofFilename !== null) {
        try { @unlink(manual_payment_proof_file_path($previousProofFilename)); } catch (Throwable $ignored) {}
    }
    echo json_encode(['success' => true, 'message' => 'Receipt received. Your booking is awaiting payment verification.', 'expected_amount' => $expectedAmount]);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    if ($stored !== null) @unlink($stored['path']);
    http_response_code(422);
    $message = (int)$e->getCode() === 1062 ? 'That transaction reference has already been submitted. Check the reference or contact the resort.' : ($e instanceof RuntimeException ? $e->getMessage() : 'Payment proof could not be submitted.');
    echo json_encode(['success' => false, 'message' => $message]);
}
