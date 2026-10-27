<?php
declare(strict_types=1);
require_once __DIR__ . '/contact_verification.php';

function recovery_sms_enabled(): bool { return ($_ENV['PASSWORD_SMS_RECOVERY_ENABLED'] ?? '0') === '1' && sms_ready(); }
function recovery_sms_eligible(array $user): bool {
    return ($user['role'] ?? '') === 'customer' && ($user['status'] ?? '') === 'active'
        && (int)($user['phone_signup_required'] ?? 0) === 1 && (int)($user['is_verified'] ?? 0) === 1
        && !empty($user['verified_at']) && is_string($user['phone_e164'] ?? null)
        && preg_match('/\A\+639[0-9]{9}\z/D', $user['phone_e164']) === 1;
}
function recovery_code_hash(int $userId, string $phone, string $code): string {
    $key = (string)($_ENV['APP_KEY'] ?? '');
    if (strlen($key) < 32) throw new RuntimeException('Recovery configuration unavailable.');
    return hash_hmac('sha256', $userId . "\0reset_phone\0" . $phone . "\0" . $code, $key);
}
function recovery_sms_payload(string $phone, string $code): array {
    if (preg_match('/\A[0-9]{6}\z/D', $code) !== 1) throw new InvalidArgumentException('Invalid code.');
    return ['recipient'=>unisms_normalize_mobile($phone),
        'content'=>'Sevilla360 password reset code: '.$code.'. Expires in 5 minutes. Do not share this code.',
        'sender_id'=>UNISMS_DEFAULT_SENDER_ID];
}
function recovery_submit_sms(string $phone, string $code, callable $transport): array {
    $response=$transport(UNISMS_SMS_ENDPOINT,recovery_sms_payload($phone,$code));
    if (!is_array($response) || ($response['http_status'] ?? null)!==201) {
        if (is_array($response) && in_array($response['http_status'] ?? null,[400,401,403,422],true)) throw new UnexpectedValueException('SMS request was rejected.');
        throw new RuntimeException('SMS delivery status is unknown.');
    }
    $message=$response['body']['message'] ?? null;
    if (!is_array($message) || !is_string($message['reference_id'] ?? null)
        || preg_match('/\Amsg_[A-Za-z0-9_-]+\z/D',$message['reference_id'])!==1
        || !in_array($message['status'] ?? null,['pending','retrying','sent'],true)) throw new RuntimeException('SMS response was invalid.');
    return ['reference_id'=>$message['reference_id'],'status'=>$message['status']];
}
function recovery_grant_valid(array $session, array $user): bool {
    return (int)($session['recovery_user_id'] ?? 0) === (int)($user['id'] ?? 0)
        && (int)($session['recovery_auth_version'] ?? -1) === (int)($user['auth_version'] ?? -2)
        && recovery_sms_eligible($user)
        && is_string($session['recovery_phone_e164'] ?? null)
        && hash_equals($session['recovery_phone_e164'], $user['phone_e164'])
        && (int)($session['recovery_expires_at'] ?? 0) > time()
        && ($user['role'] ?? '') === 'customer' && ($user['status'] ?? '') === 'active';
}

/** Consume one recovery code under row locks. Returns only a reset grant identity. */
function recovery_consume_code(mysqli $db, string $email, string $code): ?array {
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || preg_match('/\A[0-9]{6}\z/D',$code)!==1) return null;
    $db->begin_transaction();
    try {
        $stmt=$db->prepare("SELECT u.id,u.auth_version,u.role,u.status,u.is_verified,u.phone_signup_required,p.phone_e164,p.verified_at FROM users u LEFT JOIN customer_verified_phones p ON p.user_id=u.id WHERE u.email=? LIMIT 1 FOR UPDATE");
        $stmt->bind_param('s',$email); $stmt->execute(); $user=$stmt->get_result()->fetch_assoc();
        if (!$user || !recovery_sms_eligible($user)) { $db->commit(); return null; }
        $id=(int)$user['id'];
        $stmt=$db->prepare('SELECT id,phone_e164,code_hash,provider_status,failed_attempts,expires_at <= NOW() AS expired FROM recovery_sms_challenges WHERE user_id=? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i',$id); $stmt->execute(); $challenge=$stmt->get_result()->fetch_assoc();
        if (!$challenge || (int)$challenge['failed_attempts']>=5 || (int)$challenge['expired']===1
            || !hash_equals($user['phone_e164'],$challenge['phone_e164'])
            || !in_array($challenge['provider_status'],['pending','retrying','sent'],true)) { $db->commit(); return null; }
        if (!hash_equals($challenge['code_hash'],recovery_code_hash($id,$challenge['phone_e164'],$code))) {
            $stmt=$db->prepare('UPDATE recovery_sms_challenges SET failed_attempts=failed_attempts+1 WHERE id=?'); $stmt->bind_param('i',$challenge['id']); $stmt->execute();
            $db->commit(); return null;
        }
        $stmt=$db->prepare('UPDATE recovery_sms_challenges SET consumed_at=NOW() WHERE id=? AND consumed_at IS NULL'); $stmt->bind_param('i',$challenge['id']); $stmt->execute();
        if ($stmt->affected_rows!==1) { $db->rollback(); return null; }
        $db->commit();
        return ['user_id'=>$id,'auth_version'=>(int)$user['auth_version'],'phone_e164'=>$user['phone_e164']];
    } catch(Throwable $error) { $db->rollback(); throw $error; }
}

/** The password transition and challenge invalidation commit together. */
function recovery_reset_password(mysqli $db, array $grant, string $passwordHash): bool {
    $id=(int)($grant['recovery_user_id']??0);
    if ($id<=0) return false;
    $db->begin_transaction();
    try {
        $stmt=$db->prepare('SELECT u.id,u.role,u.status,u.auth_version,u.is_verified,u.phone_signup_required,p.phone_e164,p.verified_at FROM users u LEFT JOIN customer_verified_phones p ON p.user_id=u.id WHERE u.id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i',$id); $stmt->execute(); $user=$stmt->get_result()->fetch_assoc();
        if (!$user || !recovery_grant_valid($grant,$user)) { $db->commit(); return false; }
        $expected=(int)$user['auth_version'];
        $stmt=$db->prepare("UPDATE users SET password_hash=?,auth_version=auth_version+1,reset_token=NULL,reset_token_hash=NULL,reset_expires_at=NULL WHERE id=? AND auth_version=? AND role='customer' AND status='active'");
        $stmt->bind_param('sii',$passwordHash,$id,$expected); $stmt->execute();
        if ($stmt->affected_rows!==1) { $db->rollback(); return false; }
        $stmt=$db->prepare('UPDATE recovery_sms_challenges SET consumed_at=NOW() WHERE user_id=? AND consumed_at IS NULL');
        $stmt->bind_param('i',$id); $stmt->execute();
        $db->commit(); return true;
    } catch(Throwable $error) { $db->rollback(); throw $error; }
}
