<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$tempDir = sys_get_temp_dir() . '/sevilla-suspend-endpoint-' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0700)) throw new RuntimeException('Unable to create temporary endpoint-test directory.');

$server = null;
$sessionId = 'suspensioncontract' . bin2hex(random_bytes(8));
$sessionFile = $tempDir . '/sess_' . $sessionId;
$preloadFile = $tempDir . '/preload.php';
$serverLog = $tempDir . '/server.log';
$cleanup = static function () use (&$server, $tempDir, $sessionFile): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    @unlink($sessionFile);
    foreach (glob($tempDir . '/*') ?: [] as $path) @unlink($path);
    @rmdir($tempDir);
};

try {
    $preload = <<<'PHP'
<?php
$_ENV['BACKUP_DIR'] = '';
$_ENV['DB_HOST'] = '127.0.0.1';
$_ENV['DB_USER'] = 'invalid_contract_test_user';
$_ENV['DB_PASS'] = 'invalid_contract_test_password';
$_ENV['DB_NAME'] = 'missing_contract_test_database';
PHP;
    file_put_contents($preloadFile, $preload);

    ini_set('session.save_path', $tempDir);
    session_id($sessionId);
    if (!session_start()) throw new RuntimeException('Unable to start test session.');
    $_SESSION = [
        'logged_in' => true,
        'role' => 'admin',
        'user_id' => 987654,
        'csrf_token' => 'suspension-endpoint-test-csrf',
        'auth_started_at' => time(),
        'last_user_activity' => time(),
    ];
    session_write_close();

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errorMessage);
    if (!$socket) throw new RuntimeException('Unable to reserve an endpoint-test port.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int)substr(strrchr((string)$address, ':'), 1);

    $server = proc_open([
        PHP_BINARY,
        '-d', 'session.save_path=' . $tempDir,
        '-d', 'auto_prepend_file=' . $preloadFile,
        '-S', '127.0.0.1:' . $port,
        '-t', $projectRoot,
    ], [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']], $pipes, $projectRoot);
    if (!is_resource($server)) throw new RuntimeException('Unable to start the endpoint-test server.');
    fclose($pipes[0]);

    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @fsockopen('127.0.0.1', $port, $probeError, $probeMessage, 0.1);
        if (is_resource($probe)) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) throw new RuntimeException('Endpoint-test server did not start.');

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nX-CSRF-Token: suspension-endpoint-test-csrf\r\nCookie: " . session_name() . '=' . $sessionId . "\r\n",
        'content' => '{"user_id":true,"action":"active"}',
        'ignore_errors' => true,
        'timeout' => 4,
    ]]);
    $responseBody = file_get_contents('http://127.0.0.1:' . $port . '/actions/admin/suspend_user.php', false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('/\s(\d{3})\s/', $statusLine, $statusMatch)) throw new RuntimeException('Endpoint-test response had no HTTP status.');
    $response = is_string($responseBody) ? json_decode($responseBody, true) : null;
    if ((int)$statusMatch[1] !== 422 || !is_array($response) || ($response['success'] ?? true) !== false) {
        throw new RuntimeException('Authenticated endpoint should reject malformed IDs before opening a database connection.');
    }

    $endpoint = file_get_contents($projectRoot . '/actions/admin/suspend_user.php');
    if (!is_string($endpoint)
        || strpos($endpoint, 'customer_suspension_parse_request') === false
        || strpos($endpoint, 'customer_suspension_parse_request') > strpos($endpoint, 'config/db_connect.php')) {
        throw new RuntimeException('Request validation must precede the database include.');
    }

    echo "Customer suspension endpoint checks passed.\n";
} finally {
    $cleanup();
}
