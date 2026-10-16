<?php
declare(strict_types=1);

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

$root = dirname(__DIR__);
$adminJs = realpath($root . '/assets/js/admin-page/admin_bookings.js');
$adminCss = realpath($root . '/assets/css/admin-page/admin_bookings.css');
$globalCss = realpath($root . '/assets/css/style.css');
if ($adminJs === false || $adminCss === false || $globalCss === false) throw new RuntimeException('Admin UI assets are unavailable.');
$fileUrl = static fn(string $path): string => 'file://' . str_replace(' ', '%20', $path);
$adminJsUrl = $fileUrl($adminJs);
$adminCssUrl = $fileUrl($adminCss);
$globalCssUrl = $fileUrl($globalCss);
$fixtureDir = sys_get_temp_dir() . '/sevilla360-admin-overdue-ui-' . bin2hex(random_bytes(8));
if (!mkdir($fixtureDir, 0700)) throw new RuntimeException('Unable to create isolated browser fixture.');

$html = <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><meta name="csrf-token" content="synthetic">
<link rel="stylesheet" href="{$globalCssUrl}"><link rel="stylesheet" href="{$adminCssUrl}">
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
  <section class="admin-modal" id="declineModal"><span id="decline-booking-id"></span><button id="btn-execute-decline"></button><button class="close-modal">Close</button></section>
  <section class="admin-modal manual-proof-review-modal" id="manualPaymentReviewModal" role="dialog" aria-modal="true" tabindex="-1">
    <h3 id="manual-proof-title">Review payment proof</h3><p id="manual-proof-context"></p>
    <strong id="manual-proof-amount"></strong><strong id="manual-proof-method"></strong><strong id="manual-proof-reference"></strong>
    <img id="manual-proof-preview" hidden><div id="manual-proof-late-ack-wrap" hidden><label for="manual-proof-late-ack"><input type="checkbox" id="manual-proof-late-ack"> I acknowledge this is a late payment verification.</label></div>
    <textarea id="manual-proof-rejection-reason"></textarea><p id="manual-proof-status" hidden></p>
    <button class="close-modal">Close</button><button id="btn-reject-manual-proof">Reject proof</button><button id="btn-approve-manual-proof">Approve payment</button>
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
window.qaReviewRequests = [];
window.fetch = async (url, options = {}) => {
  if (String(url).includes('review_manual_payment.php')) {
    window.qaReviewRequests.push(JSON.parse(options.body));
    return new Response(JSON.stringify({success:true,message:'Payment proof verified and payment recorded.'}), {status:200,headers:{'Content-Type':'application/json'}});
  }
  return new Response(JSON.stringify({success:true,data:[row,normalPendingRow],pagination:{current_page:1,total_pages:1,total_rows:2}}), {status:200,headers:{'Content-Type':'application/json'}});
};
window.showAlert = () => {};
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
    check(await waitFor(() => document.querySelector('.open-manual-proof')), 'booking row did not render');
    const rowEl = document.querySelector('#admin-bookings-tbody tr');
    const beforeHeight = rowEl.getBoundingClientRect().height;
    check(document.querySelector('.status-overdue')?.textContent === 'Overdue Verification', 'overdue badge is missing');
    check(document.querySelector('.status-note--overdue')?.textContent.includes('Event ended — payment still unverified'), 'overdue explanation is missing');
    check(document.querySelector('.open-manual-proof')?.textContent.trim() === 'Review Proof', 'Review Proof is not the primary action');
    const disabledPrice = document.querySelector('#admin-bookings-tbody tr:nth-child(2) .btn-action--disabled');
    check(disabledPrice?.disabled && disabledPrice.textContent.trim() === 'Price editing unavailable', 'unavailable price editing is not clearly disabled');
    check(parseFloat(getComputedStyle(disabledPrice).fontSize) >= 13 && getComputedStyle(disabledPrice).textTransform === 'none', 'disabled action text remains readable and consistently cased');

    const toggle = document.querySelector('.booking-action-menu-toggle');
    toggle.click();
    const panel = document.getElementById(toggle.getAttribute('aria-controls'));
    const panelRect = panel.getBoundingClientRect();
    check(document.body.contains(panel) && panel.parentElement === document.body, 'secondary menu was not moved to the body portal');
    check(panelRect.left >= 0 && panelRect.top >= 0 && panelRect.right <= innerWidth + 1 && panelRect.bottom <= innerHeight + 1, 'portal menu exceeds the viewport');
    check(Math.abs(rowEl.getBoundingClientRect().height - beforeHeight) < 1, 'opening the portal menu expanded its table row');
    const enabledItems = Array.from(panel.querySelectorAll('[role="menuitem"]:not([disabled])'));
    check(enabledItems[0]?.tabIndex === 0 && window.qaFocusCalls[0]?.includes('role="menuitem"'), 'menu did not request focus on its first enabled item');
    enabledItems[0].dispatchEvent(new KeyboardEvent('keydown', {key:'ArrowDown',bubbles:true}));
    check(enabledItems[0].tabIndex === -1 && enabledItems[1].tabIndex === 0, 'ArrowDown did not navigate enabled menu items');
    enabledItems[1].dispatchEvent(new KeyboardEvent('keydown', {key:'Home',bubbles:true}));
    check(enabledItems[0].tabIndex === 0 && enabledItems[1].tabIndex === -1, 'Home did not select the first enabled menu item');
    enabledItems[0].dispatchEvent(new KeyboardEvent('keydown', {key:'End',bubbles:true}));
    check(enabledItems[0].tabIndex === -1 && enabledItems[1].tabIndex === 0, 'End did not select the last enabled menu item');
    enabledItems[1].dispatchEvent(new KeyboardEvent('keydown', {key:'Escape',bubbles:true}));
    check(toggle.getAttribute('aria-expanded') === 'false' && panel.parentElement.classList.contains('booking-action-menu') && window.qaFocusCalls.at(-1)?.includes('booking-action-menu-toggle'), 'Escape did not close, restore origin, and request focus restoration; expanded=' + toggle.getAttribute('aria-expanded') + '; parent=' + panel.parentElement.className + '; lastFocus=' + window.qaFocusCalls.at(-1));

    toggle.click();
    document.getElementById('outside-click').click();
    check(toggle.getAttribute('aria-expanded') === 'false' && panel.parentElement.classList.contains('booking-action-menu'), 'outside click did not close the portal menu');

    toggle.click();
    panel.querySelector('.open-decline').click();
    check(document.getElementById('declineModal').classList.contains('active') && document.getElementById('decline-booking-id').textContent === '501', 'a menu action did not reach its existing click handler');
    document.querySelector('#declineModal .close-modal').click();

    const reviewButton = document.querySelector('.open-manual-proof');
    reviewButton.click();
    const ack = document.getElementById('manual-proof-late-ack');
    await new Promise(resolve => setTimeout(resolve, 40));
    check(!document.getElementById('manual-proof-late-ack-wrap').hidden && window.qaFocusCalls.at(-1)?.includes('manual-proof-late-ack'), 'late acknowledgement was not shown and sent a focus request');
    document.getElementById('btn-approve-manual-proof').click();
    check(document.getElementById('manual-proof-status').textContent.includes('Acknowledge') && window.qaReviewRequests.length === 0, 'approval without acknowledgement was submitted');
    ack.checked = true;
    document.getElementById('btn-approve-manual-proof').click();
    check(await waitFor(() => window.qaReviewRequests.length === 1), 'acknowledged review request was not sent');
    check(window.qaReviewRequests[0].submission_id === '7001' && window.qaReviewRequests[0].acknowledge_late_verification === true, 'review request did not send the explicit acknowledgement');
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
        echo ($passed ? 'PASS|' : 'FAIL|') . $label . ' overdue proof UI menu and acknowledgement runtime' . "\n";
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
