<?php
require_once __DIR__ . '/../../includes/contact_verification.php';
$email=trim((string)($_POST['email']??''));
$back=static function(string $message) use ($email): never { $_SESSION['auth_alert']=['title'=>'Notice','message'=>$message,'type'=>'warning']; header('Location: ../../auth.php?verify_email='.rawurlencode($email)); exit; };
if ($_SERVER['REQUEST_METHOD']!=='POST' || !filter_var($email,FILTER_VALIDATE_EMAIL) || empty($_POST['csrf_token']) || !hash_equals((string)($_SESSION['csrf_token']??''),(string)$_POST['csrf_token'])) $back('Refresh the page and try again.');
$code=(string)($_POST['verification_code']??'');
$ip=request_client_ip();
if (!contact_allow($conn,'otp-ip-'.$ip,30,900) || !contact_allow($conn,'otp-email-'.strtolower($email),5,900)) $back('Too many code attempts. Try again later.');
$stmt=$conn->prepare('SELECT id FROM users WHERE email=? AND role=\'customer\' AND phone_signup_required=1 AND is_verified=0 LIMIT 1'); $stmt->bind_param('s',$email); $stmt->execute(); $user=$stmt->get_result()->fetch_assoc();
if (!$user) $back('This account is unavailable for phone verification.');
$targetStmt=$conn->prepare("SELECT target FROM contact_challenges WHERE user_id=? AND purpose='signup_phone' AND consumed_at IS NULL ORDER BY id DESC LIMIT 1");
$targetStmt->bind_param('i',$user['id']); $targetStmt->execute(); $target=$targetStmt->get_result()->fetch_assoc()['target']??'';
if ($target==='' || !contact_allow($conn,'otp-phone-'.$target,5,900)) $back('Too many code attempts. Try again later.');
try { if (!contact_verify($conn,(int)$user['id'],'signup_phone',$code)) $back('The code is invalid, expired, or already used. Request a new code if needed.'); }
catch(Throwable $e) { error_log('Phone verification failed: '.get_class($e)); $back('Verification could not be completed. Try again or contact support.'); }
unset($_SESSION['pending_phone_user_id']);
$_SESSION['auth_alert']=['title'=>'Success','message'=>'Phone verified. Sign in with your email and password. You can confirm your recovery email in Settings.','type'=>'success'];
header('Location: ../../auth.php'); exit;
