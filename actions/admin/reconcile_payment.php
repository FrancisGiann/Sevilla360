<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/paymongo.php';
require_once __DIR__ . '/../../includes/payment_service.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'staff'], true)) {
    http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
}
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403); echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']); exit;
}
$data = json_decode(file_get_contents('php://input'), true);
$booking_id = filter_var($data['booking_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$booking_id) { http_response_code(422); echo json_encode(['success' => false, 'message' => 'A valid booking is required.']); exit; }

try {
    $stmt = $conn->prepare("SELECT b.id, b.reference_no, b.total_amount, b.amount_paid, b.booking_status, b.payment_status, s.provider_session_id, s.amount AS checkout_amount, s.currency FROM bookings b JOIN booking_checkout_sessions s ON s.booking_id = b.id WHERE b.id = ? AND s.status IN ('creating','created','paid') ORDER BY s.id DESC LIMIT 1");
    if (!$stmt) throw new RuntimeException('Unable to load checkout session.');
    $stmt->bind_param('i', $booking_id); if (!$stmt->execute()) throw new RuntimeException('Unable to load checkout session.');
    $booking = $stmt->get_result()->fetch_assoc();
    if (!$booking || empty($booking['provider_session_id'])) throw new RuntimeException('No PayMongo checkout session is recorded for this booking.');
    if (in_array($booking['booking_status'], ['Cancelled', 'Completed'], true) || $booking['payment_status'] === 'Refunded') throw new RuntimeException('This booking is not eligible for reconciliation.');
    $remaining_due = max(0, (float)$booking['total_amount'] - (float)$booking['amount_paid']);
    if ((float)$booking['checkout_amount'] <= 0) throw new RuntimeException('The recorded checkout amount is invalid.');
    $checkout_exceeds_balance = (float)$booking['checkout_amount'] > $remaining_due + 0.01;

    $provider = paymongo_fetch_checkout_session((string)$booking['provider_session_id']);
    $attrs = $provider['attributes'] ?? [];
    $provider_status = strtolower((string)($attrs['status'] ?? ''));
    $raw_ref = (string)($attrs['reference_number'] ?? '');
    $ref = $raw_ref; if (str_contains($raw_ref, '_')) { $parts = explode('_', $raw_ref); $ref = (string)end($parts); }
    if (!hash_equals((string)$booking['reference_no'], $ref)) throw new RuntimeException('Provider reference does not match this booking.');
    $payments = $attrs['payments'] ?? [];
    $payment = [];
    foreach ($payments as $candidate) {
        if (strtolower((string)($candidate['attributes']['status'] ?? '')) === 'paid') {
            $payment = $candidate;
            break;
        }
    }
    if (!$payment) {
        // `expired` is the only terminal Checkout Session status. Persist it
        // only when PayMongo did not return a paid payment entry; a completed
        // payment may still be reported on an expired session.
        if ($provider_status === 'expired') {
            $terminal = $conn->prepare("UPDATE booking_checkout_sessions SET status = 'expired', provider_status = ? WHERE booking_id = ? AND provider_session_id = ?");
            if (!$terminal) throw new RuntimeException('Unable to record the expired checkout session.');
            $terminal_status = $provider_status;
            $terminal->bind_param('sis', $terminal_status, $booking_id, $booking['provider_session_id']);
            if (!$terminal->execute()) throw new RuntimeException('Unable to record the expired checkout session.');
        }
        throw new RuntimeException('PayMongo has not returned a paid payment entry for this checkout session.');
    }
    // Checkout Session status is active/expired. A paid payment entry is the
    // authoritative success signal and remains valid even if the session is
    // later reported expired. Only expiry without a paid entry is terminal.
    if ($provider_status === 'expired') {
        // Continue with the paid entry; credit_verified_payment remains the
        // idempotent source of truth for the booking/payment transaction.
    } elseif ($provider_status !== 'active') {
        throw new RuntimeException('PayMongo returned an unsupported checkout session status.');
    }
    $payment_attrs = $payment['attributes'] ?? [];
    $transaction_id = (string)($payment['id'] ?? ($attrs['payment_intent']['id'] ?? ''));
    $amount_cents = (int)($payment_attrs['amount'] ?? ($attrs['line_items'][0]['amount'] ?? 0));
    $currency = strtoupper((string)($payment_attrs['currency'] ?? ($attrs['line_items'][0]['currency'] ?? $booking['currency'])));
    $amount = $amount_cents / 100;
    if ($transaction_id === '' || $amount <= 0 || $currency !== 'PHP') throw new RuntimeException('Provider payment details are incomplete or unsupported.');
    if (abs($amount - (float)$booking['checkout_amount']) > 0.01) throw new RuntimeException('Provider amount does not match the recorded checkout amount.');
    if ($checkout_exceeds_balance) {
        // A retry after a webhook may observe a zero balance. Permit only the
        // exact already-recorded transaction through to the shared idempotent
        // credit path; a new overpayment remains rejected.
        $duplicate = $conn->prepare("SELECT id FROM payments WHERE booking_id = ? AND transaction_id = ? AND status = 'Success' LIMIT 1");
        if (!$duplicate) throw new RuntimeException('Unable to validate the existing payment transaction.');
        $duplicate->bind_param('is', $booking_id, $transaction_id);
        if (!$duplicate->execute()) throw new RuntimeException('Unable to validate the existing payment transaction.');
        if ($duplicate->get_result()->num_rows === 0) throw new RuntimeException('The recorded checkout amount exceeds the current booking balance.');
    }
    $method = strtolower((string)($attrs['payment_method_used'] ?? ''));
    $payment_method = str_contains($method, 'gcash') ? 'GCash' : (str_contains($method, 'paymaya') ? 'Maya' : 'PayMongo');
    $result = credit_verified_payment($conn, (string)$booking['reference_no'], $amount, $transaction_id, $payment_method, (string)$booking['provider_session_id'], $currency);
    echo json_encode(['success' => true, 'duplicate' => $result['duplicate'], 'message' => $result['duplicate'] ? 'Payment was already credited.' : 'Verified payment credited.', 'payment_status' => $result['status'], 'amount_paid' => $result['amount_paid']]);
} catch (Throwable $e) {
    http_response_code(422); echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
