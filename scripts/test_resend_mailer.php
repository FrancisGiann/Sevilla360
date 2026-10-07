<?php
/** Isolated Resend transport and mail-flow regression test; never contacts Resend. */

function resend_test_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function resend_test_assert(bool $condition, string $message): void
{
    $GLOBALS['resend_test_assertions'] = ($GLOBALS['resend_test_assertions'] ?? 0) + 1;
    if (!$condition) resend_test_fail($message);
}

function resend_test_run_child(string $mode): string
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-n', __FILE__, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) resend_test_fail('unable to start isolated PHP worker');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) resend_test_fail(trim($stderr) !== '' ? trim($stderr) : trim($stdout));
    return trim($stdout);
}

$mode = $argv[1] ?? '';
if ($mode === '') {
    $worker = resend_test_run_child('--worker');
    $noCurl = resend_test_run_child('--no-curl');
    if (!str_starts_with($worker, 'Resend transport and mail-flow cases passed (') || !str_ends_with($worker, ' assertions).')) resend_test_fail('unexpected worker result');
    if ($noCurl !== 'Missing-cURL behavior passed.') resend_test_fail('unexpected missing-cURL result');
    echo 'PASS: ' . $worker . ' ' . $noCurl . "\n";
    exit(0);
}

if ($mode === '--no-curl') {
    $_ENV['RESEND_API_KEY'] = 're_test_key_only';
    $_SERVER['RESEND_API_KEY'] = 're_test_key_only';
    $_ENV['RESEND_FROM_EMAIL'] = 'reservations@example.test';
    $_SERVER['RESEND_FROM_EMAIL'] = 'reservations@example.test';
    require_once __DIR__ . '/../includes/resend_mailer.php';
    try {
        resend_mail_send('customer@example.test', 'Customer', 'Subject', '<p>Body</p>', 'Sevilla360 Accounts');
        resend_test_fail('missing cURL unexpectedly sent');
    } catch (Exception $e) {
        resend_test_assert($e->getMessage() === 'Email delivery is unavailable because cURL is not installed.', 'missing-cURL error must be safe and specific');
    }
    echo "Missing-cURL behavior passed.\n";
    exit(0);
}

if ($mode !== '--worker') resend_test_fail('unknown test mode');

$GLOBALS['resend_test_assertions'] = 0;

foreach ([
    'CURLOPT_POST' => 1,
    'CURLOPT_HTTPHEADER' => 2,
    'CURLOPT_POSTFIELDS' => 3,
    'CURLOPT_RETURNTRANSFER' => 4,
    'CURLOPT_CONNECTTIMEOUT' => 5,
    'CURLOPT_TIMEOUT' => 6,
    'CURLOPT_FOLLOWLOCATION' => 7,
    'CURLOPT_MAXREDIRS' => 8,
    'CURLOPT_SSL_VERIFYPEER' => 9,
    'CURLOPT_SSL_VERIFYHOST' => 10,
    'CURLOPT_NOSIGNAL' => 11,
    'CURLOPT_PROTOCOLS' => 12,
    'CURLOPT_REDIR_PROTOCOLS' => 13,
    'CURLOPT_PROTOCOLS_STR' => 14,
    'CURLOPT_REDIR_PROTOCOLS_STR' => 15,
    'CURLPROTO_HTTPS' => 16,
    'CURLINFO_RESPONSE_CODE' => 17,
] as $constant => $value) {
    if (!defined($constant)) define($constant, $value);
}
if (!defined('MYSQLI_ASSOC')) define('MYSQLI_ASSOC', 1);

$GLOBALS['resend_test_mode'] = 'success';
$GLOBALS['resend_test_http_status'] = 201;
$GLOBALS['resend_test_http_body'] = '{"id":"email_test_123"}';
$GLOBALS['resend_test_last_request'] = null;
$GLOBALS['resend_test_close_count'] = 0;
$GLOBALS['resend_test_receipt_category'] = 'Hotel Room';

if (!function_exists('curl_init')) {
    function curl_init($url = null) { return (object)['url' => $url, 'options' => []]; }
    function curl_setopt_array($handle, array $options) { $handle->options = $options; return true; }
    function curl_exec($handle)
    {
        $headers = $handle->options[CURLOPT_HTTPHEADER] ?? [];
        $authorization = '';
        foreach ($headers as $header) {
            if (str_starts_with($header, 'Authorization: ')) $authorization = substr($header, strlen('Authorization: '));
        }
        $payload = json_decode((string)($handle->options[CURLOPT_POSTFIELDS] ?? ''), true);
        $GLOBALS['resend_test_last_request'] = [
            'url' => $handle->url,
            'options' => $handle->options,
            'authorization' => $authorization,
            'payload' => $payload,
        ];
        return $GLOBALS['resend_test_mode'] === 'transport' ? false : $GLOBALS['resend_test_http_body'];
    }
    function curl_getinfo($handle, $option = null) { return $GLOBALS['resend_test_http_status']; }
    function curl_error($handle) { return 'RAW_CURL_SECRET_SENTINEL'; }
    function curl_errno($handle) { return 28; }
    function curl_close($handle) { $GLOBALS['resend_test_close_count']++; }
}

class mysqli_result
{
    private array $rows;
    private int $position = 0;
    public function __construct(array $rows) { $this->rows = $rows; }
    public function fetch_assoc() { return $this->rows[$this->position++] ?? null; }
    public function fetch_all($mode = MYSQLI_ASSOC) { return $this->rows; }
}

class mysqli_stmt
{
    private string $sql;
    private array $params = [];
    public function __construct(string $sql) { $this->sql = $sql; }
    public function bind_param($types, &...$values)
    {
        $this->params = array_map(static fn(&$value) => $value, $values);
        return true;
    }
    public function execute() { return true; }
    public function get_result() { return $GLOBALS['resend_test_db']->resultFor($this->sql, $this->params); }
    public function close() { return true; }
}

class mysqli
{
    public function query(string $sql)
    {
        return new mysqli_result([
            ['setting_key' => 'biz_name', 'setting_value' => 'Sevilla360'],
            ['setting_key' => 'biz_tagline', 'setting_value' => 'LUXURY RESORT & EVENTS'],
            ['setting_key' => 'biz_email', 'setting_value' => 'reservations@misevillas.com'],
        ]);
    }
    public function prepare(string $sql) { return new mysqli_stmt($sql); }
}

class ResendTestDatabase
{
    private array $booking = [
        'id' => 55,
        'reference_no' => 'REF-TEST-55',
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-03',
        'guests_count' => 3,
        'base_amount' => 10000,
        'total_amount' => 12000,
        'amount_paid' => 1500,
        'payment_scheme' => '50% Full',
        'venue_name' => 'Palm Hall',
        'category' => 'Hotel Room',
        'room_type' => 'Deluxe',
        'room_number' => '204',
        'new_start_date' => '2026-11-04',
        'new_end_date' => '2026-11-05',
    ];
    public function resultFor(string $sql, array $params): mysqli_result
    {
        if (str_contains($sql, 'booking_line_items')) return new mysqli_result([]);
        if (str_contains($sql, 'booking_rooms')) return new mysqli_result([]);
        if (str_contains($sql, 'booking_villa_details')) return new mysqli_result([['stay_type' => 'Overnight', 'overnight_stay_inclusions' => 'Breakfast']]);
        if (str_contains($sql, 'FROM payments p')) return new mysqli_result([]);
        if (str_contains($sql, 'FROM cancellations')) return new mysqli_result([['fee_percent' => 0, 'fee_deducted' => 0, 'refund_amount' => 1500, 'status' => 'Pending']]);
        if (str_contains($sql, 'reschedule_requests rr')) return new mysqli_result([$this->booking]);
        if (str_contains($sql, 'FROM bookings b')) {
            $booking = $this->booking;
            if (str_contains($sql, 'payment_scheme')) {
                $booking['category'] = $GLOBALS['resend_test_receipt_category'];
                return new mysqli_result([$booking]);
            }
            if (str_contains($sql, 'reference_no = ?')) {
                $booking['category'] = $GLOBALS['resend_test_receipt_category'];
                return new mysqli_result([$booking]);
            }
            return new mysqli_result([$booking]);
        }
        return new mysqli_result([]);
    }
}

$_ENV['RESEND_API_KEY'] = 're_test_key_only_DO_NOT_PRINT';
$_SERVER['RESEND_API_KEY'] = $_ENV['RESEND_API_KEY'];
$_ENV['RESEND_FROM_EMAIL'] = 'reservations@misevillas.com';
$_SERVER['RESEND_FROM_EMAIL'] = $_ENV['RESEND_FROM_EMAIL'];
$_ENV['RESEND_REPLY_TO'] = '';
$_SERVER['RESEND_REPLY_TO'] = '';
$GLOBALS['resend_test_db'] = new ResendTestDatabase();
$conn = new mysqli();
require_once __DIR__ . '/../includes/mailer.php';

function resend_test_send(callable $callback, string $expectedSubject, string $expectedFrom, ?string $bodyNeedle = null): array
{
    $GLOBALS['resend_test_last_request'] = null;
    $GLOBALS['resend_test_mode'] = 'success';
    $GLOBALS['resend_test_http_status'] = 201;
    $GLOBALS['resend_test_http_body'] = '{"id":"email_test_123"}';
    $callback();
    $request = $GLOBALS['resend_test_last_request'];
    resend_test_assert(is_array($request), 'mail flow did not call fake HTTP transport');
    resend_test_assert($request['url'] === 'https://api.resend.com/emails', 'transport endpoint must remain fixed HTTPS');
    resend_test_assert($request['authorization'] === 'Bearer re_test_key_only_DO_NOT_PRINT', 'bearer authorization was not configured');
    resend_test_assert(($request['payload']['from'] ?? '') === $expectedFrom, 'sender display name or sender address changed');
    resend_test_assert(($request['payload']['subject'] ?? '') === $expectedSubject, 'email subject changed');
    resend_test_assert(is_array($request['payload']['to'] ?? null) && count($request['payload']['to']) === 1, 'recipient payload changed');
    if ($bodyNeedle !== null) resend_test_assert(str_contains((string)($request['payload']['html'] ?? ''), $bodyNeedle), 'email template content changed');
    if (($_ENV['RESEND_REPLY_TO'] ?? '') === '') resend_test_assert(!array_key_exists('reply_to', $request['payload']), 'optional reply-to should be omitted when unset');
    $options = $request['options'];
    resend_test_assert(($options[CURLOPT_SSL_VERIFYPEER] ?? false) === true && ($options[CURLOPT_SSL_VERIFYHOST] ?? 0) === 2, 'TLS verification options missing');
    resend_test_assert(($options[CURLOPT_CONNECTTIMEOUT] ?? 0) === 5 && ($options[CURLOPT_TIMEOUT] ?? 0) === 20, 'bounded timeout options missing');
    resend_test_assert(($options[CURLOPT_FOLLOWLOCATION] ?? true) === false, 'redirects must not be followed');
    $httpsRestricted = defined('CURLOPT_PROTOCOLS_STR')
        ? (($options[CURLOPT_PROTOCOLS_STR] ?? '') === 'https')
        : (($options[CURLOPT_PROTOCOLS] ?? 0) === CURLPROTO_HTTPS);
    resend_test_assert($httpsRestricted, 'HTTPS protocol restriction missing');
    return $request;
}

// Receipt variants keep the same template, account text, and Reservations sender.
$GLOBALS['resend_test_receipt_category'] = 'Hotel Room';
$receiptResult = send_booking_receipt('zoe@example.test', 'Zoë Sample', 'REF-HOTEL', 'Deluxe', 1500, 'Confirmed');
resend_test_assert($receiptResult === true, 'receipt must still return true');
$hotelRequest = $GLOBALS['resend_test_last_request'];
resend_test_assert(($hotelRequest['payload']['from'] ?? '') === '"Sevilla360 Reservations" <reservations@misevillas.com>', 'receipt sender identity changed');
resend_test_assert(($hotelRequest['payload']['subject'] ?? '') === 'Sevilla360: Your Booking Itinerary [REF-HOTEL]', 'hotel receipt subject changed');
resend_test_assert(str_contains($hotelRequest['payload']['html'] ?? '', 'OFFICIAL BOOKING ITINERARY'), 'hotel receipt body changed');
resend_test_assert(str_contains($hotelRequest['payload']['html'] ?? '', 'Deluxe'), 'hotel receipt details missing');
resend_test_assert(str_contains($hotelRequest['payload']['to'][0] ?? '', 'Zoë Sample'), 'Unicode recipient name was not preserved');

$GLOBALS['resend_test_receipt_category'] = 'Resort Villa';
$villaRequest = resend_test_send(static function () { send_booking_receipt('guest@example.test', 'Guest', 'REF-VILLA', 'Villa', 1500, 'Confirmed'); }, 'Sevilla360: Your Booking Itinerary [REF-VILLA]', '"Sevilla360 Reservations" <reservations@misevillas.com>', 'Villa stay:');
resend_test_assert(str_contains($villaRequest['payload']['html'], 'Breakfast'), 'villa inclusion text missing');

$GLOBALS['resend_test_receipt_category'] = 'Event Hall';
$eventRequest = resend_test_send(static function () { send_booking_receipt('planner@example.test', 'Planner', 'REF-EVENT', 'Palm Hall', 0, 'Event Inquiry Received'); }, 'Sevilla360: Your Booking Itinerary [REF-EVENT]', '"Sevilla360 Reservations" <reservations@misevillas.com>', 'EVENT INQUIRY RECEIVED');
resend_test_assert(str_contains($eventRequest['payload']['html'], 'To Be Arranged'), 'event inquiry amount wording changed');

$GLOBALS['resend_test_receipt_category'] = 'Event Hall';
$invoiceResult = send_invoice_ready_email('planner@example.test', 'Planner', 'REF-INVOICE', 12000, 'https://example.test/dashboard');
resend_test_assert($invoiceResult === null, 'invoice function must retain its void return');
$invoiceRequest = $GLOBALS['resend_test_last_request'];
resend_test_assert(($invoiceRequest['payload']['subject'] ?? '') === 'Sevilla360: Your Final Event Invoice [REF-INVOICE]', 'invoice subject changed');
resend_test_assert(($invoiceRequest['payload']['from'] ?? '') === '"Sevilla360 Accounts" <reservations@misevillas.com>', 'invoice sender role changed');
resend_test_assert(str_contains($invoiceRequest['payload']['html'] ?? '', 'Your Event Consultation is Complete!'), 'invoice body changed');

$custom = resend_test_send(static function () { send_custom_email('customer@example.test', 'Customer', 'Verify Your Sevilla360 Account', '<p>Use code 123456</p>'); }, 'Verify Your Sevilla360 Account', '"Sevilla360 Accounts" <reservations@misevillas.com>', '123456');
resend_test_assert(($custom['payload']['html'] ?? '') === '<p>Use code 123456</p>', 'custom email body should be passed through unchanged');
resend_test_send(static function () { send_custom_email('customer@example.test', 'Customer', 'Your verification code', '<p>123456</p>'); }, 'Your verification code', '"Sevilla360 Accounts" <reservations@misevillas.com>', '123456');

$reset = send_password_reset_email('customer@example.test', 'Customer', 'https://example.test/reset?token=opaque');
resend_test_assert($reset === true, 'password-reset function must still return true');
$resetRequest = $GLOBALS['resend_test_last_request'];
resend_test_assert(($resetRequest['payload']['subject'] ?? '') === 'Sevilla360: Password Reset Request', 'password-reset subject changed');
resend_test_assert(str_contains($resetRequest['payload']['html'] ?? '', 'https://example.test/reset?token=opaque'), 'password-reset link missing from body');

$welcome = send_welcome_email('customer@example.test', 'Customer');
resend_test_assert($welcome === true, 'welcome function must return send_custom_email result');
$welcomeRequest = $GLOBALS['resend_test_last_request'];
resend_test_assert(($welcomeRequest['payload']['subject'] ?? '') === 'Welcome to Sevilla360!', 'welcome subject changed');
resend_test_assert(str_contains($welcomeRequest['payload']['html'] ?? '', 'Your account has been successfully verified!'), 'welcome body changed');

$GLOBALS['resend_test_receipt_category'] = 'Hotel Room';
$statusCases = [
    ['cancellation request', static fn() => send_booking_cancellation_email('customer@example.test', 'Customer', 55, 'cancellation_requested', 1200, 'Schedule change'), 'Sevilla360: Refund Request Received [REF-TEST-55]', 'REFUND REQUEST RECEIVED'],
    ['refund sent', static fn() => send_booking_cancellation_email('customer@example.test', 'Customer', 55, 'refund', 1200), 'Sevilla360: Refund Processed [REF-TEST-55]', 'REFUND PROCESSED'],
    ['customer cancellation', static fn() => send_booking_cancellation_email('customer@example.test', 'Customer', 55, 'customer_cancelled'), 'Sevilla360: Booking Cancelled [REF-TEST-55]', 'BOOKING CANCELLED'],
    ['admin cancellation', static fn() => send_booking_cancellation_email('customer@example.test', 'Customer', 55, 'admin_cancelled'), 'Sevilla360: Booking Cancelled [REF-TEST-55]', 'BOOKING CANCELLED'],
    ['reschedule rejection', static fn() => send_reschedule_rejected_email('customer@example.test', 'Customer', 55, 'Dates are unavailable'), 'Sevilla360: Reschedule Request Update [REF-TEST-55]', 'RESCHEDULE REQUEST UPDATE'],
    ['refund rejection', static fn() => send_refund_rejected_email('customer@example.test', 'Customer', 55, 'Please contact support'), 'Sevilla360: Refund Request Update [REF-TEST-55]', 'REFUND REQUEST UPDATE'],
    ['reschedule approval', static fn() => send_reschedule_approved_email('customer@example.test', 'Customer', 55), 'Sevilla360: Reschedule Approved [REF-TEST-55]', 'RESCHEDULE APPROVED'],
];
foreach ($statusCases as [$label, $callback, $subject, $needle]) {
    $request = resend_test_send($callback, $subject, '"Sevilla360 Accounts" <reservations@misevillas.com>', $needle);
    resend_test_assert(($request['payload']['to'][0] ?? '') === '"Customer" <customer@example.test>', $label . ' recipient changed');
}

// Optional reply-to is omitted when blank and validated when present.
$_ENV['RESEND_REPLY_TO'] = 'support@misevillas.com';
$_SERVER['RESEND_REPLY_TO'] = $_ENV['RESEND_REPLY_TO'];
$replyRequest = resend_test_send(static function () { send_custom_email('customer@example.test', 'Customer', 'Reply test', '<p>Reply</p>'); }, 'Reply test', '"Sevilla360 Accounts" <reservations@misevillas.com>');
resend_test_assert(($replyRequest['payload']['reply_to'] ?? '') === 'support@misevillas.com', 'valid reply-to was not sent');

function resend_test_expect_failure(callable $callback, string $messageNeedle, array $forbidden = []): void
{
    $before = $GLOBALS['resend_test_last_request'];
    try {
        $callback();
        resend_test_fail('invalid mail request unexpectedly succeeded');
    } catch (Exception $e) {
        resend_test_assert(str_contains($e->getMessage(), $messageNeedle), 'unexpected safe error category');
        foreach ($forbidden as $text) {
            resend_test_assert(!str_contains($e->getMessage(), $text), 'exception leaked sensitive request/transport data');
        }
    }
    if ($messageNeedle !== 'Email delivery failed (HTTP 429).' && $messageNeedle !== 'Email delivery failed (HTTP 503).' && $messageNeedle !== 'Email delivery transport failed.' && $messageNeedle !== 'Email provider returned an invalid response.') {
        resend_test_assert($GLOBALS['resend_test_last_request'] === $before, 'invalid request reached the HTTP transport');
    }
}

$_ENV['RESEND_API_KEY'] = '';
resend_test_expect_failure(static fn() => resend_mail_send('customer@example.test', 'Customer', 'Subject', '<p>Body</p>', 'Accounts'), 'Email delivery is not configured.');
$_ENV['RESEND_API_KEY'] = 're_test_key_only_DO_NOT_PRINT';
$_ENV['RESEND_FROM_EMAIL'] = '';
resend_test_expect_failure(static fn() => resend_mail_send('customer@example.test', 'Customer', 'Subject', '<p>Body</p>', 'Accounts'), 'Email sender address is invalid.');
$_ENV['RESEND_FROM_EMAIL'] = 'reservations@misevillas.com';
$_ENV['RESEND_FROM_EMAIL'] = 'not-an-email';
resend_test_expect_failure(static fn() => resend_mail_send('customer@example.test', 'Customer', 'Subject', '<p>Body</p>', 'Accounts'), 'Email sender address is invalid.');
$_ENV['RESEND_FROM_EMAIL'] = 'reservations@misevillas.com';
resend_test_expect_failure(static fn() => resend_mail_send('not-an-email', 'Customer', 'Subject', '<p>Body</p>', 'Accounts'), 'Email recipient address is invalid.');
resend_test_expect_failure(static fn() => resend_mail_send('customer@example.test', "Customer\r\nBcc: other@example.test", 'Subject', '<p>Body</p>', 'Accounts'), 'Email delivery configuration or recipient name is invalid.');
resend_test_expect_failure(static fn() => resend_mail_send('customer@example.test', 'Customer', "Subject\r\nBcc: other@example.test", '<p>Body</p>', 'Accounts'), 'Email delivery configuration or subject is invalid.');
resend_test_expect_failure(static fn() => resend_mail_send('customer@example.test', 'Customer', 'Subject', '<p>Body</p>', "Accounts\r\nBcc:other@example.test"), 'Email delivery configuration or sender name is invalid.');
$_ENV['RESEND_REPLY_TO'] = "support@misevillas.com\r\nBcc:other@example.test";
resend_test_expect_failure(static fn() => resend_mail_send('customer@example.test', 'Customer', 'Subject', '<p>Body</p>', 'Accounts'), 'Email reply-to address is invalid.');
$_ENV['RESEND_REPLY_TO'] = '';
resend_test_expect_failure(static fn() => resend_mail_send('customer@example.test', 'Customer', 'Subject', "<p>\xFF</p>", 'Accounts'), 'Email content is invalid.');

$secretNeedles = ['re_test_key_only_DO_NOT_PRINT', 'RAW_CURL_SECRET_SENTINEL', 'customer@example.test', 'CONFIDENTIAL_RAW_PROVIDER_BODY'];
$GLOBALS['resend_test_mode'] = 'transport';
resend_test_expect_failure(static fn() => resend_mail_send('customer@example.test', 'Customer', 'Subject', 'confidential body', 'Accounts'), 'Email delivery transport failed.', $secretNeedles);
$GLOBALS['resend_test_mode'] = 'success';
$GLOBALS['resend_test_http_status'] = 429;
$GLOBALS['resend_test_http_body'] = '{"message":"CONFIDENTIAL_RAW_PROVIDER_BODY"}';
resend_test_expect_failure(static fn() => resend_mail_send('customer@example.test', 'Customer', 'Subject', 'confidential body', 'Accounts'), 'Email delivery failed (HTTP 429).', $secretNeedles);
$GLOBALS['resend_test_http_status'] = 503;
resend_test_expect_failure(static fn() => resend_mail_send('customer@example.test', 'Customer', 'Subject', 'confidential body', 'Accounts'), 'Email delivery failed (HTTP 503).', $secretNeedles);
$GLOBALS['resend_test_http_status'] = 201;
$GLOBALS['resend_test_http_body'] = '{"not":"json email id"';
resend_test_expect_failure(static fn() => resend_mail_send('customer@example.test', 'Customer', 'Subject', 'confidential body', 'Accounts'), 'Email provider returned an invalid response.', $secretNeedles);
$GLOBALS['resend_test_http_body'] = '{"message":"ok"}';
resend_test_expect_failure(static fn() => resend_mail_send('customer@example.test', 'Customer', 'Subject', 'confidential body', 'Accounts'), 'Email provider returned an invalid response.', $secretNeedles);
$GLOBALS['resend_test_http_body'] = '{"id":123}';
resend_test_expect_failure(static fn() => resend_mail_send('customer@example.test', 'Customer', 'Subject', 'confidential body', 'Accounts'), 'Email provider returned an invalid response.', $secretNeedles);

resend_test_assert($GLOBALS['resend_test_close_count'] > 0, 'cURL handles were not closed');
echo 'Resend transport and mail-flow cases passed (' . $GLOBALS['resend_test_assertions'] . " assertions).\n";
