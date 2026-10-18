<?php
/** Regression checks for canonical hotel upload destinations and legacy media slots. */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/media_upload_validation.php';

$checks = [];
$canonical = hotel_room_group_canonical_media_slot_key([
    'building_name' => 'Abelardo',
    'room_type' => 'Family Room / Superior',
    'room_type_code' => 'family_room_superior',
]);
$checks['Abelardo standard slot is classified as standard and retains its validated gallery identity'] =
    $canonical !== null
    && media_upload_classify_website_slot($canonical) === [
        'expected_media_type' => 'standard',
        'canonical_gallery_slot' => $canonical,
    ];
$checks['Abelardo 360 suffix is classified as a 360 upload for the same gallery'] =
    $canonical !== null
    && media_upload_classify_website_slot($canonical . '_360') === [
        'expected_media_type' => '360',
        'canonical_gallery_slot' => $canonical,
    ];
$checks['canonical identities match the exact building and canonical room type from inventory'] =
    $canonical !== null
    && media_upload_canonical_slot_matches_inventory_row($canonical, [
        'building_name' => 'Abelardo',
        'room_type' => 'Family Room / Superior',
        'room_type_code' => 'family_room_superior',
    ])
    && !media_upload_canonical_slot_matches_inventory_row($canonical, [
        'building_name' => 'Abelardo',
        'room_type' => 'Standard Room',
        'room_type_code' => 'standard_room',
    ]);
$forged = 'hotel_type_' . str_repeat('a', 40);
$checks['well-formed but nonexistent canonical keys fail inventory identity matching'] =
    media_upload_classify_website_slot($forged) !== null
    && !media_upload_canonical_slot_matches_inventory_row($forged, [
        'building_name' => 'Abelardo',
        'room_type' => 'Family Room / Superior',
        'room_type_code' => 'family_room_superior',
    ]);
$standardSlotClassification = $canonical === null ? null : media_upload_classify_website_slot($canonical);
$panoSlotClassification = $canonical === null ? null : media_upload_classify_website_slot($canonical . '_360');
$checks['malformed canonical keys are rejected and suffixes require their matching media type'] =
    media_upload_classify_website_slot('hotel_type_' . str_repeat('A', 40)) === null
    && media_upload_classify_website_slot('hotel_type_' . str_repeat('a', 39)) === null
    && ($standardSlotClassification['expected_media_type'] ?? null) === 'standard'
    && ($panoSlotClassification['expected_media_type'] ?? null) === '360';
$checks['historical event and villa slots retain their standard and 360 classifications'] =
    media_upload_classify_website_slot('venue_infinity_hall') === ['expected_media_type' => 'standard', 'canonical_gallery_slot' => null]
    && media_upload_classify_website_slot('venue_infinity_hall_360') === ['expected_media_type' => '360', 'canonical_gallery_slot' => null]
    && media_upload_classify_website_slot('venue_lagoon_villa_360') === ['expected_media_type' => '360', 'canonical_gallery_slot' => null];
$checks['historical homepage and general gallery destinations remain accepted'] =
    media_upload_classify_website_slot('home-hero') === ['expected_media_type' => 'standard', 'canonical_gallery_slot' => null]
    && media_upload_classify_website_slot('home-about') === ['expected_media_type' => 'standard', 'canonical_gallery_slot' => null]
    && media_upload_classify_website_slot('gallery') === ['expected_media_type' => null, 'canonical_gallery_slot' => null]
    && media_upload_classify_website_slot('home-exp-1') === ['expected_media_type' => 'standard', 'canonical_gallery_slot' => null]
    && media_upload_classify_website_slot('home-exp-2') === ['expected_media_type' => 'standard', 'canonical_gallery_slot' => null]
    && media_upload_classify_website_slot('home-exp-3') === ['expected_media_type' => 'standard', 'canonical_gallery_slot' => null];

$upload = (string)file_get_contents($root . '/actions/admin/upload_media.php');
$checks['endpoint validates canonical inventory after image conversion and before replacement or insert'] =
    strpos($upload, 'media_cms_ensure_admin_thumbnail($file[\'db_path\'])') < strpos($upload, 'media_upload_canonical_slot_exists($conn, $canonical_gallery_slot)')
    && strpos($upload, 'media_upload_canonical_slot_exists($conn, $canonical_gallery_slot)') < strpos($upload, 'DELETE FROM media_cms WHERE slot_assignment = ?')
    && strpos($upload, 'media_upload_canonical_slot_exists($conn, $canonical_gallery_slot)') < strpos($upload, 'INSERT INTO media_cms')
    && str_contains($upload, '$expected_media_type !== null && $media_type !== $expected_media_type');
$validation = (string)file_get_contents($root . '/includes/media_upload_validation.php');
$checks['database identity lookup is constrained to active hotel inventory and reuses the canonical helper'] =
    str_contains($validation, 'hotel_group_schema_ready($conn)')
    && str_contains($validation, "v.category = 'Hotel Room' AND v.status <> 'Inactive'")
    && str_contains($validation, 'hotel_room_group_canonical_media_slot_key($row)');
$checks['option slots require exact group inventory identity and uploads enable only the matching override in the insert transaction'] =
    str_contains($validation, 'function media_upload_option_slot_exists')
    && str_contains($validation, 'hotel_room_group_option_media_slot_key($groupId, $tour) !== $websiteSlot')
    && str_contains($validation, 'hotel_room_group_option_media_identity($conn, $groupId, true)')
    && str_contains($upload, 'media_upload_option_slot_exists($conn, $option_group_id, $website_slot)')
    && strpos($upload, 'media_upload_option_slot_exists($conn, $option_group_id, $website_slot)') < strpos($upload, 'INSERT INTO media_cms')
    && str_contains($upload, "\$media_type === '360' ? 'tour_media_override_enabled' : 'standard_media_override_enabled'")
    && strpos($upload, 'UPDATE hotel_room_groups SET {$overrideColumn} = 1') < strpos($upload, '$conn->commit()');

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . "|$label\n";
    if (!$passed) $failed++;
}
exit($failed === 0 ? 0 : 1);
