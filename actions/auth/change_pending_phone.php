<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/contact_verification.php';
header('Content-Type: application/json');
$out=static function(bool $ok,string $message): never { echo json_encode(['success'=>$ok,'message'=>$message]); exit; };
$data=json_decode(file_get_contents('php://input'),true);
if ($_SERVER['REQUEST_METHOD']!=='POST' || !is_array($data) || !hash_equals((string)($_SESSION['csrf_token']??''),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) $out(false,'Refresh the page and try again.');
$id=(int)($_SESSION['pending_phone_user_id']??0); $email=trim((string)($data['email']??''));
if ($id<=0 || !filter_var($email,FILTER_VALIDATE_EMAIL)) $out(false,'Sign in with your email and password before changing the number.');
try { $phone=unisms_normalize_mobile((string)($data['phone']??'')); } catch(Throwable $e) { $out(false,'Enter a Philippine mobile number.'); }
$stmt=$conn->prepare("SELECT id FROM users WHERE id=? AND email=? AND role='customer' AND is_verified=0 AND phone_signup_required=1 LIMIT 1");
$stmt->bind_param('is',$id,$email); $stmt->execute(); if (!$stmt->get_result()->fetch_assoc()) $out(false,'Sign in with your email and password again.');
$ownerStmt=$conn->prepare('SELECT user_id FROM customer_verified_phones WHERE phone_e164=? AND verified_at IS NOT NULL LIMIT 1');
$ownerStmt->bind_param('s',$phone); $ownerStmt->execute(); if ($ownerStmt->get_result()->fetch_assoc()) $out(false,'This number is linked to another account. Use another number or contact support.');
try {
    $stmt=$conn->prepare('UPDATE customer_verified_phones SET phone_e164=?,verified_at=NULL WHERE user_id=? AND verified_at IS NULL');
    $stmt->bind_param('si',$phone,$id); $stmt->execute();
} catch(Throwable $e) { $out(false,'This number is linked to another account. Use another number or contact support.'); }
try { if (!contact_send_sms($conn,$id,$phone,'signup_phone')) $out(false,'No code was sent. Wait, use another eligible number, or contact support.'); }
catch(Throwable $e) { $out(false,'No code was sent. Try again later.'); }
$out(true,'A code was queued for the new number. Enter it above to activate your account.');
