<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

$cancel = $read('actions/user/request_cancel.php');
$customerDetails = $read('actions/user/get_my_booking_details.php');
$adminDetails = $read('actions/admin/get_booking_details.php');
$adminAction = $read('actions/admin/update_booking_status.php');
$helper = $read('includes/refund_helper.php');
$customerPage = $read('user_dashboard.php');
$customerJs = $read('assets/js/user_dashboard.js');
$customerCss = $read('assets/css/user_dashboard.css');
$customerUiCss = $read('assets/css/ui-refinement.css');
$adminBookings = $read('includes/admin-page/admin_bookings.php');
$adminOverview = $read('includes/admin-page/admin_overview.php');
$adminBookingsJs = $read('assets/js/admin-page/admin_bookings.js');
$adminOverviewJs = $read('assets/js/admin-page/admin_overview.js');
$adminBookingsCss = $read('assets/css/admin-page/admin_bookings.css');
$migration = $read('migrations/022_refund_destination_details.sql');
$rollback = $read('migrations/rollback/022_refund_destination_details.sql');
$readme = $read('README.md');
$mailer = $read('includes/mailer.php');

$lockPosition = strpos($cancel, '$stmt_booking_lock = $conn->prepare(');
$destinationPosition = strpos($cancel, '$destination = normalize_refund_destination(');
$paidGuardPosition = strpos($cancel, 'if ($amount_paid > 0) {', $lockPosition === false ? 0 : $lockPosition);
$assert($lockPosition !== false && $paidGuardPosition !== false && $destinationPosition !== false
    && $lockPosition < $paidGuardPosition && $paidGuardPosition < $destinationPosition,
    'Paid refund destinations are validated only after the booking is locked and amount_paid is reread.');

$unpaidStart = strpos($cancel, '// SCENARIO B: They haven\'t paid anything yet! Instantly cancel it.');
$unpaidEnd = strpos($cancel, '// Clean up any pending reschedule request', $unpaidStart === false ? 0 : $unpaidStart);
$unpaidBranch = $unpaidStart !== false && $unpaidEnd !== false ? substr($cancel, $unpaidStart, $unpaidEnd - $unpaidStart) : '';
$assert($unpaidBranch !== '' && !str_contains($unpaidBranch, '$data[\'refund_destination\']')
    && !str_contains($unpaidBranch, 'normalize_refund_destination'),
    'Wholly unpaid instant cancellation ignores any destination payload.');

$assert(str_contains($cancel, 'INSERT INTO cancellations (booking_id, reason, refund_amount, fee_deducted, fee_percent, refund_destination_method, refund_destination_account_name, refund_destination_account_identifier, refund_destination_bank_name, status)')
    && str_contains($cancel, 'UPDATE cancellations SET reason = ?, refund_amount = ?, fee_deducted = ?, fee_percent = ?, refund_destination_method = ?, refund_destination_account_name = ?, refund_destination_account_identifier = ?, refund_destination_bank_name = ?')
    && str_contains($cancel, "WHERE id = ? AND status = 'Rejected'")
    && str_contains($cancel, "status = 'Pending', admin_reply = NULL"),
    'First paid requests and rejected-row reopen replace all destination details atomically in prepared statements.');

$assert(str_contains($adminAction, 'SELECT id, reason, refund_amount, fee_deducted, fee_percent, status, refund_destination_method, refund_destination_account_name, refund_destination_account_identifier, refund_destination_bank_name')
    && str_contains($adminAction, 'refund_destination_is_valid($refund_row)')
    && str_contains($adminAction, 'Contact the customer before sending funds.')
    && !str_contains($adminAction, '$data[\'refund_destination\']'),
    'Refund processing locks and validates the saved destination and accepts no destination supplied by admin UI.');

$assert(str_contains($customerDetails, "ORDER BY id DESC LIMIT 1")
    && str_contains($customerDetails, "['Pending', 'Rejected', 'Processed']")
    && str_contains($customerDetails, 'refund_destination_for_customer($cancellation)')
    && preg_match('/unset\([^;]*refund_destination_account_identifier/s', $customerDetails) === 1
    && str_contains($customerDetails, 'require_once \'../../includes/refund_helper.php\';'),
    'Customer booking details include the latest request states and remove the unmasked identifier before JSON output.');
$assert(str_contains($adminDetails, "in_array((\$_SESSION['role'] ?? ''), ['staff', 'admin'], true)")
    && str_contains($adminDetails, 'refund_destination_account_identifier, refund_destination_bank_name FROM cancellations')
    && str_contains($adminBookingsJs, 'destinationIdentifier.textContent = destination?.accountIdentifier')
    && str_contains($adminBookingsJs, 'destinationBank.textContent = showBank ? destination.bankName :')
    && str_contains($adminOverviewJs, 'bankValue.textContent = showBank ? String(cancellation.refund_destination_bank_name)')
    && str_contains($adminBookingsJs, 'fetch(`actions/admin/get_booking_details.php?id=${encodeURIComponent(bookingId)}`'),
    'Authorized admin details provide the authoritative full destination and both booking-details UIs render values with textContent.');

$assert(str_contains($adminBookings, 'class="admin-proof-history-summary-action"')
    && str_contains($adminBookings, 'fa-solid fa-chevron-down')
    && str_contains($adminOverview, 'class="admin-proof-history-summary-action"')
    && str_contains($adminOverview, 'fa-solid fa-chevron-down')
    && str_contains($adminBookingsCss, '#viewDetailsModal,')
    && str_contains($adminBookingsCss, '#overviewBookingModal { width: min(94vw, 640px); max-width: 640px;')
    && str_contains($adminBookingsCss, '#refundModal {')
    && str_contains($adminBookingsCss, 'width: min(94vw, 640px);')
    && str_contains($adminBookingsCss, '#viewDetailsModal section,')
    && str_contains($adminBookingsCss, '#overviewBookingModal section,')
    && str_contains($adminBookingsCss, '.admin-proof-history[open] .admin-proof-history-summary-action > i { transform: rotate(180deg); }'),
    'Both admin details modals use compact widths/section spacing and visible keyboard-operable proof disclosures with counts and rotating chevrons.');

$assert(str_contains($customerCss, '.modal-details-scroll {')
    && str_contains($customerCss, 'width: min(94vw, 640px);')
    && str_contains($customerCss, 'max-width: 640px;')
    && str_contains($customerCss, 'max-height: min(90dvh, 820px);')
    && str_contains($customerCss, '.modal-details-scroll section {')
    && str_contains($customerCss, 'padding: 12px 0 0;'),
    'Customer View Details uses the same restrained width with modal-only section spacing and bounded scrolling.');

$assert(str_contains($adminBookingsCss, '#viewDetailsModal .summary-grid,')
    && str_contains($adminBookingsCss, '#overviewBookingModal .summary-grid,')
    && str_contains($adminBookingsCss, '#viewDetailsModal .booking-detail-grid,')
    && str_contains($adminBookingsCss, '#viewDetailsModal .admin-payment-history-entry > p,')
    && str_contains($adminBookingsCss, 'grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);')
    && str_contains($adminBookingsCss, 'text-align: right;')
    && str_contains($adminBookingsCss, 'font-variant-numeric: tabular-nums;')
    && str_contains($adminBookingsCss, '#viewDetailsModal .vd-total-row .value,')
    && str_contains($adminBookingsCss, '#overviewBookingModal .overview-total-value')
    && str_contains($adminBookingsCss, '#viewDetailsModal .booking-detail-grid > strong,')
    && str_contains($adminBookingsCss, 'text-align: left;'),
    'Admin booking, refund, payment-history, and financial rows use aligned right-edge values on desktop and stacked left-aligned rows on mobile.');

$assert(str_contains($customerCss, '.modal-details-scroll .modal-summary > p,')
    && str_contains($customerCss, '.modal-details-scroll .details-breakdown-section > p,')
    && str_contains($customerCss, '.modal-details-scroll .payment-history-entry > p,')
    && str_contains($customerCss, '.modal-details-scroll .booking-detail-grid > strong')
    && str_contains($customerCss, '.modal-details-scroll .booking-detail-grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 8px 14px; }')
    && str_contains($customerCss, 'font-variant-numeric: tabular-nums;')
    && str_contains($customerCss, 'text-align: right;')
    && str_contains($customerCss, '.modal-details-scroll .booking-detail-grid { grid-template-columns: minmax(0, 1fr); gap: 3px; }')
    && str_contains($customerCss, 'text-align: left;'),
    'Customer details, financial, refund, and payment rows share the right-aligned desktop treatment and stack left-aligned on narrow screens.');

$assert(str_contains($customerPage, '<option value="GCash">GCash</option>')
    && str_contains($customerPage, '<option value="Maya">Maya</option>')
    && str_contains($customerPage, '<option value="Bank Transfer">Bank Transfer</option>')
    && str_contains($customerPage, 'Never share your PIN or OTP.')
    && str_contains($customerPage, 'id="cancel-refund-bank-field"')
    && str_contains($customerJs, "refundDestinationMethod.value === 'Bank Transfer'")
    && str_contains($customerJs, "refund_destination: {")
    && str_contains($customerJs, 'invalidDestinationField.focus()')
    && str_contains($customerJs, 'destinationFields.hidden = true'),
    'Paid cancellation UI reveals required controlled destination fields, focuses invalid input, and resets fields on close.');
$assert(str_contains($customerDetails, 'refund_destination_for_customer($cancellation)')
    && str_contains($helper, "return '•••• '")
    && str_contains($helper, 'substr($visible, -4)')
    && str_contains($customerJs, 'destination.masked_identifier')
    && str_contains($customerJs, "valueEl.textContent = String(value)"),
    'Customer View Details renders only the masked identifier in safe text nodes.');

$assert(str_contains($adminBookings, 'Mark refund sent')
    && str_contains($adminBookingsJs, 'Mark it sent only after the transfer is complete.')
    && str_contains($adminBookingsJs, 'newBtn.disabled = !requestIsPending || !hasRequiredDetails')
    && str_contains($adminBookingsJs, 'newRejectBtn.disabled = true')
    && str_contains($adminBookingsJs, 'newRejectBtn.disabled = !requestIsPending')
    && str_contains($adminBookingsJs, 'transactionInput.reportValidity()')
    && !str_contains($adminBookings, 'Execute Refund'),
    'Admin refund UI presents a manual send workflow and disables completion until an authoritative pending destination is verified.');

$assert(str_contains($customerPage, 'booking-row-primary')
    && substr_count($customerPage, 'booking-row-primary') === 2
    && str_contains($customerPage, 'if (!$payment_action): ?>')
    && substr_count($customerPage, 'booking-more-toggle') === 1
    && str_contains($customerPage, 'aria-label="More actions for booking <?php echo htmlspecialchars($raw_booking_reference')
    && str_contains($customerPage, 'title="More actions for booking <?php echo htmlspecialchars($raw_booking_reference')
    && str_contains($customerPage, 'fa-solid fa-ellipsis')
    && str_contains($customerPage, 'action-menu-item')
    && str_contains($customerPage, 'aria-expanded="false"')
    && str_contains($customerJs, "event.key === 'Escape' && document.querySelector('.booking-more-toggle[aria-expanded=\"true\"]')")
    && str_contains($customerJs, 'closeBookingActionDisclosure(false)')
    && str_contains($customerJs, 'closeBookingActionDisclosure(true)')
    && str_contains($customerJs, "toggle.setAttribute('aria-expanded', 'false')")
    && str_contains($customerJs, 'if (restoreFocus && toggle.isConnected) toggle.focus()')
    && str_contains($customerJs, "if (!event.target.closest('.booking-row-more'))")
    && str_contains($customerJs, "event.target.closest('.booking-action-menu-panel .action-menu-item')")
    && str_contains($customerJs, "panel.style.position = 'fixed'")
    && str_contains($customerJs, 'document.body.appendChild(panel)')
    && str_contains($customerJs, 'restoreBookingActionPanel(panel)')
    && str_contains($customerJs, 'parent: panel.parentNode')
    && str_contains($customerJs, 'Math.min(228, viewportWidth - (viewportGutter * 2))')
    && str_contains($customerJs, "panel.querySelector('.action-menu-item')?.focus({ preventScroll: true })")
    && str_contains($customerJs, "const isTabEdge = event.shiftKey")
    && str_contains($customerJs, "event.target.closest('.booking-action-menu-panel')")
    && str_contains($customerCss, '.action-cell.has-more-actions')
    && str_contains($customerCss, '.booking-action-menu-panel[hidden]')
    && str_contains($customerCss, 'text-transform: none !important;')
    && str_contains($customerCss, '.booking-action-menu-panel .action-menu-item--destructive')
    && str_contains($customerUiCss, '#tab-bookings .action-cell .btn-action:not(.booking-more-toggle)')
    && str_contains($customerUiCss, '#tab-bookings .action-cell .booking-more-toggle')
    && str_contains($customerUiCss, 'width: 44px !important;'),
    'Customer booking rows keep one primary action and an accessible, compact overflow disclosure, portaled and viewport-anchored with safe close/focus behavior.');
$assert(str_contains($customerPage, 'if (!$is_completed && $can_submit_manual_payment($b))')
    && preg_match('/if \(\$payment_action\): \?>\s*<button type="button" class="btn-details action-menu-item"/', $customerPage) === 1
    && str_contains($customerPage, 'if ($can_reschedule_booking): ?>')
    && str_contains($customerPage, 'if ($can_cancel_booking): ?>')
    && str_contains($customerPage, 'if ($can_review_booking && !$booking_review): ?>')
    && str_contains($customerPage, 'action-menu-item--destructive')
    && strpos($customerPage, 'if ($can_cancel_booking): ?>') > strpos($customerPage, 'if ($can_review_booking && !$booking_review): ?>'),
    'Payable bookings keep payment primary and place secondary actions in More, with refund/cancel last and visually separated.');

$assert(str_contains($migration, 'ADD COLUMN IF NOT EXISTS refund_destination_method VARCHAR(24) NULL')
    && str_contains($migration, 'ADD COLUMN IF NOT EXISTS refund_destination_account_name VARCHAR(120) NULL')
    && str_contains($migration, 'ADD COLUMN IF NOT EXISTS refund_destination_account_identifier VARCHAR(64) NULL')
    && str_contains($migration, 'ADD COLUMN IF NOT EXISTS refund_destination_bank_name VARCHAR(100) NULL')
    && !preg_match('/\b(?:INSERT|UPDATE|DELETE)\s+/i', $migration),
    'Forward migration is additive, nullable, repeat-safe DDL without backfill or destructive DML.');
$assert(str_contains($rollback, 'DESTRUCTIVE ROLLBACK')
    && str_contains($rollback, 'DROP COLUMN IF EXISTS refund_destination_account_identifier')
    && str_contains($readme, 'mariadb -u USER -p DATABASE < migrations/022_refund_destination_details.sql')
    && str_contains($readme, 'Before deploying the customer refund-destination workflow')
    && preg_match('/permanently\s+deletes refund destination details collected after migration 022/', $readme) === 1,
    'Rollback is isolated under migrations/rollback and both it and deployment order document data loss and manual schema application.');

$assert(!str_contains($mailer, 'refund_destination_account_identifier')
    && !str_contains($cancel, 'refund_destination_account_identifier" =>')
    && str_contains($mailer, 'sent to the destination you provided'),
    'Destination details are excluded from messages and notifications while the refund email reflects the manual destination workflow.');

echo "Refund destination integration contract checks passed ({$assertions} assertions).\n";
