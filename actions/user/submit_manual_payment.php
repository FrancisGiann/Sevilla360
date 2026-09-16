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
$methodKeyInput = $_POST['method'] ?? null;
$methodKey = is_string($methodKeyInput) ? trim($methodKeyInput) : '';
if (!$bookingId || !manual_payment_method_key_is_valid($methodKey)) {
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
$method = '';
try {
    $availableMethods = manual_payment_load_instructions($conn);
    if (!isset($availableMethods[$methodKey]) || !$availableMethods[$methodKey]['enabled']) throw new RuntimeException('That payment method is no longer available. Refresh and choose another method.');
    if (!is_array($proof) || (int)($proof['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($proof['tmp_name'] ?? ''))) {
        throw new RuntimeException('Choose a receipt image before submitting.');
    }
    $stored = manual_payment_store_proof((string)$proof['tmp_name']);
    $referenceInput = $_POST['transaction_reference'] ?? null;
    if (!is_string($referenceInput)) throw new RuntimeException('Enter a valid transaction reference.');
    $reference = trim($referenceInput);
    manual_payment_validate_reference($reference);

    if (!$conn->begin_transaction()) throw new RuntimeException('Unable to start payment submission.');
    $settingsLock = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'manual_payment_instructions' LIMIT 1 FOR UPDATE");
    if (!$settingsLock || !$settingsLock->execute()) throw new RuntimeException('Unable to verify the active payment method.');
    $settingsRow = $settingsLock->get_result()->fetch_assoc();
    $settingsLock->close();
    if (!$settingsRow) throw new RuntimeException('No customer payment methods are currently available.');
    $lockedMethods = manual_payment_decode_instructions((string)$settingsRow['setting_value']);
    if (!isset($lockedMethods[$methodKey]) || !$lockedMethods[$methodKey]['enabled']) throw new RuntimeException('That payment method is no longer available. Refresh and choose another method.');
    $method = manual_payment_validate_method_name($lockedMethods[$methodKey]['name']);
    $fingerprint = manual_payment_reference_fingerprint($method, $reference);

    $completionSql = booking_completion_sql('b');
    $sql = "SELECT b.id, b.reference_no, b.total_amount, b.amount_paid, b.payment_scheme, b.booking_status, b.payment_status, b.payment_due_at, b.source, v.category AS venue_category, CASE WHEN {$completionSql} THEN 1 ELSE 0 END AS is_completed, (b.payment_due_at IS NULL OR b.payment_due_at > NOW()) AS deadline_open, EXISTS (SELECT 1 FROM cancellations cx WHERE cx.booking_id = b.id AND cx.status = 'Pending') AS pending_cancel_request, EXISTS (SELECT 1 FROM reschedule_requests rr WHERE rr.booking_id = b.id AND rr.status = 'Pending') AS pending_reschedule_request FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN venues v ON v.id = b.venue_id WHERE b.id = ? AND c.user_id = ? FOR UPDATE";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to validate this booking.');
    $userId = (int)$_SESSION['user_id'];
    $stmt->bind_param('ii', $bookingId, $userId);
    if (!$stmt->execute()) throw new RuntimeException('Unable to validate this booking.');
    $booking = $stmt->get_result()->fetch_assoc();
    if (!$booking) throw new RuntimeException('Booking not found or access denied.');
    $lockedBookingId = (int)$booking['id'];
    // Current/locking reads refresh the EXISTS flags after acquiring the booking lock.
    // Lifecycle writers use the same booking-first order, so a request committed while
    // this submission waited is visible and cannot change until this transaction ends.
    $cancelRequestLock = $conn->prepare("SELECT id FROM cancellations WHERE booking_id = ? AND status = 'Pending' LIMIT 1 FOR UPDATE");
    if (!$cancelRequestLock) throw new RuntimeException('Unable to validate pending booking requests.');
    $cancelRequestLock->bind_param('i', $lockedBookingId);
    if (!$cancelRequestLock->execute()) throw new RuntimeException('Unable to validate pending booking requests.');
    $booking['pending_cancel_request'] = $cancelRequestLock->get_result()->num_rows > 0 ? 1 : 0;

    $rescheduleRequestLock = $conn->prepare("SELECT id FROM reschedule_requests WHERE booking_id = ? AND status = 'Pending' ORDER BY id LIMIT 1 FOR UPDATE");
    if (!$rescheduleRequestLock) throw new RuntimeException('Unable to validate pending booking requests.');
    $rescheduleRequestLock->bind_param('i', $lockedBookingId);
    if (!$rescheduleRequestLock->execute()) throw new RuntimeException('Unable to validate pending booking requests.');
    $booking['pending_reschedule_request'] = $rescheduleRequestLock->get_result()->num_rows > 0 ? 1 : 0;
    if ((int)$booking['pending_cancel_request'] === 1) throw new RuntimeException('Wait for the cancellation or refund request to be reviewed before submitting or replacing payment proof.');
    if ((int)$booking['pending_reschedule_request'] === 1) throw new RuntimeException('Wait for the reschedule request to be reviewed before submitting or replacing payment proof.');
    if (booking_is_completed($booking) || $booking['booking_status'] === 'Cancelled' || $booking['payment_status'] === 'Paid' || $booking['payment_status'] === 'Refunded' || $booking['source'] !== 'Online' || !in_array($booking['booking_status'], ['Pending', 'Confirmed'], true)) {
        throw new RuntimeException('This booking can no longer accept payment proof.');
    }
    if ($booking['venue_category'] === 'Event Hall' && $booking['booking_status'] === 'Pending') throw new RuntimeException('Your Event Hall inquiry must be quoted before payment.');
    if ((int)$booking['is_completed'] === 1) throw new RuntimeException('Completed bookings cannot accept payment proof.');
    $expectedAmount = manual_payment_expected_amount($booking);

    $pending = $conn->prepare("SELECT id, booking_id, status, proof_filename FROM manual_payment_submissions WHERE booking_id = ? AND status = 'pending' ORDER BY id FOR UPDATE");
    if (!$pending) throw new RuntimeException('Unable to check existing payment proof.');
    $pending->bind_param('i', $bookingId);
    if (!$pending->execute()) throw new RuntimeException('Unable to check existing payment proof.');
    $pendingRows = $pending->get_result()->fetch_all(MYSQLI_ASSOC);
    if (count($pendingRows) > 1) throw new RuntimeException('Multiple proofs are awaiting review for this booking. Contact the resort before replacing one.');
    $pendingSubmission = $pendingRows[0] ?? null;
    if (!$pendingSubmission && (float)$booking['amount_paid'] === 0.0 && $booking['payment_status'] === 'Unpaid' && (int)$booking['deadline_open'] !== 1) throw new RuntimeException('The payment window has expired. Contact the resort for help.');

    $existingStmt = $conn->prepare('SELECT id, booking_id, status, proof_filename FROM manual_payment_submissions WHERE reference_fingerprint = ? LIMIT 1 FOR UPDATE');
    if (!$existingStmt) throw new RuntimeException('Unable to check transaction reference history.');
    $existingStmt->bind_param('s', $fingerprint);
    if (!$existingStmt->execute()) throw new RuntimeException('Unable to check transaction reference history.');
    $existing = $existingStmt->get_result()->fetch_assoc() ?: null;
    $decision = manual_payment_submission_decision($pendingSubmission, $existing, (int)$bookingId);
    $previousProofFilename = null;
    if ($decision === 'replace_pending') {
        $submissionId = (int)$pendingSubmission['id'];
        $previousProofFilename = (string)$pendingSubmission['proof_filename'];
        $stmt = $conn->prepare("UPDATE manual_payment_submissions SET customer_user_id = ?, reviewer_user_id = NULL, payment_id = NULL, payment_method = ?, expected_amount = ?, transaction_reference = ?, reference_fingerprint = ?, proof_filename = ?, proof_mime = ?, proof_size_bytes = ?, proof_sha256 = ?, rejection_reason = NULL, submitted_at = NOW(), reviewed_at = NULL WHERE id = ? AND booking_id = ? AND status = 'pending'");
        if (!$stmt) throw new RuntimeException('Unable to replace the pending payment proof.');
        $stmt->bind_param('isdsssssisii', $userId, $method, $expectedAmount, $reference, $fingerprint, $stored['filename'], $stored['mime'], $stored['size'], $stored['sha256'], $submissionId, $bookingId);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) throw new RuntimeException('The pending payment proof changed before replacement could be saved.');
    } elseif ($decision === 'resubmit') {
        $submissionId = (int)$existing['id'];
        $previousProofFilename = (string)$existing['proof_filename'];
        $stmt = $conn->prepare("UPDATE manual_payment_submissions SET customer_user_id = ?, reviewer_user_id = NULL, payment_id = NULL, payment_method = ?, expected_amount = ?, transaction_reference = ?, reference_fingerprint = ?, proof_filename = ?, proof_mime = ?, proof_size_bytes = ?, proof_sha256 = ?, status = 'pending', rejection_reason = NULL, submitted_at = NOW(), reviewed_at = NULL WHERE id = ? AND booking_id = ? AND status = 'rejected'");
        if (!$stmt) throw new RuntimeException('Unable to update corrected payment proof.');
        $stmt->bind_param('isdsssssisii', $userId, $method, $expectedAmount, $reference, $fingerprint, $stored['filename'], $stored['mime'], $stored['size'], $stored['sha256'], $submissionId, $bookingId);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) throw new RuntimeException('The rejected payment proof changed before correction could be saved.');
    } else {
        $stmt = $conn->prepare("INSERT INTO manual_payment_submissions (booking_id, customer_user_id, payment_method, expected_amount, transaction_reference, reference_fingerprint, proof_filename, proof_mime, proof_size_bytes, proof_sha256, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
        if (!$stmt) throw new RuntimeException('Unable to save payment proof.');
        $stmt->bind_param('iisdssssis', $bookingId, $userId, $method, $expectedAmount, $reference, $fingerprint, $stored['filename'], $stored['mime'], $stored['size'], $stored['sha256']);
        if (!$stmt->execute()) throw new RuntimeException('Unable to save payment proof.');
        $submissionId = (int)$conn->insert_id;
    }

    $action = match ($decision) {
        'replace_pending' => 'Customer replaced pending ' . $method . ' proof for Booking ' . $booking['reference_no'] . ' (submission ' . $submissionId . ')',
        'resubmit' => 'Customer corrected rejected ' . $method . ' proof for Booking ' . $booking['reference_no'],
        default => 'Customer submitted ' . $method . ' proof for Booking ' . $booking['reference_no'],
    };
    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Manual Payment', ?, ?)");
    if (!$audit) throw new RuntimeException('Unable to record payment proof audit.');
    $ip = request_client_ip();
    $audit->bind_param('iss', $userId, $action, $ip);
    if (!$audit->execute()) throw new RuntimeException('Unable to record payment proof audit.');
    $notificationTitle = match ($decision) {
        'replace_pending' => 'Payment Proof Replaced',
        'resubmit' => 'Corrected Payment Proof Received',
        default => 'Payment Proof Received',
    };
    $notificationVerb = $decision === 'replace_pending' ? 'replacement' : 'proof';
    create_user_notification($conn, $userId, $notificationTitle, 'Your ' . $method . ' payment ' . $notificationVerb . ' for booking ' . $booking['reference_no'] . ' is awaiting review.');
    realtime_enqueue_event($conn, 'admin', 'payment.proof_submitted', ['booking_id' => (int)$bookingId, 'submission_id' => $submissionId, 'reference_no' => (string)$booking['reference_no'], 'replacement' => $decision === 'replace_pending']);
    if (!$conn->commit()) throw new RuntimeException('Unable to save payment proof.');
    $stored = null;
    if ($previousProofFilename !== null) {
        try { @unlink(manual_payment_proof_file_path($previousProofFilename)); } catch (Throwable $ignored) {}
    }
    $successMessage = $decision === 'replace_pending'
        ? 'Your replacement receipt was received. The updated proof is awaiting payment verification.'
        : 'Receipt received. Your booking is awaiting payment verification.';
    echo json_encode(['success' => true, 'message' => $successMessage, 'expected_amount' => $expectedAmount]);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    if ($stored !== null) @unlink($stored['path']);
    http_response_code(422);
    $message = (int)$e->getCode() === 1062 ? 'That transaction reference has already been submitted. Check the reference or contact the resort.' : ($e instanceof RuntimeException ? $e->getMessage() : 'Payment proof could not be submitted.');
    echo json_encode(['success' => false, 'message' => $message]);
}
