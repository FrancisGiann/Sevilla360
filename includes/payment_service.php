<?php
/** Shared, idempotent payment crediting path for webhooks and reconciliation. */
require_once __DIR__ . '/realtime.php';
function credit_verified_payment(mysqli $conn, string $reference_no, float $amount_paid, string $transaction_id, string $payment_method = 'PayMongo', ?string $provider_session_id = null, string $currency = 'PHP'): array {
    if ($reference_no === '' || $amount_paid <= 0 || $transaction_id === '' || $currency !== 'PHP') throw new RuntimeException('Payment payload is invalid.');
    if (!$conn->begin_transaction()) throw new RuntimeException('Unable to start the payment transaction.');
    try {
        $stmt = $conn->prepare("SELECT b.id, b.total_amount, b.amount_paid, b.booking_status, b.payment_status, c.user_id FROM bookings b LEFT JOIN customers c ON c.id = b.customer_id WHERE b.reference_no = ? FOR UPDATE");
        if (!$stmt) throw new RuntimeException('Unable to load the booking for payment.');
        $stmt->bind_param('s', $reference_no); if (!$stmt->execute()) throw new RuntimeException('Unable to load the booking for payment.');
        $booking = $stmt->get_result()->fetch_assoc(); if (!$booking) throw new RuntimeException('Booking reference was not found.');
        if ($provider_session_id !== null) {
            $stmt = $conn->prepare("SELECT booking_id, amount, currency FROM booking_checkout_sessions WHERE provider_session_id = ? LIMIT 1");
            if (!$stmt) throw new RuntimeException('Unable to validate the checkout session.');
            $stmt->bind_param('s', $provider_session_id); if (!$stmt->execute()) throw new RuntimeException('Unable to validate the checkout session.');
            $checkout = $stmt->get_result()->fetch_assoc();
            if ($checkout) {
                if ((int)$checkout['booking_id'] !== (int)$booking['id'] || strtoupper((string)$checkout['currency']) !== $currency || abs((float)$checkout['amount'] - $amount_paid) > 0.01) throw new RuntimeException('Payment does not match the recorded checkout session.');
            } else {
                // Legacy sessions created before migration 009 have no row.
                // Adopt one only when this booking has no persisted checkout
                // sessions; a provider-ID mismatch on a newer booking fails.
                $stmt = $conn->prepare('SELECT id FROM booking_checkout_sessions WHERE booking_id = ? LIMIT 1');
                if (!$stmt) throw new RuntimeException('Unable to validate the booking checkout history.');
                $booking_id = (int)$booking['id']; $stmt->bind_param('i', $booking_id);
                if (!$stmt->execute()) throw new RuntimeException('Unable to validate the booking checkout history.');
                if ($stmt->get_result()->num_rows > 0) throw new RuntimeException('Payment provider session does not belong to this booking.');
                $legacy_key = 'legacy:' . $provider_session_id;
                $legacy_meta = json_encode(['reference_number' => $reference_no, 'amount' => $amount_paid, 'currency' => $currency, 'legacy' => true], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                $stmt = $conn->prepare("INSERT INTO booking_checkout_sessions (booking_id, checkout_key, provider_session_id, amount, currency, status, provider_status, metadata_json) VALUES (?, ?, ?, ?, ?, 'paid', 'paid', ?)");
                if (!$stmt) throw new RuntimeException('Unable to adopt the legacy checkout session.');
                $stmt->bind_param('issdss', $booking_id, $legacy_key, $provider_session_id, $amount_paid, $currency, $legacy_meta);
                if (!$stmt->execute()) throw new RuntimeException('Unable to adopt the legacy checkout session.');
            }
        }
        if ($stmt = $conn->prepare('SELECT id, booking_id FROM payments WHERE transaction_id = ? LIMIT 1')) {
            $stmt->bind_param('s', $transaction_id); if (!$stmt->execute()) throw new RuntimeException('Unable to check payment idempotency.');
            $existing_payment = $stmt->get_result()->fetch_assoc();
            if ($existing_payment) {
                if ((int)$existing_payment['booking_id'] !== (int)$booking['id']) throw new RuntimeException('Payment transaction is already attached to another booking.');
                if ($provider_session_id !== null) {
                    $stmt = $conn->prepare("UPDATE booking_checkout_sessions SET status = 'paid', provider_status = 'paid' WHERE provider_session_id = ?");
                    if (!$stmt) throw new RuntimeException('Unable to reconcile the checkout session.');
                    $stmt->bind_param('s', $provider_session_id); if (!$stmt->execute()) throw new RuntimeException('Unable to reconcile the checkout session.');
                }
                if (!$conn->commit()) throw new RuntimeException('Unable to commit the duplicate payment result.');
                return ['duplicate' => true, 'status' => $booking['payment_status'], 'amount_paid' => (float)$booking['amount_paid']];
            }
        } else throw new RuntimeException('Unable to check payment idempotency.');
        if (in_array($booking['booking_status'], ['Cancelled', 'Completed'], true) || $booking['payment_status'] === 'Refunded') throw new RuntimeException('This booking is no longer eligible for payment.');
        $remaining = max(0, (float)$booking['total_amount'] - (float)$booking['amount_paid']);
        if ($remaining <= 0 || $amount_paid > $remaining + 0.01) throw new RuntimeException('Payment exceeds the booking balance and was not credited.');
        $new_amount = (float)$booking['amount_paid'] + $amount_paid; $status = $new_amount >= (float)$booking['total_amount'] - 0.01 ? 'Paid' : 'Partial';
        $stmt = $conn->prepare("INSERT INTO payments (booking_id, transaction_id, payment_method, amount, status) VALUES (?, ?, ?, ?, 'Success')");
        if (!$stmt) throw new RuntimeException('Unable to prepare the payment record.');
        $id = (int)$booking['id']; $stmt->bind_param('issd', $id, $transaction_id, $payment_method, $amount_paid); if (!$stmt->execute()) throw new RuntimeException('Unable to record the payment.');
        $stmt = $conn->prepare("UPDATE bookings SET booking_status = 'Confirmed', payment_status = ?, amount_paid = ? WHERE id = ?");
        if (!$stmt) throw new RuntimeException('Unable to prepare the booking payment update.');
        $stmt->bind_param('sdi', $status, $new_amount, $id); if (!$stmt->execute()) throw new RuntimeException('Unable to update the booking payment status.');
        if ($provider_session_id !== null) {
            $stmt = $conn->prepare("UPDATE booking_checkout_sessions SET status = 'paid', provider_status = 'paid' WHERE provider_session_id = ?");
            if (!$stmt) throw new RuntimeException('Unable to reconcile the checkout session.');
            $stmt->bind_param('s', $provider_session_id); if (!$stmt->execute()) throw new RuntimeException('Unable to reconcile the checkout session.');
        }
        $action = 'Received ₱' . number_format($amount_paid, 2) . " via $payment_method for Booking $reference_no";
        $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (NULL, 'PayMongo Payment', ?, 'PayMongo Server')");
        if (!$stmt) throw new RuntimeException('Unable to prepare the payment audit entry.');
        $stmt->bind_param('s', $action); if (!$stmt->execute()) throw new RuntimeException('Unable to record the payment audit entry.');
        realtime_enqueue_event($conn, 'admin', 'payment.received', [
            'booking_id' => $id,
            'reference_no' => $reference_no,
            'payment_status' => $status,
            'amount_paid' => $new_amount,
        ]);
        $customer_user_id = (int)($booking['user_id'] ?? 0);
        if ($customer_user_id > 0) {
            realtime_enqueue_event($conn, 'customer:' . $customer_user_id, 'payment.received', [
                'booking_id' => $id,
                'reference_no' => $reference_no,
                'payment_status' => $status,
                'amount_paid' => $new_amount,
            ]);
        }
        if (!$conn->commit()) throw new RuntimeException('Unable to commit the payment transaction.');
        return ['duplicate' => false, 'status' => $status, 'amount_paid' => $new_amount, 'booking_id' => $id];
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}

/** Verify one locally recorded checkout session, then use the shared locked/idempotent credit path. */
function reconcile_payment_for_booking(mysqli $conn, int $booking_id): array {
    $stmt = $conn->prepare("SELECT b.id, b.reference_no, b.total_amount, b.amount_paid, b.booking_status, b.payment_status, s.provider_session_id, s.amount AS checkout_amount, s.currency FROM bookings b JOIN booking_checkout_sessions s ON s.booking_id = b.id WHERE b.id = ? AND s.status IN ('creating','created','paid') ORDER BY s.id DESC LIMIT 1");
    if (!$stmt) throw new RuntimeException('Unable to load checkout session.');
    $stmt->bind_param('i', $booking_id);
    if (!$stmt->execute()) throw new RuntimeException('Unable to load checkout session.');
    $booking = $stmt->get_result()->fetch_assoc();
    if (!$booking || empty($booking['provider_session_id'])) throw new RuntimeException('No PayMongo checkout session is recorded for this booking.');
    if (in_array($booking['booking_status'], ['Cancelled', 'Completed'], true) || $booking['payment_status'] === 'Refunded') throw new RuntimeException('This booking is not eligible for reconciliation.');
    $remaining_due = max(0.0, (float)$booking['total_amount'] - (float)$booking['amount_paid']);
    if ((float)$booking['checkout_amount'] <= 0 || strtoupper((string)$booking['currency']) !== 'PHP') throw new RuntimeException('The recorded checkout amount is invalid.');
    $checkout_exceeds_balance = (float)$booking['checkout_amount'] > $remaining_due + 0.01;
    $provider = paymongo_fetch_checkout_session((string)$booking['provider_session_id']);
    $attrs = $provider['attributes'] ?? [];
    $provider_status = strtolower((string)($attrs['status'] ?? ''));
    $raw_ref = (string)($attrs['reference_number'] ?? '');
    $ref = $raw_ref;
    if (str_contains($raw_ref, '_')) { $reference_parts = explode('_', $raw_ref); $ref = (string)end($reference_parts); }
    if (!hash_equals((string)$booking['reference_no'], $ref)) throw new RuntimeException('Provider reference does not match this booking.');
    $payment = [];
    foreach (($attrs['payments'] ?? []) as $candidate) if (strtolower((string)($candidate['attributes']['status'] ?? '')) === 'paid') { $payment = $candidate; break; }
    if (!$payment) {
        if ($provider_status === 'expired') {
            $terminal = $conn->prepare("UPDATE booking_checkout_sessions SET status = 'expired', provider_status = ? WHERE booking_id = ? AND provider_session_id = ?");
            if (!$terminal) throw new RuntimeException('Unable to record the expired checkout session.');
            $terminal->bind_param('sis', $provider_status, $booking_id, $booking['provider_session_id']);
            $terminal->execute();
        }
        throw new RuntimeException('PayMongo has not returned a paid payment entry for this checkout session.');
    }
    if ($provider_status !== 'active' && $provider_status !== 'expired') throw new RuntimeException('PayMongo returned an unsupported checkout session status.');
    $payment_attrs = $payment['attributes'] ?? [];
    $transaction_id = (string)($payment['id'] ?? ($attrs['payment_intent']['id'] ?? ''));
    $amount = ((int)($payment_attrs['amount'] ?? ($attrs['line_items'][0]['amount'] ?? 0))) / 100;
    $currency = strtoupper((string)($payment_attrs['currency'] ?? ($attrs['line_items'][0]['currency'] ?? $booking['currency'])));
    if ($transaction_id === '' || $amount <= 0 || $currency !== 'PHP') throw new RuntimeException('Provider payment details are incomplete or unsupported.');
    if (abs($amount - (float)$booking['checkout_amount']) > 0.01) throw new RuntimeException('Provider amount does not match the recorded checkout amount.');
    if ($checkout_exceeds_balance) {
        $duplicate = $conn->prepare("SELECT id FROM payments WHERE booking_id = ? AND transaction_id = ? AND status = 'Success' LIMIT 1");
        $duplicate->bind_param('is', $booking_id, $transaction_id); $duplicate->execute();
        if ($duplicate->get_result()->num_rows === 0) throw new RuntimeException('The recorded checkout amount exceeds the current booking balance.');
    }
    $method = strtolower((string)($attrs['payment_method_used'] ?? ''));
    $payment_method = str_contains($method, 'gcash') ? 'GCash' : (str_contains($method, 'paymaya') ? 'Maya' : 'PayMongo');
    return credit_verified_payment($conn, (string)$booking['reference_no'], $amount, $transaction_id, $payment_method, (string)$booking['provider_session_id'], $currency);
}
?>
