<?php
/** Shared, idempotent payment crediting path for webhooks and reconciliation. */
function credit_verified_payment(mysqli $conn, string $reference_no, float $amount_paid, string $transaction_id, string $payment_method = 'PayMongo', ?string $provider_session_id = null, string $currency = 'PHP'): array {
    if ($reference_no === '' || $amount_paid <= 0 || $transaction_id === '' || $currency !== 'PHP') throw new RuntimeException('Payment payload is invalid.');
    if (!$conn->begin_transaction()) throw new RuntimeException('Unable to start the payment transaction.');
    try {
        $stmt = $conn->prepare("SELECT id, total_amount, amount_paid, booking_status, payment_status FROM bookings WHERE reference_no = ? FOR UPDATE");
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
        if (!$conn->commit()) throw new RuntimeException('Unable to commit the payment transaction.');
        return ['duplicate' => false, 'status' => $status, 'amount_paid' => $new_amount, 'booking_id' => $id];
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}
?>
