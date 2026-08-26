<?php
/** Focused regression checks for the centralized server-side session policy. */
$save_path = sys_get_temp_dir() . '/sevilla-session-policy-' . bin2hex(random_bytes(8));
if (!mkdir($save_path, 0700, true) && !is_dir($save_path)) exit("Unable to create session test directory.\n");
register_shutdown_function(static function () use ($save_path): void {
    foreach (glob($save_path . '/sess_*') ?: [] as $session_file) {
        if (is_file($session_file)) @unlink($session_file);
    }
    @rmdir($save_path);
});
session_save_path($save_path);
require_once __DIR__ . '/../includes/session_init.php';

function session_policy_test_assert(bool $condition, string $message): void
{
    if (!$condition) exit("Session policy test failed: {$message}\n");
}

function session_policy_test_request(array $values, array $server = []): array
{
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_id('policy_' . bin2hex(random_bytes(8)));
    session_start();
    $_SERVER = array_merge($_SERVER, $server);
    if (!array_key_exists('REQUEST_URI', $server)) unset($_SERVER['REQUEST_URI']);
    if (!array_key_exists('HTTP_X_SEVILLA_BACKGROUND', $server)) unset($_SERVER['HTTP_X_SEVILLA_BACKGROUND']);
    $_SESSION = $values;
    session_policy_enforce();
    $result = $_SESSION;
    session_write_close();
    return $result;
}

$now = time();
$customer = session_policy_test_request([
    'logged_in' => true,
    'role' => 'customer',
    'user_id' => 1,
    'auth_started_at' => $now,
    'last_user_activity' => $now - 1799,
]);
session_policy_test_assert(($customer['logged_in'] ?? false) === true, 'customer active at 1799 seconds');
session_policy_test_assert((int)$customer['last_user_activity'] >= $now, 'active request refreshes user activity');

$customer_expired = session_policy_test_request([
    'logged_in' => true,
    'role' => 'customer',
    'user_id' => 1,
    'auth_started_at' => $now,
    'last_user_activity' => $now - 1801,
]);
session_policy_test_assert(empty($customer_expired['logged_in']), 'customer expires after idle timeout');
session_policy_test_assert(isset($customer_expired['auth_alert']), 'idle expiry preserves an auth alert');

foreach (['staff' => 901, 'admin' => 901] as $role => $age) {
    $expired = session_policy_test_request([
        'logged_in' => true,
        'role' => $role,
        'user_id' => 1,
        'auth_started_at' => $now,
        'last_user_activity' => $now - $age,
    ]);
    session_policy_test_assert(empty($expired['logged_in']), "{$role} expires after idle timeout");
}

$absolute_expired = session_policy_test_request([
    'logged_in' => true,
    'role' => 'admin',
    'user_id' => 1,
    'auth_started_at' => $now - 28801,
    'last_user_activity' => $now - 1,
]);
session_policy_test_assert(empty($absolute_expired['logged_in']), 'absolute lifetime does not slide with activity');

$passive = session_policy_test_request([
    'logged_in' => true,
    'role' => 'customer',
    'user_id' => 1,
    'auth_started_at' => $now,
    'last_user_activity' => $now - 100,
], [
    'REQUEST_URI' => '/Sevilla360/actions/user/get_notifications.php',
]);
session_policy_test_assert((int)$passive['last_user_activity'] === $now - 100, 'passive polling does not refresh activity');

$invalid_role = session_policy_test_request([
    'logged_in' => true,
    'role' => 'unknown',
    'user_id' => 1,
]);
session_policy_test_assert(empty($invalid_role['logged_in']), 'unknown roles fail closed');

$migrated = session_policy_test_request([
    'logged_in' => true,
    'role' => 'customer',
    'user_id' => 1,
    'last_activity' => $now - 100,
]);
session_policy_test_assert(($migrated['logged_in'] ?? false) === true, 'legacy session migrates without immediate logout');
session_policy_test_assert(isset($migrated['auth_started_at'], $migrated['last_user_activity']), 'legacy timestamps are initialized');

echo "Session policy checks passed\n";
