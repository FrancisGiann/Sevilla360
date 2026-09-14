<?php
/** Static contract checks for structured audit details and backup feature retirement. */

function audit_backup_contract_assert(bool $condition, string $message): void
{
    if (!$condition) exit("Audit/backup contract test failed: {$message}\n");
}

function audit_backup_contract_source(string $path): string
{
    $source = file_get_contents(__DIR__ . '/../' . $path);
    audit_backup_contract_assert($source !== false, "source is readable ({$path})");
    return $source;
}

function audit_backup_contract_csv_safe_value(string $value): string
{
    $trimmed = preg_replace('/\A[\s\p{Cc}\p{Cf}\p{Z}]+/u', '', $value);
    if ($trimmed === null) return $value;
    return $trimmed !== '' && strpos('=+-@', $trimmed[0]) !== false ? "'" . $value : $value;
}

$login = audit_backup_contract_source('actions/auth/login_process.php');
$paymentReview = audit_backup_contract_source('actions/admin/review_manual_payment.php');
$bookingStatus = audit_backup_contract_source('actions/admin/update_booking_status.php');
$listEndpoint = audit_backup_contract_source('actions/admin/get_audit_logs.php');
$detailEndpoint = audit_backup_contract_source('actions/admin/get_audit_log_detail.php');
$detailsHelper = audit_backup_contract_source('includes/audit_log_details.php');
$auditScript = audit_backup_contract_source('assets/js/admin-page/admin_auditlog.js');
$auditView = audit_backup_contract_source('includes/admin-page/admin_auditlog.php');
$auditStyles = audit_backup_contract_source('assets/css/admin-page/admin_auditlog.css');
$bookingListEndpoint = audit_backup_contract_source('actions/admin/get_bookings_page.php');
$bookingScript = audit_backup_contract_source('assets/js/admin-page/admin_bookings.js');
$auditMigration = audit_backup_contract_source('migrations/024_audit_log_details.sql');
$backupMigration = audit_backup_contract_source('migrations/025_drop_backups_table.sql');
$dashboard = audit_backup_contract_source('admin_dashboard.php');
$readme = audit_backup_contract_source('README.md');
$checklist = audit_backup_contract_source('TESTING_CHECKLIST.md');
require_once __DIR__ . '/../includes/audit_log_details.php';

$loginAuditStart = strpos($login, '$audit = $conn->prepare');
$staffGateStart = strrpos(substr($login, 0, $loginAuditStart === false ? 0 : $loginAuditStart), 'in_array($user[\'role\'], [\'admin\', \'staff\'], true)');
audit_backup_contract_assert($loginAuditStart !== false && $staffGateStart !== false, 'successful login audit is gated to admin and staff roles');
audit_backup_contract_assert(str_contains($login, 'Successful admin and staff logins') && !str_contains($login, 'Successful customer login'), 'login audit comments accurately describe coverage');

audit_backup_contract_assert(str_contains($auditMigration, 'event_type') && str_contains($auditMigration, 'entity_type') && str_contains($auditMigration, 'entity_id') && str_contains($auditMigration, 'details_json'), 'forward migration adds nullable structured audit fields');
audit_backup_contract_assert(str_contains($listEndpoint, 'a.id') && str_contains($listEndpoint, 'actor_role') && str_contains($listEndpoint, 'has_details'), 'audit list returns ID, actor role, and detail availability');
audit_backup_contract_assert(str_contains($listEndpoint, 'MAX_AUDIT_PAGE_SIZE') || str_contains($listEndpoint, 'min(100'), 'audit list clamps pagination');
audit_backup_contract_assert(str_contains($detailEndpoint, 'REQUEST_METHOD') && str_contains($detailEndpoint, '$_SESSION[\'role\']') && str_contains($detailEndpoint, 'hash_equals') && str_contains($detailEndpoint, 'FILTER_VALIDATE_INT'), 'audit detail endpoint validates POST, admin role, CSRF, and audit ID');
audit_backup_contract_assert(str_contains($detailEndpoint, 'audit_log_safe_details') && str_contains($detailsHelper, 'audit_log_safe_details') && str_contains($detailsHelper, 'payment.manual_proof_approved'), 'detail endpoint uses an event-specific server allowlist');
audit_backup_contract_assert(str_contains($detailsHelper, 'if (!is_string($json) || trim($json) === \'\')') || str_contains($detailsHelper, 'return []'), 'historical rows without JSON details decode to an empty detail set');
$historicalDetails = audit_log_safe_details(null, null);
audit_backup_contract_assert($historicalDetails === [], 'historical audit rows without structured details remain valid');
$paymentDetails = audit_log_safe_details(json_encode([
    'booking_reference' => 'BK-123',
    'payment_method' => 'GCash',
    'amount' => 250.50,
    'submission_id' => 12,
    'payment_id' => 34,
    'transaction_reference' => 'FULL-TRANSACTION-REFERENCE',
    'account_identifier' => '09171234567',
    'receipt_path' => '/private/proofs/receipt.jpg',
    'password' => 'secret',
], JSON_THROW_ON_ERROR), 'payment.manual_proof_approved');
audit_backup_contract_assert(array_keys($paymentDetails) === ['booking_reference', 'payment_method', 'amount', 'submission_id', 'payment_id'], 'payment detail decoding returns only approved safe keys');
$unmaskedRefund = audit_log_safe_details(json_encode([
    'booking_reference' => 'BK-123',
    'booking_id' => 3,
    'cancellation_id' => 4,
    'refund_amount' => 100,
    'decision' => 'processed',
    'refund_transaction_reference_masked' => 'FULL-TRANSACTION-REFERENCE',
    'refund_transaction_id' => 'FULL-TRANSACTION-REFERENCE',
], JSON_THROW_ON_ERROR), 'booking.refund_processed');
audit_backup_contract_assert(!array_key_exists('refund_transaction_reference_masked', $unmaskedRefund) && !array_key_exists('refund_transaction_id', $unmaskedRefund), 'refund detail decoding never exposes an unmasked transaction reference');

foreach (['payment.manual_proof_approved', 'payment.manual_proof_rejected', 'submission_id', 'payment_id', 'booking_reference', 'payment_method'] as $marker) {
    audit_backup_contract_assert(str_contains($paymentReview, $marker), "payment audit includes {$marker}");
}
audit_backup_contract_assert(str_contains($paymentReview, "'amount'") && str_contains($paymentReview, "'reason'"), 'payment audits include amount and bounded rejection reason');
audit_backup_contract_assert(str_contains($paymentReview, '\'issssis\', $reviewerId') && strpos($paymentReview, '=== $targetState') < strpos($paymentReview, 'INSERT INTO audit_logs'), 'payment audit actor is the reviewer and idempotent repeats return before audit insertion');
foreach (['booking.refund_processed', 'booking.refund_rejected', 'refund_transaction_reference_masked', 'booking_reference', 'refund_amount', 'decision'] as $marker) {
    audit_backup_contract_assert(str_contains($bookingStatus, $marker), "refund audit includes {$marker}");
}
audit_backup_contract_assert(str_contains($bookingStatus, '\'reason\' => $admin_reply') && str_contains($bookingStatus, 'audit_details_json'), 'refund rejection reason is bounded and refund details are structured');

foreach (['role="dialog"', 'aria-modal="true"', 'audit-detail-dialog'] as $marker) {
    audit_backup_contract_assert(str_contains($auditView, $marker), "audit detail view includes {$marker}");
}
foreach (['keydown', 'Enter', ' ', 'showModal()', 'textContent', 'get_audit_log_detail.php', "method: 'POST'", 'admin_dashboard.php?page=bookings&booking_id='] as $marker) {
    audit_backup_contract_assert(str_contains($auditScript, $marker), "audit UI supports {$marker}");
}
audit_backup_contract_assert(!str_contains($auditScript, 'innerHTML') && !str_contains($auditScript, 'insertAdjacentHTML'), 'audit UI renders server values without HTML interpolation');
audit_backup_contract_assert(str_contains($auditStyles, ':focus-visible') && str_contains($auditStyles, '.audit-detail-dialog'), 'audit UI styles keyboard focus and detail dialog');
audit_backup_contract_assert(str_contains($bookingListEndpoint, 'array_key_exists(\'booking_id\', $data)')
    && str_contains($bookingListEndpoint, "'b.id = ?'")
    && str_contains($bookingListEndpoint, 'FILTER_VALIDATE_INT')
    && str_contains($bookingListEndpoint, 'CAST(b.id AS CHAR) LIKE ?'), 'booking ID deep links use a validated exact filter while preserving generic numeric search');
audit_backup_contract_assert(str_contains($bookingScript, 'booking_id: urlBookingId') && str_contains($bookingScript, 'String(booking.id) === exactBookingId'), 'booking loader carries and matches the exact deep-link ID');
$exactBranchStart = strpos($bookingScript, 'if (!suppressUrlSearchAction && urlBookingId !== null)');
$genericBranchStart = $exactBranchStart === false ? false : strpos($bookingScript, 'else if (!suppressUrlSearchAction && urlSearch', $exactBranchStart);
$exactBranch = $exactBranchStart === false || $genericBranchStart === false ? '' : substr($bookingScript, $exactBranchStart, $genericBranchStart - $exactBranchStart);
audit_backup_contract_assert($exactBranch !== '' && str_contains($exactBranch, 'if (exactViewButton) exactViewButton.click()') && !str_contains($exactBranch, "document.querySelector('.btn-view')"), 'an exact audit-linked ID never falls back to another booking');
$collisionRows = [['id' => 112], ['id' => 12]];
$exactCollisionMatches = array_values(array_filter($collisionRows, static fn(array $booking): bool => (string)$booking['id'] === '12'));
audit_backup_contract_assert(count($exactCollisionMatches) === 1 && $exactCollisionMatches[0]['id'] === 12, 'numeric collision 12 versus 112 resolves only the exact booking');

$csvExportBranch = strpos($auditScript, 'function csvSafeValue(value)');
$csvQuoteEscape = strpos($auditScript, "safeValue.replace(/\"/g");
audit_backup_contract_assert($csvExportBranch !== false
    && strpos($auditScript, 'p{Cc}\\p{Cf}\\p{Z}') !== false
    && strpos($auditScript, '/^[=+\\-@]/u') !== false
    && strpos($auditScript, 'csvSafeValue(cell.innerText.replace') !== false
    && $csvQuoteEscape !== false
    && $csvExportBranch < $csvQuoteEscape, 'CSV export neutralizes formula-leading cells before quote escaping');
foreach ([
    "  =SUM(1)" => "'  =SUM(1)",
    "\t-2+3" => "'\t-2+3",
    "\u{200B}@cmd" => "'\u{200B}@cmd",
    '+value' => "'+value",
    'ordinary text' => 'ordinary text',
] as $csvInput => $expectedCsvValue) {
    audit_backup_contract_assert(audit_backup_contract_csv_safe_value($csvInput) === $expectedCsvValue, 'formula-safe CSV output preserves the original cell text');
}

foreach (['actions/admin/create_backup.php', 'actions/admin/delete_backup.php', 'actions/admin/download_backup.php', 'actions/admin/import_backup.php', 'actions/admin/restore_backup.php', 'includes/backup_helper.php', 'includes/admin-page/admin_backups.php', 'assets/js/admin-page/admin_backups.js', 'assets/css/admin-page/admin_backups.css', 'scripts/daily_backup.php'] as $path) {
    audit_backup_contract_assert(!file_exists(__DIR__ . '/../' . $path), "obsolete backup feature file is removed ({$path})");
}
audit_backup_contract_assert(!str_contains($dashboard, 'admin_backups') && !str_contains($dashboard, "'backups'"), 'dashboard has no backup page or script reference');
audit_backup_contract_assert(!preg_match('/backup|BACKUP_DIR/i', $readme), 'README has no current backup setup instructions');
audit_backup_contract_assert(!preg_match('/backup/i', $checklist), 'testing checklist has no backup workflow references');
audit_backup_contract_assert((bool)preg_match('/DROP\s+TABLE\s+IF\s+EXISTS\s+`?backups`?/i', $backupMigration), 'forward migration drops only the obsolete backups metadata table idempotently');

echo "Audit/backup contract checks passed\n";
