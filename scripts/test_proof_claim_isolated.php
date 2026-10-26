<?php
declare(strict_types=1);

// Only a disposable local MariaDB socket is accepted. No application config is loaded.
if (PHP_SAPI !== 'cli' || !in_array($argc, [2, 5], true) || !str_starts_with((string)realpath($argv[1]), '/tmp/sevilla360-booking-proof.local/')) {
    fwrite(STDERR, "Usage: php scripts/test_proof_claim_isolated.php /tmp/sevilla360-booking-proof.local/server.sock\n");
    exit(2);
}
require_once dirname(__DIR__) . '/includes/manual_payment.php';
$socket = (string)realpath($argv[1]);
if ($argc === 5) {
    if ($argv[2] !== '--child' || !preg_match('/\Aproof_claim_[a-f0-9]{8}\z/', $argv[3]) || !str_starts_with($argv[4], '/tmp/sevilla360-proof-race-')) exit(2);
    try {
        $child = new mysqli('localhost', 'root', '', $argv[3], 0, $socket);
        $child->query('SET SESSION innodb_lock_wait_timeout=5');
        $child->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $child->begin_transaction();
        $won = manual_payment_claim_inventory_locked($child, ['id'=>11,'venue_id'=>1,'start_date'=>'2027-01-05','end_date'=>'2027-01-07']);
        $child->rollback();
        file_put_contents($argv[4], $won ? 'incorrect-double-claim' : 'blocked');
        exit(0);
    } catch (Throwable $error) {
        file_put_contents($argv[4], 'error:' . $error->getMessage());
        exit(1);
    }
}
$admin = new mysqli('localhost', 'root', '', null, 0, $socket);
$db = 'proof_claim_' . bin2hex(random_bytes(4));
$admin->query("CREATE DATABASE `{$db}`");
$conn = new mysqli('localhost', 'root', '', $db, 0, $socket);
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException("FAIL {$label}");
    $checks++;
    echo "PASS {$label}\n";
};
try {
    foreach ([
        'CREATE TABLE venues (id INT PRIMARY KEY, category VARCHAR(40), status VARCHAR(40)) ENGINE=InnoDB',
        'CREATE TABLE bookings (id INT PRIMARY KEY, venue_id INT, start_date DATE, end_date DATE, booking_status VARCHAR(30), source VARCHAR(30)) ENGINE=InnoDB',
        'CREATE TABLE booking_rooms (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, venue_id INT, start_date DATE, end_date DATE) ENGINE=InnoDB',
        'CREATE TABLE booking_locks (id INT AUTO_INCREMENT PRIMARY KEY, venue_id INT, start_date DATE, end_date DATE, expires_at DATETIME) ENGINE=InnoDB',
        'CREATE TABLE maintenance (id INT AUTO_INCREMENT PRIMARY KEY, venue_id INT, is_blocking INT, status VARCHAR(30), start_date DATE, end_date DATE) ENGINE=InnoDB',
        'CREATE TABLE seminars (id INT PRIMARY KEY, status VARCHAR(30)) ENGINE=InnoDB',
        'CREATE TABLE seminar_reservations (id INT AUTO_INCREMENT PRIMARY KEY, seminar_id INT, venue_id INT, resource_kind VARCHAR(20), start_date DATE, end_date DATE) ENGINE=InnoDB',
    ] as $sql) $conn->query($sql);
    $conn->query("INSERT INTO venues VALUES (1,'Hotel Room','Available')");
    $conn->query("INSERT INTO bookings VALUES (10,1,'2027-01-05','2027-01-07','Awaiting Proof','Online'),(11,1,'2027-01-05','2027-01-07','Awaiting Proof','Online')");
    $first = ['id'=>10,'venue_id'=>1,'start_date'=>'2027-01-05','end_date'=>'2027-01-07'];
    $second = ['id'=>11,'venue_id'=>1,'start_date'=>'2027-01-05','end_date'=>'2027-01-07'];
    $conn->begin_transaction();
    $assert(manual_payment_claim_inventory_locked($conn, $first), 'first proof can claim free unit');
    $conn->query("UPDATE bookings SET booking_status='Pending' WHERE id=10");
    $conn->commit();
    $conn->begin_transaction();
    $assert(!manual_payment_claim_inventory_locked($conn, $second), 'competing proof cannot claim occupied unit');
    $conn->rollback();
    $conn->query("UPDATE bookings SET booking_status='Cancelled' WHERE id=10");
    $conn->begin_transaction();
    $assert(manual_payment_claim_inventory_locked($conn, $second), 'released unit can be claimed');
    $conn->rollback();
    if (function_exists('proc_open')) {
        $resultFile = sys_get_temp_dir() . '/sevilla360-proof-race-' . bin2hex(random_bytes(6));
        $conn->begin_transaction();
        $assert(manual_payment_claim_inventory_locked($conn, $first), 'first transaction acquires venue mutex');
        $conn->query("UPDATE bookings SET booking_status='Pending' WHERE id=10");
        $process = proc_open([PHP_BINARY, __FILE__, $socket, '--child', $db, $resultFile], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Unable to launch isolated competitor.');
        fclose($pipes[0]);
        usleep(300000);
        $conn->commit();
        stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $status = proc_close($process);
        $result = is_file($resultFile) ? file_get_contents($resultFile) : 'missing';
        @unlink($resultFile);
        $assert($result === 'blocked' && $status === 0, 'concurrent proof waits, then loses after first claim commits (' . $result . ' ' . trim($stderr) . ')');
    }
    $conn->query("ALTER TABLE bookings ADD payment_due_at DATETIME NULL, ADD proof_first_submitted_at DATETIME NULL, ADD proof_rejections_count TINYINT NOT NULL DEFAULT 0, ADD proof_retry_submitted_at DATETIME NULL");
    $conn->query("UPDATE bookings SET payment_due_at=DATE_ADD(NOW(), INTERVAL 24 HOUR), proof_first_submitted_at=NOW() WHERE id=10");
    $conn->query("UPDATE bookings SET proof_rejections_count=1, payment_due_at=DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id=10 AND proof_rejections_count=0");
    $rejected = $conn->query("SELECT proof_rejections_count, TIMESTAMPDIFF(MINUTE,NOW(),payment_due_at) AS minutes_left FROM bookings WHERE id=10")->fetch_assoc();
    $assert((int)$rejected['proof_rejections_count']===1 && (int)$rejected['minutes_left']>=1439, 'first rejection refreshes correction deadline to 24 hours');
    $beforeRetry = $conn->query("SELECT payment_due_at FROM bookings WHERE id=10")->fetch_assoc()['payment_due_at'];
    $conn->query("UPDATE bookings SET proof_retry_submitted_at=NOW() WHERE id=10 AND proof_rejections_count=1 AND proof_retry_submitted_at IS NULL");
    $afterRetry = $conn->query("SELECT payment_due_at, proof_retry_submitted_at FROM bookings WHERE id=10")->fetch_assoc();
    $assert($afterRetry['payment_due_at']===$beforeRetry && $afterRetry['proof_retry_submitted_at']!==null, 'retry is recorded without a second deadline reset');
    $conn->query("UPDATE bookings SET payment_due_at=DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id=10");
    $expiredReview = $conn->query("SELECT payment_due_at, booking_status, source, 'Unpaid' AS payment_status, 0 AS amount_paid, 0 AS is_completed, 'Hotel Room' AS venue_category FROM bookings WHERE id=10")->fetch_assoc();
    $assert(!manual_payment_expiry_candidate($expiredReview,true) && manual_payment_expiry_candidate($expiredReview,false), 'pending review blocks overdue expiry, but rejected retry window releases');
    $conn->query("UPDATE bookings SET booking_status='Cancelled', proof_rejections_count=2, payment_due_at=NULL WHERE id=10 AND proof_rejections_count=1");
    $closed = $conn->query("SELECT booking_status, proof_rejections_count, payment_due_at FROM bookings WHERE id=10")->fetch_assoc();
    $assert($closed['booking_status']==='Cancelled' && (int)$closed['proof_rejections_count']===2 && $closed['payment_due_at']===null, 'second rejection releases the date immediately');
    $conn->query("INSERT INTO venues VALUES (2,'Resort Villa','Available'),(3,'Event Hall','Available')");
    $conn->query("INSERT INTO bookings VALUES (20,2,'2027-01-05','2027-01-07','Awaiting Proof','Online',NULL,NULL,0,NULL),(21,2,'2027-01-05','2027-01-07','Awaiting Proof','Online',NULL,NULL,0,NULL),(30,3,'2027-01-05','2027-01-07','Pending','Online',NULL,NULL,0,NULL)");
    $villa = ['id'=>20,'venue_id'=>2,'start_date'=>'2027-01-05','end_date'=>'2027-01-07'];
    $conn->begin_transaction();
    $assert(manual_payment_claim_inventory_locked($conn,$villa), 'unpaid villa requests do not preempt first proof');
    $conn->query("UPDATE bookings SET booking_status='Pending' WHERE id=20");
    $conn->commit();
    $conn->begin_transaction();
    $assert(!manual_payment_claim_inventory_locked($conn,['id'=>21,'venue_id'=>2,'start_date'=>'2027-01-05','end_date'=>'2027-01-07']), 'villa competing claim is denied after first proof');
    $conn->rollback();
    $conn->query("INSERT INTO booking_rooms (booking_id,venue_id,start_date,end_date) VALUES (30,1,'2027-01-05','2027-01-07')");
    $conn->begin_transaction();
    $assert(manual_payment_claim_inventory_locked($conn,$second), 'pending Event Hall inquiry does not preclaim its hotel add-on');
    $conn->rollback();
    $conn->query("UPDATE bookings SET booking_status='Confirmed' WHERE id=30");
    $conn->begin_transaction();
    $assert(!manual_payment_claim_inventory_locked($conn,$second), 'confirmed Event Hall allocation blocks its hotel room');
    $conn->rollback();
    $proofColumns = 'id BIGINT PRIMARY KEY, booking_id INT, status VARCHAR(20), customer_user_id INT, reviewer_user_id INT NULL, payment_id INT NULL, payment_method VARCHAR(32), expected_amount DECIMAL(10,2), transaction_reference VARCHAR(64), reference_fingerprint CHAR(64), proof_filename VARCHAR(80), proof_mime VARCHAR(32), proof_size_bytes INT, proof_sha256 CHAR(64), rejection_reason VARCHAR(500) NULL, submitted_at DATETIME, reviewed_at DATETIME NULL';
    $conn->query("CREATE TABLE manual_payment_submissions ({$proofColumns}) ENGINE=InnoDB");
    $historyColumns = substr($proofColumns, strlen('id BIGINT PRIMARY KEY, '));
    $conn->query("CREATE TABLE manual_payment_submission_history (history_id INT AUTO_INCREMENT PRIMARY KEY, submission_id BIGINT, {$historyColumns}) ENGINE=InnoDB");
    $conn->query("INSERT INTO manual_payment_submissions VALUES (1,10,'pending',1,NULL,NULL,'GCash',1500,'MOCK-A',REPEAT('a',64),'proof-a.png','image/png',150,REPEAT('b',64),NULL,NOW(),NULL)");
    $conn->begin_transaction();
    manual_payment_archive_submission_locked($conn,1);
    $conn->query("UPDATE manual_payment_submissions SET status='rejected', rejection_reason='Unreadable', reviewed_at=NOW() WHERE id=1");
    manual_payment_archive_submission_locked($conn,1);
    $conn->query("UPDATE manual_payment_submissions SET status='pending', proof_filename='proof-b.png', rejection_reason=NULL, reviewed_at=NULL WHERE id=1");
    $conn->commit();
    $proofHistory = $conn->query("SELECT GROUP_CONCAT(CONCAT(status,':',proof_filename) ORDER BY history_id) AS versions FROM manual_payment_submission_history WHERE submission_id=1")->fetch_assoc()['versions'];
    $assert($proofHistory==='pending:proof-a.png,rejected:proof-a.png', 'replacement preserves original proof and rejection snapshots');
    echo "PASS {$checks} isolated proof-claim checks\n";
} finally {
    $conn->close();
    $admin->query("DROP DATABASE `{$db}`");
    $admin->close();
}
