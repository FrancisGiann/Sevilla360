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

require_once __DIR__ . '/session_account_guard.php';
$account_state = session_account_revalidation_state($conn);
if ($account_state === 'unavailable') {
    http_response_code(503);
    exit('Account status could not be verified. Please try again.');
}
if ($account_state !== 'active') {
    session_policy_expire('Your account is suspended, inactive, or no longer authorized. Please sign in again.', 'Account unavailable', 'error');
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
