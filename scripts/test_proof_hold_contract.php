<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/manual_payment.php';
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$booking = $read('actions/bookings/submit_online.php');
$select = $read('actions/bookings/lock_dates.php');
$submit = $read('actions/user/submit_manual_payment.php');
$review = $read('actions/admin/review_manual_payment.php');
$payment = $read('includes/manual_payment.php');
$migration = $read('migrations/033_proof_submission_claims.sql');
$checks = [
    'online request remains nonexclusive before proof' => str_contains($booking, "'Awaiting Proof'") && str_contains($select, 'if ($is_staff_booking)') && str_contains($select, 'Dates selected|0'),
    'first proof uses venue mutex and committed inventory' => str_contains($submit, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED') && str_contains($submit, 'manual_payment_claim_inventory_locked($conn, $booking)') && str_contains($payment, 'booking_lock_venues_in_order($conn'),
    'first accepted claim starts exactly 24 hours' => str_contains($submit, "DATE_ADD(NOW(), INTERVAL 24 HOUR)"),
    'first rejection restarts one correction window' => str_contains($review, "proof_rejections_count = 1, payment_due_at = DATE_ADD(NOW(), INTERVAL 24 HOUR)"),
    'retry does not extend the deadline' => str_contains($submit, 'proof_retry_submitted_at = NOW()') && !str_contains($submit, 'proof_retry_submitted_at = NOW(), payment_due_at'),
    'second rejection cancels immediately' => str_contains($review, "booking_status = 'Cancelled', proof_rejections_count = 2, payment_due_at = NULL"),
    'pending staff review prevents expiry' => str_contains($payment, 'if ($hasPendingProof'),
    'old proof and review states are archived' => str_contains($submit, 'manual_payment_archive_submission_locked($conn, $submissionId)') && str_contains($review, 'manual_payment_archive_submission_locked($conn, $submissionId)') && str_contains($migration, 'manual_payment_submission_history'),
];
$now = new DateTimeImmutable('2027-01-01 12:00:00');
$fixture = ['source'=>'Online','booking_status'=>'Pending','is_completed'=>0,'venue_category'=>'Hotel Room','payment_status'=>'Unpaid','amount_paid'=>0,'payment_due_at'=>'2027-01-01 11:59:59'];
$checks['unreviewed proof remains held after deadline'] = !manual_payment_expiry_candidate($fixture, true, $now);
$checks['first rejection without retry releases after deadline'] = manual_payment_expiry_candidate($fixture, false, $now);
$checks['new preproof request expires without payment'] = manual_payment_expiry_candidate($fixture + [], false, $now) && manual_payment_expiry_candidate(array_replace($fixture, ['booking_status'=>'Awaiting Proof']), false, $now);
$checks['unexpired retry window remains held'] = !manual_payment_expiry_candidate(array_replace($fixture, ['payment_due_at'=>'2027-01-02 12:00:00']), false, $now);
$failures = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $label . "\n";
    if (!$ok) $failures++;
}
exit($failures ? 1 : 0);
