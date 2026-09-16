<?php
/** Focused static contracts for the adviser issue phases. */
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    echo ($condition ? 'PASS' : 'FAIL') . "|{$message}\n";
    if (!$condition) $GLOBALS['failures']++;
};
$failures = 0;

$showroom = $read('assets/js/showroom.js');
$showroomPhp = $read('showroom.php');
$showroomCss = $read('assets/css/showroom.css');
$adminHotspots = $read('assets/js/admin-page/admin_hotspots.js');
$adminCms = $read('includes/admin-page/admin_cms.php');
$adminCmsCss = $read('assets/css/admin-page/admin_cms.css');
$adminDashboard = $read('admin_dashboard.php');
$notifications = $read('includes/admin_notifications.php');
$markRead = $read('actions/admin/mark_notification_read.php');
$customerMarkRead = $read('actions/user/mark_notifications_read.php');
$adminBookings = $read('assets/js/admin-page/admin_bookings.js');
$header = $read('includes/header.php');
$dashboardJs = $read('assets/js/admin-page/admin_notifications.js');
$guide = $read('assets/js/guide_tours.js');
$uiRefinement = $read('assets/css/ui-refinement.css');
$hotspotMaterial = $read('assets/js/hotspot-material.js');
$paymentSubmit = $read('actions/user/submit_manual_payment.php');
$overview = $read('includes/admin-page/admin_overview.php');
$style = $read('assets/css/style.css');
$hotspotNavSvg = $read('assets/img/hotspot-nav-v3.svg');

$assert(str_contains($showroom, 'categoryCapacityMax') && str_contains($showroom, 'data-receptionist-group-input')
    && str_contains($showroom, 'data-receptionist-group-submit') && str_contains($showroom, '/^\\d+$/')
    && str_contains($showroom, 'Number.isSafeInteger(count)') && str_contains($showroom, 'count > maxCapacity'), 'receptionist accepts exact positive integer counts with dynamic category max validation');
$assert(str_contains($showroom, 'guideContext.groupSize = count') && str_contains($showroom, 'rankVenues(category, guideContext.groupSize')
    && str_contains($showroom, 'selectVenues(category, guideContext.groupSize'), 'exact guest count is stored and passed to ranking and recommendations');
$assert(str_contains($showroomCss, '.receptionist-guest-count-form') && str_contains($showroomCss, 'aria-invalid'), 'exact count control has inline recovery and visible focus styling');

$pngIsRgba512 = static function (string $relative) use ($root): bool {
    $bytes = @file_get_contents($root . '/' . $relative, false, null, 0, 26);
    if (!is_string($bytes) || strlen($bytes) < 26 || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") return false;
    $size = unpack('Nwidth/Nheight', substr($bytes, 16, 8));
    return $size === ['width' => 512, 'height' => 512] && ord($bytes[25]) === 6;
};
$assert($pngIsRgba512('assets/img/hotspot-info-v3.png') && $pngIsRgba512('assets/img/hotspot-nav-v3.png'), 'hotspot info and navigation assets are matching 512px RGBA v3 PNGs');
$assert(str_contains($hotspotMaterial, 'transparent = true') && str_contains($hotspotMaterial, 'alphaTest')
    && str_contains($hotspotMaterial, 'depthWrite = false') && str_contains($hotspotMaterial, 'depthTest = false') && !str_contains($hotspotMaterial, 'depthTest = true') && str_contains($hotspotMaterial, 'size = 350')
    && str_contains($hotspotMaterial, 'preload') && str_contains($hotspotMaterial, 'onError')
    && str_contains($hotspotMaterial, 'createFallbackTexture') && str_contains($hotspotMaterial, 'CanvasTexture') && str_contains($hotspotMaterial, 'DataTexture')
    && str_contains($hotspotMaterial, 'hotspotFallback = fallback') && str_contains($hotspotMaterial, 'hotspotRotationDegrees')
    && str_contains($hotspotMaterial, 'configure(spot, type, degrees)') && str_contains($hotspotMaterial, 'const textureCache = new Map()')
    && str_contains($hotspotMaterial, "const asset = assets[type === 'nav' ? 'nav' : 'info'];")
    && str_contains($hotspotMaterial, 'new global.PANOLENS.Infospot(size, asset)')
    && !str_contains($hotspotMaterial, 'new global.PANOLENS.Infospot(size, null)')
    && !str_contains($hotspotMaterial, 'new global.PANOLENS.Infospot(size)')
    && str_contains($hotspotMaterial, 'hotspotTextureRequest')
    && str_contains($hotspotMaterial, 'hotspot-info-v3.png') && str_contains($hotspotMaterial, 'hotspot-nav-v3.png')
    && str_contains($hotspotMaterial, '#17372E') && str_contains($hotspotMaterial, '#D8B277') && str_contains($hotspotMaterial, '#FFF8E8')
    && str_contains($hotspotMaterial, 'context.moveTo(0, -30)') && str_contains($hotspotMaterial, 'localY >= -10.5')
    && str_contains($hotspotNavSvg, 'M256 98L383 225')
    && !str_contains($hotspotMaterial, 'hotspot-info-v2.png') && !str_contains($hotspotMaterial, 'hotspot-nav-v2.png')
    && !str_contains($hotspotMaterial, 'material.rotation * 180 / Math.PI'), 'hotspot material setup centralizes matching v3 colors, transparency, sizing, preload, fallback, rotation, and same-asset Panolens loading');
$assert(str_contains($adminHotspots, 'SevillaHotspotMaterial') && str_contains($showroom, 'SevillaHotspotMaterial')
    && str_contains($adminHotspots, 'depthTest = false') && str_contains($showroom, 'depthTest = false')
    && !str_contains($adminHotspots, 'depthTest = true') && !str_contains($showroom, 'depthTest = true'), 'admin and public hotspot paths use the shared material helper and preserve depthTest=false');
$assert(substr_count($adminHotspots, 'autoHideInfospot: false') === 1
    && substr_count($showroom, 'autoHideInfospot: false') === 1, 'admin and public Panolens viewers keep hotspot markers visible after blank clicks');
$assert(str_contains($hotspotMaterial, 'assignFallbackTexture(spot, type, storedDegrees(spot));')
    && str_contains($hotspotMaterial, 'spot.visible = true') && str_contains($hotspotMaterial, 'material.visible = true')
    && str_contains($hotspotMaterial, 'material.opacity = 1') && str_contains($hotspotMaterial, 'spot.frustumCulled = false')
    && str_contains($hotspotMaterial, 'spot.renderOrder = 10') && str_contains($hotspotMaterial, 'function dispose(spot)')
    && str_contains($hotspotMaterial, 'hotspotDisposed') && str_contains($hotspotMaterial, 'isCurrentRequest'), 'hotspot sprites receive synchronous fallback, explicit visibility invariants, render safety, and stale-dispose guards');
$assert(str_contains($adminHotspots, 'function takeSavedHotspotSpot(id)')
    && str_contains($adminHotspots, 'const savedSpot = takeSavedHotspotSpot(hotspot.id)')
    && str_contains($adminHotspots, 'pendingSpot = savedSpot || createHotspotSpot')
    && str_contains($adminHotspots, 'if (pendingSpot.parent !== currentPanoMesh) currentPanoMesh.add(pendingSpot)')
    && str_contains($adminHotspots, 'const wasEditingSavedSpot = Boolean(pendingSpot?.userData?.hotspotId)')
    && str_contains($adminHotspots, 'if (wasEditingSavedSpot) await loadExistingHotspots(currentMediaId)')
    && !str_contains($adminHotspots, 'removeSavedHotspotSpot(hotspot.id)'), 'editing reuses the live saved sprite and preserves other tracked markers without a remove-create gap');

$migration = $read('migrations/027_admin_notification_reads.sql');
$assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS admin_notification_reads') && str_contains($migration, 'PRIMARY KEY (user_id, notification_key)')
    && str_contains($migration, 'FOREIGN KEY (user_id)') && str_contains($migration, 'idx_admin_notification_reads_key'), 'admin notification read migration is idempotent and account-scoped');
$assert(str_contains($notifications, "'key'") && str_contains($notifications, "'kind'") && str_contains($notifications, "'target_url'")
    && str_contains($notifications, 'proof_sha256') && str_contains($notifications, 'UNIX_TIMESTAMP(m.submitted_at)') && str_contains($notifications, 'admin_notification_reads'), 'admin notifications expose normalized action fields and proof revision keys');
$assert(str_contains($notifications, 'NULL, cx.created_at') && str_contains($notifications, 'NULL, rr.created_at'), 'cancellation and reschedule actions use their own request timestamps');
$assert(str_contains($markRead, '$_SERVER[\'REQUEST_METHOD\']') && str_contains($markRead, 'POST') && str_contains($markRead, 'hash_equals')
    && str_contains($markRead, 'admin_notification_key_is_active') && str_contains($markRead, 'ON DUPLICATE KEY UPDATE'), 'mark-read endpoint is POST-only, CSRF-protected, active-key validated, and idempotent');
$assert(str_contains($dashboardJs, 'Needs action') && str_contains($dashboardJs, 'mark_notification_read.php') && str_contains($dashboardJs, 'read-error')
    && str_contains($header, 'payment-proof') === false && str_contains($header, 'data-admin-notification-key') && str_contains($header, 'Could not save read state'), 'admin action list stays unresolved while per-account read failures recover visibly');
$assert(str_contains($adminBookings, 'open_payment_proof') && str_contains($adminBookings, 'open-manual-proof')
    && str_contains($adminBookings, 'payment.proof_submitted') && str_contains($adminBookings, 'payment.received')
    && str_contains($adminBookings, 'booking.updated') && str_contains($adminBookings, 'booking.expired'), 'payment proof notifications deep-link and refresh admin bookings on lifecycle events');
$assert(str_contains($header, 'response.ok') && str_contains($header, '!res.success') && str_contains($header, 's-notif-read-state'), 'customer notification UI mutates only after successful JSON writes and updates read state');
$assert(str_contains($header, 'wasUnread') && str_contains($header, 'if (!wasUnread)') && str_contains($header, 'return;'), 'already-read customer notification clicks show the message without posting or decrementing again');
$assert(str_contains($customerMarkRead, 'array_key_exists(\'id\', $_POST)') && str_contains($customerMarkRead, 'http_response_code(422)')
    && str_contains($customerMarkRead, '!is_string($raw_notif_id)') && str_contains($customerMarkRead, 'FILTER_VALIDATE_INT')
    && str_contains($customerMarkRead, '$_SESSION[\'role\'] ?? null') && str_contains($customerMarkRead, 'is_string($session_csrf_token)'), 'supplied invalid notification ids cannot fall through to mark-all and auth/CSRF inputs are null-safe');
$assert(str_contains($paymentSubmit, "payment.proof_submitted") && !preg_match('/\b(?:mail|send_[a-z_]+|PHPMailer|mailer)\s*\(/i', $paymentSubmit), 'payment proof submission emits in-app/realtime state without outbound email');

$assert(str_contains($adminCms, 'data-hotspot-step="1"') && str_contains($adminCms, 'data-hotspot-step="2"') && str_contains($adminCms, 'data-hotspot-step="3"')
    && str_contains($adminCms, 'Save current guest view') && str_contains($adminCms, 'hotspot-more-menu'), 'tour setup is a functional three-step workflow with progressive disclosure');
$assert(substr_count($adminCms, 'class="hotspot-step-number"') === 3
    && str_contains($adminCms, 'View options') && !str_contains($adminCms, '<summary>More</summary>')
    && str_contains($adminCms, 'fa-circle-question') && str_contains($adminCms, 'fa-xmark'), 'tour workflow exposes sequence numbering, named view options, and compact icon-assisted modal actions');
$assert(str_contains($adminCmsCss, 'grid-template-columns: minmax(0, 1.35fr)')
    && str_contains($adminCmsCss, '.hotspot-step + .hotspot-step { border-inline-start')
    && str_contains($adminCmsCss, '.hotspot-more-menu[open] > .hotspot-more-actions')
    && str_contains($adminCmsCss, 'summary::-webkit-details-marker')
    && str_contains($adminCmsCss, '@media (max-width: 900px)')
    && str_contains($adminCmsCss, 'position: static;'), 'tour workflow uses a connected hierarchy, anchored desktop disclosure, and in-flow compact responsive disclosure');
$assert(str_contains($adminCms, 'id="hs-form-state"') && str_contains($adminHotspots, "const formState = document.getElementById('hs-form-state')")
    && str_contains($adminCmsCss, 'height: min(82vh, 790px)')
    && str_contains($adminCmsCss, ".hotspot-form-actions {\n  position: sticky;")
    && str_contains($adminCmsCss, 'max-height: 62%')
    && str_contains($adminCmsCss, 'scrollbar-gutter: stable'), 'editor workspace prioritizes the panorama with compact form state and always-reachable sidebar actions');
$assert(str_contains($adminHotspots, 'function setHotspotFormVisible(visible, editing = false)')
    && str_contains($adminHotspots, "hotspotSidebar.classList.toggle('is-form-active', visible)")
    && str_contains($adminHotspots, "hotspotSidebar.classList.toggle('is-editing', visible && editing)")
    && str_contains($adminHotspots, "hotspotListSection.setAttribute('aria-hidden', visible ? 'true' : 'false')")
    && str_contains($adminHotspots, 'formWrapper.scrollTop = 0')
    && !str_contains($adminHotspots, "formWrapper.classList.add('hidden')")
    && !str_contains($adminHotspots, "formWrapper.classList.remove('hidden')"), 'form visibility uses one explicit sidebar state and resets internal scroll on open');
$assert(str_contains($adminCmsCss, '.hotspot-sidebar.is-form-active #hotspot-form-wrapper:not(.hidden)')
    && str_contains($adminCmsCss, '.hotspot-sidebar.is-form-active .hotspot-list-section { display: none; }')
    && str_contains($adminCmsCss, 'max-height: none;')
    && str_contains($adminCmsCss, '@media (max-width: 900px)'), 'active hotspot forms own desktop sidebar height while mobile returns to normal flow');
$assert(str_contains($guide, 'localStorage') && str_contains($guide, 'Skip') && str_contains($guide, 'Escape') && str_contains($guide, 'focus')
    && str_contains($guide, 'prefers-reduced-motion') && str_contains($showroom, 'sevilla360-showroom-tour-v1')
    && str_contains($adminHotspots, 'sevilla360-admin-tour-v1-'), 'versioned guest/admin tutorials support skip, replay, keyboard, focus, and reduced motion');
$assert(str_contains($guide, 'sevilla-guide-target-ring') && str_contains($guide, 'highlightRefresh')
    && str_contains($uiRefinement, '.sevilla-guide-target-ring') && str_contains($uiRefinement, 'z-index: 1')
    && !str_contains($uiRefinement, '[data-guide-target="true"] { position: relative; z-index: 100001'), 'guide highlights stay in the modal stacking context without lifting the target container above the panel');
$assert(str_contains($showroomPhp, 'btn-showroom-help') && str_contains($adminCms, 'btn-hotspot-help') && str_contains($adminCmsCss, '@media'), 'tutorial help controls and responsive styling are wired');

$assert(!str_contains($adminDashboard, "'sales'") && !str_contains($adminDashboard, 'admin_sales')
    && str_contains($overview, '<div class="stat-card">') && !str_contains($overview, 'page=sales')
    && !is_file($root . '/includes/admin-page/admin_sales.php') && !is_file($root . '/assets/js/admin-page/admin_sales.js')
    && !is_file($root . '/assets/css/admin-page/admin_sales.css') && !is_file($root . '/actions/admin/get_sales_report.php'), 'standalone Sales feature is removed while Overview Monthly Sales remains static');
$assert(str_contains($style, '.notif-section-label') && str_contains($style, '.notif-item.is-read'), 'notification read and Needs action states have visual affordances');

exit($failures === 0 ? 0 : 1);
