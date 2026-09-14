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

echo "Admin Settings contract checks passed (4 assertions)\n";
