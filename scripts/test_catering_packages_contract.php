<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/catering_packages.php';
require_once $root . '/includes/receptionist_knowledge.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Catering package contract failed: {$message}\n");
        exit(1);
    }
};
$assertInvalid = static function (array $post, string $message) use ($assert): void {
    try {
        catering_package_settings_from_post($post);
    } catch (InvalidArgumentException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

$defaults = catering_packages_from_settings([]);
$assert(array_column($defaults, 'rate') === ['550', '600', '650'], 'unconfigured installs use the supplied package reference prices.');
$assert(array_column($defaults, 'name') === ['Package 1', 'Package 2', 'Package 3'], 'unconfigured package names match the supplied reference.');
$assert($defaults['silver']['menu'] === [
    'Beef Tenderloin with Mushroom Sauce', 'Chicken Cordon Bleu', 'Pork Shanghai Roll',
    'Canton Sotanghon', 'Soup', 'Rice', 'Coffee Jelly', 'Iced Tea',
], 'Package 1 defaults contain only the supplied menu items in order.');
$assert($defaults['gold']['menu'] === [
    'Beef with Broccoli', "Sevilla's Chicken", 'Pork Asado', 'Fish Fillet w/ tartar dip',
    'Mixed Vegetables with Quail Egg', 'Soup', 'Rice', 'Buko Pandan', 'Cucumber Lemonade',
], 'Package 2 defaults preserve the supplied wording and order.');
$assert($defaults['platinum']['menu'] === [
    'Beef Caldereta', 'Pandan Chicken', 'Pork Hamonado', 'Sweet & Sour Fish', 'Calamares',
    'Chopsuey', 'Soup', 'Rice', 'Cathedral Window', 'Lemon Jelly Juice',
], 'Package 3 defaults contain only the supplied menu items in order.');

$existing = catering_packages_from_settings([
    'catering_silver' => '725',
    'catering_gold' => '1049.25',
    'catering_platinum' => '0',
]);
$assert(array_column($existing, 'rate') === ['725', '1049.25', '0'], 'existing legacy rate values, including zero, remain authoritative.');
$assert(catering_package_format_rate('550.1234') === '550.1234'
    && catering_package_format_rate('550.1000') === '550.10'
    && catering_package_format_rate('550') === '550.00', 'rate display preserves up to four decimal places and shows at least cents.');

$validPost = [];
foreach (catering_package_definitions() as $key => $definition) {
    $validPost[$definition['name_key']] = 'Custom ' . strtoupper($key);
    $validPost[$definition['rate_key']] = '600.125';
    $validPost['catering_' . $key . '_menu'] = "First dish\n\nSecond dish";
}
$normalized = catering_package_settings_from_post($validPost);
$assert($normalized['catering_silver'] === '600.125'
    && $normalized['catering_silver_name'] === 'Custom SILVER'
    && json_decode($normalized['catering_silver_menu_json'], true) === ['First dish', 'Second dish'], 'valid package edits normalize blank menu lines and store the legacy price key.');
$singlePackage = catering_package_settings_for_post('gold', $validPost);
$assert(array_keys($singlePackage) === ['catering_gold', 'catering_gold_name', 'catering_gold_menu_json']
    && !array_key_exists('catering_silver', $singlePackage)
    && !array_key_exists('catering_platinum', $singlePackage), 'single-package edits return only the selected package settings.');

$invalidRate = $validPost;
$invalidRate['catering_gold'] = '1e3';
$assertInvalid($invalidRate, 'scientific notation is rejected instead of silently coerced.');
$invalidRate = $validPost;
$invalidRate['catering_gold'] = '-1';
$assertInvalid($invalidRate, 'negative package prices are rejected.');
$invalidRate = $validPost;
$invalidRate['catering_gold'] = '1000000.00001';
$assertInvalid($invalidRate, 'prices with more than four decimal places are rejected.');
$invalidName = $validPost;
$invalidName['catering_gold_name'] = str_repeat('x', 61);
$assertInvalid($invalidName, 'package names over 60 characters are rejected.');
$invalidMenu = $validPost;
$invalidMenu['catering_gold_menu'] = "\x01bad item";
$assertInvalid($invalidMenu, 'control characters in menu items are rejected.');
$invalidMenu = $validPost;
$invalidMenu['catering_gold_menu'] = str_repeat("Dish\n", 31);
$assertInvalid($invalidMenu, 'menus over 30 items are rejected.');

$renderSettings = [
    'catering_silver_name' => '<script>alert("x")</script>',
    'catering_silver_menu_json' => json_encode(['<img src=x onerror=alert(1)>', 'Iced Tea']),
];
$cateringPackages = catering_packages_from_settings($renderSettings);
ob_start();
include $root . '/includes/partials/catering_package_cards.php';
$rendered = (string)ob_get_clean();
$assert(str_contains($rendered, 'data-package-id="package-1"')
    && str_contains($rendered, 'data-package-id="package-2"')
    && str_contains($rendered, 'data-package-id="package-3"'), 'rendered cards carry stable IDs even if package rates match.');
$assert(str_contains($rendered, '₱550.00 / pax')
    && str_contains($rendered, '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;')
    && str_contains($rendered, '&lt;img src=x onerror=alert(1)&gt;')
    && !str_contains($rendered, '<script>alert("x")</script>'), 'rendered names and menu items are escaped while the displayed price stays accurate.');

$knowledgeRecords = receptionist_knowledge_build_records([], [
    'catering_silver' => '555.125',
    'catering_silver_name' => 'Custom Buffet',
], []);
$pricingRecord = array_values(array_filter($knowledgeRecords, static fn(array $record): bool => ($record['kind'] ?? null) === 'event_pricing'))[0] ?? [];
$customCateringModifier = array_values(array_filter($pricingRecord['modifiers'] ?? [], static fn(array $modifier): bool => ($modifier['label'] ?? null) === 'Catering: Custom Buffet'))[0] ?? [];
$assert(($customCateringModifier['amount'] ?? null) === 555.125
    && receptionist_knowledge_money(555.125) === '₱555.125'
    && receptionist_knowledge_money(550.0001) === '₱550.0001', 'the receptionist uses the configured package name and preserves up to four decimal places.');

$settingsPage = (string)file_get_contents($root . '/includes/admin-page/admin_settings.php');
$settingsController = (string)file_get_contents($root . '/assets/js/admin-page/admin_settings.js');
$settingsStyles = (string)file_get_contents($root . '/assets/css/admin-page/admin_settings.css');
$preferencesEndpoint = (string)file_get_contents($root . '/actions/admin/save_preferences.php');
$bookingController = (string)file_get_contents($root . '/assets/js/booking.js');
$assert(str_contains($settingsPage, 'catering_packages_from_settings($current_settings)')
    && str_contains($settingsPage, 'name="catering_<?php echo $package_key; ?>_name"')
    && str_contains($settingsPage, 'name="catering_<?php echo $package_key; ?>_menu"')
    && str_contains($settingsPage, 'data-package-summary')
    && str_contains($settingsPage, 'data-package-number="Package <?php echo $package_number; ?>"')
    && str_contains($settingsPage, '<dialog id="catering-package-dialog"')
    && str_contains($settingsPage, 'id="catering-package-dialog-form"')
    && str_contains($settingsPage, 'data-menu-list')
    && str_contains($settingsPage, 'data-menu-add')
    && str_contains($settingsPage, 'data-menu-payload'), 'admin Settings renders editable package details and individually managed menu rows with the existing textarea field contract.');
$assert(str_contains($settingsController, 'maxCateringMenuItems = 30')
    && str_contains($settingsController, 'if (preferencesSaveStatus.textContent !== message)')
    && str_contains($settingsController, 'list.insertBefore(row, sibling)')
    && str_contains($settingsController, 'list.insertBefore(sibling, row)')
    && str_contains($settingsController, "payload.value = rows.map(row => row.querySelector('[data-menu-item]')?.value.trim() || '').join('\\n')")
    && str_contains($settingsController, 'itemInput.setCustomValidity(validationMessage)')
    && str_contains($settingsController, "fetch('actions/admin/save_catering_package.php'")
    && str_contains($settingsController, 'renderPackageSummary(summary, data.package)')
    && str_contains($settingsController, 'packageDialog.showModal()')
    && str_contains($settingsController, "document.body.style.overflow = 'hidden'")
    && str_contains($settingsController, "event.key !== 'Tab'")
    && str_contains($settingsController, 'focusable[focusable.length - 1].focus()')
    && str_contains($settingsController, 'focusable[0].focus()')
    && str_contains($settingsController, "!element.closest('[hidden]')")
    && str_contains($settingsController, 'packageDialog?.open && (packageDialogDirty || packageDialogSaving)')
    && str_contains($settingsController, "packageDialog?.addEventListener('cancel'")
    && str_contains($settingsController, 'requestPackageDialogClose()')
    && str_contains($settingsController, 'if (packageDialog?.open || preferencesSaving) return')
    && str_contains($settingsController, "const isNetworkFailure = error instanceof TypeError")
    && str_contains($settingsController, 'preferencesRevision === submittedRevision')
    && str_contains($settingsController, 'Submitted changes saved. New edits are still unsaved.')
    && str_contains($settingsController, "finally {\n        btnSavePrefs.innerHTML = originalText;\n        btnSavePrefs.disabled = false;")
    && str_contains($settingsController, '/failed to fetch|networkerror|load failed|fetch failed/i')
    && str_contains($settingsController, 'your edits are still here')
    && str_contains($settingsPage, 'Discard your unsaved package edits?')
    && str_contains($settingsStyles, '.catering-discard-confirm[hidden] { display: none; }'), 'menu editing preserves order and limits, reports blank rows, and the dialog confirms discards while save feedback keeps edits.');
$assert(str_contains($preferencesEndpoint, 'catering_package_settings_from_post($_POST)')
    && str_contains($preferencesEndpoint, 'hash_equals($_SESSION[\'csrf_token\'], $client_csrf_token)')
    && str_contains($preferencesEndpoint, "INSERT INTO audit_logs (user_id, module, action, ip_address)"), 'the admin endpoint validates packages, keeps the existing CSRF guard, and records the save in audit logs.');
$packageEndpoint = (string)file_get_contents($root . '/actions/admin/save_catering_package.php');
$assert(str_contains($packageEndpoint, 'catering_package_settings_for_post($packageKey, $_POST)')
    && str_contains($packageEndpoint, "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")
    && str_contains($packageEndpoint, "Updated catering package ")
    && str_contains($packageEndpoint, 'hash_equals($sessionCsrf, $requestCsrf)'), 'the dedicated admin endpoint validates and audits one selected package save.');
$assert(str_contains($bookingController, 'cateringTierId')
    && str_contains($bookingController, 'item.dataset.packageId === String(draft.addons.cateringTierId || \'\')')
    && str_contains($bookingController, 'item.value === String(draft.addons.cateringTier || \'\')'), 'new drafts restore by stable package ID while legacy drafts still restore by price.');

fwrite(STDOUT, "Catering package contract passed.\n");
