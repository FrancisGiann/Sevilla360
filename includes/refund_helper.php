<?php
function calculate_refund_breakdown(float $amountPaid): array {
    $amountPaid = max(0.0, round($amountPaid, 2));
    return ['fee_percent' => 0.0, 'fee' => 0.0, 'refund' => $amountPaid];
}

/** Validate and normalize the customer-selected destination for a paid refund. */
function normalize_refund_destination(array $input): array {
    $methodValue = $input['method'] ?? null;
    $method = is_string($methodValue) ? trim($methodValue) : '';
    $allowedMethods = ['GCash', 'Maya', 'Bank Transfer'];
    if (!in_array($method, $allowedMethods, true)) {
        throw new InvalidArgumentException('Choose GCash, Maya, or Bank Transfer as the refund destination.');
    }

    $accountName = normalize_refund_destination_text($input['account_name'] ?? '', 2, 120, 'Enter the account holder name (2–120 characters).');
    $identifierValue = $input['account_identifier'] ?? null;
    $rawIdentifier = is_string($identifierValue) ? trim($identifierValue) : '';
    if ($rawIdentifier === '' || preg_match('/[\x00-\x1F\x7F]/', $rawIdentifier)) {
        throw new InvalidArgumentException('Enter a valid wallet, mobile, or account number.');
    }

    if ($method === 'Bank Transfer') {
        $identifier = preg_replace('/\s+/', ' ', $rawIdentifier);
        if (!is_string($identifier) || strlen($identifier) < 4 || strlen($identifier) > 64
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9 -]*\z/D', $identifier)) {
            throw new InvalidArgumentException('Enter a valid bank account number using letters, numbers, spaces, or hyphens (4–64 characters).');
        }
        $bankName = normalize_refund_destination_text($input['bank_name'] ?? '', 2, 100, 'Enter the bank name (2–100 characters).');
    } else {
        $identifier = normalize_refund_wallet_identifier($rawIdentifier);
        $bankName = null;
    }

    return [
        'method' => $method,
        'account_name' => $accountName,
        'account_identifier' => $identifier,
        'bank_name' => $bankName,
    ];
}

function normalize_refund_destination_text($value, int $minimum, int $maximum, string $errorMessage): string {
    if (!is_string($value)) throw new InvalidArgumentException($errorMessage);
    $text = trim($value);
    if ($text === '' || preg_match('/[\x00-\x1F\x7F]/', $text) || preg_match('//u', $text) !== 1) {
        throw new InvalidArgumentException($errorMessage);
    }
    $text = preg_replace('/\s+/u', ' ', $text);
    if (!is_string($text)) throw new InvalidArgumentException($errorMessage);
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length < $minimum || $length > $maximum) throw new InvalidArgumentException($errorMessage);
    return $text;
}

function normalize_refund_wallet_identifier(string $value): string {
    $compact = preg_replace('/[\s-]+/', '', trim($value));
    if (!is_string($compact)) throw new InvalidArgumentException('Enter a valid Philippine mobile number for the selected e-wallet.');

    if (preg_match('/\A09[0-9]{9}\z/D', $compact)) {
        $digits = '63' . substr($compact, 1);
    } elseif (preg_match('/\A9[0-9]{9}\z/D', $compact)) {
        $digits = '63' . $compact;
    } elseif (preg_match('/\A639[0-9]{9}\z/D', $compact)) {
        $digits = $compact;
    } elseif (preg_match('/\A\+639[0-9]{9}\z/D', $compact)) {
        $digits = substr($compact, 1);
    } else {
        throw new InvalidArgumentException('Enter a valid Philippine mobile number, such as 09XX XXX XXXX or +639XX XXX XXXX.');
    }

    return '+' . $digits;
}

function refund_destination_is_valid(array $destination): bool {
    try {
        normalize_refund_destination([
            'method' => (string)($destination['refund_destination_method'] ?? $destination['method'] ?? ''),
            'account_name' => (string)($destination['refund_destination_account_name'] ?? $destination['account_name'] ?? ''),
            'account_identifier' => (string)($destination['refund_destination_account_identifier'] ?? $destination['account_identifier'] ?? ''),
            'bank_name' => (string)($destination['refund_destination_bank_name'] ?? $destination['bank_name'] ?? ''),
        ]);
        return true;
    } catch (InvalidArgumentException $error) {
        return false;
    }
}

function mask_refund_destination_identifier(?string $identifier): ?string {
    if ($identifier === null || trim($identifier) === '') return null;
    $visible = preg_replace('/[^A-Za-z0-9]/', '', $identifier);
    if (!is_string($visible) || $visible === '') return null;
    return '•••• ' . substr($visible, -4);
}

function refund_destination_for_customer(array $destination): array {
    return [
        'method' => $destination['refund_destination_method'] ?? null,
        'account_name' => $destination['refund_destination_account_name'] ?? null,
        'masked_identifier' => mask_refund_destination_identifier(isset($destination['refund_destination_account_identifier']) ? (string)$destination['refund_destination_account_identifier'] : null),
        'bank_name' => $destination['refund_destination_bank_name'] ?? null,
    ];
}

function record_cancellation_history(mysqli $conn, int $bookingId, ?int $cancellationId, string $action, string $reason, float $refundAmount, float $feeDeducted, float $feePercent, ?string $adminReply, ?int $actorUserId): void {
    $allowed = ['requested', 'reopened', 'rejected', 'processed', 'cancelled'];
    if (!in_array($action, $allowed, true)) throw new RuntimeException('Invalid cancellation history action.');
    $stmt = $conn->prepare('INSERT INTO cancellation_history (booking_id, cancellation_id, action, reason, refund_amount, fee_deducted, fee_percent, admin_reply, actor_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) throw new RuntimeException('Unable to record cancellation history.');
    $stmt->bind_param('iissdddsi', $bookingId, $cancellationId, $action, $reason, $refundAmount, $feeDeducted, $feePercent, $adminReply, $actorUserId);
    if (!$stmt->execute()) throw new RuntimeException('Unable to record cancellation history.');
}
?>
