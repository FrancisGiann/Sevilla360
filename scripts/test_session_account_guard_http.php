<?php
// CLI-only authenticated HTTP integration test. It uses a disposable MariaDB
// database and PHP's local server; it never reads or writes the app database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 3) {
    fwrite(STDERR, "Usage: php scripts/test_session_account_guard_http.php <isolated-socket> <isolated-temp-root>\n");
    exit(2);
}

$socketPath = realpath($argv[1]);
$temporaryRoot = realpath($argv[2]);
$requiredPrefix = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sevilla360-session-account.';
if ($socketPath === false || $temporaryRoot === false
    || !str_starts_with($temporaryRoot . DIRECTORY_SEPARATOR, $requiredPrefix)
    || !str_starts_with($socketPath, $temporaryRoot . DIRECTORY_SEPARATOR)
    || !file_exists($socketPath)) {
    fwrite(STDERR, "Refusing to run outside a dedicated temporary MariaDB socket and datadir.\n");
    exit(2);
}

$projectRoot = dirname(__DIR__);
$databaseName = 'sevilla360_account_test_' . bin2hex(random_bytes(5));
$failureDatabaseName = $databaseName . '_fail';
$sessionPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'sessions-' . bin2hex(random_bytes(4));
if (!mkdir($sessionPath, 0700) && !is_dir($sessionPath)) throw new RuntimeException('Unable to create isolated session directory.');
register_shutdown_function(static function () use ($sessionPath): void {
    foreach (glob($sessionPath . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $sessionFile) {
        if (is_file($sessionFile)) @unlink($sessionFile);
    }
    @rmdir($sessionPath);
});
$db = null;
$serverProcesses = [];
$checks = 0;
$failures = [];

function account_guard_check(string $label, bool $condition): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) $failures[] = $label;
    fwrite(STDOUT, ($condition ? 'PASS ' : 'FAIL ') . $label . "\n");
}

function account_guard_connect(string $socket, ?string $database = null): mysqli
{
    $connection = new mysqli('localhost', 'root', '', $database, 0, $socket);
    $connection->set_charset('utf8mb4');
    return $connection;
}

function account_guard_session(string $directory, string $role, int $userId, bool $withCsrf = true): array
{
    $sessionId = 'g' . bin2hex(random_bytes(12));
    $csrf = bin2hex(random_bytes(24));
    ini_set('session.save_path', $directory);
    ini_set('session.use_strict_mode', '0');
    session_id($sessionId);
    session_start();
    $_SESSION = [
        'logged_in' => true,
        'user_id' => $userId,
        'role' => $role,
        'csrf_token' => $withCsrf ? $csrf : bin2hex(random_bytes(24)),
        'auth_started_at' => time(),
        'last_user_activity' => time(),
        'last_activity' => time(),
    ];
    session_write_close();
    return ['id' => $sessionId, 'csrf' => $withCsrf ? $csrf : ''];
}

function account_guard_request(int $port, string $path, array $session, array $body, ?string $csrfOverride = null): array
{
    $headers = [
        'Content-Type: application/json',
        'Cookie: PHPSESSID=' . $session['id'],
    ];
    $csrf = $csrfOverride ?? ($session['csrf'] ?? '');
    if ($csrf !== '') $headers[] = 'X-CSRF-TOKEN: ' . $csrf;
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => json_encode($body, JSON_THROW_ON_ERROR),
        'ignore_errors' => true,
        'timeout' => 5,
    ]]);
    $response = file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/\AHTTP\/\S+\s+(\d{3})/', $header, $match)) $status = (int)$match[1];
    }
    return ['status' => $status, 'body' => is_string($response) ? $response : ''];
}

function account_guard_multipart_request(int $port, string $path, array $session, array $fields, string $filename, string $fileBytes): array
{
    $boundary = '----------------sevilla' . bin2hex(random_bytes(12));
    $body = '';
    foreach ($fields as $name => $value) {
        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
        $body .= $value . "\r\n";
    }
    $body .= "--{$boundary}\r\n";
    $body .= 'Content-Disposition: form-data; name="fileInput[]"; filename="' . $filename . '"' . "\r\n";
    $body .= "Content-Type: image/png\r\n\r\n" . $fileBytes . "\r\n";
    $body .= "--{$boundary}--\r\n";
    $headers = [
        'Content-Type: multipart/form-data; boundary=' . $boundary,
        'Cookie: PHPSESSID=' . $session['id'],
        'X-CSRF-TOKEN: ' . ($session['csrf'] ?? ''),
        'Content-Length: ' . strlen($body),
    ];
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 5,
    ]]);
    $response = file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/\AHTTP\/\S+\s+(\d{3})/', $header, $match)) $status = (int)$match[1];
    }
    return ['status' => $status, 'body' => is_string($response) ? $response : ''];
}

function account_guard_anonymous_session(string $directory): array
{
    $sessionId = 'g' . bin2hex(random_bytes(12));
    $csrf = bin2hex(random_bytes(24));
    ini_set('session.save_path', $directory);
    ini_set('session.use_strict_mode', '0');
    session_id($sessionId);
    session_start();
    $_SESSION = ['csrf_token' => $csrf];
    session_write_close();
    return ['id' => $sessionId, 'csrf' => $csrf];
}

function account_guard_form_request(int $port, string $path, array $session, array $fields): array
{
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nCookie: PHPSESSID={$session['id']}",
        'content' => http_build_query($fields),
        'ignore_errors' => true,
        'follow_location' => 0,
        'max_redirects' => 0,
        'timeout' => 5,
    ]]);
    $response = file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
    $status = 0;
    $newSessionId = null;
    $location = null;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/\AHTTP\/\S+\s+(\d{3})/', $header, $match)) $status = (int)$match[1];
        if (preg_match('/\ASet-Cookie:\s*PHPSESSID=([^;]+)/i', $header, $match)) $newSessionId = $match[1];
        if (preg_match('/\ALocation:\s*(.+)\z/i', $header, $match)) $location = trim($match[1]);
    }
    return ['status' => $status, 'body' => is_string($response) ? $response : '', 'session_id' => $newSessionId, 'location' => $location];
}

function account_guard_read_session(string $directory, string $sessionId): array
{
    ini_set('session.save_path', $directory);
    ini_set('session.use_strict_mode', '0');
    session_id($sessionId);
    session_start();
    $values = $_SESSION;
    session_write_close();
    return $values;
}

function account_guard_start_server(string $socket, string $sessions, string $projectRoot, string $database, string $logPath): array
{
    $reservation = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$reservation) throw new RuntimeException('Unable to reserve a local test port.');
    $address = stream_socket_get_name($reservation, false);
    fclose($reservation);
    $port = (int)substr(strrchr((string)$address, ':'), 1);
    $command = [
        PHP_BINARY,
        '-d', 'variables_order=EGPCS',
        '-d', 'session.save_path=' . $sessions,
        '-d', 'mysqli.default_socket=' . $socket,
        '-S', '127.0.0.1:' . $port,
        '-t', $projectRoot,
    ];
    $environment = [
        'PATH' => (string)getenv('PATH'),
        'DB_HOST' => 'localhost',
        // The application loads its project .env when a variable is absent.
        // Use explicit disposable credentials so this local HTTP process can
        // never fall through to the developer's configured database account.
        'DB_USER' => 'sevilla_guard_test',
        'DB_PASS' => 'guard-test-only',
        'DB_NAME' => $database,
        'BACKUP_DIR' => '',
        // An unauthorized upload should be rejected before size validation.
        'MEDIA_MAX_IMAGE_BYTES' => '1',
    ];
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']], $pipes, $projectRoot, $environment);
    if (!is_resource($process)) throw new RuntimeException('Unable to start the isolated PHP server.');
    if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $connectError, $connectMessage, 0.1);
        if ($probe) {
            fclose($probe);
            return ['process' => $process, 'port' => $port];
        }
        usleep(100000);
    }
    proc_terminate($process);
    proc_close($process);
    throw new RuntimeException('The isolated PHP server did not become ready.');
}

function account_guard_stop_server($process): void
{
    if (!is_resource($process)) return;
    proc_terminate($process);
    proc_close($process);
}

try {
    $db = account_guard_connect($socketPath);
    $db->query("CREATE DATABASE `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->query("CREATE DATABASE `{$failureDatabaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->query("DROP USER IF EXISTS 'sevilla_guard_test'@'localhost'");
    $db->query("CREATE USER 'sevilla_guard_test'@'localhost' IDENTIFIED BY 'guard-test-only'");
    $db->query("GRANT ALL PRIVILEGES ON `{$databaseName}`.* TO 'sevilla_guard_test'@'localhost'");
    $db->query("GRANT ALL PRIVILEGES ON `{$failureDatabaseName}`.* TO 'sevilla_guard_test'@'localhost'");
    $db->select_db($databaseName);
    $schema = [
        "CREATE TABLE users (id INT PRIMARY KEY, role VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, email VARCHAR(190) NOT NULL, is_verified TINYINT NOT NULL DEFAULT 1, password_hash VARCHAR(255) NOT NULL DEFAULT '') ENGINE=InnoDB",
        "CREATE TABLE staff (user_id INT PRIMARY KEY, status VARCHAR(20) NOT NULL, full_name VARCHAR(100) NOT NULL DEFAULT '') ENGINE=InnoDB",
        "CREATE TABLE customers (user_id INT PRIMARY KEY, first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL, phone VARCHAR(40) NULL, special_req TEXT NULL) ENGINE=InnoDB",
        "CREATE TABLE venues (id INT PRIMARY KEY, name VARCHAR(100) NOT NULL, category VARCHAR(40) NOT NULL, status VARCHAR(20) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE bookings (id INT PRIMARY KEY AUTO_INCREMENT, source VARCHAR(30) NOT NULL DEFAULT 'Online', venue_id INT NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE maintenance (id INT PRIMARY KEY, venue_id INT NOT NULL, is_blocking TINYINT NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'Scheduled', start_date DATE NOT NULL, end_date DATE NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE audit_logs (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT NOT NULL, module VARCHAR(80) NOT NULL, action VARCHAR(255) NOT NULL, ip_address VARCHAR(64) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE showroom_hotspots (id INT PRIMARY KEY, media_id INT NOT NULL DEFAULT 1) ENGINE=InnoDB",
        "CREATE TABLE rate_limits (id INT PRIMARY KEY AUTO_INCREMENT, ip_address VARCHAR(64) NOT NULL, action_name VARCHAR(80) NOT NULL, attempts INT NOT NULL DEFAULT 0, first_attempt DATETIME NOT NULL, last_attempt DATETIME NOT NULL, UNIQUE KEY unique_rate_limit (ip_address, action_name)) ENGINE=InnoDB",
    ];
    foreach ($schema as $statement) $db->query($statement);
    $adminPasswordHash = password_hash('Guard-Test!123', PASSWORD_DEFAULT);
    $customerPasswordHash = password_hash('Guard-Test!123', PASSWORD_DEFAULT);
    $adminPasswordHash = $db->real_escape_string($adminPasswordHash);
    $customerPasswordHash = $db->real_escape_string($customerPasswordHash);
    $db->query("INSERT INTO users (id,role,status,email,is_verified,password_hash) VALUES
        (1,'staff','active','staff@example.test',1,''),
        (2,'admin','active','admin@example.test',1,'{$adminPasswordHash}'),
        (3,'customer','active','customer@example.test',1,'{$customerPasswordHash}'),
        (4,'staff','active','rolechange@example.test',1,'')");
    $db->query("INSERT INTO staff (user_id,status,full_name) VALUES (1,'active','Test Staff'),(2,'active','Test Admin'),(4,'active','Role Change')");
    $db->query("INSERT INTO customers (user_id,first_name,last_name,phone) VALUES (3,'Before','Customer','555'),(4,'Role','Mismatch','555')");
    $db->query("INSERT INTO venues (id,name,category,status) VALUES (1,'Fixture Room','Hotel Room','Available')");
    $db->query("INSERT INTO maintenance (id,venue_id,is_blocking,status,start_date,end_date) VALUES (101,1,0,'Scheduled','2030-01-01','2030-01-01'),(102,1,0,'Scheduled','2030-01-02','2030-01-02')");
    $db->query("INSERT INTO showroom_hotspots (id) VALUES (201),(202),(203)");

    $server = account_guard_start_server($socketPath, $sessionPath, $projectRoot, $databaseName, $temporaryRoot . '/php-server.log');
    $serverProcesses[] = $server['process'];
    $port = $server['port'];
    $staffSession = account_guard_session($sessionPath, 'staff', 1);
    $customerSession = account_guard_session($sessionPath, 'customer', 3);
    $adminSession = account_guard_session($sessionPath, 'admin', 2);
    $roleChangeSession = account_guard_session($sessionPath, 'staff', 4);

    foreach ([
        ['admin@example.test', 'admin_dashboard.php', 'admin', 2],
        ['customer@example.test', 'user_dashboard.php', 'customer', 3],
    ] as [$email, $destination, $expectedRole, $expectedId]) {
        $loginSession = account_guard_anonymous_session($sessionPath);
        $loginResult = account_guard_form_request($port, '/actions/auth/login_process.php', $loginSession, [
            'csrf_token' => $loginSession['csrf'],
            'email' => $email,
            'password' => 'Guard-Test!123',
            // The current single sign-in form submits this value for every
            // account; server-side role controls access and redirect.
            'login_type' => 'customer',
        ]);
        $sessionValues = is_string($loginResult['session_id'])
            ? account_guard_read_session($sessionPath, $loginResult['session_id'])
            : [];
        account_guard_check("{$expectedRole} credentials authenticate from the current sign-in form", $loginResult['status'] === 302
            && is_string($loginResult['location']) && str_contains($loginResult['location'], $destination)
            && (int)($sessionValues['user_id'] ?? 0) === $expectedId
            && ($sessionValues['role'] ?? null) === $expectedRole);
    }

    $result = account_guard_request($port, '/actions/admin/delete_maintenance.php', $staffSession, ['id' => 101]);
    account_guard_check('active staff session can perform its authorized maintenance mutation', $result['status'] === 200 && str_contains($result['body'], '"success":true'));
    account_guard_check('active staff mutation changed only its fixture row', (int)$db->query('SELECT COUNT(*) AS c FROM maintenance WHERE id=101')->fetch_assoc()['c'] === 0);

    $db->query("UPDATE staff SET status='inactive' WHERE user_id=1");
    $result = account_guard_request($port, '/actions/admin/delete_maintenance.php', $staffSession, ['id' => 102]);
    account_guard_check('archived staff session is rejected with 401', $result['status'] === 401 && str_contains($result['body'], 'Unauthorized access.'));
    account_guard_check('archived staff request does not mutate its target', (int)$db->query('SELECT COUNT(*) AS c FROM maintenance WHERE id=102')->fetch_assoc()['c'] === 1);

    $result = account_guard_request($port, '/actions/user/save_settings.php', $customerSession, ['action' => 'update_profile', 'fname' => 'Active', 'lname' => 'Customer', 'phone' => '555']);
    account_guard_check('active customer session can update its own profile', $result['status'] === 200 && str_contains($result['body'], '"success":true')
        && (string)$db->query('SELECT first_name FROM customers WHERE user_id=3')->fetch_assoc()['first_name'] === 'Active');
    $db->query("UPDATE users SET status='suspended' WHERE id=3");
    $result = account_guard_request($port, '/actions/user/save_settings.php', $customerSession, ['action' => 'update_profile', 'fname' => 'Blocked', 'lname' => 'Customer', 'phone' => '999']);
    account_guard_check('suspended customer session is rejected with 401', $result['status'] === 401 && str_contains($result['body'], 'Unauthorized access.'));
    account_guard_check('suspended customer request cannot change profile data', (string)$db->query('SELECT first_name FROM customers WHERE user_id=3')->fetch_assoc()['first_name'] === 'Active');

    $result = account_guard_request($port, '/actions/admin/delete_hotspot.php', $adminSession, ['id' => 201]);
    account_guard_check('active admin session can perform its authorized CMS mutation', $result['status'] === 200 && str_contains($result['body'], '"success":true')
        && (int)$db->query('SELECT COUNT(*) AS c FROM showroom_hotspots WHERE id=201')->fetch_assoc()['c'] === 0);
    $db->query("UPDATE staff SET status='inactive' WHERE user_id=2");
    $result = account_guard_request($port, '/actions/admin/delete_hotspot.php', $adminSession, ['id' => 202]);
    account_guard_check('inactive admin session is rejected with 401', $result['status'] === 401 && str_contains($result['body'], 'Unauthorized access.'));
    account_guard_check('inactive admin request cannot delete its target', (int)$db->query('SELECT COUNT(*) AS c FROM showroom_hotspots WHERE id=202')->fetch_assoc()['c'] === 1);

    $inactiveAdminAuditSession = account_guard_session($sessionPath, 'admin', 2);
    $result = account_guard_request($port, '/actions/admin/get_audit_logs.php', $inactiveAdminAuditSession, ['page' => 1]);
    account_guard_check('revoked audit request preserves its existing 403 status', $result['status'] === 403 && str_contains($result['body'], 'Unauthorized access.'));

    $uploadsDirectory = $projectRoot . '/assets/uploads';
    $beforeUploadFiles = is_dir($uploadsDirectory) ? scandir($uploadsDirectory) : [];
    $inactiveAdminUploadSession = account_guard_session($sessionPath, 'admin', 2);
    $validTinyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/zucAAAAASUVORK5CYII=', true);
    $result = account_guard_multipart_request(
        $port,
        '/actions/admin/upload_media.php',
        $inactiveAdminUploadSession,
        ['media_type' => 'standard', 'website_slot' => 'home-about'],
        'account-guard-probe.png',
        is_string($validTinyPng) ? $validTinyPng : ''
    );
    account_guard_check('inactive admin upload is rejected before image validation', $result['status'] === 401 && str_contains($result['body'], 'Unauthorized access.'));
    $afterUploadFiles = is_dir($uploadsDirectory) ? scandir($uploadsDirectory) : [];
    account_guard_check('rejected upload leaves the application upload directory unchanged', $beforeUploadFiles === $afterUploadFiles);

    $db->query("UPDATE users SET role='customer' WHERE id=4");
    $result = account_guard_request($port, '/actions/admin/delete_maintenance.php', $roleChangeSession, ['id' => 102]);
    account_guard_check('role change after sign-in is rejected before mutation', $result['status'] === 401
        && (int)$db->query('SELECT COUNT(*) AS c FROM maintenance WHERE id=102')->fetch_assoc()['c'] === 1);
    $db->query("UPDATE staff SET status='active' WHERE user_id=2");
    $csrfAdminSession = account_guard_session($sessionPath, 'admin', 2);
    $result = account_guard_request($port, '/actions/admin/delete_hotspot.php', $csrfAdminSession, ['id' => 203], 'wrong-token');
    account_guard_check('existing CSRF guard still rejects invalid tokens before account revalidation', $result['status'] === 403
        && (int)$db->query('SELECT COUNT(*) AS c FROM showroom_hotspots WHERE id=203')->fetch_assoc()['c'] === 1);

    account_guard_stop_server($server['process']);
    $serverProcesses = [];
    $failureServer = account_guard_start_server($socketPath, $sessionPath, $projectRoot, $failureDatabaseName, $temporaryRoot . '/php-failure-server.log');
    $serverProcesses[] = $failureServer['process'];
    $failureSession = account_guard_session($sessionPath, 'admin', 2);
    $result = account_guard_request($failureServer['port'], '/actions/admin/delete_hotspot.php', $failureSession, ['id' => 203]);
    account_guard_check('account-table lookup failure returns 503 and fails closed', $result['status'] === 503
        && str_contains($result['body'], 'Account status could not be verified.'));

    fwrite(STDOUT, "{$checks} checks: " . (count($failures) === 0 ? 'PASS' : 'FAIL') . "\n");
    if ($failures) {
        foreach ($failures as $failure) fwrite(STDERR, " - {$failure}\n");
        exit(1);
    }
} finally {
    foreach ($serverProcesses as $process) account_guard_stop_server($process);
    if ($db instanceof mysqli) {
        $db->query("DROP DATABASE IF EXISTS `{$failureDatabaseName}`");
        $db->query("DROP DATABASE IF EXISTS `{$databaseName}`");
        $db->query("DROP USER IF EXISTS 'sevilla_guard_test'@'localhost'");
        $db->close();
    }
}
