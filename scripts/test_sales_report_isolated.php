<?php
declare(strict_types=1);

// Report integration fixture. Requires an already-running disposable, socket-only MariaDB.
if (PHP_SAPI !== 'cli' || $argc !== 2) {
    fwrite(STDERR, "Usage: php scripts/test_sales_report_isolated.php /tmp/sevilla360-sales-report.XXXX/server.sock\n");
    exit(2);
}
$socket = realpath($argv[1]);
if ($socket === false || !str_starts_with($socket, sys_get_temp_dir() . '/sevilla360-sales-report.')) {
    fwrite(STDERR, "Refusing a non-isolated MariaDB socket.\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once dirname(__DIR__) . '/includes/sales_report.php';

$root = new mysqli('localhost', 'root', '', null, 0, $socket);
$database = 'sales_report_' . bin2hex(random_bytes(5));
$root->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db = new mysqli('localhost', 'root', '', $database, 0, $socket);
$db->set_charset('utf8mb4');
$db->query("SET time_zone = '+08:00'");
$checks = 0;
$check = static function (string $label, bool $ok) use (&$checks): void {
    $checks++;
    fwrite(STDOUT, ($ok ? 'PASS ' : 'FAIL ') . $label . "\n");
    if (!$ok) throw new RuntimeException($label);
};
$same = static function ($expected, $actual, string $label) use ($check): void {
    $check($label . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')', $expected === $actual);
};

try {
    foreach ([
        "CREATE TABLE venues (id INT PRIMARY KEY, name VARCHAR(100), category VARCHAR(40)) ENGINE=InnoDB",
        "CREATE TABLE hotel_rooms (id INT PRIMARY KEY AUTO_INCREMENT, venue_id INT) ENGINE=InnoDB",
        "CREATE TABLE customers (id INT PRIMARY KEY, first_name VARCHAR(80), last_name VARCHAR(80)) ENGINE=InnoDB",
        "CREATE TABLE bookings (id INT PRIMARY KEY, reference_no VARCHAR(40), total_amount DECIMAL(10,2), venue_id INT, customer_id INT, source VARCHAR(40)) ENGINE=InnoDB",
        "CREATE TABLE booking_rooms (id INT PRIMARY KEY AUTO_INCREMENT, booking_id INT, venue_id INT, line_total DECIMAL(10,2)) ENGINE=InnoDB",
        "CREATE TABLE payments (id INT PRIMARY KEY, booking_id INT, transaction_id VARCHAR(100), payment_method VARCHAR(120), amount DECIMAL(10,2), status VARCHAR(20), payment_date DATETIME, fee_cents BIGINT NULL, net_amount_cents BIGINT NULL, fee_verified_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE manual_payment_submissions (id INT PRIMARY KEY AUTO_INCREMENT, payment_id INT, transaction_reference VARCHAR(120)) ENGINE=InnoDB",
        "CREATE TABLE cancellations (id INT PRIMARY KEY, booking_id INT, refund_amount DECIMAL(10,2), refund_provider VARCHAR(24), refund_snapshot_id CHAR(32), refund_destination_method VARCHAR(24), refund_transaction_id VARCHAR(255)) ENGINE=InnoDB",
        "CREATE TABLE cancellation_history (id INT PRIMARY KEY, booking_id INT, cancellation_id INT, action VARCHAR(24), refund_amount DECIMAL(10,2), created_at DATETIME) ENGINE=InnoDB",
        "CREATE TABLE seminar_payments (id INT PRIMARY KEY, seminar_id INT, amount DECIMAL(10,2), status VARCHAR(20), created_at DATETIME, payment_method VARCHAR(40), transaction_reference VARCHAR(120)) ENGINE=InnoDB",
        "CREATE TABLE seminars (id INT PRIMARY KEY, name VARCHAR(100), agreed_price DECIMAL(10,2), hall_venue_id INT) ENGINE=InnoDB",
        "CREATE TABLE pm_refund_snapshots (id CHAR(32) PRIMARY KEY, cancellation_id INT, booking_id INT) ENGINE=InnoDB",
        "CREATE TABLE pm_refund_allocations (id CHAR(32) PRIMARY KEY, snapshot_id CHAR(32), payment_id VARCHAR(100), refund_cents BIGINT) ENGINE=InnoDB",
        "CREATE TABLE pm_refund_attempts (id CHAR(32) PRIMARY KEY, allocation_id CHAR(32), correlation_note VARCHAR(255), provider_refund_id VARCHAR(100), status VARCHAR(20), provider_updated_at BIGINT NULL, reconciled_at DATETIME NULL, updated_at DATETIME) ENGINE=InnoDB",
    ] as $sql) $db->query($sql);

    // Reproduce the live migration boundary: older application columns are
    // unicode_ci while newer PayMongo tables inherit the current DB default.
    $uca = $db->query("SHOW COLLATION LIKE 'utf8mb4_uca1400_ai_ci'")->fetch_assoc();
    if ($uca) {
        $db->query("ALTER TABLE cancellations MODIFY refund_snapshot_id CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
        foreach (['pm_refund_snapshots','pm_refund_allocations','pm_refund_attempts'] as $table) {
            $db->query("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci");
        }
        $db->query("ALTER TABLE payments MODIFY transaction_id VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    $db->query("INSERT INTO venues VALUES (1,'Grand Hall','Event Hall'),(2,'Villa B','Resort Villa')");
    $db->query("INSERT INTO customers VALUES (1,'Alice','Example'),(2,'Bob','Example'),(3,'Cory','Example')");
    $db->query("INSERT INTO bookings VALUES (1,'BK-1',150.00,1,1,'Online'),(2,'BK-2',40.00,2,2,'Online'),(3,'BK-3',20.00,1,3,'Online')");
    $db->query("INSERT INTO payments VALUES
        (1,1,'pay_gcash_one','GCash',100.00,'Success','2026-10-01 10:00:00',350,9650,'2026-10-01 10:01:00'),
        (2,1,'pay_maya_two','Maya',50.00,'Success','2026-10-01 11:00:00',200,NULL,'2026-10-01 11:01:00'),
        (3,2,'pay_unknown_fee','PayMongo Test',40.00,'Success','2026-10-01 12:00:00',NULL,NULL,NULL),
        (4,3,'MANUAL-test','Cash',20.00,'Success','2026-10-01 13:00:00',NULL,NULL,NULL)");
    $db->query("INSERT INTO manual_payment_submissions (payment_id,transaction_reference) VALUES (4,'CASH-REF')");
    $lateProviderTime = strtotime('2026-10-02 23:59:59 Asia/Manila');
    $db->query("INSERT INTO pm_refund_snapshots VALUES
        ('snap0000000000000000000000000001',1,1),('snap0000000000000000000000000002',NULL,1)");
    $db->query("INSERT INTO pm_refund_allocations VALUES
        ('alloc000000000000000000000000001','snap0000000000000000000000000001','pay_gcash_one',3000),
        ('alloc000000000000000000000000002','snap0000000000000000000000000001','pay_maya_two',1000),
        ('alloc000000000000000000000000003','snap0000000000000000000000000002','pay_gcash_one',500)");
    $db->query("INSERT INTO pm_refund_attempts VALUES
        ('attempt0000000000000000000000001','alloc000000000000000000000000001','note-1','ref_gcash_1','succeeded',{$lateProviderTime},'2026-10-03 00:01:00','2026-10-03 00:01:00'),
        ('attempt0000000000000000000000002','alloc000000000000000000000000001','note-duplicate','ref_gcash_1','succeeded',{$lateProviderTime},'2026-10-03 00:01:00','2026-10-03 00:01:00'),
        ('attempt0000000000000000000000003','alloc000000000000000000000000002','note-2','ref_maya_1','succeeded',{$lateProviderTime},'2026-10-03 00:01:00','2026-10-03 00:01:00'),
        ('attempt0000000000000000000000004','alloc000000000000000000000000003','note-pending',NULL,'pending',NULL,NULL,'2026-10-03 00:01:00')");
    $db->query("INSERT INTO cancellations VALUES (1,1,30.00,'paymongo','snap0000000000000000000000000001','GCash','legacy-duplicate')");
    $db->query("INSERT INTO cancellation_history VALUES
        (1,1,1,'processed',30.00,'2026-10-02 20:00:00'),
        (2,1,1,'processed',30.00,'2026-10-02 20:05:00'),
        (3,3,2,'processed',5.00,'2026-10-02 21:00:00')");
    $db->query("INSERT INTO cancellations VALUES (2,3,5.00,NULL,NULL,'Cash','legacy-cash')");

    $filters = ['from'=>'2026-10-01','to'=>'2026-10-02','method'=>'','venue_id'=>0];
    $report = sales_report_build($db, $filters);
    $same(21000, $report['received_cents'], 'successful receipts include PayMongo, manual cash, and unknown-fee payments');
    $same(4500, $report['refunded_cents'], 'successful PayMongo allocations use original methods; pending and post-period refunds are excluded');
    $same(550, $report['provider_fee_cents'], 'verified PayMongo fees include rows where net_amount_cents is NULL');
    $same(1, $report['fee_unknown_rows'], 'missing verified provider fee remains explicitly unknown');
    $same(null, $report['net_cents'], 'net is unknown when any selected PayMongo payment has unknown fees');
    $check('duplicate cancellation history does not duplicate provider refunds', $report['refunded_cents'] === 4500);

    $methodReport = sales_report_build($db, array_replace($filters, ['method'=>'GCash']));
    $gcash = array_values(array_filter($methodReport['method_totals'], static fn(array $row): bool => $row['method'] === 'GCash'))[0] ?? null;
    $check('GCash successful provider refund is attributed to original GCash payment', $gcash !== null && $gcash['received_cents'] === 10000 && $gcash['refunded_cents'] === 3000 && $gcash['provider_fee_cents'] === 350 && $gcash['net_cents'] === 6650);
    $mayaReport = sales_report_build($db, array_replace($filters, ['method'=>'Maya']));
    $maya = array_values(array_filter($mayaReport['method_totals'], static fn(array $row): bool => $row['method'] === 'Maya'))[0] ?? null;
    $check('split refund allocation follows Maya original payment and derives net from verified fee', $maya !== null && $maya['refunded_cents'] === 1000 && $maya['provider_fee_cents'] === 200 && $maya['net_cents'] === 3800);
    $unknownReport = sales_report_build($db, array_replace($filters, ['method'=>'PayMongo Test']));
    $same(null, $unknownReport['net_cents'], 'unknown fee is not silently treated as zero for filtered PayMongo method');
    $same(1, $unknownReport['fee_unknown_rows'], 'filtered unknown-fee payment is visible in fee count');

    $activities = sales_report_fetch_activities($db, $filters, 0, 100);
    $providerRefunds = array_values(array_filter($activities, static fn(array $row): bool => $row['activity_type'] === 'refunded' && str_starts_with((string)$row['activity_id'], 'PMREFUND:')));
    $same(2, count($providerRefunds), 'one row per distinct succeeded provider refund ID; duplicate ID is counted once');
    $providerMethods = array_values(array_unique(array_column($providerRefunds, 'method_label')));
    sort($providerMethods);
    $same(['GCash','Maya'], $providerMethods, 'provider refund method labels come from the original payment rows');

    $captured = [];
    sales_report_build($db, $filters, 1, 50, static function (array $transaction) use (&$captured): void { $captured[] = $transaction; });
    $check('export callback receives gross, customer refund, verified fee, and net columns', count($captured) === 7 && isset($captured[0]['gross_received_cents'], $captured[0]['customer_refund_cents'], $captured[0]['provider_fee_cents'], $captured[0]['net_after_fees_refunds_cents']));

    $db->query("INSERT INTO cancellations VALUES (3,2,7.00,'paymongo',NULL,NULL,NULL)");
    $db->query("INSERT INTO cancellation_history VALUES (4,2,3,'processed',7.00,'2026-10-02 22:00:00')");
    $incomplete = sales_report_build($db, $filters);
    $same(false, $incomplete['paymongo_refund_data_complete'], 'processed PayMongo history without successful allocation records makes the period incomplete');
    $same(null, $incomplete['net_cents'], 'unmatched processed PayMongo history never produces a falsely complete net total');
    $cashScope = sales_report_build($db, array_replace($filters, ['method'=>'Cash']));
    $check('unmatched GCash refund does not make the Cash method report unknown', $cashScope['received_cents'] === 2000 && $cashScope['refunded_cents'] === 500 && $cashScope['net_cents'] === 1500);
    $venueScope = sales_report_build($db, array_replace($filters, ['venue_id'=>1]));
    $check('unmatched refund at a different venue does not make this venue report unknown', $venueScope['received_cents'] === 17000 && $venueScope['refunded_cents'] === 4500 && $venueScope['net_cents'] === 11950);

    $db->query('DROP TABLE pm_refund_attempts, pm_refund_allocations, pm_refund_snapshots');
    $cashOnly = sales_report_build($db, array_replace($filters, ['from'=>'2026-10-01','to'=>'2026-10-01','method'=>'Cash']));
    $check('missing PayMongo ledger does not hide known manual-only net in a period with no PayMongo refund history', $cashOnly['received_cents'] === 2000 && $cashOnly['refunded_cents'] === 0 && $cashOnly['net_cents'] === 2000);
    $db->query('ALTER TABLE payments DROP COLUMN net_amount_cents');
    $feeOnly = sales_report_build($db, array_replace($filters, ['from'=>'2026-10-01','to'=>'2026-10-01','method'=>'GCash']));
    $check('verified fees remain reportable when optional stored net column is absent', $feeOnly['provider_fee_cents'] === 350 && $feeOnly['fee_unknown_rows'] === 0 && $feeOnly['net_cents'] === null);

    echo "Sales report isolated checks passed ({$checks} checks)\n";
} finally {
    $db->close();
    $root->query("DROP DATABASE IF EXISTS `{$database}`");
    $root->close();
}
