<?php
require_once 'config/db_connect.php';
require_once 'includes/hotel_rooms.php';
require_once 'includes/media_helper.php';

// 1. Fetch DISTINCT venues by grouping by BOTH Building Name AND Room Type
$venues_query = $conn->query("
    SELECT 
        v.category,
        v.name AS venue_name,
        hr.room_type 
    FROM venues v
    LEFT JOIN hotel_rooms hr ON v.id = hr.venue_id
    WHERE v.status != 'Inactive' AND v.category <> 'Hotel Room'
    GROUP BY 
        v.id,
        v.category, 
        v.name,
        hr.room_type
");

// 2. Setup Base Arrays
$website_slots = [
    'home-hero' => ['title' => 'Landing Page - Hero Banner', 'badge' => 'Homepage', 'type' => 'standard'],
    'home-about' => ['title' => 'Homepage - About/Welcome Photo', 'badge' => 'Homepage', 'type' => 'standard'],
    'home-exp-1' => ['title' => 'Homepage - Experience 1', 'badge' => 'Homepage', 'type' => 'standard'],
    'home-exp-2' => ['title' => 'Homepage - Experience 2', 'badge' => 'Homepage', 'type' => 'standard'],
    'home-exp-3' => ['title' => 'Homepage - Experience 3', 'badge' => 'Homepage', 'type' => 'standard']
];

$venue_standard_slots = []; // Distinct array for venue standard slots
$venue_360_slots = [];
$hotel_gallery_alias_slots = []; // Canonical standard slot => original slot assignments to aggregate for display.
$venue_categories = []; 
$venue_category_filters = [
    'Hotel Room' => 'hotel-room',
    'Event Hall' => 'event-hall',
    'Resort Villa' => 'villa'
];

// Keep the existing event/villa slots, and create one canonical slot per hotel
// building/type. Commercial variants appear in the card details, not as separate galleries.
if ($venues_query) {
    while($v = $venues_query->fetch_assoc()) {
        $display_name = (string)$v['venue_name'];
        
        $clean_name = htmlspecialchars($display_name);
        $category_filter = $venue_category_filters[$v['category']] ?? 'venue';
        
        $slot_key = media_cms_venue_slot_key($display_name);
        
        $venue_categories[$slot_key] = $clean_name;
        
        // Place into dedicated venue_standard_slots array
        $venue_standard_slots[$slot_key] = [
            'title' => $clean_name . ' (Standard Photo)',
            'badge' => $v['category'],
            'type' => 'standard',
            'category_filter' => $category_filter
        ];
        
        // Slot for 360 panorama
        $venue_360_slots[$slot_key . '_360'] = [
            'title' => $clean_name . ' (360 View)',
            'badge' => '360 Panorama',
            'type' => '360',
            'category_filter' => $category_filter,
            'category_badge' => $v['category']
        ];
    }
}

$hotel_group_schema_ready = hotel_group_schema_ready($conn);
$hotel_media_overrides_ready = hotel_group_media_overrides_schema_ready($conn);
$hotel_variant_rows = [];
$hotel_legacy_labels_by_group = [];
$hotel_editor_types = [];
if ($hotel_group_schema_ready) {
    $legacyLabelsResult = $conn->query("SELECT room_group_id, room_type FROM hotel_rooms WHERE room_group_id IS NOT NULL GROUP BY room_group_id, room_type");
    if ($legacyLabelsResult) while ($labelRow = $legacyLabelsResult->fetch_assoc()) {
        $groupId = (int)$labelRow['room_group_id'];
        $label = trim((string)$labelRow['room_type']);
        if ($groupId > 0 && $label !== '') $hotel_legacy_labels_by_group[$groupId][] = $label;
    }
    $hotelOverrideSelect = $hotel_media_overrides_ready
        ? 'MAX(g.standard_media_override_enabled) AS standard_media_override_enabled, MAX(g.tour_media_override_enabled) AS tour_media_override_enabled'
        : '0 AS standard_media_override_enabled, 0 AS tour_media_override_enabled';
    $hotelRowsResult = $conn->query("SELECT g.id AS room_group_id, g.building_name, g.media_slot_key, g.legacy_room_type,
            COALESCE(t.display_name, g.legacy_room_type, 'Hotel Room') AS room_type, g.room_type_code,
            g.base_capacity, g.max_capacity, g.bed_count, g.nightly_rate, g.extra_pax_rate,
            g.check_in_time, g.check_out_time, {$hotelOverrideSelect}, COUNT(DISTINCT h.venue_id) AS inventory_count
        FROM hotel_room_groups g
        LEFT JOIN hotel_room_types t ON t.type_code = g.room_type_code
        INNER JOIN hotel_rooms h ON h.room_group_id = g.id
        INNER JOIN venues v ON v.id = h.venue_id AND v.category = 'Hotel Room' AND v.status <> 'Inactive'
        GROUP BY g.id, g.building_name, g.media_slot_key, g.legacy_room_type, g.room_type_code,
            t.display_name, g.base_capacity, g.max_capacity, g.bed_count, g.nightly_rate,
            g.extra_pax_rate, g.check_in_time, g.check_out_time
        ORDER BY g.building_name, t.sort_order, g.sort_order, g.id");
    if ($hotelRowsResult) while ($hotelRow = $hotelRowsResult->fetch_assoc()) $hotel_variant_rows[] = $hotelRow;
} else {
    $hotelRowsResult = $conn->query("SELECT v.name AS building_name, h.room_type, h.nightly_rate, h.base_capacity,
            h.max_capacity, h.bed_count, h.extra_pax_rate, h.check_in_time, h.check_out_time, COUNT(*) AS inventory_count
        FROM venues v INNER JOIN hotel_rooms h ON h.venue_id = v.id
        WHERE v.category = 'Hotel Room' AND v.status <> 'Inactive'
        GROUP BY v.name, h.room_type, h.nightly_rate, h.base_capacity, h.max_capacity, h.bed_count,
            h.extra_pax_rate, h.check_in_time, h.check_out_time
        ORDER BY v.name, h.room_type, h.nightly_rate, h.max_capacity");
    if ($hotelRowsResult) while ($hotelRow = $hotelRowsResult->fetch_assoc()) $hotel_variant_rows[] = $hotelRow;
}

foreach ($hotel_variant_rows as $hotelRow) {
    $building = trim((string)($hotelRow['building_name'] ?? ''));
    $roomType = trim((string)($hotelRow['room_type'] ?? 'Hotel Room'));
    if ($building === '' || $roomType === '') continue;
    $typeCode = (string)($hotelRow['room_type_code'] ?? '');
    $group = [
        'building_name' => $building,
        'room_type' => $roomType,
        'room_type_code' => $typeCode,
        'legacy_room_type' => (string)($hotelRow['legacy_room_type'] ?? ''),
        'media_slot_key' => (string)($hotelRow['media_slot_key'] ?? ''),
    ];
    $slotKey = hotel_room_group_canonical_media_slot_key($group);
    if ($slotKey === null) continue;
    if (!isset($hotel_editor_types[$slotKey])) {
        $hotel_editor_types[$slotKey] = [
            'canonical_slot' => $slotKey,
            'shared_tour_slot' => $slotKey . '_360',
            'building_name' => $building,
            'room_type' => $roomType,
            'room_type_code' => $typeCode,
            'options' => [],
        ];
    }
    $cleanName = htmlspecialchars($building . ' — ' . $roomType, ENT_QUOTES, 'UTF-8');
    $categoryFilter = 'hotel-room';
    if (!isset($venue_standard_slots[$slotKey])) {
        $venue_categories[$slotKey] = $cleanName;
        $venue_standard_slots[$slotKey] = [
            'title' => $cleanName . ' (Shared Standard Gallery)',
            'badge' => 'Hotel Room',
            'type' => 'standard',
            'category_filter' => $categoryFilter,
            'canonical_room_type' => true,
            'hotel_building_name' => $building,
            'hotel_room_type_code' => $typeCode,
            'hotel_room_type_label' => $roomType,
            'variant_labels' => [],
        ];
        $venue_360_slots[$slotKey . '_360'] = [
            'title' => $cleanName . ' (Shared 360 Gallery)',
            'badge' => '360 Panorama',
            'type' => '360',
            'category_filter' => $categoryFilter,
            'category_badge' => 'Hotel Room',
            'canonical_room_type' => true,
            'variant_labels' => [],
        ];
        $hotel_gallery_alias_slots[$slotKey] = [];
    }
    $variantLabel = hotel_public_variant_label($hotelRow);
    $variantIdentity = is_numeric($hotelRow['room_group_id'] ?? null)
        ? 'group-' . (int)$hotelRow['room_group_id']
        : hotel_commercial_variant_key($hotelRow + ['building_name' => $building, 'legacy_room_type' => $roomType]);
    if (!isset($venue_standard_slots[$slotKey]['_variant_keys'][$variantIdentity])) {
        $venue_standard_slots[$slotKey]['_variant_keys'][$variantIdentity] = true;
        $venue_standard_slots[$slotKey]['variant_labels'][] = $variantLabel;
        $venue_360_slots[$slotKey . '_360']['variant_labels'][] = $variantLabel;
    }
    $groupId = (int)($hotelRow['room_group_id'] ?? 0);
    if ($groupId > 0) {
        $hotel_editor_types[$slotKey]['options'][$groupId] = [
            'room_group_id' => $groupId,
            'label' => $variantLabel,
            'base_capacity' => (int)($hotelRow['base_capacity'] ?? 0),
            'max_capacity' => (int)($hotelRow['max_capacity'] ?? 0),
            'bed_count' => (int)($hotelRow['bed_count'] ?? 0),
            'nightly_rate' => (float)($hotelRow['nightly_rate'] ?? 0),
            'inventory_count' => max(0, (int)($hotelRow['inventory_count'] ?? 0)),
            'standard_override_enabled' => (int)($hotelRow['standard_media_override_enabled'] ?? 0) === 1,
            'tour_override_enabled' => (int)($hotelRow['tour_media_override_enabled'] ?? 0) === 1,
            'standard_slot' => hotel_room_group_option_media_slot_key($groupId),
            'tour_slot' => hotel_room_group_option_media_slot_key($groupId, true),
        ];
    }
    $aliases = hotel_room_group_media_slot_keys($group);
    $groupId = (int)($hotelRow['room_group_id'] ?? 0);
    foreach ($hotel_legacy_labels_by_group[$groupId] ?? [] as $legacyLabel) {
        $aliases = array_merge($aliases, hotel_room_group_media_slot_keys($group + ['legacy_media_room_type' => $legacyLabel]));
    }
    $hotel_gallery_alias_slots[$slotKey] = array_values(array_unique(array_merge($hotel_gallery_alias_slots[$slotKey], $aliases)));
}

foreach ($hotel_editor_types as &$hotelEditorType) {
    $hotelEditorType['options'] = array_values($hotelEditorType['options']);
    usort($hotelEditorType['options'], static fn(array $left, array $right): int =>
        ($left['nightly_rate'] <=> $right['nightly_rate'])
        ?: ($left['max_capacity'] <=> $right['max_capacity'])
        ?: ($left['bed_count'] <=> $right['bed_count'])
        ?: ($left['room_group_id'] <=> $right['room_group_id'])
    );
}
unset($hotelEditorType);

foreach ($venue_standard_slots as &$slotInfo) unset($slotInfo['_variant_keys']);
unset($slotInfo);
foreach ($venue_360_slots as &$slotInfo) unset($slotInfo['_variant_keys']);
unset($slotInfo);

$hotel_photo_preview_slots = [];
foreach ($hotel_editor_types as $canonicalSlot => $editorType) {
    $hotel_photo_preview_slots[$canonicalSlot] = true;
    foreach ($hotel_gallery_alias_slots[$canonicalSlot] ?? [] as $aliasSlot) $hotel_photo_preview_slots[$aliasSlot] = true;
    foreach ($editorType['options'] as $option) {
        $optionSlot = (string)($option['standard_slot'] ?? '');
        if ($optionSlot !== '') $hotel_photo_preview_slots[$optionSlot] = true;
    }
}

// One narrow, stable query powers cards, galleries, and hotspot editing.
$query = "SELECT id, file_name, file_path, media_type, slot_assignment, is_primary,
                 showroom_view_x, showroom_view_y, showroom_view_z, showroom_fov
          FROM media_cms ORDER BY is_primary DESC, id DESC";
$result = $conn->query($query);

$uploaded_media = []; // For the one-to-one homepage system slots
$gallery_items = [];  // General gallery
$standard_venue_photos = []; // Grouped standard photos
$pano_venue_photos = [];     // Grouped 360 panoramas

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        try {
            $row['file_path'] = media_cms_upload_relative_path((string)$row['file_path']);
        } catch (Throwable $e) {
            error_log('CMS media path unavailable for media id ' . (int)$row['id'] . ': ' . $e->getMessage());
            continue;
        }
        try {
            $thumbnail = media_cms_ensure_admin_thumbnail($row['file_path']);
            $thumbnailFile = media_cms_thumbnail_file_path($row['file_path']);
            $row['thumbnail_path'] = $thumbnail['path'];
            $row['thumbnail_version'] = (int)(@filemtime($thumbnailFile) ?: 0);
            $row['thumbnail_width'] = (int)$thumbnail['width'];
            $row['thumbnail_height'] = (int)$thumbnail['height'];
        } catch (Throwable $e) {
            // A failed derivative must never send the full source to a card.
            error_log('CMS thumbnail unavailable for media id ' . (int)$row['id'] . ': ' . $e->getMessage());
            $row['thumbnail_path'] = 'assets/img/placeholder.jpg';
            $row['thumbnail_version'] = 0;
            $row['thumbnail_width'] = MEDIA_CMS_THUMBNAIL_WIDTH;
            $row['thumbnail_height'] = MEDIA_CMS_THUMBNAIL_HEIGHT;
        }
        $slot = $row['slot_assignment'];
        
        if ($slot === 'gallery') {
            $gallery_items[] = $row;
        } elseif (strpos($slot, 'home-') === 0) {
            // Grab the newest record for single slots
            if (!isset($uploaded_media[$slot])) {
                $uploaded_media[$slot] = $row;
            }
        } elseif (strpos($slot, '_360') !== false) {
            $pano_venue_photos[$slot][] = $row;
        } else {
            $standard_venue_photos[$slot][] = $row;
        }
    }
}

// Keep the exact per-slot panorama sets intact for hotspot editing. The
// canonical management gallery below is an aggregate and may cross slots.
$hotel_source_pano_venue_photos = $pano_venue_photos;
$hotel_source_pano_venue_photos_ordered = [];
foreach ($hotel_source_pano_venue_photos as $slot => $photos) {
    usort($photos, static fn(array $left, array $right): int =>
        ((int)$right['is_primary'] <=> (int)$left['is_primary']) ?: ((int)$left['id'] <=> (int)$right['id'])
    );
    $hotel_source_pano_venue_photos_ordered[$slot] = $photos;
}
$hotel_gallery_hotspot_sources = [];
foreach ($hotel_gallery_alias_slots as $canonical_slot => $alias_slots) {
    $hotel_gallery_hotspot_sources[$canonical_slot] = hotel_room_group_source_panorama_galleries(
        $alias_slots,
        $hotel_source_pano_venue_photos_ordered
    );
}

// Present every historical/explicit source gallery under the canonical type
// card while preserving each photo's actual slot_assignment for management.
foreach ($hotel_gallery_alias_slots as $canonical_slot => $alias_slots) {
    $standardById = [];
    $panoramasById = [];
    foreach ($alias_slots as $alias_slot) {
        foreach ($standard_venue_photos[$alias_slot] ?? [] as $photo) {
            $standardById[(int)$photo['id']] = $photo;
        }
        $panoSlot = $alias_slot . '_360';
        foreach ($pano_venue_photos[$panoSlot] ?? [] as $photo) {
            $panoramasById[(int)$photo['id']] = $photo;
        }
    }
    $canonicalStandard = array_values($standardById);
    usort($canonicalStandard, static fn(array $left, array $right): int => hotel_public_media_candidate_compare(
        ['id' => (int)$left['id'], 'is_primary' => (int)$left['is_primary'], 'path' => (string)$left['file_path']],
        ['id' => (int)$right['id'], 'is_primary' => (int)$right['is_primary'], 'path' => (string)$right['file_path']]
    ));
    $canonicalPanoramas = array_values($panoramasById);
    usort($canonicalPanoramas, static fn(array $left, array $right): int =>
        ((int)$right['is_primary'] <=> (int)$left['is_primary']) ?: ((int)$left['id'] <=> (int)$right['id'])
    );
    $standard_venue_photos[$canonical_slot] = $canonicalStandard;
    $pano_venue_photos[$canonical_slot . '_360'] = $canonicalPanoramas;
}

// Keep a PHP-only ordered view for the card's Hotspots button. The editor
// derives the same order from galleryData, so no duplicate panorama payload is
// emitted and View 1/2/3 stays aligned with showroom.php's pano_urls order.
$pano_venue_photos_ordered = [];
foreach ($pano_venue_photos as $slot => $photos) {
    usort($photos, static function (array $left, array $right): int {
        $primaryOrder = (int)$right['is_primary'] <=> (int)$left['is_primary'];
        return $primaryOrder !== 0 ? $primaryOrder : ((int)$left['id'] <=> (int)$right['id']);
    });
    $pano_venue_photos_ordered[$slot] = $photos;
}

$cms_gallery_slots = array_merge($standard_venue_photos, $pano_venue_photos);
$cms_card_previews_by_id = [];
$hotel_preview_sizes = '(max-width: 700px) calc(84vw - 2rem), (max-width: 1000px) calc(50vw - 4rem), 430px';
// Wider responsive sources are only for standard hotel photos; tours stay on bounded admin thumbnails.
foreach ($hotel_photo_preview_slots as $previewSlot => $unused) {
    foreach ($standard_venue_photos[$previewSlot] ?? [] as $photo) {
        $mediaId = (int)$photo['id'];
        if (isset($cms_card_previews_by_id[$mediaId])) continue;
        $preview = media_cms_card_image_data((string)$photo['file_path'], $hotel_preview_sizes);
        if ($preview['srcset'] !== '') {
            $cms_card_previews_by_id[$mediaId] = [
                'src' => (string)$preview['src'],
                'srcset' => (string)$preview['srcset'],
                'sizes' => (string)$preview['sizes'],
            ];
        }
    }
}

$cms_gallery_data = [];
foreach ($cms_gallery_slots as $slot => $photos) {
    $cms_gallery_data[$slot] = array_map(static function (array $photo) use ($cms_card_previews_by_id): array {
        $preview = $cms_card_previews_by_id[(int)$photo['id']] ?? null;
        return [
            'id' => (int)$photo['id'],
            'file_name' => (string)$photo['file_name'],
            'file_path' => (string)$photo['file_path'],
            'is_primary' => (int)$photo['is_primary'],
            'thumbnail_path' => (string)$photo['thumbnail_path'],
            'thumbnail_version' => (int)$photo['thumbnail_version'],
            'thumbnail_width' => (int)$photo['thumbnail_width'],
            'thumbnail_height' => (int)$photo['thumbnail_height'],
            'card_preview_src' => (string)($preview['src'] ?? ''),
            'card_preview_srcset' => (string)($preview['srcset'] ?? ''),
            'card_preview_sizes' => (string)($preview['sizes'] ?? ''),
            'showroom_view_x' => $photo['showroom_view_x'],
            'showroom_view_y' => $photo['showroom_view_y'],
            'showroom_view_z' => $photo['showroom_view_z'],
            'showroom_fov' => $photo['showroom_fov'],
            'slot_assignment' => (string)$photo['slot_assignment'],
        ];
    }, $photos);
}
?>

<script>
window.galleryData = <?php echo json_encode($cms_gallery_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
window.hotelGalleryEditorData = <?php echo json_encode(array_values($hotel_editor_types), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
window.hotelGalleryOverridesAvailable = <?php echo $hotel_media_overrides_ready ? 'true' : 'false'; ?>;
window.hotelSourcePanoData = <?php
$hotel_source_pano_payload = [];
foreach ($hotel_gallery_hotspot_sources as $source_galleries) foreach ($source_galleries as $source_gallery) {
    $source_pano_slot = $source_gallery['slot'];
    $hotel_source_pano_payload[$source_pano_slot] = array_map(static fn(array $photo): array => [
            'id' => (int)$photo['id'],
            'file_name' => (string)$photo['file_name'],
            'file_path' => (string)$photo['file_path'],
            'is_primary' => (int)$photo['is_primary'],
            'slot_assignment' => (string)$photo['slot_assignment'],
            'showroom_view_x' => $photo['showroom_view_x'],
            'showroom_view_y' => $photo['showroom_view_y'],
            'showroom_view_z' => $photo['showroom_view_z'],
            'showroom_fov' => $photo['showroom_fov'],
        ], $source_gallery['photos']);
}
foreach ($hotel_editor_types as $editor_type) foreach ($editor_type['options'] as $option) {
    $source_pano_slot = (string)($option['tour_slot'] ?? '');
    if ($source_pano_slot === '' || isset($hotel_source_pano_payload[$source_pano_slot])) continue;
    $option_panoramas = $pano_venue_photos_ordered[$source_pano_slot] ?? [];
    if (!$option_panoramas) continue;
    $hotel_source_pano_payload[$source_pano_slot] = array_map(static fn(array $photo): array => [
        'id' => (int)$photo['id'], 'file_name' => (string)$photo['file_name'],
        'file_path' => (string)$photo['file_path'], 'is_primary' => (int)$photo['is_primary'],
        'slot_assignment' => (string)$photo['slot_assignment'],
        'showroom_view_x' => $photo['showroom_view_x'], 'showroom_view_y' => $photo['showroom_view_y'],
        'showroom_view_z' => $photo['showroom_view_z'], 'showroom_fov' => $photo['showroom_fov'],
    ], $option_panoramas);
}
echo json_encode($hotel_source_pano_payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>;
</script>

<div class="cms-container">
    <div class="cms-toolbar">
        <div class="cms-filter-groups">
            <div class="cms-filter-row">
                <span class="cms-filter-label" id="cms-category-filter-label">Category</span>
                <div class="cms-category-filters" role="group" aria-labelledby="cms-category-filter-label">
                    <button type="button" class="cms-category-pill active" data-category="venues" aria-pressed="true">All Venues</button>
                    <button type="button" class="cms-category-pill" data-category="hotel-room" aria-pressed="false">Hotel Rooms</button>
                    <button type="button" class="cms-category-pill" data-category="event-hall" aria-pressed="false">Event Halls</button>
                    <button type="button" class="cms-category-pill" data-category="villa" aria-pressed="false">Villas</button>
                    <button type="button" class="cms-category-pill" data-category="website" aria-pressed="false">Website &amp; Gallery</button>
                </div>
            </div>
            <div class="cms-filter-row">
                <span class="cms-filter-label" id="cms-media-filter-label">Media type</span>
                <div class="cms-filters" role="group" aria-labelledby="cms-media-filter-label">
                    <button type="button" class="cms-pill active" data-filter="all" aria-pressed="true">All Media</button>
                    <button type="button" class="cms-pill" data-filter="360" aria-pressed="false">360 Showroom</button>
                    <button type="button" class="cms-pill" data-filter="standard" aria-pressed="false">Standard Photos</button>
                </div>
            </div>
        </div>
        <div class="cms-controls">
            <button class="btn btn-primary" id="btnOpenUpload">+ Upload Media</button>
        </div>
    </div>

    <!-- Media Grid -->
    <div class="cms-grid" id="cms-grid-container">

        <!-- 1. SYSTEM SLOTS (Hero Banner & Welcome Photo) -->
        <?php foreach($website_slots as $slot_key => $slot_info): 
            $has_img = isset($uploaded_media[$slot_key]);
        ?>
        <div class="cms-card" data-type="standard" data-category="website">
            <div class="cms-img-wrapper"
                style="background:#e0e0e0; display:flex; align-items:center; justify-content:center;">
                <?php if ($has_img): ?>
                <?php echo media_cms_card_image_markup($uploaded_media[$slot_key], $slot_info['title']); ?>
                <?php else: ?>
                <span style="color:#888;">Empty Slot</span>
                <?php endif; ?>
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title"><?php echo $slot_info['title']; ?></h4>
                    <span class="badge badge-gray"><?php echo $slot_info['badge']; ?></span>
                </div>
                <div class="cms-actions" style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button class="btn btn-primary btn-cms-modal" data-slot="<?php echo $slot_key; ?>" data-type="standard" style="padding: 8px 16px; font-size: 0.85rem; flex: 1;">
                        <?php echo $has_img ? 'Replace' : 'Upload'; ?>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Hotel room types use one editor per building and canonical room type. -->
        <?php foreach ($hotel_editor_types as $canonical_slot => $editor_type):
            $shared_standard = $standard_venue_photos[$canonical_slot] ?? [];
            $shared_tour_slot = $canonical_slot . '_360';
            $shared_panoramas = $pano_venue_photos_ordered[$shared_tour_slot] ?? [];
            $first_shared = $shared_standard[0] ?? null;
            $hotelTitle = (string)$editor_type['building_name'] . ' — ' . (string)$editor_type['room_type'];
            $sharedHotspotSources = $hotel_gallery_hotspot_sources[$canonical_slot] ?? [];
            $sharedHotspotOptions = [];
            foreach ($sharedHotspotSources as $sourceGallery) {
                $sourceKey = preg_replace('/_360$/', '', (string)$sourceGallery['slot']);
                $sourceKey = preg_replace('/^venue_/', '', $sourceKey);
                $sourceLabel = preg_match('/^hotel_type_[a-f0-9]{40}$/', $sourceKey)
                    ? 'Shared gallery'
                    : ucwords(str_replace('_', ' ', $sourceKey)) . ' photos';
                $sharedHotspotOptions[] = ['slot' => $sourceGallery['slot'], 'label' => $sourceLabel];
            }
        ?>
        <article class="cms-card cms-hotel-type-card<?php echo $first_shared ? '' : ' cms-hotel-type-card-empty'; ?>" data-type="hotel" data-types="standard 360" data-category="hotel-room"
            data-hotel-canonical-slot="<?php echo htmlspecialchars($canonical_slot, ENT_QUOTES, 'UTF-8'); ?>"
            data-hotel-building="<?php echo htmlspecialchars((string)$editor_type['building_name'], ENT_QUOTES, 'UTF-8'); ?>"
            data-hotel-room-type-code="<?php echo htmlspecialchars((string)$editor_type['room_type_code'], ENT_QUOTES, 'UTF-8'); ?>"
            data-hotel-room-type="<?php echo htmlspecialchars((string)$editor_type['room_type'], ENT_QUOTES, 'UTF-8'); ?>">
            <div class="cms-img-wrapper<?php echo $first_shared ? '' : ' cms-hotel-empty-cover'; ?>">
                <?php if ($first_shared): ?>
                    <?php echo media_cms_card_image_markup($first_shared, $hotelTitle); ?>
                <?php else: ?>
                    <div class="cms-hotel-empty-preview">
                        <span class="cms-hotel-empty-icon" aria-hidden="true"><i class="fa-solid fa-images"></i></span>
                        <span class="cms-hotel-empty-copy">
                            <strong>No shared photos yet</strong>
                            <span>Fallback gallery for room options</span>
                        </span>
                        <button type="button" class="btn cms-hotel-add-shared" aria-label="Add shared photos for <?php echo htmlspecialchars($hotelTitle, ENT_QUOTES, 'UTF-8'); ?>">Add photos</button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="cms-card-content">
                <div class="cms-hotel-card-header">
                    <div class="cms-hotel-card-title-group">
                        <p class="cms-hotel-building"><?php echo htmlspecialchars((string)$editor_type['building_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <h4 class="cms-title"><?php echo htmlspecialchars((string)$editor_type['room_type'], ENT_QUOTES, 'UTF-8'); ?></h4>
                    </div>
                    <span class="cms-hotel-option-count"><?php echo count($editor_type['options']); ?> <?php echo count($editor_type['options']) === 1 ? 'option' : 'options'; ?></span>
                </div>
                <p class="cms-hotel-gallery-summary">
                    <span><?php echo count($shared_standard); ?> photos</span>
                    <span><?php echo count($shared_panoramas); ?> tour scenes</span>
                </p>
                <button type="button" class="btn btn-primary cms-open-hotel-editor" data-default-tab="photos">
                    Edit galleries
                </button>
            </div>
            <div class="cms-hotel-hidden-actions" hidden aria-hidden="true">
                <button type="button" class="btn-cms-modal" data-type="standard" data-slot="<?php echo htmlspecialchars($canonical_slot, ENT_QUOTES, 'UTF-8'); ?>"></button>
                <button type="button" class="btn-cms-modal" data-type="360" data-slot="<?php echo htmlspecialchars($shared_tour_slot, ENT_QUOTES, 'UTF-8'); ?>"></button>
                <button type="button" class="btn-manage-gallery" data-media-type="standard" data-slot="<?php echo htmlspecialchars($canonical_slot, ENT_QUOTES, 'UTF-8'); ?>" data-hotel-shared="true"></button>
                <button type="button" class="btn-manage-gallery" data-media-type="360" data-slot="<?php echo htmlspecialchars($shared_tour_slot, ENT_QUOTES, 'UTF-8'); ?>"></button>
                <?php if ($sharedHotspotOptions): ?>
                <button type="button" class="btn-place-hotspots"
                    data-slot="<?php echo htmlspecialchars((string)$sharedHotspotOptions[0]['slot'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-gallery-title="<?php echo htmlspecialchars($hotelTitle . ' shared 360 tour', ENT_QUOTES, 'UTF-8'); ?>"
                    data-gallery-sources="<?php echo htmlspecialchars(json_encode($sharedHotspotOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>"></button>
                <?php endif; ?>
                <?php foreach ($editor_type['options'] as $option):
                    $optionStandardPhotos = $standard_venue_photos[$option['standard_slot']] ?? [];
                    $optionPanoramas = $hotel_source_pano_venue_photos_ordered[$option['tour_slot']] ?? [];
                ?>
                <button type="button" class="btn-cms-modal" data-type="standard" data-slot="<?php echo htmlspecialchars($option['standard_slot'], ENT_QUOTES, 'UTF-8'); ?>"></button>
                <button type="button" class="btn-cms-modal" data-type="360" data-slot="<?php echo htmlspecialchars($option['tour_slot'], ENT_QUOTES, 'UTF-8'); ?>"></button>
                <button type="button" class="btn-manage-gallery" data-media-type="standard" data-slot="<?php echo htmlspecialchars($option['standard_slot'], ENT_QUOTES, 'UTF-8'); ?>"></button>
                <button type="button" class="btn-manage-gallery" data-media-type="360" data-slot="<?php echo htmlspecialchars($option['tour_slot'], ENT_QUOTES, 'UTF-8'); ?>"></button>
                    <?php if ($optionPanoramas): ?>
                <button type="button" class="btn-place-hotspots" data-slot="<?php echo htmlspecialchars($option['tour_slot'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-gallery-title="<?php echo htmlspecialchars($hotelTitle . ' · ' . $option['label'] . ' tour', ENT_QUOTES, 'UTF-8'); ?>"
                    data-gallery-sources="<?php echo htmlspecialchars(json_encode([['slot' => $option['tour_slot'], 'label' => 'Room option tour']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>"></button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </article>
        <?php endforeach; ?>

        <!-- 2. 360 PANORAMA SLOTS -->
        <?php foreach($venue_360_slots as $slot_key => $slot_info): 
            if (!empty($slot_info['canonical_room_type'])) continue;
            $photos_array = isset($pano_venue_photos[$slot_key]) ? $pano_venue_photos[$slot_key] : [];
            $photo_count = count($photos_array);
            $has_img = $photo_count > 0;
            // Use the primary-first ascending-ID view for the card and hotspot button.
            $ordered_photos_array = isset($pano_venue_photos_ordered[$slot_key]) ? $pano_venue_photos_ordered[$slot_key] : $photos_array;
            $first_photo = $has_img ? $ordered_photos_array[0] : null;
        ?>
        <div class="cms-card" data-type="360" data-category="<?php echo htmlspecialchars($slot_info['category_filter'], ENT_QUOTES, 'UTF-8'); ?>">
            <div class="cms-img-wrapper"
                style="background:#e0e0e0; display:flex; align-items:center; justify-content:center;">
                <?php if ($has_img): ?>
                <?php echo media_cms_card_image_markup($first_photo, $slot_info['title']); ?>
                <?php else: ?>
                <span style="color:#888;">Empty Slot</span>
                <?php endif; ?>
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title"><?php echo $slot_info['title']; ?></h4>
                    <span class="badge badge-gold">
                        <?php echo $has_img ? $photo_count . ' Panoramas' : $slot_info['category_badge']; ?>
                    </span>
                </div>
                <?php if (!empty($slot_info['canonical_room_type'])): ?>
                <p class="cms-size">Shared by <?php echo count($slot_info['variant_labels']); ?> booking option<?php echo count($slot_info['variant_labels']) === 1 ? '' : 's'; ?><?php if (!empty($slot_info['variant_labels'])): ?>: <?php echo htmlspecialchars(implode(' · ', $slot_info['variant_labels']), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></p>
                <?php endif; ?>
                <?php if (!$has_img): ?>
                <p class="cms-size">No 360 view uploaded yet.</p>
                <?php else: ?>
                <p class="cms-size">360 Virtual Tour Active</p>
                <?php endif; ?>

                <div class="cms-actions" style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button class="btn btn-primary btn-cms-modal" data-slot="<?php echo $slot_key; ?>" data-type="360" style="padding: 8px 16px; font-size: 0.85rem; flex: 1;">
                        <?php echo $has_img ? 'Add More' : 'Upload'; ?>
                    </button>
                    <?php if ($has_img): ?>
                    <button class="btn btn-outline btn-manage-gallery" data-slot="<?php echo $slot_key; ?>"
                        style="padding: 8px 16px; font-size: 0.85rem; flex: 1;">Manage</button>
                    <?php if (!empty($slot_info['canonical_room_type'])): ?>
                        <?php
                            $canonical_slot = substr($slot_key, 0, -4);
                            $hotspot_sources = $hotel_gallery_hotspot_sources[$canonical_slot] ?? [];
                            $hotspot_source_options = [];
                            foreach ($hotspot_sources as $source_gallery) {
                                $source_label_key = preg_replace('/_360$/', '', (string)$source_gallery['slot']);
                                $source_label_key = preg_replace('/^venue_/', '', $source_label_key);
                                if (preg_match('/^hotel_type_[a-f0-9]{40}$/', $source_label_key)) {
                                    $source_label = 'Canonical gallery';
                                } else {
                                    $source_label = ucwords(str_replace('_', ' ', $source_label_key)) . ' gallery';
                                }
                                $hotspot_source_options[] = ['slot' => $source_gallery['slot'], 'label' => $source_label];
                            }
                            $hotspot_gallery_title = preg_replace('/ \(Shared 360 Gallery\)$/', '', (string)$slot_info['title']);
                        ?>
                        <?php if ($hotspot_source_options): ?>
                        <button class="btn btn-outline btn-place-hotspots"
                            data-slot="<?php echo htmlspecialchars($hotspot_source_options[0]['slot'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-gallery-title="<?php echo htmlspecialchars($hotspot_gallery_title, ENT_QUOTES, 'UTF-8'); ?>"
                            data-gallery-sources="<?php echo htmlspecialchars(json_encode($hotspot_source_options, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>"
                            aria-label="Edit hotspots for <?php echo htmlspecialchars($hotspot_gallery_title, ENT_QUOTES, 'UTF-8'); ?>"
                            style="padding: 8px 16px; font-size: 0.85rem; flex: 1;">
                            <i class="fa-solid fa-map-pin" aria-hidden="true"></i> Hotspots</button>
                        <?php endif; ?>
                    <?php else: ?>
                    <button class="btn btn-outline btn-place-hotspots" data-media-id="<?php echo $ordered_photos_array[0]['id']; ?>"
                        data-slot="<?php echo $slot_key; ?>"
                        style="padding: 8px 16px; font-size: 0.85rem; flex: 1;">
                        <i class="fa-solid fa-map-pin"></i> Hotspots</button>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- 3. STANDARD VENUE PHOTOS -->
        <!-- FIX: Iterate via $venue_standard_slots (includes empty ones), not just photos that exist -->
        <?php foreach($venue_standard_slots as $slot_key => $slot_info): 
            if (!empty($slot_info['canonical_room_type'])) continue;
            $photos_array = isset($standard_venue_photos[$slot_key]) ? $standard_venue_photos[$slot_key] : [];
            $photo_count = count($photos_array);
            $has_img = $photo_count > 0;
            $first_photo = $has_img ? $photos_array[0] : null;
        ?>
        <div class="cms-card" data-type="standard" data-category="<?php echo htmlspecialchars($slot_info['category_filter'], ENT_QUOTES, 'UTF-8'); ?>"<?php if (!empty($slot_info['canonical_room_type'])): ?> data-hotel-building="<?php echo htmlspecialchars((string)$slot_info['hotel_building_name'], ENT_QUOTES, 'UTF-8'); ?>" data-hotel-room-type-code="<?php echo htmlspecialchars((string)$slot_info['hotel_room_type_code'], ENT_QUOTES, 'UTF-8'); ?>" data-hotel-room-type="<?php echo htmlspecialchars((string)$slot_info['hotel_room_type_label'], ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
            <div class="cms-img-wrapper"
                style="background:#e0e0e0; display:flex; align-items:center; justify-content:center;">
                <?php if ($has_img): ?>
                <?php echo media_cms_card_image_markup($first_photo, $slot_info['title']); ?>
                <?php else: ?>
                <span style="color:#888;">Empty Slot</span>
                <?php endif; ?>
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title"><?php echo $slot_info['title']; ?></h4>
                    <span
                        class="badge badge-gray"><?php echo $has_img ? $photo_count . ' Photos' : $slot_info['badge']; ?></span>
                </div>
                <?php if (!empty($slot_info['canonical_room_type'])): ?>
                <p class="cms-size">Shared by <?php echo count($slot_info['variant_labels']); ?> booking option<?php echo count($slot_info['variant_labels']) === 1 ? '' : 's'; ?><?php if (!empty($slot_info['variant_labels'])): ?>: <?php echo htmlspecialchars(implode(' · ', $slot_info['variant_labels']), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></p>
                <?php endif; ?>
                <?php if (!$has_img): ?>
                <p class="cms-size">No standard photos uploaded yet.</p>
                <?php else: ?>
                <p class="cms-size">Standard Photo Gallery</p>
                <?php endif; ?>

                <div class="cms-actions" style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button class="btn btn-primary btn-cms-modal" data-slot="<?php echo $slot_key; ?>" data-type="standard" style="padding: 8px 16px; font-size: 0.85rem; flex: 1;">
                        <?php echo $has_img ? 'Add More' : 'Upload'; ?>
                    </button>
                    <?php if ($has_img): ?>
                    <button class="btn btn-outline btn-manage-gallery" data-slot="<?php echo $slot_key; ?>"
                        style="padding: 8px 16px; font-size: 0.85rem; flex: 1;">Manage</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- 4. GENERAL GALLERY ITEMS -->
        <?php foreach($gallery_items as $item): ?>
        <div class="cms-card" data-type="<?php echo htmlspecialchars((string)$item['media_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" data-category="website">
            <div class="cms-img-wrapper">
                <?php echo media_cms_card_image_markup($item, (string)$item['file_name']); ?>
            </div>
            <div class="cms-card-content">
                <div class="cms-card-header">
                    <h4 class="cms-title">General Gallery</h4>
                    <span class="badge badge-gray">Unassigned</span>
                </div>
                <p class="cms-size">File: <?php echo htmlspecialchars($item['file_name']); ?></p>
                <div class="cms-actions">
                    <button class="btn-delete btn-delete-media" data-id="<?php echo $item['id']; ?>">Delete</button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

    </div>
    <p class="cms-empty-state" id="cms-empty-state" role="status" hidden>No media slots match these filters.</p>
</div>

<div class="cms-modal-overlay hotel-editor-overlay" id="hotelGalleryEditor" hidden>
    <section class="cms-modal-content hotel-editor-content" role="dialog" aria-modal="true" aria-labelledby="hotel-editor-title" tabindex="-1">
        <header class="hotel-editor-header">
            <div>
                <h2 id="hotel-editor-title">Room galleries</h2>
                <p id="hotel-editor-subtitle">Choose a gallery, then select Photos or 360 Tour.</p>
            </div>
            <button type="button" class="btn cms-btn-outline hotel-editor-close" aria-label="Close room gallery editor">Close</button>
        </header>
        <div class="hotel-editor-scope">
            <label for="hotel-gallery-scope">Gallery for</label>
            <select id="hotel-gallery-scope" aria-describedby="hotel-gallery-scope-help">
                <option value="shared">Shared default</option>
            </select>
            <p id="hotel-gallery-scope-help">Shared galleries are used when a room option has no photos or tour of its own.</p>
            <p id="hotel-options-migration-notice" class="hotel-editor-notice" hidden>Room-option galleries need migration 032. Shared room photos and tours are still available.</p>
        </div>
        <div class="hotel-editor-tabs" role="tablist" aria-label="Gallery media type">
            <button type="button" role="tab" id="hotel-tab-photos" aria-controls="hotel-panel-photos" aria-selected="true" tabindex="0" data-hotel-tab="photos">Photos</button>
            <button type="button" role="tab" id="hotel-tab-tour" aria-controls="hotel-panel-tour" aria-selected="false" tabindex="-1" data-hotel-tab="tour">360 Tour</button>
        </div>
        <div class="hotel-editor-body">
            <section id="hotel-panel-photos" role="tabpanel" aria-labelledby="hotel-tab-photos" data-hotel-panel="photos" tabindex="0">
                <div id="hotel-gallery-photos-panel"></div>
            </section>
            <section id="hotel-panel-tour" role="tabpanel" aria-labelledby="hotel-tab-tour" data-hotel-panel="tour" tabindex="0" hidden>
                <div id="hotel-gallery-tour-panel"></div>
            </section>
        </div>
        <footer class="hotel-editor-footer">
            <button type="button" class="btn btn-primary hotel-editor-close">Done</button>
        </footer>
    </section>
</div>

<!-- ==============================================
     MODALS 
     ============================================== -->

<!-- 1. UPLOAD MODAL -->
<div class="cms-modal-overlay" id="uploadModal">
    <div class="cms-modal-content">
        <h3 class="cms-modal-title">Upload Website Media</h3>
        <form class="cms-form" id="cms-upload-form">
            <div class="cms-drag-drop" id="dragDropArea">
                <i class="fa-solid fa-cloud-arrow-up drop-icon"></i>
                <p class="drop-text"><strong>Drag and drop</strong> images here<br>or <span class="highlight">Click to
                        browse</span></p>
                <input type="file" id="fileInput" accept="image/jpeg, image/png, image/webp" multiple hidden>
            </div>

            <div class="cms-form-group">
                <label>Media Type</label>
                <select name="media_type" id="modal-media-type" required>
                    <option value="" disabled selected>Select media type...</option>
                    <option value="standard">Standard Photo (Multiple Allowed)</option>
                    <option value="360">360 Panorama (Multiple Allowed)</option>
                </select>
            </div>

            <div class="cms-form-group">
                <label>Assign to Website Slot</label>
                <select name="website_slot" id="modal-website-slot" required>
                    <option value="" disabled selected>Select where this image goes...</option>

                    <optgroup label="System & Gallery">
                        <option value="home-hero" data-type="standard" style="display:none;">Landing Page - Hero Banner
                        </option>
                        <option value="home-about" data-type="standard" style="display:none;">Homepage - About/Welcome
                            Photo</option>
                        <option value="gallery" data-type="standard" style="display:none;">General Gallery (Standard)
                        </option>
                        <option value="gallery" data-type="360" style="display:none;">General Gallery (360)</option>
                    </optgroup>

                    <optgroup label="Resort Venues">
                        <!-- FIX: Iterate over correct Standard Venue array inside dropdown -->
                        <?php foreach($venue_standard_slots as $key => $slot): ?>
                        <option value="<?php echo $key; ?>" data-type="standard" style="display:none;">
                            <?php echo $slot['title']; ?>
                        </option>
                        <?php endforeach; ?>

                        <?php foreach($venue_360_slots as $key => $slot): ?>
                        <option value="<?php echo $key; ?>" data-type="360" style="display:none;">
                            <?php echo $slot['title']; ?>
                        </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php if ($hotel_editor_types): ?>
                    <optgroup label="Room option galleries">
                        <?php foreach ($hotel_editor_types as $editor_type): foreach ($editor_type['options'] as $option): ?>
                        <option value="<?php echo htmlspecialchars($option['standard_slot'], ENT_QUOTES, 'UTF-8'); ?>" data-type="standard" style="display:none;">
                            <?php echo htmlspecialchars($editor_type['building_name'] . ' — ' . $editor_type['room_type'] . ' · ' . $option['label'] . ' · Own photos', ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                        <option value="<?php echo htmlspecialchars($option['tour_slot'], ENT_QUOTES, 'UTF-8'); ?>" data-type="360" style="display:none;">
                            <?php echo htmlspecialchars($editor_type['building_name'] . ' — ' . $editor_type['room_type'] . ' · ' . $option['label'] . ' · Own 360 tour', ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                        <?php endforeach; endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                </select>
            </div>

            <div id="upload-progress-container" style="display: none; margin-bottom: 15px;">
                <div
                    style="display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 0.85rem; font-weight: 600;">
                    <span style="color: var(--color-dark);">Uploading...</span>
                    <span id="upload-progress-text" style="color: var(--color-gold);">0%</span>
                </div>
                <div style="width: 100%; background: #eee; border-radius: 10px; height: 8px; overflow: hidden;">
                    <div id="upload-progress-bar"
                        style="width: 100%; height: 100%; background: var(--color-gold); transform-origin: left center; transform: scaleX(0); transition: transform 0.2s;"></div>
                </div>
            </div>

            <div class="cms-modal-actions">
                <button type="button" class="btn cms-btn-outline" id="btnCloseModal">Cancel</button>
                <button type="submit" class="btn btn-primary">Upload</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. MANAGE GALLERY MODAL -->
<div class="cms-modal-overlay" id="manageGalleryModal">
    <div class="cms-modal-content manage-gallery-content" style="max-width: 800px;">
        <h3 class="cms-modal-title" id="mg-title">Manage Gallery</h3>
        <p id="mg-hint" class="cms-size" hidden></p>

        <!-- NEW: BULK DELETE CONTROLS -->
        <div id="mg-bulk-controls" class="manage-gallery-bulk"
            style="display: none; justify-content: space-between; align-items: center; margin-bottom: 15px; background: #f9f9f9; padding: 10px 15px; border-radius: 6px; border: 1px solid #e0e0e0;">
            <label
                style="cursor:pointer; font-weight:600; display:flex; align-items:center; gap:8px; color: var(--color-dark);">
                <input type="checkbox" id="mg-select-all" style="transform:scale(1.2);"> Select All
            </label>
            <button type="button" class="btn" style="background:#c75c5c; color:#fff; border:none; opacity:0.5;"
                id="btn-mg-bulk-delete" disabled>
                <i class="fa-solid fa-trash"></i> Delete Selected (<span id="mg-sel-count">0</span>)
            </button>
        </div>

        <div id="mg-grid" class="manage-gallery-grid"
            style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; max-height: 50vh; overflow-y: auto; padding-right: 5px; margin-bottom: 20px;">
            <!-- JavaScript will inject photos here -->
        </div>
        <p id="mg-empty-state" class="cms-size" hidden></p>

        <div class="cms-modal-actions manage-gallery-actions" style="justify-content: space-between;">
            <button type="button" class="btn cms-btn-outline" id="btnCloseGalleryModal">Close</button>
            <button type="button" class="btn btn-primary" id="btn-mg-add">Add Photos</button>
        </div>
    </div>
</div>

<!-- 3. LIGHTBOX FOR FULLSCREEN VIEWING -->
<div id="cms-lightbox"
    style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 100000; display: none; align-items: center; justify-content: center; cursor: zoom-out;">
    <img id="cms-lightbox-img"
        style="max-width: 90%; max-height: 90vh; object-fit: contain; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.5);">
</div>

<!-- 4. UNIVERSAL CONFIRM MODAL -->
<div class="cms-modal-overlay" id="uniConfirmModal" style="z-index: 9999;">
    <div class="cms-modal-content" style="max-width: 400px; text-align: center;">
        <i class="fa-solid fa-circle-question"
            style="font-size: 3rem; color: var(--color-gold); margin-bottom: 15px;"></i>
        <h3 class="cms-modal-title" style="margin-bottom: 10px;">Confirm Action</h3>
        <p id="uc-message" style="color: var(--color-dark-light); font-size: 0.95rem; margin-bottom: 25px;">Are you
            sure?</p>
        <div style="display: flex; gap: 10px;">
            <button class="btn cms-btn-outline" id="uc-btn-no" style="flex: 1;">No, Cancel</button>
            <button class="btn btn-primary" id="uc-btn-yes" style="flex: 1;">Yes, Proceed</button>
        </div>
    </div>
</div>

<!-- 5. UNIVERSAL ALERT MODAL -->
<div class="cms-modal-overlay" id="uniAlertModal" style="z-index: 10000;">
    <div class="cms-modal-content" style="max-width: 400px; text-align: center;">
        <i id="ua-icon" class="fa-solid fa-circle-info"
            style="font-size: 3rem; color: var(--color-gold); margin-bottom: 15px;"></i>
        <h3 class="cms-modal-title" id="ua-title" style="margin-bottom: 10px;">Notice</h3>
        <p id="ua-message" style="color: var(--color-dark-light); font-size: 0.95rem; margin-bottom: 25px;">Message goes
            here.</p>
        <div style="display: flex; gap: 10px;">
            <button class="btn btn-primary" id="ua-btn-ok" style="flex: 1;">OK</button>
        </div>
    </div>
</div>

<!-- 6. TOUR SETUP & HOTSPOTS MODAL -->
<div class="cms-modal-overlay" id="hotspotModal" style="z-index: 5000;">
    <div class="cms-modal-content hotspot-modal-content" role="dialog" aria-modal="true" aria-labelledby="hotspot-modal-title" tabindex="-1">
        <div class="hotspot-modal-header">
            <div class="hotspot-modal-header-copy">
                <h3 class="cms-modal-title" id="hotspot-modal-title">Tour Setup &amp; Hotspots</h3>
                <p>Choose a starting scene and saved view, then place guest directions and information pins.</p>
            </div>
            <div class="hotspot-modal-header-actions">
                <button type="button" class="btn cms-btn-outline" id="btn-hotspot-help" aria-label="Replay tour setup guide"><i class="fa-solid fa-circle-question" aria-hidden="true"></i><span>Help</span></button>
                <button type="button" class="btn cms-btn-outline" id="btnCloseHotspotModal" aria-label="Close tour setup"><i class="fa-solid fa-xmark" aria-hidden="true"></i><span>Close</span></button>
            </div>
        </div>

        <div class="hotspot-modal-body">
            <div class="hotspot-steps" aria-label="Tour setup steps">
                <section class="hotspot-step" data-hotspot-step="1" aria-labelledby="hotspot-step-1-title">
                    <h4 id="hotspot-step-1-title"><span class="hotspot-step-number" aria-hidden="true">1</span><span>Choose panorama</span></h4>
                    <div class="hotspot-view-selector" id="hs-source-gallery-picker" hidden>
                        <label for="hs-admin-gallery-selector">Gallery source</label>
                        <select id="hs-admin-gallery-selector" aria-describedby="hs-gallery-description"></select>
                        <p id="hs-gallery-description" class="hotspot-view-description">Choose which existing gallery to edit. Each gallery keeps its own saved hotspots and navigation targets.</p>
                    </div>
                    <div class="hotspot-view-selector">
                        <label for="hs-admin-view-selector">Tour panorama</label>
                        <select id="hs-admin-view-selector" aria-describedby="hs-view-description"></select>
                        <p id="hs-view-description" class="hotspot-view-description" aria-live="polite"></p>
                    </div>
                </section>
                <section class="hotspot-step" data-hotspot-step="2" aria-labelledby="hotspot-step-2-title">
                    <h4 id="hotspot-step-2-title"><span class="hotspot-step-number" aria-hidden="true">2</span><span>Set guest view</span></h4>
                    <div class="hotspot-tour-status" aria-live="polite" aria-atomic="true">
                        <span id="hs-starting-scene-status" class="hotspot-state-pill">Starting scene</span>
                        <span id="hs-view-preset-status" class="hotspot-state-pill">View not set</span>
                    </div>
                    <div class="hotspot-tour-actions">
                        <button type="button" class="hotspot-btn hotspot-btn-primary" id="btn-save-panorama-view" disabled><span data-hotspot-button-label>Save current guest view</span></button>
                        <button type="button" class="hotspot-btn hotspot-btn-secondary" id="btn-make-starting-scene"><span data-hotspot-button-label>Make starting scene</span></button>
                        <details class="hotspot-more-menu">
                            <summary>View options</summary>
                            <div class="hotspot-more-actions">
                                <button type="button" class="hotspot-btn hotspot-btn-secondary" id="btn-preview-saved-view" disabled><span data-hotspot-button-label>Preview saved view</span></button>
                                <button type="button" class="hotspot-btn hotspot-btn-secondary" id="btn-clear-panorama-view" disabled><span data-hotspot-button-label>Reset saved view</span></button>
                            </div>
                        </details>
                    </div>
                </section>
                <section class="hotspot-step" data-hotspot-step="3" aria-labelledby="hotspot-step-3-title">
                    <h4 id="hotspot-step-3-title"><span class="hotspot-step-number" aria-hidden="true">3</span><span>Place and manage hotspots</span></h4>
                    <div class="hotspot-step-support">
                        <span id="hs-hotspot-count" class="hotspot-state-pill">0 hotspots</span>
                        <p>Double-click or double-tap the panorama to place an information or walk marker. Review its details, then save it from the form.</p>
                    </div>
                </section>
            </div>

            <p id="hs-editor-status" class="hotspot-editor-status" role="status" aria-live="polite" aria-atomic="true">Choose a panorama to begin editing its tour.</p>

            <div class="hotspot-editor-stage">
                <div class="hotspot-pano-panel">
                    <div id="hotspot-pano-container" aria-label="360 degree panorama preview"></div>
                    <div id="hotspot-loading" role="status" aria-live="polite">
                        <i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i>
                        <span id="hotspot-loading-message">Loading panorama…</span>
                        <button type="button" class="hotspot-btn hotspot-btn-secondary" id="btn-retry-hotspot-view" hidden><span data-hotspot-button-label>Retry panorama</span></button>
                    </div>
                </div>

                <div class="hotspot-sidebar">
                    <div id="hotspot-form-wrapper" class="hotspot-form hidden">
                        <h4 class="hotspot-form-heading"><span id="hs-form-heading">New Hotspot</span><span class="hotspot-form-state" id="hs-form-state">New pin</span></h4>
                        <div class="hotspot-field">
                            <label for="hs-type">Type</label>
                            <select id="hs-type">
                                <option value="info">Information pin</option>
                                <option value="nav">Walk to another view</option>
                            </select>
                        </div>
                        <div class="hotspot-field">
                            <label for="hs-title">Guest label</label>
                            <input type="text" id="hs-title" maxlength="150" placeholder="e.g. Poolside entrance" autocomplete="off">
                        </div>
                        <div class="hotspot-field" id="hs-desc-wrapper">
                            <label for="hs-description">Description</label>
                            <textarea id="hs-description" rows="3" maxlength="5000" placeholder="Shown when a guest opens this pin"></textarea>
                        </div>
                        <div class="hotspot-field hidden" id="hs-target-wrapper">
                            <label for="hs-target-index">Destination</label>
                            <select id="hs-target-index"></select>
                        </div>
                        <fieldset class="hotspot-field hotspot-rotation hidden" id="hs-arrow-rotation-wrapper">
                            <legend>Arrow direction</legend>
                            <label for="hs-arrow-rotation-range">Rotate the walk marker</label>
                            <input type="range" id="hs-arrow-rotation-range" min="0" max="359" step="1" value="0" aria-label="Rotate walk marker from 0 to 359 degrees">
                            <div class="hotspot-rotation-value">
                                <label for="hs-arrow-rotation">Degrees</label>
                                <input type="number" id="hs-arrow-rotation" min="0" max="359" step="1" value="0" inputmode="numeric">
                                <button type="button" class="hotspot-btn hotspot-btn-secondary" id="btn-reset-arrow-rotation">Reset</button>
                            </div>
                        </fieldset>
                        <div class="hotspot-form-actions">
                            <button type="button" class="hotspot-btn hotspot-btn-secondary" id="btn-cancel-hotspot">Cancel</button>
                            <button type="button" class="hotspot-btn hotspot-btn-primary" id="btn-save-hotspot"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i><span id="hs-save-label" data-hotspot-button-label>Save Pin</span></button>
                        </div>
                    </div>
                    <section class="hotspot-list-section" aria-labelledby="hotspot-list-title">
                        <h4 id="hotspot-list-title">Placed Hotspots</h4>
                        <div id="hotspot-list" aria-live="polite" aria-busy="false">
                            <p class="hotspot-empty-state">No hotspots placed yet. Double-click or double-tap the panorama to add one.</p>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="hotspot-tour-toast" class="hotspot-tour-toast" role="status" aria-live="polite" aria-atomic="true">
    <span class="hotspot-tour-toast-icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
    <span class="hotspot-tour-toast-copy">
        <strong data-tour-toast-message></strong>
        <span data-tour-toast-detail hidden></span>
    </span>
</div>
