<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/seminar_payments.php';
require_once __DIR__ . '/../includes/seminars.php';
require_once __DIR__ . '/../includes/sales_report.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$throws = static function (callable $callable): bool {
    try { $callable(); return false; } catch (InvalidArgumentException) { return true; }
};
$insertPayment = static function (int $seminarId, string $amountValue, string $method, string $reference, int $userId) use ($conn): int {
    $amountCents = seminar_payment_amount_cents($amountValue);
    [$reference, $fingerprint] = seminar_payment_normalize_reference($reference, $method);
    $amount = seminar_payment_money($amountCents);
    $idempotencyKey = hash('sha256', random_bytes(32));
    $stmt = $conn->prepare('INSERT INTO seminar_payments (seminar_id,amount,payment_method,transaction_reference,reference_fingerprint,idempotency_key,created_by) VALUES (?,?,?,?,?,?,?)');
    $stmt->bind_param('isssssi', $seminarId, $amount, $method, $reference, $fingerprint, $idempotencyKey, $userId);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
};

$appDb = (string)$conn->query('SELECT DATABASE()')->fetch_row()[0];
$dbHost = (string)($_ENV['DB_HOST'] ?? 'localhost');
if ($appDb !== 'sevilla360' || !in_array($dbHost, ['localhost', '127.0.0.1', '::1'], true)) {
    throw new RuntimeException('Refusing rollback checks unless the configured target is the local sevilla360 database.');
}
$hall = $conn->query("SELECT id FROM venues WHERE category='Event Hall' ORDER BY id LIMIT 1")->fetch_assoc();
$actor = $conn->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetch_assoc();
if (!$hall || !$actor) throw new RuntimeException('Rollback test requires an existing Event Hall and user row.');
$hallId = (int)$hall['id'];
$userId = (int)$actor['id'];
$configured = seminar_payment_methods($conn);
$nonCashMethods = array_values(array_filter($configured, static fn(string $method): bool => strcasecmp($method, 'Cash') !== 0));
if (!$nonCashMethods) throw new RuntimeException('Rollback test requires at least one enabled non-cash method to verify references.');
$method = $nonCashMethods[0];
$suffix = strtoupper(bin2hex(random_bytes(8)));
$seminarId = 0;
$inTransaction = false;
$baselineReceived = sales_report_current_month_received_total($conn);

try {
    $conn->begin_transaction();
    $inTransaction = true;
    $seminarName = 'ROLLBACK PAYMENT TEST ' . $suffix;
    $price = '100.00';
    $start = '2099-11-10';
    $end = '2099-11-11';
    $checkIn = '2099-11-09';
    $checkOut = '2099-11-12';
    $seminarStmt = $conn->prepare("INSERT INTO seminars (name,agreed_price,status,hall_venue_id,hall_start_date,hall_end_date,hotel_check_in,hotel_check_out,created_by) VALUES (?,?,'draft',?,?,?,?,?,?)");
    $seminarStmt->bind_param('ssissssi', $seminarName, $price, $hallId, $start, $end, $checkIn, $checkOut, $userId);
    $seminarStmt->execute();
    $seminarId = (int)$conn->insert_id;
    $seminarStmt->close();

    $firstReference = 'SEMINAR-' . $suffix . '-01';
    $secondReference = 'SEMINAR-' . $suffix . '-02';
    $firstId = $insertPayment($seminarId, '35.25', $method, $firstReference, $userId);
    $secondId = $insertPayment($seminarId, '40.50', $method, $secondReference, $userId);
    $summary = seminar_payment_summary($conn, $seminarId);
    $assert($summary['paid_cents'] === 7575 && $summary['balance_cents'] === 2425, 'two partial payments did not reconcile to exact cents');
    $assert($throws(static fn() => seminar_payment_assert_amount_within_balance(2426, 10000, 7575)), 'overpayment was not rejected');

    [, $duplicateFingerprint] = seminar_payment_normalize_reference('seminar ' . $suffix . ' 01', $method);
    $duplicateKey = hash('sha256', random_bytes(32));
    $duplicateAmount = '1.00';
    $duplicateReference = 'seminar ' . $suffix . ' 01';
    $duplicateStmt = $conn->prepare('INSERT INTO seminar_payments (seminar_id,amount,payment_method,transaction_reference,reference_fingerprint,idempotency_key,created_by) VALUES (?,?,?,?,?,?,?)');
    $duplicateStmt->bind_param('isssssi', $seminarId, $duplicateAmount, $method, $duplicateReference, $duplicateFingerprint, $duplicateKey, $userId);
    $duplicateRejected = false;
    try { $duplicateStmt->execute(); } catch (mysqli_sql_exception $error) { $duplicateRejected = (int)$error->getCode() === 1062; }
    $duplicateStmt->close();
    $assert($duplicateRejected, 'database did not reject a normalized duplicate transaction reference');

    $voidReason = 'Correcting the amount entered for this external receipt.';
    $voidStmt = $conn->prepare("UPDATE seminar_payments SET status='voided',reference_fingerprint=NULL,voided_by=?,voided_at=NOW(),void_reason=? WHERE id=? AND seminar_id=? AND status='posted'");
    $voidStmt->bind_param('isii', $userId, $voidReason, $firstId, $seminarId);
    $voidStmt->execute();
    $assert($voidStmt->affected_rows === 1, 'payment correction did not preserve a voided receipt');
    $voidStmt->close();
    $replacementId = $insertPayment($seminarId, '34.25', $method, $firstReference, $userId);
    $summary = seminar_payment_summary($conn, $seminarId);
    $assert($summary['paid_cents'] === 7475 && $summary['balance_cents'] === 2525, 'voided receipt or corrected replacement was included incorrectly');
    $assert($throws(static fn() => seminar_payment_assert_price_floor($conn, $seminarId, '74.74')), 'agreed price floor allowed a reduction below net posted receipts');
    seminar_payment_assert_price_floor($conn, $seminarId, '74.75');

    $history = seminar_payment_history($conn, $seminarId);
    $byId = [];
    foreach ($history as $payment) $byId[(int)$payment['id']] = $payment;
    $assert(count($history) === 3 && ($byId[$firstId]['status'] ?? '') === 'voided'
        && ($byId[$firstId]['void_reason'] ?? '') === $voidReason
        && ($byId[$replacementId]['status'] ?? '') === 'posted', 'history did not retain the correction and replacement records');

    $statusStmt = $conn->prepare("UPDATE seminars SET status='cancelled' WHERE id=?");
    $statusStmt->bind_param('i', $seminarId);
    $statusStmt->execute();
    $statusStmt->close();
    $locked = seminar_payment_lock($conn, $seminarId);
    $assert($throws(static fn() => seminar_payment_assert_receivable($locked)), 'cancelled seminar accepted a new receipt');
    $summaryAfterCancel = seminar_payment_summary($conn, $seminarId);
    $assert($summaryAfterCancel['paid_cents'] === 7475, 'cancellation removed posted seminar payments');

    $today = (new DateTimeImmutable('now', sales_report_timezone()))->format('Y-m-d');
    $activities = sales_report_fetch_activities($conn, ['from' => $today, 'to' => $today, 'method' => '', 'venue_id' => 0], 0, 5000);
    $seminarActivities = array_values(array_filter($activities, static fn(array $activity): bool => ($activity['record_source'] ?? '') === 'seminar'
        && (int)($activity['activity_id'] ?? 0) === $replacementId));
    $targets = $seminarActivities ? sales_report_allocation_targets($seminarActivities[0], []) : [];
    $assert(count($seminarActivities) === 1 && count($targets) === 1 && $targets[0]['filter_id'] === $hallId,
        'sales report query did not include the posted replacement under its Event Hall only');
    $receivedAfter = sales_report_current_month_received_total($conn);
    $assert($receivedAfter - $baselineReceived === 7475, 'monthly received metric did not add the net posted seminar receipts');

    fwrite(STDOUT, "PASS MariaDB rollback integration: migration-backed ledger, partials, overpay, duplicate reference, correction/replacement, price floor, cancellation retention, history, and sales.\n");
} finally {
    if ($inTransaction) $conn->rollback();
}

if ($seminarId > 0) {
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM seminars WHERE id=?');
    $stmt->bind_param('i', $seminarId);
    $stmt->execute();
    $remaining = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    if ($remaining !== 0) throw new RuntimeException('Rollback integration left a test seminar row behind.');
}
