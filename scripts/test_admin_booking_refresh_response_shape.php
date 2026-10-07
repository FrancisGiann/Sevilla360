<?php
declare(strict_types=1);

/** Focused runtime regression: malformed JSON-success payloads must not clear mounted history. */
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
    fwrite(STDOUT, "SKIP|Chrome is unavailable for the admin response-shape runtime check.\n");
    exit(0);
}

$fixtureDir = sys_get_temp_dir() . '/sevilla360-admin-refresh-shape-' . bin2hex(random_bytes(8));
if (!mkdir($fixtureDir, 0700)) {
    fwrite(STDERR, "Unable to create isolated browser fixture.\n");
    exit(1);
}

$adminJs = realpath(dirname(__DIR__) . '/assets/js/admin-page/admin_bookings.js');
$scriptUrl = $adminJs === false ? '' : 'file://' . str_replace(' ', '%20', $adminJs);
$html = <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><meta name="csrf-token" content="synthetic"></head><body>
<input id="table-search"><select id="table-venue-filter"><option value="All">All</option></select>
<button id="btn-reset-booking-filters"></button>
<div id="bookingFilters"><button class="tab-btn active" data-filter="all" aria-pressed="true">All</button></div>
<select id="bookingFilterSelect"><option value="" selected>Select</option></select>
<p id="booking-results-status"></p><table><tbody id="admin-bookings-tbody"></tbody></table>
<span id="pag-current-page"></span><span id="pag-total-pages"></span><span id="pag-total-rows"></span>
<button id="btn-prev-page"></button><button id="btn-next-page"></button>
<a id="btn-export-bookings"></a><button id="btn-refresh-bookings"></button><div id="modalOverlay"></div>
<script>
const urlBookingId = null;
const urlSearch = null;
const qaRow = {record_type:'seminar',id:902,reference_no:'SEM-QA-902',venue_name:'Event Hall',seminar_name:'Synthetic seminar',start_date:'2026-11-20',end_date:'2026-11-20',display_booking_status:'Draft',payment_status:'Unpaid',total_amount:0,amount_paid:0,attendee_count:1};
window.qaResponses = [
  {success:true,data:[qaRow],pagination:{current_page:1,total_pages:2,total_rows:16}},
  {success:true,data:null,pagination:{current_page:1,total_pages:2,total_rows:16}},
  {success:true,data:[null],pagination:{current_page:1,total_pages:2,total_rows:16}},
  {success:true,data:[{}],pagination:{current_page:1,total_pages:2,total_rows:16}},
  {success:true,data:[qaRow],pagination:{current_page:3,total_pages:2,total_rows:16}}
];
window.qaFetchCount = 0;
window.qaConsumedResponseCount = 0;
window.fetch = async () => {
  window.qaFetchCount++;
  const payload = window.qaResponses.shift() || window.qaResponses.at(-1);
  const response = new Response(JSON.stringify(payload), {status:200, headers:{'Content-Type':'application/json'}});
  const readJson = response.json.bind(response);
  response.json = async () => {
    window.qaConsumedResponseCount++;
    return readJson();
  };
  return response;
};
</script>
<script src="{$scriptUrl}"></script>
<script>
(() => {
  const cases = ['data:null', 'null row', 'missing row fields', 'out-of-range pagination'];
  let index = 0;
  const fail = () => { document.body.dataset.refreshShape = 'fail'; };
  const runNext = () => {
    if (index >= cases.length) {
      const expectedCount = cases.length + 1;
      document.body.dataset.refreshShape = window.qaFetchCount === expectedCount
        && window.qaConsumedResponseCount === expectedCount ? 'pass' : 'fail';
      return;
    }
    const row = document.querySelector('.seminar-history-row');
    if (!row) { window.setTimeout(runNext, 10); return; }
    const pagination = ['pag-current-page', 'pag-total-pages', 'pag-total-rows']
      .map(id => document.getElementById(id).textContent).join('|');
    window.dispatchEvent(new Event('focus'));
    window.setTimeout(() => {
      const currentPagination = ['pag-current-page', 'pag-total-pages', 'pag-total-rows']
        .map(id => document.getElementById(id).textContent).join('|');
      if (window.qaFetchCount !== index + 2 || window.qaConsumedResponseCount !== index + 2
        || row !== document.querySelector('.seminar-history-row')
        || pagination !== currentPagination) {
        fail();
        return;
      }
      index++;
      window.setTimeout(runNext, 10);
    }, 60);
  };
  window.setTimeout(runNext, 30);
})();
</script>
</body></html>
HTML;

$failed = 0;
try {
    if ($scriptUrl === '') throw new RuntimeException('Admin booking script is missing.');
    file_put_contents($fixtureDir . '/fixture.html', $html, LOCK_EX);
    $fixtureUrl = 'file://' . str_replace(' ', '%20', $fixtureDir . '/fixture.html');
    $command = [
        $chrome, '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
        '--user-data-dir=' . $fixtureDir . '/chrome-profile', '--no-first-run', '--disable-background-networking',
        '--disable-sync', '--metrics-recording-only', '--disable-default-apps', '--disable-component-update',
        '--allow-file-access-from-files', '--virtual-time-budget=1200', '--dump-dom', $fixtureUrl,
    ];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Chrome could not start.');
    fclose($pipes[0]);
    $dom = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $passed = $exitCode === 0 && str_contains((string)$dom, 'data-refresh-shape="pass"');
    echo ($passed ? 'PASS|' : 'FAIL|') . 'actual admin script preserves row node and pagination for malformed JSON-success payloads' . "\n";
    if (!$passed) $failed++;
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL|Admin response-shape runtime check could not complete: ' . get_class($error) . "\n");
    $failed++;
} finally {
    $removeOwnedTree = static function (string $path) use (&$removeOwnedTree): void {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') $removeOwnedTree($path . '/' . $name);
        }
        rmdir($path);
    };
    $removeOwnedTree($fixtureDir);
}

exit($failed === 0 ? 0 : 1);
