<?php
declare(strict_types=1);

// Disposable socket-only MariaDB contract test. Never loads application config.
if (PHP_SAPI !== 'cli' || $argc !== 2
    || !str_starts_with((string)realpath($argv[1]), '/tmp/sevilla360-payment-hold.')) {
    fwrite(STDERR, "Usage: php scripts/test_paymongo_hold_lifecycle_isolated.php /tmp/sevilla360-payment-hold.XXXXXX/server.sock\n");
    exit(2);
}
putenv('PAYMONGO_CAPSTONE_MODE=test');

require_once dirname(__DIR__) . '/prototype/paymongo/provider.php';
require_once dirname(__DIR__) . '/prototype/paymongo/service.php';
require_once dirname(__DIR__) . '/prototype/paymongo/hosted_flow.php';
require_once dirname(__DIR__) . '/includes/selection_availability.php';
require_once dirname(__DIR__) . '/includes/customer_booking_status.php';
date_default_timezone_set('Asia/Manila');

$socket = (string)realpath($argv[1]);
$admin = new mysqli('localhost', 'root', '', null, 0, $socket);
$admin->query("SET time_zone = '+08:00'");
$database = 'pm_hold_' . bin2hex(random_bytes(4));
$admin->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db = new mysqli('localhost', 'root', '', $database, 0, $socket);
$db->query("SET time_zone = '+08:00'");
$db->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL ' . $label);
    echo 'PASS ' . $label . "\n";
    $checks++;
};

try {
    foreach ([
        "CREATE TABLE venues (id INT PRIMARY KEY, category VARCHAR(40), status VARCHAR(40)) ENGINE=InnoDB",
        "CREATE TABLE bookings (id INT PRIMARY KEY, customer_id INT, reference_no VARCHAR(40), venue_id INT, start_date DATE, end_date DATE, total_amount DECIMAL(12,2), amount_paid DECIMAL(12,2) DEFAULT 0, payment_scheme VARCHAR(30), booking_status VARCHAR(30), payment_status VARCHAR(30), source VARCHAR(20), quote_version INT DEFAULT 0, quote_accepted TINYINT DEFAULT 0, payment_due_at DATETIME, payment_step_token CHAR(32), payment_step_expires_at DATETIME, payment_step_seen_at DATETIME, policy_accepted_at DATETIME, policy_version VARCHAR(80), pending_change TINYINT DEFAULT 0, updated_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE booking_rooms (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, venue_id INT, start_date DATE, end_date DATE) ENGINE=InnoDB",
        "CREATE TABLE booking_locks (id INT AUTO_INCREMENT PRIMARY KEY, venue_id INT, session_id VARCHAR(100), source VARCHAR(30), start_date DATE, end_date DATE, expires_at DATETIME, INDEX(venue_id)) ENGINE=InnoDB",
        "CREATE TABLE maintenance (id INT AUTO_INCREMENT PRIMARY KEY, venue_id INT, is_blocking TINYINT, status VARCHAR(30), start_date DATE, end_date DATE) ENGINE=InnoDB",
        "CREATE TABLE seminars (id INT PRIMARY KEY, status VARCHAR(30)) ENGINE=InnoDB",
        "CREATE TABLE seminar_reservations (id INT AUTO_INCREMENT PRIMARY KEY, seminar_id INT, venue_id INT, resource_kind VARCHAR(20), start_date DATE, end_date DATE) ENGINE=InnoDB",
        "CREATE TABLE payment_step_exits (token CHAR(32) PRIMARY KEY, booking_id INT, exited_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
        "CREATE TABLE pm_attempts (id CHAR(32) PRIMARY KEY, booking_id INT, snapshot CHAR(64), amount_cents BIGINT, base_paid_cents BIGINT, hold_token CHAR(32), expires_at DATETIME, status VARCHAR(30) DEFAULT 'creating', provider_session_id VARCHAR(100) UNIQUE, checkout_url VARCHAR(2048), created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6), INDEX(booking_id)) ENGINE=InnoDB",
        "CREATE TABLE pm_receipts (payment_id VARCHAR(100) PRIMARY KEY, attempt_id CHAR(32), amount_cents BIGINT, currency VARCHAR(8), disposition VARCHAR(80), created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6), INDEX(attempt_id)) ENGINE=InnoDB",
        "CREATE TABLE pm_events (id VARCHAR(100) PRIMARY KEY, digest CHAR(64), outcome VARCHAR(80), created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6)) ENGINE=InnoDB",
        "CREATE TABLE audit_logs (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, module VARCHAR(80), action TEXT, ip_address VARCHAR(64)) ENGINE=InnoDB",
    ] as $sql) $db->query($sql);

    $db->query("INSERT INTO venues VALUES (1,'Hotel Room','Available'),(2,'Hotel Room','Available'),(3,'Resort Villa','Available')");
    $db->query("INSERT INTO bookings (id,customer_id,reference_no,venue_id,start_date,end_date,total_amount,amount_paid,payment_scheme,booking_status,payment_status,source,payment_due_at,policy_version) VALUES
        (1,1,'PM-RETRY',1,DATE_ADD(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 32 DAY),1000,0,'50% Downpayment','Awaiting Proof','Unpaid','Online',DATE_ADD(NOW(),INTERVAL 1 DAY),'terms-v4-paymongo-actual-fee'),
        (2,2,'PM-COMPETITOR',1,DATE_ADD(CURDATE(),INTERVAL 30 DAY),DATE_ADD(CURDATE(),INTERVAL 32 DAY),1000,0,'50% Downpayment','Awaiting Proof','Unpaid','Online',DATE_ADD(NOW(),INTERVAL 1 DAY),'terms-v4-paymongo-actual-fee'),
        (3,3,'PM-PAID',2,DATE_ADD(CURDATE(),INTERVAL 40 DAY),DATE_ADD(CURDATE(),INTERVAL 42 DAY),1000,0,'50% Downpayment','Awaiting Proof','Unpaid','Online',DATE_ADD(NOW(),INTERVAL 1 DAY),'terms-v4-paymongo-actual-fee'),
        (4,4,'PM-UNCERTAIN',3,DATE_ADD(CURDATE(),INTERVAL 50 DAY),DATE_ADD(CURDATE(),INTERVAL 52 DAY),1000,0,'50% Downpayment','Awaiting Proof','Unpaid','Online',DATE_ADD(NOW(),INTERVAL 1 DAY),'terms-v4-paymongo-actual-fee'),
        (5,5,'PM-UNSENT',3,DATE_ADD(CURDATE(),INTERVAL 70 DAY),DATE_ADD(CURDATE(),INTERVAL 72 DAY),1000,0,'50% Downpayment','Awaiting Proof','Unpaid','Online',DATE_ADD(NOW(),INTERVAL 1 DAY),'terms-v4-paymongo-actual-fee')");

    $sessions = [];
    $createCount = 0;
    $transportMode = 'normal';
    $transport = static function (string $method, string $url, array $headers, ?string $body) use (&$sessions, &$createCount, &$transportMode): array {
        $path = (string)parse_url($url, PHP_URL_PATH);
        if ($method === 'POST' && $path === '/v1/checkout_sessions') {
            $createCount++;
            if ($transportMode === 'create_uncertain') return ['status'=>503,'body'=>'{}'];
            $attributes = json_decode((string)$body, true, 16, JSON_THROW_ON_ERROR)['data']['attributes'];
            $id = 'cs_fake_' . $createCount;
            $sessions[$id] = ['id'=>$id,'type'=>'checkout_session','attributes'=>[
                'livemode'=>false,'reference_number'=>$attributes['reference_number'],'status'=>'active',
                'line_items'=>$attributes['line_items'],'checkout_url'=>'https://checkout.paymongo.com/session/'.$id,'payments'=>[],
            ]];
            return ['status'=>200,'body'=>json_encode(['data'=>$sessions[$id], JSON_THROW_ON_ERROR])];
        }
        if (preg_match('/\A\/v1\/checkout_sessions\/(cs_[A-Za-z0-9_]+)(\/expire)?\z/D', $path, $match)) {
            $id = $match[1];
            if ($transportMode === 'retrieve_uncertain' && $method === 'GET') return ['status'=>503,'body'=>'{}'];
            if (!isset($sessions[$id])) return ['status'=>404,'body'=>'{}'];
            if ($method === 'POST' && ($match[2] ?? '') === '/expire') $sessions[$id]['attributes']['status'] = 'expired';
            return ['status'=>200,'body'=>json_encode(['data'=>$sessions[$id]], JSON_THROW_ON_ERROR)];
        }
        return ['status'=>404,'body'=>'{}'];
    };
    $provider = new PayMongoTestProvider('sk_test_PM_HOLD_LIFECYCLE', $transport, 'https://example.test/user_dashboard.php');
    $store = new PayMongoPrototype($db, false);
    $flow = new PayMongoHostedFlow($store, $provider);

    $first = $flow->start(1, 1, true);
    $firstAttempt = $db->real_escape_string($first['attempt']);
    $firstRow = $db->query("SELECT expires_at,hold_token,status FROM pm_attempts WHERE id='{$firstAttempt}'")->fetch_assoc();
    $timer = $db->query('SELECT payment_step_expires_at,payment_step_seen_at FROM bookings WHERE id=1')->fetch_assoc();
    $lock = $db->query("SELECT expires_at FROM booking_locks WHERE session_id='pay:{$firstRow['hold_token']}'")->fetch_assoc();
    $assert($first['checkout_url'] === 'https://checkout.paymongo.com/session/cs_fake_1', 'payment begins only after one server-side checkout is created');
    $assert($firstRow['status'] === 'open' && $firstRow['expires_at'] === $timer['payment_step_expires_at'], 'attempt and visible timer share the same original 15-minute deadline');
    $assert($lock && $lock['expires_at'] === '9999-12-31 23:59:59', 'inventory lock outlives the timer until verified provider closure');
    $assert(booking_selection_availability_state($db, 1, 'Hotel Room', date('Y-m-d', strtotime('+30 days')), date('Y-m-d', strtotime('+32 days')), 'other-customer') === 'held', 'a second customer sees a clear temporary-held availability state');
    try { $flow->start(2, 2, true); $competingCheckout = true; } catch (Throwable $e) { $competingCheckout = false; }
    $assert(!$competingCheckout && $createCount === 1, 'competing checkout cannot create a second hold or provider session');

    $firstSessionId = substr($first['checkout_url'], strrpos($first['checkout_url'], '/') + 1);
    $sessions[$firstSessionId]['attributes']['status'] = 'expired';
    $assert($flow->recover($first['attempt'], 1) === 'expired', 'an early provider expiry retires the checkout attempt');
    $earlyBooking = $db->query('SELECT booking_status,payment_step_token,payment_step_expires_at FROM bookings WHERE id=1')->fetch_assoc();
    $earlyLockCount = (int)$db->query("SELECT COUNT(*) n FROM booking_locks WHERE session_id='pay:{$firstRow['hold_token']}' AND expires_at>NOW()")->fetch_assoc()['n'];
    $assert($earlyBooking['booking_status'] === 'Awaiting Proof' && !empty($earlyBooking['payment_step_token']) && $earlyBooking['payment_step_expires_at'] === $firstRow['expires_at'] && $earlyLockCount === 1, 'early provider expiry keeps the pending booking and original inventory hold through its 15-minute deadline');
    $seenBeforeRetry = $db->query('SELECT payment_step_seen_at FROM bookings WHERE id=1')->fetch_assoc()['payment_step_seen_at'];
    $second = $flow->start(1, 1, true);
    $secondAttempt = $db->real_escape_string($second['attempt']);
    $secondExpiry = $db->query("SELECT expires_at FROM pm_attempts WHERE id='{$secondAttempt}'")->fetch_assoc()['expires_at'];
    $assert($second['attempt'] !== $first['attempt'] && $secondExpiry === $firstRow['expires_at'], 'a safe retry keeps the original countdown instead of resetting 15 minutes');
    $assert($seenBeforeRetry === $timer['payment_step_seen_at'], 'retrying an expired checkout preserves the original payment-step start');

    $db->query("UPDATE bookings SET payment_step_seen_at=DATE_SUB(NOW(),INTERVAL 16 MINUTE),payment_step_expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=1");
    $db->query("UPDATE pm_attempts SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id='{$secondAttempt}'");
    $transportMode = 'retrieve_uncertain';
    $assert($flow->expireDue(null, 20) === 0, 'an unavailable provider status defers server expiry');
    $assert((int)$db->query("SELECT COUNT(*) n FROM booking_locks WHERE session_id LIKE 'pay:%' AND expires_at>NOW()")->fetch_assoc()['n'] === 1, 'uncertain payment keeps inventory unavailable after the displayed timer ends');
    $assert($db->query('SELECT booking_status FROM bookings WHERE id=1')->fetch_assoc()['booking_status'] === 'Awaiting Proof', 'uncertain payment does not mark the booking expired');
    $db->begin_transaction();
    $lockedBooking = $db->query('SELECT * FROM bookings WHERE id=1 FOR UPDATE')->fetch_assoc();
    $manualFallbackHold = manual_payment_acquire_step_hold_locked($db, $lockedBooking, str_repeat('b', 32));
    $db->commit();
    $assert($manualFallbackHold === null, 'legacy payment-details acquisition cannot replace an unresolved PayMongo hold');
    $assert((int)$db->query("SELECT COUNT(*) n FROM booking_locks WHERE session_id=(SELECT CONCAT('pay:',hold_token) FROM pm_attempts WHERE id='{$secondAttempt}') AND expires_at>NOW()")->fetch_assoc()['n'] === 1, 'cross-channel fallback leaves the provider lock intact');

    $transportMode = 'normal';
    $expiredCount = $flow->expireDue(null, 20);
    $assert($expiredCount === 1, 'the server expiry worker closes an unpaid checkout without a browser return');
    $expired = $db->query('SELECT booking_status,payment_status,payment_due_at FROM bookings WHERE id=1')->fetch_assoc();
    $isPastDeadline = (int)$db->query('SELECT payment_due_at<=NOW() AS expired FROM bookings WHERE id=1')->fetch_assoc()['expired'] === 1;
    $assert($expired['booking_status'] === 'Cancelled' && $expired['payment_status'] === 'Unpaid' && $isPastDeadline, 'expired booking keeps an expired deadline marker and is no longer payment due');
    $assert((int)$db->query("SELECT COUNT(*) n FROM booking_locks WHERE session_id LIKE 'pay:%' AND expires_at>NOW()")->fetch_assoc()['n'] === 0, 'verified unpaid expiry releases the durable inventory lock');
    $status = customer_dashboard_status($expired + ['source'=>'Online','amount_paid'=>0]);
    $assert($status[0] === 'Booking expired', 'customer dashboard labels the terminal booking as expired');

    $paid = $flow->start(3, 3, true);
    $sessionId = substr($paid['checkout_url'], strrpos($paid['checkout_url'], '/') + 1);
    $sessions[$sessionId]['attributes']['payments'] = [[
        'id'=>'pay_fake_1','type'=>'payment','attributes'=>['livemode'=>false,'status'=>'paid','amount'=>50000,'currency'=>'PHP','source'=>['type'=>'gcash']],
    ]];
    $assert($flow->recover($paid['attempt'], 3) === 'applied', 'a verified paid provider result reconciles through the same booking transaction');
    $paidBooking = $db->query('SELECT booking_status,payment_status FROM bookings WHERE id=3')->fetch_assoc();
    $assert($paidBooking['booking_status'] === 'Confirmed' && $paidBooking['payment_status'] === 'Partial', 'confirmed payment confirms booking and records its paid state');
    $assert((int)$db->query("SELECT COUNT(*) n FROM booking_locks WHERE session_id LIKE 'pay:%' AND expires_at>NOW()")->fetch_assoc()['n'] === 0, 'successful payment consumes the held inventory claim');

    $transportMode = 'create_uncertain';
    $uncertain = $flow->start(4, 4, true);
    $uncertainId = $db->real_escape_string($uncertain['attempt']);
    $createsAfterFirstUncertain = $createCount;
    $again = $flow->start(4, 4, true);
    $assert($uncertain['message'] === 'uncertain' && $again['attempt'] === $uncertain['attempt'], 'uncertain create returns its durable attempt for checking');
    $assert($createCount === $createsAfterFirstUncertain, 'an uncertain provider create is never POSTed a second time');
    $assert((int)$db->query("SELECT COUNT(*) n FROM booking_locks WHERE session_id=(SELECT CONCAT('pay:',hold_token) FROM pm_attempts WHERE id='{$uncertainId}') AND expires_at>NOW()")->fetch_assoc()['n'] === 1, 'an unresolved create keeps its inventory reserved');

    // Simulate a process crash after the atomic attempt+hold commit but before
    // the request-claim transition or provider POST.
    $unsent = $store->begin(5, 5, true);
    $unsentId = $db->real_escape_string($unsent['id']);
    $createCountBeforeUnsentExpiry = $createCount;
    $db->query("UPDATE bookings SET payment_step_seen_at=DATE_SUB(NOW(),INTERVAL 16 MINUTE),payment_step_expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=5");
    $db->query("UPDATE pm_attempts SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id='{$unsentId}'");
    $assert($unsent['status'] === 'creating' && empty($unsent['provider_session_id']), 'crash fixture has a committed hold and no provider request');
    $assert($flow->expireDue(null, 20) === 1, 'expiry safely retires an unsent create after its original deadline');
    $unsentState = $db->query("SELECT status FROM pm_attempts WHERE id='{$unsentId}'")->fetch_assoc();
    $assert($unsentState['status'] === 'expired' && $createCount === $createCountBeforeUnsentExpiry, 'unsent crash cleanup closes the attempt without posting to the provider');
    $assert((int)$db->query("SELECT COUNT(*) n FROM booking_locks WHERE session_id=(SELECT CONCAT('pay:',hold_token) FROM pm_attempts WHERE id='{$unsentId}') AND expires_at>NOW()")->fetch_assoc()['n'] === 0, 'unsent crash cleanup releases its durable hold after expiry');

    echo "PASS {$checks} isolated PayMongo hold lifecycle assertions\n";
} finally {
    $db->close();
    $admin->query("DROP DATABASE `{$database}`");
    $admin->close();
}
