<?php
/** Static regression contract for the admin Settings render path. */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/event_bundle.php';
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) {
        fwrite(STDERR, "Could not read {$path}\n");
        exit(1);
    }
    return $contents;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Admin Settings contract failed: {$message}\n");
        exit(1);
    }
};

$shell = $read('admin_dashboard.php');
$settings = $read('includes/admin-page/admin_settings.php');
$assert(str_contains($shell, "elseif (\$page === 'settings') include 'includes/admin-page/admin_settings.php';")
    && str_contains($shell, 'assets/css/admin-page/admin_settings.css')
    && str_contains($shell, 'assets/js/admin-page/admin_settings.js'), 'the settings route includes its content, stylesheet, and controller');

$readyFields = 'hr.room_type_code, hr.room_group_id, hg.media_slot_key';
$legacyFields = 'NULL AS room_type_code, NULL AS room_group_id, NULL AS media_slot_key';
$assert(str_contains($settings, "? '{$readyFields}'")
    && str_contains($settings, ": '{$legacyFields}';")
    && !str_starts_with($readyFields, ',')
    && !str_starts_with($legacyFields, ','), 'schema-ready and fallback venue select fragments have no leading delimiter');

$venueSave = $read('actions/admin/save_venue.php');
$floorMigration = $read('migrations/029_hotel_room_floors.sql');
$settingsClient = $read('assets/js/admin-page/admin_settings.js');
$assert(str_contains($settings, 'hr.room_number, hr.floor_label, hr.bed_count')
    && str_contains($settings, 'json_encode([(string)$v[\'name\'], $floor_label]')
    && str_contains($settings, '$floor_search_label = $floor_label !== \'\' ? $floor_label : \'Floor not recorded\'')
    && str_contains($settings, 'data-search="<?php echo htmlspecialchars($venue_search')
    && str_contains($settings, 'placeholder="Search hotel, floor, or room..."'), 'Manage Venues groups hotel inventory by floor, retains each room row and identity, and searches hotel, floor, type, and room number.');
$assert(str_contains($settings, 'name="floor_label"') && str_contains($settings, 'maxlength="80"')
    && str_contains($settings, 'Applied to every room in this batch.')
    && str_contains($settingsClient, 'vm-hr-floor') && str_contains($settingsClient, 'venueData.floor_label'), 'the optional floor control supports editing and one shared label per bulk-created batch.');
$assert(str_contains($settings, '$hotel_building_names')
    && str_contains($settings, 'id="vm-hotel-name-options"')
    && str_contains($settings, 'Reuse the same hotel or building name for every room in this property.')
    && str_contains($settings, 'Rooms group by building name and floor.')
    && str_contains($settings, 'Optional room details'), 'hotel names can be reused from suggestions, floor grouping is explicit, and optional room details are disclosed separately.');
$assert(preg_match('/id="vm-hr-floor"[^>]*data-required="false"/', $settings) === 1
    && str_contains($settings, '<label for="vm-hr-floor">Floor label (optional)</label>')
    && !str_contains($settings, '<label for="vm-hr-floor">Floor label <span class="field-help">Optional</span></label>')
    && preg_match('/id="vm-hr-media-slot"[^>]*data-required="false"/', $settings) === 1
    && preg_match('/id="vm-hr-check-in"[^>]*data-required="false"/', $settings) === 1
    && preg_match('/id="vm-hr-check-out"[^>]*data-required="false"/', $settings) === 1
    && str_contains($settingsClient, 'field.required = enabled && field.dataset.required !== "false"')
    && str_contains($settingsClient, 'floorField.setAttribute("aria-describedby", bulkEnabled ? "vm-hr-floor-bulk-help" : "vm-hr-floor-help")'), 'optional floor, timing, and media fields stay optional when hotel fields are enabled, with batch-specific accessible help.');
$assert(str_contains($settingsClient, 'venueData.floor_label')
    && str_contains($settingsClient, 'hotelAdvancedDetails.open = isHotelRoom && isEditMode')
    && str_contains($settingsClient, 'venueSaveButton.textContent = enabled ? "Create Rooms" : "Add Room"')
    && str_contains($settingsClient, 'openVenueModal(this)'), 'hotel edit values remain available, optional details open for edits, and single/bulk add actions stay distinct.');
$assert(str_contains($venueSave, "venue_text('floor_label', 80)")
    && str_contains($venueSave, 'floor_label = ?')
    && str_contains($venueSave, 'floor_label, bed_count')
    && str_contains($floorMigration, 'ADD COLUMN IF NOT EXISTS floor_label VARCHAR(80) NULL')
    && !preg_match('/\b(?:DROP|DELETE)\s+/i', $floorMigration), 'server validation caps UTF-8 floor labels at 80 characters and the schema migration is additive.');
$assert(str_contains($settingsClient, 'const manuallyCollapsedGroups = new Set();')
    && str_contains($settingsClient, '!manuallyCollapsedGroups.has(groupId)')
    && str_contains($settingsClient, 'manuallyCollapsedGroups.add(groupId)')
    && !str_contains($settingsClient, "children.forEach(row => row.classList.toggle('room-row-collapsed', expanded))"), 'search respects a manual group collapse and filters rows from the same aria-expanded state.');

$queryBoundary = 'hr.check_in_time, hr.check_out_time,' . "\n        {\$hotel_group_select},\n        eh.base_capacity";
$assert(str_contains($settings, $queryBoundary), 'the shared venue SELECT owns exactly one delimiter before and after the optional hotel-group fields');

foreach ([$readyFields, $legacyFields] as $fields) {
    $assembled = 'hr.check_in_time, hr.check_out_time, ' . $fields . ', eh.base_capacity';
    $assert(!str_contains($assembled, ', ,') && preg_match('/,\s*,/', $assembled) !== 1, 'both venue SELECT variants assemble without an empty column');
}

$paymentSettingsSave = $read('actions/admin/save_manual_payment_settings.php');
$preferencesSave = $read('actions/admin/save_preferences.php');
$eventBundle = $read('includes/event_bundle.php');
$manualPaymentHelper = $read('includes/manual_payment.php');
$paymentDetails = $read('actions/user/get_manual_payment_details.php');
$paymentSubmission = $read('actions/user/submit_manual_payment.php');
$migration = $read('migrations/026_custom_manual_payment_methods.sql');
$rollback = $read('migrations/rollback/026_custom_manual_payment_methods.sql');
$assert(str_contains($settings, 'foreach ($manual_payment_instructions as $method_key => $method_settings)')
    && str_contains($settings, 'methods[<?php echo $safe_method_key; ?>][name]')
    && str_contains($settings, 'id="btn-add-manual-payment-method"')
    && str_contains($settings, 'manual-payment-move-up')
    && str_contains($settings, 'methods[<?php echo $safe_method_key; ?>][remove_qr]'), 'Customer Payments renders the configured method list with editable names, add/reorder controls, and optional QR management.');
$assert(str_contains($paymentSettingsSave, "\$_SESSION['role'] ?? '') !== 'admin'")
    && str_contains($paymentSettingsSave, 'hash_equals($_SESSION[\'csrf_token\'], $csrf)')
    && str_contains($paymentSettingsSave, 'manual_payment_method_key_is_valid($key)')
    && str_contains($paymentSettingsSave, 'manual_payment_store_qr_image($temporaryPath)')
    && str_contains($paymentSettingsSave, 'if (!$conn->commit())')
    && str_contains($paymentSettingsSave, 'foreach ($oldFiles as $oldFile)'), 'method saves retain admin/CSRF guards and verified QR replacement cleanup follows a successful database commit.');
$assert(str_contains($manualPaymentHelper, "'sort_order' => " . '$order')
    && str_contains($manualPaymentHelper, 'MANUAL_PAYMENT_MAX_METHODS = 50')
    && str_contains($paymentDetails, "'label' => \$method['name']")
    && str_contains($paymentDetails, "if (!\$method['enabled']) continue")
    && str_contains($paymentSubmission, '$lockedMethods[$methodKey][\'enabled\']'), 'customers receive ordered active methods and submissions recheck active state on the server.');
$assert(substr_count($migration, 'MODIFY payment_method VARCHAR(120) NOT NULL') === 2
    && str_contains($migration, 'ALTER TABLE payments')
    && substr_count($rollback, 'WHERE BINARY payment_method NOT IN') === 2
    && str_contains($rollback, "ENUM(''GCash'', ''Maya'', ''Bank Transfer'')")
    && str_contains($rollback, "ENUM(''Cash'', ''GCash'', ''Maya'', ''Bank Transfer'', ''PayMongo'', ''Card'')")
    && str_contains($rollback, 'Rollback blocked: custom payment method submissions exist')
    && str_contains($rollback, 'Rollback blocked: custom payment method values exist'), 'both payment method columns allow custom snapshots and each rollback guard preserves its table-specific legacy enum values.');
$assert(!str_contains($settings, 'refund-fee-percent')
    && !str_contains($settings, 'refund_fee_percent')
    && !str_contains($settingsClient, 'refund-fee-percent')
    && !str_contains($settingsClient, 'payment-processing fee between')
    && !str_contains($preferencesSave, 'refund_fee_percent'), 'the retired processing-fee control is absent from settings, save validation, and the preferences payload.');

$assert(str_contains($eventBundle, "EVENT_BUNDLE_DISCOUNT_SETTING_KEY = 'event_hall_bundle_discount_percent'")
    && str_contains($eventBundle, 'EVENT_BUNDLE_DISCOUNT_DEFAULT_PERCENT = 20.0')
    && str_contains($eventBundle, 'is_finite($percent)')
    && str_contains($eventBundle, '$percent < 0 || $percent > 100')
    && str_contains($eventBundle, 'event_bundle_discount_label'), 'bundle discount defaults, validates finite 0..100 percentages, and formats one shared line-item label.');
$assert(str_contains($settings, 'name="event_hall_bundle_discount_percent"')
    && str_contains($settings, 'min="0" max="100" step="0.01"')
    && str_contains($settings, 'selected hotel-room subtotal')
    && str_contains($preferencesSave, 'parse_event_bundle_discount_percent')
    && strpos($preferencesSave, 'parse_event_bundle_discount_percent') < strpos($preferencesSave, 'INSERT INTO system_settings'), 'the administrator can configure the escaped bundle percentage and the server validates it before upserting settings.');

$assert(parse_event_bundle_discount_percent(0) === 0.0
    && parse_event_bundle_discount_percent('20.25') === 20.25
    && parse_event_bundle_discount_percent(100) === 100.0, 'bundle percentage parsing accepts the inclusive boundaries and supported decimals.');
$invalidBundleValues = ['', 'not-a-number', INF, -0.01, 100.01, [20], true];
foreach ($invalidBundleValues as $invalidBundleValue) {
    $assert(parse_event_bundle_discount_percent($invalidBundleValue) === null, 'invalid bundle percentage values are rejected without coercion.');
}
$assert(event_bundle_discount_rate('20.25') === 0.2025
    && event_bundle_discount_label('20.25') === 'Event Hall + Hotel Bundle Discount (20.25%)'
    && normalize_event_bundle_discount_percent('malformed') === 20.0
    && normalize_event_bundle_discount_percent(INF) === 20.0, 'bundle rate, dynamic label, and malformed stored-value fallback remain consistent.');

echo "Admin Settings contract checks passed\n";
