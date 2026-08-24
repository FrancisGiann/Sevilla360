<?php
/** Static regression guard for the OTP verification/authentication ordering. */
$path = __DIR__ . '/../actions/auth/verify_process.php';
$source = file_get_contents($path);
if ($source === false) exit("Unable to read verification endpoint.\n");

$required_fragments = [
    "WHERE id = ? AND role = 'customer' AND status = 'active' AND is_verified = FALSE",
    'SELECT is_verified, role, status FROM users WHERE id = ? LIMIT 1 FOR UPDATE',
    'if ($update_stmt->affected_rows === 1)',
];
foreach ($required_fragments as $fragment) {
    if (strpos($source, $fragment) === false) exit("Verification integrity guard missing: {$fragment}\n");
}

$commit_position = strpos($source, "if (!\$conn->commit())");
$session_position = strpos($source, 'session_regenerate_id(true);');
$auth_position = strpos($source, "\$_SESSION['user_id'] =");
if ($commit_position === false || $session_position === false || $auth_position === false || $session_position < $commit_position || $auth_position < $commit_position) {
    exit("Authentication session assignment must follow a successful verification commit.\n");
}

echo "Verification integrity checks passed\n";
