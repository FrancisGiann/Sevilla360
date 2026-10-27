<?php
require_once __DIR__ . '/../../includes/contact_verification.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../../auth.php'); exit; }
$back = static function (string $message, string $email=''): never {
    $_SESSION['auth_alert']=['title'=>'Notice','message'=>$message,'type'=>'warning'];
    header('Location: ../../auth.php'.($email!==''?'?verify_email='.rawurlencode($email):'')); exit;
};
if (!sms_ready()) $back('Phone verification is temporarily unavailable. Please try again later.');
if (empty($_POST['csrf_token']) || !hash_equals((string)($_SESSION['csrf_token']??''),(string)$_POST['csrf_token'])) $back('Security token expired. Refresh the page.');
if (!empty($_POST['website_url_honeypot']) || !check_rate_limit($conn,'register_attempt',3,60)) $back('Please try again later.');
$email=trim((string)($_POST['email']??'')); $first=trim((string)($_POST['first_name']??'')); $last=trim((string)($_POST['last_name']??''));
$password=(string)($_POST['password']??'');
try { $phone=unisms_normalize_mobile((string)($_POST['phone']??'')); } catch(Throwable $e) { $back('Enter a Philippine mobile number.'); }
if (!filter_var($email,FILTER_VALIDATE_EMAIL) || $first==='' || $last==='' || $password!==($_POST['confirm_password']??null) || !password_policy_validate($password)['valid'] || ($_POST['consent']??null)!=='1') $back('Check the required account details and try again.');
try {
    $conn->begin_transaction();
    $stmt=$conn->prepare('SELECT id FROM users WHERE email=? LIMIT 1'); $stmt->bind_param('s',$email); $stmt->execute(); if ($stmt->get_result()->num_rows) throw new RuntimeException('Email already registered. Sign in to continue verification.');
    $stmt=$conn->prepare('SELECT user_id FROM customer_verified_phones WHERE phone_e164=? AND verified_at IS NOT NULL LIMIT 1'); $stmt->bind_param('s',$phone); $stmt->execute(); if ($stmt->get_result()->num_rows) throw new RuntimeException('This number is already linked to an account. Use another number or contact support.');
    $hash=password_hash($password,PASSWORD_DEFAULT);
    $stmt=$conn->prepare("INSERT INTO users(email,password_hash,role,is_verified,phone_signup_required,consented_at) VALUES (?,?,'customer',0,1,NOW())"); $stmt->bind_param('ss',$email,$hash); $stmt->execute(); $id=(int)$conn->insert_id;
    $stmt=$conn->prepare('SELECT id,user_id FROM customers WHERE email=? LIMIT 1 FOR UPDATE'); $stmt->bind_param('s',$email); $stmt->execute(); $customer=$stmt->get_result()->fetch_assoc();
    if ($customer && $customer['user_id']!==null) throw new RuntimeException('This customer profile is already linked.');
    if ($customer) { $stmt=$conn->prepare('UPDATE customers SET user_id=?,first_name=?,last_name=? WHERE id=?'); $stmt->bind_param('issi',$id,$first,$last,$customer['id']); }
    else { $stmt=$conn->prepare('INSERT INTO customers(user_id,first_name,last_name,email) VALUES (?,?,?,?)'); $stmt->bind_param('isss',$id,$first,$last,$email); }
    $stmt->execute();
    $stmt=$conn->prepare('INSERT INTO customer_verified_phones(user_id,phone_e164) VALUES (?,?)'); $stmt->bind_param('is',$id,$phone); $stmt->execute();
    $conn->commit();
} catch(Throwable $e) { $conn->rollback(); $back($e instanceof RuntimeException?$e->getMessage():'Could not create the account. Check your details and try again.'); }
session_regenerate_id(true);
$_SESSION['pending_phone_user_id']=$id;
if (!contact_send_sms($conn,$id,$phone,'signup_phone')) $back('Account saved, but the code could not be sent. Check the number, use another eligible number if SMS is unsupported, or contact support. Sign in to request another code.',$email);
$back('A verification code was queued for your phone. Enter it to activate your account.',$email);
