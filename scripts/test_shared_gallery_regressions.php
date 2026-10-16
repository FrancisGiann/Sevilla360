<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/showroom_tour.php';
require_once __DIR__ . '/../includes/media_helper.php';

$checks = [];
$eventData = ['infinity_hall' => ['id' => 'infinity_hall']];
$villaData = ['garden_villa' => ['id' => 'garden_villa']];
$checks['event 360 slots retain legacy venue destinations after hotel canonicalization'] =
    showroom_tour_media_destination_ids('venue_infinity_hall_360', '360', $eventData, []) === ['infinity_hall']
    && showroom_tour_media_destination_ids('venue_infinity_hall', 'standard', $eventData, []) === ['infinity_hall'];
$checks['villa 360 slots retain legacy venue destinations after hotel canonicalization'] =
    showroom_tour_media_destination_ids('venue_garden_villa_360', '360', $villaData, []) === ['garden_villa'];

$hotelId = 'hotel_type_family';
$hotelData = [$hotelId => ['id' => $hotelId, 'category' => 'Hotel Room']];
$hotelAliases = [
    'venue_miradeth_1st_floor_family_room_superior' => [$hotelId],
    'hotel_type_family' => [$hotelId],
];
$checks['hotel legacy and canonical aliases still resolve to one hotel destination'] =
    showroom_tour_media_destination_ids('venue_miradeth_1st_floor_family_room_superior_360', '360', $hotelData, $hotelAliases) === [$hotelId]
    && showroom_tour_media_destination_ids('hotel_type_family_360', '360', $hotelData, $hotelAliases) === [$hotelId];

$miradethSource = [
    'id' => 103,
    'file_name' => 'room-pano.jpg',
    'file_path' => 'assets/uploads/room-pano.jpg',
    'is_primary' => 0,
    'slot_assignment' => 'venue_miradeth_1st_floor_family_room_superior_360',
];
$sourceGalleries = hotel_room_group_source_panorama_galleries(
    [
        'venue_miradeth_1st_floor_family_room_superior',
        'venue_miradeth_1st_floor_family_room_superior',
        'hotel_type_family',
    ],
    [
        'venue_miradeth_1st_floor_family_room_superior_360' => [$miradethSource],
        // Simulate a display aggregate copied under the canonical alias. Its
        // original slot ownership must prevent a second hotspot editor action.
        'hotel_type_family_360' => [$miradethSource],
    ]
);
$checks['Miradeth alias aggregation exposes one source gallery for one actual panorama'] =
    count($sourceGalleries) === 1
    && $sourceGalleries[0]['slot'] === 'venue_miradeth_1st_floor_family_room_superior_360'
    && count($sourceGalleries[0]['photos']) === 1
    && (int)$sourceGalleries[0]['photos'][0]['id'] === 103;

$secondSource = $miradethSource;
$secondSource['id'] = 204;
$secondSource['slot_assignment'] = 'venue_miradeth_1st_floor_alternate_360';
$distinctGalleries = hotel_room_group_source_panorama_galleries(
    ['venue_miradeth_1st_floor_family_room_superior', 'venue_miradeth_1st_floor_alternate'],
    [
        'venue_miradeth_1st_floor_family_room_superior_360' => [$miradethSource],
        'venue_miradeth_1st_floor_alternate_360' => [$secondSource],
    ]
);
$checks['distinct actual source galleries remain separately selectable'] = count($distinctGalleries) === 2
    && $distinctGalleries[0]['slot'] !== $distinctGalleries[1]['slot'];

$cms = (string)file_get_contents(__DIR__ . '/../includes/admin-page/admin_cms.php');
$hotspots = (string)file_get_contents(__DIR__ . '/../assets/js/admin-page/admin_hotspots.js');
$cmsClient = (string)file_get_contents(__DIR__ . '/../assets/js/admin-page/admin_cms.js');
$cmsCss = (string)file_get_contents(__DIR__ . '/../assets/css/admin-page/admin_cms.css');
$showroom = (string)file_get_contents(__DIR__ . '/../showroom.php');
$showroomClient = (string)file_get_contents(__DIR__ . '/../assets/js/showroom.js');
$checks['canonical CMS card emits one hotspot action with real source choices'] =
    str_contains($cms, 'data-gallery-sources=')
    && str_contains($cms, 'hotel_gallery_hotspot_sources[$canonical_slot]')
    && str_contains($cms, 'hotel_source_pano_venue_photos_ordered')
    && !str_contains($cms, 'Hotspots <?php echo $hotspot_gallery_index; ?>');
$checks['hotspot editor changes exact source slot before loading panorama and saved pins'] =
    str_contains($cms, 'id="hs-admin-gallery-selector"')
    && str_contains($hotspots, 'adminGallerySelector?.addEventListener(\'change\'')
    && str_contains($hotspots, 'String(photo.slot_assignment) === source.slot')
    && str_contains($hotspots, 'initViewer(currentPhotosArray[0].file_path)');
$checks['one hotel type card remains visible to standard and 360 filters and exposes independent room option controls'] =
    str_contains($cms, 'class="cms-card cms-hotel-type-card" data-type="hotel" data-types="standard 360"')
    && str_contains($cms, 'id="hotelGalleryEditor"')
    && str_contains($cms, 'data-hotel-tab="photos"')
    && str_contains($cms, 'data-hotel-tab="tour"')
    && str_contains($cms, 'data-hotel-tab="options"')
    && str_contains($cms, "'standard_slot' => hotel_room_group_option_media_slot_key(\$groupId)")
    && str_contains($cms, "'tour_slot' => hotel_room_group_option_media_slot_key(\$groupId, true)")
    && str_contains($cmsClient, 'Manage own photos')
    && str_contains($cmsClient, 'Manage own tour')
    && str_contains($cmsClient, 'Return to shared photos')
    && str_contains($cmsClient, 'Return to shared tour')
    && str_contains($cmsClient, 'Manage room option photos')
    && str_contains($cmsClient, 'No photos yet. Add photos for this room option.')
    && str_contains($cmsClient, 'not currently used')
    && str_contains($cms, 'id="mg-empty-state"');
$checks['hotel editor tabs reset global section spacing and child gallery dialogs stack above the parent'] =
    str_contains($cmsCss, '.hotel-editor-body [role="tabpanel"] { padding: 0; }')
    && preg_match('/\.hotel-editor-overlay\s*\{\s*z-index:\s*1050;/s', $cmsCss) === 1
    && preg_match('/\.cms-modal-overlay\s*\{[^}]*z-index:\s*1100;/s', $cmsCss) === 1
    && str_contains($cmsClient, 'facts.textContent = `${option.inventory_count}');
$checks['showroom switches photos and 360 tour by exact option and keeps shared media as independent fallback'] =
    str_contains($showroom, '$hotel_showroom_option_map[$tourSlot]')
    && str_contains($showroom, '$hotel_showroom_option_map[(string)$slot]')
    && str_contains($showroom, '$hotel_showroom_option_tour_scopes[$scope]')
    && str_contains($showroom, "\$destination['shared_gallery'] = \$destination['gallery'];")
    && str_contains($showroomClient, 'function applyHotelVariantMedia(room, variant)')
    && str_contains($showroomClient, 'variant.gallery.length ? variant.gallery : sharedGallery')
    && str_contains($showroomClient, 'showroomPanoCacheKey(roomId, room, variant)');

$failed = false;
foreach ($checks as $description => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " — {$description}\n";
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
