<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/session_account_guard.php';
require_once __DIR__ . '/../../includes/contact_verification.php';
require_once __DIR__ . '/../../includes/mailer.php';
header('Content-Type: application/json');
$out=static function(bool $ok,string $message): never { echo json_encode(['success'=>$ok,'message'=>$message]); exit; };
if ($_SERVER['REQUEST_METHOD']!=='POST' || ($_SESSION['role']??'')!=='customer' || !hash_equals((string)($_SESSION['csrf_token']??''),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) $out(false,'Unauthorized request.');
if (!session_account_require_active_json($conn,['customer'],['success'=>false,'message'=>'Unauthorized request.'],['success'=>false,'message'=>'Account status unavailable.'])) exit;
$userId=(int)$_SESSION['user_id']; $data=json_decode(file_get_contents('php://input'),true); $action=is_array($data)?($data['action']??''):'';
$stmt=$conn->prepare('SELECT phone_signup_required,email,recovery_email,recovery_email_pending FROM users WHERE id=?'); $stmt->bind_param('i',$userId); $stmt->execute(); $user=$stmt->get_result()->fetch_assoc();
if (!$user || (int)$user['phone_signup_required']!==1) $out(false,'This verification flow is unavailable for this account.');
if ($action==='request_phone') {
    try { $phone=unisms_normalize_mobile((string)($data['phone']??'')); } catch(Throwable $e) { $out(false,'Enter a Philippine mobile number.'); }
    $stmt=$conn->prepare('SELECT user_id FROM customer_verified_phones WHERE phone_e164=? AND verified_at IS NOT NULL'); $stmt->bind_param('s',$phone); $stmt->execute(); $owner=$stmt->get_result()->fetch_assoc();
    if ($owner && (int)$owner['user_id']!==$userId) $out(false,'This number is already linked to another account.');
    try { if (!contact_send_sms($conn,$userId,$phone,'change_phone')) $out(false,'No code was sent. Wait a minute, check the number, use another eligible number if SMS is unsupported, or contact support.'); }
    catch(Throwable $e) { $out(false,'No code was sent. Try again later.'); }
    $out(true,'Code queued for the new number. Your old verified number remains until you confirm it.');
}
if ($action==='verify_phone') {
    if (!contact_allow($conn,'change-phone-'.request_client_ip(),30,900) || !contact_allow($conn,'change-phone-user-'.$userId,5,900)) $out(false,'Too many attempts. Try later.');
    $targetStmt=$conn->prepare("SELECT target FROM contact_challenges WHERE user_id=? AND purpose='change_phone' AND consumed_at IS NULL ORDER BY id DESC LIMIT 1"); $targetStmt->bind_param('i',$userId); $targetStmt->execute(); $target=$targetStmt->get_result()->fetch_assoc()['target']??'';
    if ($target==='' || !contact_allow($conn,'change-phone-target-'.$target,5,900)) $out(false,'Too many attempts. Try later.');
    try { $ok=contact_verify($conn,$userId,'change_phone',(string)($data['code']??'')); }
    catch(Throwable $e) { $out(false,'Could not verify this number. Check if another account already uses it.'); }
    $out($ok,$ok?'Phone number verified.':'Invalid, expired, or already used code.');
}
if ($action==='request_recovery_email') {
    $email=trim((string)($data['email']??'')); if (!filter_var($email,FILTER_VALIDATE_EMAIL)) $out(false,'Enter a valid recovery email.');
    if (!contact_allow($conn,'recovery-ip-'.request_client_ip(),8,86400) || !contact_allow($conn,'recovery-user-'.$userId,5,86400) || !contact_allow($conn,'recovery-cooldown-'.$userId,1,60)) $out(false,'Wait before requesting another email.');
    $code=contact_code();
    try {
        $conn->begin_transaction();
        $stmt=$conn->prepare('UPDATE users SET recovery_email_pending=? WHERE id=?'); $stmt->bind_param('si',$email,$userId); $stmt->execute();
        contact_issue($conn,$userId,'recovery_email',$email,$code); $conn->commit();
        $safe=htmlspecialchars($code,ENT_QUOTES,'UTF-8');
        send_custom_email($email,'Customer','Confirm your Sevilla360 recovery email','<p>Enter this code in your account settings to confirm this inbox: <strong>'.$safe.'</strong>. It expires in 5 minutes. If you did not request this, ignore it.</p>');
    } catch(Throwable $e) { if ($conn->errno) $conn->rollback(); $out(false,'Verification email could not be sent. Try again later.'); }
    $out(true,'Confirmation code sent. Your recovery address changes only after you enter it.');
}
if ($action==='verify_recovery_email') {
    if (!contact_allow($conn,'recovery-verify-ip-'.request_client_ip(),30,900) || !contact_allow($conn,'recovery-verify-user-'.$userId,5,900)) $out(false,'Too many attempts. Try later.');
    try { $ok=contact_verify($conn,$userId,'recovery_email',(string)($data['code']??'')); }
    catch(Throwable $e) { $out(false,'Could not confirm recovery email. Try again.'); }
    $out($ok,$ok?'Recovery email confirmed.':'Invalid, expired, or already used code.');
}
$out(false,'Invalid action.');
