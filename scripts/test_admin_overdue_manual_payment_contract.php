<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/manual_payment.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};
$throws = static function (callable $callback, string $message) use ($assert): void {
    try {
        $callback();
    } catch (Throwable $error) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

$manila = new DateTimeZone('Asia/Manila');
$now = new DateTimeImmutable('2026-10-16 12:00:00', $manila);
$expiredBooking = [
    'source' => 'Online',
    'booking_status' => 'Pending',
    'payment_status' => 'Unpaid',
    'amount_paid' => '0.00',
    'payment_due_at' => '2026-10-16 12:00:00',
    'venue_category' => 'Hotel Room',
    'is_completed' => 0,
];
$assert(!manual_payment_deadline_has_expired('2026-10-16 12:00:01', $now), 'A deadline one second in the future remains open.');
$assert(manual_payment_deadline_has_expired('2026-10-16 12:00:00', $now), 'A deadline expires at the exact Asia/Manila cutoff.');
$assert(manual_payment_deadline_has_expired('2026-10-16 11:59:59', $now), 'A deadline in the past is expired.');
$assert(!manual_payment_deadline_has_expired('not a timestamp', $now), 'Malformed deadlines fail closed instead of expiring a booking.');
$assert(manual_payment_expiry_candidate($expiredBooking, false, $now), 'An unpaid online Pending booking expires at its deadline.');
$assert(!manual_payment_expiry_candidate(array_replace($expiredBooking, ['payment_due_at' => '2026-10-16 12:00:01']), false, $now), 'An unpaid booking before its deadline remains open.');
$assert(!manual_payment_expiry_candidate($expiredBooking, true, $now), 'A pending proof exempts a booking from expiry after the locked recheck.');
$assert(!manual_payment_expiry_candidate(array_replace($expiredBooking, ['venue_category' => 'Event Hall']), false, $now), 'An unquoted Event Hall inquiry remains exempt from ordinary expiry.');
$assert(!manual_payment_expiry_candidate(array_replace($expiredBooking, ['booking_status' => 'Completed']), false, $now), 'Completed bookings are never ordinary expiry candidates.');
$assert(!manual_payment_expiry_candidate(array_replace($expiredBooking, ['source' => 'Staff']), false, $now), 'Staff bookings are never ordinary expiry candidates.');
$assert(!manual_payment_expiry_candidate(array_replace($expiredBooking, ['payment_status' => 'Partial', 'amount_paid' => '1.00']), false, $now), 'Partially paid bookings are never ordinary expiry candidates.');

$pastPending = [
    'source' => 'Online', 'booking_status' => 'Pending', 'payment_status' => 'Unpaid',
    'amount_paid' => '0.00', 'venue_category' => 'Hotel Room', 'is_completed' => 0,
];
$throws(static fn() => manual_payment_assert_approval_eligible($pastPending, true, false), 'Late proof approval requires explicit acknowledgement.');
manual_payment_assert_approval_eligible($pastPending, true, true);
$assert(true, 'An acknowledged late proof may be reviewed for a Pending reservation.');
$pastCompleted = array_replace($pastPending, ['booking_status' => 'Completed', 'is_completed' => 1]);
$throws(static fn() => manual_payment_assert_approval_eligible($pastCompleted, false, true), 'A Completed booking cannot use the ordinary review path while its event is active.');
manual_payment_assert_approval_eligible($pastCompleted, true, true);
$assert(true, 'An acknowledged late proof remains reviewable for a Completed booking.');
$throws(static fn() => manual_payment_assert_approval_eligible(array_replace($pastPending, ['venue_category' => 'Event Hall']), true, true), 'A late acknowledgement cannot bypass an unfinalized Event Hall quotation.');
$throws(static fn() => manual_payment_assert_approval_eligible(array_replace($pastPending, ['payment_status' => 'Refunded']), true, true), 'Refunded bookings remain ineligible for proof approval.');
$throws(static fn() => manual_payment_assert_approval_eligible(array_replace($pastPending, ['source' => 'Staff']), true, true), 'Customer proof cannot be approved for a staff booking.');

$assert(manual_payment_rejection_cancels_overdue_pending($pastPending, true), 'Rejecting proof cancels an ended, wholly unpaid Pending booking.');
$assert(!manual_payment_rejection_cancels_overdue_pending($pastPending, false), 'A current event does not enter the overdue cancellation path.');
$assert(!manual_payment_rejection_cancels_overdue_pending(array_replace($pastPending, ['amount_paid' => '1.00', 'payment_status' => 'Partial']), true), 'A partially paid booking keeps its status when overdue proof is rejected.');
$assert(!manual_payment_rejection_cancels_overdue_pending(array_replace($pastPending, ['booking_status' => 'Confirmed']), true), 'A Confirmed booking keeps its status when overdue proof is rejected.');
$assert(!manual_payment_rejection_cancels_overdue_pending($pastCompleted, true), 'A Completed booking keeps its history when overdue proof is rejected.');

$root = dirname(__DIR__);
$reviewEndpoint = file_get_contents($root . '/actions/admin/review_manual_payment.php');
$statusEndpoint = file_get_contents($root . '/actions/admin/update_booking_status.php');
$bookingsEndpoint = file_get_contents($root . '/actions/admin/get_bookings_page.php');
$paymentHelpers = file_get_contents($root . '/includes/manual_payment.php');
$bookingsJs = file_get_contents($root . '/assets/js/admin-page/admin_bookings.js');
$bookingsCss = file_get_contents($root . '/assets/css/admin-page/admin_bookings.css');
foreach ([$reviewEndpoint, $statusEndpoint, $bookingsEndpoint, $paymentHelpers, $bookingsJs, $bookingsCss] as $source) {
    if (!is_string($source)) throw new RuntimeException('Unable to read an overdue-proof contract source file.');
}

$assert(str_contains($bookingsEndpoint, 'CASE WHEN b.end_date < CURDATE() THEN 1 ELSE 0 END AS event_ended'), 'The API derives ended state from the database date in the Manila-local session.');
$assert(str_contains($bookingsEndpoint, "mps_overdue.status='pending'"), 'Overdue status is emitted only while a pending proof exists.');
$actionRequiredPosition = strpos($bookingsEndpoint, "EXISTS (SELECT 1 FROM manual_payment_submissions mps_action");
$completionGatePosition = $actionRequiredPosition === false ? false : strpos($bookingsEndpoint, 'OR (NOT {$bookingCompletionSql} AND', $actionRequiredPosition);
$assert($actionRequiredPosition !== false && $completionGatePosition !== false, 'Action Required keeps overdue pending proof visible even for Completed bookings.');
$assert(str_contains($bookingsJs, 'Overdue Verification') && str_contains($bookingsJs, 'Event ended — payment still unverified'), 'The table exposes the overdue badge and explanatory status.');
$assert(str_contains($bookingsJs, '>Review Proof</button>'), 'Review Proof remains the visible primary action.');
$assert(str_contains($bookingsJs, 'booking-action-menu-panel') && str_contains($bookingsJs, "event.key === 'Escape'") && str_contains($bookingsJs, "event.key === 'Home'") && str_contains($bookingsJs, "event.key === 'End'"), 'Secondary actions use a keyboard-operable portal menu.');
$assert(str_contains($bookingsCss, '.booking-action-menu-panel[hidden]') && str_contains($bookingsCss, 'position: fixed'), 'The portal menu has a hidden state and fixed viewport positioning.');
$assert(str_contains($bookingsJs, 'Price editing unavailable') && str_contains($bookingsJs, 'disabled>'), 'Unavailable price editing is rendered disabled.');

$ackPosition = strpos($reviewEndpoint, 'manual_payment_assert_approval_eligible');
$amountPosition = strpos($reviewEndpoint, 'if ($currentExpectedCents !== $submittedExpectedCents)');
$creditPosition = strpos($reviewEndpoint, 'manual_payment_credit_locked(');
$assert($ackPosition !== false && $amountPosition !== false && $creditPosition !== false && $ackPosition < $amountPosition && $amountPosition < $creditPosition, 'Ack and current-balance validation run before payment credit.');
$assert(str_contains($reviewEndpoint, 'FOR UPDATE') && str_contains($reviewEndpoint, "WHERE id = ? AND status = 'pending'"), 'The booking and proof remain locked, and review decisions are single-use.');
$assert(str_contains($paymentHelpers, 'function manual_payment_apply_overdue_rejection_locked') && str_contains($paymentHelpers, "booking_status = 'Cancelled', payment_due_at = NULL") && str_contains($reviewEndpoint, 'manual_payment_apply_overdue_rejection_locked($conn, $booking, true)'), 'Overdue rejection cancels only the eligible Pending case and never reopens its deadline.');
$assert(str_contains($reviewEndpoint, '\'late_verification\' => $eventEnded'), 'The audit record marks late verification.');
$assert(str_contains($statusEndpoint, 'Bookings cannot be approved after the event has ended.'), 'The ordinary approval endpoint blocks ended events.');
$assert(str_contains($statusEndpoint, 'cannot be finalized after the event has ended') && str_contains($statusEndpoint, 'A payment cannot approve a Pending booking after the event has ended.'), 'Invoice finalization and direct payment cannot bypass the ended-event approval block.');

$expectedCents = manual_payment_expected_amount_cents(['total_amount' => '1000.00', 'amount_paid' => '250.00', 'payment_scheme' => '20% Reservation']);
$assert($expectedCents === 75000, 'Current balance validation still computes the authoritative remaining amount.');
$assert(manual_payment_submission_decision(['id' => 12, 'booking_id' => 71, 'status' => 'pending'], null, 71) === 'replace_pending', 'A repeated pending proof remains on the existing locked row.');
$throws(static fn() => manual_payment_submission_retry_decision(['booking_id' => 71, 'status' => 'approved'], 71), 'An already approved fingerprint cannot be reviewed a second time.');

echo "Admin overdue manual-payment contract checks passed ({$assertions} assertions).\n";
