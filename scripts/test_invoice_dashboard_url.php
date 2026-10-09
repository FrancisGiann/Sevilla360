<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/site_metadata.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$_SERVER['HTTP_HOST'] = 'attacker.example';
$_SERVER['HTTPS'] = 'off';
$assert(
    site_metadata_absolute_url('user_dashboard.php', 'https://misevillas.com') === 'https://misevillas.com/user_dashboard.php',
    'root installation must use the configured HTTPS origin'
);
$assert(
    site_metadata_absolute_url('user_dashboard.php', 'https://example.test/Sevilla360') === 'https://example.test/Sevilla360/user_dashboard.php',
    'subdirectory installation path must be preserved'
);
$assert(
    site_metadata_absolute_url('user_dashboard.php', 'https://misevillas.com') !== 'http://' . $_SERVER['HTTP_HOST'] . '/user_dashboard.php',
    'forged request host and scheme must not affect invoice links'
);
$assert(site_metadata_absolute_url('user_dashboard.php', '') === null, 'missing base URL must disable invoice email generation');
$assert(site_metadata_absolute_url('user_dashboard.php', 'http://misevillas.com') === null, 'invalid public HTTP base URL must be rejected');
$assert(site_metadata_absolute_url('../user_dashboard.php', 'https://misevillas.com') === null, 'path traversal must be rejected');

$action = (string)file_get_contents($root . '/actions/admin/update_booking_status.php');
$assert(str_contains($action, "require_once __DIR__ . '/../../includes/site_metadata.php';"), 'admin action must load centralized base URL validation');
$assert(str_contains($action, "site_metadata_absolute_url('user_dashboard.php')"), 'invoice URL must use the configured application base');
$assert(str_contains($action, "if (\$dash_link === null)") && str_contains($action, 'Invoice email skipped: APP_BASE_URL is missing or invalid')
    && strpos($action, "if (\$dash_link === null)") < strpos($action, 'send_invoice_ready_email('), 'missing or invalid base must skip mail while leaving quote finalization outside the mail branch');
$assert(!str_contains($action, "\$_SERVER['HTTP_HOST']") && !str_contains($action, '"/Sevilla360/user_dashboard.php"'), 'invoice links must not use request host headers or a hard-coded install path');

echo "Invoice dashboard URL checks passed.\n";
