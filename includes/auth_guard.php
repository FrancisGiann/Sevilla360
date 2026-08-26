<?php
require_once __DIR__ . '/session_init.php';

// check if the user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: auth.php");
    exit();
}

// Revalidate the account on every protected request. This immediately ends
// sessions that were active when an administrator suspended a customer or
// marked a staff account inactive.
require_once __DIR__ . '/../config/db_connect.php';
$account_stmt = $conn->prepare("
    SELECT u.role, u.status AS user_status, s.status AS staff_status
    FROM users u
    LEFT JOIN staff s ON s.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
");
$account_stmt->bind_param('i', $_SESSION['user_id']);
$account_stmt->execute();
$account = $account_stmt->get_result()->fetch_assoc();
$account_stmt->close();

$account_status = ($account && in_array($account['role'], ['admin', 'staff'], true))
    ? ($account['staff_status'] ?? '')
    : ($account['user_status'] ?? '');
if (!$account || strcasecmp((string) $account_status, 'active') !== 0) {
    session_policy_expire(
        'Your account is suspended or inactive. Please contact an administrator.',
        'Account unavailable',
        'error'
    );
    header("Location: auth.php");
    exit();
}


// check if the user has the required role to access the page
if (isset($required_role) && $required_role === 'admin') {
    if ($_SESSION['role'] !== 'staff' && $_SESSION['role'] !== 'admin') {
        header("Location: index.php");
        exit();
    }
}

// If the required role is 'customer', ensure that only customers can access the page
if (isset($required_role) && $required_role === 'customer') {
    if ($_SESSION['role'] !== 'customer') {
        header("Location: index.php");
        exit();
    }
}
?>
