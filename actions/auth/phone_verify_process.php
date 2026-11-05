<?php
require_once __DIR__ . '/../../includes/contact_verification.php';
require_once __DIR__ . '/../../includes/customer_login_recovery.php';
require_once __DIR__ . '/../../includes/booking_intent.php';
$email=trim((string)($_POST['email']??''));
$back=static function(string $message) use ($email): never { $_SESSION['auth_alert']=['title'=>'Notice','message'=>$message,'type'=>'warning']; header('Location: ../../auth.php?verify_email='.rawurlencode($email)); exit; };
if ($_SERVER['REQUEST_METHOD']!=='POST' || !filter_var($email,FILTER_VALIDATE_EMAIL) || empty($_POST['csrf_token']) || !hash_equals((string)($_SESSION['csrf_token']??''),(string)$_POST['csrf_token'])) $back('Refresh the page and try again.');
$code=(string)($_POST['verification_code']??'');
$ip=request_client_ip();
if (!contact_allow($conn,'otp-ip-'.$ip,30,900) || !contact_allow($conn,'otp-email-'.strtolower($email),5,900)) $back('Too many code attempts. Try again later.');
$stmt=$conn->prepare('SELECT id FROM users WHERE email=? AND role=\'customer\' AND phone_signup_required=1 AND is_verified=0 LIMIT 1'); $stmt->bind_param('s',$email); $stmt->execute(); $user=$stmt->get_result()->fetch_assoc();
if (!$user || (int)($_SESSION['pending_phone_user_id'] ?? 0) !== (int)$user['id']) {
    $back('Sign in to continue verifying this account.');
}
$targetStmt=$conn->prepare("SELECT target FROM contact_challenges WHERE user_id=? AND purpose='signup_phone' AND consumed_at IS NULL ORDER BY id DESC LIMIT 1");
$targetStmt->bind_param('i',$user['id']); $targetStmt->execute(); $target=$targetStmt->get_result()->fetch_assoc()['target']??'';
if ($target==='' || !contact_allow($conn,'otp-phone-'.$target,5,900)) $back('Too many code attempts. Try again later.');
try { if (!contact_verify($conn,(int)$user['id'],'signup_phone',$code)) $back('The code is invalid, expired, or already used. Request a new code if needed.'); }
catch(Throwable $e) { error_log('Phone verification failed: '.get_class($e)); $back('Verification could not be completed. Try again or contact support.'); }
// contact_verify has committed the single-use OTP and phone/account transition.
// Re-read the account before issuing the same session used by normal login.
try {
    $session_stmt = $conn->prepare("SELECT u.id, u.auth_version, c.first_name FROM users u LEFT JOIN customers c ON c.user_id=u.id WHERE u.id=? AND u.role='customer' AND u.status='active' AND u.is_verified=1 AND u.phone_signup_required=1 LIMIT 1");
    $session_stmt->bind_param('i', $user['id']);
    $session_stmt->execute();
    $authenticated_user = $session_stmt->get_result()->fetch_assoc();
    if (!$authenticated_user) throw new RuntimeException('Verified account is unavailable.');
} catch (Throwable $e) {
    error_log('Phone signup session failed: ' . get_class($e));
    $back('Your account could not be signed in. Please try signing in again.');
}

session_regenerate_id(true);
unset($_SESSION['pending_phone_user_id'], $_SESSION['recovery_user_id'], $_SESSION['recovery_auth_version'], $_SESSION['recovery_expires_at'], $_SESSION['reset_user_id'], $_SESSION['reset_origin'], $_SESSION['auth_alert']);
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$_SESSION['user_id'] = (int)$authenticated_user['id'];
$_SESSION['role'] = 'customer';
$_SESSION['logged_in'] = true;
$_SESSION['auth_version'] = (int)$authenticated_user['auth_version'];
$_SESSION['first_name'] = trim((string)($authenticated_user['first_name'] ?? '')) ?: 'Customer';
session_policy_mark_authenticated();
customer_login_recovery_clear();
$resume_target = booking_auth_consume_destination('customer');
if ($resume_target === null) $_SESSION['signup_welcome'] = true;
header('Location: ../../' . ($resume_target ?? 'user_dashboard.php'));
exit;
