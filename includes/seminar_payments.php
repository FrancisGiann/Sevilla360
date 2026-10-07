<?php
declare(strict_types=1);

require_once __DIR__ . '/manual_payment.php';

/** Parse a decimal money value into exact integer cents. */
function seminar_payment_amount_cents(mixed $value, bool $requirePositive = true): int
{
    if (!is_string($value) && !is_int($value)) {
        throw new InvalidArgumentException('Enter an amount with up to 10 whole digits and 2 decimal places.');
    }
    $amount = trim((string)$value);
    if (!preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/D', $amount)) {
        throw new InvalidArgumentException('Enter an amount with up to 10 whole digits and 2 decimal places.');
    }
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    $whole = ltrim($whole, '0');
    $whole = $whole === '' ? '0' : $whole;
    if (strlen($whole) > 10) throw new InvalidArgumentException('The amount exceeds the allowed maximum.');
    $cents = ((int)$whole * 100) + (int)str_pad($fraction, 2, '0');
    if ($requirePositive && $cents < 1) throw new InvalidArgumentException('Payment amount must be greater than zero.');
    return $cents;
}

function seminar_payment_money(int $cents): string
{
    return intdiv(max(0, $cents), 100) . '.' . str_pad((string)(max(0, $cents) % 100), 2, '0', STR_PAD_LEFT);
}

function seminar_payment_cents_from_db($value): int
{
    if ($value === null || $value === '') return 0;
    return seminar_payment_amount_cents((string)$value, false);
}

function seminar_payment_methods(mysqli $conn): array
{
    $methods = ['Cash' => 'Cash'];
    foreach (manual_payment_enabled_methods($conn) as $method) {
        $name = trim((string)($method['name'] ?? ''));
        if ($name !== '' && strcasecmp($name, 'Cash') !== 0) {
            $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
            $methods[$key] = $name;
        }
    }
    return array_values($methods);
}

function seminar_payment_validate_method(mysqli $conn, mixed $input): string
{
    if (!is_string($input)) throw new InvalidArgumentException('Choose a configured payment method.');
    $value = trim($input);
    foreach (seminar_payment_methods($conn) as $method) {
        if (strcasecmp($value, $method) === 0) return $method;
    }
    throw new InvalidArgumentException('Choose Cash or an enabled payment method from Settings.');
}

function seminar_payment_normalize_reference(mixed $input, string $method): array
{
    if ($method === 'Cash') {
        if ($input !== null && $input !== '' && !is_string($input)) throw new InvalidArgumentException('Reference must be text.');
        $reference = trim((string)$input);
        if (strlen($reference) > 64 || preg_match('/[\x00-\x1F\x7F]/', $reference)) throw new InvalidArgumentException('Cash reference must be 64 characters or fewer.');
        return [$reference === '' ? null : $reference, null];
    }
    if (!is_string($input)) throw new InvalidArgumentException('Enter the transaction reference for this payment.');
    $reference = trim($input);
    try {
        $normalized = manual_payment_validate_reference($reference);
    } catch (Throwable $error) {
        throw new InvalidArgumentException('Enter a 4–64 character transaction reference using letters, numbers, spaces, dots, underscores, slashes, or hyphens.');
    }
    return [$reference, hash('sha256', $normalized)];
}

function seminar_payment_summary(mysqli $conn, int $seminarId): array
{
    $stmt = $conn->prepare("SELECT s.agreed_price, s.status, COALESCE(SUM(CASE WHEN p.status='posted' THEN p.amount ELSE 0 END),0) AS paid
        FROM seminars s LEFT JOIN seminar_payments p ON p.seminar_id=s.id WHERE s.id=? GROUP BY s.id");
    if (!$stmt) throw new RuntimeException('Unable to load seminar payment totals.');
    $stmt->bind_param('i', $seminarId);
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to load seminar payment totals.'); }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new InvalidArgumentException('Seminar not found.');
    $priceCents = $row['agreed_price'] === null ? null : seminar_payment_cents_from_db($row['agreed_price']);
    $paidCents = seminar_payment_cents_from_db($row['paid']);
    $balanceCents = $priceCents === null ? null : max(0, $priceCents - $paidCents);
    return [
        'agreed_price' => $row['agreed_price'] === null ? null : seminar_payment_money((int)$priceCents),
        'paid' => seminar_payment_money($paidCents),
        'balance' => $balanceCents === null ? null : seminar_payment_money($balanceCents),
        'agreed_price_cents' => $priceCents,
        'paid_cents' => $paidCents,
        'balance_cents' => $balanceCents,
        'status' => (string)$row['status'],
    ];
}

function seminar_payment_history(mysqli $conn, int $seminarId): array
{
    $stmt = $conn->prepare("SELECT p.id, p.amount, p.payment_method, p.transaction_reference, p.status, p.created_at,
            p.void_reason, p.voided_at, COALESCE(st.full_name, '') AS voided_by_name
        FROM seminar_payments p LEFT JOIN staff st ON st.user_id=p.voided_by
        WHERE p.seminar_id=? ORDER BY p.created_at DESC, p.id DESC");
    if (!$stmt) throw new RuntimeException('Unable to load seminar payment history.');
    $stmt->bind_param('i', $seminarId);
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to load seminar payment history.'); }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) $row['amount'] = seminar_payment_money(seminar_payment_cents_from_db($row['amount']));
    unset($row);
    return $rows;
}

function seminar_payment_lock(mysqli $conn, int $seminarId): array
{
    $stmt = $conn->prepare('SELECT id, agreed_price, status FROM seminars WHERE id=? FOR UPDATE');
    if (!$stmt) throw new RuntimeException('Unable to lock seminar payment record.');
    $stmt->bind_param('i', $seminarId);
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to lock seminar payment record.'); }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new InvalidArgumentException('Seminar not found.');
    return $row;
}

function seminar_payment_paid_cents_locked(mysqli $conn, int $seminarId): int
{
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS paid FROM seminar_payments WHERE seminar_id=? AND status='posted'");
    if (!$stmt) throw new RuntimeException('Unable to check the seminar balance.');
    $stmt->bind_param('i', $seminarId);
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to check the seminar balance.'); }
    $paid = $stmt->get_result()->fetch_assoc()['paid'] ?? '0';
    $stmt->close();
    return seminar_payment_cents_from_db($paid);
}

function seminar_payment_assert_receivable(array $seminar): void
{
    if (($seminar['status'] ?? '') === 'cancelled') throw new InvalidArgumentException('Payments cannot be added to a cancelled seminar.');
    if (($seminar['agreed_price'] ?? null) === null) throw new InvalidArgumentException('Set the agreed seminar price before recording a payment.');
}

function seminar_payment_assert_amount_within_balance(int $amountCents, int $priceCents, int $paidCents): void
{
    if ($amountCents > $priceCents - $paidCents) throw new InvalidArgumentException('Payment amount exceeds the remaining seminar balance.');
}

function seminar_payment_assert_price_floor(mysqli $conn, int $seminarId, ?string $price): void
{
    $paidCents = seminar_payment_paid_cents_locked($conn, $seminarId);
    $priceCents = $price === null ? null : seminar_payment_cents_from_db($price);
    if ($priceCents === null && $paidCents > 0) throw new InvalidArgumentException('The agreed price cannot be cleared while seminar payments are posted.');
    if ($priceCents !== null && $priceCents < $paidCents) throw new InvalidArgumentException('Agreed price cannot be lower than posted seminar payments. Void or correct payments first.');
}
