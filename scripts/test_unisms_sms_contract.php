<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/unisms_sms.php';

$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};
$throws = static function (callable $callback): bool {
    try { $callback(); } catch (InvalidArgumentException | RuntimeException $error) { return true; }
    return false;
};

$assert(unisms_normalize_mobile('0917 123 4567') === '+639171234567', 'local mobile normalization');
$assert(unisms_normalize_mobile('639171234567') === '+639171234567', 'country code normalization');
$assert($throws(static fn() => unisms_normalize_mobile('+12025550123')), 'non-PH number rejected');
$assert($throws(static fn() => unisms_signup_otp_payload('09171234567', '12<script>')), 'invalid code rejected');
$signupText = unisms_signup_otp_payload('09171234567', '123456')['content'];
$changeText = unisms_signup_otp_payload('09171234567', '123456', 'change_phone')['content'];
$assert(str_contains($signupText, 'Sevilla360 signup code: 123456') && str_contains($signupText, 'Expires in 5 minutes.') && str_contains($signupText, 'Do not share this code.'), 'branded signup text and expiry');
$assert(str_contains($changeText, 'Sevilla360 phone change code: 123456') && $changeText !== $signupText, 'phone-change purpose is distinct');
$assert($throws(static fn() => unisms_signup_otp_payload('09171234567', '123456', 'password_reset')), 'password reset cannot use signup SMS purpose');

$calls = 0;
$mock = static function (string $endpoint, array $payload) use (&$calls, $assert): array {
    $calls++;
    $assert($endpoint === UNISMS_SMS_ENDPOINT, 'fixed provider endpoint');
    $assert($payload['recipient'] === '+639171234567' && $payload['sender_id'] === 'Unisoft', 'default sender and normalized recipient');
    $assert(!str_contains($payload['content'], 'http') && strlen($payload['content']) <= 160 && preg_match('/\A[\x20-\x7E]+\z/D', $payload['content']) === 1, 'single-segment GSM-friendly link-free OTP');
    return ['http_status' => 201, 'body' => ['message' => ['reference_id' => 'msg_test-1', 'status' => 'pending']]];
};
$result = unisms_submit_signup_otp('09171234567', '123456', $mock);
$assert($result === ['reference_id' => 'msg_test-1', 'status' => 'pending'] && $calls === 1, 'queued is not verified');
$changeResult = unisms_submit_signup_otp('09171234567', '654321', $mock, 'change_phone');
$assert($changeResult['status'] === 'pending' && $calls === 2, 'phone-change send uses the same provider contract with a distinct purpose');
$assert($throws(static fn() => unisms_submit_signup_otp('09171234567', '123456', static fn() => ['http_status' => 422, 'body' => []])), 'provider rejection fails closed');
$assert($throws(static fn() => unisms_submit_signup_otp('09171234567', '123456', static fn() => ['http_status' => 0, 'body' => null])), 'provider timeout fails closed');
$assert($throws(static fn() => unisms_submit_signup_otp('09171234567', '123456', static fn() => ['http_status' => 201, 'body' => ['message' => ['status' => 'pending']]])), 'malformed acceptance fails closed');
echo "UniSMS offline contract: {$checks} checks passed.\n";
