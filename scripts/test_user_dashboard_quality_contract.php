<?php
/** Static quality contracts for the customer dashboard. */
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) {
        fwrite(STDERR, "Could not read {$path}\n");
        exit(1);
    }
    return $contents;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "User dashboard quality contract failed: {$message}\n");
        exit(1);
    }
};

$php = $read('user_dashboard.php');
$js = $read('assets/js/user_dashboard.js');
$css = $read('assets/css/user_dashboard.css');
$uiCss = $read('assets/css/ui-refinement.css');
$statusResolver = $read('includes/customer_booking_status.php');
$bookingDetailsEndpoint = $read('actions/user/get_my_booking_details.php');
$paymentEligibilityStart = strpos($php, '$can_submit_manual_payment = static function');
$paymentEligibilityEnd = strpos($php, '$manual_payment_action_label', $paymentEligibilityStart === false ? 0 : $paymentEligibilityStart);
$paymentEligibility = $paymentEligibilityStart === false || $paymentEligibilityEnd === false
    ? ''
    : substr($php, $paymentEligibilityStart, $paymentEligibilityEnd - $paymentEligibilityStart);

$allDashboardTabsHaveServerAndClientActivation = true;
foreach (['overview', 'bookings', 'settings'] as $section) {
    $activeClassCondition = "\$initial_section === '$section' ? 'active' : ''";
    $allDashboardTabsHaveServerAndClientActivation = $allDashboardTabsHaveServerAndClientActivation
        && str_contains($php, 'id="tab-' . $section . '"')
        && str_contains($php, $activeClassCondition);
}
$activePaneRuleStart = strrpos($uiCss, '.dashboard-layout .tab-pane.active {');
$activePaneRuleEnd = $activePaneRuleStart === false ? false : strpos($uiCss, '}', $activePaneRuleStart);
$activePaneRule = $activePaneRuleStart === false || $activePaneRuleEnd === false
    ? ''
    : substr($uiCss, $activePaneRuleStart, $activePaneRuleEnd - $activePaneRuleStart);
$reducedMotionStart = strpos($uiCss, '@media (prefers-reduced-motion: reduce)');
$reducedMotionCss = $reducedMotionStart === false ? '' : substr($uiCss, $reducedMotionStart);
$reducedMotionPanelStart = strpos($reducedMotionCss, '.dashboard-layout .tab-pane.active,');
$reducedMotionPanelBrace = $reducedMotionPanelStart === false ? false : strpos($reducedMotionCss, '{', $reducedMotionPanelStart);
$reducedMotionPanelEnd = $reducedMotionPanelBrace === false ? false : strpos($reducedMotionCss, '}', $reducedMotionPanelBrace);
$reducedMotionPanelSelectors = $reducedMotionPanelStart === false || $reducedMotionPanelBrace === false
    ? ''
    : substr($reducedMotionCss, $reducedMotionPanelStart, $reducedMotionPanelBrace - $reducedMotionPanelStart);
$reducedMotionPanelDeclarations = $reducedMotionPanelBrace === false || $reducedMotionPanelEnd === false
    ? ''
    : substr($reducedMotionCss, $reducedMotionPanelBrace + 1, $reducedMotionPanelEnd - $reducedMotionPanelBrace - 1);
$assert($allDashboardTabsHaveServerAndClientActivation && str_contains($php, "if (!in_array(\$initial_section, ['overview', 'bookings', 'settings'], true))") && str_contains($js, 'const validSections = new Set(["overview", "bookings", "settings"]);') && str_contains($js, 'pane.id === `tab-${targetSection}`') && str_contains($js, 'setDashboardSection(requestedDashboardSection(), { focusBooking: true });'), 'overview, bookings, and settings panes are server-renderable and reachable through dashboard navigation');
$assert(str_contains($php, '<div class="settings-container">') && substr_count($php, '<div class="settings-card">') >= 3 && str_contains($php, 'id="set-fname"') && str_contains($php, 'id="set-prefs"') && str_contains($php, 'id="set-old-pass"'), 'settings pane retains its profile, preference, and security controls');
$assert(str_contains($activePaneRule, 'display: block;') && str_contains($activePaneRule, 'visibility: visible !important;') && str_contains($activePaneRule, 'opacity: 1 !important;'), 'active customer tabs remain visible when an entrance animation is paused or interrupted');
$assert(str_ends_with(trim($reducedMotionPanelSelectors), '.admin-layout .audit-log-container') && !str_contains($reducedMotionPanelSelectors, '.modal-overlay') && str_contains($reducedMotionPanelDeclarations, 'animation: none !important;') && str_contains($reducedMotionPanelDeclarations, 'opacity: 1 !important;') && str_contains($reducedMotionPanelDeclarations, 'transform: none !important;') && str_contains($reducedMotionCss, "  .modal-overlay .modal-box,"), 'reduced-motion panel selectors close before the separate modal rule and disable active-pane opacity animation');
$assert(str_contains($php, 'SUM(CASE WHEN b.booking_status = \'Pending\' AND NOT $booking_completion_sql THEN 1 ELSE 0 END) AS pending') && str_contains($php, '<div class="stat-label">PENDING BOOKINGS</div>'), 'Pending calculation is preserved and the customer-facing KPI describes pending bookings');
$assert(str_contains($statusResolver, "return ['Awaiting payment verification', 'badge-pending'];") && str_contains($statusResolver, "return ['Proof rejected', 'badge-cancelled'];") && str_contains($statusResolver, "return ['Inquiry sent', 'badge-pending'];") && str_contains($statusResolver, "return ['Payment due', 'badge-pending'];") && str_contains($statusResolver, "return ['Payment window expired', 'badge-cancelled'];"), 'customer status resolver defines the payment, proof, inquiry, and expiry labels');
$assert(!str_contains($php, 'Awaiting Approval') && !str_contains($js, 'Awaiting Approval') && str_contains($php, "\$dashboard_status = 'customer_dashboard_status';"), 'overview, booking history, and details use the shared customer status resolver without implying approval is required');
$assert(str_contains($bookingDetailsEndpoint, 'EXISTS (SELECT 1 FROM manual_payment_submissions mps_pending') && str_contains($bookingDetailsEndpoint, 'latest_mps.transaction_reference AS manual_submission_reference') && str_contains($bookingDetailsEndpoint, 'latest_mps.status AS manual_submission_status') && str_contains($bookingDetailsEndpoint, 'latest_mps.submitted_at AS manual_submission_submitted_at') && str_contains($bookingDetailsEndpoint, 'latest_mps.rejection_reason AS manual_rejection_reason') && str_contains($bookingDetailsEndpoint, 'mps_latest.customer_user_id = c.user_id') && !str_contains($bookingDetailsEndpoint, 'proof_filename') && !str_contains($bookingDetailsEndpoint, 'proof_sha256'), 'owned booking details return only the latest proof review metadata needed by the customer');
$assert(str_contains($js, 'badge.textContent = data.customer_status_label') && str_contains($js, "pending: 'Awaiting payment verification'") && str_contains($js, "rejected: 'Proof rejected'") && !str_contains($js, "approved: 'Approved'") && str_contains($js, 'referenceEl.textContent') && str_contains($js, 'reviewNoteEl.textContent'), 'details modal uses server-resolved booking status and renders only pending/rejected proof metadata');

require_once $root . '/includes/customer_booking_status.php';
$statusFixture = [
    'booking_status' => 'Pending',
    'display_booking_status' => 'Pending',
    'venue_type' => 'Hotel Room',
    'source' => 'Online',
    'payment_status' => 'Unpaid',
    'amount_paid' => 0,
];
$assert(customer_dashboard_status($statusFixture) === ['Payment due', 'badge-pending'], 'unpaid online bookings with no proof show Payment due');
$proofPending = $statusFixture + ['manual_payment_pending' => 1];
$proofPending['payment_due_at'] = date('Y-m-d H:i:s', time() - 3600);
$assert(customer_dashboard_status($proofPending) === ['Awaiting payment verification', 'badge-pending'], 'pending proof review takes precedence over an expired payment deadline');
$proofRejected = $statusFixture + ['manual_submission_status' => 'rejected'];
$proofRejected['payment_due_at'] = date('Y-m-d H:i:s', time() - 3600);
$assert(customer_dashboard_status($proofRejected) === ['Proof rejected', 'badge-cancelled'], 'latest proof rejection remains visible after the deadline');
$expiredUnpaid = $statusFixture;
$expiredUnpaid['payment_due_at'] = date('Y-m-d H:i:s', time() - 3600);
$assert(customer_dashboard_status($expiredUnpaid) === ['Payment window expired', 'badge-cancelled'], 'expired wholly unpaid bookings do not get hidden by Pending');
$eventInquiry = $expiredUnpaid;
$eventInquiry['venue_type'] = 'Event Hall';
$assert(customer_dashboard_status($eventInquiry) === ['Inquiry sent', 'badge-pending'], 'Pending Event Hall inquiries retain their inquiry state');
$partialPayment = $expiredUnpaid;
$partialPayment['payment_status'] = 'Partial';
$partialPayment['amount_paid'] = 100;
$assert(customer_dashboard_status($partialPayment) === ['Partially paid', 'badge-partial'], 'partially paid bookings retain their truthful payment state');
$paidBooking = $statusFixture;
$paidBooking['payment_status'] = 'Paid';
$assert(customer_dashboard_status($paidBooking) === ['Fully paid', 'badge-paid'], 'fully paid bookings retain their truthful payment state');
$cancelPending = $statusFixture + ['cancel_pending' => 1];
$assert(customer_dashboard_status($cancelPending) === ['Pending refund', 'badge-cancelled'], 'pending cancellation keeps its existing customer label');
$reschedulePending = $statusFixture + ['resched_pending' => 1];
$assert(customer_dashboard_status($reschedulePending) === ['Reschedule requested', 'badge-reschedule'], 'pending reschedule keeps its existing customer label');
$assert(str_contains($paymentEligibility, '!empty($booking[\'cancel_pending\'])') && str_contains($paymentEligibility, '($booking[\'cancel_status\'] ?? \'\') === \'Pending\'') && str_contains($paymentEligibility, '!empty($booking[\'resched_pending\'])') && str_contains($paymentEligibility, '($booking[\'resched_status\'] ?? \'\') === \'Pending\'') && str_contains($php, 'if (!$is_completed && $can_submit_manual_payment($b))') && !str_contains($php, '|| !empty($b[\'manual_payment_pending\'])'), 'payment and replacement actions fail closed for pending cancellation/refund or reschedule requests, without a pending-proof bypass');
$cancelledBooking = $statusFixture;
$cancelledBooking['booking_status'] = 'Cancelled';
$cancelledBooking['display_booking_status'] = 'Cancelled';
$assert(customer_dashboard_status($cancelledBooking) === ['Cancelled', 'badge-cancelled'], 'cancelled bookings retain their terminal label');
$completedBooking = $statusFixture;
$completedBooking['display_booking_status'] = 'Completed';
$assert(customer_dashboard_status($completedBooking) === ['Completed', 'badge-completed'], 'completed bookings retain their terminal label');
$assert(str_contains($php, 'data-status="<?php echo htmlspecialchars($filter_data, ENT_QUOTES, \'UTF-8\'); ?>"') && str_contains($php, '$filter_data = \'Pending\';'), 'specialized statuses retain the existing Pending filter classification');
$assert(str_contains($php, 'href="user_dashboard.php?section=bookings" data-dashboard-section="bookings">Review bookings</a>') && str_contains($php, '$balance_due > 0'), 'positive outstanding balance links directly to the bookings section through dashboard navigation compatibility');

$assert(!str_contains($php, 'filter-pill') && !str_contains($php, 'statusFiltersDesktop') && !str_contains($php, 'statusFiltersMobile') && !str_contains($js, 'filterPills'), 'Booking History uses one status selector without the old pills or duplicate mobile control');
foreach ([
    'All' => 'All bookings',
    'Pending' => 'Needs Attention',
    'Partially Paid' => 'Partially Paid',
    'Paid' => 'Paid',
    'Completed' => 'Completed',
    'Cancelled' => 'Cancelled',
] as $value => $label) {
    $assert(str_contains($php, '<option value="' . $value . '">' . $label . '</option>'), "status selector retains {$value} with label {$label}");
}
$assert(str_contains($php, '<label for="statusFilter">Filter bookings</label>') && str_contains($js, 'statusFilter.addEventListener("change"'), 'the status selector is labeled and drives the existing row filters');
$assert(preg_match('/<a\b[^>]*>\s*<button\b/is', $php) !== 1 && preg_match('/<button\b[^>]*>\s*<a\b/is', $php) !== 1, 'dashboard markup has no directly nested anchor/button controls');
$assert(str_contains($php, 'class="btn-primary-dash booking-new-link"><i') && str_contains($php, '</i> New Booking</a>'), 'New Booking is one semantic anchor styled as the action');
$assert(str_contains($php, 'You don’t have any bookings yet. Select “New Booking” above to explore venues and start a reservation.'), 'the empty booking history explains the next task');

foreach ([
    ['set-fname', 'given-name'],
    ['set-lname', 'family-name'],
    ['set-email', 'email'],
    ['set-phone', 'tel'],
    ['set-prefs', null],
    ['set-old-pass', 'current-password'],
    ['set-new-pass', 'new-password'],
    ['set-confirm-pass', 'new-password'],
] as [$id, $autocomplete]) {
    $assert(str_contains($php, '<label for="' . $id . '">'), "Settings label is associated with {$id}");
    $assert(str_contains($php, 'id="' . $id . '"'), "Settings field {$id} has a stable id");
    if ($autocomplete !== null) $assert(str_contains($php, 'autocomplete="' . $autocomplete . '"'), "Settings field {$id} keeps autocomplete {$autocomplete}");
}
$assert(str_contains($php, '<label for="set-prefs">') && str_contains($php, '<textarea id="set-prefs"'), 'special-request textarea has a matching label');

$assert(str_contains($php, '<button type="button" class="notif-item') && str_contains($php, 'aria-label="<?php echo htmlspecialchars(($n[\'is_read\'] ? \'Read notification: \' : \'Unread notification: \')'), 'server-rendered notifications are buttons with readable read-state labels');
$assert(str_contains($js, 'return \'<button type="button" class="notif-item ') && str_contains($js, 'escapeHtml(item.id)') && str_contains($js, 'escapeHtml(item.title)') && str_contains($js, 'escapeHtml(item.message)') && str_contains($js, 'escapeHtml(item.created_at)'), 'refreshed notification buttons escape every dynamic field before insertion');
$assert(str_contains($php, 'id="notif-live-status" class="sr-only" role="status" aria-live="polite"') && str_contains($js, "announceNotificationChange('All notifications marked as read.')") && str_contains($js, 'marked as read.`'), 'notification read changes are announced through a live status');
$assert(str_contains($php, 'id="notif-retry"') && str_contains($php, 'aria-haspopup="dialog"') && str_contains($php, 'aria-controls="notif-dropdown"') && str_contains($php, 'aria-expanded="false"') && str_contains($js, "setAttribute('aria-expanded', String(open))") && str_contains($js, 'Previously loaded notifications are still available.') && str_contains($js, 'canRetry: true'), 'notification popup state is synchronized and refresh failures retain loaded items with inline retry');
$assert(str_contains($php, 'role="dialog" aria-labelledby="notif-dropdown-title" aria-modal="false"') && str_contains($php, 'id="notif-dropdown-title"'), 'notification popup has a named accessible region');

$assert(str_contains($php, '<aside id="customer-sidebar" class="dashboard-sidebar" inert>') && str_contains($php, 'aria-controls="customer-sidebar" aria-expanded="false"') && str_contains($php, 'id="sidebar-overlay" class="sidebar-overlay" aria-hidden="true"'), 'drawer has a stable ID, closed inert state, controlled trigger, and hidden overlay state');
$assert(str_contains($php, '<button type="button" id="btn-close-sidebar"') && str_contains($js, 'sidebar.inert = !open && !desktopSidebarQuery.matches') && str_contains($js, 'drawerInvoker = document.activeElement') && str_contains($js, 'event.key === \'Escape\'') && str_contains($js, 'event.shiftKey') && str_contains($js, 'last.focus()') && str_contains($js, 'invoker?.focus({ preventScroll: true })') && str_contains($js, 'bodyOverflowBeforeDrawer'), 'drawer opens focusably, traps Tab in both directions, closes with Escape, restores focus and prior overflow, and syncs viewport state');
$assert(str_contains($php, '<a href="support.php" class="nav-link support-link">') && str_contains($php, '<span>Help &amp; Support</span>'), 'sidebar includes the labeled Help & Support route');

$assert(str_contains($js, 'Your cancellation request was not submitted') && str_contains($js, 'Your reschedule request was not submitted') && str_contains($js, 'Your profile changes were not submitted') && str_contains($js, 'Your preference changes were not submitted') && str_contains($js, 'Your password change was not submitted') && str_contains($js, 'Your review was not submitted') && str_contains($js, 'Check your connection and try again.') && !str_contains($js, 'Network error occurred.'), 'customer submission failures state what was not submitted and how to retry');
$assert(str_contains($js, 'Booking details could not be loaded. No booking changes were submitted.'), 'booking detail loading failure is contextual and confirms changes were not submitted');

$cancelRequestStart = strpos($js, "fetch('actions/user/request_cancel.php'");
$cancelRequestEnd = strpos($js, '.then((response) => response.json())', $cancelRequestStart === false ? 0 : $cancelRequestStart);
$cancelRequest = $cancelRequestStart !== false && $cancelRequestEnd !== false
    ? substr($js, $cancelRequestStart, $cancelRequestEnd - $cancelRequestStart)
    : '';
$assert($cancelRequest !== ''
    && str_contains($cancelRequest, 'booking_id: bookingId')
    && str_contains($cancelRequest, 'reason: reason')
    && str_contains($cancelRequest, 'refund_destination: {')
    && str_contains($cancelRequest, '...(isRefundable ? {'),
    'cancellation request retains its booking/reason payload and sends destination data only for paid refunds');

foreach ([
    'actions/user/request_cancel.php',
    'actions/user/request_reschedule.php',
    'body: JSON.stringify({ booking_id: bookingId, new_start_date: newStart, new_end_date: newEnd, reason: reason })',
    'actions/user/save_settings.php',
    'body: JSON.stringify(payload)',
    'actions/user/get_my_booking_details.php?id=${bookingId}',
    'actions/user/get_notifications.php',
    'actions/user/mark_notifications_read.php',
    'body: `id=${encodeURIComponent(id)}`',
] as $criticalRequestContract) {
    $assert(str_contains($js, $criticalRequestContract), 'critical existing endpoint/payload contract is retained: ' . $criticalRequestContract);
}
$assert(str_contains($css, '.notif-item:focus-visible') && str_contains($css, '.notif-item-content') && str_contains($css, '.history-header .status-filter') && str_contains($css, '.action-cell .btn-details .vd-text { display: inline !important; }') && str_contains($css, '.sidebar-footer .support-link'), 'CSS includes visible focus, long-content wrapping, responsive selector, visible details text, and Help styling');
$assert(str_contains($php, 'id="ud-payment-history-list"') && !str_contains($php, 'data-tid='), 'customer booking markup avoids embedding the internal transaction id and provides a payment-history target');
$assert(str_contains($js, 'Array.isArray(res.data.payments)') && str_contains($js, "['Payment method', payment?.payment_method") && str_contains($js, "['Transaction/reference ID', payment?.transaction_reference") && str_contains($js, 'valueEl.textContent = String(value)'), 'customer booking details render ordered structured payment entries with safe text nodes');
$assert(str_contains($js, "['pending', 'rejected'].includes(manualSubmissionStatus)") && str_contains($js, "const rejectionReason = manualSubmissionStatus === 'rejected'") && str_contains($js, 'reviewNoteEl.textContent = rejectionReason;'), 'latest manual-submission details remain visible for pending/rejected proofs only, preserving rejection notes while approved payments use payment history');
$assert(str_contains($css, 'grid-template-columns: repeat(2, minmax(0, 1fr));') && str_contains($css, 'width: min(100%, 340px);') && str_contains($css, 'grid-column: 2;') && str_contains($css, 'grid-column: 1 / -1;') && str_contains($css, 'min-height: 44px;') && str_contains($css, 'grid-template-columns: minmax(0, 1fr); width: 100%;'), 'booking actions form a right-aligned equal two-column grid with full-width mobile and spanning rejection note');
$assert(!str_contains($js, 'data-tid') && !str_contains($js, 'ud-tid'), 'customer details no longer consume a raw internal payment id');

echo "User dashboard quality contract checks passed\n";
