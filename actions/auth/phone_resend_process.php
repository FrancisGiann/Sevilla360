<?php
require_once __DIR__ . '/../../includes/contact_verification.php';
header('Content-Type: application/json');
$out=static function(int $status,string $message): never { http_response_code($status); echo json_encode(['success'=>$status===200,'message'=>$message]); exit; };
$email=trim((string)($_POST['email']??''));
if ($_SERVER['REQUEST_METHOD']!=='POST' || !filter_var($email,FILTER_VALIDATE_EMAIL) || !hash_equals((string)($_SESSION['csrf_token']??''),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) $out(403,'Refresh the page and try again.');
if (!sms_ready()) $out(503,'Phone verification is temporarily unavailable. Contact support if you cannot continue.');
$stmt=$conn->prepare("SELECT u.id,p.phone_e164 FROM users u JOIN customer_verified_phones p ON p.user_id=u.id WHERE u.email=? AND u.role='customer' AND u.phone_signup_required=1 AND u.is_verified=0 LIMIT 1"); $stmt->bind_param('s',$email); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc();
if (!$row) $out(200,'If this account needs verification, a code request has been processed.');
try { if (!contact_send_sms($conn,(int)$row['id'],$row['phone_e164'],'signup_phone')) $out(429,'No code was sent. Wait a minute, check your number, use another eligible number if SMS is unsupported, or contact support.'); }
catch(Throwable $e) { error_log('Phone resend failed: '.get_class($e)); $out(503,'No code was sent. Try again later or contact support.'); }
$out(200,'A new verification code was queued for your phone.');
