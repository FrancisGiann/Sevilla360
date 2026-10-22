<?php
declare(strict_types=1);

$chromeCandidates = [getenv('CHROME_BIN') ?: null, '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'];
$chrome = null;
foreach ($chromeCandidates as $candidate) {
    if ($candidate && is_file($candidate) && is_executable($candidate)) { $chrome = $candidate; break; }
}
if ($chrome === null) {
    fwrite(STDOUT, "SKIP|Chrome is unavailable for the admin booking history state check.\n");
    exit(0);
}

$fixtureDir = sys_get_temp_dir() . '/sevilla360-admin-history-state-' . bin2hex(random_bytes(8));
if (!mkdir($fixtureDir, 0700)) throw new RuntimeException('Unable to create isolated browser fixture.');
$adminJs = realpath(dirname(__DIR__) . '/assets/js/admin-page/admin_bookings.js');
if ($adminJs === false) throw new RuntimeException('Admin booking script is missing.');
$scriptUrl = 'file://' . str_replace(' ', '%20', $adminJs);
$html = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><meta name="csrf-token" content="synthetic"></head><body>
<div id="bookingRecordTabs" role="tablist">
  <button id="booking-record-tab-booking" type="button" data-record-type="booking" class="active">Bookings</button>
  <button id="booking-record-tab-seminar" type="button" data-record-type="seminar">Seminars</button>
</div>
<h3 id="booking-history-title"></h3><label id="table-search-label" for="table-search"></label>
<input id="table-search" value="keep this search"><select id="table-venue-filter"><option value="All">All</option><option value="Event Hall" selected>Event Hall</option></select>
<button id="btn-reset-booking-filters"></button>
<div data-record-filters="booking"><div id="bookingFilters"><button class="tab-btn active" data-filter="all">All</button><button class="tab-btn" data-filter="pending">Pending</button></div></div>
<div id="seminarFilters" data-record-filters="seminar" hidden><button class="tab-btn active" data-filter="all">All</button><button class="tab-btn" data-filter="draft">Draft</button></div>
<p id="booking-results-status"></p><div id="booking-history-results"><table><caption id="booking-table-caption"></caption><thead><tr><th id="booking-history-id-heading"></th><th id="booking-history-name-heading"></th></tr></thead><tbody id="admin-bookings-tbody"></tbody></table></div>
<span id="pag-current-page"></span><span id="pag-total-pages"></span><span id="pag-total-rows"></span><span id="history-record-count"></span>
<button id="btn-prev-page"></button><button id="btn-next-page"></button><a id="btn-export-bookings"></a><button id="btn-refresh-bookings"></button><div id="modalOverlay"></div>
<script>
window.qaPending = [];
window.fetch = (_url, options) => new Promise(resolve => window.qaPending.push({options, resolve}));
window.qaResponse = payload => ({ok:true,status:200,headers:{get:()=>'application/json'},json:async()=>payload});
window.qaWait = async predicate => {
  const start=Date.now();
  while (!predicate()) { if (Date.now()-start>2000) throw new Error('Timed out waiting for history state.'); await new Promise(resolve=>setTimeout(resolve,10)); }
};
</script>
<script src="{$scriptUrl}"></script>
<script>
document.addEventListener('DOMContentLoaded', async () => {
  try {
    await window.qaWait(() => window.qaPending.length === 1);
    const initial = JSON.parse(window.qaPending[0].options.body);
    if (initial.record_type !== 'booking' || initial.status !== 'all') throw new Error('Booking history is not the initial request.');

    document.querySelector('#bookingFilters [data-filter="pending"]').click();
    document.getElementById('booking-record-tab-seminar').click();
    window.qaPending[0].resolve(window.qaResponse({success:true,data:[],pagination:{current_page:1,total_pages:1,total_rows:0}}));
    await window.qaWait(() => window.qaPending.length === 2);

    const seminarRequest = JSON.parse(window.qaPending[1].options.body);
    if (seminarRequest.record_type !== 'seminar' || seminarRequest.status !== 'all'
      || seminarRequest.search !== 'keep this search' || seminarRequest.venue !== 'Event Hall') {
      throw new Error('Switching history did not reset only the page/status while retaining search and venue.');
    }
    if (!document.getElementById('booking-history-title').textContent.includes('Seminar')
      || document.getElementById('booking-record-tab-seminar').getAttribute('aria-selected') !== 'true'
      || document.getElementById('seminarFilters').hidden) throw new Error('Seminar context did not update: ' + JSON.stringify({title:document.getElementById('booking-history-title').textContent,selected:document.getElementById('booking-record-tab-seminar').getAttribute('aria-selected'),hidden:document.getElementById('seminarFilters').hidden}));
    if (!document.getElementById('btn-export-bookings').href.includes('record_type=seminar')) throw new Error('Seminar CSV link is not scoped.');

    window.qaPending[1].resolve(window.qaResponse({success:true,data:[{record_type:'booking',id:77}],pagination:{current_page:1,total_pages:1,total_rows:1}}));
    await window.qaWait(() => document.getElementById('booking-results-status').textContent.includes('couldn’t load seminars'));
    if (document.querySelector('[data-record-type="booking"][data-ref]')) throw new Error('Opposite-type row was rendered in Seminar history.');

    document.querySelector('[data-table-action="retry"]').click();
    await window.qaWait(() => window.qaPending.length === 3);
    const retry = JSON.parse(window.qaPending[2].options.body);
    if (retry.record_type !== 'seminar' || retry.status !== 'all') throw new Error('Retry escaped the active seminar filters.');
    window.qaPending[2].resolve(window.qaResponse({success:true,data:[{record_type:'seminar',id:78,reference_no:'SEM-78',seminar_name:'Synthetic seminar',venue_name:'Event Hall',start_date:'2026-11-20',end_date:'2026-11-20',display_booking_status:'Draft',payment_status:'Unpaid',total_amount:100,amount_paid:0,attendee_count:2}],pagination:{current_page:1,total_pages:1,total_rows:1}}));
    await window.qaWait(() => !!document.querySelector('.seminar-history-row'));
    if (document.querySelector('[data-record-type="booking"][data-ref]')) throw new Error('Booking row leaked into Seminar history.');
    document.body.dataset.historyState = 'pass';
  } catch (error) {
    document.body.dataset.historyState = 'fail';
    document.body.dataset.historyError = String(error && error.message || error);
  }
});
</script></body></html>
HTML;

try {
    file_put_contents($fixtureDir . '/fixture.html', $html, LOCK_EX);
    $fixtureUrl = 'file://' . str_replace(' ', '%20', $fixtureDir . '/fixture.html');
    $command = [$chrome, '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--user-data-dir=' . $fixtureDir . '/chrome-profile', '--no-first-run', '--disable-background-networking', '--allow-file-access-from-files', '--virtual-time-budget=5000', '--dump-dom', $fixtureUrl];
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Chrome could not start.');
    fclose($pipes[0]); $dom = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    $passed = $code === 0 && str_contains((string)$dom, 'data-history-state="pass"');
    if (!$passed && preg_match('/data-history-error="([^"]*)"/', (string)$dom, $match)) fwrite(STDERR, 'DETAIL|' . htmlspecialchars_decode($match[1], ENT_QUOTES) . "\n");
    echo ($passed ? 'PASS|' : 'FAIL|') . "booking/seminar switching, stale response suppression, server-type validation, and scoped CSV state\n";
    exit($passed ? 0 : 1);
} finally {
    $remove = static function(string $path) use (&$remove): void {
        if (is_link($path) || is_file($path)) { unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
        rmdir($path);
    };
    $remove($fixtureDir);
}
