<?php
/** Static contract checks for CMS primary-media state and update safety. */
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$cms_js = $read('assets/js/admin-page/admin_cms.js');
$primary_php = $read('actions/admin/set_primary_media.php');
$cms_php = $read('includes/admin-page/admin_cms.php');
$upload_php = $read('actions/admin/upload_media.php');
$index_php = $read('index.php');
$legacy_home_slots = ['home-eventhall', 'home-villa', 'home-hotel'];

$checks = [
    'CMS derives primary state from the database flag' => str_contains($cms_js, 'Number(photo.is_primary) === 1') && !str_contains($cms_js, 'const isPrimary = index === 0'),
    'primary controls expose pressed state and labels' => str_contains($cms_js, 'aria-pressed=') && str_contains($cms_js, 'aria-label=') && str_contains($cms_js, 'Set as primary image'),
    'primary request sends a numeric id and guards pending buttons' => str_contains($cms_js, 'id: mediaIdNumber') && str_contains($cms_js, 'primaryBtn.dataset.pending') && str_contains($cms_js, 'finally(() =>'),
    'primary request handles HTTP and invalid JSON responses' => str_contains($cms_js, '!res.ok') && str_contains($cms_js, 'The server returned an invalid response.'),
    'primary state changes only after confirmed success' => str_contains($cms_js, 'data.success !== true') && str_contains($cms_js, 'window.galleryData[slot]') && str_contains($cms_js, 'updatePrimaryButtonState'),
];

// Keep the method/object checks explicit so the contract remains readable.
$checks['endpoint requires POST and a JSON object'] = str_contains($primary_php, "REQUEST_METHOD'] ?? '') !== 'POST'")
    && str_contains($primary_php, 'json_decode($rawData ?: \'\', false, 512, JSON_THROW_ON_ERROR)')
    && str_contains($primary_php, '!is_object($decodedData)');
$checks += [
    'endpoint validates positive integer id and safe slot' => str_contains($primary_php, '!is_int($data[\'id\']) || $data[\'id\'] < 1') && str_contains($primary_php, '/\\A[a-zA-Z0-9_-]{1,100}\\z/'),
    'endpoint verifies id and slot before resetting' => str_contains($primary_php, 'SELECT id FROM media_cms WHERE id = ? AND slot_assignment = ? LIMIT 1 FOR UPDATE') && strpos($primary_php, 'SELECT id FROM media_cms WHERE id = ? AND slot_assignment = ?') < strpos($primary_php, 'UPDATE media_cms SET is_primary = 0 WHERE slot_assignment = ?'),
    'endpoint retains slot predicate when setting primary' => str_contains($primary_php, 'UPDATE media_cms SET is_primary = 1 WHERE id = ? AND slot_assignment = ?'),
    'endpoint verifies one primary and returns its id' => str_contains($primary_php, 'COUNT(*) AS primary_count') && str_contains($primary_php, "'primary_id' => \$media_id"),
    'endpoint rolls back and hides database errors' => str_contains($primary_php, '$conn->rollback()') && str_contains($primary_php, "'Primary image could not be updated.'") && !str_contains($primary_php, "'message' => \$e->getMessage()"),
    'CMS omits obsolete category preview controls' => !array_filter($legacy_home_slots, static fn(string $slot): bool => str_contains($cms_php, $slot)),
    'upload allowlist omits obsolete category preview slots' => !array_filter($legacy_home_slots, static fn(string $slot): bool => str_contains($upload_php, "'$slot'")),
    'homepage venue cards source standard media galleries in primary order' => str_contains($index_php, "WHERE media_type = 'standard' ORDER BY is_primary DESC, id ASC") && str_contains($index_php, '$public_images') && str_contains($index_php, "'images' => \$public_images"),
    'upload progress uses a transform with a zero default' => str_contains($cms_php, 'transform-origin: left center; transform: scaleX(0); transition: transform 0.2s;') && str_contains($cms_js, "progBar.style.transform = 'scaleX(0)'") && str_contains($cms_js, 'Math.min(1, Math.max(0, percentComplete / 100))'),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . "|$label\n";
    if (!$passed) $failed++;
}
exit($failed === 0 ? 0 : 1);
