<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/rate_limit.php';
require_once __DIR__ . '/../../includes/recovery_security.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../../recovery_code.php'); exit(); }
$fail=static function (): never {
    $_SESSION['auth_alert']=['title'=>'Notice','message'=>'The code is invalid or expired. Request a new code if needed.','type'=>'warning'];
    header('Location: ../../recovery_code.php'); exit();
};
if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'],$_POST['csrf_token'])) $fail();
if (!recovery_sms_enabled() || !check_rate_limit($conn,'verify_recovery_sms',8,15)) $fail();
$email=is_string($_POST['email'] ?? null)?strtolower(trim($_POST['email'])):'';
$code=is_string($_POST['code'] ?? null)?trim($_POST['code']):'';
if (!filter_var($email,FILTER_VALIDATE_EMAIL) || preg_match('/\A[0-9]{6}\z/D',$code)!==1) $fail();
if (!contact_allow($conn,'recovery-check-'.$email,8,900)) $fail();
$conn->begin_transaction();
try {
    $stmt=$conn->prepare("SELECT u.id,u.auth_version,u.role,u.status,u.is_verified,u.phone_signup_required,p.phone_e164,p.verified_at FROM users u LEFT JOIN customer_verified_phones p ON p.user_id=u.id WHERE u.email=? LIMIT 1 FOR UPDATE");
    $stmt->bind_param('s',$email); $stmt->execute(); $user=$stmt->get_result()->fetch_assoc();
    if (!$user || !recovery_sms_eligible($user)) { $conn->commit(); $fail(); }
    $id=(int)$user['id'];
    if (!contact_allow($conn,'recovery-check-phone-'.$user['phone_e164'],8,900)) { $conn->commit(); $fail(); }
    $stmt=$conn->prepare('SELECT id,phone_e164,code_hash,provider_status,failed_attempts,expires_at <= NOW() AS expired FROM recovery_sms_challenges WHERE user_id=? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i',$id); $stmt->execute(); $challenge=$stmt->get_result()->fetch_assoc();
    if (!$challenge || (int)$challenge['failed_attempts']>=5 || (int)$challenge['expired']===1
        || !hash_equals($user['phone_e164'],$challenge['phone_e164'])
        || !in_array($challenge['provider_status'],['pending','retrying','sent'],true)) { $conn->commit(); $fail(); }
    if (!hash_equals($challenge['code_hash'],recovery_code_hash($id,$challenge['phone_e164'],$code))) {
        $stmt=$conn->prepare('UPDATE recovery_sms_challenges SET failed_attempts=failed_attempts+1 WHERE id=?'); $stmt->bind_param('i',$challenge['id']); $stmt->execute();
        $conn->commit(); $fail();
    }
    $stmt=$conn->prepare('UPDATE recovery_sms_challenges SET consumed_at=NOW() WHERE id=? AND consumed_at IS NULL'); $stmt->bind_param('i',$challenge['id']); $stmt->execute();
    if ($stmt->affected_rows!==1) { $conn->rollback(); $fail(); }
    $conn->commit();
    session_policy_reset();
    $_SESSION['recovery_user_id']=$id;
    $_SESSION['recovery_auth_version']=(int)$user['auth_version'];
    $_SESSION['recovery_expires_at']=time()+600;
    header('Location: ../../reset_password.php?channel=sms'); exit();
} catch (Throwable $error) { $conn->rollback(); error_log('Recovery verification failed: '.get_class($error)); $fail(); }
