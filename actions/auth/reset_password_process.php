<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once '../../config/db_connect.php';
require_once '../../includes/rate_limit.php';
require_once '../../includes/password_policy.php';
require_once '../../includes/password_reset_security.php';
require_once '../../includes/recovery_security.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Security token expired.', 'type' => 'error'];
        header("Location: ../../auth.php?origin=" . rawurlencode(password_reset_origin((string)($_POST['origin'] ?? 'customer'))));
        exit();
    }
    
    // RATE LIMITING CHECK
    if (!check_rate_limit($conn, 'reset_password', 5, 15)) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Too many attempts. Please try again later.', 'type' => 'error'];
        header("Location: ../../auth.php?origin=" . rawurlencode(password_reset_origin((string)($_POST['origin'] ?? 'customer'))));
        exit();
    }
    
    $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
    $smsMode = ($_POST['channel'] ?? '') === 'sms';
    $origin = password_reset_origin(is_string($_POST['origin'] ?? null) ? $_POST['origin'] : (string)($_SESSION['reset_origin'] ?? 'customer'));
    $new_password = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $confirm_password = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
    $user_id = $_SESSION['reset_user_id'] ?? 0;
    
    if ((!$smsMode && empty($token)) || empty($new_password) || (!$smsMode && $user_id === 0)) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Invalid request.', 'type' => 'error'];
        header("Location: ../../auth.php?origin=" . rawurlencode($origin));
        exit();
    }
    
    if ($new_password !== $confirm_password) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Passwords do not match!', 'type' => 'error'];
        header("Location: ../../auth.php?origin=" . rawurlencode($origin));
        exit();
    }
    
    $password_policy = password_policy_validate($new_password);
    if (!$password_policy['valid']) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => $password_policy['message'], 'type' => 'error'];
        header("Location: ../../auth.php?origin=" . rawurlencode($origin));
        exit();
    }
    if ($smsMode) {
        if (!recovery_sms_enabled()) { $_SESSION['auth_alert']=['title'=>'Error','message'=>'Text recovery is unavailable. Request another recovery method.','type'=>'error']; header('Location: ../../auth.php'); exit(); }
        $hash=password_hash($new_password,PASSWORD_BCRYPT);
        try { $changed=recovery_reset_password($conn,$_SESSION,$hash); }
        catch (Throwable $error) { error_log('Recovery reset failed: '.get_class($error)); $changed=false; }
        if ($changed) {
            session_policy_reset(['title'=>'Success','message'=>'Password reset. Sign in with your new password.','type'=>'success']);
            header('Location: ../../auth.php'); exit();
        }
        $_SESSION['auth_alert']=['title'=>'Error','message'=>'Reset failed. Request a new code.','type'=>'error'];
        header('Location: ../../auth.php'); exit();
    }
    
    // Validate the one-time digest again and require the account to remain
    // active. Resetting a password never changes role or account status.
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'This reset link has expired or is invalid.', 'type' => 'error'];
        header("Location: ../../auth.php?origin=" . rawurlencode($origin));
        exit();
    }
    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT u.id, u.role, u.status, s.status AS staff_status FROM users u LEFT JOIN staff s ON s.user_id = u.id WHERE u.id = ? AND u.reset_token_hash = ? AND u.reset_expires_at > NOW() AND u.role IN ('customer', 'staff', 'admin')");
    $stmt->bind_param("is", $user_id, $tokenHash);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $resetUser = $res->fetch_assoc();
    $active = $resetUser && (($resetUser['role'] === 'customer' && strcasecmp((string)$resetUser['status'], 'active') === 0)
        || (in_array($resetUser['role'] ?? '', ['staff', 'admin'], true) && strcasecmp((string)($resetUser['staff_status'] ?? ''), 'active') === 0));
    if (!$resetUser || !$active) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'This reset link has expired or is invalid.', 'type' => 'error'];
        header("Location: ../../auth.php?origin=" . rawurlencode($origin));
        exit();
    }
    
    // Hash new password
    $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
    
    // Update password and CLEAR token
    $update = $conn->prepare("UPDATE users SET password_hash = ?, auth_version=auth_version+1, reset_token = NULL, reset_token_hash = NULL, reset_expires_at = NULL WHERE id = ? AND reset_token_hash = ? AND reset_expires_at > NOW()");
    $update->bind_param("sis", $password_hash, $user_id, $tokenHash);
    
    if ($update->execute() && $update->affected_rows === 1) {
        $clear=$conn->prepare('UPDATE recovery_sms_challenges SET consumed_at=NOW() WHERE user_id=? AND consumed_at IS NULL'); $clear->bind_param('i',$user_id); $clear->execute();
        unset($_SESSION['reset_user_id'], $_SESSION['reset_origin']); // clear from session
        if (in_array($resetUser['role'], ['staff', 'admin'], true)) {
            try {
                $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Authentication', 'Successful staff/admin password reset', ?)");
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $audit->bind_param('is', $user_id, $ip);
                $audit->execute();
                $audit->close();
            } catch (Throwable $ignored) { /* Password reset remains successful if audit storage is unavailable. */ }
        }
        $_SESSION['auth_alert'] = ['title' => 'Success', 'message' => 'Your password has been successfully reset. You can now log in.', 'type' => 'success'];
        header("Location: ../../auth.php?origin=" . rawurlencode($origin));
        exit();
    } else {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Database error. Please try again later.', 'type' => 'error'];
        header("Location: ../../auth.php?origin=" . rawurlencode($origin));
        exit();
    }
}
?>
