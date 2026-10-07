<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../includes/resend_mailer.php';

$apiKey = resend_mail_config('RESEND_API_KEY');
$sender = resend_mail_config('RESEND_FROM_EMAIL');
$replyTo = resend_mail_config('RESEND_REPLY_TO');
$keyConfigured = $apiKey !== '' && preg_match('/[\x00-\x20\x7F]/', $apiKey) !== 1;
$senderConfigured = $sender !== '' && filter_var($sender, FILTER_VALIDATE_EMAIL) !== false;
$replyToValid = $replyTo === '' || filter_var($replyTo, FILTER_VALIDATE_EMAIL) !== false;
$curlAvailable = extension_loaded('curl') && function_exists('curl_init');

echo 'Resend API key configured: ' . ($keyConfigured ? 'yes' : 'no') . PHP_EOL;
echo 'Resend sender address valid: ' . ($senderConfigured ? 'yes' : 'no') . PHP_EOL;
echo 'Resend reply-to address: ' . ($replyTo === '' ? 'not set (optional)' : ($replyToValid ? 'valid' : 'invalid')) . PHP_EOL;
echo 'PHP cURL available: ' . ($curlAvailable ? 'yes' : 'no') . PHP_EOL;

exit($keyConfigured && $senderConfigured && $replyToValid && $curlAvailable ? 0 : 1);
