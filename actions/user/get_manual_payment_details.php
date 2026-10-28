<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json; charset=utf-8');
if (($_SESSION['role'] ?? '') !== 'customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sign in with your customer account to view payment instructions.']);
    exit;
}
$csrf = (string)($_SESSION['csrf_token'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $csrf === '' || !hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Payment session validation failed.']);
    exit;
}
$bookingId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$holdToken = is_string($_POST['hold_token'] ?? null) ? $_POST['hold_token'] : '';
if (!preg_match('/\A[a-f0-9]{32}\z/D', $holdToken)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid payment session.']);
    exit;
}
if (!$bookingId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Booking not found.']);
    exit;
}
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/session_account_guard.php';
require_once __DIR__ . '/../../includes/booking_lifecycle.php';
require_once __DIR__ . '/../../includes/manual_payment.php';
if (!session_account_require_active_json($conn, ['customer'], ['success' => false, 'message' => 'Sign in with your customer account to view payment instructions.'], ['success' => false, 'message' => 'Account status could not be verified.'])) exit;

try {
    if (!$conn->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') || !$conn->begin_transaction()) throw new RuntimeException('Unable to start payment step.');
    $completionSql = booking_completion_sql('b');
    $stmt = $conn->prepare("SELECT b.id, b.reference_no, b.venue_id, b.start_date, b.end_date, b.total_amount, b.amount_paid, b.payment_scheme, b.booking_status, b.payment_status, b.payment_due_at, b.payment_step_token, b.payment_step_expires_at, b.payment_step_seen_at, b.proof_first_submitted_at, b.proof_inventory_conflict, b.source, v.category AS venue_category, CASE WHEN {$completionSql} THEN 1 ELSE 0 END AS is_completed, (b.payment_due_at IS NULL OR b.payment_due_at > NOW()) AS deadline_open, EXISTS (SELECT 1 FROM cancellations cx WHERE cx.booking_id = b.id AND cx.status = 'Pending') AS pending_cancel_request, EXISTS (SELECT 1 FROM reschedule_requests rr WHERE rr.booking_id = b.id AND rr.status = 'Pending') AS pending_reschedule_request FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN venues v ON v.id = b.venue_id WHERE b.id = ? AND c.user_id = ? LIMIT 1 FOR UPDATE");
    if (!$stmt) throw new RuntimeException('Unable to load payment details.');
    $userId = (int)$_SESSION['user_id'];
    $stmt->bind_param('ii', $bookingId, $userId);
    if (!$stmt->execute()) throw new RuntimeException('Unable to load payment details.');
    $booking = $stmt->get_result()->fetch_assoc();
    if (!$booking) throw new RuntimeException('Booking not found or access denied.');
    if ((int)$booking['pending_cancel_request'] === 1) throw new RuntimeException('Wait for the cancellation or refund request to be reviewed before submitting or replacing payment proof.');
    if ((int)$booking['pending_reschedule_request'] === 1) throw new RuntimeException('Wait for the reschedule request to be reviewed before submitting or replacing payment proof.');

    $latestStmt = $conn->prepare('SELECT status, payment_method, transaction_reference, rejection_reason, submitted_at FROM manual_payment_submissions WHERE booking_id = ? ORDER BY id DESC LIMIT 1');
    if (!$latestStmt) throw new RuntimeException('Unable to load proof status.');
    $latestStmt->bind_param('i', $bookingId);
    if (!$latestStmt->execute()) throw new RuntimeException('Unable to load proof status.');
    $latest = $latestStmt->get_result()->fetch_assoc();

    $isPendingReplacement = $latest && $latest['status'] === 'pending';
    $reconcileCancelled = $booking['booking_status'] === 'Cancelled' && $booking['payment_step_seen_at'] !== null && $booking['proof_first_submitted_at'] === null && $booking['payment_status'] === 'Unpaid';
    if (booking_is_completed($booking) || ($booking['booking_status'] === 'Cancelled' && !$reconcileCancelled) || $booking['payment_status'] === 'Paid' || $booking['payment_status'] === 'Refunded' || $booking['source'] !== 'Online') {
        throw new RuntimeException('This booking cannot accept a customer payment.');
    }
    if ($booking['venue_category'] === 'Event Hall' && $booking['booking_status'] === 'Pending') throw new RuntimeException('Your Event Hall inquiry must be quoted before payment.');
    if (!$reconcileCancelled && !in_array($booking['booking_status'], ['Awaiting Proof', 'Pending', 'Confirmed'], true)) throw new RuntimeException('This booking cannot accept a customer payment.');

    $instructions = manual_payment_load_instructions($conn);
    $hasEnabledMethod = (bool)array_filter($instructions, static fn(array $method): bool => $method['enabled']);
    $reconciliationOnly = $reconcileCancelled || (int)$booking['proof_inventory_conflict'] === 1;
    $holdExpiresAt = null;
    if ($booking['booking_status'] === 'Awaiting Proof' && !$isPendingReplacement && !$reconciliationOnly && $hasEnabledMethod && (int)$booking['deadline_open'] === 1) {
        $holdExpiresAt = manual_payment_acquire_step_hold_locked($conn, $booking, $holdToken);
        $reconciliationOnly = $holdExpiresAt === null;
    } elseif ($booking['booking_status'] === 'Awaiting Proof') {
        $reconciliationOnly = true;
    }
    if (!$isPendingReplacement && (float)$booking['amount_paid'] === 0.0 && $booking['payment_status'] === 'Unpaid' && (int)$booking['deadline_open'] !== 1) $reconciliationOnly = true;

    $methods = [];
    foreach ($instructions as $key => $method) {
        if (!$method['enabled']) continue;
        $methods[] = $reconciliationOnly ? ['key' => $key, 'label' => $method['name']] : ['key' => $key, 'label' => $method['name']] + $method;
    }

    if (!$conn->commit()) throw new RuntimeException('Unable to save payment step.');

    echo json_encode(['success' => true, 'data' => [
        'booking_id' => (int)$booking['id'],
        'reference_no' => (string)$booking['reference_no'],
        'booking_status' => (string)$booking['booking_status'],
        'expected_amount' => manual_payment_expected_amount($booking),
        'payment_due_at' => $booking['payment_due_at'],
        'hold_token' => $holdExpiresAt === null ? null : $holdToken,
        'hold_expires_at' => $holdExpiresAt,
        'reconciliation_only' => $reconciliationOnly,
        'methods' => $methods,
        'latest_submission' => $latest,
        'review_overdue' => $isPendingReplacement && manual_payment_proof_review_overdue((string)$latest['submitted_at'], manual_payment_review_hours($conn)),
        'submission_mode' => $isPendingReplacement ? 'replace_pending' : (($latest['status'] ?? '') === 'rejected' ? 'resubmit_rejected' : 'submit'),
    ]], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Payment details could not be loaded.']);
}
