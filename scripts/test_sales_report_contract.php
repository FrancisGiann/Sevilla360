<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/sales_report.php';
require_once __DIR__ . '/../includes/manual_payment.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Sales report contract failed: {$message}\n");
        exit(1);
    }
};
$same = static function ($expected, $actual, string $message) use ($assert): void {
    $assert($expected === $actual, $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
};

$fixedNow = new DateTimeImmutable('2026-09-23 14:30:00', new DateTimeZone('Asia/Manila'));
$same(['from' => '2026-09-01', 'to' => '2026-09-23', 'method' => '', 'venue_id' => 0], array_intersect_key(sales_report_default_filters($fixedNow), array_flip(['from', 'to', 'method', 'venue_id'])), 'default report range uses Manila month to date');
$same(17, sales_report_normalize_filters(['room_id' => '17'], $fixedNow)['venue_id'], 'legacy room_id URLs normalize to the venue group filter');
$same(23, sales_report_normalize_filters(['venue_id' => '23', 'room_id' => '17'], $fixedNow)['venue_id'], 'canonical venue_id takes precedence over the legacy alias');
$same('', sales_report_normalize_filters(['method' => ' Unknown '], $fixedNow)['method'], 'legacy Unknown method filters normalize to all methods');
$same('2026-02-01', sales_report_normalize_filters([], new DateTimeImmutable('2026-02-28', new DateTimeZone('Asia/Manila')))['from'], 'missing filters use the month start');
$invalidDate = sales_report_normalize_filters(['from' => '2026-02-30', 'to' => '2026-03-01'], $fixedNow);
$same('2026-09-01', $invalidDate['from'], 'invalid calendar dates fall back to the safe range');
$assert($invalidDate['error'] !== '', 'invalid calendar dates produce a visible validation message');
$reversedDate = sales_report_normalize_filters(['from' => '2026-09-24', 'to' => '2026-09-23'], $fixedNow);
$same('2026-09-23', $reversedDate['to'], 'reversed date ranges fall back to the safe range');

$evenSplit = sales_report_allocate_cents(100, [
    ['key' => 'room:1', 'weight' => 1],
    ['key' => 'room:2', 'weight' => 1],
    ['key' => 'other', 'weight' => 1],
]);
$same(100, array_sum($evenSplit), 'largest-remainder allocation reconciles each cent');
ksort($evenSplit, SORT_STRING);
$same(['other' => 34, 'room:1' => 33, 'room:2' => 33], $evenSplit, 'largest-remainder ties resolve deterministically by target key');
$largeShare = sales_report_mul_divmod(900000000000, 400000000000, 1200000000000);
$same([300000000000, 0], $largeShare, 'integer allocation arithmetic avoids intermediate overflow');
$same(2, array_sum(sales_report_allocate_cents(2, [
    ['key' => 'room:1', 'weight' => 1],
    ['key' => 'room:2', 'weight' => 1],
    ['key' => 'other', 'weight' => 1],
])), 'sub-dollar payments still reconcile after room allocation');

$activity = [
    'booking_id' => 91,
    'booking_total' => '105.00',
    'primary_filter_id' => 500,
    'primary_venue' => 'Event Hall · Grand Hall',
    'primary_category' => 'Event Hall',
];
$roomLines = [91 => [
    ['venue_id' => 11, 'filter_id' => 11, 'venue_name' => 'Hotel · Stellar', 'venue_category' => 'Hotel Room', 'line_total' => '20.00'],
    ['venue_id' => 12, 'filter_id' => 11, 'venue_name' => 'Hotel · Stellar', 'venue_category' => 'Hotel Room', 'line_total' => '30.00'],
    ['venue_id' => 21, 'filter_id' => 21, 'venue_name' => 'Resort Villa · Villa A', 'venue_category' => 'Resort Villa', 'line_total' => '15.00'],
]];
$targets = sales_report_allocation_targets($activity, $roomLines);
$targetWeights = array_column($targets, 'weight', 'key');
$same(['building:11' => 5000, 'venue:21' => 1500, 'venue:500' => 4000], $targetWeights, 'event hall remainder, grouped hotel building, and named villa use current line totals');
$same(['Hotel · Stellar', 'Resort Villa · Villa A', 'Event Hall · Grand Hall'], array_column($targets, 'label'), 'venue targets carry building, villa, and event hall labels');
$assert(!in_array('Other venue revenue', array_column($targets, 'label'), true), 'named event halls and villas are not lost in an other-venue bucket');
$same(10003, array_sum(sales_report_allocate_cents(10003, $targets)), 'event payment shares reconcile to the original transaction');
$same(10003, array_sum(sales_report_allocate_cents(10003, $targets)), 'the same weights reconcile a processed refund');
$hotelTargets = sales_report_allocation_targets([
    'booking_id' => 92,
    'booking_total' => '40.00',
    'primary_filter_id' => 11,
    'primary_venue' => 'Hotel · Stellar',
    'primary_category' => 'Hotel Room',
], []);
$same('building:11', $hotelTargets[0]['key'], 'direct hotel bookings stay attributed to their building');
$same(11, $hotelTargets[0]['filter_id'], 'hotel building filter identity is independent of physical room IDs');
$villaTargets = sales_report_allocation_targets([
    'booking_id' => 93,
    'booking_total' => '25.00',
    'primary_filter_id' => 33,
    'primary_venue' => 'Resort Villa · Villa B',
    'primary_category' => 'Resort Villa',
], []);
$same('venue:33', $villaTargets[0]['key'], 'direct villa bookings retain their own named venue target');
$same('Hotel · Stellar', sales_report_venue_label('Hotel Room', 'Stellar'), 'hotel labels show the building name without a physical room number');

$same("'\u{2003}=2+2", sales_report_csv_cell("\u{2003}=2+2"), 'CSV export protects formulas after Unicode separators while preserving the cell text');
$same("'\t@SUM(A1)", sales_report_csv_cell("\t@SUM(A1)"), 'CSV export protects formulas after tabs');
$same('Room 11', sales_report_csv_cell('Room 11'), 'CSV export preserves ordinary text');
$same(null, manual_payment_display_reference('MANUAL-abc123', null), 'manual payment references hide internal idempotency keys');
$same('GCASH-REF-42', manual_payment_display_reference('MANUAL-abc123', 'GCASH-REF-42'), 'manual payment references prefer the submitted customer reference');
$csvStream = fopen('php://temp', 'w+b');
sales_report_csv_line($csvStream, ['Net', '-5.00'], [1]);
rewind($csvStream);
$same('Net,-5.00' . "\n", stream_get_contents($csvStream), 'negative numeric CSV values remain numeric instead of being escaped as text');
fclose($csvStream);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$service = $read('includes/sales_report.php');
$page = $read('includes/admin-page/admin_sales.php');
$export = $read('actions/admin/export_sales_report.php');
$shell = $read('admin_dashboard.php');
$stats = $read('actions/admin/get_dashboard_stats.php');
$hotelMigration = $read('migrations/023_hotel_room_groups.sql');
$same("NULLIF(TRIM(COALESCE(p.payment_method, '')), '') IS NOT NULL AND LOWER(TRIM(COALESCE(p.payment_method, ''))) <> 'unknown'", sales_report_usable_method_sql('p.payment_method'), 'the SQL method predicate rejects null, blank, and literal Unknown methods');
$assert(str_contains($service, "p.status = 'Success' AND p.amount > 0") && str_contains($service, "h.action = 'processed' AND h.refund_amount > 0") && str_contains($service, 'h.created_at >= ? AND h.created_at < ?'), 'received rows require successful positive payments and refunds use processed history dates');
$assert(str_contains($service, "sales_report_usable_method_sql('p.payment_method')") && str_contains($service, "sales_report_usable_method_sql('cx.refund_destination_method')") && str_contains($service, 'AND {$receivedMethodUsable}') && str_contains($service, 'AND {$refundMethodUsable}'), 'received and refunded SQL exclude blank, null, and literal Unknown methods');
$assert(str_contains($service, "strcasecmp(\$method, 'unknown') === 0") && !str_contains($service, "?: 'Unknown'"), 'the shared builder never exposes or counts an unusable method as Unknown');
$assert(str_contains($service, "UPPER(LEFT(TRIM(COALESCE(p.transaction_id, '')), 7)) = 'MANUAL-'") && str_contains($service, 'NULLIF(TRIM(mps.transaction_reference), \'\')'), 'transaction references suppress internal MANUAL idempotency keys unless a submitted reference exists');
$assert(str_contains($service, "'Maintenance'") && str_contains($service, "'MAINT-%'") && str_contains($service, "'MAINTENANCE'"), 'maintenance bookings are excluded from received and refunded activity');
$assert(str_contains($service, 'LIMIT ? OFFSET ?') && str_contains($service, '$batchSize = 1000'), 'summary and ledger processing fetch activity rows in bounded batches');
$assert(str_contains($hotelMigration, 'v.name AS building_name') && str_contains($service, 'GROUP BY building_venue.name') && str_contains($service, 'GROUP BY v.name') && !str_contains($service, 'room_number') && !str_contains($service, 'room_type'), 'hotel revenue and filter options aggregate by the schema building name without physical room details');
$assert(str_contains($service, "WHERE v.category IN ('Event Hall', 'Resort Villa')") && str_contains($service, "WHEN 'Event Hall' THEN CONCAT('Event Hall · '") && str_contains($service, "WHEN 'Resort Villa' THEN CONCAT('Resort Villa · '") && str_contains($page, 'Venue shares estimate') && str_contains($page, 'Legacy refunds use the date recorded in cancellation history') && str_contains($page, 'refunds without a recorded destination method are excluded from totals') && !str_contains($page, 'appear as Unknown'), 'named venue labels, attribution notes, and missing refund-method handling remain documented');
$assert(str_contains($page, 'name="venue_id"') && str_contains($page, 'Venue group') && !str_contains($page, 'name="room_id"') && str_contains($page, "salesExportLink('venues')") && str_contains($export, "['transactions', 'venues', 'rooms']") && str_contains($export, 'if ($exportType === \'rooms\') $exportType = \'venues\';'), 'venue group selection and CSV exports use canonical venue labels while retaining legacy export links');
$assert(str_contains($service, 'function sales_report_resolve_venue_filter') && str_contains($service, 'THEN COALESCE(hotel_building.group_id, v.id)') && str_contains($page, 'sales_report_resolve_venue_filter($conn') && str_contains($export, 'sales_report_resolve_venue_filter($conn'), 'legacy physical room IDs resolve to building filters in the page and export');
$assert(str_contains($page, "\$salesPaginationBase = ['page' => 'sales'] + \$salesQuery"), 'ledger pagination links stay on the Sales page while preserving filters');
$assert(str_contains($page, 'catch (Throwable $salesLoadError)') && str_contains($page, 'Sales data is temporarily unavailable.') && str_contains($page, 'Retry report'), 'query failures render a safe unavailable state with a retry action');
$assert(str_contains($page, "\$salesRetryQuery = ['page' => 'sales'] + \$salesQuery + ['sales_page' => \$salesPage]") && str_contains($page, "'from' => \$salesFilters['from']") && str_contains($page, "'to' => \$salesFilters['to']") && str_contains($page, "'method' => \$salesFilters['method']") && str_contains($page, "'venue_id' => \$salesFilters['venue_id']"), 'retry URL retains normalized dates, method, venue group, and page filters');
$assert(str_contains($shell, "\$page === 'sales' && \$is_admin_user") && str_contains($page, "\$_SESSION['role'] ?? '') !== 'admin'") && str_contains($page, 'sales_report_is_active_admin($conn'), 'page routing and included report enforce current active-admin access');
$assert(str_contains($export, "\$_SESSION['role'] ?? '') !== 'admin'") && str_contains($export, "\$_SESSION['logged_in'] ?? false) !== true") && str_contains($export, 'sales_report_is_active_admin($conn') && str_contains($service, "SELECT u.role, s.status AS staff_status") && str_contains($service, "'active'") && str_contains($export, 'sales_report_build($conn, $filters, 1, 50, static function'), 'exports validate a live active-admin account and reuse the shared filtered report service');
$assert(str_contains($stats, 'sales_report_current_month_received_total($conn)') && str_contains($service, 'sales_report_activity_exclusions()') && str_contains($service, '$methodUsable = sales_report_usable_method_sql(\'p.payment_method\')'), 'overview monthly sales uses the report service inclusion rules, including usable methods');

echo "Sales report contract checks passed\n";
