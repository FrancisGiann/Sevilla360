<?php
$page_title = '360° Virtual Tour of M.I. Sevilla Resort | Sevilla360';
$page_description = 'Explore the M.I. Sevilla Resort virtual showroom, browse available event halls, hotel rooms and villas, and view resort spaces in 360°.';
$extra_css = 'assets/css/showroom.css';
$extra_js = [
    'assets/js/panorama-view-compat.js',
    'assets/js/hotspot-material.js',
    'assets/js/guide_tours.js',
    'assets/js/receptionist-chat.js',
    'assets/js/showroom.js',
];
$active_page = 'showroom';

require_once 'config/db_connect.php';
require_once 'includes/showroom_tour.php';
require_once 'includes/hotel_rooms.php';
require_once 'includes/media_helper.php';

$natural_receptionist_enabled = trim((string)($_ENV['RECEPTIONIST_NATURAL_HYBRID_ENABLED'] ?? getenv('RECEPTIONIST_NATURAL_HYBRID_ENABLED') ?: '')) === '1';

$hotel_group_schema_ready = hotel_group_schema_ready($conn);
$hotel_media_overrides_ready = $hotel_group_schema_ready && hotel_group_media_overrides_schema_ready($conn);
$hotel_room_group_select = $hotel_group_schema_ready ? 'hr.room_group_id' : 'NULL';
$hotel_type_code_select = $hotel_group_schema_ready ? 'hrg.room_type_code' : 'NULL';
$hotel_type_code_group = $hotel_group_schema_ready ? ', hrg.room_type_code, hr.room_group_id' : '';
$hotel_type_catalog_join = $hotel_group_schema_ready
    ? ' LEFT JOIN hotel_room_groups hrg ON hrg.id = hr.room_group_id LEFT JOIN hotel_room_types hrt ON hrt.type_code = hrg.room_type_code'
    : '';
$hotel_building_select = $hotel_group_schema_ready ? 'COALESCE(hrg.building_name, v.name)' : 'v.name';
$hotel_building_group = $hotel_group_schema_ready ? ', hrg.building_name' : '';
$hotel_canonical_type_select = $hotel_group_schema_ready ? 'COALESCE(hrt.display_name, hr.room_type)' : 'hr.room_type';
$hotel_group_media_select = $hotel_group_schema_ready
    ? "hrg.media_slot_key, hrg.legacy_room_type, NULL AS legacy_media_room_types,
        hrg.base_capacity, hrg.max_capacity, hrg.bed_count AS group_bed_count,
        hrg.nightly_rate AS group_nightly_rate, hrg.extra_pax_rate, hrg.check_in_time, hrg.check_out_time,
        " . ($hotel_media_overrides_ready
            ? 'MAX(hrg.standard_media_override_enabled) AS standard_media_override_enabled, MAX(hrg.tour_media_override_enabled) AS tour_media_override_enabled'
            : '0 AS standard_media_override_enabled, 0 AS tour_media_override_enabled')
    : 'NULL AS media_slot_key, hr.room_type AS legacy_room_type, hr.room_type AS legacy_media_room_type, hr.room_type AS legacy_media_room_types, hr.base_capacity AS base_capacity, hr.max_capacity AS max_capacity, hr.bed_count AS group_bed_count, hr.nightly_rate AS group_nightly_rate, hr.extra_pax_rate AS extra_pax_rate, hr.check_in_time AS check_in_time, hr.check_out_time AS check_out_time';
$hotel_group_by = $hotel_group_schema_ready
    ? ', hrg.media_slot_key, hrg.legacy_room_type, hrg.base_capacity, hrg.max_capacity, hrg.bed_count, hrg.nightly_rate, hrg.extra_pax_rate, hrg.check_in_time, hrg.check_out_time'
    : ', hr.base_capacity, hr.max_capacity, hr.bed_count, hr.nightly_rate, hr.extra_pax_rate, hr.check_in_time, hr.check_out_time';
$hotel_type_active_filter = $hotel_group_schema_ready ? " AND (v.category <> 'Hotel Room' OR hrt.active = 1)" : '';

// 1. Fetch all venues
$venues_query = $conn->query("
    SELECT 
        v.id, v.category, v.name AS venue_name, {$hotel_building_select} AS building_name, v.status, v.description, v.amenities,
        {$hotel_canonical_type_select} AS room_type, {$hotel_room_group_select} AS room_group_id, {$hotel_type_code_select} AS room_type_code,
        {$hotel_group_media_select}, hr.room_type AS inventory_legacy_room_type,
        MIN(hr.bed_count) AS min_bed_count, MAX(hr.bed_count) AS max_bed_count,
        COUNT(hr.venue_id) AS inventory_count,
        eh.base_rate AS eh_rate, eh.max_capacity AS eh_cap,
        vi.day_rate AS vi_rate, vi.max_capacity AS vi_cap
    FROM venues v
    LEFT JOIN hotel_rooms hr ON v.id = hr.venue_id
    {$hotel_type_catalog_join}
    LEFT JOIN event_halls eh ON v.id = eh.venue_id
    LEFT JOIN villas vi ON v.id = vi.venue_id
    WHERE v.status = 'Available'{$hotel_type_active_filter}
    GROUP BY v.id, v.category, v.name, hr.room_type {$hotel_type_code_group}{$hotel_building_group}{$hotel_group_by}
    ORDER BY v.id ASC, hr.room_type ASC
");

$showroom_data = [];
$hotel_showroom_slot_map = []; // [actual media slot => all variant destination IDs sharing the canonical room type]
$hotel_showroom_option_map = []; // [deterministic option slot => exact destination and room group]
$hotel_showroom_option_tour_scopes = []; // [private panorama scope => exact destination and variant key]

if ($venues_query) {
    while($v = $venues_query->fetch_assoc()) {
        $buildingName = $v['category'] === 'Hotel Room' ? (string)($v['building_name'] ?? $v['venue_name']) : (string)$v['venue_name'];
        $display_name = ($v['category'] === 'Hotel Room' && !empty($v['room_type'])) ? $buildingName . ' - ' . $v['room_type'] : $v['venue_name'];
        $safe_id = $v['category'] === 'Hotel Room'
            ? 'hotel_' . substr(hotel_public_room_type_key($buildingName, (string)($v['room_type_code'] ?? ''), (string)($v['room_type'] ?? '')), 0, 32)
            : substr(media_cms_venue_slot_key($display_name), strlen('venue_'));
        
        $cap = 'N/A'; $rate = 'N/A';
        $capacity_value = null; $rate_value = null; $beds_value = null;
        $beds = '';
        if ($v['category'] === 'Hotel Room') {
            $capacity_value = is_numeric($v['max_capacity']) ? (int)$v['max_capacity'] : null;
            $rate_value = is_numeric($v['group_nightly_rate'] ?? $v['nightly_rate'] ?? null) ? (float)($v['group_nightly_rate'] ?? $v['nightly_rate']) : null;
            $cap = $capacity_value !== null ? $capacity_value . ' pax' : 'N/A';
            $rate = $rate_value !== null ? '₱' . number_format($rate_value) . ' /night' : 'N/A';
            $minBeds = (int)($v['min_bed_count'] ?? 0); $maxBeds = (int)($v['max_bed_count'] ?? 0);
            $beds_value = $maxBeds > 0 ? $maxBeds : ($minBeds > 0 ? $minBeds : null);
            $beds = $beds_value === null ? '' : ($minBeds === $maxBeds ? $minBeds . ' ' . ($minBeds === 1 ? 'bed' : 'beds') : $minBeds . '–' . $maxBeds . ' beds');

            $commercial_key = hotel_commercial_variant_key([
                'building_name' => $buildingName,
                'room_type_code' => (string)($v['room_type_code'] ?? ''),
                'legacy_room_type' => (string)($v['room_type_code'] ?? '') === '' ? (string)($v['room_type'] ?? '') : '',
                'base_capacity' => (int)($v['base_capacity'] ?? 0),
                'max_capacity' => (int)($v['max_capacity'] ?? 0),
                'bed_count' => (int)($v['group_bed_count'] ?? $beds_value ?? 0),
                'nightly_rate' => $rate_value ?? 0,
                'extra_pax_rate' => (float)($v['extra_pax_rate'] ?? 0),
                'check_in_time' => (string)($v['check_in_time'] ?? '14:00:00'),
                'check_out_time' => (string)($v['check_out_time'] ?? '12:00:00'),
            ]);
            $variant_label = hotel_public_variant_label([
                'base_capacity' => (int)($v['base_capacity'] ?? 0),
                'max_capacity' => (int)($v['max_capacity'] ?? 0),
                'bed_count' => $beds_value ?? 0,
                'nightly_rate' => $rate_value,
            ]);
            $variant = [
                'room_group_id' => is_numeric($v['room_group_id'] ?? null) ? (int)$v['room_group_id'] : null,
                'variant_key' => $commercial_key,
                'room_type_code' => (string)($v['room_type_code'] ?? ''),
                'label' => $variant_label,
                'base_capacity' => (int)($v['base_capacity'] ?? 0),
                'max_capacity' => (int)($v['max_capacity'] ?? 0),
                'bed_count' => (int)($v['group_bed_count'] ?? $beds_value ?? 0),
                'nightly_rate' => $rate_value,
                'extra_pax_rate' => (float)($v['extra_pax_rate'] ?? 0),
                'check_in_time' => (string)($v['check_in_time'] ?? ''),
                'check_out_time' => (string)($v['check_out_time'] ?? ''),
                'inventory_count' => max(0, (int)($v['inventory_count'] ?? 0)),
                'standard_media_override_enabled' => (int)($v['standard_media_override_enabled'] ?? 0) === 1,
                'tour_media_override_enabled' => (int)($v['tour_media_override_enabled'] ?? 0) === 1,
            ];
            $room_group_id = $variant['room_group_id'];
            $variant_map_key = $commercial_key . ($room_group_id !== null ? '#group:' . $room_group_id : '');
            $variant['variant_map_key'] = $variant_map_key;
            $variant['_own_gallery_candidates'] = [];
            $variant['_own_tour'] = [
                'pano_urls' => [], 'pano_media_ids' => [], 'showroom_views' => [],
                'hotspots_by_pano_index' => [], 'legacy_pano_media_ids' => [],
            ];
            $media_group = [
                'building_name' => $buildingName,
                'room_type' => (string)($v['room_type'] ?? ''),
                'room_type_code' => (string)($v['room_type_code'] ?? ''),
                'legacy_room_type' => (string)($v['legacy_room_type'] ?? ''),
                'legacy_media_room_types' => (string)($v['legacy_media_room_types'] ?? $v['inventory_legacy_room_type'] ?? ''),
                'media_slot_key' => (string)($v['media_slot_key'] ?? ''),
            ];
            foreach (hotel_room_group_media_slot_keys($media_group) as $media_slot) {
                $hotel_showroom_slot_map[$media_slot][] = $safe_id;
            }

            if (!isset($showroom_data[$safe_id])) {
                $showroom_data[$safe_id] = [
                    'id' => $safe_id,
                    'venue_id' => $v['id'],
                    'review_key' => hotel_public_review_key(null, $buildingName, (string)($v['room_type'] ?? '')),
                    'title' => strtoupper($display_name),
                    'category' => $v['category'],
                    'room_type' => $v['room_type'] ?? '',
                    'room_type_code' => $v['room_type_code'] ?? null,
                    'venue_name' => $buildingName,
                    'status' => $v['status'],
                    'description' => !empty($v['description']) ? $v['description'] : 'Explore this hotel room in the Sevilla360 showroom.',
                    'amenities' => !empty($v['amenities']) ? explode(',', $v['amenities']) : [],
                    'variants' => [],
                    '_variant_map' => [],
                    'pano_url' => '',
                    'gallery' => [],
                    'gallery_candidates' => [],
                ];
            }
            if (isset($showroom_data[$safe_id]['_variant_map'][$variant_map_key])) {
                $showroom_data[$safe_id]['_variant_map'][$variant_map_key]['inventory_count'] += $variant['inventory_count'];
            } else {
                $showroom_data[$safe_id]['_variant_map'][$variant_map_key] = $variant;
            }
            if ($room_group_id !== null) {
                $standardSlot = hotel_room_group_option_media_slot_key($room_group_id);
                $tourSlot = hotel_room_group_option_media_slot_key($room_group_id, true);
                if ($standardSlot !== null) $hotel_showroom_option_map[$standardSlot] = ['destination_id' => $safe_id, 'variant_key' => $variant_map_key, 'kind' => 'standard'];
                if ($tourSlot !== null) {
                    $scope = 'variant_tour_' . $room_group_id;
                    $hotel_showroom_option_map[$tourSlot] = ['destination_id' => $safe_id, 'variant_key' => $variant_map_key, 'kind' => '360', 'scope' => $scope];
                    $hotel_showroom_option_tour_scopes[$scope] = ['destination_id' => $safe_id, 'variant_key' => $variant_map_key];
                }
            }
            continue;
        } else {
            $variant_label = '';
        }
        if ($v['category'] === 'Event Hall') {
            $capacity_value = is_numeric($v['eh_cap']) ? (int)$v['eh_cap'] : null;
            $rate_value = is_numeric($v['eh_rate']) ? (float)$v['eh_rate'] : null;
            $cap = $capacity_value !== null ? $capacity_value . ' pax' : 'N/A';
            $rate = $rate_value !== null ? '₱' . number_format($rate_value) . ' /day' : 'N/A';
        }
        if ($v['category'] === 'Resort Villa') {
            $capacity_value = is_numeric($v['vi_cap']) ? (int)$v['vi_cap'] : null;
            $rate_value = is_numeric($v['vi_rate']) ? (float)$v['vi_rate'] : null;
            $cap = $capacity_value !== null ? $capacity_value . ' pax' : 'N/A';
            $rate = $rate_value !== null ? '₱' . number_format($rate_value) . ' /day' : 'N/A';
        }

        // Keep fallback copy factual and category-safe when CMS description is absent.
        $category_fallback = match ($v['category']) {
            'Event Hall' => 'event space',
            'Hotel Room' => 'hotel room',
            'Resort Villa' => 'resort villa',
            default => 'venue'
        };
        $desc = !empty($v['description']) ? $v['description'] : "Explore this $category_fallback in the Sevilla360 showroom.";
        $amenities = !empty($v['amenities']) ? explode(',', $v['amenities']) : [];

        $showroom_data[$safe_id] = [
            'id' => $safe_id,
            'venue_id' => $v['id'],
            'review_key' => $v['category'] === 'Hotel Room'
                ? hotel_public_review_key($v['room_group_id'] ?? null, (string)$v['venue_name'], (string)($v['room_type'] ?? ''))
                : (strtolower((string)$v['category']) === 'event hall' ? 'event-' : 'villa-') . (int)$v['id'],
            'title' => strtoupper($display_name),
            'category' => $v['category'],
            'room_type' => $v['room_type'] ?? '',
            'room_group_id' => is_numeric($v['room_group_id'] ?? null) ? (int)$v['room_group_id'] : null,
            'room_type_code' => $v['room_type_code'] ?? null,
            'variant_label' => $variant_label,
            'venue_name' => $v['venue_name'],
            'capacity' => $cap,
            'capacity_value' => $capacity_value,
            'capacity_numeric' => $capacity_value,
            'beds' => $beds,
            'beds_value' => $beds_value,
            'beds_numeric' => $beds_value,
            'rate' => $rate,
            'rate_value' => $rate_value,
            'rate_numeric' => $rate_value,
            'status' => $v['status'],
            'description' => $desc,
            'amenities' => $amenities,
            'pano_url' => '', 
            'gallery' => []   
        ];
    }
}

// Collapse commercial variants into one canonical room destination while
// keeping every exact group/key available for booking.
foreach ($showroom_data as &$destination) {
    if (($destination['category'] ?? '') !== 'Hotel Room') continue;
    $destination['variants'] = array_values($destination['_variant_map'] ?? []);
    usort($destination['variants'], static fn(array $left, array $right): int =>
        ((float)($left['nightly_rate'] ?? 0) <=> (float)($right['nightly_rate'] ?? 0))
        ?: ((int)($left['max_capacity'] ?? 0) <=> (int)($right['max_capacity'] ?? 0))
        ?: ((int)($left['bed_count'] ?? 0) <=> (int)($right['bed_count'] ?? 0))
    );
    $rates = array_values(array_filter(array_map(static fn(array $variant): ?float => is_numeric($variant['nightly_rate'] ?? null) ? (float)$variant['nightly_rate'] : null, $destination['variants']), static fn(?float $value): bool => $value !== null));
    $baseCapacities = array_values(array_filter(array_map(static fn(array $variant): int => (int)($variant['base_capacity'] ?? 0), $destination['variants']), static fn(int $value): bool => $value > 0));
    $maxCapacities = array_values(array_filter(array_map(static fn(array $variant): int => (int)($variant['max_capacity'] ?? 0), $destination['variants']), static fn(int $value): bool => $value > 0));
    $beds = array_values(array_filter(array_map(static fn(array $variant): int => (int)($variant['bed_count'] ?? 0), $destination['variants']), static fn(int $value): bool => $value > 0));
    $destination['inventory_count'] = array_sum(array_map(static fn(array $variant): int => (int)($variant['inventory_count'] ?? 0), $destination['variants']));
    $destination['rate_value'] = $rates ? min($rates) : null;
    $destination['rate_max_value'] = $rates ? max($rates) : null;
    if ($rates) {
        $rateLabel = '₱' . number_format(min($rates));
        if (min($rates) !== max($rates)) $rateLabel .= '–₱' . number_format(max($rates));
        $destination['rate'] = $rateLabel . ' /night';
    } else {
        $destination['rate'] = 'N/A';
    }
    $destination['capacity_value'] = $maxCapacities ? max($maxCapacities) : null;
    $destination['capacity'] = $baseCapacities && $maxCapacities
        ? (min($baseCapacities) === max($maxCapacities) ? min($baseCapacities) : min($baseCapacities) . '–' . max($maxCapacities)) . ' pax'
        : 'N/A';
    $destination['beds_value'] = $beds ? max($beds) : null;
    $destination['beds'] = $beds ? (min($beds) === max($beds) ? min($beds) : min($beds) . '–' . max($beds)) . ' ' . (max($beds) === 1 ? 'bed' : 'beds') : '';
    $destination['variant_summary'] = count($destination['variants']) . ' room option' . (count($destination['variants']) === 1 ? '' : 's')
        . ($destination['capacity'] !== 'N/A' ? ' · ' . $destination['capacity'] : '')
        . ($destination['beds'] !== '' ? ' · ' . $destination['beds'] : '')
        . ($destination['rate'] !== 'N/A' ? ' · from ' . $destination['rate'] : '');
}
unset($destination);
$hotel_showroom_slot_map = array_map(static fn(array $ids): array => array_values(array_unique($ids)), $hotel_showroom_slot_map);

// 2. Fetch Media from CMS and attach to the correct showroom venue
// Primary panoramas are shown first; stable media IDs keep navigation intact.
$media_query = $conn->query("SELECT id, slot_assignment, file_path, file_name, media_type, is_primary, showroom_view_x, showroom_view_y, showroom_view_z, showroom_fov FROM media_cms ORDER BY is_primary DESC, id ASC");

$pano_index_map = []; // [ base_id => [ media_id => pano_url_index ] ]
$legacy_pano_media_ids = []; // [ base_id => media IDs in historical id-ascending order ]
$legacy_pano_media_ids_by_slot = []; // Variant destination -> original media slot -> historical IDs
$pano_source_slot_by_media_id = []; // Stable IDs preserve each hotspot graph's original slot boundary

if ($media_query) {
    while ($m = $media_query->fetch_assoc()) {
        $slot = $m['slot_assignment'];
        $mediaId = (int)$m['id'];
        $sourceAvailable = media_cms_source_file_available((string)($m['file_path'] ?? ''));

        if ($m['media_type'] === '360' && str_ends_with((string)$slot, '_360')) {
            $base_id = substr((string)$slot, 0, -4);
            $optionSource = $hotel_showroom_option_map[(string)$slot] ?? null;
            if (is_array($optionSource) && ($optionSource['kind'] ?? '') === '360') {
                $scope = (string)$optionSource['scope'];
                $legacy_pano_media_ids[$scope][] = $mediaId;
                $legacy_pano_media_ids_by_slot[$scope][$base_id][] = $mediaId;
                $pano_source_slot_by_media_id[$mediaId] = $base_id;
                if (!$sourceAvailable) continue;
                $variantTour =& $showroom_data[$optionSource['destination_id']]['_variant_map'][$optionSource['variant_key']]['_own_tour'];
                $variantTour['pano_urls'][] = $m['file_path'];
                $variantTour['pano_media_ids'][] = (int)$m['id'];
                if ($m['showroom_view_x'] !== null && $m['showroom_view_y'] !== null && $m['showroom_view_z'] !== null && $m['showroom_fov'] !== null) {
                    $variantTour['showroom_views'][(string)(int)$m['id']] = [
                        'x' => (float)$m['showroom_view_x'], 'y' => (float)$m['showroom_view_y'],
                        'z' => (float)$m['showroom_view_z'], 'fov' => (float)$m['showroom_fov'],
                    ];
                }
                $pano_index_map[$scope][(int)$m['id']] = count($variantTour['pano_urls']) - 1;
                unset($variantTour);
                continue;
            }
            $destination_ids = showroom_tour_media_destination_ids((string)$slot, '360', $showroom_data, $hotel_showroom_slot_map);
            foreach (array_values(array_unique($destination_ids)) as $destination_id) {
                if (!isset($showroom_data[$destination_id])) continue;
                if (isset($pano_index_map[$destination_id][(int)$m['id']])) continue;
                if (!isset($showroom_data[$destination_id]['pano_urls'])) {
                    $showroom_data[$destination_id]['pano_urls'] = [];
                    $showroom_data[$destination_id]['pano_media_ids'] = [];
                }
                if (!isset($pano_index_map[$destination_id])) $pano_index_map[$destination_id] = [];
                $legacy_pano_media_ids[$destination_id] ??= [];
                $legacy_pano_media_ids_by_slot[$destination_id][$base_id] ??= [];
                $legacy_pano_media_ids[$destination_id][] = $mediaId;
                $legacy_pano_media_ids_by_slot[$destination_id][$base_id][] = $mediaId;
                $pano_source_slot_by_media_id[$mediaId] = $base_id;
                if (!$sourceAvailable) continue;
                $current_index = count($showroom_data[$destination_id]['pano_urls']);
                $showroom_data[$destination_id]['pano_urls'][] = $m['file_path'];
                $showroom_data[$destination_id]['pano_media_ids'][] = (int)$m['id'];
                if ($m['showroom_view_x'] !== null && $m['showroom_view_y'] !== null && $m['showroom_view_z'] !== null && $m['showroom_fov'] !== null) {
                    if (!isset($showroom_data[$destination_id]['showroom_views'])) $showroom_data[$destination_id]['showroom_views'] = [];
                    $showroom_data[$destination_id]['showroom_views'][(string)(int)$m['id']] = [
                        'x' => (float)$m['showroom_view_x'],
                        'y' => (float)$m['showroom_view_y'],
                        'z' => (float)$m['showroom_view_z'],
                        'fov' => (float)$m['showroom_fov'],
                    ];
                }
                $pano_index_map[$destination_id][(int)$m['id']] = $current_index;
            }
        }
        elseif ($m['media_type'] === 'standard') {
            if (!$sourceAvailable) continue;
            $optionSource = $hotel_showroom_option_map[(string)$slot] ?? null;
            if (is_array($optionSource) && ($optionSource['kind'] ?? '') === 'standard') {
                $path = (string)$m['file_path'];
                $candidate = ['id' => (int)$m['id'], 'is_primary' => (int)$m['is_primary'], 'path' => $path];
                $optionVariant =& $showroom_data[$optionSource['destination_id']]['_variant_map'][$optionSource['variant_key']];
                $optionVariant['_own_gallery_candidates'][$path] = $candidate;
                unset($optionVariant);
                continue;
            }
            $base_id = preg_match('/^venue_/', $slot) && strpos($slot, '_std') === false
                ? preg_replace('/^venue_/', '', $slot)
                : $slot;
            $destination_ids = showroom_tour_media_destination_ids((string)$slot, 'standard', $showroom_data, $hotel_showroom_slot_map);
            foreach (array_values(array_unique($destination_ids)) as $destination_id) {
                if (!isset($showroom_data[$destination_id])) continue;
                if (($showroom_data[$destination_id]['category'] ?? '') === 'Hotel Room') {
                    $path = (string)$m['file_path'];
                    $candidate = ['id' => (int)$m['id'], 'is_primary' => (int)$m['is_primary'], 'path' => $path];
                    $previous = $showroom_data[$destination_id]['gallery_candidates'][$path] ?? null;
                    if ($previous === null || hotel_public_media_candidate_compare($candidate, $previous) < 0) {
                        $showroom_data[$destination_id]['gallery_candidates'][$path] = $candidate;
                    }
                } elseif (!in_array($m['file_path'], $showroom_data[$destination_id]['gallery'], true)) {
                    $showroom_data[$destination_id]['gallery'][] = $m['file_path'];
                }
            }
        }
    }
}

foreach ($showroom_data as &$destination) {
    if (($destination['category'] ?? '') !== 'Hotel Room') continue;
    $galleryCandidates = array_values($destination['gallery_candidates'] ?? []);
    usort($galleryCandidates, 'hotel_public_media_candidate_compare');
    $destination['gallery'] = array_column($galleryCandidates, 'path');
    $destination['shared_gallery'] = $destination['gallery'];
    unset($destination['gallery_candidates']);
}
unset($destination);

// Older hotspot records stored a positional index against the historical
// id-ascending panorama list.  Keep that legacy list separate from the
// current primary-first display order so a primary flag change cannot retarget
// an existing navigation pin.
foreach ($legacy_pano_media_ids_by_slot as &$source_slots) {
    foreach ($source_slots as &$legacy_ids) sort($legacy_ids, SORT_NUMERIC);
    unset($legacy_ids);
}
unset($source_slots);
foreach ($legacy_pano_media_ids as $base_id => $legacy_ids) {
    sort($legacy_ids, SORT_NUMERIC);
    if (isset($hotel_showroom_option_tour_scopes[$base_id])) {
        $scope = $hotel_showroom_option_tour_scopes[$base_id];
        $showroom_data[$scope['destination_id']]['_variant_map'][$scope['variant_key']]['_own_tour']['legacy_pano_media_ids'] = $legacy_ids;
    } elseif (isset($showroom_data[$base_id])) {
        $showroom_data[$base_id]['legacy_pano_media_ids'] = $legacy_ids;
    }
}

// 3. Fetch hotspots and group by venue + pano index
$hotspots_query = $conn->query("
    SELECT id, media_id, type, title, description, position_x, position_y, position_z, target_pano_index, target_media_id, arrow_rotation
    FROM showroom_hotspots
");
if ($hotspots_query) {
    while ($h = $hotspots_query->fetch_assoc()) {
        $media_id = $h['media_id'];
        foreach ($pano_index_map as $base_id => $media_to_index) {
            $optionTourScope = $hotel_showroom_option_tour_scopes[$base_id] ?? null;
            if (isset($media_to_index[$media_id])) {
                $resolved_target_media_id = null;
                if ($h['type'] === 'nav') {
                    $source_slot = $pano_source_slot_by_media_id[(int)$media_id] ?? '';
                    $legacy_ids = $legacy_pano_media_ids_by_slot[$base_id][$source_slot] ?? [];
                    // Retain historical IDs above for positional resolution, but only
                    // link to scenes that are actually available to the guest.
                    $venue_media_ids = array_values(array_unique(array_map('intval', array_keys($media_to_index))));
                    $resolved_target_media_id = showroom_tour_resolve_target_media_id(
                        (int)$media_id,
                        $h['target_media_id'],
                        $h['target_pano_index'],
                        $legacy_ids,
                        $venue_media_ids
                    );
                    // Broken navigation references remain visible in the admin
                    // editor but must never create dead controls for guests.
                    if ($resolved_target_media_id === null) {
                        break;
                    }
                }

                $pano_index = $media_to_index[$media_id];
                if (is_array($optionTourScope)) {
                    $hotspotTarget =& $showroom_data[$optionTourScope['destination_id']]['_variant_map'][$optionTourScope['variant_key']]['_own_tour']['hotspots_by_pano_index'];
                } else {
                    if (!isset($showroom_data[$base_id]['hotspots_by_pano_index'])) $showroom_data[$base_id]['hotspots_by_pano_index'] = [];
                    $hotspotTarget =& $showroom_data[$base_id]['hotspots_by_pano_index'];
                }
                if (!isset($hotspotTarget[$pano_index])) $hotspotTarget[$pano_index] = [];
                $hotspotTarget[$pano_index][] = [
                    'id' => $h['id'],
                    'type' => $h['type'],
                    'title' => $h['title'],
                    'description' => $h['description'],
                    'position_x' => $h['position_x'],
                    'position_y' => $h['position_y'],
                    'position_z' => $h['position_z'],
                    'target_pano_index' => $h['target_pano_index'],
                    'target_media_id' => $resolved_target_media_id ?? $h['target_media_id'],
                    'arrow_rotation' => max(0, min(359, (int)$h['arrow_rotation'])),
                ];
                unset($hotspotTarget);
                break;
            }
        }
    }
}

// Preserve the shared gallery/tour on the parent destination, then attach the
// effective, independently-fallbacked media to each exact commercial option.
foreach ($showroom_data as &$destination) {
    if (($destination['category'] ?? '') !== 'Hotel Room') continue;
    $destination['shared_tour'] = [
        'pano_urls' => $destination['pano_urls'] ?? [],
        'pano_media_ids' => $destination['pano_media_ids'] ?? [],
        'showroom_views' => $destination['showroom_views'] ?? [],
        'hotspots_by_pano_index' => $destination['hotspots_by_pano_index'] ?? [],
        'legacy_pano_media_ids' => $destination['legacy_pano_media_ids'] ?? [],
    ];
    foreach ($destination['_variant_map'] as &$variant) {
        $ownCandidates = array_values($variant['_own_gallery_candidates'] ?? []);
        $ownCandidates = array_values(array_filter($ownCandidates, static function (array $candidate): bool {
            try { media_cms_upload_file_path((string)($candidate['path'] ?? ''), true); return true; }
            catch (Throwable $error) { return false; }
        }));
        usort($ownCandidates, 'hotel_public_media_candidate_compare');
        $ownGallery = array_column($ownCandidates, 'path');
        $ownTour = $variant['_own_tour'] ?? [];
        $ownTourUrls = [];
        $ownTourIds = [];
        $ownTourViews = [];
        $oldToNewIndexes = [];
        foreach (($ownTour['pano_urls'] ?? []) as $oldIndex => $path) {
            try { media_cms_upload_file_path((string)$path, true); }
            catch (Throwable $error) { continue; }
            $newIndex = count($ownTourUrls);
            $oldToNewIndexes[(int)$oldIndex] = $newIndex;
            $ownTourUrls[] = $path;
            $ownTourIds[] = (int)($ownTour['pano_media_ids'][$oldIndex] ?? 0);
            $mediaId = (string)$ownTourIds[$newIndex];
            if (isset($ownTour['showroom_views'][$mediaId])) $ownTourViews[$mediaId] = $ownTour['showroom_views'][$mediaId];
        }
        $ownTourHotspots = [];
        foreach (($ownTour['hotspots_by_pano_index'] ?? []) as $oldIndex => $hotspots) {
            if (!isset($oldToNewIndexes[(int)$oldIndex])) continue;
            $newIndex = $oldToNewIndexes[(int)$oldIndex];
            foreach ($hotspots as $hotspot) {
                if (($hotspot['type'] ?? '') === 'nav' && !in_array((int)($hotspot['target_media_id'] ?? 0), $ownTourIds, true)) continue;
                if (($hotspot['type'] ?? '') === 'nav') {
                    $targetIndex = array_search((int)$hotspot['target_media_id'], $ownTourIds, true);
                    if ($targetIndex === false || $targetIndex === $newIndex) continue;
                    $hotspot['target_pano_index'] = $targetIndex;
                }
                $ownTourHotspots[$newIndex][] = $hotspot;
            }
        }
        $ownTour = [
            'pano_urls' => $ownTourUrls,
            'pano_media_ids' => $ownTourIds,
            'showroom_views' => $ownTourViews,
            'hotspots_by_pano_index' => $ownTourHotspots,
            'legacy_pano_media_ids' => $ownTourIds,
        ];
        $variantGallery = showroom_tour_select_option_media(
            ['gallery' => $destination['shared_gallery'] ?? []],
            ['gallery' => $ownGallery],
            !empty($variant['standard_media_override_enabled']),
            'standard'
        );
        $variant['gallery'] = $variantGallery['gallery'] ?? [];
        $variant['tour'] = showroom_tour_select_option_media(
            $destination['shared_tour'],
            $ownTour,
            !empty($variant['tour_media_override_enabled']),
            '360'
        );
        unset($variant['_own_gallery_candidates'], $variant['_own_tour'], $variant['variant_map_key']);
    }
    unset($variant);
    $destination['variants'] = array_values($destination['_variant_map'] ?? []);
    usort($destination['variants'], static fn(array $left, array $right): int =>
        ((float)($left['nightly_rate'] ?? 0) <=> (float)($right['nightly_rate'] ?? 0))
        ?: ((int)($left['max_capacity'] ?? 0) <=> (int)($right['max_capacity'] ?? 0))
        ?: ((int)($left['bed_count'] ?? 0) <=> (int)($right['bed_count'] ?? 0))
        ?: ((int)($left['room_group_id'] ?? 0) <=> (int)($right['room_group_id'] ?? 0))
    );
    unset($destination['_variant_map']);
}
unset($destination);

// 4. Group showroom venues into categories for UI rendering
$grouped_showroom = ['Event Hall' => [], 'Hotel Room' => [], 'Resort Villa' => []];
$first_available_category = '';
$first_available_room = '';

foreach($showroom_data as $id => $data) {
    if (($data['category'] ?? '') === 'Hotel Room' || !empty($data['pano_urls']) || !empty($data['gallery'])) {
        $grouped_showroom[$data['category']][$id] = $data;
        if (empty($first_available_category)) {
            $first_available_category = $data['category'];
            $first_available_room = $id;
        }
    }
}

// Keep every canonical hotel presentation selectable, including rooms with
// empty CMS slots, grouped by the same room-type order as booking.php.
$grouped_hotel_rooms = showroom_tour_group_hotel_venues($grouped_showroom['Hotel Room']);

include 'includes/header.php';
?>

<!-- Pass PHP Data to Javascript -->
<script>
window.showroomData = <?php echo json_encode($showroom_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.userName = <?php echo json_encode(!empty($_SESSION['first_name']) ? $_SESSION['first_name'] : (!empty($_SESSION['username']) ? $_SESSION['username'] : null)); ?>;
</script>

<!-- EXACT Three.js version required by Panolens -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/105/three.min.js"></script>

<!-- Polyfill for Panolens Node.js bug -->
<script>
window.process = {
    env: {
        NODE_ENV: 'production'
    }
};
</script>
<script src="https://cdn.jsdelivr.net/npm/panolens@0.12.1/build/panolens.min.js"></script>

<!-- Showroom Container -->
<main>
<section class="showroom-wrapper" id="showroom-wrapper">
    <div class="showroom-container">

        <!-- 1. The Big Viewer Box -->
        <div class="big-viewer-box">

            <!-- === 360 UI Elements === -->
            <div class="viewer-label ui-360" id="top-room-label">Showroom</div>

            <div class="showroom-destinations ui-360" id="showroom-destinations">
                <button type="button" id="btn-showroom-destinations" aria-controls="showroom-destinations-list" aria-expanded="false" disabled>
                    <i class="fa-solid fa-signs-post" aria-hidden="true"></i>
                    <span>Destinations</span>
                    <span class="showroom-destinations-count" id="showroom-destinations-count" aria-hidden="true">0</span>
                </button>
                <div id="showroom-destinations-list" class="showroom-destinations-list" role="group" aria-label="Destinations from this panorama" hidden></div>
            </div>

            <button type="button" id="btn-info" class="ui-360 top-right-btn" title="Venue Information" aria-label="Venue information">
                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            </button>

            <div class="viewer-controls ui-360" id="viewer-controls">

                <button type="button" id="btn-reset-view" title="Reset View" aria-label="Reset panorama view"><i class="fa-solid fa-compass" aria-hidden="true"></i></button>
                <button type="button" id="btn-zoom-in" title="Zoom In" aria-label="Zoom in"><i class="fa-solid fa-magnifying-glass-plus" aria-hidden="true"></i></button>
                <button type="button" id="btn-zoom-out" title="Zoom Out" aria-label="Zoom out"><i class="fa-solid fa-magnifying-glass-minus" aria-hidden="true"></i></button>
                <button type="button" id="btn-fullscreen" title="Fullscreen" aria-label="Toggle fullscreen"><i class="fa-solid fa-expand" aria-hidden="true"></i></button>
                <button type="button" id="btn-showroom-help" title="Show showroom guide" aria-label="Show showroom guide"><i class="fa-regular fa-circle-question" aria-hidden="true"></i><span class="sr-only">Help</span></button>
            </div>

            <div id="pano-container" class="ui-360" style="width:100%; height:100%;"></div>

            <!-- NEW: 360 Interaction Hint Overlay -->
            <div id="interaction-hint" class="ui-360">
                <div class="hint-icon">
                    <i class="fa-solid fa-hand-pointer"></i>
                </div>
                <p>Drag to explore</p>
            </div>

            <!-- NEW: The Loading Placeholder that covers the black screen -->
            <div id="pano-loading-overlay"
                style="position: absolute; top:0; left:0; width:100%; height:100%; z-index: 4; background: url('assets/img/placeholder.jpg') center/cover no-repeat; display: none; align-items: center; justify-content: center;">
                <div
                    style="background: rgba(0,0,0,0.6); color: white; padding: 10px 25px; border-radius: 30px; font-family: var(--font-body); font-weight: 500; letter-spacing: 1px; backdrop-filter: blur(4px);">
                    <i class="fa-solid fa-circle-notch fa-spin" style="margin-right: 8px;"></i> Loading 360° View...
                </div>
            </div>

            <!-- === Photo Gallery UI Elements === -->

            <!-- NEW: Flexbox Header for Gallery Mode -->
            <div class="gallery-header ui-photos">
                <div>
                    <span class="photo-room-title" id="gallery-title">--</span>
                    <span id="gallery-counter" aria-live="polite"
                        style="color: var(--color-gold); margin-left: 10px; font-weight: bold;"></span>
                </div>
                <button class="btn-back" id="btn-back-to-360">Back to 360</button>
            </div>

            <button class="slider-arrow left ui-photos" id="slide-prev" aria-label="Previous photo">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 19l-7-7 7-7" />
                </svg>
            </button>
            <button class="slider-arrow right ui-photos" id="slide-next" aria-label="Next photo">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M5 12h14M12 5l7 7-7 7" />
                </svg>
            </button>

            <div class="slider-image-container ui-photos">
                <img src="assets/img/placeholder.jpg" alt="Room Photo" id="current-slide-img">
            </div>

            <!-- NEW: The Thumbnail Filmstrip -->
            <div class="thumbnail-strip ui-photos" id="thumbnail-strip"></div>

            <button class="btn-back ui-photos" id="btn-back-to-360-gallery">Back to 360</button>

            <!-- === Sleek Dropdown Pill Navigation === -->
            <div class="room-navigation-wrapper ui-360">
                <div class="master-category-pills">

                    <?php foreach($grouped_showroom as $category => $venues): ?>
                    <?php if (!empty($venues)): ?>

                    <?php
                        $menu_id = 'dropdown-' . str_replace(' ', '-', $category);
                        $is_hotel_menu = $category === 'Hotel Room';
                        $master_aria_label = $is_hotel_menu
                            ? 'Hotel Rooms; ' . count($grouped_hotel_rooms) . ' room types, ' . count($venues) . ' venues'
                            : $category . 's';
                    ?>

                    <!-- Master Pill Wrapper -->
                    <div class="pill-dropdown-wrapper<?php echo $is_hotel_menu ? ' hotel-room-dropdown' : ''; ?>">
                        <button
                            type="button"
                            class="master-pill <?php echo ($category === $first_available_category) ? 'active' : ''; ?>"
                            data-category="<?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>"
                            aria-label="<?php echo htmlspecialchars($master_aria_label, ENT_QUOTES, 'UTF-8'); ?>"
                            aria-controls="<?php echo htmlspecialchars($menu_id, ENT_QUOTES, 'UTF-8'); ?>"
                            aria-expanded="false">
                            <?php echo htmlspecialchars($category); ?>s
                            <i class="fa-solid fa-chevron-up"
                                style="margin-left: 8px; font-size: 0.75rem; transition: transform 0.3s;"></i>
                        </button>

                        <!-- Floating Dropdown Menu -->
                        <div class="pill-dropdown-menu<?php echo ($category === $first_available_category) ? ' active' : ''; ?><?php echo $is_hotel_menu ? ' hotel-room-groups-menu' : ''; ?>"
                            id="<?php echo htmlspecialchars($menu_id, ENT_QUOTES, 'UTF-8'); ?>"
                            role="group"
                            aria-label="<?php echo htmlspecialchars($category === 'Hotel Room' ? 'Hotel rooms grouped by room type' : $category . ' venues', ENT_QUOTES, 'UTF-8'); ?>">
                            <?php if ($is_hotel_menu): ?>
                            <div class="hotel-room-groups">
                                <?php $hotel_group_index = 0; foreach ($grouped_hotel_rooms as $room_type => $room_venues): $hotel_group_index++; ?>
                                <?php $heading_id = 'hotel-room-type-heading-' . $hotel_group_index; ?>
                                <div class="hotel-room-group" role="group" aria-labelledby="<?php echo htmlspecialchars($heading_id, ENT_QUOTES, 'UTF-8'); ?>">
                                    <h3 class="hotel-room-group-title" id="<?php echo htmlspecialchars($heading_id, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$room_type, ENT_QUOTES, 'UTF-8'); ?></h3>
                                    <div class="hotel-room-group-items">
                                        <?php foreach ($room_venues as $id => $data): ?>
                                        <button type="button" class="dropdown-item<?php echo ($id === $first_available_room) ? ' active' : ''; ?>"
                                            data-room="<?php echo htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php echo ($id === $first_available_room) ? 'aria-current="true"' : ''; ?>>
                                            <span><?php echo htmlspecialchars(ucwords(strtolower((string)$data['venue_name'] ?? $data['title'])), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php if (!empty($data['variant_summary'])): ?><small class="showroom-room-option-summary"><?php echo htmlspecialchars((string)$data['variant_summary'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                                        </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <?php foreach($venues as $id => $data): ?>
                            <button type="button" class="dropdown-item<?php echo ($id === $first_available_room) ? ' active' : ''; ?>"
                                data-room="<?php echo htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8'); ?>"
                                <?php echo ($id === $first_available_room) ? 'aria-current="true"' : ''; ?>>
                                <?php echo htmlspecialchars(ucwords(strtolower((string)$data['title'])), ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php endif; ?>
                    <?php endforeach; ?>

                </div>
            </div>
        </div>

        <!-- 2. The Details Block -->
        <div class="details-box">
            <div class="details-left">
                <h1 class="details-title">M.I. Sevilla Resort Virtual Showroom</h1>
                <!-- NEW: The Description -->
                <p class="venue-description" id="val-desc">
                    Explore the selected venue in the Sevilla360 showroom. Details and media update when a venue is selected.
                </p>

                <!-- NEW: Amenities Grid -->
                <div class="amenities-grid">
                    <div class="amenity"><i class="fa-solid fa-wifi" aria-hidden="true"></i> Free Wi-Fi</div>
                    <div class="amenity"><i class="fa-solid fa-snowflake" aria-hidden="true"></i> Fully Air-Conditioned</div>
                    <div class="amenity"><i class="fa-solid fa-square-parking" aria-hidden="true"></i> Ample Parking</div>
                    <div class="amenity"><i class="fa-solid fa-wheelchair" aria-hidden="true"></i> Wheelchair Accessible</div>
                </div>
                <section class="showroom-reviews" aria-labelledby="showroom-reviews-title">
                    <h3 class="details-title" id="showroom-reviews-title">GUEST REVIEWS</h3>
                    <p class="showroom-rating" id="val-rating" aria-live="polite">Loading ratings…</p>
                    <div class="showroom-reviews-list" id="showroom-reviews-list" aria-live="polite">
                        <p class="showroom-review-state">Loading reviews…</p>
                    </div>
                </section>
                <div class="detail-row" style="margin-top: 20px;">
                    <span class="d-label">CURRENTLY VIEWING</span>
                    <span class="d-value" id="val-title">--</span>
                </div>
                <div class="detail-row">
                    <span class="d-label">CATEGORY</span>
                    <span class="d-value" id="val-category">--</span>
                </div>
                <div class="detail-row">
                    <span class="d-label">MAX CAPACITY</span>
                    <span class="d-value" id="val-capacity">--</span>
                </div>
                <div class="detail-row" id="val-beds-row">
                    <span class="d-label">BEDS</span>
                    <span class="d-value" id="val-beds">--</span>
                </div>
                <div class="showroom-hotel-variant" id="showroom-hotel-variant" hidden>
                    <label for="showroom-hotel-variant-select">Choose a room option</label>
                    <select id="showroom-hotel-variant-select" aria-describedby="showroom-hotel-variant-help">
                        <option value="">Choose capacity, beds and nightly price</option>
                    </select>
                    <small id="showroom-hotel-variant-help">Choose the capacity and rate that fit your stay before booking.</small>
                </div>
            </div>

            <div class="details-right">
                <h3 class="details-title">AVAILABILITY & ACTION</h3>
                <div class="detail-row">
                    <span class="d-label">Status</span>
                    <span class="d-value" id="val-status">--</span>
                </div>
                <div class="detail-row" style="border-bottom: none;">
                    <span class="d-label">Starting Rate</span>
                    <span class="d-value" id="val-rate">--</span>
                </div>

                <div class="action-buttons">
                    <a href="booking.php" class="btn-mock btn-book"
                        style="text-align:center; text-decoration:none; display:flex; justify-content:center; align-items:center;">BOOK
                        VENUE</a>
                    <button class="btn-mock btn-photos" id="btn-view-photos">VIEW PHOTOS</button>
                </div>
            </div>
        </div>

    </div>

    <!-- Virtual receptionist: the showroom remains visible while the guide introduces the available tours. -->
    <div class="showroom-receptionist" id="showroom-receptionist" data-receptionist-natural-enabled="<?= $natural_receptionist_enabled ? 'true' : 'false' ?>" hidden>
        <div class="showroom-receptionist-backdrop" aria-hidden="true">
            <img id="receptionist-backdrop-image" src="assets/img/placeholder.jpg" alt="" decoding="async">
        </div>
        <div class="showroom-receptionist-scrim" data-receptionist-close aria-hidden="true"></div>
        <div class="showroom-receptionist-dialog" role="dialog" aria-modal="true"
            aria-labelledby="receptionist-title" aria-describedby="receptionist-message" tabindex="-1">
            <div class="receptionist-portrait-wrap">
                <img class="receptionist-portrait" src="assets/img/showroom-receptionist.webp"
                    alt="" aria-hidden="true" width="1024" height="1536">
            </div>
            <button type="button" class="receptionist-skip" data-receptionist-skip>Skip introduction</button>
            <div class="receptionist-panel">
                <div class="receptionist-panel-head">
                    <div>
                        <h2 id="receptionist-title">Welcome</h2>
                    </div>
                </div>
                <p class="receptionist-message" id="receptionist-message">
                    Welcome to M.I. Sevilla Resort &amp; Events Place. I’m your virtual receptionist. How may I help you today?
                </p>
                <button type="button" class="receptionist-continue" id="receptionist-continue"
                    data-receptionist-continue>Continue</button>
                <p class="receptionist-live" id="receptionist-live" aria-live="polite" aria-atomic="true"></p>
                <div class="receptionist-choices" id="receptionist-choices" role="group" aria-label="Receptionist choices">
                    <button type="button" class="receptionist-choice" data-receptionist-intent="Event Hall">Event</button>
                    <button type="button" class="receptionist-choice" data-receptionist-intent="Hotel Room">Hotel</button>
                    <button type="button" class="receptionist-choice" data-receptionist-intent="Resort Villa">Villa</button>
                    <button type="button" class="receptionist-choice receptionist-choice-secondary" data-receptionist-close>Just look around</button>
                </div>
                <button type="button" class="receptionist-chat-toggle" data-receptionist-chat-toggle
                    aria-controls="receptionist-chat-shell" aria-expanded="false" hidden>Prefer typing? Chat with receptionist</button>
                <section class="receptionist-chat-shell" id="receptionist-chat-shell" aria-labelledby="receptionist-chat-title" aria-hidden="true" hidden>
                    <div class="receptionist-chat-head">
                        <div class="receptionist-chat-identity">
                            <img class="receptionist-chat-avatar" src="assets/img/showroom-receptionist.webp"
                                 alt="" width="36" height="36" aria-hidden="true">
                            <div>
                                <h3 id="receptionist-chat-title">Sevilla Receptionist</h3>
                                <span class="receptionist-chat-capabilities">Venues, stays, and resort FAQs</span>
                            </div>
                        </div>
                        <button type="button" class="receptionist-chat-close" data-receptionist-chat-close aria-label="Collapse receptionist chat">Close</button>
                    </div>
                    <p class="receptionist-chat-privacy">Please don’t share payment, account, contact, or personal details. Messages go to an AI service.</p>
                    <div class="receptionist-chat-conversation" id="receptionist-chat-conversation" role="region" aria-label="Receptionist conversation" tabindex="0">
                        <div class="receptionist-chat-transcript" id="receptionist-chat-transcript" role="log" aria-live="polite" aria-relevant="additions text" aria-label="Receptionist messages"></div>
                        <div class="receptionist-chat-guided-content" id="receptionist-chat-guided-content"></div>
                    </div>
                    <p class="receptionist-chat-status" id="receptionist-chat-status" role="status" aria-live="polite"></p>
                    <div class="receptionist-chat-quick-replies" id="receptionist-chat-quick-replies" aria-label="Suggested questions"></div>
                    <form class="receptionist-chat-form" id="receptionist-chat-form">
                        <label class="sr-only" for="receptionist-chat-input">Ask the receptionist</label>
                        <div class="receptionist-chat-composer">
                            <textarea id="receptionist-chat-input" name="message" maxlength="500" rows="2" placeholder="Ask a question" autocomplete="off"></textarea>
                            <div class="receptionist-chat-form-actions">
                                <button type="submit" class="receptionist-choice receptionist-choice-primary" id="receptionist-chat-send">Send</button>
                            </div>
                        </div>
                    </form>
                    <details class="receptionist-chat-tools">
                        <summary>More options</summary>
                        <div class="receptionist-chat-tools-menu">
                            <label class="receptionist-chat-category-label" for="receptionist-chat-category">Change booking type
                                <select id="receptionist-chat-category" name="category" data-receptionist-chat-category-select>
                                    <option value="" selected>Choose a type</option>
                                    <option value="category_event_hall">Event halls</option>
                                    <option value="category_hotel_room">Hotel rooms</option>
                                    <option value="category_resort_villa">Resort villas</option>
                                </select>
                            </label>
                            <label class="receptionist-chat-locale-label" for="receptionist-chat-locale">Language
                                <select id="receptionist-chat-locale" name="locale">
                                    <option value="auto">Auto</option>
                                    <option value="en">English</option>
                                    <option value="fil">Filipino</option>
                                    <option value="taglish">Taglish</option>
                                </select>
                            </label>
                            <button type="button" data-receptionist-chat-prompt="What policies and FAQs can you help with?">Support FAQs</button>
                            <button type="button" data-receptionist-chat-start-over>Start over</button>
                        </div>
                    </details>
                </section>
            </div>
            <button type="button" class="receptionist-sound" data-receptionist-sound-toggle
                aria-pressed="false" aria-label="Turn on background music">Music off</button>
            <audio id="receptionist-ambience" preload="none" loop
                src="assets/audio/receptionist-ambience.wav?v=<?= (int)(@filemtime(__DIR__ . '/assets/audio/receptionist-ambience.wav') ?: 0); ?>"></audio>
        </div>
    </div>
    <button type="button" class="receptionist-reopen" id="receptionist-reopen" aria-label="Ask receptionist" hidden>
        <span class="receptionist-reopen-face" aria-hidden="true"><img src="assets/img/showroom-receptionist.webp" alt=""></span>
        <span>Ask receptionist</span>
    </button>
    <button type="button" class="receptionist-sound receptionist-sound-launcher" data-receptionist-sound-toggle
        aria-pressed="false" aria-label="Turn on background music" hidden>Music off</button>
    <!-- Mobile Info Modal -->
    <div class="modal-overlay" id="info-modal" style="z-index: 999999;">
        <!-- Extremely high z-index to sit over fullscreen! -->
        <div class="modal-content modal-sm"
            style="background: rgba(42, 37, 34, 0.95); color: white; border: 1px solid var(--color-gold); backdrop-filter: blur(10px);">
            <h3 class="modal-title" id="info-modal-title"
                style="color: var(--color-gold); border-bottom: 1px solid rgba(214, 168, 112, 0.3); padding-bottom: 15px;">
                Venue Name</h3>

            <div class="modal-body" style="color: #e0e0e0;">
                <p id="info-modal-desc" style="font-size: 0.95rem; line-height: 1.6; margin-bottom: 20px;">Venue
                    description goes here.</p>

                <div
                    style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 0.85rem; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 15px; margin-bottom: 20px;">
                    <div><span style="color: var(--color-gold); display: block; font-size: 0.75rem;">CAPACITY</span>
                        <span id="info-modal-cap">--</span>
                    </div>
                    <div><span style="color: var(--color-gold); display: block; font-size: 0.75rem;">BEDS</span>
                        <span id="info-modal-beds">--</span>
                    </div>
                    <div><span style="color: var(--color-gold); display: block; font-size: 0.75rem;">RATE</span> <span
                            id="info-modal-rate">--</span></div>
                </div>

                <section class="showroom-reviews showroom-reviews-mobile" aria-labelledby="info-modal-reviews-title">
                    <h4 id="info-modal-reviews-title">Guest reviews</h4>
                    <p class="showroom-rating" id="info-modal-rating" aria-live="polite">Loading ratings…</p>
                    <div class="showroom-reviews-list" id="info-modal-reviews-list" aria-live="polite">
                        <p class="showroom-review-state">Loading reviews…</p>
                    </div>
                </section>

                <!-- We will copy the amenities grid into here dynamically! -->
                <div id="info-modal-amenities" class="amenities-grid"
                    style="border-bottom: none; padding-bottom: 0; color: white;"></div>
            </div>

            <div class="modal-actions-center">
                <button class="btn btn-primary" id="btn-close-info" style="width: 100%;">Close</button>
            </div>
        </div>
    </div>

</section>
</main>

<?php include 'includes/footer.php'; ?>
