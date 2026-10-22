<?php
declare(strict_types=1);

$chromeCandidates = [getenv('CHROME_BIN') ?: null, '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'];
$chrome = null;
foreach ($chromeCandidates as $candidate) {
    if ($candidate && is_file($candidate) && is_executable($candidate)) { $chrome = $candidate; break; }
}
if ($chrome === null) {
    fwrite(STDOUT, "SKIP|Chrome is unavailable for the overview booking modal check.\n");
    exit(0);
}

$root = dirname(__DIR__);
$overviewSource = file_get_contents($root . '/includes/admin-page/admin_overview.php');
if (!is_string($overviewSource)) throw new RuntimeException('Unable to read overview markup.');
$tableStart = strpos($overviewSource, '<section class="table-card recent-bookings-card overview-recent-bookings"');
$tableEnd = $tableStart === false ? false : strpos($overviewSource, '</section>', $tableStart);
$modalStart = strpos($overviewSource, '<!-- Overview Modals Overlay -->');
if ($tableStart === false || $tableEnd === false || $modalStart === false) throw new RuntimeException('Actual overview table or modal markup is missing.');
$tableMarkup = substr($overviewSource, $tableStart, $tableEnd + strlen('</section>') - $tableStart);
$modalMarkup = substr($overviewSource, $modalStart);
$baseCss = file_get_contents($root . '/assets/css/style.css');
if (!is_string($baseCss)) throw new RuntimeException('Unable to read base stylesheet.');
// Keep the real base rules while avoiding the remote Google Fonts import in this offline fixture.
$baseCss = preg_replace('/^\s*@import[^;]*;\s*/m', '', $baseCss, 1) ?? $baseCss;
$stylePaths = [
    realpath($root . '/assets/css/admin-page/admin_overview.css'),
    realpath($root . '/assets/css/admin-page/admin_bookings.css'),
];
$scriptPath = realpath($root . '/assets/js/admin-page/admin_overview.js');
$runner = realpath($root . '/scripts/test_admin_modal_mobile_geometry_runner.js');
if (in_array(false, $stylePaths, true) || !$scriptPath || !$runner) throw new RuntimeException('Overview UI assets or browser runner are missing.');
$styleLinks = '<style>' . $baseCss . "</style>\n" . implode("\n", array_map(static fn(string $path): string => '<link rel="stylesheet" href="file://' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '">', $stylePaths));
$booking = [
    'id' => 321,
    'reference_no' => 'QA-320',
    'first_name' => 'Synthetic',
    'last_name' => 'Guest',
    'email' => 'synthetic@example.test',
    'phone' => 'N/A',
    'booking_status' => 'Confirmed',
    'display_booking_status' => 'Confirmed',
    'payment_status' => 'Paid',
    'start_date' => '2099-01-01',
    'end_date' => '2099-01-02',
    'guests_count' => 4,
    'venue_name' => 'Synthetic Villa',
    'venue_category' => 'Resort Villa',
    'total_amount' => 5000,
    'base_amount' => 5000,
    'addons_amount' => 0,
    'extra_pax_amount' => 0,
    'amount_paid' => 5000,
    'payment_scheme' => 'full_payment',
];
$bookingJson = json_encode($booking, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$longCopyJson = json_encode(str_repeat('Synthetic booking history supports independent scrolling. ', 8), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$html = <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
{$styleLinks}
</head><body>
<main style="min-height:100vh;padding:16px"><div style="height:120px">Overview fixture context</div>{$tableMarkup}</main>
{$modalMarkup}
<script>
window.addEventListener('error', event => { window.__overviewError = event.message || 'script error'; });
window.addEventListener('unhandledrejection', event => { window.__overviewError = String(event.reason || 'unhandled rejection'); });
window.Chart = { defaults: { font: {}, plugins: { legend: { labels: {} } } }, getChart: () => null };
window.__detailFetchCount = 0;
window.fetch = async url => {
  if (String(url).includes('get_booking_details.php')) {
    window.__detailFetchCount++;
    return { ok: true, json: async () => ({ success: true, data: { booking: {$bookingJson}, specifics: null, addons: [], room_allocations: [], payments: [], proof_history: [], cancellation: null } }) };
  }
  return { ok: true, json: async () => [] };
};
</script>
<script src="file://{$scriptPath}"></script>
<script>
document.addEventListener('DOMContentLoaded', () => setTimeout(() => {
  const body = document.getElementById('overviewBookingBodyScroll');
  for (let index = 0; index < 24; index++) {
    const paragraph = document.createElement('p');
    paragraph.textContent = {$longCopyJson};
    body.appendChild(paragraph);
  }
  window.dispatchEvent(new CustomEvent('SevillaDashboardData', { detail: {
    userRole: 'admin', actionRequired: 0, arrivalsToday: 0, occupancyRate: 0, monthlySales: 0,
    charts: { status: [] }, todaysOperations: [], upcomingEvents: [], recentBookings: [{$bookingJson}]
  }}));
  window.__overviewReady = true;
}, 0));
</script>
</body></html>
HTML;

$fixtureDir = sys_get_temp_dir() . '/sevilla360-overview-booking-' . bin2hex(random_bytes(8));
if (!mkdir($fixtureDir, 0700)) throw new RuntimeException('Unable to create isolated browser fixture.');
try {
    file_put_contents($fixtureDir . '/fixture.html', $html, LOCK_EX);
    $fixtureUrl = 'file://' . str_replace(' ', '%20', $fixtureDir . '/fixture.html');
    foreach ([320, 390, 1280] as $width) {
        $command = ['node', $runner, $chrome, $fixtureUrl, (string)$width, '800', $fixtureDir . '/profile-' . $width, 'overview'];
        $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Chrome could not start.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        $result = json_decode(trim((string)$output), true);
        $passed = $code === 0 && is_array($result) && ($result['state'] ?? '') === 'pass' && ($result['width'] ?? null) === $width;
        if (!$passed) fwrite(STDERR, 'Overview browser diagnostics: ' . trim((string)$stderr) . ' ' . trim((string)$output) . "\n");
        echo ($passed ? 'PASS ' : 'FAIL ') . $width . 'px overview booking detail and Recent Bookings interactions' . ($result ? ': ' . json_encode($result) : '') . "\n";
        if (!$passed) exit(1);
    }
} finally {
    $remove = static function(string $path) use (&$remove): void {
        if (is_link($path) || is_file($path)) { unlink($path); return; }
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
        rmdir($path);
    };
    $remove($fixtureDir);
}
