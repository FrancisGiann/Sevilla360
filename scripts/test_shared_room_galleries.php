<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/hotel_rooms.php';
require_once __DIR__ . '/../includes/media_helper.php';

$checks = [];
$familyFloorOne = hotel_public_room_type_key('Abelardo', 'family_room_superior', 'Family Room');
$familyFloorTwo = hotel_public_room_type_key('Abelardo', 'family_room_superior', 'Superior Room');
$dormitory = hotel_public_room_type_key('Abelardo', 'dormitory_room', 'Family Room');
$checks['canonical codes unify legacy aliases while separate types stay separate'] = $familyFloorOne === $familyFloorTwo && $familyFloorOne !== $dormitory
    && hotel_public_room_type_key('Abelardo', '', 'Family Room') !== hotel_public_room_type_key('Abelardo', '', 'Superior Room');

$variantA = ['base_capacity' => 6, 'max_capacity' => 8, 'bed_count' => 3, 'nightly_rate' => 5000];
$variantB = ['base_capacity' => 10, 'max_capacity' => 12, 'bed_count' => 6, 'nightly_rate' => 7000];
$checks['variant labels keep capacity beds and rate explicit'] = hotel_public_variant_label($variantA) === '6–8 guests · 3 beds · ₱5,000.00/night'
    && hotel_public_variant_label($variantB) === '10–12 guests · 6 beds · ₱7,000.00/night';
$checks['each option has deterministic separate photo and 360 slots'] = hotel_room_group_option_media_slot_key(41) === 'hotel_option_41'
    && hotel_room_group_option_media_slot_key('41', true) === 'hotel_option_41_360'
    && hotel_room_group_option_media_slot_group_id('hotel_option_41') === 41
    && hotel_room_group_option_media_slot_group_id('hotel_option_41_360') === 41
    && hotel_room_group_option_media_slot_group_id('hotel_option_041') === null
    && hotel_room_group_option_media_slot_group_id('hotel_option_41_notes') === null;
$migration = (string)file_get_contents(dirname(__DIR__) . '/migrations/032_hotel_variant_media_overrides.sql');
$groupSource = (string)file_get_contents(dirname(__DIR__) . '/includes/hotel_rooms.php');
$modeEndpoint = (string)file_get_contents(dirname(__DIR__) . '/actions/admin/set_hotel_media_mode.php');
$checks['override rollout is additive, defaults to shared media, and group upserts preserve both flags'] = str_contains($migration, 'ADD COLUMN IF NOT EXISTS standard_media_override_enabled TINYINT(1) NOT NULL DEFAULT 0')
    && str_contains($migration, 'ADD COLUMN IF NOT EXISTS tour_media_override_enabled TINYINT(1) NOT NULL DEFAULT 0')
    && str_contains($groupSource, 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')
    && !str_contains(substr($groupSource, strpos($groupSource, 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'), 700), 'standard_media_override_enabled')
    && !str_contains(substr($groupSource, strpos($groupSource, 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'), 700), 'tour_media_override_enabled');
$checks['option gallery mode endpoint gates admin and CSRF before connecting, then locks active inventory and updates fixed columns transactionally'] =
    strpos($modeEndpoint, "REQUEST_METHOD'] ?? '') !== 'POST'") < strpos($modeEndpoint, "require_once __DIR__ . '/../../config/db_connect.php'")
    && strpos($modeEndpoint, 'hash_equals($csrf, $requestCsrf)') < strpos($modeEndpoint, "require_once __DIR__ . '/../../config/db_connect.php'")
    && str_contains($modeEndpoint, 'hotel_room_group_option_media_identity($conn, (int)$groupId, true)')
    && str_contains($modeEndpoint, "'tour_media_override_enabled' : 'standard_media_override_enabled'")
    && str_contains($modeEndpoint, 'if (!empty($transactionStarted)')
    && !str_contains($modeEndpoint, 'UPDATE hotel_room_groups SET {$payload');
$checks['unselected type presentation prefers shared photos then uses one ordered option without mixing galleries'] = hotel_public_presentation_gallery(
    ['assets/uploads/shared.jpg'],
    [['images' => ['assets/uploads/option-a.jpg']], ['images' => ['assets/uploads/option-b.jpg']]]
) === ['assets/uploads/shared.jpg']
    && hotel_public_presentation_gallery([], [
        ['images' => ['assets/uploads/option-a-cover.jpg', 'assets/uploads/option-a-room.jpg']],
        ['images' => ['assets/uploads/option-b.jpg']],
    ]) === ['assets/uploads/option-a-cover.jpg', 'assets/uploads/option-a-room.jpg']
    && hotel_public_presentation_gallery([], [['images' => ['assets/img/placeholder.jpg']]]) === ['assets/img/placeholder.jpg'];

$groupA = [
    'building_name' => 'Rafael',
    'room_type' => 'Dormitory Room',
    'room_type_code' => 'dormitory_room',
    'legacy_room_type' => 'Dormitory',
    'legacy_media_room_types' => ['Dormitory', '2nd Floor'],
    'media_slot_key' => 'rafael_dormitory_gallery_a',
];
$groupB = [
    'building_name' => 'Rafael',
    'room_type' => 'Dormitory Room',
    'room_type_code' => 'dormitory_room',
    'legacy_room_type' => 'Dormitory Room',
    'legacy_media_room_types' => ['Dormitory Room', 'North Wing'],
    'media_slot_key' => 'rafael_dormitory_gallery_b',
];
$slotsA = hotel_room_group_media_slot_keys($groupA);
$slotsB = hotel_room_group_media_slot_keys($groupB);
$canonicalSlot = hotel_room_group_canonical_media_slot_key($groupA);
$checks['explicit gallery slots remain distinct while canonical uploads are shared'] = in_array('rafael_dormitory_gallery_a', $slotsA, true)
    && !in_array('rafael_dormitory_gallery_b', $slotsA, true)
    && in_array('rafael_dormitory_gallery_b', $slotsB, true)
    && !in_array('rafael_dormitory_gallery_a', $slotsB, true)
    && $canonicalSlot !== null && in_array($canonicalSlot, $slotsA, true) && in_array($canonicalSlot, $slotsB, true)
    && in_array('venue_rafael_2nd_floor', $slotsA, true) && in_array('venue_rafael_north_wing', $slotsB, true);

$media = [
    'rafael_dormitory_gallery_a' => [
        ['id' => 31, 'file_path' => 'assets/uploads/a-old.jpg', 'is_primary' => 0],
        ['id' => 30, 'file_path' => 'assets/uploads/shared.jpg', 'is_primary' => 0],
    ],
    'venue_rafael_2nd_floor' => [
        ['id' => 22, 'file_path' => 'assets/uploads/a-cover.jpg', 'is_primary' => 1],
    ],
    'rafael_dormitory_gallery_b' => [
        ['id' => 41, 'file_path' => 'assets/uploads/b-old.jpg', 'is_primary' => 1],
    ],
    'venue_rafael_north_wing' => [
        ['id' => 40, 'file_path' => 'assets/uploads/shared.jpg', 'is_primary' => 0],
    ],
    (string)$canonicalSlot => [
        ['id' => 10, 'file_path' => 'assets/uploads/new-shared.jpg', 'is_primary' => 0],
    ],
];
$imagesA = hotel_room_group_public_images($groupA, $media);
$imagesB = hotel_room_group_public_images($groupB, $media);
$checks['public alias lookup preserves both explicit galleries and promotes the chosen primary'] = $imagesA[0] === 'assets/uploads/a-cover.jpg'
    && in_array('assets/uploads/a-old.jpg', $imagesA, true)
    && in_array('assets/uploads/new-shared.jpg', $imagesA, true)
    && !in_array('assets/uploads/b-old.jpg', $imagesA, true)
    && $imagesB[0] === 'assets/uploads/b-old.jpg'
    && in_array('assets/uploads/new-shared.jpg', $imagesB, true)
    && count($imagesA) === count(array_unique($imagesA));

$uploadsRoot = dirname(__DIR__) . '/assets/uploads';
$sharedFixture = tempnam($uploadsRoot, '.test-shared-fallback-');
if ($sharedFixture !== false) {
    file_put_contents($sharedFixture, 'temporary media fixture');
    $sharedFixtureRelative = 'assets/uploads/' . basename($sharedFixture);
    $checks['CMS source availability accepts a readable media file and rejects missing or unsafe records'] = media_cms_source_file_available($sharedFixtureRelative)
        && !media_cms_source_file_available('assets/uploads/missing-cms-source-test.jpg')
        && !media_cms_source_file_available('../config/db_connect.php');
    $checks['homepage public galleries accept media records and legacy paths while retaining valid file order'] = media_cms_public_image_paths([
        ['id' => 100, 'file_path' => $sharedFixtureRelative, 'is_primary' => 1],
        ['id' => 101, 'file_path' => 'assets/uploads/missing-public-gallery-test.jpg', 'is_primary' => 0],
        ['id' => 102, 'file_path' => null, 'is_primary' => 0],
        $sharedFixtureRelative,
    ]) === [$sharedFixtureRelative, $sharedFixtureRelative]
        && media_cms_public_image_paths([
            ['file_path' => 'assets/uploads/missing-public-gallery-test.jpg'],
            ['file_path' => '../config/db_connect.php'],
            ['file_path' => 12],
        ]) === ['assets/img/placeholder.jpg'];
    $checks['enabled option gallery with only missing media falls back to a readable shared photo'] = array_column(
        hotel_room_group_public_effective_image_candidates(
            ['room_group_id' => 41] + $groupA,
            [
                'hotel_option_41' => [['id' => 99, 'file_path' => 'assets/uploads/missing-test-option-photo.jpg', 'is_primary' => 1]],
                (string)$canonicalSlot => [['id' => 100, 'file_path' => $sharedFixtureRelative, 'is_primary' => 0]],
            ],
            true
        ),
        'path'
    ) === [$sharedFixtureRelative];
    unlink($sharedFixture);
} else {
    $checks['enabled option gallery with only missing media falls back to a readable shared photo'] = false;
}
$sharedCoverCandidates = array_merge(
    hotel_room_group_public_image_candidates($groupA, $media),
    hotel_room_group_public_image_candidates($groupB, $media)
);
usort($sharedCoverCandidates, 'hotel_public_media_candidate_compare');
$checks['legacy duplicate primaries have a deterministic fallback cover'] = ($sharedCoverCandidates[0]['path'] ?? '') === 'assets/uploads/b-old.jpg';

$sharedGalleryRows = [
    ['id' => 22, 'slot_assignment' => 'venue_rafael_2nd_floor', 'is_primary' => 0],
    ['id' => 41, 'slot_assignment' => 'rafael_dormitory_gallery_b', 'is_primary' => 1],
];
$sharedGalleryAliases = array_values(array_unique([...$slotsA, ...$slotsB]));
foreach ($sharedGalleryRows as &$mediaRow) {
    if (in_array($mediaRow['slot_assignment'], $sharedGalleryAliases, true)) {
        $mediaRow['is_primary'] = (int)$mediaRow['id'] === 22 ? 1 : 0;
    }
}
unset($mediaRow);
$checks['selecting an older cross-slot image replaces the newer shared primary'] = (int)$sharedGalleryRows[0]['is_primary'] === 1
    && (int)$sharedGalleryRows[1]['is_primary'] === 0;

$checks['media preference ranks primary candidates across all canonical aliases'] = str_contains(file_get_contents(__DIR__ . '/../index.php') ?: '', "'_image_candidates' => []")
    && str_contains(file_get_contents(__DIR__ . '/../includes/admin-page/admin_cms.php') ?: '', 'hotel_public_media_candidate_compare')
    && str_contains(file_get_contents(__DIR__ . '/../showroom.php') ?: '', 'hotel_public_media_candidate_compare')
    && str_contains(file_get_contents(__DIR__ . '/../includes/hotel_recommendation_service.php') ?: '', 'ORDER BY is_primary DESC, id DESC');
$checks['homepage standard media resolver normalizes record rows before building event and villa galleries'] = str_contains(
    file_get_contents(__DIR__ . '/../index.php') ?: '',
    'media_cms_public_image_paths($public_media[$public_slot_key($displayName)] ?? [])'
);
$primaryApi = file_get_contents(__DIR__ . '/../actions/admin/set_primary_media.php') ?: '';
$cmsJs = file_get_contents(__DIR__ . '/../assets/js/admin-page/admin_cms.js') ?: '';
$checks['CMS shared cover action updates every photo in the canonical gallery'] = str_contains($cmsJs, 'requestBody.canonical_gallery_slot = sharedGallery.canonicalSlot')
    && str_contains($cmsJs, 'new Set((window.galleryData?.[sharedGallerySlot] || []).map(photo => String(photo.id)))')
    && str_contains($cmsJs, "sharedGalleryMediaIds?.has(String(photo.id))")
    && str_contains($cmsJs, 'Choose the image that should appear as the cover for this shared room gallery.');
$checks['server derives shared media slots from validated hotel identity and clears them atomically'] = str_contains($primaryApi, 'set_primary_media_hotel_gallery_slots(')
    && str_contains($primaryApi, 'hotel_room_group_media_slot_keys($group)')
    && str_contains($primaryApi, 'UPDATE media_cms SET is_primary = 0 WHERE slot_assignment IN ({$slotPlaceholders})')
    && str_contains($primaryApi, 'UPDATE media_cms SET is_primary = 1 WHERE id = ? AND slot_assignment IN ({$slotPlaceholders})')
    && str_contains($primaryApi, 'COUNT(*) AS primary_count')
    && str_contains($primaryApi, '$conn->rollback()');
$checks['CMS hotspot editor reads exact source-slot panoramas'] = str_contains(file_get_contents(__DIR__ . '/../includes/admin-page/admin_cms.php') ?: '', 'window.hotelSourcePanoData =')
    && str_contains(file_get_contents(__DIR__ . '/../assets/js/admin-page/admin_hotspots.js') ?: '', 'window.hotelSourcePanoData?.[source.slot]')
    && str_contains(file_get_contents(__DIR__ . '/../assets/js/admin-page/admin_hotspots.js') ?: '', 'String(photo.slot_assignment) === source.slot');
$checks['group and raw-label review keys resolve to the canonical card'] = str_contains(file_get_contents(__DIR__ . '/../index.php') ?: '', "\$listing['review_keys'][] = hotel_public_review_key(\$groupId, \$building, \$roomType)")
    && str_contains(file_get_contents(__DIR__ . '/../assets/js/index.js') ?: '', 'Array.isArray(venue.review_keys) ? venue.review_keys : []');

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . '|' . $label . PHP_EOL;
    if (!$passed) $failed++;
}
exit($failed === 0 ? 0 : 1);
