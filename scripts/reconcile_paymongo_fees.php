<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$apply = false;
$after = 0;
$afterSpecified = false;
$scheduled = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') {
        fwrite(STDOUT, "Usage: php scripts/reconcile_paymongo_fees.php [--after=PAYMENT_ROW_ID] [--apply] [--scheduled]\n");
        fwrite(STDOUT, "Checks up to 25 missing fees manually, or up to 10 in scheduled mode. Without --apply, manual runs do not update rows.\n");
        fwrite(STDOUT, "--scheduled implies --apply, uses a rotating cursor, and cannot be combined with --after.\n");
        exit(0);
    } elseif ($argument === '--apply') {
        $apply = true;
    } elseif ($argument === '--scheduled') {
        $scheduled = true;
    } elseif (preg_match('/\A--after=(0|[1-9][0-9]{0,9})\z/D', $argument, $match)) {
        $after = (int)$match[1];
        $afterSpecified = true;
    } else {
        fwrite(STDERR, "Usage: php scripts/reconcile_paymongo_fees.php [--after=PAYMENT_ROW_ID] [--apply] [--scheduled]\n");
        fwrite(STDERR, "Checks up to 25 missing fees manually, or up to 10 in scheduled mode. Without --apply, manual runs do not update rows.\n");
        fwrite(STDERR, "--scheduled implies --apply, uses a rotating cursor, and cannot be combined with --after.\n");
        exit(2);
    }
}
if ($scheduled && $afterSpecified) {
    fwrite(STDERR, "--after cannot be combined with --scheduled.\n");
    exit(2);
}
if ($scheduled) $apply = true;

function paymongo_fee_schedule_lock()
{
    $path = database_backup_directory(false) . DIRECTORY_SEPARATOR . 'paymongo-fees-scheduled.lock';
    if (is_link($path) || (file_exists($path) && !is_file($path))) {
        throw new RuntimeException('Scheduled reconciliation lock is invalid.');
    }
    $stream = @fopen($path, 'c+b');
    if ($stream === false) throw new RuntimeException('Scheduled reconciliation lock is unavailable.');
    @chmod($path, 0600);
    if (!flock($stream, LOCK_EX | LOCK_NB)) {
        fclose($stream);
        return false;
    }
    return $stream;
}

function paymongo_fee_schedule_cursor_read(): int
{
    $path = database_backup_directory(false) . DIRECTORY_SEPARATOR . 'paymongo-fees-scheduled.json';
    if (is_link($path)) throw new RuntimeException('Scheduled reconciliation cursor is invalid.');
    if (!file_exists($path)) return 0;
    if (!is_file($path)) throw new RuntimeException('Scheduled reconciliation cursor is invalid.');
    $raw = file_get_contents($path);
    if (!is_string($raw) || strlen($raw) > 256) throw new RuntimeException('Scheduled reconciliation cursor is unreadable.');
    $state = json_decode($raw, true);
    if (!is_array($state) || !is_int($state['cursor'] ?? null) || $state['cursor'] < 0) {
        throw new RuntimeException('Scheduled reconciliation cursor is invalid.');
    }
    return $state['cursor'];
}

function paymongo_fee_schedule_cursor_write(int $cursor): void
{
    if ($cursor < 0) throw new InvalidArgumentException('Invalid scheduled reconciliation cursor.');
    $directory = database_backup_directory(false);
    $path = $directory . DIRECTORY_SEPARATOR . 'paymongo-fees-scheduled.json';
    if (is_link($path) || (file_exists($path) && !is_file($path))) {
        throw new RuntimeException('Scheduled reconciliation cursor is invalid.');
    }

    $contents = json_encode(['cursor' => $cursor, 'updated_at' => gmdate('c')], JSON_THROW_ON_ERROR) . "\n";
    $temporary = null;
    $stream = false;
    try {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $directory . DIRECTORY_SEPARATOR . '.paymongo-fees-' . bin2hex(random_bytes(8)) . '.tmp';
            $stream = @fopen($candidate, 'x+b');
            if ($stream !== false) {
                $temporary = $candidate;
                break;
            }
        }
        if ($stream === false || $temporary === null) throw new RuntimeException('Scheduled reconciliation cursor cannot be written.');
        if (!@chmod($temporary, 0600)) throw new RuntimeException('Scheduled reconciliation cursor cannot be protected.');
        $offset = 0;
        while ($offset < strlen($contents)) {
            $written = fwrite($stream, substr($contents, $offset));
            if (!is_int($written) || $written < 1) throw new RuntimeException('Scheduled reconciliation cursor cannot be written.');
            $offset += $written;
        }
        if (!fflush($stream)) throw new RuntimeException('Scheduled reconciliation cursor cannot be written.');
        fclose($stream);
        $stream = false;
        if (is_link($path) || !@rename($temporary, $path)) {
            throw new RuntimeException('Scheduled reconciliation cursor cannot be published.');
        }
        $temporary = null;
    } finally {
        if (is_resource($stream)) fclose($stream);
        if (is_string($temporary) && file_exists($temporary)) @unlink($temporary);
    }
}

require_once __DIR__ . '/../includes/paymongo_native.php';
require_once __DIR__ . '/../includes/paymongo_refunds.php';

$scheduleLock = null;
try {
    if (!paymongo_capstone_enabled()) throw new RuntimeException('PayMongo test mode is required.');
    if ($scheduled) {
        require_once __DIR__ . '/../includes/database_backup.php';
        $scheduleLock = paymongo_fee_schedule_lock();
        if ($scheduleLock === false) exit(0);
        $after = paymongo_fee_schedule_cursor_read();
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require __DIR__ . '/../config/db_connect.php';
    paymongo_native_store($conn);
    $epoch = database_backup_authentication_epoch();

    // A provider-looking reference alone does not prove the payment belongs to this booking.
    $receiptLink = "JOIN pm_receipts r ON BINARY r.payment_id = BINARY p.transaction_id
        AND r.disposition = 'applied' AND r.currency = 'PHP' AND r.amount_cents = p.amount * 100
        JOIN pm_attempts a ON a.id = r.attempt_id AND a.booking_id = p.booking_id";
    $needsFee = "p.status = 'Success' AND (p.fee_cents IS NULL OR p.fee_verified_at IS NULL)";
    $limit = $scheduled ? 10 : 25;
    $select = $conn->prepare("SELECT p.id, p.transaction_id, p.amount, p.fee_cents, p.net_amount_cents
        FROM payments p $receiptLink WHERE p.id > ? AND $needsFee ORDER BY p.id LIMIT $limit");
    $select->bind_param('i', $after);
    $select->execute();
    $rows = $select->get_result()->fetch_all(MYSQLI_ASSOC);
    if ($scheduled && !$rows && $after > 0) {
        $after = 0;
        $select->execute();
        $rows = $select->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $select->close();

    if ($apply) {
        $update = $conn->prepare("UPDATE payments p $receiptLink
            SET p.fee_cents = ?, p.net_amount_cents = ?, p.fee_verified_at = NOW(6)
            WHERE p.id = ? AND BINARY p.transaction_id = BINARY ? AND p.amount = ? AND $needsFee
            AND (p.fee_cents <=> ?) AND (p.net_amount_cents <=> ?)");
    }
    $provider = $rows ? paymongo_native_provider() : null;
    $scheduleCursor = $scheduled && $rows ? (int)$rows[count($rows) - 1]['id'] : 0;
    $verified = $saved = $skipped = 0;
    foreach ($rows as $row) {
        $after = (int)$row['id'];
        $reference = $row['transaction_id'];
        $amount = (string)$row['amount'];
        $oldFee = $row['fee_cents'] === null ? null : (int)$row['fee_cents'];
        $oldNet = $row['net_amount_cents'] === null ? null : (int)$row['net_amount_cents'];
        try {
            $gross = paymongo_refund_money_to_cents($amount);
            $payment = $provider->retrievePayment($reference)['attributes'];
            $fee = $payment['fee'];
            $net = $payment['net_amount'] ?? $oldNet;
            if ($payment['amount'] !== $gross || ($net !== null && $gross - $fee !== $net)
                || ($oldFee !== null && $oldFee !== $fee) || ($oldNet !== null && $oldNet !== $net)) {
                $skipped++;
                continue;
            }
        } catch (RuntimeException $error) {
            $skipped++;
            continue;
        }
        $verified++;
        if (!$apply) continue;

        $lock = database_backup_lock(false);
        if ($lock === false) throw new RuntimeException('Database maintenance is in progress.');
        try {
            if (database_backup_maintenance_state() !== null || database_backup_authentication_epoch() !== $epoch) {
                throw new RuntimeException('The database changed during reconciliation.');
            }
            $update->bind_param('iiissii', $fee, $net, $after, $reference, $amount, $oldFee, $oldNet);
            $update->execute();
            $saved += $update->affected_rows;
        } finally {
            database_backup_release_lock($lock);
        }
    }

    if ($scheduled) paymongo_fee_schedule_cursor_write($scheduleCursor);
    if (!$scheduled || $rows) {
        printf("%s: %d checked, %d verified, %d saved, %d unavailable or inconsistent.\n",
            $apply ? 'Apply' : 'Dry run', count($rows), $verified, $saved, $skipped);
        if (!$scheduled && count($rows) === 25) printf("For the next batch, add --after=%d.\n", $after);
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Fee reconciliation stopped. Check the PayMongo test and database configuration, then retry.\n");
    exit(1);
} finally {
    if (is_resource($scheduleLock)) database_backup_release_lock($scheduleLock);
}
