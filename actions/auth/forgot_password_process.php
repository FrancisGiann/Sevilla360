<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once '../../config/db_connect.php';
require_once '../../includes/mailer.php';
require_once '../../includes/rate_limit.php';
require_once '../../includes/password_reset_security.php';
require_once __DIR__ . '/../../includes/contact_verification.php';
require_once __DIR__ . '/../../includes/recovery_security.php';

$origin = password_reset_origin(is_string($_POST['origin'] ?? null) ? $_POST['origin'] : 'customer');
$genericRedirect = static function (string $origin): never {
    $_SESSION['auth_alert'] = ['title' => 'Notice', 'message' => PASSWORD_RESET_GENERIC_MESSAGE, 'type' => 'info'];
    $destination = ($_POST['channel'] ?? 'email') === 'sms' ? '../../recovery_code.php' : '../../auth.php?origin=' . rawurlencode(password_reset_origin($origin));
    header('Location: ' . $destination);
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
    $channel = ($_POST['channel'] ?? 'email') === 'sms' ? 'sms' : 'email';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $genericRedirect($origin);
    }
    // This limiter is keyed by the account identifier even when no account exists.
    // All channels get the same public response for unknown and ineligible users.
    if (!contact_allow($conn, 'recovery-account-' . strtolower($email), 5, 3600)) $genericRedirect($origin);
    if ($channel === 'sms' && !recovery_sms_enabled()) {
        $_SESSION['auth_alert'] = ['title'=>'Notice','message'=>'Text recovery is unavailable right now. Please use a verified recovery email or contact support.','type'=>'warning'];
        header('Location: ../../auth.php?origin=' . rawurlencode($origin)); exit();
    }
    if ($channel === 'sms') {
        $stmt = $conn->prepare("SELECT u.id,u.role,u.status,u.is_verified,u.phone_signup_required,p.phone_e164,p.verified_at FROM users u LEFT JOIN customer_verified_phones p ON p.user_id=u.id WHERE u.email=? LIMIT 1");
        $stmt->bind_param('s',$email); $stmt->execute(); $user=$stmt->get_result()->fetch_assoc();
        if ($user && recovery_sms_eligible($user)) {
            $phone=$user['phone_e164']; $id=(int)$user['id'];
            if (recovery_sms_reserve($conn, $id, $phone)) {
                $code=contact_code(); $hash=recovery_code_hash($id,$phone,$code);
                $conn->begin_transaction();
                try {
                    $lock=$conn->prepare('SELECT id FROM users WHERE id=? FOR UPDATE'); $lock->bind_param('i',$id); $lock->execute(); $lock->get_result()->fetch_assoc();
                    $old=$conn->prepare('UPDATE recovery_sms_challenges SET consumed_at=NOW() WHERE user_id=? AND consumed_at IS NULL'); $old->bind_param('i',$id); $old->execute();
                    $ins=$conn->prepare('INSERT INTO recovery_sms_challenges(user_id,phone_e164,code_hash,expires_at) VALUES (?,?,?,DATE_ADD(NOW(),INTERVAL 5 MINUTE))'); $ins->bind_param('iss',$id,$phone,$hash); $ins->execute(); $challengeId=(int)$conn->insert_id;
                    $conn->commit();
                    try {
                        $sent=recovery_submit_sms($phone,$code,'contact_sms_transport');
                        $upd=$conn->prepare('UPDATE recovery_sms_challenges SET provider_reference=?,provider_status=? WHERE id=? AND consumed_at IS NULL'); $upd->bind_param('ssi',$sent['reference_id'],$sent['status'],$challengeId); $upd->execute();
                    } catch (Throwable $error) {
                        $providerState=$error instanceof UnexpectedValueException ? 'rejected' : 'unknown';
                        try { $upd=$conn->prepare('UPDATE recovery_sms_challenges SET provider_status=? WHERE id=? AND consumed_at IS NULL'); $upd->bind_param('si',$providerState,$challengeId); $upd->execute(); } catch (Throwable $ignored) {}
                        error_log('Recovery SMS delivery failed: '.get_class($error));
                    }
                } catch (Throwable $error) { $conn->rollback(); error_log('Recovery SMS setup failed: '.get_class($error)); }
            }
        }
        $genericRedirect($origin);
    }
    if (!contact_allow($conn,'recovery-email-ip-'.request_client_ip(),8,3600)) $genericRedirect($origin);
    
    // Check if user exists
    $stmt = $conn->prepare("SELECT id, email, role, status, is_verified, phone_signup_required, recovery_email, recovery_email_verified_at FROM users WHERE email = ? LIMIT 1");
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
        $resetDestination = contact_reset_destination($user);
        if ($resetDestination === null || ($user['role']==='customer' && (int)$user['is_verified']!==1)) $genericRedirect($origin);
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
            send_password_reset_email($resetDestination, $name, $reset_link);
        } catch (Exception $e) {
            // Ignore mail errors for security (don't leak if email exists or not)
        }
    }
    
    // Always show generic success message to prevent user enumeration
    $genericRedirect($origin);
}
?>
