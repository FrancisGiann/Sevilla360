<?php
session_start();
require_once '../../config/db_connect.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Security token expired.', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }
    
    $token = $_POST['token'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $user_id = $_SESSION['reset_user_id'] ?? 0;
    
    if (empty($token) || empty($new_password) || $user_id === 0) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Invalid request.', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }
    
    if ($new_password !== $confirm_password) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Passwords do not match!', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }
    
    if (strlen($new_password) < 6) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Password must be at least 6 characters.', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }
    
    // Validate token again just to be 100% sure
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND reset_token = ? AND reset_expires_at > NOW()");
    $stmt->bind_param("is", $user_id, $token);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows !== 1) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'This reset link has expired or is invalid.', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }
    
    // Hash new password
    $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
    
    // Update password and CLEAR token
    $update = $conn->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires_at = NULL WHERE id = ?");
    $update->bind_param("si", $password_hash, $user_id);
    
    if ($update->execute()) {
        unset($_SESSION['reset_user_id']); // clear from session
        $_SESSION['auth_alert'] = ['title' => 'Success', 'message' => 'Your password has been successfully reset. You can now log in.', 'type' => 'success'];
        header("Location: ../../auth.php");
        exit();
    } else {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Database error. Please try again later.', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }
}
?>
