<?php
/** Static contract checks for public venue browsing and guest booking safety. */
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$booking_php = $read('booking.php');
$booking_js = $read('assets/js/booking.js');
$calendar_js = $read('assets/js/calendar.js');
$index_php = $read('index.php');
$index_js = $read('assets/js/index.js');
$lock_dates_php = $read('actions/bookings/lock_dates.php');
$admin_status_php = $read('actions/admin/update_booking_status.php');
$index_css = $read('assets/css/index.css');
$ui_refinement_css = $read('assets/css/ui-refinement.css');
$submit_start = strpos($booking_js, 'async submitOnlineBooking()');
$submit_body = $submit_start === false ? '' : substr($booking_js, $submit_start);
$guest_redirect_position = strpos($submit_body, "window.location.href = 'auth.php?destination=booking_resume';");
$customer_lock_position = strpos($submit_body, "if (this.state.activeTabId !== 'event-hall' && !this.state.isDatesLocked)");
$guest_confirm_start = strpos($booking_js, 'if (!canHold)');
$guest_lock_fetch_position = strpos($booking_js, "fetch('actions/bookings/lock_dates.php'");
$restore_start = strpos($booking_js, 'async restoreDraftIfRequested()');
$restore_body = $restore_start === false ? '' : substr($booking_js, $restore_start);
$restore_final_save_position = strpos($restore_body, "this.isRestoringDraft = false;\n            this.saveDraft();");
$invalid_primary_position = strpos($restore_body, 'if (invalidStart || invalidInterior || invalidEnd)');
$invalid_primary_addon_restore_position = $invalid_primary_position === false ? false : strpos($restore_body, 'await this.restoreAddonSelections(draft);', $invalid_primary_position);
$modal_calendar_constructor_count = substr_count($index_js, "new SevillaCalendar('idx-modal-calendar'");
$xss_fixture = '<img src=x onerror=alert(1)> & <special>';
$cancel_position = strpos($admin_status_php, "elseif (\$action === 'cancel')");
$cancel_body = $cancel_position === false ? '' : substr($admin_status_php, $cancel_position, 500);
$checks = [
    'booking page is session-backed and not customer-guarded' => str_contains($booking_php, "require_once 'includes/session_init.php';") && !str_contains($booking_php, "require 'includes/auth_guard.php';"),
    'saved phone lookup is customer-only' => str_contains($booking_php, 'if ($booking_is_customer)') && str_contains($booking_php, '$saved_contact_phone ='),
    'guest lock endpoint remains customer-only' => str_contains($read('actions/bookings/lock_dates.php'), "(\$_SESSION['role'] ?? '') === 'customer'") && str_contains($read('actions/bookings/submit_online.php'), "\$_SESSION['role'] !== 'customer'"),
    'booking draft has a version, ttl, and excludes sensitive fields' => str_contains($booking_js, "sevilla360.booking-draft.v1") && str_contains($booking_js, 'version: 1') && str_contains($booking_js, 'createdAt') && str_contains($booking_js, 'draftTtlMs') && str_contains($booking_js, "? 'Hotel Room'") && !str_contains($booking_js, 'phone:') && !str_contains($booking_js, 'notes:') && !str_contains($booking_js, 'computedTotal:'),
    'booking draft persists add-on selections and saves changes' => str_contains($booking_js, 'addonStartDate') && str_contains($booking_js, 'cateringTier') && str_contains($booking_js, 'roomGroups') && str_contains($booking_js, 'quantityInput.addEventListener') && str_contains($booking_js, 'event-type-others') && substr_count($booking_js, 'this.saveDraft();') >= 8,
    'guest submit redirects before the customer-only lock requirement' => str_contains($submit_body, 'if (!this.state.activeCalendar?.startDate)') && $guest_redirect_position !== false && $customer_lock_position !== false && $guest_redirect_position < $customer_lock_position,
    'guest confirmation never calls the lock endpoint' => $guest_confirm_start !== false && $guest_lock_fetch_position !== false && $guest_confirm_start < $guest_lock_fetch_position && str_contains($booking_js, 'const canHold = this.auth.isCustomer && !isEventInquiry'),
    'restoration scopes payment and checks add-on authority' => str_contains($booking_js, 'item.name === activeContext.activeRadioGroup') && str_contains($booking_js, 'restoreAddonSelections') && str_contains($booking_js, 'updateRoomAvailabilityLabels(startDate, endDate)') && str_contains($booking_js, 'get_room_availability.php'),
    'draft restoration defers writes until all controls are restored' => str_contains($booking_js, 'this.isRestoringDraft = true;') && str_contains($booking_js, 'if (this.isRestoringDraft) return;') && $restore_final_save_position !== false && str_contains($restore_body, 'restoreCatering();') && str_contains($restore_body, 'restoreAv();'),
    'invalid primary dates retain venue options and restore independent add-ons' => $invalid_primary_position !== false && $invalid_primary_addon_restore_position !== false && str_contains(substr($restore_body, $invalid_primary_position), 'restoreCatering();') && str_contains(substr($restore_body, $invalid_primary_position), 'restoreAv();') && str_contains(substr($restore_body, $invalid_primary_position), "calendar.clearSelectedRange();"),
    'checkout redirect clears draft only after server response' => str_contains($booking_js, "if (response[0] === 'CheckoutUrl')") && str_contains($booking_js, "this.clearDraft();\n                window.location.href = response[1];"),
    'modal calendar is created once and category rules reset' => $modal_calendar_constructor_count === 1 && str_contains($index_js, 'modalCalendar.clearSelection()') && str_contains($index_js, 'fixedDurationNights = venue.category === \'Resort Villa\' ? 0 : null') && str_contains($index_js, 'fixedDurationGuard = venue.category === \'Resort Villa\''),
    'homepage cards and modal expose complete category rates' => str_contains($index_php, 'max_nightly_rate') && str_contains($index_php, 'publicVenueCatalog') && str_contains($index_js, 'idx-catalog-card-rate') && str_contains($index_js, 'idx-modal-rate') && str_contains($index_js, 'hotelRateText') && str_contains($index_js, 'Rate on request'),
    'homepage categories use a responsive stacked carousel sequence' => str_contains($index_php, 'idx-catalog-stack') && !str_contains($index_php, 'idx-catalog-grid') && substr_count($index_php, 'class="idx-catalog-section idx-catalog-section--') === 1 && str_contains($index_php, "['Event Hall' => 'Event Halls', 'Resort Villa' => 'Resort Villas', 'Hotel Room' => 'Hotel Rooms']") && str_contains($index_css, '.idx-catalog-stack { display: flex; flex-direction: column;') && !str_contains($index_css, '.idx-catalog-stack { display: grid') && !str_contains($index_css, '.idx-catalog-stack { grid-template-columns') && str_contains($index_css, '.idx-catalog-card { flex: 0 0 100%; display: grid;') && str_contains($index_js, 'Object.values(venue.facts || {}).slice(0, 2)'),
    'carousel controls use accessible icons, overlay positions, and one-item disable state' => str_contains($index_php, 'fa-chevron-left') && str_contains($index_php, 'fa-chevron-right') && str_contains($index_php, 'class="idx-carousel-prev" aria-label="Previous <?php echo htmlspecialchars($label); ?>"') && str_contains($index_php, 'class="idx-carousel-next" aria-label="Next <?php echo htmlspecialchars($label); ?>"') && str_contains($index_php, 'idx-carousel-position') && str_contains($index_js, 'control.disabled = cards.length <= 1') && str_contains($index_js, 'idx-catalog-shell-empty'),
    'single venue carousels remove dead controls and keep many-item navigation' => str_contains($index_js, "shell.classList.toggle('idx-catalog-shell-single', isSingle)") && str_contains($index_js, "section.dataset.carouselState = isSingle ? 'single' : 'multi'") && str_contains($index_css, '.idx-catalog-shell-single { padding-bottom: 0; }') && str_contains($index_css, '.idx-carousel-controls[hidden] { display: none; }') && str_contains($index_css, 'idx-catalog-shell:not(.idx-catalog-shell-single):not(.idx-catalog-shell-empty)'),
    'villa detail action wins later important outline overrides' => str_contains($ui_refinement_css, '.idx-page .idx-catalog-section--resort-villa .idx-catalog-card-actions .idx-btn-outline-dark') && str_contains($ui_refinement_css, 'color: var(--idx-paper) !important') && str_contains($ui_refinement_css, 'color: var(--idx-ink) !important'),
    'venue rows stay compact with a layered villa image treatment' => str_contains($index_css, 'height: clamp(18rem, 28vw, 19rem)') && str_contains($index_css, 'grid-template-columns: minmax(0, .82fr) minmax(0, 1.18fr)') && str_contains($index_css, 'background: transparent; box-shadow: none') && str_contains($index_css, 'top: -3.25rem') === false && str_contains($index_css, 'left: -3.25rem'),
    'homepage rows retain editorial spacing and usable carousel controls' => str_contains($index_css, '.idx-catalog-heading::after') && str_contains($index_css, 'top: clamp(9rem, 14vw, 11rem)') && str_contains($index_css, 'min-height: 44px') && str_contains($index_css, 'font-variant-numeric: tabular-nums') && str_contains($index_css, '.idx-catalog-section--resort-villa .idx-carousel-controls button'),
    'modal is framed, viewport bounded, and mobile scroll fallback remains' => str_contains($index_css, 'width: min(49rem, 100%)') && str_contains($index_css, 'height: min(84svh, 44rem)') && str_contains($index_css, 'max-height: min(84svh, calc(100svh - 2rem))') && str_contains($index_css, 'overflow: hidden') && str_contains($index_css, 'overflow-y: auto; overscroll-behavior: contain') && str_contains($index_css, 'max-height: calc(100svh - 2rem); overflow: auto') && str_contains($index_css, '@media (max-height: 640px) and (min-width: 768px)') && !str_contains($index_js, 'const rateFacts'),
    'modal arrows and calendar navigation use accessible icon buttons' => str_contains($index_php, 'fa-xmark') && substr_count($index_php, 'fa-chevron-left') >= 2 && substr_count($index_php, 'fa-chevron-right') >= 2 && str_contains($index_php, 'aria-label="Previous image"') && str_contains($index_php, 'aria-label="Next month"'),
    'summary labels render the xss fixture as text' => str_contains($xss_fixture, '<img src=x onerror=alert(1)>') && str_contains($booking_js, 'labelEl.textContent = label') && str_contains($booking_js, 'breakdownEl.replaceChildren()') && !str_contains($booking_js, '<span>${label}') && !str_contains($booking_js, 'summary.html'),
    'calendar ignores stale availability and supports keyboard cells' => str_contains($calendar_js, 'availabilityRequestGeneration') && str_contains($calendar_js, 'requestGeneration') && str_contains($calendar_js, 'if (requestGeneration !== this.availabilityRequestGeneration) return;') && str_contains($calendar_js, 'createElement(isInteractive ? "button" : "div")') && str_contains($calendar_js, 'isPastDate'),
    'lock dates rejects noncanonical and past starts before insert' => str_contains($lock_dates_php, "preg_match('/\\A[0-9]{4}-[0-9]{2}-[0-9]{2}\\z/D'") && str_contains($lock_dates_php, "format('Y-m-d') === \$start_date") && str_contains($lock_dates_php, "new DateTimeImmutable('today')") && str_contains($lock_dates_php, '$start_dt < $today') && str_contains($lock_dates_php, 'begin_transaction'),
    'homepage delegates document closing to footer' => !str_contains($index_php, '</body>') && !str_contains($index_php, '</html>'),
    'post-auth destination is allowlisted' => str_contains($read('includes/booking_intent.php'), "BOOKING_AUTH_DESTINATION = 'booking_resume'") && str_contains($read('includes/booking_intent.php'), 'hash_equals'),
    'admin override cancellation path is retired' => !preg_match('/force' . '.?' . 'cancel/i', $admin_status_php . $read('includes/admin-page/admin_bookings.php') . $read('assets/js/admin-page/admin_bookings.js')) && str_contains($admin_status_php, "throw new Exception('Invalid action provided.')"),
    'ordinary admin cancellation only accepts pending bookings' => $cancel_position !== false && str_contains($cancel_body, "locked_booking['booking_status'] ?? '') !== 'Pending'") && str_contains($cancel_body, 'Only Pending bookings can be declined or cancelled.'),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? "PASS" : "FAIL") . "|$label\n";
    if (!$passed) $failed++;
}
exit($failed === 0 ? 0 : 1);
