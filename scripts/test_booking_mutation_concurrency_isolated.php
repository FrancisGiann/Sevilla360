<?php
// CLI harness only. Start a fresh temporary datadir with mariadb-install-db,
// launch mariadbd on a Unix socket with --skip-networking, then pass the socket
// and its /tmp/sevilla360-booking-concurrency.* root below. No app DB config is loaded.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php scripts/test_booking_mutation_concurrency_isolated.php <isolated-socket> <isolated-temp-root>\n");
    exit(2);
}

$socketPath = realpath($argv[1]);
$temporaryRoot = realpath($argv[2]);
$requiredPrefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sevilla360-booking-concurrency.';
if ($socketPath === false || $temporaryRoot === false
    || !str_starts_with($temporaryRoot . DIRECTORY_SEPARATOR, $requiredPrefix)
    || !str_starts_with($socketPath, $temporaryRoot . DIRECTORY_SEPARATOR)
    || !file_exists($socketPath)) {
    fwrite(STDERR, "Refusing to run outside a dedicated temporary MariaDB socket and datadir.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/includes/booking_rules.php';

$databaseName = 'sevilla360_booking_test_' . bin2hex(random_bytes(5));
$checks = 0;
$failures = [];
$admin = null;

function booking_test_check(string $label, bool $condition): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) $failures[] = $label;
    fwrite(STDOUT, ($condition ? 'PASS ' : 'FAIL ') . $label . "\n");
}

function booking_test_connect(string $socketPath, ?string $databaseName = null): mysqli
{
    $conn = new mysqli('localhost', 'root', '', $databaseName, 0, $socketPath);
    if ($conn->connect_errno) throw new RuntimeException('Could not connect to the isolated MariaDB socket.');
    $conn->set_charset('utf8mb4');
    $conn->query('SET SESSION innodb_lock_wait_timeout = 30');
    return $conn;
}

function booking_test_assert_query(mysqli $conn, string $sql): void
{
    if (!$conn->query($sql)) throw new RuntimeException('Isolated fixture SQL failed: ' . $conn->error);
}

function booking_test_insert(mysqli $conn, string $sql, string $types, array $values): void
{
    $stmt = $conn->prepare($sql);
    if (!$stmt || !booking_rules_bind_params($stmt, $types, $values) || !$stmt->execute()) {
        throw new RuntimeException('Isolated fixture insert failed.');
    }
    $stmt->close();
}

function booking_test_create_schema(mysqli $conn): void
{
    $statements = [
        "CREATE TABLE venues (id INT PRIMARY KEY, name VARCHAR(100) NOT NULL, category VARCHAR(40) NOT NULL, status VARCHAR(20) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE hotel_rooms (venue_id INT PRIMARY KEY, room_type VARCHAR(80) NOT NULL, nightly_rate DECIMAL(10,2) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE bookings (id INT PRIMARY KEY, venue_id INT NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, booking_status VARCHAR(20) NOT NULL, source VARCHAR(30) NOT NULL DEFAULT 'Online') ENGINE=InnoDB",
        "CREATE TABLE booking_rooms (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT NOT NULL, venue_id INT NOT NULL, nightly_rate DECIMAL(10,2) NOT NULL DEFAULT 0, start_date DATE NOT NULL, end_date DATE NOT NULL, nights INT NOT NULL DEFAULT 1, line_total DECIMAL(10,2) NOT NULL DEFAULT 0) ENGINE=InnoDB",
        "CREATE TABLE maintenance (id INT AUTO_INCREMENT PRIMARY KEY, venue_id INT NOT NULL, is_blocking TINYINT NOT NULL DEFAULT 1, status VARCHAR(20) NULL, start_date DATE NOT NULL, end_date DATE NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE booking_locks (id INT AUTO_INCREMENT PRIMARY KEY, session_id VARCHAR(80) NOT NULL, venue_id INT NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, expires_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE seminars (id INT PRIMARY KEY, status VARCHAR(20) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE seminar_reservations (id INT AUTO_INCREMENT PRIMARY KEY, seminar_id INT NOT NULL, venue_id INT NOT NULL, resource_kind VARCHAR(12) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL) ENGINE=InnoDB",
    ];
    foreach ($statements as $sql) booking_test_assert_query($conn, $sql);
}

function booking_test_event_conflict(mysqli $conn, int $venueId, int $bookingId, string $start, string $end): bool
{
    $sql = "SELECT id FROM bookings WHERE venue_id=? AND id<>? AND booking_status IN ('Confirmed','Completed')
        AND COALESCE(source,'')<>'Maintenance' AND " . booking_overlap_sql('Event Hall') . ' LIMIT 1';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiss', $venueId, $bookingId, $end, $start);
    if (!$stmt->execute()) throw new RuntimeException('Event Hall conflict fixture query failed.');
    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $found;
}

function booking_test_controlled_venue_lock(mysqli $conn, array $venueIds, $channel, string $role): void
{
    if (!is_resource($channel)) {
        booking_lock_venues_in_order($conn, $venueIds);
        return;
    }

    fwrite($channel, "PLANNED\n");
    fflush($channel);
    $command = trim((string)fgets($channel));
    if ($role === 'holder' && $command === 'LOCK') {
        booking_lock_venues_in_order($conn, $venueIds);
        $connectionId = (int)($conn->query('SELECT CONNECTION_ID() AS id')->fetch_assoc()['id'] ?? 0);
        $activeTransaction = (int)($conn->query('SELECT @@in_transaction AS active')->fetch_assoc()['active'] ?? 0);
        fwrite($channel, "LOCKED " . json_encode(['connection_id' => $connectionId, 'active' => $activeTransaction]) . "\n");
        fflush($channel);
        if (trim((string)fgets($channel)) !== 'RELEASE') throw new RuntimeException('Venue-lock holder was not released.');
        return;
    }
    if ($role === 'waiter' && $command === 'ATTEMPT') {
        $connectionId = (int)($conn->query('SELECT CONNECTION_ID() AS id')->fetch_assoc()['id'] ?? 0);
        fwrite($channel, "WAITING " . json_encode(['connection_id' => $connectionId]) . "\n");
        fflush($channel);
        booking_lock_venues_in_order($conn, $venueIds);
        return;
    }
    throw new RuntimeException('Unexpected deterministic concurrency command.');
}

function booking_test_finalize_worker(string $socketPath, string $databaseName, int $bookingId, $channel = null, string $role = ''): string
{
    $conn = booking_test_connect($socketPath, $databaseName);
    try {
        booking_begin_mutation_transaction($conn);
        $stmt = $conn->prepare('SELECT id,venue_id,start_date,end_date,booking_status FROM bookings WHERE id=? FOR UPDATE');
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$booking || $booking['booking_status'] !== 'Pending') throw new RuntimeException('Pending inquiry changed before invoice finalization.');

        $plan = event_hall_addon_reallocation_plan($conn, $bookingId);
        $candidateIds = event_hall_addon_candidate_venue_ids($conn, $plan);
        $stmt = $conn->prepare('SELECT venue_id FROM booking_rooms WHERE booking_id=?');
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $existingIds = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'venue_id'));
        $stmt->close();
        booking_test_controlled_venue_lock($conn, array_merge([(int)$booking['venue_id']], $existingIds, $candidateIds), $channel, $role);

        if (booking_test_event_conflict($conn, (int)$booking['venue_id'], $bookingId, $booking['start_date'], $booking['end_date'])) {
            $conn->rollback();
            return 'conflict';
        }
        reallocate_event_hall_addons($conn, $bookingId, $plan, $candidateIds);
        $stmt = $conn->prepare("UPDATE bookings SET booking_status='Confirmed' WHERE id=?");
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        return 'confirmed';
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    } finally {
        $conn->close();
    }
}

function booking_test_reschedule_worker(string $socketPath, string $databaseName, int $bookingId, string $start, string $end, $channel = null, string $role = ''): string
{
    $conn = booking_test_connect($socketPath, $databaseName);
    try {
        booking_begin_mutation_transaction($conn);
        $stmt = $conn->prepare('SELECT id,venue_id FROM bookings WHERE id=? FOR UPDATE');
        $stmt->bind_param('i', $bookingId);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$booking) throw new RuntimeException('Reschedule booking fixture is missing.');
        booking_test_controlled_venue_lock($conn, [(int)$booking['venue_id']], $channel, $role);
        if (booking_test_event_conflict($conn, (int)$booking['venue_id'], $bookingId, $start, $end)) {
            $conn->rollback();
            return 'conflict';
        }
        $stmt = $conn->prepare('UPDATE bookings SET start_date=?,end_date=? WHERE id=?');
        $stmt->bind_param('ssi', $start, $end, $bookingId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        return 'rescheduled';
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    } finally {
        $conn->close();
    }
}

/** Fork synchronized workers and release them together to contend on real InnoDB venue mutexes. */
function booking_test_run_parallel(string $socketPath, string $databaseName, array $workers): array
{
    $processes = [];
    $channels = [];
    foreach ($workers as $worker) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) throw new RuntimeException('Unable to create isolated worker synchronization sockets.');
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('Unable to fork an isolated concurrency worker.');
        if ($pid === 0) {
            fclose($pair[0]);
            try {
                fwrite($pair[1], "READY\n");
                fflush($pair[1]);
                if (trim((string)fgets($pair[1])) !== 'GO') throw new RuntimeException('Worker start signal was missing.');
                $result = $worker($socketPath, $databaseName);
                fwrite($pair[1], "RESULT " . json_encode(['result' => $result]) . "\n");
                fflush($pair[1]);
                fclose($pair[1]);
                exit(0);
            } catch (Throwable $error) {
                fwrite($pair[1], "RESULT " . json_encode(['error' => get_class($error), 'message' => substr($error->getMessage(), 0, 240)]) . "\n");
                fflush($pair[1]);
                fclose($pair[1]);
                exit(1);
            }
        }
        fclose($pair[1]);
        $processes[] = $pid;
        $channels[] = $pair[0];
    }

    foreach ($channels as $channel) {
        if (trim((string)fgets($channel)) !== 'READY') throw new RuntimeException('Concurrency worker did not become ready.');
    }
    foreach ($channels as $channel) { fwrite($channel, "GO\n"); fflush($channel); }
    $results = [];
    foreach ($channels as $index => $channel) {
        $line = trim((string)fgets($channel));
        fclose($channel);
        $results[] = str_starts_with($line, 'RESULT ') ? json_decode(substr($line, 7), true) : ['error' => 'worker_no_result'];
    }
    foreach ($processes as $index => $pid) {
        pcntl_waitpid($pid, $status);
        if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            $results[$index]['process_exit'] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 'abnormal';
        }
    }
    return $results;
}

/** Force a real waiter: both transactions finish planning, the holder locks and pauses, then the waiter attempts the same venue union. */
function booking_test_run_staged_pair(string $socketPath, string $databaseName, callable $holder, callable $waiter): array
{
    $processes = [];
    $channels = [];
    foreach ([$holder, $waiter] as $worker) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) throw new RuntimeException('Unable to create staged worker synchronization sockets.');
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('Unable to fork a staged concurrency worker.');
        if ($pid === 0) {
            fclose($pair[0]);
            try {
                fwrite($pair[1], "READY\n"); fflush($pair[1]);
                if (trim((string)fgets($pair[1])) !== 'GO') throw new RuntimeException('Staged worker start signal was missing.');
                $result = $worker($socketPath, $databaseName, $pair[1]);
                fwrite($pair[1], "RESULT " . json_encode(['result' => $result]) . "\n"); fflush($pair[1]);
                fclose($pair[1]); exit(0);
            } catch (Throwable $error) {
                fwrite($pair[1], "RESULT " . json_encode(['error' => get_class($error), 'message' => substr($error->getMessage(), 0, 240)]) . "\n");
                fflush($pair[1]); fclose($pair[1]); exit(1);
            }
        }
        fclose($pair[1]);
        $processes[] = $pid;
        $channels[] = $pair[0];
    }

    $readMessage = static function ($channel, int $timeoutSeconds = 8): string {
        $read = [$channel]; $write = []; $except = [];
        $ready = stream_select($read, $write, $except, $timeoutSeconds);
        if ($ready !== 1) throw new RuntimeException('Timed out waiting for a staged worker signal.');
        return trim((string)fgets($channel));
    };

    try {
        foreach ($channels as $channel) if ($readMessage($channel) !== 'READY') throw new RuntimeException('Staged worker did not become ready.');
        foreach ($channels as $channel) { fwrite($channel, "GO\n"); fflush($channel); }
        foreach ($channels as $channel) if ($readMessage($channel) !== 'PLANNED') throw new RuntimeException('A worker did not finish its pre-lock planning.');
        fwrite($channels[0], "LOCK\n"); fflush($channels[0]);
        $holderMessage = $readMessage($channels[0]);
        if (!str_starts_with($holderMessage, 'LOCKED ')) throw new RuntimeException('The holder did not acquire its venue union.');
        $holderState = json_decode(substr($holderMessage, 7), true) ?: [];
        $holderConnectionId = (int)($holderState['connection_id'] ?? 0);
        $holderActive = (int)($holderState['active'] ?? 0) === 1 && $holderConnectionId > 0;
        fwrite($channels[1], "ATTEMPT\n"); fflush($channels[1]);
        $waiterMessage = $readMessage($channels[1]);
        if (!str_starts_with($waiterMessage, 'WAITING ')) throw new RuntimeException('The waiter did not attempt its venue union.');
        $waiterState = json_decode(substr($waiterMessage, 8), true) ?: [];
        $waiterConnectionId = (int)($waiterState['connection_id'] ?? 0);

        $monitor = booking_test_connect($socketPath, $databaseName);
        $waitObserved = false;
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $stmt = $monitor->prepare('SELECT COUNT(*) AS waiting FROM information_schema.INNODB_LOCK_WAITS w
                INNER JOIN information_schema.INNODB_TRX requesting ON requesting.trx_id=w.requesting_trx_id
                INNER JOIN information_schema.INNODB_TRX blocking ON blocking.trx_id=w.blocking_trx_id
                WHERE requesting.trx_mysql_thread_id=? AND blocking.trx_mysql_thread_id=?');
            $stmt->bind_param('ii', $waiterConnectionId, $holderConnectionId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ((int)($row['waiting'] ?? 0) > 0) { $waitObserved = true; break; }
            usleep(250000);
        }
        if (!$waitObserved) {
            $stmt = $monitor->prepare('SELECT trx_state,trx_id,trx_mysql_thread_id,trx_query FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id IN (?,?)');
            $stmt->bind_param('ii', $holderConnectionId, $waiterConnectionId);
            $stmt->execute();
            $activeWorkers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            fwrite(STDERR, 'No InnoDB wait for worker connections ' . $holderConnectionId . '/' . $waiterConnectionId . '; worker transactions: ' . json_encode($activeWorkers) . "\n");
        }
        $monitor->close();
        fwrite($channels[0], "RELEASE\n"); fflush($channels[0]);
        $results = [];
        foreach ($channels as $channel) {
            $line = $readMessage($channel, 10);
            $results[] = str_starts_with($line, 'RESULT ') ? json_decode(substr($line, 7), true) : ['error' => 'worker_no_result'];
            fclose($channel);
        }
        foreach ($processes as $index => $pid) {
            pcntl_waitpid($pid, $status);
            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                $results[$index]['process_exit'] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 'abnormal';
            }
        }
        return ['holder_active' => $holderActive, 'holder_state' => $holderState, 'wait_observed' => $waitObserved, 'results' => $results];
    } catch (Throwable $error) {
        @fwrite($channels[0], "RELEASE\n"); @fflush($channels[0]);
        foreach ($channels as $channel) if (is_resource($channel)) fclose($channel);
        foreach ($processes as $pid) { @posix_kill($pid, SIGTERM); @pcntl_waitpid($pid, $status); }
        throw $error;
    }
}

function booking_test_has_overlap(mysqli $conn, int $venueId, string $category, string $start, string $end): bool
{
    $sql = 'SELECT id FROM bookings WHERE venue_id=? AND ' . booking_overlap_sql($category) . ' LIMIT 1';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iss', $venueId, $end, $start);
    $stmt->execute();
    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $found;
}

try {
    $admin = booking_test_connect($socketPath, 'mysql');
    $status = $admin->query("SHOW VARIABLES LIKE 'skip_networking'")->fetch_assoc();
    $datadir = $admin->query('SELECT @@datadir AS value')->fetch_assoc()['value'] ?? '';
    if (strtoupper((string)($status['Value'] ?? '')) !== 'ON' || !str_starts_with(realpath($datadir) . DIRECTORY_SEPARATOR, $temporaryRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('The MariaDB server is not the requested socket-only temporary instance.');
    }
    booking_test_assert_query($admin, "CREATE DATABASE `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $conn = booking_test_connect($socketPath, $databaseName);
    booking_test_create_schema($conn);

    $conn->query("INSERT INTO venues (id,name,category,status) VALUES
        (40,'South Hall A','Event Hall','Available'),(41,'South Hall B','Event Hall','Available'),
        (50,'Central Hall A','Event Hall','Available'),(51,'Central Hall B','Event Hall','Available'),
        (200,'North Hall A','Event Hall','Available'),(201,'North Hall B','Event Hall','Available'),
        (400,'Shared Reschedule Hall','Event Hall','Available'),(700,'Seminar Mutex Hall','Event Hall','Available'),
        (100,'North Hotel','Hotel Room','Available'),(101,'North Hotel','Hotel Room','Available'),
        (300,'South Hotel','Hotel Room','Available'),(301,'South Hotel','Hotel Room','Available'),
        (600,'Boundary Hotel','Hotel Room','Available'),(601,'Boundary Villa','Resort Villa','Available'),(602,'Boundary Hall','Event Hall','Available')");
    $conn->query("INSERT INTO hotel_rooms (venue_id,room_type,nightly_rate) VALUES
        (100,'Standard',500),(101,'Standard',500),(300,'Standard',550),(301,'Standard',550),(600,'Standard',500)");
    $conn->query("INSERT INTO bookings (id,venue_id,start_date,end_date,booking_status,source) VALUES
        (1,50,'2030-06-01','2030-06-02','Pending','Online'),(2,50,'2030-06-01','2030-06-02','Pending','Online'),
        (3,200,'2030-06-10','2030-06-11','Pending','Online'),(4,201,'2030-06-10','2030-06-11','Pending','Online'),
        (5,40,'2030-06-20','2030-06-21','Pending','Online'),(6,41,'2030-06-20','2030-06-21','Pending','Online'),
        (20,400,'2030-06-01','2030-06-02','Confirmed','Online'),(21,400,'2030-06-10','2030-06-11','Confirmed','Online'),
        (90,600,'2030-10-01','2030-10-02','Confirmed','Online'),
        (91,602,'2030-10-10','2030-10-10','Confirmed','Online'),
        (92,601,'2030-10-20','2030-10-20','Confirmed','Online')");
    $conn->query("INSERT INTO booking_rooms (booking_id,venue_id,start_date,end_date,nights,line_total) VALUES
        (3,100,'2030-06-10','2030-06-11',1,0),(4,101,'2030-06-10','2030-06-11',1,0),
        (5,300,'2030-06-20','2030-06-21',1,0),(6,301,'2030-06-20','2030-06-21',1,0)");
    $conn->query("INSERT INTO seminars (id,status) VALUES (700,'draft')");
    $conn->query("INSERT INTO seminar_reservations (seminar_id,venue_id,resource_kind,start_date,end_date) VALUES (700,700,'hall','2030-08-01','2030-08-02')");

    $conn->query("INSERT INTO booking_locks (session_id,venue_id,start_date,end_date,expires_at) VALUES
        ('owner-session',600,'2030-11-01','2030-11-02',DATE_ADD(NOW(),INTERVAL 10 MINUTE)),
        ('foreign-session',600,'2030-11-01','2030-11-02',DATE_ADD(NOW(),INTERVAL 10 MINUTE)),
        ('owner-session',600,'2030-11-03','2030-11-04',DATE_SUB(NOW(),INTERVAL 1 MINUTE))");

    $highPlanA = event_hall_addon_reallocation_plan($conn, 3);
    $highPlanB = event_hall_addon_reallocation_plan($conn, 4);
    $highPoolA = event_hall_addon_candidate_venue_ids($conn, $highPlanA);
    $highPoolB = event_hall_addon_candidate_venue_ids($conn, $highPlanB);
    $lowPlanA = event_hall_addon_reallocation_plan($conn, 5);
    $lowPlanB = event_hall_addon_reallocation_plan($conn, 6);
    $lowPoolA = event_hall_addon_candidate_venue_ids($conn, $lowPlanA);
    $lowPoolB = event_hall_addon_candidate_venue_ids($conn, $lowPlanB);
    booking_test_check('addon contention fixtures share complete A/B pools on both sides of the hall IDs',
        $highPoolA === [100, 101] && $highPoolA === $highPoolB
        && $lowPoolA === [300, 301] && $lowPoolA === $lowPoolB);

    $version = $conn->query('SELECT VERSION() AS version')->fetch_assoc()['version'] ?? 'unknown';
    fwrite(STDOUT, "Isolated engine: {$version}\n");

    booking_begin_mutation_transaction($conn);
    $conn->query('SELECT id FROM venues WHERE id=50 FOR UPDATE')->free();
    $transactionActive = (int)($conn->query('SELECT @@in_transaction AS active')->fetch_assoc()['active'] ?? 0);
    $transactionIsolation = $conn->query('SELECT trx_isolation_level FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=CONNECTION_ID()')->fetch_assoc()['trx_isolation_level'] ?? '';
    $conn->rollback();
    booking_test_check('mutation helper opens an active READ COMMITTED transaction', $transactionActive === 1 && $transactionIsolation === 'READ COMMITTED');

    $conn->close();
    $conn = null;
    $admin->close();
    $admin = null;

    $sameHall = booking_test_run_staged_pair($socketPath, $databaseName,
        fn($socket, $db, $channel) => booking_test_finalize_worker($socket, $db, 1, $channel, 'holder'),
        fn($socket, $db, $channel) => booking_test_finalize_worker($socket, $db, 2, $channel, 'waiter'));
    $sameHallResults = array_map(static fn($row) => $row['result'] ?? 'error', $sameHall['results']);
    $conn = booking_test_connect($socketPath, $databaseName);
    $confirmed = (int)$conn->query("SELECT COUNT(*) AS n FROM bookings WHERE id IN (1,2) AND booking_status='Confirmed'")->fetch_assoc()['n'];
    booking_test_check('forced same-hall invoice finalization wait observes the first commit and only one confirms', $sameHall['holder_active'] && $sameHall['wait_observed']
        && count(array_filter($sameHallResults, static fn($result) => $result === 'confirmed')) === 1
        && count(array_filter($sameHallResults, static fn($result) => $result === 'conflict')) === 1 && $confirmed === 1);

    $conn->close();
    $conn = null;
    $highHallRun = booking_test_run_staged_pair($socketPath, $databaseName,
        fn($socket, $db, $channel) => booking_test_finalize_worker($socket, $db, 3, $channel, 'holder'),
        fn($socket, $db, $channel) => booking_test_finalize_worker($socket, $db, 4, $channel, 'waiter'));
    $highHallIds = $highHallRun['results'];
    foreach ($highHallIds as $workerResult) if (isset($workerResult['error'])) fwrite(STDERR, 'Worker error: ' . $workerResult['error'] . ' ' . ($workerResult['message'] ?? '') . "\n");
    $conn = booking_test_connect($socketPath, $databaseName);
    $highAllocations = $conn->query('SELECT venue_id FROM booking_rooms WHERE booking_id IN (3,4) ORDER BY booking_id')->fetch_all(MYSQLI_ASSOC);
    booking_test_check('forced addon shared-pool wait with hall IDs above candidates uses the full sorted union', $highHallRun['holder_active'] && $highHallRun['wait_observed'] && count($highAllocations) === 2
        && (int)$highAllocations[0]['venue_id'] !== (int)$highAllocations[1]['venue_id']
        && count(array_filter($highHallIds, static fn($row) => ($row['result'] ?? '') === 'confirmed')) === 2);

    $conn->close();
    $conn = null;
    $lowHallRun = booking_test_run_staged_pair($socketPath, $databaseName,
        fn($socket, $db, $channel) => booking_test_finalize_worker($socket, $db, 5, $channel, 'holder'),
        fn($socket, $db, $channel) => booking_test_finalize_worker($socket, $db, 6, $channel, 'waiter'));
    $lowHallIds = $lowHallRun['results'];
    $conn = booking_test_connect($socketPath, $databaseName);
    $lowAllocations = $conn->query('SELECT venue_id FROM booking_rooms WHERE booking_id IN (5,6) ORDER BY booking_id')->fetch_all(MYSQLI_ASSOC);
    booking_test_check('forced addon shared-pool wait with hall IDs below candidates uses the full sorted union', $lowHallRun['holder_active'] && $lowHallRun['wait_observed'] && count($lowAllocations) === 2
        && (int)$lowAllocations[0]['venue_id'] !== (int)$lowAllocations[1]['venue_id']
        && count(array_filter($lowHallIds, static fn($row) => ($row['result'] ?? '') === 'confirmed')) === 2);

    $conn->close();
    $conn = booking_test_connect($socketPath, $databaseName);
    booking_test_assert_query($conn, 'ALTER TABLE hotel_rooms ADD room_group_id INT NULL');
    booking_test_assert_query($conn, 'ALTER TABLE booking_rooms ADD room_group_id INT NULL');
    booking_test_assert_query($conn, 'CREATE TABLE hotel_room_groups (id INT PRIMARY KEY, room_type_code VARCHAR(40), building_name VARCHAR(100), legacy_room_type VARCHAR(80)) ENGINE=InnoDB');
    booking_test_assert_query($conn, 'CREATE TABLE hotel_room_types (type_code VARCHAR(40) PRIMARY KEY, display_name VARCHAR(80)) ENGINE=InnoDB');
    booking_test_assert_query($conn, "INSERT INTO hotel_room_types VALUES ('standard_room','Standard Room')");
    booking_test_assert_query($conn, "INSERT INTO hotel_room_groups VALUES (9,'standard_room','Grouped Hotel','Standard Room')");
    booking_test_assert_query($conn, "INSERT INTO venues (id,name,category,status) VALUES (900,'Grouped Hall A','Event Hall','Available'),(901,'Grouped Hall B','Event Hall','Available'),(800,'Grouped Hotel','Hotel Room','Available'),(801,'Grouped Hotel','Hotel Room','Available')");
    booking_test_assert_query($conn, "INSERT INTO hotel_rooms (venue_id,room_type,nightly_rate,room_group_id) VALUES (800,'Standard Room',650,9),(801,'Standard Room',650,9)");
    booking_test_assert_query($conn, "INSERT INTO bookings (id,venue_id,start_date,end_date,booking_status,source) VALUES (7,900,'2030-07-10','2030-07-11','Pending','Online'),(8,901,'2030-07-10','2030-07-11','Pending','Online')");
    booking_test_assert_query($conn, "INSERT INTO booking_rooms (booking_id,venue_id,room_group_id,nightly_rate,start_date,end_date,nights,line_total) VALUES (7,800,9,650,'2030-07-10','2030-07-11',1,0),(8,801,9,650,'2030-07-10','2030-07-11',1,0)");
    $groupPlanA = event_hall_addon_reallocation_plan($conn, 7);
    $groupPlanB = event_hall_addon_reallocation_plan($conn, 8);
    booking_test_check('grouped hotel schema resolves group 9 to the same complete candidate pool', !empty($groupPlanA['groups_schema_ready'])
        && count($groupPlanA['groups'] ?? []) === 1 && event_hall_addon_candidate_venue_ids($conn, $groupPlanA) === [800, 801]
        && event_hall_addon_candidate_venue_ids($conn, $groupPlanB) === [800, 801]);

    $conn->close();
    $conn = null;
    $groupedRun = booking_test_run_staged_pair($socketPath, $databaseName,
        fn($socket, $db, $channel) => booking_test_finalize_worker($socket, $db, 7, $channel, 'holder'),
        fn($socket, $db, $channel) => booking_test_finalize_worker($socket, $db, 8, $channel, 'waiter'));
    $conn = booking_test_connect($socketPath, $databaseName);
    $groupedAllocations = $conn->query('SELECT venue_id FROM booking_rooms WHERE booking_id IN (7,8) ORDER BY booking_id')->fetch_all(MYSQLI_ASSOC);
    booking_test_check('grouped hotel add-on contenders wait on the prelocked pool and allocate distinct rooms', $groupedRun['holder_active'] && $groupedRun['wait_observed']
        && count($groupedAllocations) === 2 && (int)$groupedAllocations[0]['venue_id'] !== (int)$groupedAllocations[1]['venue_id']
        && count(array_filter($groupedRun['results'], static fn($row) => ($row['result'] ?? '') === 'confirmed')) === 2);

    $conn->close();
    $conn = null;
    $reschedules = booking_test_run_staged_pair($socketPath, $databaseName,
        fn($socket, $db, $channel) => booking_test_reschedule_worker($socket, $db, 20, '2031-07-10', '2031-07-11', $channel, 'holder'),
        fn($socket, $db, $channel) => booking_test_reschedule_worker($socket, $db, 21, '2031-07-10', '2031-07-11', $channel, 'waiter'));
    $conn = booking_test_connect($socketPath, $databaseName);
    booking_test_check('forced Event Hall reschedule wait sees the first commit and rejects the overlap', $reschedules['holder_active'] && $reschedules['wait_observed']
        && count(array_filter($reschedules['results'], static fn($row) => ($row['result'] ?? '') === 'rescheduled')) === 1
        && count(array_filter($reschedules['results'], static fn($row) => ($row['result'] ?? '') === 'conflict')) === 1);

    $seminarWriter = booking_test_connect($socketPath, $databaseName);
    $seminarWriter->begin_transaction();
    $seminarLock = $seminarWriter->query("SELECT id FROM seminars WHERE id=700 FOR UPDATE");
    $seminarLock->free();
    booking_begin_mutation_transaction($conn);
    booking_lock_venues_in_order($conn, [700]);
    $seminarConflict = seminar_has_resource_conflict($conn, 700, '2030-08-01', '2030-08-02', null, false);
    $conn->commit();
    $seminarWriter->query('SELECT id FROM venues WHERE id=700 FOR UPDATE')->free();
    $seminarWriter->rollback();
    $seminarWriter->close();
    booking_test_check('booking occupancy read stays fresh without locking a seminar row held by its writer', $seminarConflict);

    booking_test_check('hotel checkout can equal the next guest check-in boundary', !booking_test_has_overlap($conn, 600, 'Hotel Room', '2030-10-02', '2030-10-03'));
    booking_test_check('hotel intervals still reject a true overlap', booking_test_has_overlap($conn, 600, 'Hotel Room', '2030-10-01', '2030-10-03'));
    booking_test_check('Event Hall same-day boundary remains inclusive', booking_test_has_overlap($conn, 602, 'Event Hall', '2030-10-10', '2030-10-11'));
    booking_test_check('Villa same-day service date remains inclusive', booking_test_has_overlap($conn, 601, 'Resort Villa', '2030-10-20', '2030-10-21'));

    $holdStmt = $conn->prepare('SELECT id FROM booking_locks WHERE session_id=? AND venue_id=600 AND start_date=? AND end_date=? AND expires_at>NOW() LIMIT 1');
    $sessionId = 'owner-session'; $holdStart = '2030-11-01'; $holdEnd = '2030-11-02';
    $holdStmt->bind_param('sss', $sessionId, $holdStart, $holdEnd); $holdStmt->execute();
    $ownedActive = $holdStmt->get_result()->num_rows === 1;
    $sessionId = 'foreign-session'; $holdStmt->execute(); $foreignActive = $holdStmt->get_result()->num_rows === 1;
    $sessionId = 'owner-session'; $holdStart = '2030-11-03'; $holdEnd = '2030-11-04'; $holdStmt->execute(); $expiredOwned = $holdStmt->get_result()->num_rows === 1;
    $holdStmt->close();
    booking_test_check('active holds remain session-owned and expired holds no longer qualify', $ownedActive && $foreignActive && !$expiredOwned);

    $conn->close();
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR ' . get_class($error) . ' ' . substr($error->getMessage(), 0, 240) . "\n");
    $failures[] = 'isolated concurrency harness setup or execution';
} finally {
    if ($admin instanceof mysqli) {
        try { $admin->query("DROP DATABASE IF EXISTS `{$databaseName}`"); } catch (Throwable $ignored) {}
        $admin->close();
    } elseif ($socketPath !== false && file_exists($socketPath)) {
        try {
            $cleanupConnection = booking_test_connect($socketPath, 'mysql');
            $cleanupConnection->query("DROP DATABASE IF EXISTS `{$databaseName}`");
            $cleanupConnection->close();
        } catch (Throwable $ignored) {}
    }
}

fwrite(STDOUT, "Checks: {$checks}; failures: " . count($failures) . "\n");
if ($failures) exit(1);
