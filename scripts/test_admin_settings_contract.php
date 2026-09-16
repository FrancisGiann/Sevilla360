<?php
/** Static regression contract for the admin Settings render path. */
declare(strict_types=1);

$root = dirname(__DIR__);
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

$queryBoundary = 'hr.check_in_time, hr.check_out_time,' . "\n        {\$hotel_group_select},\n        eh.base_capacity";
$assert(str_contains($settings, $queryBoundary), 'the shared venue SELECT owns exactly one delimiter before and after the optional hotel-group fields');

foreach ([$readyFields, $legacyFields] as $fields) {
    $assembled = 'hr.check_in_time, hr.check_out_time, ' . $fields . ', eh.base_capacity';
    $assert(!str_contains($assembled, ', ,') && preg_match('/,\s*,/', $assembled) !== 1, 'both venue SELECT variants assemble without an empty column');
}

$paymentSettingsSave = $read('actions/admin/save_manual_payment_settings.php');
$preferencesSave = $read('actions/admin/save_preferences.php');
$settingsClient = $read('assets/js/admin-page/admin_settings.js');
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

echo "Admin Settings contract checks passed (9 assertions)\n";
