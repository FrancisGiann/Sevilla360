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

// ==========================================
// IDLE SESSION TIMEOUT (30 MINUTES)
// ==========================================
$timeout_minutes = 30;
$timeout_seconds = $timeout_minutes * 60;

if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $timeout_seconds) {
        // Clear old session data
        session_unset();
        session_destroy();
        
        // Start a fresh session for the alert message
        session_start();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['auth_alert'] = [
            'title' => 'Session Expired', 
            'message' => 'You were automatically logged out due to 30 minutes of inactivity.', 
            'type' => 'warning'
        ];
        
        header("Location: auth.php");
        exit();
    }
}
// Refresh the activity timestamp since they are active right now
$_SESSION['last_activity'] = time();


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