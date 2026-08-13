<?php
session_start();
require_once '../../config/db_connect.php';
require_once '../../includes/mailer.php';
require_once '../../includes/rate_limit.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // CSRF Check
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Security token expired.', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }
    
    // HONEYPOT CHECK
    if (!empty($_POST['website_url_honeypot'])) {
        $_SESSION['auth_alert'] = ['title' => 'Notice', 'message' => 'If your email is in our system, you will receive a password reset link shortly.', 'type' => 'info'];
        header("Location: ../../auth.php");
        exit();
    }
    
    // RATE LIMITING CHECK
    if (!check_rate_limit($conn, 'forgot_password', 3, 60)) {
        $_SESSION['auth_alert'] = ['title' => 'Notice', 'message' => 'If your email is in our system, you will receive a password reset link shortly.', 'type' => 'info'];
        header("Location: ../../auth.php");
        exit();
    }
    
    $email = trim($_POST['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Invalid email format.', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }
    
    // Check if user exists
    $stmt = $conn->prepare("SELECT id, email, role FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Generate Token (1 Hour Expiry)
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        $update = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires_at = ? WHERE id = ?");
        $update->bind_param("ssi", $token, $expires, $user['id']);
        $update->execute();
        
        // Get user name (Optional, fallback to 'User')
        $name = 'User';
        if ($user['role'] === 'customer') {
            $name_stmt = $conn->prepare("SELECT first_name FROM customers WHERE user_id = ?");
            $name_stmt->bind_param("i", $user['id']);
            $name_stmt->execute();
            $res = $name_stmt->get_result();
            if ($res->num_rows > 0) $name = $res->fetch_assoc()['first_name'];
        } else {
            $name_stmt = $conn->prepare("SELECT full_name FROM staff WHERE user_id = ?");
            $name_stmt->bind_param("i", $user['id']);
            $name_stmt->execute();
            $res = $name_stmt->get_result();
            if ($res->num_rows > 0) $name = $res->fetch_assoc()['full_name'];
        }
        
        // Build reset link
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
        // Find the root path automatically assuming standard deployment
        $script_path = dirname($_SERVER['SCRIPT_NAME'], 3); 
        if ($script_path == '\\' || $script_path == '/') $script_path = '';
        $reset_link = $base_url . $script_path . "/reset_password.php?token=" . urlencode($token);
        
        try {
            send_password_reset_email($user['email'], $name, $reset_link);
        } catch (Exception $e) {
            // Ignore mail errors for security (don't leak if email exists or not)
        }
    }
    
    // Always show generic success message to prevent user enumeration
    $_SESSION['auth_alert'] = ['title' => 'Notice', 'message' => 'If your email is in our system, you will receive a password reset link shortly.', 'type' => 'info'];
    header("Location: ../../auth.php");
    exit();
}
?>
