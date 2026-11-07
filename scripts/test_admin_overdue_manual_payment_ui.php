<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$adminJsSource = (string)file_get_contents($root . '/assets/js/admin-page/admin_bookings.js');
$adminMarkupSource = (string)file_get_contents($root . '/includes/admin-page/admin_bookings.php');
if (str_contains($adminJsSource, 'open-manual-proof')
    || str_contains($adminJsSource, 'actions/admin/review_manual_payment.php')
    || str_contains($adminMarkupSource, 'manualPaymentReviewModal')
    || str_contains($adminMarkupSource, 'btn-approve-manual-proof')
    || str_contains($adminMarkupSource, 'btn-reject-manual-proof')) {
    throw new RuntimeException('Retired admin payment-proof review UI remains reachable.');
}
if (!str_contains($adminMarkupSource, 'id="vd-proof-history"')
    || !str_contains($adminJsSource, 'function renderAdminProofHistory(')
    || !str_contains($adminJsSource, "viewButton.textContent = proofAvailable ? 'View proof' : 'Proof no longer available';")) {
    throw new RuntimeException('Read-only historical proof details were removed.');
}

$chromeCandidates = array_filter([
    getenv('CHROME_BIN') ?: null,
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
]);
$chrome = null;
foreach ($chromeCandidates as $candidate) {
    if (is_file($candidate) && is_executable($candidate)) {
        $chrome = $candidate;
        break;
    }
}
if ($chrome === null) {
    fwrite(STDOUT, "SKIP|Chrome is unavailable for the overdue payment UI check.\n");
    exit(0);
}

$adminJs = realpath($root . '/assets/js/admin-page/admin_bookings.js');
$adminCss = realpath($root . '/assets/css/admin-page/admin_bookings.css');
$globalCss = realpath($root . '/assets/css/style.css');
$refinementCss = realpath($root . '/assets/css/ui-refinement.css');
if ($adminJs === false || $adminCss === false || $globalCss === false || $refinementCss === false) throw new RuntimeException('Admin UI assets are unavailable.');
$fileUrl = static fn(string $path): string => 'file://' . str_replace(' ', '%20', $path);
$adminJsUrl = $fileUrl($adminJs);
$adminCssUrl = $fileUrl($adminCss);
$globalCssUrl = $fileUrl($globalCss);
$refinementCssUrl = $fileUrl($refinementCss);
$fixtureDir = sys_get_temp_dir() . '/sevilla360-admin-overdue-ui-' . bin2hex(random_bytes(8));
if (!mkdir($fixtureDir, 0700)) throw new RuntimeException('Unable to create isolated browser fixture.');

$html = <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><meta name="csrf-token" content="synthetic">
<link rel="stylesheet" href="{$globalCssUrl}"><link rel="stylesheet" href="{$adminCssUrl}"><link rel="stylesheet" href="{$refinementCssUrl}">
</head><body><main class="admin-bookings-container">
<input id="table-search"><select id="table-venue-filter"><option value="All">All</option></select>
<button id="btn-reset-booking-filters"></button>
<div id="bookingFilters"><button class="tab-btn active" data-filter="all" aria-pressed="true">All</button></div>
<select id="bookingFilterSelect"><option value="" selected>Select</option></select>
<p id="booking-results-status"></p><div class="table-responsive"><table class="bookings-table"><tbody id="admin-bookings-tbody"></tbody></table></div>
<span id="pag-current-page"></span><span id="pag-total-pages"></span><span id="pag-total-rows"></span>
<button id="btn-prev-page"></button><button id="btn-next-page"></button>
<a id="btn-export-bookings"></a><button id="btn-refresh-bookings"></button>
<div class="modal-overlay" id="modalOverlay">
  <section class="admin-modal" id="declineModal"><span id="decline-booking-id"></span><button id="btn-execute-decline">Yes, Cancel It</button><button class="close-modal">Close</button></section>
  <section class="admin-modal modal-edit-price" id="editPriceModal">
    <span id="ep-booking-id"></span><input id="ep-guests"><input id="ep-event-type">
    <select id="ep-event-style"><option value="theater">Theater</option><option value="classroom">Classroom</option><option value="banquet">Banquet</option></select>
    <small id="ep-event-style-help"></small><input id="ep-base-rate"><select id="ep-payment-scheme"><option value="20% Reservation">20% Reservation</option><option value="50% Downpayment">50% Downpayment</option></select>
    <textarea id="ep-admin-notes"></textarea><div id="ep-line-items"></div><span id="ep-calc-total"></span>
    <button id="ep-btn-add-item"></button><button id="btn-execute-edit-price" disabled>Success!</button>
  </section>
</div><button id="outside-click">Outside</button></main>
<script>
const urlBookingId = null; const urlSearch = null;
const row = {record_type:'booking',id:501,reference_no:'OVD-501',venue_name:'Synthetic Hotel',venue_category:'Hotel Room',
  first_name:'Ada',last_name:'Lovelace',start_date:'2026-10-10',end_date:'2026-10-11',total_amount:'100.00',amount_paid:'0.00',
  booking_status:'Pending',display_booking_status:'Pending',payment_status:'Unpaid',event_ended:1,proof_overdue:1,
  pending_payment_submission_id:7001,pending_payment_expected_amount:'100.00',pending_payment_method:'GCash',pending_payment_reference:'TXN-ABC'};
const normalPendingRow = {record_type:'booking',id:502,reference_no:'PENDING-502',venue_name:'Synthetic Hotel',venue_category:'Hotel Room',
  first_name:'Grace',last_name:'Hopper',start_date:'2026-11-10',end_date:'2026-11-11',total_amount:'200.00',amount_paid:'0.00',
  booking_status:'Pending',display_booking_status:'Pending',payment_status:'Unpaid',event_ended:0,proof_overdue:0};
const eventInquiry = (id, eventEnded = 0, paymentStatus = 'Unpaid', amountPaid = '0.00', pendingProofId = 0) => ({record_type:'booking',id,reference_no:'EVENT-'+id,
  venue_name:'Synthetic Event Hall',venue_category:'Event Hall',first_name:'Katherine',last_name:'Johnson',start_date:'2026-12-10',end_date:'2026-12-10',
  total_amount:'0.00',amount_paid:amountPaid,booking_status:'Pending',display_booking_status:'Pending',payment_status:paymentStatus,event_ended:eventEnded,proof_overdue:0,pending_payment_submission_id:pendingProofId});
const eventRows = [eventInquiry(503),eventInquiry(504,1),eventInquiry(505,0,'Partial','25.00'),eventInquiry(506),eventInquiry(507,0,'Unpaid','0.00',7007)];
window.qaBookingActions = [];
window.qaPendingDetails = [];
window.qaRespond = (data, status = 200) => new Response(JSON.stringify(data), {status,headers:{'Content-Type':'application/json'}});
const staleDeclineButton = document.getElementById('btn-execute-decline');
staleDeclineButton.disabled = true; staleDeclineButton.textContent = 'Success!'; staleDeclineButton.style.opacity = '0.7';
window.qaRetiredReviewCalls = 0;
window.fetch = async (url, options = {}) => {
  if (String(url).includes('update_booking_status.php')) {
    window.qaBookingActions.push(JSON.parse(options.body || '{}'));
    return window.qaRespond({success:true,message:'Synthetic action accepted'});
  }
  if (String(url).includes('get_booking_details.php')) {
    const match = String(url).match(/[?&]id=(\d+)/);
    const id = match ? match[1] : '';
    return new Promise(resolve => window.qaPendingDetails.push({id,resolve:() => resolve(window.qaRespond({success:true,data:{
      booking:{reference_no:'EVENT-'+id,guests_count:120,base_amount:'0.00',payment_scheme:'20% Reservation'},
      specifics:{event_type:'Synthetic event',event_style:'theater',admin_notes:''},addons:[],line_items:[],event_style_capacities:{theater:120,classroom:90,banquet:80}
    }}))}));
  }
  if (String(url).includes('review_manual_payment.php')) {
    window.qaRetiredReviewCalls++;
  }
  return window.qaRespond({success:true,data:[row,normalPendingRow,...eventRows],pagination:{current_page:1,total_pages:1,total_rows:2+eventRows.length}});
};
window.qaAlerts = [];
window.showAlert = (...args) => { window.qaAlerts.push(args); };
window.showConfirm = async () => false;
window.qaFocusCalls = [];
const nativeFocus = HTMLElement.prototype.focus;
HTMLElement.prototype.focus = function(...args) { window.qaFocusCalls.push(this.outerHTML?.slice(0,140) || this.tagName); return nativeFocus.apply(this,args); };
</script>
<script src="{$adminJsUrl}"></script>
<script>
(async () => {
  const waitFor = async predicate => {
    for (let i=0; i<100; i++) { if (predicate()) return true; await new Promise(resolve => setTimeout(resolve, 20)); }
    return false;
  };
  const check = (condition, name) => { if (!condition) throw new Error(name); };
  try {
    check(await waitFor(() => document.querySelector('.btn-view[data-id="501"]')), 'booking row did not render');
    const targetBookingId = innerWidth <= 700 ? '501' : '502';
    const rowEl = document.querySelector('#admin-bookings-tbody tr:nth-child(' + (targetBookingId === '501' ? 1 : 2) + ')');
    const beforeHeight = rowEl.getBoundingClientRect().height;
    check(document.querySelector('.status-overdue')?.textContent === 'Overdue Verification', 'overdue badge is missing');
    check(document.querySelector('.status-note--overdue')?.textContent.includes('Event ended — payment still unverified'), 'overdue explanation is missing');
    check(document.querySelector('.open-manual-proof') === null && document.getElementById('manualPaymentReviewModal') === null, 'obsolete proof review control or modal remains reachable');
    check(document.querySelector('.btn-view[data-id="501"]')?.textContent.trim() === 'View Details', 'booking details action is missing');
    const disabledPrice = document.querySelector('#admin-bookings-tbody tr:nth-child(2) .btn-action--disabled');
    check(disabledPrice?.disabled && disabledPrice.textContent.trim() === 'Price editing unavailable', 'unavailable price editing is not clearly disabled');
    check(parseFloat(getComputedStyle(disabledPrice).fontSize) >= 13 && getComputedStyle(disabledPrice).textTransform === 'none', 'disabled action text remains readable and consistently cased');

    if (innerWidth > 700) {
      const toggle = document.querySelector('.booking-action-menu-toggle[aria-controls="booking-actions-booking-' + targetBookingId + '"]');
      toggle.click();
      const panel = document.getElementById(toggle.getAttribute('aria-controls'));
      const panelRect = panel.getBoundingClientRect();
      check(document.body.contains(panel) && panel.parentElement === document.body, 'secondary menu was not moved to the body portal');
      check(panelRect.left >= 0 && panelRect.top >= 0 && panelRect.right <= innerWidth + 1 && panelRect.bottom <= innerHeight + 1, 'portal menu exceeds the viewport');
      check(Math.abs(rowEl.getBoundingClientRect().height - beforeHeight) < 1, 'opening the portal menu expanded its table row');
      const enabledItems = Array.from(panel.querySelectorAll('[role="menuitem"]:not([disabled])'));
      check(enabledItems[0]?.tabIndex === 0 && window.qaFocusCalls[0]?.includes('role="menuitem"'), 'menu did not request focus on its first enabled item');
      enabledItems[0].dispatchEvent(new KeyboardEvent('keydown', {key:'ArrowDown',bubbles:true}));
      const arrowTarget = enabledItems[enabledItems.length > 1 ? 1 : 0];
      check(arrowTarget.tabIndex === 0 && enabledItems.filter(item => item.tabIndex === 0).length === 1, 'ArrowDown did not navigate enabled menu items');
      arrowTarget.dispatchEvent(new KeyboardEvent('keydown', {key:'Home',bubbles:true}));
      check(enabledItems[0].tabIndex === 0 && enabledItems.filter(item => item.tabIndex === 0).length === 1, 'Home did not select the first enabled menu item');
      enabledItems[0].dispatchEvent(new KeyboardEvent('keydown', {key:'End',bubbles:true}));
      const lastEnabledItem = enabledItems.at(-1);
      check(lastEnabledItem.tabIndex === 0 && enabledItems.filter(item => item.tabIndex === 0).length === 1, 'End did not select the last enabled menu item');
      lastEnabledItem.dispatchEvent(new KeyboardEvent('keydown', {key:'Escape',bubbles:true}));
      check(toggle.getAttribute('aria-expanded') === 'false' && panel.parentElement.classList.contains('booking-action-menu') && window.qaFocusCalls.at(-1)?.includes('booking-action-menu-toggle'), 'Escape did not close, restore origin, and request focus restoration; expanded=' + toggle.getAttribute('aria-expanded') + '; parent=' + panel.parentElement.className + '; lastFocus=' + window.qaFocusCalls.at(-1));

      toggle.click();
      document.getElementById('outside-click').click();
      check(toggle.getAttribute('aria-expanded') === 'false' && panel.parentElement.classList.contains('booking-action-menu'), 'outside click did not close the portal menu');

      toggle.click();
      panel.querySelector('.open-decline').click();
      check(document.getElementById('declineModal').classList.contains('active') && document.getElementById('decline-booking-id').textContent === targetBookingId, 'a menu action did not reach its existing click handler');
      check(!document.getElementById('btn-execute-decline').disabled && document.getElementById('btn-execute-decline').textContent.trim() === 'Yes, Cancel It', 'decline action did not reset stale disabled/success state');
      document.querySelector('#declineModal .close-modal').click();
    }

    const eventRow = document.querySelector('#admin-bookings-tbody tr[data-ref="event-503"]');
    const eventEditButton = eventRow?.querySelector('.open-edit-price[data-id="503"]');
    const eventCancelButton = eventRow?.querySelector('.action-buttons > .open-decline[data-id="503"]');
    check(eventEditButton && eventEditButton.classList.contains('btn-confirm'), 'pending Event Hall quote action is missing visible enabled styling');
    check(getComputedStyle(eventEditButton).backgroundColor === 'rgb(140, 96, 45)' && getComputedStyle(eventEditButton).color === 'rgb(255, 255, 255)', 'pending Event Hall quote action does not use the live gold and white palette');
    check(eventCancelButton?.textContent.trim() === 'Decline', 'unpaid pending Event Hall decline is not discoverable inline');
    check(!document.querySelector('#admin-bookings-tbody tr[data-ref="event-505"] .open-decline'), 'paid pending Event Hall inquiry exposes the unpaid-only cancel action');
    check(!document.querySelector('#admin-bookings-tbody tr[data-ref="event-507"] .open-decline'), 'pending payment proof exposes Event Hall cancellation before review');

    const idCell = eventRow.querySelector('[data-label="Booking ID"]');
    const statusBadge = eventRow.querySelector('[data-label="Status"] .status-badge');
    if (innerWidth > 1024) {
      const table = document.querySelector('.bookings-table');
      check(getComputedStyle(idCell).whiteSpace === 'nowrap' && getComputedStyle(statusBadge).whiteSpace === 'nowrap', 'desktop booking ID or status badge can wrap');
      check(table.scrollWidth >= 1240 && getComputedStyle(document.querySelector('.table-responsive')).overflowX === 'auto', 'desktop table does not keep its readable width inside the scroll wrapper');
      check(getComputedStyle(document.querySelector('.action-buttons')).display === 'grid', 'desktop row actions remain stacked instead of using the two-column layout');
    } else {
      check(getComputedStyle(rowEl).display === 'block' && getComputedStyle(document.querySelector('.table-responsive')).overflowX === 'visible', 'mobile booking cards were replaced by a horizontally scrolling table');
    }

    eventCancelButton.click();
    const declineExecute = document.getElementById('btn-execute-decline');
    check(document.getElementById('decline-booking-id').textContent === '503' && !declineExecute.disabled && declineExecute.textContent.trim() === 'Yes, Cancel It', 'opening event cancellation did not reset modal state and target');
    declineExecute.click();
    check(await waitFor(() => window.qaBookingActions.length === 1), 'synthetic cancellation action was not sent');
    check(window.qaBookingActions[0].action === 'cancel' && String(window.qaBookingActions[0].booking_id) === '503', 'cancellation action did not retain the selected pending event ID');
    check(await waitFor(() => !eventRow.isConnected && document.querySelector('.open-decline[data-id="504"]')), 'pending event rows did not reload after synthetic success');
    document.querySelector('.open-decline[data-id="504"]').click();
    check(document.getElementById('decline-booking-id').textContent === '504' && !document.getElementById('btn-execute-decline').disabled && document.getElementById('btn-execute-decline').textContent.trim() === 'Yes, Cancel It', 'reopening decline after success retained stale state or booking ID');
    document.querySelector('#declineModal .close-modal').click();

    document.querySelector('.open-edit-price[data-id="503"]').click();
    document.querySelector('.open-edit-price[data-id="506"]').click();
    check(await waitFor(() => window.qaPendingDetails.length === 2), 'event quote detail requests did not start');
    window.qaPendingDetails.find(request => request.id === '506')?.resolve();
    check(await waitFor(() => document.getElementById('editPriceModal').classList.contains('active')), 'pending Event Hall quote details did not open; requests=' + JSON.stringify(window.qaPendingDetails.map(request => request.id)) + '; buttons=' + JSON.stringify(Array.from(document.querySelectorAll('.open-edit-price')).map(button => [button.dataset.id,button.textContent,button.disabled])) + '; alerts=' + JSON.stringify(window.qaAlerts));
    check(document.getElementById('ep-booking-id').textContent === 'EVENT-506', 'latest event quote request did not populate its booking');
    const finalizeButton = document.getElementById('btn-execute-edit-price');
    check(!finalizeButton.disabled && finalizeButton.textContent.trim() === 'Save & Send Invoice', 'opening event quote did not reset stale finalize button state');
    window.qaPendingDetails.find(request => request.id === '503')?.resolve();
    await new Promise(resolve => setTimeout(resolve, 50));
    check(document.getElementById('ep-booking-id').textContent === 'EVENT-506', 'stale event quote response overwrote the current booking');

    check(window.qaRetiredReviewCalls === 0, 'retired proof-review endpoint was called');
    document.body.dataset.overdueUi = 'pass';
  } catch (error) {
    document.body.dataset.overdueUi = 'fail';
    document.body.dataset.overdueUiReason = String(error.message || error).slice(0, 500);
  }
})();
</script></body></html>
HTML;

$failed = 0;
try {
    file_put_contents($fixtureDir . '/fixture.html', $html, LOCK_EX);
    foreach ([['desktop', '1280,900'], ['mobile', '390,844']] as [$label, $size]) {
        $command = [
            $chrome, '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
            '--user-data-dir=' . $fixtureDir . '/profile-' . $label, '--no-first-run', '--disable-background-networking',
            '--disable-sync', '--metrics-recording-only', '--disable-default-apps', '--disable-component-update',
            '--allow-file-access-from-files', '--window-size=' . $size, '--virtual-time-budget=2500', '--dump-dom',
            $fileUrl($fixtureDir . '/fixture.html'),
        ];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Chrome could not start.');
        fclose($pipes[0]);
        $dom = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $passed = $exitCode === 0 && str_contains((string)$dom, 'data-overdue-ui="pass"');
        echo ($passed ? 'PASS|' : 'FAIL|') . $label . ' overdue status, pending Event Hall actions, modal reset, and stale quote response runtime' . "\n";
        if (!$passed) {
            if (preg_match('/data-overdue-ui-reason="([^"]*)"/', (string)$dom, $match) === 1) fwrite(STDERR, $label . ' detail: ' . html_entity_decode($match[1]) . "\n");
            if ($errors !== '') fwrite(STDERR, $label . ' browser diagnostic: ' . substr($errors, 0, 500) . "\n");
            $failed++;
        }
    }
} finally {
    $removeOwnedTree = static function (string $path) use (&$removeOwnedTree): void {
        if (is_link($path) || is_file($path)) { unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') $removeOwnedTree($path . '/' . $name);
        rmdir($path);
    };
    $removeOwnedTree($fixtureDir);
}
exit($failed === 0 ? 0 : 1);
