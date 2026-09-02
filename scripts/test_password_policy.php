<?php
/** Focused checks for new-password validation and legacy login compatibility. */
require_once __DIR__ . '/../includes/password_policy.php';

function password_policy_test_assert(bool $condition, string $message): void
{
    if (!$condition) exit("Password policy test failed: {$message}\n");
}

function password_policy_test_valid(string $password, string $message): void
{
    $result = password_policy_validate($password);
    password_policy_test_assert($result['valid'] === true, $message);
    password_policy_test_assert($result['message'] === '', "valid result has no error message ({$message})");
}

function password_policy_test_invalid(string $password, string $message): void
{
    $result = password_policy_validate($password);
    password_policy_test_assert($result['valid'] === false, $message);
    password_policy_test_assert($result['message'] === password_policy_message(), "invalid result has the policy message ({$message})");
}

$minimum_valid = 'Aa1!abcd'; // Exactly 8 bytes.
$maximum_valid = 'Aa1!' . str_repeat('b', 68); // Exactly 72 bytes.

password_policy_test_valid($minimum_valid, '8-byte password is accepted');
password_policy_test_valid($maximum_valid, '72-byte password is accepted');
password_policy_test_invalid('Aa1!abc', '7-byte password is rejected');
password_policy_test_invalid('Aa1!' . str_repeat('b', 69), '73-byte password is rejected');
password_policy_test_invalid('aa1!abcdefgh', 'missing uppercase ASCII letter is rejected');
password_policy_test_invalid('AA1!ABCDEFGH', 'missing lowercase ASCII letter is rejected');
password_policy_test_invalid('Aa!!abcdefgh', 'missing digit is rejected');
password_policy_test_invalid('Aa1abcdefgh', 'missing symbol is rejected');
password_policy_test_invalid("Aa1!abcdef\0gh", 'embedded NUL byte is rejected');

// Login must continue to use password_verify without applying the new policy.
$weak_password = 'weakpass';
$weak_hash = password_hash($weak_password, PASSWORD_BCRYPT);
password_policy_test_assert(password_verify($weak_password, $weak_hash), 'legacy weak bcrypt hash still verifies');
$login_source = file_get_contents(__DIR__ . '/../actions/auth/login_process.php');
password_policy_test_assert($login_source !== false, 'login endpoint can be inspected');
password_policy_test_assert(strpos($login_source, 'password_policy_validate') === false, 'login does not invoke new-password validation');
password_policy_test_assert(strpos($login_source, 'password_verify(') !== false, 'login continues to use password_verify');

foreach ([
    '../actions/auth/register_process.php',
    '../actions/auth/reset_password_process.php',
    '../actions/user/save_settings.php',
    '../actions/admin/save_profile.php',
    '../actions/admin/manage_staff.php',
] as $write_path) {
    $write_source = file_get_contents(__DIR__ . '/' . $write_path);
    password_policy_test_assert($write_source !== false, "password-setting endpoint can be inspected ({$write_path})");
    password_policy_test_assert(strpos($write_source, 'password_policy.php') !== false, "password policy is included ({$write_path})");
    password_policy_test_assert(strpos($write_source, 'password_policy_validate') !== false, "password policy is applied ({$write_path})");
}

echo "Password policy checks passed\n";
