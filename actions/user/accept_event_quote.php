<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SESSION['role'] ?? '') !== 'customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sign in with your customer account to accept this quotation.']);
    exit;
}

$csrfToken = (string)($_SESSION['csrf_token'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $csrfToken === ''
    || !hash_equals($csrfToken, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session validation failed.']);
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, 8193);
if (!is_string($raw) || strlen($raw) > 8192) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}
$data = json_decode($raw, true);
$bookingId = filter_var($data['booking_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$quoteVersion = filter_var($data['quote_version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_array($data) || !$bookingId || !$quoteVersion) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid Event Hall quotation is required.']);
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/session_account_guard.php';
require_once __DIR__ . '/../../includes/paymongo_native.php';
if (!defined('SEVILLA_PM_FULL_APP') || !SEVILLA_PM_FULL_APP) {
    http_response_code(410);
    echo json_encode(['success' => false, 'message' => 'Event Hall quote acceptance is unavailable.']);
    exit;
}
if (!session_account_require_active_json($conn, ['customer'], ['success' => false, 'message' => 'Sign in with your customer account to accept this quotation.'], ['success' => false, 'message' => 'Account status could not be verified.'])) exit;

try {
    paymongo_native_gate();
    $store = paymongo_native_store($conn);
    $userId = (int)$_SESSION['user_id'];
    $result = $store->atomic(function () use ($store, $userId, $bookingId, $quoteVersion): array {
        $customer = $store->row('SELECT id FROM customers WHERE user_id=? LIMIT 1', [$userId]);
        if (!$customer) throw new RuntimeException('Booking not found or access denied.');
        $customerId = (int)$customer['id'];
        $booking = $store->booking((int)$bookingId, $customerId);
        if (($booking['venue_category'] ?? '') !== 'Event Hall' || ($booking['source'] ?? '') !== 'Online'
            || ($booking['booking_status'] ?? '') !== 'Confirmed'
            || !in_array(($booking['payment_status'] ?? ''), ['Unpaid', 'Partial'], true)) {
            throw new RuntimeException('This Event Hall quotation is no longer available for acceptance.');
        }
        if ((int)($booking['quote_version'] ?? 0) !== (int)$quoteVersion || (int)($booking['quote_version'] ?? 0) < 1) {
            throw new RuntimeException('This quotation has changed. Refresh the booking details and review the latest version.');
        }
        $now = manual_payment_db_now($store->db);
        if (empty($booking['payment_due_at']) || $booking['payment_due_at'] <= $now
            || (string)$booking['start_date'] < substr($now, 0, 10)) {
            throw new RuntimeException('The Event Hall payment deadline has passed.');
        }
        if ((int)($booking['pending_change'] ?? 0) === 1) {
            throw new RuntimeException('Resolve the pending booking request before accepting this quotation.');
        }
        if ($store->row("SELECT id FROM pm_attempts WHERE booking_id=? AND status IN ('creating','open','uncertain','review') LIMIT 1 FOR UPDATE", [(int)$bookingId])
            || $store->row("SELECT r.payment_id FROM pm_receipts r JOIN pm_attempts a ON a.id=r.attempt_id WHERE a.booking_id=? AND r.disposition<>'applied' LIMIT 1 FOR UPDATE", [(int)$bookingId])) {
            throw new RuntimeException('A payment outcome needs reconciliation before accepting this quotation.');
        }
        if ((int)$booking['quote_accepted'] !== 1) {
            $store->query("UPDATE bookings SET quote_accepted=1 WHERE id=? AND quote_version=? AND quote_accepted=0 AND payment_due_at>NOW() AND booking_status='Confirmed' AND payment_status IN ('Unpaid','Partial')", [(int)$bookingId, (int)$quoteVersion]);
            if ($store->db->affected_rows !== 1) throw new RuntimeException('The quotation changed before it could be accepted. Refresh the booking details and try again.');
        }
        // Acceptance never changes payment_due_at: the staff-finalized quote
        // keeps its original 24-hour deadline, including safe checkout retries.
        return ['quote_version' => (int)$quoteVersion, 'payment_due_at' => (string)$booking['payment_due_at']];
    });
    echo json_encode(['success' => true, 'message' => 'Event Hall quotation accepted.', 'data' => $result], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $error instanceof RuntimeException ? $error->getMessage() : 'The Event Hall quotation could not be accepted.'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
