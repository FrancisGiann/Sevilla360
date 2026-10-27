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
// Limit guesses for the current verified number as well as the account and IP.
$stmt=$conn->prepare("SELECT p.phone_e164 FROM users u JOIN customer_verified_phones p ON p.user_id=u.id WHERE u.email=? AND p.verified_at IS NOT NULL LIMIT 1");
$stmt->bind_param('s',$email); $stmt->execute(); $target=$stmt->get_result()->fetch_assoc()['phone_e164']??'';
if ($target!=='' && !contact_allow($conn,'recovery-check-phone-'.$target,8,900)) $fail();
try { $grant=recovery_consume_code($conn,$email,$code); }
catch (Throwable $error) { error_log('Recovery verification failed: '.get_class($error)); $fail(); }
if (!$grant) $fail();
session_policy_reset();
$_SESSION['recovery_user_id']=$grant['user_id'];
$_SESSION['recovery_auth_version']=$grant['auth_version'];
$_SESSION['recovery_phone_e164']=$grant['phone_e164'];
$_SESSION['recovery_expires_at']=time()+600;
header('Location: ../../reset_password.php?channel=sms'); exit();
