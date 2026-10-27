<?php
declare(strict_types=1);
require_once __DIR__ . '/unisms_sms.php';
require_once __DIR__ . '/request_context.php';

function phone_signup_enabled(): bool { return ($_ENV['PHONE_SIGNUP_ENABLED'] ?? '0') === '1'; }
function sms_ready(): bool {
    return phone_signup_enabled() && ($_ENV['UNISMS_SMS_ENABLED'] ?? '0') === '1'
        && (int)($_ENV['UNISMS_DAILY_SMS_LIMIT'] ?? 0) > 0
        && (string)($_ENV['UNISMS_SECRET_KEY'] ?? '') !== '';
}
function contact_reset_destination(array $user): ?string {
    if ((int)($user['phone_signup_required'] ?? 0) !== 1) return (string)($user['email'] ?? '');
    $address=$user['recovery_email'] ?? null;
    return !empty($user['recovery_email_verified_at']) && is_string($address) && filter_var($address,FILTER_VALIDATE_EMAIL) ? $address : null;
}
function contact_profile_phone_allowed(array $user, string $phone): bool {
    return (int)($user['phone_signup_required'] ?? 0) !== 1 || $phone === ($user['phone_e164'] ?? '');
}
function contact_code(): string { return sprintf('%06d', random_int(0, 999999)); }
function contact_code_hash(int $userId, string $purpose, string $target, string $code): string {
    $key = (string)($_ENV['APP_KEY'] ?? '');
    if (strlen($key) < 32) throw new RuntimeException('Verification configuration unavailable.');
    return hash_hmac('sha256', $userId . "\0" . $purpose . "\0" . $target . "\0" . $code, $key);
}
/** Atomic durable limiter across workers; no client supplied key is stored raw. */
function contact_allow(mysqli $db, string $scope, int $limit, int $seconds): bool {
    $key = hash('sha256', $scope);
    $db->query('SET @contact_attempt = 1');
    $sql = 'INSERT INTO contact_throttles(scope_key,window_start,attempts,last_attempt) VALUES (?,NOW(),1,NOW()) ON DUPLICATE KEY UPDATE attempts=IF(window_start < DATE_SUB(NOW(), INTERVAL ? SECOND),(@contact_attempt := 1),(@contact_attempt := LEAST(attempts+1,?))),window_start=IF(@contact_attempt=1,NOW(),window_start),last_attempt=IF(@contact_attempt>?,last_attempt,NOW())';
    $stmt = $db->prepare($sql);
    $cap = $limit + 1;
    $stmt->bind_param('siii', $key, $seconds, $cap, $limit);
    $stmt->execute();
    $n = (int)($db->query('SELECT @contact_attempt AS n')->fetch_assoc()['n'] ?? $cap);
    return $n <= $limit;
}
function contact_issue(mysqli $db, int $userId, string $purpose, string $target, string $code): int {
    $hash = contact_code_hash($userId, $purpose, $target, $code);
    $stmt = $db->prepare("UPDATE contact_challenges SET consumed_at=NOW() WHERE user_id=? AND purpose=? AND consumed_at IS NULL");
    $stmt->bind_param('is', $userId, $purpose); $stmt->execute();
    $stmt = $db->prepare("INSERT INTO contact_challenges(user_id,purpose,target,code_hash,expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(),INTERVAL 5 MINUTE))");
    $stmt->bind_param('isss', $userId, $purpose, $target, $hash); $stmt->execute();
    return (int)$db->insert_id;
}
function contact_verify(mysqli $db, int $userId, string $purpose, string $code): bool {
    if (preg_match('/\A[0-9]{6}\z/D', $code) !== 1) return false;
    $db->begin_transaction();
    try {
        $stmt=$db->prepare('SELECT id,target,code_hash,expires_at,expires_at <= NOW() AS expired,failed_attempts,provider_status FROM contact_challenges WHERE user_id=? AND purpose=? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $stmt->bind_param('is',$userId,$purpose); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc();
        if (!$row || (int)$row['expired'] === 1 || (int)$row['failed_attempts'] >= 5 || ($purpose !== 'recovery_email' && !in_array($row['provider_status'],['pending','retrying','sent'],true))) { $db->commit(); return false; }
        if (!hash_equals($row['code_hash'],contact_code_hash($userId,$purpose,$row['target'],$code))) {
            $stmt=$db->prepare('UPDATE contact_challenges SET failed_attempts=failed_attempts+1 WHERE id=?'); $stmt->bind_param('i',$row['id']); $stmt->execute(); $db->commit(); return false;
        }
        if ($purpose === 'signup_phone' || $purpose === 'change_phone') {
            $stmt=$db->prepare('SELECT role,status,is_verified,phone_signup_required FROM users WHERE id=? FOR UPDATE'); $stmt->bind_param('i',$userId); $stmt->execute(); $user=$stmt->get_result()->fetch_assoc();
            if (!$user || $user['role'] !== 'customer' || $user['status'] !== 'active' || ($purpose==='signup_phone' && ((int)$user['is_verified']!==0 || (int)$user['phone_signup_required']!==1))) { $db->rollback(); return false; }
            $stmt=$db->prepare('UPDATE customer_verified_phones SET phone_e164=?,verified_at=NOW() WHERE user_id=?'); $stmt->bind_param('si',$row['target'],$userId); $stmt->execute();
            if ($purpose === 'signup_phone') { $stmt=$db->prepare('UPDATE users SET is_verified=1 WHERE id=? AND is_verified=0 AND phone_signup_required=1'); $stmt->bind_param('i',$userId); $stmt->execute(); if ($stmt->affected_rows!==1) { $db->rollback(); return false; } }
            $stmt=$db->prepare('UPDATE customers SET phone=? WHERE user_id=?'); $stmt->bind_param('si',$row['target'],$userId); $stmt->execute();
        } else {
            $stmt=$db->prepare('UPDATE users SET recovery_email=recovery_email_pending,recovery_email_verified_at=NOW(),recovery_email_pending=NULL,reset_token=NULL,reset_token_hash=NULL,reset_expires_at=NULL WHERE id=? AND recovery_email_pending=? AND phone_signup_required=1'); $stmt->bind_param('is',$userId,$row['target']); $stmt->execute(); if ($stmt->affected_rows!==1) { $db->rollback(); return false; }
        }
        $stmt=$db->prepare('UPDATE contact_challenges SET consumed_at=NOW() WHERE id=? AND consumed_at IS NULL'); $stmt->bind_param('i',$row['id']); $stmt->execute();
        $db->commit(); return true;
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}
function contact_sms_transport(string $endpoint,array $payload): array {
    if (!sms_ready()) throw new RuntimeException('SMS verification is unavailable.');
    $sender=(string)($_ENV['UNISMS_SENDER_ID'] ?? '');
    if (!preg_match('/\A[A-Za-z0-9 ]{2,20}\z/D',$sender)) throw new RuntimeException('SMS sender is unavailable.');
    $payload['sender_id']=$sender;
    $ch=curl_init($endpoint);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_USERPWD=>(string)$_ENV['UNISMS_SECRET_KEY'].':',CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);
    $body=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return ['http_status'=>$status,'body'=>is_string($body)?json_decode($body,true):null];
}
function contact_send_sms(mysqli $db,int $userId,string $phone,string $purpose): bool {
    if (!sms_ready()) return false;
    $phone=unisms_normalize_mobile($phone); $ip=request_client_ip();
    if (!contact_allow($db,'sms-ip-'.$ip,8,86400) || !contact_allow($db,'sms-phone-'.$phone,5,86400) || !contact_allow($db,'sms-cooldown-'.$phone,1,60) || !contact_allow($db,'sms-global-'.date('Y-m-d'),(int)$_ENV['UNISMS_DAILY_SMS_LIMIT'],86400)) return false;
    $code=contact_code(); $db->begin_transaction();
    try { $id=contact_issue($db,$userId,$purpose,$phone,$code); $db->commit(); } catch(Throwable $e) { $db->rollback(); throw $e; }
    try {
        $result=unisms_submit_signup_otp($phone,$code,'contact_sms_transport',$purpose);
        $stmt=$db->prepare('UPDATE contact_challenges SET provider_reference=?,provider_status=? WHERE id=? AND consumed_at IS NULL');
        $stmt->bind_param('ssi',$result['reference_id'],$result['status'],$id); $stmt->execute(); return $stmt->affected_rows===1;
    } catch(Throwable $e) { error_log('SMS request failed: '.get_class($e)); return false; }
}
