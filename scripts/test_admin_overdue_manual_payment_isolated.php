<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Usage: php scripts/test_admin_overdue_manual_payment_isolated.php <isolated-socket> <isolated-temp-root>\n");
    exit(2);
}

$socketPath = realpath($argv[1]);
$temporaryRoot = realpath($argv[2]);
$requiredPrefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sevilla360-manual-credit.';
if ($socketPath === false || $temporaryRoot === false
    || !str_starts_with($temporaryRoot . DIRECTORY_SEPARATOR, $requiredPrefix)
    || !str_starts_with($socketPath, $temporaryRoot . DIRECTORY_SEPARATOR)
    || !file_exists($socketPath)) {
    fwrite(STDERR, "Refusing to run outside a dedicated temporary MariaDB socket and datadir.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/includes/manual_payment.php';

$databaseName = 'sevilla360_manual_credit_' . bin2hex(random_bytes(5));
$assertions = 0;
$failures = [];
$admin = null;
$conn = null;
$assert = static function (string $label, bool $condition) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) $failures[] = $label;
    fwrite(STDOUT, ($condition ? 'PASS ' : 'FAIL ') . $label . "\n");
};
$connect = static function (?string $database = null) use ($socketPath): mysqli {
    $connection = new mysqli('localhost', 'root', '', $database, 0, $socketPath);
    if ($connection->connect_errno) throw new RuntimeException('Could not connect to isolated MariaDB.');
    $connection->set_charset('utf8mb4');
    $connection->query("SET time_zone = '+08:00'");
    return $connection;
};
$fetchBooking = static function (mysqli $connection, int $bookingId): array {
    $stmt = $connection->prepare("SELECT b.*, v.category AS venue_category, CASE WHEN b.end_date < CURDATE() THEN 1 ELSE 0 END AS event_ended, 0 AS is_completed FROM bookings b JOIN venues v ON v.id=b.venue_id WHERE b.id=? FOR UPDATE");
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!is_array($booking)) throw new RuntimeException('Isolated booking fixture is missing.');
    return $booking;
};
$credit = static function (int $bookingId, float $amount, string $transactionId, ?int $submissionId, bool $preserve): array {
    global $conn, $fetchBooking;
    if (!$conn->begin_transaction()) throw new RuntimeException('Could not start isolated credit transaction.');
    try {
        $booking = $fetchBooking($conn, $bookingId);
        $result = manual_payment_credit_locked($conn, $booking, $amount, 'GCash', $transactionId, $submissionId, $preserve);
        if ($submissionId !== null) {
            $update = $conn->prepare("UPDATE manual_payment_submissions SET status='approved' WHERE id=? AND booking_id=? AND status='pending'");
            $update->bind_param('ii', $submissionId, $bookingId);
            $update->execute();
            if ($update->affected_rows !== 1) throw new RuntimeException('Isolated proof review did not update one pending submission.');
            $update->close();
        }
        $conn->commit();
        return $result;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
};
$expectCreditFailure = static function (int $bookingId, float $amount, string $transactionId, ?int $submissionId, bool $preserve) use ($credit, $assert): void {
    try {
        $credit($bookingId, $amount, $transactionId, $submissionId, $preserve);
        $assert('invalid payment credit is rejected', false);
    } catch (Throwable) {
        $assert('invalid payment credit is rejected', true);
    }
};
$bookingSnapshot = static function (mysqli $connection, int $bookingId): array {
    $stmt = $connection->prepare('SELECT booking_status,payment_status,amount_paid,payment_due_at FROM bookings WHERE id=?');
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: [];
};

try {
    $admin = $connect('mysql');
    $networking = $admin->query("SHOW VARIABLES LIKE 'skip_networking'")->fetch_assoc();
    $datadir = $admin->query('SELECT @@datadir AS value')->fetch_assoc()['value'] ?? '';
    if (strtoupper((string)($networking['Value'] ?? '')) !== 'ON'
        || !str_starts_with(realpath((string)$datadir) . DIRECTORY_SEPARATOR, $temporaryRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('MariaDB is not the requested socket-only temporary instance.');
    }
    $admin->query("CREATE DATABASE `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $conn = $connect($databaseName);
    foreach ([
        "CREATE TABLE venues (id INT PRIMARY KEY, category VARCHAR(40) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE bookings (id INT PRIMARY KEY, venue_id INT NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, total_amount DECIMAL(10,2) NOT NULL, amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0, booking_status VARCHAR(20) NOT NULL, payment_status VARCHAR(20) NOT NULL, payment_due_at DATETIME NULL, source VARCHAR(20) NOT NULL DEFAULT 'Online') ENGINE=InnoDB",
        "CREATE TABLE manual_payment_submissions (id INT PRIMARY KEY, booking_id INT NOT NULL, status VARCHAR(20) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE payments (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT NOT NULL, transaction_id VARCHAR(100) NOT NULL UNIQUE, payment_method VARCHAR(120) NOT NULL, amount DECIMAL(10,2) NOT NULL, status VARCHAR(20) NOT NULL) ENGINE=InnoDB",
    ] as $sql) $conn->query($sql);
    $conn->query("INSERT INTO venues (id,category) VALUES (1,'Hotel Room'),(2,'Event Hall')");
    $conn->query("INSERT INTO bookings (id,venue_id,start_date,end_date,total_amount,amount_paid,booking_status,payment_status,payment_due_at) VALUES
        (1,1,'2026-10-10','2026-10-11',100,0,'Pending','Unpaid','2026-10-01 00:00:00'),
        (2,1,'2026-10-10','2026-10-11',60,10,'Completed','Partial','2026-10-01 00:00:00'),
        (3,1,'2026-11-10','2026-11-11',100,0,'Pending','Unpaid','2026-12-01 00:00:00'),
        (4,1,'2026-10-10','2026-10-11',100,90,'Pending','Partial','2026-10-01 00:00:00'),
        (5,1,'2026-10-10','2026-10-11',100,90,'Pending','Partial','2026-10-01 00:00:00'),
        (6,1,'2026-10-10','2026-10-11',100,0,'Pending','Unpaid','2026-10-01 00:00:00'),
        (7,2,'2026-10-10','2026-10-11',100,0,'Pending','Unpaid','2026-10-01 00:00:00'),
        (8,1,'2026-10-10','2026-10-11',100,20,'Pending','Partial','2026-10-01 00:00:00'),
        (9,1,'2026-10-10','2026-10-11',100,0,'Confirmed','Unpaid','2026-10-01 00:00:00'),
        (10,1,'2026-10-10','2026-10-11',100,0,'Completed','Unpaid','2026-10-01 00:00:00'),
        (11,1,'2026-10-10','2026-10-11',100,0,'Pending','Unpaid','2026-10-01 00:00:00')");
    $conn->query("INSERT INTO manual_payment_submissions (id,booking_id,status) VALUES (101,1,'pending'),(102,2,'pending'),(104,4,'pending'),(105,5,'pending'),(106,6,'pending'),(116,6,'pending')");

    $pendingCredit = $credit(1, 100.00, 'MANUAL-GCASH:OVERDUE-PENDING-01', 101, true);
    $pendingAfter = $bookingSnapshot($conn, 1);
    $assert('overdue proof credit preserves Pending status and records payment', $pendingCredit['payment_status'] === 'Paid' && $pendingAfter['booking_status'] === 'Pending' && $pendingAfter['amount_paid'] === '100.00' && $pendingAfter['payment_due_at'] === null);
    $submission = $conn->query('SELECT status FROM manual_payment_submissions WHERE id=101')->fetch_assoc();
    $assert('review transaction marks the approved proof terminal', ($submission['status'] ?? '') === 'approved');

    $completedCredit = $credit(2, 50.00, 'MANUAL-GCASH:OVERDUE-COMPLETED-01', 102, true);
    $completedAfter = $bookingSnapshot($conn, 2);
    $assert('overdue proof credit preserves Completed history', $completedCredit['payment_status'] === 'Paid' && $completedAfter['booking_status'] === 'Completed' && $completedAfter['amount_paid'] === '60.00');

    $ordinaryCredit = $credit(3, 25.00, 'MANUAL-GCASH:ORDINARY-01', null, false);
    $ordinaryAfter = $bookingSnapshot($conn, 3);
    $assert('ordinary payment credit retains the existing Pending-to-Confirmed path', $ordinaryCredit['payment_status'] === 'Partial' && $ordinaryAfter['booking_status'] === 'Confirmed');

    $expectCreditFailure(4, 20.00, 'MANUAL-GCASH:OVERDUE-PENDING-01', 104, true);
    $assert('duplicate transaction reference leaves the second booking unchanged', $bookingSnapshot($conn, 4)['amount_paid'] === '90.00');
    $expectCreditFailure(5, 20.00, 'MANUAL-GCASH:OVERDUE-MISMATCH-01', 105, true);
    $assert('mismatched balance leaves booking and ledger unchanged', $bookingSnapshot($conn, 5)['amount_paid'] === '90.00'
        && (int)$conn->query("SELECT COUNT(*) AS total FROM payments WHERE transaction_id='MANUAL-GCASH:OVERDUE-MISMATCH-01'")->fetch_assoc()['total'] === 0);
    $expectCreditFailure(6, 25.00, 'MANUAL-GCASH:OVERDUE-PENDING-PROOF-01', 106, true);
    $assert('another pending proof blocks credit while the approved proof is excluded', $bookingSnapshot($conn, 6)['amount_paid'] === '0.00');
    $expectCreditFailure(4, 10.00, 'MANUAL-GCASH:PRESERVE-WITHOUT-PROOF', null, true);
    $expectCreditFailure(7, 10.00, 'MANUAL-GCASH:UNQUOTED-HALL', 107, true);

    $updateApproval = $conn->prepare("UPDATE manual_payment_submissions SET status='approved' WHERE id=101 AND status='pending'");
    $updateApproval->execute();
    $assert('a duplicate/reversed proof decision cannot change a terminal review', $updateApproval->affected_rows === 0);
    $updateApproval->close();

    foreach ([
        [11, true],
        [8, false],
        [9, false],
        [10, false],
    ] as [$bookingId, $expectedCancelled]) {
        $conn->begin_transaction();
        $booking = $fetchBooking($conn, (int)$bookingId);
        $cancelled = manual_payment_apply_overdue_rejection_locked($conn, $booking, true);
        $conn->commit();
        $after = $bookingSnapshot($conn, (int)$bookingId);
        $assert('overdue rejection preserves or cancels the correct booking state for ID ' . $bookingId,
            $cancelled === $expectedCancelled
            && $after['booking_status'] === ($expectedCancelled ? 'Cancelled' : $booking['booking_status'])
            && $after['payment_due_at'] === null);
    }
    $conn->begin_transaction();
    try {
        manual_payment_apply_overdue_rejection_locked($conn, $fetchBooking($conn, 3), false);
        $conn->rollback();
        $assert('active events cannot enter overdue rejection policy', false);
    } catch (Throwable) {
        $conn->rollback();
        $assert('active events cannot enter overdue rejection policy', true);
    }

    if ($failures) {
        fwrite(STDERR, 'FAIL isolated payment integration: ' . implode(', ', $failures) . "\n");
        exit(1);
    }
    fwrite(STDOUT, "Isolated manual-payment MariaDB integration passed ({$assertions} assertions).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL isolated manual-payment integration: ' . get_class($error) . "\n");
    exit(1);
} finally {
    if ($conn instanceof mysqli) $conn->close();
    if ($admin instanceof mysqli) {
        $admin->query("DROP DATABASE IF EXISTS `{$databaseName}`");
        $admin->close();
    }
}
