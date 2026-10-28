<?php
declare(strict_types=1);

// Use only a disposable, socket-only MariaDB beneath /tmp. Never load app config.
if (PHP_SAPI !== 'cli' || !in_array($argc, [2, 5], true)
    || !str_starts_with((string)realpath($argv[1]), '/tmp/sevilla360-payment-hold.')) {
    fwrite(STDERR, "Usage: php scripts/test_payment_step_hold_isolated.php /tmp/sevilla360-payment-hold.XXXXXX/server.sock\n");
    exit(2);
}
require_once dirname(__DIR__) . '/includes/manual_payment.php';
require_once dirname(__DIR__) . '/includes/selection_availability.php';
$socket = (string)realpath($argv[1]);
$connect = static function (?string $database = null) use ($socket): mysqli {
    $c = new mysqli('localhost', 'root', '', $database, 0, $socket);
    $c->query("SET time_zone = '+08:00'");
    $c->query('SET SESSION innodb_lock_wait_timeout=8');
    $c->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    return $c;
};
$booking = static fn(int $id): array => ['id'=>$id,'venue_id'=>1,'start_date'=>'2027-01-05','end_date'=>'2027-01-07'];
if ($argc === 5) {
    if (!in_array($argv[2], ['--child', '--child-expire'], true) || !preg_match('/\Astep_hold_[a-f0-9]{8}\z/', $argv[3]) || !str_starts_with($argv[4], '/tmp/sevilla360-step-result-')) exit(2);
    try {
        $c = $connect($argv[3]);
        $c->begin_transaction();
        if ($argv[2] === '--child-expire') {
            $row = $c->query('SELECT * FROM bookings WHERE id=20 FOR UPDATE')->fetch_assoc();
            $pending = (int)$c->query('SELECT COUNT(*) n FROM manual_payment_submissions WHERE booking_id=20 AND status=\'pending\' FOR UPDATE')->fetch_assoc()['n'];
            if ($pending === 0 && $row['payment_due_at'] < $c->query('SELECT NOW() db_now')->fetch_assoc()['db_now']) {
                $c->query("UPDATE bookings SET booking_status='Cancelled' WHERE id=20");
                $result = 'cancelled';
            } else $result = 'protected';
            $c->commit();
            file_put_contents($argv[4], $result);
            exit($result === 'protected' ? 0 : 1);
        }
        $b = $c->query('SELECT * FROM bookings WHERE id=11 FOR UPDATE')->fetch_assoc();
        $expiry = manual_payment_acquire_step_hold_locked($c, $b, str_repeat('b', 32));
        $c->commit();
        file_put_contents($argv[4], $expiry === null ? 'blocked' : 'double-hold');
        exit($expiry === null ? 0 : 1);
    } catch (Throwable $e) {
        file_put_contents($argv[4], 'error:' . $e->getMessage());
        exit(1);
    }
}
$admin = $connect();
$db = 'step_hold_' . bin2hex(random_bytes(4));
$admin->query("CREATE DATABASE `{$db}`");
$c = $connect($db);
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . "\n";
    $checks++;
};
try {
    foreach ([
        'CREATE TABLE venues (id INT PRIMARY KEY, category VARCHAR(40), status VARCHAR(40)) ENGINE=InnoDB',
        'CREATE TABLE bookings (id INT PRIMARY KEY, venue_id INT, start_date DATE, end_date DATE, booking_status VARCHAR(30), source VARCHAR(30), payment_due_at DATETIME, payment_step_token CHAR(32), payment_step_expires_at DATETIME, payment_step_seen_at DATETIME, proof_first_submitted_at DATETIME) ENGINE=InnoDB',
        'CREATE TABLE booking_rooms (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, venue_id INT, start_date DATE, end_date DATE) ENGINE=InnoDB',
        'CREATE TABLE booking_locks (id INT AUTO_INCREMENT PRIMARY KEY, venue_id INT, session_id VARCHAR(100), source VARCHAR(30), start_date DATE, end_date DATE, expires_at DATETIME) ENGINE=InnoDB',
        'CREATE TABLE maintenance (id INT AUTO_INCREMENT PRIMARY KEY, venue_id INT, is_blocking INT, status VARCHAR(30), start_date DATE, end_date DATE) ENGINE=InnoDB',
        'CREATE TABLE seminars (id INT PRIMARY KEY, status VARCHAR(30)) ENGINE=InnoDB',
        'CREATE TABLE seminar_reservations (id INT AUTO_INCREMENT PRIMARY KEY, seminar_id INT, venue_id INT, resource_kind VARCHAR(20), start_date DATE, end_date DATE) ENGINE=InnoDB',
        'CREATE TABLE payment_step_exits (token CHAR(32) PRIMARY KEY, booking_id INT, exited_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB',
        'CREATE TABLE manual_payment_submissions (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, status VARCHAR(30)) ENGINE=InnoDB',
    ] as $sql) $c->query($sql);
    $c->query("INSERT INTO venues VALUES (1,'Hotel Room','Available'),(2,'Hotel Room','Available'),(3,'Resort Villa','Available')");
    $c->query("INSERT INTO bookings (id,venue_id,start_date,end_date,booking_status,source) VALUES (10,1,'2027-01-05','2027-01-07','Awaiting Proof','Online'),(11,1,'2027-01-05','2027-01-07','Awaiting Proof','Online'),(20,3,'2027-01-05','2027-01-07','Awaiting Proof','Online')");
    $assert(booking_selection_availability_state($c, 1, 'Hotel Room', '2027-01-05', '2027-01-07', 'client-2') === 'available', 'second active client initially sees available room');
    $a = str_repeat('a', 32); $b = str_repeat('b', 32); $d = str_repeat('d', 32);
    $c->begin_transaction();
    $row = $c->query('SELECT * FROM bookings WHERE id=10 FOR UPDATE')->fetch_assoc();
    $expiry = manual_payment_acquire_step_hold_locked($c, $row, $a);
    $assert(is_string($expiry), 'first payment step holds the free hotel unit');
    $assert(booking_selection_availability_state($c, 1, 'Hotel Room', '2027-01-05', '2027-01-07', 'client-2') === 'held', 'competing client sees temporary hold');
    $assert(booking_selection_availability_state($c, 1, 'Hotel Room', '2027-01-08', '2027-01-10', 'client-2') === 'available', 'nonoverlapping dates remain available');
    $assert(booking_selection_availability_state($c, 3, 'Resort Villa', '2027-01-05', '2027-01-07', 'client-2') === 'available', 'different villa inventory remains available');
    $resultFile = '/tmp/sevilla360-step-result-' . bin2hex(random_bytes(5));
    $child = proc_open([PHP_BINARY, __FILE__, $socket, '--child', $db, $resultFile], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($child)) throw new RuntimeException('Cannot launch competing request.');
    fclose($pipes[0]);
    usleep(250000);
    $c->commit();
    stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($child);
    $result = is_file($resultFile) ? file_get_contents($resultFile) : 'missing';
    @unlink($resultFile);
    $assert($code === 0 && $result === 'blocked', 'concurrent acquisition waits and loses after commit: ' . $result . ' ' . trim($error));
    $c->begin_transaction();
    $row = $c->query('SELECT * FROM bookings WHERE id=10 FOR UPDATE')->fetch_assoc();
    $assert(manual_payment_acquire_step_hold_locked($c, $row, $a) === $expiry, 'same token cannot extend its hold');
    $c->commit();
    $c->query("INSERT INTO payment_step_exits (token,booking_id) VALUES ('{$d}',11)");
    $c->begin_transaction();
    $row = $c->query('SELECT * FROM bookings WHERE id=11 FOR UPDATE')->fetch_assoc();
    $assert(manual_payment_acquire_step_hold_locked($c, $row, $d) === null, 'exit arriving before acquisition prevents resurrection');
    $c->commit();
    $c->begin_transaction();
    $row = $c->query('SELECT * FROM bookings WHERE id=10 FOR UPDATE')->fetch_assoc();
    $assert(manual_payment_consume_step_hold_locked($c, $row, $a), 'valid hold is consumed by proof');
    $assert(manual_payment_claim_inventory_locked($c, $row), 'proof claims room after consuming hold');
    $c->query("UPDATE bookings SET booking_status='Pending' WHERE id=10");
    $c->commit();
    $assert(booking_selection_availability_state($c, 1, 'Hotel Room', '2027-01-05', '2027-01-07', 'client-2') === 'unavailable', 'proof promotion changes competing screen from held to unavailable');
    $c->begin_transaction();
    $row = $c->query('SELECT * FROM bookings WHERE id=11 FOR UPDATE')->fetch_assoc();
    $assert(manual_payment_acquire_step_hold_locked($c, $row, $b) === null, 'promoted proof blocks later payment step');
    $c->commit();
    $c->query("UPDATE bookings SET booking_status='Cancelled' WHERE id=10");
    $assert(booking_selection_availability_state($c, 1, 'Hotel Room', '2027-01-05', '2027-01-07', 'client-2') === 'available', 'released claim restores competing screen');
    $c->query("INSERT INTO booking_locks (venue_id,session_id,source,start_date,end_date,expires_at) VALUES (1,'staff-session','walkin','2027-01-05','2027-01-07',DATE_ADD(NOW(),INTERVAL 1 HOUR))");
    $c->begin_transaction();
    $row = $c->query('SELECT * FROM bookings WHERE id=11 FOR UPDATE')->fetch_assoc();
    $assert(manual_payment_acquire_step_hold_locked($c, $row, $b) === null, 'staff walk-in hold blocks customer payment step');
    $c->commit();
    $c->query("DELETE FROM booking_locks WHERE session_id='staff-session'");
    $c->query("INSERT INTO booking_rooms (booking_id,venue_id,start_date,end_date) VALUES (20,2,'2027-01-05','2027-01-07')");
    $c->begin_transaction();
    $row = $c->query('SELECT * FROM bookings WHERE id=20 FOR UPDATE')->fetch_assoc();
    $assert(manual_payment_acquire_step_hold_locked($c, $row, $a) !== null, 'villa plus add-on acquires both resources');
    $c->commit();
    $count = $c->query("SELECT COUNT(*) n FROM booking_locks WHERE session_id='pay:{$a}'")->fetch_assoc()['n'];
    $assert((int)$count === 2, 'multi-resource hold inserts both lock rows');
    $assert(booking_selection_availability_state($c, 3, 'Resort Villa', '2027-01-05', '2027-01-07', 'client-2') === 'held', 'competing villa screen sees temporary hold');
    $assert(booking_selection_availability_state($c, 3, 'Resort Villa', '2027-01-07', '2027-01-07', 'client-2') === 'held', 'villa final service day overlaps inclusively');
    $c->query("UPDATE bookings SET payment_step_expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=20");
    $c->query("UPDATE booking_locks SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE session_id='pay:{$a}'");
    $assert(booking_selection_availability_state($c, 3, 'Resort Villa', '2027-01-05', '2027-01-07', 'client-2') === 'available', 'expired villa hold restores availability');
    $c->begin_transaction();
    $row = $c->query('SELECT * FROM bookings WHERE id=20 FOR UPDATE')->fetch_assoc();
    $assert(!manual_payment_consume_step_hold_locked($c, $row, $a), 'expired token cannot promote proof');
    $assert(manual_payment_acquire_step_hold_locked($c, $row, $b) !== null, 'reentry can reacquire after expiry');
    $c->commit();
    $c->begin_transaction();
    $row = $c->query('SELECT * FROM bookings WHERE id=20 FOR UPDATE')->fetch_assoc();
    manual_payment_clear_step_hold_locked($c, $row);
    $c->commit();
    $assert((int)$c->query("SELECT COUNT(*) n FROM booking_locks WHERE session_id='pay:{$b}'")->fetch_assoc()['n'] === 0, 'stale proof cleanup removes current short hold without claiming inventory');
    $e = str_repeat('e', 32); $f = str_repeat('f', 32);
    $c->begin_transaction();
    $row = $c->query('SELECT * FROM bookings WHERE id=20 FOR UPDATE')->fetch_assoc();
    $assert(manual_payment_acquire_step_hold_locked($c, $row, $e) !== null, 'returning customer reacquires a fresh hold');
    $c->commit();
    $c->begin_transaction();
    $row = $c->query('SELECT * FROM bookings WHERE id=20 FOR UPDATE')->fetch_assoc();
    manual_payment_exit_step_locked($c, $row, $e);
    $c->commit();
    $assert(booking_selection_availability_state($c, 3, 'Resort Villa', '2027-01-05', '2027-01-07', 'client-2') === 'available', 'Back releases the villa hold immediately');
    $c->begin_transaction();
    $row = $c->query('SELECT * FROM bookings WHERE id=20 FOR UPDATE')->fetch_assoc();
    $assert(manual_payment_acquire_step_hold_locked($c, $row, $f) !== null, 'reentry takes a new hold token');
    $c->commit();
    $c->begin_transaction();
    $row = $c->query('SELECT * FROM bookings WHERE id=20 FOR UPDATE')->fetch_assoc();
    manual_payment_exit_step_locked($c, $row, $e);
    $c->commit();
    $assert((string)$c->query('SELECT payment_step_token FROM bookings WHERE id=20')->fetch_assoc()['payment_step_token'] === $f, 'delayed old exit cannot release the new hold');
    $c->begin_transaction();
    $row = $c->query('SELECT * FROM bookings WHERE id=20 FOR UPDATE')->fetch_assoc();
    $c->query("UPDATE bookings SET booking_status='Pending', proof_first_submitted_at=NOW() WHERE id=20");
    $row['booking_status'] = 'Pending'; $row['proof_first_submitted_at'] = date('Y-m-d H:i:s');
    manual_payment_exit_step_locked($c, $row, $f);
    $c->commit();
    $assert((string)$c->query('SELECT payment_step_token FROM bookings WHERE id=20')->fetch_assoc()['payment_step_token'] === $f, 'Back cannot release a promoted proof claim');
    $manilaNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $candidate = ['source'=>'Online','booking_status'=>'Awaiting Proof','payment_status'=>'Unpaid','amount_paid'=>0,'is_completed'=>0,'venue_category'=>'Resort Villa',
        'payment_due_at'=>$manilaNow->modify('-1 minute')->format('Y-m-d H:i:s'), 'payment_step_expires_at'=>$manilaNow->modify('+10 minutes')->format('Y-m-d H:i:s')];
    $assert(!manual_payment_expiry_candidate($candidate, false), 'active payment step protects a booking across the original 24-hour deadline');
    $c->query("UPDATE bookings SET booking_status='Awaiting Proof', proof_first_submitted_at=NULL WHERE id=20");
    $c->begin_transaction();
    $row = $c->query('SELECT * FROM bookings WHERE id=20 FOR UPDATE')->fetch_assoc();
    manual_payment_clear_step_hold_locked($c, $row);
    $c->commit();
    $c->query("UPDATE bookings SET payment_due_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=20");
    $c->begin_transaction();
    $c->query('SELECT id FROM bookings WHERE id=20 FOR UPDATE');
    $c->query("INSERT INTO manual_payment_submissions (booking_id,status) VALUES (20,'pending')");
    $resultFile = '/tmp/sevilla360-step-result-' . bin2hex(random_bytes(5));
    $child = proc_open([PHP_BINARY, __FILE__, $socket, '--child-expire', $db, $resultFile], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($child)) throw new RuntimeException('Cannot launch expiry request.');
    fclose($pipes[0]); usleep(250000); $c->commit();
    stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($child);
    $result = is_file($resultFile) ? file_get_contents($resultFile) : 'missing';
    @unlink($resultFile);
    $assert($code === 0 && $result === 'protected', 'expiry waits for proof and preserves pending review: ' . $result . ' ' . trim($error));
    echo "PASS {$checks} isolated assertions\n";
} finally {
    $c->close();
    $admin->query("DROP DATABASE `{$db}`");
    $admin->close();
}
