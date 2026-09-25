<?php
/** Static contracts for the admin operations dashboard refinement. */
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string)file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Admin dashboard refinement contract failed: {$message}\n");
        exit(1);
    }
};

$shell = $read('admin_dashboard.php');
$overview = $read('includes/admin-page/admin_overview.php');
$overviewJs = $read('assets/js/admin-page/admin_overview.js');
$notificationsJs = $read('assets/js/admin-page/admin_notifications.js');
$overviewCss = $read('assets/css/admin-page/admin_overview.css');

$navStart = strpos($shell, '<ul class="nav-list">');
$navEnd = $navStart === false ? false : strpos($shell, '</ul>', $navStart);
$nav = $navStart !== false && $navEnd !== false ? substr($shell, $navStart, $navEnd - $navStart) : '';
$operations = strpos($nav, '<li class="nav-group-label"><span>Operations</span></li>');
$business = strpos($nav, '<li class="nav-group-label"><span>Business</span></li>');
$system = strpos($nav, '<li class="nav-group-label"><span>System</span></li>');
$account = strpos($nav, '<li class="nav-group-label"><span>Account</span></li>');
$assert($operations !== false && $business !== false && $system !== false && $account !== false && $operations < $business && $business < $system && $system < $account, 'sidebar has requested role-specific navigation group order');
$assert(str_contains($nav, 'if ($is_admin_user):') && str_contains($nav, 'else:') && str_contains($nav, 'page=overview') && str_contains($nav, 'page=calendar') && str_contains($nav, 'page=bookings') && str_contains($nav, 'page=walkin') && str_contains($nav, 'page=maintenance') && str_contains($nav, 'page=sales') && str_contains($nav, 'page=reviews') && str_contains($nav, 'page=usermanagement') && str_contains($nav, 'page=auditlog') && str_contains($nav, 'page=cms') && str_contains($nav, 'page=settings'), 'admin Sales is available while the remaining role-specific routes stay available');
$assert(str_contains($nav, "\$page === 'overview' ? 'aria-current=\"page\"'") && str_contains($nav, "\$page === 'settings' ? 'aria-current=\"page\"'") && str_contains($shell, "\$is_admin_user ? 'System Settings' : 'Account &amp; Security'") && str_contains($shell, "\$is_admin_user ? 'Admin Dashboard' : 'Staff Dashboard'"), 'active links and role-sensitive dashboard titles are present');

$monthlyGate = strpos($overview, "<?php if ((\$_SESSION['role'] ?? '') === 'admin'): ?>");
$monthlyGateEnd = $monthlyGate === false ? false : strpos($overview, '<?php endif; ?>', $monthlyGate);
$monthlyBlock = $monthlyGate !== false && $monthlyGateEnd !== false ? substr($overview, $monthlyGate, $monthlyGateEnd - $monthlyGate) : '';
$assert($monthlyBlock !== '' && str_contains($monthlyBlock, 'Monthly Sales') && str_contains($monthlyBlock, 'id="stat-monthly-sales"') && str_contains($monthlyBlock, '<a href="admin_dashboard.php?page=sales"') && str_contains($monthlyBlock, 'class="stat-card overview-sales-card"'), 'Monthly Sales remains admin-only and links to the Sales report');
$needsAttention = strpos($overview, 'Needs Attention');
$quickGlance = strpos($overview, 'Today at a Glance');
$workspace = strpos($overview, '<div class="overview-workspace">');
$operationsStack = strpos($overview, 'class="overview-workspace-column overview-operations-column overview-support-grid"');
$itinerary = strpos($overview, 'class="widget-card overview-itinerary"');
$maintenance = strpos($overview, 'id="overview-maintenance-title"');
$events = strpos($overview, 'class="widget-card overview-events"');
$planningStack = strpos($overview, 'class="overview-workspace-column overview-planning-column"');
$schedule = strpos($overview, 'class="overview-module overview-mini-calendar"');
$pipeline = strpos($overview, 'class="overview-module overview-pipeline"');
$recentBookings = strpos($overview, 'class="table-card recent-bookings-card overview-recent-bookings"');
$assert(str_contains($shell, "if (\$page === 'overview') echo 'Operations Overview';") && !str_contains($overview, '<h2>Operations Overview</h2>') && !str_contains($overview, 'Command Center') && str_contains($overview, '<nav class="dashboard-header-bar" aria-label="Quick actions">') && $needsAttention !== false && $quickGlance !== false && $workspace !== false && $operationsStack !== false && $itinerary !== false && $maintenance !== false && $events !== false && $planningStack !== false && $schedule !== false && $pipeline !== false && $recentBookings !== false && $needsAttention < $quickGlance && $quickGlance < $workspace && $workspace < $operationsStack && $operationsStack < $itinerary && $itinerary < $maintenance && $maintenance < $pipeline && $pipeline < $events && $events < $planningStack && $planningStack < $schedule && $schedule < $recentBookings && !str_contains($overview, 'overview-itinerary-calendar') && !str_contains($overview, 'overview-ops-grid') && !str_contains($overview, 'overview-last-grid'), 'overview follows operations-column order through Maintenance, Pipeline, and Events, then planning-column order through Calendar and Recent Bookings');
$assert(str_contains($overview, 'href="admin_dashboard.php?page=bookings&amp;filter=action_req"') && !str_contains($overview, 'onclick="window.location.href') && str_contains($overview, 'id="maintenance-alerts-container"') && str_contains($overview, 'id="widget-today-list"') && str_contains($overview, 'id="overview-mini-calendar"') && str_contains($overview, 'id="overview-maintenance-summary"') && str_contains($overview, 'id="statusChart"') && str_contains($overview, 'id="widget-events-list"') && str_contains($overview, 'id="recent-bookings-tbody"'), 'existing dashboard controls, IDs, hrefs, charts, and table hooks are preserved');

$assert(str_contains($overview, 'id="dashboard-status-region"') && str_contains($overview, 'aria-live="polite"') && str_contains($overview, 'id="dashboard-retry"') && str_contains($overview, 'Loading…') && str_contains($overviewJs, 'Number.isFinite(numericValue)') && str_contains($overviewJs, '!hasSuccessfulDashboardData'), 'overview presents neutral loading, numeric zero, error, retry, and unavailable states');
$assert(str_contains($overviewJs, "alertElem.type = 'button'") && str_contains($overviewJs, "document.createElement('button')") && str_contains($overviewJs, 'overview-table-action') && !str_contains($overviewJs, "tr.addEventListener('click'"), 'dynamic maintenance alerts and detail rows use semantic keyboard-operable controls');
$assert(str_contains($overview, 'role="dialog"') && str_contains($overview, 'aria-modal="true"') && str_contains($overview, 'aria-labelledby="modal-today-title"') && str_contains($overview, 'aria-label="Close booking details"') && str_contains($overviewJs, "event.key === 'Escape'") && str_contains($overviewJs, 'getModalFocusableElements') && str_contains($overviewJs, 'opener.focus()'), 'modals have dialog names, close labels, Escape close, focus containment, and focus restoration');
$assert(str_contains($notificationsJs, 'response.ok') && str_contains($notificationsJs, 'data.error') && str_contains($notificationsJs, "new CustomEvent('SevillaDashboardError'") && str_contains($notificationsJs, "new CustomEvent('SevillaDashboardData'") && str_contains($notificationsJs, "'SevillaDashboardRefreshRequested'") && !str_contains($overviewJs, 'actions/admin/get_dashboard_stats.php'), 'notification poller owns and validates the single stats fetch and data/error/retry events');
$assert(str_contains($overviewCss, '.admin-layout.sidebar-collapsed .nav-group-label') && str_contains($overviewCss, '.nav-link:focus-visible') && str_contains($overviewCss, '.overview-workspace') && str_contains($overviewCss, '@media (max-width: 1100px)'), 'collapsed navigation, visible focus, and responsive dashboard workspace styles are present');
$assert(preg_match('/\.sidebar-nav\s*\{[^}]*min-height:\s*0;[^}]*overflow-y:\s*auto;[^}]*overscroll-behavior:\s*contain;[^}]*scrollbar-gutter:\s*stable;/s', $overviewCss) === 1, 'sidebar navigation scrolls within short viewports and contains scroll chaining');

$layoutOverrideStart = strpos($overviewCss, '/* Compact overview rhythm and let mismatched content keep its natural height. */');
$layoutOverride = $layoutOverrideStart === false ? '' : substr($overviewCss, $layoutOverrideStart);
$assert(str_contains($layoutOverride, '.dashboard-container > .overview-section { padding-block: 0; }') && str_contains($layoutOverride, ".overview-section,\n.overview-glance { margin-bottom: 20px; }"), 'top-level dashboard sections reset leaked public padding while retaining the compact section rhythm');
$assert($layoutOverride !== '' && str_contains($overview, 'class="overview-attention-items alerts-empty"') && str_contains($overview, 'id="maintenance-alerts-container"') && str_contains($overview, 'aria-relevant="additions text" hidden') && str_contains($overviewJs, 'function setMaintenanceAlertsState(state)') && str_contains($overviewJs, 'alerts.hidden = isEmpty') && str_contains($overviewJs, "layout.classList.toggle('alerts-empty', isEmpty)") && str_contains($overviewJs, "alertsContainer.childElementCount ? 'alerts' : 'empty'"), 'empty maintenance alerts start collapsed and dashboard renders toggle the alert-region state');
$assert(str_contains($overviewJs, "setMaintenanceAlertsState('error')") && str_contains($overviewJs, 'alerts.innerHTML = \'<p class="widget-placeholder-text">Maintenance alerts unavailable.</p>\'') && str_contains($layoutOverride, '.overview-attention-items.alerts-visible') && str_contains($layoutOverride, '.overview-attention-items.alerts-empty'), 'maintenance alert errors stay visible while an empty state lets Action Required use the row');
$assert(str_contains($overviewJs, 'maintenanceModule.hidden = !hasMaintenance') && str_contains($overviewJs, "classList.toggle('maintenance-empty', !hasMaintenance)") && str_contains($overviewJs, "setMaintenanceSummaryMessage('Loading maintenance…')") && str_contains($overviewJs, "setMaintenanceSummaryMessage('Maintenance data unavailable.')") && str_contains($overviewJs, 'if (calendar) calendar.innerHTML = \'<p class="widget-placeholder-text">Calendar data unavailable.</p>\'') && str_contains($layoutOverride, '.overview-support-grid .overview-maintenance[hidden]'), 'maintenance summary collapses only for a successful empty calendar and resets to visible loading or error content');
$assert(!str_contains($layoutOverride, 'align-items: stretch') && !str_contains($layoutOverride, 'min-height: 280px') && !str_contains($layoutOverride, 'min-height: 312px') && str_contains($layoutOverride, 'align-items: start') && str_contains($layoutOverride, '.overview-support-grid .overview-module { min-height: 0; }') && str_contains($layoutOverride, '.overview-support-grid .canvas-wrapper-compact { height: 205px; }') && str_contains($layoutOverride, '.overview-workspace {') && str_contains($layoutOverride, 'grid-template-columns: minmax(0, .84fr) minmax(0, 1.16fr);') && str_contains($layoutOverride, 'flex-direction: column') && str_contains($layoutOverride, '@media (max-width: 1100px)') && !str_contains($layoutOverride, '.overview-itinerary-calendar') && !str_contains($layoutOverride, '.overview-ops-grid.overview-support-grid') && !str_contains($layoutOverride, '.overview-last-grid'), 'final layout uses independent natural-height stacks and removes stale row-grid overrides while retaining chart height and responsive collapse');

echo "Admin dashboard refinement contract checks passed\n";
