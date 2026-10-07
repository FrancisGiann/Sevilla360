<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/seminars.php';
require_once __DIR__ . '/../../includes/seminar_payments.php';

header('Content-Type: application/json; charset=UTF-8');
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$csrf)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
        exit;
    }
}

function seminar_payment_request_id(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new InvalidArgumentException('Choose a valid seminar.');
    return (int)$id;
}

function seminar_payment_json_body(): array
{
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) throw new InvalidArgumentException('Invalid request data.');
    return $data;
}

function seminar_payment_payload(mysqli $conn, int $seminarId): array
{
    return [
        'summary' => seminar_payment_summary($conn, $seminarId),
        'payments' => seminar_payment_history($conn, $seminarId),
        'methods' => seminar_payment_methods($conn),
    ];
}

function seminar_payment_assert_not_claimed_elsewhere(mysqli $conn, string $reference): void
{
    $methods = manual_payment_load_instructions($conn);
    $fingerprints = [];
    foreach ($methods as $method) {
        $label = trim((string)($method['name'] ?? ''));
        if ($label === '') continue;
        try { $fingerprints[] = manual_payment_reference_fingerprint($label, $reference); } catch (Throwable) { /* Ignore invalid legacy method labels. */ }
    }
    if (!$fingerprints) return;
    $fingerprints = array_values(array_unique($fingerprints));
    $placeholders = implode(',', array_fill(0, count($fingerprints), '?'));
    $types = str_repeat('s', count($fingerprints));
    $stmt = $conn->prepare("SELECT id FROM manual_payment_submissions WHERE reference_fingerprint IN ({$placeholders}) LIMIT 1 FOR UPDATE");
    if (!$stmt) throw new RuntimeException('Unable to check duplicate transaction references.');
    $params = [$types];
    foreach ($fingerprints as $index => $_fingerprint) $params[] = &$fingerprints[$index];
    call_user_func_array([$stmt, 'bind_param'], $params);
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to check duplicate transaction references.'); }
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($exists) throw new InvalidArgumentException('This transaction reference has already been submitted for a booking payment.');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $seminarId = seminar_payment_request_id($_GET['id'] ?? null);
        echo json_encode(['success' => true] + seminar_payment_payload($conn, $seminarId));
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $data = seminar_payment_json_body();
    $operation = (string)($data['op'] ?? '');
    $seminarId = seminar_payment_request_id($data['seminar_id'] ?? null);
    $userId = (int)$_SESSION['user_id'];

    if ($operation === 'record') {
        $amountCents = seminar_payment_amount_cents($data['amount'] ?? null);
        $requestedMethod = is_string($data['payment_method'] ?? null) ? trim($data['payment_method']) : '';
        $rawIdempotencyKey = $data['idempotency_key'] ?? '';
        if (!is_string($rawIdempotencyKey) || !preg_match('/\A[A-Za-z0-9_-]{16,100}\z/D', $rawIdempotencyKey)) {
            throw new InvalidArgumentException('Refresh the payment form and try again.');
        }
        $idempotencyKey = hash('sha256', $rawIdempotencyKey);
        $isCashRetry = strcasecmp($requestedMethod, 'Cash') === 0;
        [$reference, $referenceFingerprint] = seminar_payment_normalize_reference($data['transaction_reference'] ?? '', $isCashRetry ? 'Cash' : '');

        $conn->begin_transaction();
        try {
            $locked = seminar_payment_lock($conn, $seminarId);
            $priorStmt = $conn->prepare('SELECT id, seminar_id, amount, payment_method, transaction_reference, reference_fingerprint, created_by FROM seminar_payments WHERE idempotency_key=? FOR UPDATE');
            if (!$priorStmt) throw new RuntimeException('Unable to verify this payment request.');
            $priorStmt->bind_param('s', $idempotencyKey);
            if (!$priorStmt->execute()) { $priorStmt->close(); throw new RuntimeException('Unable to verify this payment request.'); }
            $prior = $priorStmt->get_result()->fetch_assoc();
            $priorStmt->close();
            if ($prior) {
                $same = (int)$prior['seminar_id'] === $seminarId && (int)$prior['created_by'] === $userId
                    && seminar_payment_cents_from_db($prior['amount']) === $amountCents
                    && strcasecmp((string)$prior['payment_method'], $requestedMethod) === 0
                    && (string)($prior['reference_fingerprint'] ?? '') === (string)($referenceFingerprint ?? '')
                    && ($isCashRetry ? (string)($prior['transaction_reference'] ?? '') === (string)($reference ?? '') : true);
                if (!$same) throw new InvalidArgumentException('This payment request key was already used for a different payment.');
                $conn->commit();
                echo json_encode(['success' => true, 'idempotent' => true] + seminar_payment_payload($conn, $seminarId));
                exit;
            }
            seminar_payment_assert_receivable($locked);
            $method = seminar_payment_validate_method($conn, $requestedMethod);
            [$reference, $referenceFingerprint] = seminar_payment_normalize_reference($data['transaction_reference'] ?? '', $method);
            if ($method !== 'Cash' && $reference !== null) seminar_payment_assert_not_claimed_elsewhere($conn, $reference);

            $priceCents = seminar_payment_cents_from_db($locked['agreed_price']);
            $paidCents = seminar_payment_paid_cents_locked($conn, $seminarId);
            seminar_payment_assert_amount_within_balance($amountCents, $priceCents, $paidCents);

            if ($referenceFingerprint !== null) {
                $duplicate = $conn->prepare('SELECT id FROM seminar_payments WHERE reference_fingerprint=? LIMIT 1 FOR UPDATE');
                if (!$duplicate) throw new RuntimeException('Unable to check duplicate transaction references.');
                $duplicate->bind_param('s', $referenceFingerprint);
                if (!$duplicate->execute()) { $duplicate->close(); throw new RuntimeException('Unable to check duplicate transaction references.'); }
                $alreadyRecorded = $duplicate->get_result()->num_rows > 0;
                $duplicate->close();
                if ($alreadyRecorded) throw new InvalidArgumentException('This transaction reference has already been recorded for a seminar payment.');
            }

            $amount = seminar_payment_money($amountCents);
            $stmt = $conn->prepare("INSERT INTO seminar_payments (seminar_id,amount,payment_method,transaction_reference,reference_fingerprint,idempotency_key,created_by) VALUES (?,?,?,?,?,?,?)");
            if (!$stmt) throw new RuntimeException('Unable to record the seminar payment.');
            $stmt->bind_param('isssssi', $seminarId, $amount, $method, $reference, $referenceFingerprint, $idempotencyKey, $userId);
            if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to record the seminar payment.'); }
            $paymentId = (int)$conn->insert_id;
            $stmt->close();
            seminar_write_audit($conn, $userId, 'Recorded seminar payment #' . $paymentId . ' for seminar #' . $seminarId . ': PHP ' . $amount . ' via ' . $method);
            $conn->commit();
            echo json_encode(['success' => true] + seminar_payment_payload($conn, $seminarId));
            exit;
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
    }

    if ($operation === 'void') {
        $paymentId = seminar_payment_request_id($data['payment_id'] ?? null);
        $reasonInput = $data['reason'] ?? null;
        if (!is_string($reasonInput) || preg_match('//u', $reasonInput) !== 1) throw new InvalidArgumentException('Enter a correction reason.');
        $reason = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $reasonInput) ?? '');
        if ($reason === '' || mb_strlen($reason, 'UTF-8') > 500) throw new InvalidArgumentException('Enter a correction reason between 1 and 500 characters.');

        $conn->begin_transaction();
        try {
            seminar_payment_lock($conn, $seminarId);
            $paymentStmt = $conn->prepare("SELECT amount,status FROM seminar_payments WHERE id=? AND seminar_id=? FOR UPDATE");
            if (!$paymentStmt) throw new RuntimeException('Unable to verify the seminar payment.');
            $paymentStmt->bind_param('ii', $paymentId, $seminarId);
            if (!$paymentStmt->execute()) { $paymentStmt->close(); throw new RuntimeException('Unable to verify the seminar payment.'); }
            $payment = $paymentStmt->get_result()->fetch_assoc();
            $paymentStmt->close();
            if (!$payment) throw new InvalidArgumentException('Seminar payment not found.');
            if ($payment['status'] !== 'posted') throw new InvalidArgumentException('This seminar payment has already been voided.');
            $voidStmt = $conn->prepare("UPDATE seminar_payments SET status='voided',reference_fingerprint=NULL,voided_by=?,voided_at=NOW(),void_reason=? WHERE id=? AND seminar_id=? AND status='posted'");
            if (!$voidStmt) throw new RuntimeException('Unable to correct the seminar payment.');
            $voidStmt->bind_param('isii', $userId, $reason, $paymentId, $seminarId);
            if (!$voidStmt->execute() || $voidStmt->affected_rows !== 1) { $voidStmt->close(); throw new InvalidArgumentException('This seminar payment changed. Reload the history and try again.'); }
            $voidStmt->close();
            seminar_write_audit($conn, $userId, 'Applied an accounting correction to seminar payment #' . $paymentId . ' for seminar #' . $seminarId . ': PHP ' . seminar_payment_money(seminar_payment_cents_from_db($payment['amount'])));
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
        echo json_encode(['success' => true] + seminar_payment_payload($conn, $seminarId));
        exit;
    }
    throw new InvalidArgumentException('Unknown seminar payment action.');
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
} catch (mysqli_sql_exception $error) {
    if ((int)$error->getCode() === 1062) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This transaction reference or payment request was already recorded. Reload the seminar payment history.']);
        exit;
    }
    error_log('Seminar payment operation failed: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to update seminar payments. Check that migration 031 has been applied.']);
} catch (Throwable $error) {
    error_log('Seminar payment operation failed: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to update seminar payments. Check that migration 031 has been applied.']);
}
