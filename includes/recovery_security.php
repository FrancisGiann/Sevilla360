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
    if (!is_array($response) || ($response['http_status'] ?? null)!==201) throw new RuntimeException('SMS request was not accepted.');
    $message=$response['body']['message'] ?? null;
    if (!is_array($message) || !is_string($message['reference_id'] ?? null)
        || preg_match('/\Amsg_[A-Za-z0-9_-]+\z/D',$message['reference_id'])!==1
        || !in_array($message['status'] ?? null,['pending','retrying','sent'],true)) throw new RuntimeException('SMS response was invalid.');
    return ['reference_id'=>$message['reference_id'],'status'=>$message['status']];
}
function recovery_grant_valid(array $session, array $user): bool {
    return (int)($session['recovery_user_id'] ?? 0) === (int)($user['id'] ?? 0)
        && (int)($session['recovery_auth_version'] ?? -1) === (int)($user['auth_version'] ?? -2)
        && (int)($session['recovery_expires_at'] ?? 0) > time()
        && ($user['role'] ?? '') === 'customer' && ($user['status'] ?? '') === 'active';
}
