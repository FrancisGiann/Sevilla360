<?php
declare(strict_types=1);

$chromeCandidates = [getenv('CHROME_BIN') ?: null, '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'];
$chrome = null;
foreach ($chromeCandidates as $candidate) {
    if ($candidate && is_file($candidate) && is_executable($candidate)) { $chrome = $candidate; break; }
}
if ($chrome === null) {
    fwrite(STDOUT, "SKIP|Chrome is unavailable for the admin mobile geometry check.\n");
    exit(0);
}
$root = dirname(__DIR__);
$bookingsCss = realpath($root . '/assets/css/admin-page/admin_bookings.css');
$cmsCss = realpath($root . '/assets/css/admin-page/admin_cms.css');
if (!$bookingsCss || !$cmsCss) throw new RuntimeException('Admin style sheets are missing.');
$fixtureDir = sys_get_temp_dir() . '/sevilla360-admin-modal-geometry-' . bin2hex(random_bytes(8));
if (!mkdir($fixtureDir, 0700)) throw new RuntimeException('Unable to create isolated browser fixture.');
$longCopy = str_repeat('Synthetic booking history content for independent scrolling. ', 16);
$html = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="file://{$bookingsCss}"><link rel="stylesheet" href="file://{$cmsCss}">
<style>body{margin:0;overflow:hidden}</style></head><body>
<div class="modal-overlay active booking-detail-open" id="modalOverlay">
  <section class="admin-modal modal-view-details active" id="viewDetailsModal" role="dialog" aria-modal="true">
    <div class="vd-header"><div class="booking-detail-header-content"><h2 class="vd-title" id="vd-title">Booking QA-320</h2><span class="status-badge status-paid" id="vd-status-badge"><span class="vd-status-primary">Confirmed</span><span class="vd-status-note">— Rescheduled</span></span></div><button class="booking-detail-close" aria-label="Close details">×</button></div>
    <div class="vd-content-scroll" id="vd-content-scroll">
      <div class="booking-detail-section">Customer information. Synthetic content keeps this body taller than the viewport so the internal scroll area can be checked.</div>
      <div class="booking-detail-section">Booking dates and payment history. The header and close button should remain stationary while this independent section scrolls.</div>
      <div class="booking-detail-section">{$longCopy}</div><div class="booking-detail-section">{$longCopy}</div><div class="booking-detail-section">{$longCopy}</div><div class="booking-detail-section">{$longCopy}</div>
    </div>
  </section>
</div>
<div class="cms-upload-preview"><div class="cms-upload-preview-thumbnail"><img id="upload-preview-image" alt="Panorama preview" src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs="></div><div class="cms-upload-preview-details"><span class="cms-upload-preview-filename">synthetic-panorama.webp</span></div></div>
<script>
(() => {
  const modal=document.getElementById('viewDetailsModal');
  const header=modal.querySelector('.vd-header');
  const close=modal.querySelector('.booking-detail-close');
  const body=document.getElementById('vd-content-scroll');
  const badge=document.getElementById('vd-status-badge');
  const primary=badge.querySelector('.vd-status-primary');
  const note=badge.querySelector('.vd-status-note');
  const before=header.getBoundingClientRect().top;
  body.scrollTop=body.scrollHeight;
  const after=header.getBoundingClientRect().top;
  const values={
    viewport:window.innerWidth, scrollWidth:document.documentElement.scrollWidth,
    modalLeft:modal.getBoundingClientRect().left, modalRight:modal.getBoundingClientRect().right,
    headerTopBefore:before, headerTopAfter:after,
    closeWidth:close.getBoundingClientRect().width, closeHeight:close.getBoundingClientRect().height,
    bodyOverflow:getComputedStyle(body).overflowY, bodyCanScroll:body.scrollHeight>body.clientHeight && body.scrollTop>0,
    primaryWhiteSpace:getComputedStyle(primary).whiteSpace, primaryWordBreak:getComputedStyle(primary).wordBreak,
    noteWhiteSpace:getComputedStyle(note).whiteSpace, noteWordBreak:getComputedStyle(note).wordBreak,
    badgeWidth:badge.getBoundingClientRect().width, badgeBackground:getComputedStyle(badge).backgroundColor,
    previewObjectFit:getComputedStyle(document.getElementById('upload-preview-image')).objectFit,
    previewContained:document.getElementById('upload-preview-image').getBoundingClientRect().width<=document.querySelector('.cms-upload-preview-thumbnail').getBoundingClientRect().width+1
  };
  const mobile=window.innerWidth<=768;
  const passed=values.scrollWidth<=values.viewport+1 && values.modalLeft>=-1 && values.modalRight<=values.viewport+1
    && values.closeWidth>=44 && values.closeHeight>=44 && (!mobile || (Math.abs(before-after)<=1 && values.bodyOverflow==='auto' && values.bodyCanScroll))
    && values.primaryWhiteSpace==='nowrap' && values.primaryWordBreak==='normal'
    && values.noteWhiteSpace==='normal' && values.noteWordBreak==='normal'
    && values.badgeBackground==='rgb(228, 238, 229)' && values.previewObjectFit==='contain' && values.previewContained;
  document.body.dataset.geometry=passed?'pass':'fail';
  document.body.dataset.metrics=JSON.stringify(values);
})();
</script></body></html>
HTML;
try {
    file_put_contents($fixtureDir . '/fixture.html', $html, LOCK_EX);
    $fixtureUrl = 'file://' . str_replace(' ', '%20', $fixtureDir . '/fixture.html');
    $runner = realpath($root . '/scripts/test_admin_modal_mobile_geometry_runner.js');
    if (!$runner) throw new RuntimeException('Chrome geometry runner is missing.');
    foreach ([320, 390, 1280] as $width) {
        $command = ['node', $runner, $chrome, $fixtureUrl, (string)$width, '800', $fixtureDir . '/profile-' . $width];
        $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Chrome could not start.');
        fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        $result = json_decode(trim((string)$output), true);
        $metrics = is_array($result) ? ($result['metrics'] ?? null) : null;
        $passed = $code === 0 && is_array($metrics) && ($result['state'] ?? '') === 'pass' && $metrics['viewport'] === $width;
        if (!$metrics || !$passed) fwrite(STDERR, 'Chrome geometry diagnostics: ' . trim((string)$stderr) . ' ' . trim((string)$output) . "\n");
        echo ($passed ? 'PASS ' : 'FAIL ') . "$width px admin modal geometry" . ($metrics ? ': ' . json_encode($metrics) : '') . "\n";
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
