<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF TOKEN
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// check if the user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
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