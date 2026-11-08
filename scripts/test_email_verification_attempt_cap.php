<?php
/** CLI-only endpoint regression: synthetic SQL, sessions and delivery stubs. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$cases = ['valid', 'fifth-valid', 'fifth-wrong', 'capped-valid', 'capped-wrong', 'expired', 'capped-expired', 'limiter-unavailable', 'invalid-csrf', 'phone-dispatch', 'missing-account'];

if (($argv[1] ?? '') !== '--fixture') {
    $count = 0;
    foreach ($cases as $case) {
        $process = proc_open([PHP_BINARY, __FILE__, '--fixture', $case],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            $root, ['PATH' => (string)getenv('PATH')]);
        if (!is_resource($process)) throw new RuntimeException('Cannot start synthetic fixture.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $status = proc_close($process);
        $result = json_decode($output, true);
        if ($status !== 0 || $error !== '' || !is_array($result)) throw new RuntimeException("Fixture failed: {$case}: {$output}{$error}");
        $valid = in_array($case, ['valid', 'fifth-valid'], true);
        $expectedCalls = in_array($case, ['phone-dispatch', 'invalid-csrf', 'missing-account'], true) ? 0 : 1;
        $expectedMessage = match ($case) {
            'valid', 'fifth-valid' => 'Account verified successfully! Welcome to SEVILLA360.',
            'fifth-wrong' => 'Invalid verification code. Please try again.',
            'expired' => 'This verification code has expired. Please request a new one.',
            'invalid-csrf' => 'Security token expired. Please refresh the page and try again.',
            'missing-account' => 'Error: Account not found or already verified.',
            'phone-dispatch' => null,
            default => 'Too many invalid verification attempts. Please request a new code later.',
        };
        if ($result['authenticated'] !== $valid || $result['verified'] !== $valid
            || $result['message'] !== $expectedMessage || $result['limiter_calls'] !== $expectedCalls
            || $result['commits'] !== ($valid ? 1 : 0) || $result['mail_stub_calls'] !== ($valid ? 1 : 0)
            || $result['notification_stub_calls'] !== ($valid ? 1 : 0)
            || $result['session_version_issues'] !== ($valid ? 1 : 0)
            || $result['auth_version'] !== ($valid ? 1 : null)
            || $result['phone_dispatched'] !== ($case === 'phone-dispatch')
            || $result['csrf_rotated'] !== $valid || $result['session_rotated'] !== $valid
            || $result['marked_authenticated'] !== $valid) throw new RuntimeException("Unexpected {$case}: {$output}");
        if ($case === 'fifth-valid' && $result['reserved_ordinal'] !== 5) throw new RuntimeException('Fifth permitted submission must remain usable.');
        if (str_starts_with($case, 'capped-') && $result['reserved_ordinal'] !== 6) throw new RuntimeException('Exhausted budget must remain blocked.');
        echo "PASS {$case}\n"; $count++;
    }
    echo "Email verification attempt cap: {$count} isolated cases passed; no DB or provider requests.\n";
    exit;
}

$case = $argv[2] ?? '';
if (!in_array($case, $cases, true)) exit(2);
$sessionDirectory = sys_get_temp_dir() . '/sevilla-email-cap-' . bin2hex(random_bytes(8));
if (!mkdir($sessionDirectory, 0700)) throw new RuntimeException('Cannot create disposable sessions.');
session_save_path($sessionDirectory); session_start();
$originalSessionId = session_id();
$_SESSION = ['csrf_token' => 'synthetic-csrf'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['email' => 'synthetic@example.invalid', 'csrf_token' => $case === 'invalid-csrf' ? 'wrong' : 'synthetic-csrf',
    'verification_code' => in_array($case, ['fifth-wrong', 'capped-wrong'], true) ? '000000' : '123456'];
$state = ['attempts' => str_starts_with($case, 'capped-') ? 5 : (str_starts_with($case, 'fifth-') ? 4 : 0),
    'verified' => false, 'limiter_calls' => 0, 'reserved_ordinal' => null, 'commits' => 0,
    'mail_stub_calls' => 0, 'notification_stub_calls' => 0, 'session_version_issues' => 0,
    'phone_dispatched' => false, 'marked_authenticated' => false];
function request_client_ip(): string { return '192.0.2.10'; }
function create_user_notification(...$unused): bool { $GLOBALS['state']['notification_stub_calls']++; return true; }
function send_welcome_email(...$unused): void { $GLOBALS['state']['mail_stub_calls']++; }
function booking_auth_consume_destination(...$unused): ?string { return null; }
function session_policy_mark_authenticated(): void { $GLOBALS['state']['marked_authenticated'] = true; }
function session_auth_issue_version(...$unused): int { $GLOBALS['state']['session_version_issues']++; return 1; }
function email_cap_phone_dispatch(): never { $GLOBALS['state']['phone_dispatched'] = true; exit; }
class EmailCapResult {
    public int $num_rows;
    public function __construct(private array $rows) { $this->num_rows = count($rows); }
    public function fetch_assoc(): ?array { return array_shift($this->rows); }
}
class EmailCapConnection {
    public int $ordinal = 1;
    public function prepare(string $sql): EmailCapStatement { return new EmailCapStatement($this, $sql); }
    public function query(string $sql): EmailCapResult|bool {
        if (str_starts_with($sql, 'SET ')) return $GLOBALS['case'] !== 'limiter-unavailable';
        return new EmailCapResult([['attempts' => $this->ordinal]]);
    }
    public function begin_transaction(): bool { return true; }
    public function commit(): bool { $GLOBALS['state']['commits']++; return true; }
    public function rollback(): bool { return true; }
}
class EmailCapStatement {
    public int $affected_rows = 0;
    public function __construct(private EmailCapConnection $db, private string $sql) {}
    public function bind_param(string $types, &...$values): void {}
    public function close(): void {}
    public function execute(): bool {
        if (str_contains($this->sql, 'INSERT INTO rate_limits')) {
            $this->db->ordinal = $GLOBALS['state']['attempts'] = min($GLOBALS['state']['attempts'] + 1, 6);
            $GLOBALS['state']['reserved_ordinal'] = $this->db->ordinal;
        }
        if (str_starts_with($this->sql, 'DELETE FROM rate_limits')) $GLOBALS['state']['attempts'] = 0;
        if (str_starts_with($this->sql, 'UPDATE users SET is_verified')) {
            $GLOBALS['state']['verified'] = true; $this->affected_rows = 1;
        }
        return true;
    }
    public function get_result(): EmailCapResult {
        if (str_starts_with($this->sql, 'SELECT phone_signup_required')) return new EmailCapResult([['phone_signup_required' => (int)($GLOBALS['case'] === 'phone-dispatch')]]);
        if (str_contains($this->sql, 'verification_code, verification_expires_at')) return new EmailCapResult($GLOBALS['case'] === 'missing-account' ? [] : [[
            'id' => 101, 'auth_version' => 0, 'verification_code' => '123456',
            'verification_expires_at' => date('Y-m-d H:i:s', time() + (str_contains($GLOBALS['case'], 'expired') ? -60 : 600))]]);
        if (str_starts_with($this->sql, 'SELECT first_name')) return new EmailCapResult([['first_name' => 'Synthetic']]);
        if (str_starts_with($this->sql, 'SELECT id FROM user_notifications')) return new EmailCapResult([]);
        throw new RuntimeException('Unexpected fixture SQL.');
    }
}
$conn = new EmailCapConnection();
register_shutdown_function(static function () use ($sessionDirectory, $originalSessionId): void {
    $result = $GLOBALS['state'] + ['authenticated' => $_SESSION['logged_in'] ?? false,
        'message' => $_SESSION['auth_alert']['message'] ?? null,
        'auth_version' => $_SESSION['auth_version'] ?? null,
        'csrf_rotated' => ($_SESSION['csrf_token'] ?? '') !== 'synthetic-csrf',
        'session_rotated' => session_id() !== $originalSessionId];
    session_write_close();
    foreach (glob($sessionDirectory . '/sess_*') ?: [] as $file) unlink($file);
    rmdir($sessionDirectory);
    echo json_encode($result, JSON_THROW_ON_ERROR);
});
// Load the real limiter and endpoint control flow, replacing only dependencies.
// In particular, no application configuration, database, mailer or SMS code loads.
$limiter = file_get_contents($root . '/includes/rate_limit.php');
$limiter = preg_replace('/^require_once .*;$/m', '', $limiter);
$limiter = str_replace("    \$max_attempts = (int)\$max_attempts;", "    \$GLOBALS['state']['limiter_calls']++;\n    \$max_attempts = (int)\$max_attempts;", $limiter);
eval('?>' . $limiter);
$endpoint = file_get_contents($root . '/actions/auth/verify_process.php');
$endpoint = preg_replace('/^require(?:_once)? .*;$/m', '', $endpoint);
$endpoint = str_replace("require __DIR__ . '/phone_verify_process.php'", 'email_cap_phone_dispatch()', $endpoint);
eval('?>' . $endpoint);
