<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json; charset=utf-8');
if (($_SESSION['role'] ?? '') !== 'customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sign in with your customer account to view payment instructions.']);
    exit;
}
$bookingId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$bookingId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Booking not found.']);
    exit;
}
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/booking_lifecycle.php';
require_once __DIR__ . '/../../includes/manual_payment.php';

try {
    $completionSql = booking_completion_sql('b');
    $stmt = $conn->prepare("SELECT b.id, b.reference_no, b.total_amount, b.amount_paid, b.payment_scheme, b.booking_status, b.payment_status, b.payment_due_at, b.source, v.category AS venue_category, CASE WHEN {$completionSql} THEN 1 ELSE 0 END AS is_completed, (b.payment_due_at IS NULL OR b.payment_due_at > NOW()) AS deadline_open FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN venues v ON v.id = b.venue_id WHERE b.id = ? AND c.user_id = ? LIMIT 1");
    if (!$stmt) throw new RuntimeException('Unable to load payment details.');
    $userId = (int)$_SESSION['user_id'];
    $stmt->bind_param('ii', $bookingId, $userId);
    if (!$stmt->execute()) throw new RuntimeException('Unable to load payment details.');
    $booking = $stmt->get_result()->fetch_assoc();
    if (!$booking) throw new RuntimeException('Booking not found or access denied.');

    $latestStmt = $conn->prepare('SELECT status, rejection_reason, submitted_at FROM manual_payment_submissions WHERE booking_id = ? ORDER BY id DESC LIMIT 1');
    if (!$latestStmt) throw new RuntimeException('Unable to load proof status.');
    $latestStmt->bind_param('i', $bookingId);
    if (!$latestStmt->execute()) throw new RuntimeException('Unable to load proof status.');
    $latest = $latestStmt->get_result()->fetch_assoc();

    if ($latest && $latest['status'] === 'pending') throw new RuntimeException('Your payment proof is awaiting review.');
    if (booking_is_completed($booking) || $booking['booking_status'] === 'Cancelled' || $booking['payment_status'] === 'Paid' || $booking['payment_status'] === 'Refunded' || $booking['source'] !== 'Online') {
        throw new RuntimeException('This booking cannot accept a customer payment.');
    }
    if ($booking['venue_category'] === 'Event Hall' && $booking['booking_status'] === 'Pending') throw new RuntimeException('Your Event Hall inquiry must be quoted before payment.');
    if (!in_array($booking['booking_status'], ['Pending', 'Confirmed'], true)) throw new RuntimeException('This booking cannot accept a customer payment.');
    if ((float)$booking['amount_paid'] === 0.0 && $booking['payment_status'] === 'Unpaid' && (int)$booking['deadline_open'] !== 1) throw new RuntimeException('The payment window has expired. Contact the resort for help.');

    $instructions = manual_payment_load_instructions($conn);
    $methods = [];
    foreach (MANUAL_PAYMENT_METHODS as $key => $label) {
        if (!$instructions[$key]['enabled']) continue;
        $methods[] = ['key' => $key, 'label' => $label] + $instructions[$key];
    }

    echo json_encode(['success' => true, 'data' => [
        'booking_id' => (int)$booking['id'],
        'reference_no' => (string)$booking['reference_no'],
        'expected_amount' => manual_payment_expected_amount($booking),
        'payment_due_at' => $booking['payment_due_at'],
        'methods' => $methods,
        'latest_submission' => $latest,
    ]], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Payment details could not be loaded.']);
}
