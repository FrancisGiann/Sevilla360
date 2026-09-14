<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('Payment display/retention contract failed: ' . $message);
};

$helper = $read('includes/manual_payment.php');
$customerDetails = $read('actions/user/get_my_booking_details.php');
$adminDetails = $read('actions/admin/get_booking_details.php');
$proofEndpoint = $read('actions/user/payment_proof.php');
$cleanup = $read('scripts/cleanup_payment_proofs.php');
$receipt = $read('print_receipt.php');
$mailer = $read('includes/mailer.php');
$adminBookingsPhp = $read('includes/admin-page/admin_bookings.php');
$adminOverviewPhp = $read('includes/admin-page/admin_overview.php');
$adminBookingsJs = $read('assets/js/admin-page/admin_bookings.js');
$adminOverviewJs = $read('assets/js/admin-page/admin_overview.js');
$readme = $read('README.md');

$assert(str_contains($helper, 'function manual_payment_display_reference(')
    && str_contains($helper, 'strncasecmp($transactionId, \'MANUAL-\', 7) === 0')
    && str_contains($helper, 'function manual_payment_format_history('), 'shared mapper prefers the submitted reference and suppresses unmapped internal manual keys');
$assert(str_contains($customerDetails, 'manual_payment_format_history(')
    && str_contains($customerDetails, "mps.payment_id = p.id")
    && str_contains($customerDetails, "ORDER BY p.payment_date ASC, p.id ASC")
    && str_contains($customerDetails, '$response[\'data\'][\'payments\'] = $payments_res'), 'customer details return ordered structured payments without exposing raw internal transaction IDs');
$assert(str_contains($adminDetails, "in_array((\$_SESSION['role'] ?? ''), ['staff', 'admin'], true)")
    && str_contains($adminDetails, '\'payments\' => $payments')
    && str_contains($adminDetails, '\'proof_history\' => $proofHistory')
    && str_contains($adminDetails, 'transaction_reference, expected_amount, submitted_at, reviewed_at, rejection_reason')
    && !str_contains($adminDetails, 'proof_filename')
    && !str_contains($adminDetails, 'proof_sha256'), 'staff/admin details return audit metadata but no direct file paths or proof bytes');
$assert(str_contains($proofEndpoint, "['customer', 'staff', 'admin']")
    && str_contains($proofEndpoint, 'if (($_SESSION[\'role\'] ?? \'\') === \'customer\') $sql .= \' AND c.user_id = ?\'')
    && str_contains($proofEndpoint, 'manual_payment_proof_retention_expired(')
    && str_contains($proofEndpoint, 'readfile($path)'), 'proof endpoint preserves ownership checks, staff access, and enforces expiration before reading a file');
$assert(str_contains($helper, 'function manual_payment_proof_retention_expired(')
    && str_contains($helper, 'if ($status === \'pending\') return false')
    && str_contains($helper, '$cutoffYear = (int)$now->format(\'Y\') - 1')
    && str_contains($helper, 'function manual_payment_cleanup_proof_files('), 'retention rules preserve pending proofs and expire terminal files strictly after one year');
$assert(str_contains($cleanup, "PHP_SAPI !== 'cli'")
    && str_contains($cleanup, "status IN ('approved', 'rejected')")
    && str_contains($cleanup, 'reviewed_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)')
    && str_contains($cleanup, 'LIMIT ?')
    && str_contains($cleanup, 'PAYMENT_PROOF_CLEANUP_BATCH_SIZE = 100')
    && str_contains($cleanup, 'AND id > ?')
    && str_contains($cleanup, 'fopen($cursorPath, \'c+\')')
    && str_contains($cleanup, 'LOCK_EX | LOCK_NB')
    && str_contains($cleanup, 'manual_payment_cleanup_proof_files($proofRows)')
    && !str_contains($cleanup, 'DELETE FROM manual_payment_submissions'), 'CLI cleanup selects a bounded old terminal batch and retains database audit rows');
$assert(str_contains($receipt, 'manual_payment_format_history(')
    && str_contains($receipt, 'Transaction/reference ID:')
    && str_contains($receipt, 'Payment method:')
    && str_contains($mailer, 'manual_payment_format_history(')
    && str_contains($mailer, 'Transaction/reference ID:')
    && str_contains($mailer, 'Payment method:'), 'PDF receipt and receipt email display distinct method, reference, date, and amount history');

foreach ([
    [$adminBookingsPhp, 'vd-payment-history-section', 'vd-proof-history-list'],
    [$adminOverviewPhp, 'ov-vd-payment-history-section', 'ov-vd-proof-history-list'],
] as [$markup, $paymentTarget, $proofTarget]) {
    $assert(str_contains($markup, $paymentTarget) && str_contains($markup, 'Submitted proof history') && str_contains($markup, $proofTarget), 'each admin View Details surface includes payment history and a compact collapsible proof history');
}
foreach ([$adminBookingsJs, $adminOverviewJs] as $client) {
    $proofRenderStart = strpos($client, 'function renderAdminProofHistory(');
    if ($proofRenderStart === false) $proofRenderStart = strpos($client, 'function renderOverviewProofHistory(');
    $assert($proofRenderStart !== false && str_contains($client, 'actions/user/payment_proof.php?id=')
        && str_contains($client, 'image.src = `actions/user/payment_proof.php?id=${encodeURIComponent(String(submissionId))}`')
        && preg_match('/value(?:El|Element)\.textContent = String\(value\)/', $client) === 1
        && str_contains($client, 'image.onload'), 'admin/staff proof previews are requested on demand through the authenticated endpoint and render metadata as text');
}
$assert(str_contains($adminBookingsJs, "renderAdminProofHistory(res.data.proof_history, 'vd')")
    && str_contains($adminOverviewJs, 'renderOverviewProofHistory(res.data.proof_history)'), 'both booking-list and overview View Details load reviewed proof history');
$assert(str_contains($readme, '/home/ACCOUNT/domains/DOMAIN/public_html/scripts/cleanup_payment_proofs.php')
    && str_contains($readme, 'once daily at 03:15 UTC')
    && str_contains($readme, '15 3 * * * cd /var/www/html/Sevilla360'), 'operations documentation records Hostinger PHP cron path and daily cadence');

echo "Payment display and retention contract checks passed\n";
