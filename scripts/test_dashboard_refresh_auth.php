<?php
declare(strict_types=1);

/**
 * Read-only auth regression checks for both dashboard refresh endpoints.
 * The child process disables extensions and supplies a tiny fake mysqli
 * account result, so the checks never connect to or mutate an application DB.
 */

if (($argv[1] ?? '') === '--child') {
    $scenario = $argv[2] ?? '';
    $GLOBALS['qaAccount'] = match ($scenario) {
        'customer-inactive' => ['role' => 'customer', 'status' => 'inactive'],
        'customer-role-changed' => ['role' => 'staff', 'status' => 'active'],
        'admin-role-changed' => ['role' => 'admin', 'user_status' => 'active', 'staff_status' => 'active'],
        'admin-staff-inactive' => ['role' => 'staff', 'user_status' => 'active', 'staff_status' => 'inactive'],
        default => ['role' => '', 'status' => 'inactive'],
    };

    class mysqli
    {
        public string $connect_error = '';
        public function __construct(...$args) {}
        public function query($sql) { return true; }
        public function prepare($sql) { return new DashboardRefreshFakeStatement($GLOBALS['qaAccount']); }
    }

    class DashboardRefreshFakeStatement
    {
        private array $account;
        public function __construct(array $account) { $this->account = $account; }
        public function bind_param($types, &...$params): bool { return true; }
        public function execute(): bool { return true; }
        public function get_result(): DashboardRefreshFakeResult { return new DashboardRefreshFakeResult($this->account); }
        public function close(): void {}
    }

    class DashboardRefreshFakeResult
    {
        private array $account;
        public function __construct(array $account) { $this->account = $account; }
        public function fetch_assoc(): array { return $this->account; }
    }

    session_name('Sevilla360');
    session_start();
    $loggedIn = !str_starts_with($scenario, 'unauth-') && !str_starts_with($scenario, 'admin-unauth');
    $role = match ($scenario) {
        'customer-wrong-role' => 'staff',
        'admin-wrong-role' => 'customer',
        default => str_starts_with($scenario, 'admin-') ? 'staff' : 'customer',
    };
    $_SESSION = $loggedIn
        ? ['logged_in' => true, 'user_id' => 7001, 'role' => $role, 'csrf_token' => 'fixture-csrf']
        : [];
    $_SERVER['REQUEST_METHOD'] = str_contains($scenario, '-post') ? 'POST' : 'GET';
    $_SERVER['REQUEST_URI'] = str_starts_with($scenario, 'admin-')
        ? '/actions/admin/get_bookings_page.php'
        : '/actions/user/refresh_dashboard.php';
    if (!str_contains($scenario, '-csrf-bad')) $_SERVER['HTTP_X_CSRF_TOKEN'] = 'fixture-csrf';
    $_GET = str_contains($scenario, '-page-invalid') ? ['booking_page' => '0'] : [];
    $_POST = [];

    register_shutdown_function(static function (): void {
        $body = ob_get_contents();
        while (ob_get_level() > 0) ob_end_clean();
        $payload = json_decode((string)$body, true);
        echo json_encode([
            'http' => http_response_code(),
            'json' => is_array($payload),
            'success' => is_array($payload) ? ($payload['success'] ?? null) : null,
            'sessionExpired' => !($_SESSION['logged_in'] ?? false),
        ]);
    });
    ob_start();
    $root = dirname(__DIR__);
    if (str_starts_with($scenario, 'admin-')) {
        require $root . '/actions/admin/get_bookings_page.php';
    } else {
        require $root . '/actions/user/refresh_dashboard.php';
    }
    exit(2);
}

$root = dirname(__DIR__);
$sessionDir = sys_get_temp_dir() . '/sevilla360-dashboard-refresh-' . bin2hex(random_bytes(8));
if (!mkdir($sessionDir, 0700)) {
    fwrite(STDERR, "Unable to create isolated auth-test session directory.\n");
    exit(1);
}

$cases = [
    ['customer unauthenticated request is JSON 401', 'unauth-customer', 401, true],
    ['customer wrong role is JSON 401', 'customer-wrong-role', 401, false],
    ['customer CSRF mismatch is JSON 403', 'customer-csrf-bad', 403, false],
    ['customer write method is rejected as JSON 405', 'customer-post', 405, false],
    ['invalid customer page is JSON 422', 'customer-page-invalid', 422, false],
    ['inactive customer is JSON 403 and session expires', 'customer-inactive', 403, true],
    ['customer role change is JSON 403 and session expires', 'customer-role-changed', 403, true],
    ['admin unauthenticated request is JSON 401', 'admin-unauth', 401, true],
    ['admin customer role is JSON 403', 'admin-wrong-role', 403, false],
    ['admin CSRF mismatch is JSON 403', 'admin-csrf-bad', 403, false],
    ['admin role change is JSON 403 and session expires', 'admin-role-changed', 403, true],
    ['inactive staff is JSON 403 and session expires', 'admin-staff-inactive', 403, true],
];

$failed = 0;
try {
    foreach ($cases as [$label, $scenario, $status, $expired]) {
        $command = [PHP_BINARY, '-n', '-d', 'session.save_path=' . $sessionDir, __FILE__, '--child', $scenario];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            fwrite(STDERR, "FAIL|{$label}: child process could not start\n");
            $failed++;
            continue;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $result = json_decode((string)$stdout, true);
        $passed = $exitCode === 0 && is_array($result)
            && ($result['http'] ?? null) === $status
            && ($result['json'] ?? false) === true
            && ($result['success'] ?? true) === false
            && ($result['sessionExpired'] ?? null) === $expired;
        echo ($passed ? 'PASS|' : 'FAIL|') . $label . "\n";
        if (!$passed) {
            $failed++;
            if ($stderr !== '') fwrite(STDERR, 'Child PHP diagnostic was emitted.' . "\n");
        }
    }

    $dashboard = (string)file_get_contents($root . '/user_dashboard.php');
    $ownershipBound = str_contains($dashboard, '$user_id = $_SESSION[\'user_id\'];')
        && str_contains($dashboard, 'FROM customers WHERE user_id = ?')
        && str_contains($dashboard, 'WHERE b.customer_id = ?')
        && str_contains($dashboard, '$stmt_bookings->bind_param("iii", $customer_id, $booking_limit, $booking_offset);');
    echo ($ownershipBound ? 'PASS|' : 'FAIL|') . 'customer dashboard refresh renders session-owned customer queries only' . "\n";
    if (!$ownershipBound) $failed++;
} finally {
    foreach (scandir($sessionDir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $sessionDir . '/' . $name;
        if (is_file($path) || is_link($path)) unlink($path);
    }
    rmdir($sessionDir);
}

exit($failed === 0 ? 0 : 1);
