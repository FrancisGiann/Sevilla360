<?php
declare(strict_types=1);

/** Source-level guard for the production sign-in entry points. */
$root = dirname(__DIR__);
$flows = [
    'password login' => $root . '/actions/auth/login_process.php',
    'email verification login' => $root . '/actions/auth/verify_process.php',
    'phone verification login' => $root . '/actions/auth/phone_verify_process.php',
];

foreach ($flows as $name => $path) {
    $source = file_get_contents($path);
    if ($source === false
        || strpos($source, "includes/session_auth.php") === false
        || substr_count($source, 'session_auth_issue_version(') !== 1
        || strpos($source, "\$_SESSION['auth_version'] = \$auth_version;") === false) {
        exit("Single-session contract missing from {$name}.\n");
    }

    $issuePosition = strpos($source, 'session_auth_issue_version(');
    $identityPosition = strpos($source, "\$_SESSION['user_id'] =");
    if ($identityPosition === false || $issuePosition === false || $issuePosition > $identityPosition) {
        exit("{$name} must issue a version before authenticating its session.\n");
    }
}

$issuer = file_get_contents($root . '/includes/session_auth.php');
$guard = file_get_contents($root . '/includes/session_account_guard.php');
foreach ([
    'row lock' => 'LIMIT 1 FOR UPDATE',
    'atomic increment' => 'auth_version = auth_version + 1',
    'transaction commit' => '$conn->commit()',
    'failure rollback' => '$conn->rollback()',
] as $label => $fragment) {
    if ($issuer === false || strpos($issuer, $fragment) === false) exit("Session issuer is missing {$label}.\n");
}
if ($guard === false || strpos($guard, "(int)\$_SESSION['auth_version'] !== (int)\$account['auth_version']") === false) {
    exit("Session account guard no longer enforces the issued version.\n");
}

echo "Single active session contract checks passed\n";
