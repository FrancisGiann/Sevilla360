<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once '../../config/db_connect.php';
require_once '../../includes/mailer.php';
require_once '../../includes/rate_limit.php';
require_once '../../includes/password_reset_security.php';

$origin = password_reset_origin(is_string($_POST['origin'] ?? null) ? $_POST['origin'] : 'customer');
$genericRedirect = static function (string $origin): never {
    $_SESSION['auth_alert'] = ['title' => 'Notice', 'message' => PASSWORD_RESET_GENERIC_MESSAGE, 'type' => 'info'];
    header('Location: ../../auth.php?origin=' . rawurlencode(password_reset_origin($origin)));
    exit();
};

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // CSRF Check
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Security token expired.', 'type' => 'error'];
        header("Location: ../../auth.php?origin=" . rawurlencode($origin));
        exit();
    }
    
    // HONEYPOT CHECK
    if (!empty($_POST['website_url_honeypot'])) {
        $genericRedirect($origin);
    }
    
    // RATE LIMITING CHECK
    if (!check_rate_limit($conn, 'forgot_password', 3, 60)) {
        $genericRedirect($origin);
    }
    
    $emailInput = $_POST['email'] ?? '';
    $email = is_string($emailInput) ? trim($emailInput) : '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $genericRedirect($origin);
    }
    
    // Check if user exists
    $stmt = $conn->prepare("SELECT id, email, role, status FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        $staffStatus = null;
        if (in_array($user['role'], ['staff', 'admin'], true)) {
            $staffStmt = $conn->prepare('SELECT status FROM staff WHERE user_id = ? LIMIT 1');
            $staffStmt->bind_param('i', $user['id']);
            $staffStmt->execute();
            $staffStatus = $staffStmt->get_result()->fetch_assoc()['status'] ?? null;
            $staffStmt->close();
        }
        $isActiveRole = in_array($user['role'], ['customer', 'staff', 'admin'], true)
            && (($user['role'] === 'customer' && strcasecmp((string)$user['status'], 'active') === 0)
                || (in_array($user['role'], ['staff', 'admin'], true) && strcasecmp((string)$staffStatus, 'active') === 0));
        $baseUrl = password_reset_base_url();
        if (!$isActiveRole || $baseUrl === null) {
            if ($isActiveRole && $baseUrl === null) error_log('Password reset skipped: APP_BASE_URL is missing or invalid.');
            $genericRedirect($origin);
        }

        // Generate Token (1 Hour Expiry); only the digest is persisted.
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $tokenHash = hash('sha256', $token);
        
        $update = $conn->prepare("UPDATE users SET reset_token = NULL, reset_token_hash = ?, reset_expires_at = ? WHERE id = ?");
        $update->bind_param("ssi", $tokenHash, $expires, $user['id']);
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
        
        $reset_link = password_reset_link($token, $origin);
        
        try {
            send_password_reset_email($user['email'], $name, $reset_link);
        } catch (Exception $e) {
            // Ignore mail errors for security (don't leak if email exists or not)
        }
    }
    
    // Always show generic success message to prevent user enumeration
    $genericRedirect($origin);
}
?>
