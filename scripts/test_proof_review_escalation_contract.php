<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/manual_payment.php';

$checks = [];
$check = static function (string $name, bool $passed) use (&$checks): void {
    $checks[$name] = $passed;
    echo ($passed ? 'PASS' : 'FAIL') . " - {$name}\n";
};

$now = new DateTimeImmutable('2026-10-23 12:00:00', new DateTimeZone('Asia/Manila'));
$check('review threshold is not reached early', !manual_payment_proof_review_overdue('2026-10-22 12:00:01', 24, $now));
$check('review threshold begins at the exact deadline', manual_payment_proof_review_overdue('2026-10-22 12:00:00', 24, $now));
$check('invalid review threshold cannot escalate', !manual_payment_proof_review_overdue('2026-10-22 12:00:00', 0, $now));

$unpaid = [
    'source' => 'Online', 'booking_status' => 'Pending', 'payment_status' => 'Unpaid',
    'amount_paid' => 0, 'is_completed' => 0, 'venue_category' => 'Hotel Room',
    'payment_due_at' => '2026-10-22 12:00:00',
];
$check('unpaid booking without proof remains eligible for expiry', manual_payment_expiry_candidate($unpaid, false, $now));
$check('pending proof prevents automatic cancellation after payment deadline', !manual_payment_expiry_candidate($unpaid, true, $now));

$notifications = (string)file_get_contents(dirname(__DIR__) . '/includes/admin_notifications.php');
$settings = (string)file_get_contents(dirname(__DIR__) . '/actions/admin/save_manual_payment_settings.php');
$customer = (string)file_get_contents(dirname(__DIR__) . '/actions/user/get_manual_payment_details.php');
$check('staff queue prioritizes overdue proof and gives it a new unread key',
    str_contains($notifications, "'Payment Proof Overdue'")
    && str_contains($notifications, "':overdue'")
    && str_contains($notifications, "ORDER BY (title = 'Payment Proof Overdue') DESC"));
$check('review hours are configurable without booking cancellation',
    str_contains($settings, "'manual_payment_review_hours'")
    && str_contains($customer, "'review_overdue'")
    && !str_contains($notifications, 'UPDATE bookings'));

$failures = count(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo 'RESULT - ' . count($checks) . " checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
