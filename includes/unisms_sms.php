<?php
declare(strict_types=1);

/**
 * UniSMS request contract for explicitly enabled phone verification.
 * The callback makes offline tests possible without credentials or network access.
 */
const UNISMS_SMS_ENDPOINT = 'https://unismsapi.com/api/sms';
const UNISMS_DEFAULT_SENDER_ID = 'Unisoft';

function unisms_normalize_mobile(string $input): string
{
    $phone = preg_replace('/[\s().-]+/', '', trim($input));
    if (!is_string($phone)) throw new InvalidArgumentException('Enter a Philippine mobile number.');
    if (preg_match('/\A09[0-9]{9}\z/D', $phone)) return '+63' . substr($phone, 1);
    if (preg_match('/\A639[0-9]{9}\z/D', $phone)) return '+' . $phone;
    if (preg_match('/\A\+639[0-9]{9}\z/D', $phone)) return $phone;
    throw new InvalidArgumentException('Enter a Philippine mobile number.');
}

/** Number prefixes cannot prove carrier membership after mobile-number porting. */
function unisms_signup_otp_payload(string $phone, string $code, string $purpose = 'signup_phone'): array
{
    if (preg_match('/\A[0-9]{6}\z/D', $code) !== 1) throw new InvalidArgumentException('Verification code must contain six digits.');
    $label = match ($purpose) {
        'signup_phone' => 'signup',
        'change_phone' => 'phone change',
        default => throw new InvalidArgumentException('Unsupported SMS verification purpose.'),
    };
    return [
        'recipient' => unisms_normalize_mobile($phone),
        'content' => 'Sevilla360 ' . $label . ' code: ' . $code . '. Expires in 5 minutes. Do not share this code.',
        'sender_id' => UNISMS_DEFAULT_SENDER_ID,
    ];
}

/**
 * The caller must first reserve a durable send allowance and supply a server-
 * side transport. A queued response is never treated as delivered or verified.
 * Return only provider reference/status; never expose or log the OTP.
 */
function unisms_submit_signup_otp(string $phone, string $code, callable $transport, string $purpose = 'signup_phone'): array
{
    $payload = unisms_signup_otp_payload($phone, $code, $purpose);
    $response = $transport(UNISMS_SMS_ENDPOINT, $payload);
    if (!is_array($response) || !isset($response['http_status']) || !is_int($response['http_status'])) {
        throw new RuntimeException('SMS delivery response was unavailable.');
    }
    if ($response['http_status'] !== 201) {
        if (in_array($response['http_status'], [400, 401, 403, 422], true)) throw new UnexpectedValueException('SMS request was rejected.');
        throw new RuntimeException('SMS delivery status is unknown.');
    }
    $message = $response['body']['message'] ?? null;
    if (!is_array($message) || !is_string($message['reference_id'] ?? null)
        || preg_match('/\Amsg_[A-Za-z0-9_-]+\z/D', $message['reference_id']) !== 1
        || !in_array($message['status'] ?? null, ['pending', 'retrying', 'sent'], true)) {
        throw new RuntimeException('SMS delivery response was invalid.');
    }
    return ['reference_id' => $message['reference_id'], 'status' => $message['status']];
}
