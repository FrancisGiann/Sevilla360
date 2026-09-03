<?php
/** Focused regression checks for login credential normalization and recovery state. */

$session_path = sys_get_temp_dir() . '/sevilla-login-recovery-' . bin2hex(random_bytes(8));
if (!mkdir($session_path, 0700, true) && !is_dir($session_path)) exit("Unable to create login recovery test directory.\n");
register_shutdown_function(static function () use ($session_path): void {
    foreach (glob($session_path . '/sess_*') ?: [] as $session_file) {
        if (is_file($session_file)) @unlink($session_file);
    }
    @rmdir($session_path);
});
session_save_path($session_path);
session_id('login-recovery-' . bin2hex(random_bytes(8)));
session_start();
require_once __DIR__ . '/../includes/customer_login_recovery.php';

function login_recovery_test_assert(bool $condition, string $message): void
{
    if (!$condition) exit("Login recovery test failed: {$message}\n");
}

function login_recovery_test_reset_session(): void
{
    $_SESSION = [];
}

$now = 1700000000;
login_recovery_test_reset_session();
login_recovery_test_assert(!customer_login_recovery_nudge_eligible($now), 'zero failures are hidden');

customer_login_recovery_record_failure($now);
login_recovery_test_assert(!customer_login_recovery_nudge_eligible($now), 'one failure is hidden');
customer_login_recovery_record_failure($now + 1);
login_recovery_test_assert(!customer_login_recovery_nudge_eligible($now + 1), 'two failures are hidden');
customer_login_recovery_record_failure($now + 2);
login_recovery_test_assert(customer_login_recovery_nudge_eligible($now + 2), 'third failure shows the nudge');
$state = $_SESSION[CUSTOMER_LOGIN_RECOVERY_SESSION_KEY] ?? [];
login_recovery_test_assert(array_keys($state) === ['count', 'last_failure_at'], 'recovery state contains no submitted credentials');

for ($i = 0; $i < 10; $i++) customer_login_recovery_record_failure($now + 3 + $i);
$state = $_SESSION[CUSTOMER_LOGIN_RECOVERY_SESSION_KEY] ?? [];
login_recovery_test_assert(($state['count'] ?? null) === CUSTOMER_LOGIN_RECOVERY_MAX_FAILURES, 'failure count is capped');

login_recovery_test_assert(!customer_login_recovery_nudge_eligible($now + 913), 'nudge expires after 15 minutes');
login_recovery_test_assert(!isset($_SESSION[CUSTOMER_LOGIN_RECOVERY_SESSION_KEY]), 'expired state is removed');

for ($i = 0; $i < 3; $i++) customer_login_recovery_record_failure($now + 100 + $i);
customer_login_recovery_clear();
login_recovery_test_assert(!customer_login_recovery_nudge_eligible($now + 102), 'successful login reset hides the nudge');

$helper_source = file_get_contents(__DIR__ . '/../includes/customer_login_recovery.php');
$login_source = file_get_contents(__DIR__ . '/../actions/auth/login_process.php');
$auth_source = file_get_contents(__DIR__ . '/../auth.php');
$css_source = file_get_contents(__DIR__ . '/../assets/css/auth.css');
$forgot_source = file_get_contents(__DIR__ . '/../actions/auth/forgot_password_process.php');
foreach ([
    'helper' => $helper_source,
    'login endpoint' => $login_source,
    'auth page' => $auth_source,
    'auth styles' => $css_source,
    'forgot-password endpoint' => $forgot_source,
] as $name => $source) {
    login_recovery_test_assert($source !== false, "{$name} can be inspected");
}

login_recovery_test_assert(
    preg_match('/if \(\$is_customer_login\) customer_login_recovery_record_failure\(\);/', $login_source) === 1,
    'only customer generic credential failures update recovery state'
);
login_recovery_test_assert(substr_count($login_source, 'customer_login_recovery_record_failure') === 1, 'admin failures cannot update customer recovery state');
foreach ([
    '$session_csrf_token = $_SESSION[\'csrf_token\'] ?? null;',
    '$submitted_csrf_token = $_POST[\'csrf_token\'] ?? null;',
    '!is_string($session_csrf_token)',
    '!is_string($submitted_csrf_token)',
    'hash_equals($session_csrf_token, $submitted_csrf_token)',
] as $csrf_contract) {
    login_recovery_test_assert(strpos($login_source, $csrf_contract) !== false, "CSRF guard includes {$csrf_contract}");
}
login_recovery_test_assert(substr_count($login_source, 'Invalid email or password.') === 1 && substr_count($login_source, 'LOGIN_GENERIC_CREDENTIAL_ERROR') >= 3, 'customer and admin failures use one generic message');
foreach (['Incorrect password!', 'Email not found!', 'Unauthorized: Admin access required.', 'Please use the Administrator login portal.'] as $leaked_message) {
    login_recovery_test_assert(strpos($login_source, $leaked_message) === false, "legacy credential-specific message is removed ({$leaked_message})");
}

$verify_position = strpos($login_source, '$password_verified =');
$unverified_position = strpos($login_source, "if ((int)\$user['is_verified'] === 0)");
$status_position = strpos($login_source, '$account_status =');
login_recovery_test_assert(
    $verify_position !== false && $unverified_position !== false && $status_position !== false
        && $verify_position < $unverified_position && $verify_position < $status_position,
    'verification precedes unverified and account-status responses'
);
login_recovery_test_assert(strpos($login_source, 'password_verify($password, $password_hash)') !== false, 'unknown accounts use the password verification path');
login_recovery_test_assert(strpos($login_source, 'LOGIN_DUMMY_PASSWORD_HASH') !== false, 'unknown accounts use a fixed dummy hash');
login_recovery_test_assert(strpos($login_source, 'send_password_reset_email') === false, 'login never sends reset mail automatically');
login_recovery_test_assert(strpos($forgot_source, 'send_password_reset_email') !== false, 'reset mail remains in the explicit forgot-password endpoint');

$state_keys = array_keys($_SESSION);
login_recovery_test_assert($state_keys === [], 'recovery session reset stores no email or password data');
login_recovery_test_assert(strpos($helper_source, "['count'") !== false && strpos($helper_source, "'last_failure_at'") !== false, 'recovery state uses only bounded count and timestamp fields');
login_recovery_test_assert(strpos($auth_source, 'role="status" aria-live="polite"') !== false, 'recovery callout has accessible live status semantics');
login_recovery_test_assert(strpos($auth_source, 'data-forgot-password') !== false, 'recovery action uses the shared forgot-password trigger');
login_recovery_test_assert(strpos($css_source, '.forgot-link {') !== false && strpos($css_source, 'width: 100%;') !== false, 'forgot-password button remains full-width and right-aligned');
$customer_view_position = strpos($auth_source, 'id="view-user-login"');
$admin_view_position = strpos($auth_source, 'id="view-admin-login"');
$nudge_position = strpos($auth_source, 'class="login-recovery-nudge"');
login_recovery_test_assert(
    $customer_view_position !== false && $admin_view_position !== false && $nudge_position !== false
        && $customer_view_position < $nudge_position && $nudge_position < $admin_view_position,
    'recovery nudge markup is limited to the customer login view'
);
login_recovery_test_assert(strpos(file_get_contents(__DIR__ . '/../assets/js/auth.js'), 'forgotTriggers.forEach') !== false, 'forgot-password triggers share one view-switch handler');

echo "Login recovery and credential normalization checks passed\n";
