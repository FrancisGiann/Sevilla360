<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/hotel_rooms.php';
require_once __DIR__ . '/../includes/media_helper.php';

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$bookingJs = $read('assets/js/booking.js');
$showroomJs = $read('assets/js/showroom.js');
$lockPhp = $read('actions/bookings/lock_dates.php');
$submitPhp = $read('actions/bookings/submit_online.php');
$availabilityPhp = $read('actions/bookings/get_room_availability.php');
$datePhp = $read('actions/bookings/fetch_dates.php');
$recommendPhp = $read('actions/bookings/recommend_hotel_rooms.php');
$hotelRoomsPhp = $read('includes/hotel_rooms.php');
$mediaHelperPhp = $read('includes/media_helper.php');
$hotelAdminPhp = $read('includes/admin-page/admin_settings.php');
$hotelAdminJs = $read('assets/js/admin-page/admin_settings.js');
$hotelSavePhp = $read('actions/admin/save_venue.php');
$hotelMigration = $read('migrations/023_hotel_room_groups.sql');
$bookingPhp = $read('booking.php');
$homepagePhp = $read('index.php');
$showroomPhp = $read('showroom.php');
$fetchDatesPhp = $read('actions/bookings/fetch_dates.php');
$availabilityEndpointPhp = $read('actions/bookings/get_room_availability.php');
$publicReviewsPhp = $read('actions/public/get_venue_reviews.php');
$adminCmsPhp = $read('includes/admin-page/admin_cms.php');
$showroomCss = $read('assets/css/showroom.css');

$checks = [];
$schemaReadinessStart = strpos($hotelRoomsPhp, 'function hotel_group_schema_ready');
$schemaReadinessEnd = $schemaReadinessStart === false ? false : strpos($hotelRoomsPhp, 'function hotel_room_group_upsert', $schemaReadinessStart);
$schemaReadinessCode = $schemaReadinessStart === false || $schemaReadinessEnd === false
    ? '' : substr($hotelRoomsPhp, $schemaReadinessStart, $schemaReadinessEnd - $schemaReadinessStart);
$schemaQueryTables = [];
if (preg_match('/table_name IN \(([^)]+)\)/', $schemaReadinessCode, $schemaQueryMatch)) {
    preg_match_all("/'([a-z_]+)'/", $schemaQueryMatch[1], $schemaTableMatches);
    $schemaQueryTables = $schemaTableMatches[1] ?? [];
}
$requiredSchemaTables = ['hotel_room_types', 'hotel_room_groups', 'hotel_rooms', 'booking_rooms', 'bookings', 'maintenance', 'booking_locks'];
$checks['schema readiness query includes every hotel and availability dependency table'] = array_diff($requiredSchemaTables, $schemaQueryTables) === [];
$checks['fixed room type catalogue and comfort order are immutable'] = array_keys(hotel_fixed_room_types()) === [
    'standard_room', 'dormitory_room', 'family_room_superior', 'deluxe', 'vip_suite'
] && array_column(hotel_fixed_room_types(), 'comfort_rank') === [1, 2, 3, 4, 5];
$checks['fixed type catalog stores active state and the locked display order'] = str_contains($hotelMigration, 'active TINYINT(1) NOT NULL DEFAULT 1')
    && str_contains($hotelMigration, 'sort_order SMALLINT UNSIGNED NOT NULL')
    && str_contains($hotelMigration, "('standard_room', 'Standard Room', 1, 1, 1)")
    && str_contains($hotelMigration, "('dormitory_room', 'Dormitory Room', 2, 1, 2)")
    && str_contains($hotelMigration, "('family_room_superior', 'Family Room / Superior', 3, 1, 3)")
    && str_contains($hotelMigration, "('deluxe', 'Deluxe', 4, 1, 4)")
    && str_contains($hotelMigration, "('vip_suite', 'VIP Suite', 5, 1, 5)");
$checks['migration normalizes the room type foreign key collation for MariaDB defaults'] = str_contains($hotelMigration, 'room_type_code VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL')
    && str_contains($hotelMigration, "column_name = 'room_type_code' AND collation_name <> 'utf8mb4_unicode_ci'")
    && str_contains($hotelMigration, 'ALTER TABLE hotel_rooms MODIFY room_type_code VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
$checks['fixed room type rejects arbitrary display text'] = hotel_room_type_label('deluxe') === 'Deluxe';
try {
    hotel_validate_room_type_code('VIP Suite');
    $checks['fixed room type rejects arbitrary display text'] = false;
} catch (InvalidArgumentException) {
    // expected
}
$checks['Hotel priority codes expose only the confirmed three options'] = hotel_allowed_recommendation_priorities() === ['save', 'best_fit', 'comfort']
    && hotel_parse_recommendation_priority('save') === 'save'
    && hotel_parse_recommendation_priority('best_fit') === 'best_fit'
    && hotel_parse_recommendation_priority('comfort') === 'comfort'
    && hotel_parse_recommendation_priority('privacy') === null
    && hotel_parse_recommendation_priority('space') === null
    && str_contains($recommendPhp, 'if (!$guestRange || !$priority)')
    && str_contains($recommendPhp, "hotel_recommendation_response(['success' => false, 'message' => 'Choose a guest count and recommendation priority.'], 422)");
$checks['Hotel guest ranges allow only the six fixed capacity bands'] = hotel_allowed_guest_ranges() === [
        '1-2' => ['min' => 1, 'max' => 2],
        '3-4' => ['min' => 3, 'max' => 4],
        '5-6' => ['min' => 5, 'max' => 6],
        '7-8' => ['min' => 7, 'max' => 8],
        '9-12' => ['min' => 9, 'max' => 12],
        '13-16' => ['min' => 13, 'max' => 16],
    ]
    && hotel_parse_guest_range('17+') === null
    && hotel_parse_guest_range('5-10') === null
    && str_contains($recommendPhp, 'if (!$guestRange || !$priority)')
    && str_contains($recommendPhp, "['success' => false, 'message' => 'Choose a guest count and recommendation priority.'], 422)")
    && !str_contains($recommendPhp, "'state' => 'contact_reception'");
$checks['showroom pre-migration fallback aliases NULL exactly once'] = str_contains($showroomPhp, "\$hotel_type_code_select = \$hotel_group_schema_ready ? 'hrg.room_type_code' : 'NULL';")
    && str_contains($showroomPhp, '{$hotel_type_code_select} AS room_type_code')
    && !str_contains($showroomPhp, "'NULL AS room_type_code'");

$variant = ['building_name' => 'North Wing', 'room_type_code' => 'standard_room', 'base_capacity' => 2,
    'max_capacity' => 4, 'bed_count' => 2, 'nightly_rate' => 1000, 'extra_pax_rate' => 250,
    'check_in_time' => '14:00:00', 'check_out_time' => '12:00:00'];
$variantKey = hotel_commercial_variant_key($variant);
$checks['commercial variant key stays stable but separates changed group dimensions'] = $variantKey === hotel_commercial_variant_key($variant)
    && $variantKey !== hotel_commercial_variant_key([...$variant, 'building_name' => 'South Wing'])
    && $variantKey !== hotel_commercial_variant_key([...$variant, 'room_type_code' => 'deluxe'])
    && $variantKey !== hotel_commercial_variant_key([...$variant, 'base_capacity' => 3])
    && $variantKey !== hotel_commercial_variant_key([...$variant, 'extra_pax_rate' => 300]);
$checks['full-stay price includes nightly extra-pax rate'] = hotel_estimated_total($variant, 3, 4) === 4500.0;
$checks['undated pricing is a one-night estimate including extra-pax charges'] = hotel_estimated_nightly_amount($variant, 4) === 1500.0
    && hotel_estimated_total($variant, 1, 4) === 1500.0;
$checks['optional media slot is explicit, shareable and strictly validated'] = hotel_normalize_media_slot_key('hotel_wing_1') === 'hotel_wing_1'
    && hotel_normalize_media_slot_key('') === null && str_contains($hotelMigration, 'KEY idx_hotel_room_groups_media_slot (media_slot_key)')
    && !str_contains($hotelMigration, 'UNIQUE KEY uq_hotel_room_groups_media_slot');
$checks['hotel media resolver prefers explicit mappings and uses exact legacy CMS normalization'] = hotel_room_group_media_slot_key([
        'media_slot_key' => 'shared_photo_slot', 'building_name' => 'Abelardo', 'room_type' => 'VIP Suite'
    ]) === 'shared_photo_slot'
    && hotel_room_group_media_slot_key([
        'media_slot_key' => null, 'building_name' => 'Kristel', 'legacy_media_room_type' => 'Dormitory'
    ]) === 'venue_kristel_dormitory'
    && hotel_room_group_media_slot_key([
        'media_slot_key' => '', 'building_name' => 'Miradeth', 'legacy_media_room_type' => '1st Floor'
    ]) === media_cms_venue_slot_key('Miradeth - 1st Floor')
    && str_contains($mediaHelperPhp, "preg_replace('/[^a-zA-Z0-9]+/', '_', \$display_name)")
    && str_contains($mediaHelperPhp, 'admin_cms.php does')
    && str_contains($adminCmsPhp, 'media_cms_venue_slot_key($display_name)')
    && !str_contains($adminCmsPhp, "preg_replace('/[^a-zA-Z0-9]+/', '_', \$display_name)")
    && str_contains($showroomPhp, "substr(media_cms_venue_slot_key(\$display_name), strlen('venue_'))")
    && !str_contains($showroomPhp, "preg_replace('/[^a-zA-Z0-9]+/', '_', \$display_name)");
$checks['unrelated hall slots and missing hotel photos remain placeholders'] = hotel_room_group_public_images([
        'media_slot_key' => null, 'building_name' => 'Abelardo', 'legacy_media_room_type' => 'VIP Suite'
    ], ['venue_abelardo_hall_360' => ['assets/img/abelardo-hall.jpg']]) === ['assets/img/placeholder.jpg']
    && hotel_room_group_public_images([
        'media_slot_key' => 'venue_kristel_standard', 'building_name' => 'Kristel', 'room_type' => 'Standard Room'
    ], ['venue_kristel_standard' => ['assets/uploads/kristel-standard.jpg']]) === ['assets/uploads/kristel-standard.jpg'];
$checks['distinct commercial variants may share the same exact legacy media slot'] = hotel_room_group_media_slot_key([
        'media_slot_key' => null, 'building_name' => 'Rafael', 'legacy_media_room_type' => 'Dormitory Room'
    ]) === hotel_room_group_media_slot_key([
        'media_slot_key' => null, 'building_name' => 'Rafael', 'legacy_media_room_type' => 'Dormitory Room'
    ])
    && str_contains($recommendPhp, '$standardSlotToGroup[$slot][]')
    && str_contains($recommendPhp, '$panoSlotToGroup[$slot . \'_360\'][]');
$checks['recommendation and booking media use shared group slot resolution for standard and 360 media'] = str_contains($recommendPhp, 'hotel_room_group_media_slot_key($group)')
    && str_contains($recommendPhp, '$standardSlotToGroup[$slot][]')
    && str_contains($recommendPhp, '$panoSlotToGroup[$slot . \'_360\'][]')
    && str_contains($recommendPhp, "\$media['media_type'] === '360'")
    && str_contains($mediaHelperPhp, 'function get_hotel_room_group_image')
    && str_contains($mediaHelperPhp, 'hotel_room_group_media_slot_key($group)')
    && str_contains($homepagePhp, 'hotel_room_group_public_images($venue, $public_media)')
    && str_contains($homepagePhp, "'media_slot_key' => \$mediaSlot !== '' ? \$mediaSlot : null");
$checks['schema readiness checks full group and availability dependencies and group saves mark core commercial data ready'] = str_contains($hotelSavePhp, 'hotel_room_group_upsert')
    && str_contains($schemaReadinessCode, "'hotel_room_types' => ['type_code', 'display_name', 'comfort_rank', 'active', 'sort_order']")
    && str_contains($read('includes/hotel_rooms.php'), "'bookings' => ['id', 'venue_id', 'booking_status', 'source', 'start_date', 'end_date']")
    && str_contains($read('includes/hotel_rooms.php'), "'maintenance' => ['venue_id', 'is_blocking', 'status', 'start_date', 'end_date']")
    && str_contains($read('includes/hotel_rooms.php'), "'booking_locks' => ['venue_id', 'session_id', 'expires_at', 'start_date', 'end_date']")
    && str_contains($hotelRoomsPhp, '$recommendationReady = 1;')
    && str_contains($hotelRoomsPhp, "bind_param('ssssiiiddsssssi'")
    && strlen('ssssiiiddsssssi') === 15 && substr_count('ssssiiiddsssssi', 'd') === 2
    && !str_contains($hotelSavePhp, "\$_POST['occupancy_mode']")
    && !str_contains($hotelSavePhp, "\$_POST['bathroom_mode']")
    && !str_contains($hotelSavePhp, "\$_POST['floor_area_sqm']");
$checks['admin hotel saves require a database-active catalog code and retain a legacy fallback'] = function_exists('hotel_validate_active_room_type_code')
    && str_contains($hotelRoomsPhp, 'function hotel_validate_active_room_type_code')
    && str_contains($hotelRoomsPhp, 'if (!hotel_group_schema_ready($conn)) return $typeCode;')
    && str_contains($hotelRoomsPhp, 'SELECT active FROM hotel_room_types WHERE type_code = ? LIMIT 1')
    && substr_count($hotelSavePhp, 'hotel_validate_active_room_type_code($conn, $_POST[\'room_type_code\'] ?? null)') === 3;
$checks['customer room-group queries exclude inactive canonical types'] = str_contains($recommendPhp, 't.type_code = g.room_type_code AND t.active = 1')
    && str_contains($hotelRoomsPhp, 't.type_code = g.room_type_code AND t.active = 1')
    && str_contains($bookingPhp, 't.type_code = g.room_type_code AND t.active = 1')
    && str_contains($homepagePhp, 't.type_code = g.room_type_code AND t.active = 1')
    && str_contains($showroomPhp, 'hrg.id = hr.room_group_id') && str_contains($showroomPhp, 'hrt.active = 1')
    && str_contains($fetchDatesPhp, 't.type_code = g.room_type_code AND t.active = 1')
    && str_contains($availabilityEndpointPhp, 't.type_code = g.room_type_code AND t.active = 1')
    && str_contains($publicReviewsPhp, 't.type_code = g.room_type_code AND t.active = 1')
    && str_contains($submitPhp, 't.type_code = g.room_type_code AND t.active = 1')
    && str_contains($lockPhp, 't.type_code = g.room_type_code AND t.active = 1');
$checks['catalog sort order deterministically orders migrated customer group lists'] = str_contains($recommendPhp, 'ORDER BY t.sort_order ASC')
    && str_contains($bookingPhp, 'ORDER BY t.sort_order')
    && str_contains($homepagePhp, 'ORDER BY t.sort_order');
try {
    hotel_normalize_media_slot_key('unsafe slot/name');
    $checks['optional media slot rejects unsafe mapping'] = false;
} catch (InvalidArgumentException) {
    $checks['optional media slot rejects unsafe mapping'] = true;
}

$candidate = static function (int $id, string $type, int $max, float $rate, int $availability = 2, array $extra = []): array {
    return array_merge([
        'id' => $id, 'building_name' => 'North Wing', 'display_name' => 'North Wing — ' . hotel_room_type_label($type),
        'room_type_code' => $type, 'max_capacity' => $max, 'base_capacity' => 2, 'bed_count' => 2,
        'nightly_rate' => $rate, 'extra_pax_rate' => 0, 'check_in_time' => '14:00:00', 'check_out_time' => '12:00:00',
        'available_unit_count' => $availability, 'active_unit_count' => $availability, 'recommendation_ready' => 0,
        'sort_order' => 0,
    ], $extra);
};
$rangeCandidates = [
    $candidate(10, 'standard_room', 10, 1000),
    $candidate(11, 'standard_room', 12, 1200),
    $candidate(12, 'deluxe', 16, 1800),
];
$fit = hotel_rank_recommendation_groups($rangeCandidates, 'save', 2, 12);
$checks['guest-range maximum filters undersized groups and no media is required'] = array_column($fit, 'id') === [11, 12]
    && !array_key_exists('gallery', $fit[0]);
$checks['best fit orders capacity surplus, then beds, then price'] = array_column(
    hotel_rank_recommendation_groups([
        $candidate(90, 'standard_room', 5, 100, 20, ['bed_count' => 4]),
        $candidate(91, 'standard_room', 4, 100, 1, ['bed_count' => 1]),
        $candidate(92, 'standard_room', 4, 1000, 1, ['bed_count' => 2]),
        $candidate(93, 'standard_room', 4, 900, 1, ['bed_count' => 2]),
    ], 'best_fit', 2, 4, 4), 'id') === [93, 92, 91, 90];
$bestFitReasons = hotel_recommendation_reasons($candidate(94, 'standard_room', 5, 1200, 1, ['bed_count' => 2]), 'best_fit', 2, 4);
$checks['best fit explanation names capacity and listed bed count without bed-to-guest claims'] = $bestFitReasons[0]['label'] === 'Capacity fit'
    && str_contains($bestFitReasons[0]['value'], 'places above your range maximum')
    && $bestFitReasons[1]['label'] === 'Bed count' && str_contains($bestFitReasons[1]['value'], '2 beds listed')
    && !str_contains(strtolower(implode(' ', array_column($bestFitReasons, 'value'))), 'per guest');
$checks['comfort priority ranks canonical types, not room names'] = array_column(
    hotel_rank_recommendation_groups([
        $candidate(30, 'standard_room', 3, 1000),
        $candidate(31, 'vip_suite', 3, 1000),
        $candidate(32, 'deluxe', 3, 1000),
    ], 'comfort', 1, 2), 'id') === [31, 32, 30];
$incompletePriorityFacts = $candidate(33, 'deluxe', 3, 1000, 1, ['recommendation_ready' => 0]);
$checks['all three priorities need only complete commercial facts, not dormant optional metadata'] = array_column(
    hotel_rank_recommendation_groups([$incompletePriorityFacts], 'save', 1, 2), 'id') === [33]
    && array_column(hotel_rank_recommendation_groups([$incompletePriorityFacts], 'best_fit', 1, 2), 'id') === [33]
    && array_column(hotel_rank_recommendation_groups([$incompletePriorityFacts], 'comfort', 1, 2), 'id') === [33];
$checks['incomplete commercial fields and invalid prices remain ineligible'] = hotel_group_common_recommendation_issue(
    $candidate(34, 'standard_room', 3, 1000, 1, ['bed_count' => 0]), 2, true) === 'commercial_fields_missing'
    && hotel_group_common_recommendation_issue($candidate(35, 'standard_room', 3, -1, 1), 2, true) === 'pricing_metadata_missing';
$dateUnavailable = $candidate(42, 'standard_room', 3, 1000, 0, ['active_unit_count' => 1]);
$checks['dated recommendations exclude active units blocked for the requested stay'] = hotel_rank_recommendation_groups([$dateUnavailable], 'comfort', 1, 2, 3, true) === []
    && array_column(hotel_rank_recommendation_groups([$dateUnavailable], 'comfort', 1, 2, 3, false), 'id') === [42];
$checks['save tie ordering is deterministic by capacity surplus, price, units and stable keys'] = array_column(
    hotel_rank_recommendation_groups([
        $candidate(51, 'standard_room', 4, 1100, 1),
        $candidate(50, 'standard_room', 3, 1200, 1),
        $candidate(52, 'standard_room', 3, 900, 1),
    ], 'save', 1, 2), 'id') === [52, 51, 50];
$checks['equal-priority ties use unit count, sort order, then group id'] = array_column(
    hotel_rank_recommendation_groups([
        $candidate(61, 'standard_room', 3, 1000, 3, ['sort_order' => 5]),
        $candidate(62, 'standard_room', 3, 1000, 3, ['sort_order' => 2]),
        $candidate(60, 'standard_room', 3, 1000, 1, ['sort_order' => 1]),
    ], 'comfort', 1, 2), 'id') === [62, 61, 60];
$checks['best fit tie ordering uses price, active units, sort order, and stable group id'] = array_column(
    hotel_rank_recommendation_groups([
        $candidate(101, 'standard_room', 4, 1000, 2, ['bed_count' => 2, 'sort_order' => 4]),
        $candidate(102, 'standard_room', 4, 1000, 2, ['bed_count' => 2, 'sort_order' => 2]),
        $candidate(99, 'standard_room', 4, 1000, 2, ['bed_count' => 2, 'sort_order' => 2]),
        $candidate(100, 'standard_room', 4, 1000, 1, ['bed_count' => 2, 'sort_order' => 1]),
    ], 'best_fit', 1, 4, 4), 'id') === [99, 102, 101, 100];
$checks['undated best fit breaks exact ties by active physical unit count'] = array_column(
    hotel_rank_recommendation_groups([
        $candidate(110, 'standard_room', 4, 1000, 0, ['active_unit_count' => 1, 'sort_order' => 0]),
        $candidate(111, 'standard_room', 4, 1000, 0, ['active_unit_count' => 3, 'sort_order' => 5]),
    ], 'best_fit', 1, 4, 2, false), 'id') === [111, 110];
$checks['recommendations distinguish partial and no-match states'] = hotel_recommendation_state(2) === 'partial'
    && hotel_recommendation_state(0) === 'no_match'
    && hotel_recommendation_state(3) === 'matched';

$today = new DateTimeImmutable('2026-09-14');
$checks['dates are strict, future-or-today, and checkout-exclusive'] = count(hotel_validate_recommendation_dates('2026-09-14', '2026-09-15', $today)) === 3;
$checks['recommendation dates may be omitted only as an empty pair'] = hotel_validate_optional_recommendation_dates('', null, $today) === [null, null, 1, false]
    && hotel_validate_optional_recommendation_dates('2026-09-14', '2026-09-16', $today)[2] === 2;
$incompleteDateRejected = true;
foreach ([[null, '2026-09-15'], ['2026-09-14', null], ['', '2026-09-15'], ['2026-09-14', '']] as [$checkIn, $checkOut]) {
    try {
        hotel_validate_optional_recommendation_dates($checkIn, $checkOut, $today);
        $incompleteDateRejected = false;
        break;
    } catch (InvalidArgumentException) {
        // A single supplied date is never treated as undated.
    }
}
$checks['one-date recommendation requests are rejected'] = $incompleteDateRejected;
foreach ([['2026-02-31', '2026-03-01'], ['2026-09-13', '2026-09-15'], ['2026-09-14', '2026-09-14']] as [$checkIn, $checkOut]) {
    try {
        hotel_validate_recommendation_dates($checkIn, $checkOut, $today);
$checks['dates reject malformed, past, and zero-night intervals'] = false;
        break;
    } catch (InvalidArgumentException) {
        $checks['dates reject malformed, past, and zero-night intervals'] = true;
    }
}

$undatedCandidates = [
    $candidate(70, 'standard_room', 4, 1050, 0, ['active_unit_count' => 2, 'extra_pax_rate' => 250]),
    $candidate(71, 'standard_room', 4, 1000, 0, ['active_unit_count' => 1, 'extra_pax_rate' => 250]),
    $candidate(72, 'standard_room', 4, 800, 0, ['active_unit_count' => 0]),
];
$undatedRanked = hotel_rank_recommendation_groups($undatedCandidates, 'save', 1, 4, 3, false);
$checks['undated recommendations use active inventory without availability filtering'] = array_column($undatedRanked, 'id') === [71, 70]
    && hotel_recommendation_reasons($undatedRanked[0], 'save', 1, 4, false)[0] === [
        'code' => 'estimated_nightly_amount', 'label' => 'Estimated nightly amount',
        'value' => '₱1,500.00 /night for up to 4 guests, including extra-pax charges'
    ];

$checks['hotel handoff carries group id and dates without physical unit numbers'] = str_contains($showroomJs, 'room_group_id')
    && str_contains($showroomJs, 'check_in') && str_contains($showroomJs, 'check_out')
    && str_contains($showroomJs, 'guest_range') && str_contains($bookingJs, "formData.append('room_group_id', context.roomGroupId)");
$checks['undated hotel booking handoff keeps group and guest range while omitting dates'] = str_contains($showroomJs, 'if (startDate && endDate)')
    && str_contains($showroomJs, 'params.set("guest_range", String(arguments[3]))')
    && str_contains($showroomJs, 'data-receptionist-date-later')
    && str_contains($showroomJs, '"Choose dates later"');
$checks['lock and submit revalidate group membership, capacity, and transactional availability'] = str_contains($lockPhp, 'h.room_group_id = ?')
    && str_contains($lockPhp, 'h.max_capacity >= ?') && str_contains($submitPhp, 'hotel_available_group_units')
    && str_contains($submitPhp, 'selected_unit[\'room_group_id\']');
$checks['read-only availability and booking calendar use room group ids'] = !str_contains($availabilityPhp, 'DELETE FROM booking_locks')
    && str_contains($availabilityPhp, 'hotel_available_group_units') && str_contains($datePhp, 'h.room_group_id = ?');
$checks['recommender is a single POST endpoint with factual partial/no-match output'] = str_contains($recommendPhp, "\$_SERVER['REQUEST_METHOD'] !== 'POST'")
    && str_contains($recommendPhp, '$requestData = $_POST;') && str_contains($recommendPhp, 'application/json')
    && str_contains($recommendPhp, 'hotel_recommendation_state') && str_contains($recommendPhp, 'hotel_available_group_units')
    && str_contains($recommendPhp, 'hotel_validate_optional_recommendation_dates')
    && !str_contains($recommendPhp, 'WHERE g.recommendation_ready = 1')
    && str_contains($recommendPhp, 'hotel_group_common_recommendation_issue')
    && str_contains($recommendPhp, "\$response['reason_code'] = \$issueCode;")
    && str_contains($recommendPhp, "\$response['message'] = \$issueMessage;")
    && str_contains($recommendPhp, '$availableUnits = $availabilityChecked')
    && str_contains($recommendPhp, 'AS active_unit_count')
    && str_contains($recommendPhp, "'availability_checked' => \$availabilityChecked")
    && str_contains($recommendPhp, "'pricing_basis' => \$pricingBasis")
    && str_contains($recommendPhp, "['estimated_nightly_amount'] = hotel_estimated_nightly_amount")
    && str_contains($recommendPhp, 'application/json') && str_contains($recommendPhp, 'check_rate_limit($conn, \'hotel_room_recommendation\', 30, 5)')
    && str_contains($recommendPhp, '], 429)') && str_contains($recommendPhp, "'checked_at'")
    && str_contains($recommendPhp, "'total_matches'") && str_contains($recommendPhp, "'partial_match'")
    && !str_contains($recommendPhp, "'available_unit_count' =>") && str_contains($hotelRoomsPhp, "'Available for your stay'")
    && str_contains($hotelRoomsPhp, "'Dates needed to check availability'");
$checks['availability remains internal while mapped media may be shared and absent media stays eligible'] = str_contains($recommendPhp, '$group[\'available_unit_count\']')
    && !str_contains($recommendPhp, "'available_unit_count' =>") && str_contains($recommendPhp, '$standardSlotToGroup[$slot][]')
    && str_contains($recommendPhp, "'status' => \$availabilityChecked ? 'Available' : 'Availability not checked'") && str_contains($recommendPhp, "'description' =>")
    && str_contains($recommendPhp, "'amenities' =>") && str_contains($recommendPhp, "'gallery' => array_values(");
$checks['active Hotel UI and admin controls use only the confirmed priorities and no retired metadata fields'] = str_contains($showroomJs, '["Lowest price", "save"]')
    && str_contains($showroomJs, '["Best fit for my group", "best_fit"]')
    && str_contains($showroomJs, '["Higher room category", "comfort"]')
    && !str_contains($showroomJs, '"privacy"') && !str_contains($showroomJs, '"space"')
    && !str_contains($hotelAdminPhp, 'name="occupancy_mode"')
    && !str_contains($hotelAdminPhp, 'name="bathroom_mode"')
    && !str_contains($hotelAdminPhp, 'name="floor_area_sqm"')
    && !str_contains($hotelAdminJs, 'venueData.occupancy_mode')
    && !str_contains($hotelAdminJs, 'venueData.bathroom_mode')
    && !str_contains($hotelAdminJs, 'venueData.floor_area_sqm')
    && !str_contains($hotelSavePhp, 'venue_optional_choice')
    && !str_contains($hotelSavePhp, 'venue_optional_floor_area')
    && !str_contains($hotelSavePhp, "\$_POST['occupancy_mode']")
    && !str_contains($hotelSavePhp, "\$_POST['bathroom_mode']")
    && !str_contains($hotelSavePhp, "\$_POST['floor_area_sqm']")
    && str_contains($hotelAdminPhp, 'name="media_slot_key"')
    && str_contains($hotelAdminJs, 'venueData.media_slot_key')
    && substr_count($hotelSavePhp, "hotel_normalize_media_slot_key(\$_POST['media_slot_key'] ?? null)") === 3;
$checks['migrated booking records retain group description and amenities'] = str_contains($bookingPhp, 'g.description AS venue_description')
    && str_contains($bookingPhp, 'g.amenities AS venue_amenities') && str_contains($bookingPhp, 'g.description, g.amenities');
$checks['Hotel guest range question renders only the six allowed options'] = str_contains($showroomJs, 'options = [["1–2 guests", "1-2"], ["3–4 guests", "3-4"], ["5–6 guests", "5-6"], ["7–8 guests", "7-8"], ["9–12 guests", "9-12"], ["13–16 guests", "13-16"]];')
    && !str_contains($showroomJs, 'renderHotelContactReception')
    && !str_contains($showroomJs, 'For 17+ guests')
    && !str_contains($showroomJs, 'Groups of 17 or more');
$checks['ordinary no-match states link to reception and preserve browse/search actions'] = str_contains($showroomJs, 'support.php#contact')
    && str_contains($showroomJs, 'createReceptionContactLink()') && str_contains($showroomJs, 'Browse the showroom')
    && str_contains($showroomJs, 'Change dates') && str_contains($showroomJs, 'Change guests or priority');
$checks['no-match reason is announced with recovery actions'] = str_contains($showroomJs, 'guideState.hotelRecommendationMessage = typeof data.message')
    && str_contains($showroomJs, 'recommendationMessage || (availabilityChecked')
    && str_contains($showroomJs, 'setDialogue(heading, lead, announce)')
    && str_contains($showroomJs, 'Change guests or priority') && str_contains($showroomJs, 'createReceptionContactLink()');
$checks['selected group activates its explicit gallery safely and mobile hotel screens scroll and stack actions'] = str_contains($showroomJs, 'activateVenue(room.id);')
    && !str_contains($showroomJs, 'if (!room.room_group_id) activateVenue(room.id)')
    && str_contains($showroomCss, '.showroom-receptionist.is-hotel-results .receptionist-choices')
    && str_contains($showroomCss, 'overflow-y: auto;') && str_contains($showroomCss, 'grid-template-columns: minmax(0, 1fr)')
    && str_contains($showroomCss, 'min-height: 44px;');
$checks['recommendation response and room details omit retired optional metadata'] = !str_contains($recommendPhp, 'occupancy_mode')
    && !str_contains($recommendPhp, 'bathroom_mode') && !str_contains($recommendPhp, 'floor_area_sqm')
    && !str_contains($showroomJs, 'room.occupancy_mode') && !str_contains($showroomJs, 'room.bathroom_mode')
    && !str_contains($showroomJs, 'room.floor_area_sqm');
$hotelOverviewStart = strpos($showroomJs, 'const renderVenueOverview = room => {');
$hotelOverviewEnd = strpos($showroomJs, 'const renderVenue = room => {', $hotelOverviewStart === false ? 0 : $hotelOverviewStart);
$hotelOverview = $hotelOverviewStart !== false && $hotelOverviewEnd !== false
    ? substr($showroomJs, $hotelOverviewStart, $hotelOverviewEnd - $hotelOverviewStart)
    : '';
$checks['panorama-only hotel details state no standard photo while preserving the tour action'] = str_contains($hotelOverview, '(hasGallery(room) ? room.gallery.filter(Boolean) : [])')
    && str_contains($hotelOverview, 'const panoramaAvailable = Boolean(room.room_group_id && hasPanorama(room));')
    && str_contains($hotelOverview, 'Standard room photo unavailable for')
    && str_contains($hotelOverview, 'A standard room photo is unavailable. A 360° room tour is available.')
    && str_contains($hotelOverview, 'No room photos are provided. The recommendation is based on the listed room facts.')
    && str_contains($showroomJs, 'room.room_group_id')
    && str_contains($showroomJs, 'createChoice(hasPanorama(room) ? "Start 360° tour"')
    && str_contains($showroomJs, 'if (hasPanorama(room) || hasGallery(room)) primaryActions.appendChild(tourAction);');
$checks['Hotel recommendation list has semantic result and action groups in focus order'] = str_contains($showroomJs, 'receptionist-hotel-results-grid')
    && str_contains($showroomJs, 'setAttribute("role", "group")')
    && str_contains($showroomJs, 'aria-label", "Recommended hotel rooms"')
    && str_contains($showroomJs, 'receptionist-hotel-actions')
    && str_contains($showroomJs, 'aria-label", "Hotel recommendation actions"')
    && str_contains($showroomJs, 'receptionistChoices.replaceChildren(resultsGrid, actionsGroup)')
    && str_contains($showroomJs, '(guideState.hotelResults || []).slice(0, 3)');
$checks['Hotel recommendations reuse the receptionist thinking transition'] = str_contains($showroomJs, 'const showRecommendationThinking = (title, message) =>')
    && substr_count($showroomJs, 'showRecommendationThinking(') >= 2
    && str_contains($showroomJs, 'Math.max(0, 800 - (Date.now() - thinkingStartedAt))')
    && str_contains($showroomJs, 'receptionistRoot.classList.remove("is-thinking")')
    && str_contains($showroomJs, 'if (guideState.hotelResults.length) playSuccessChime();');
$checks['Hotel results use one no-scroll desktop row and compact actions'] = str_contains($showroomCss, '.showroom-receptionist.is-hotel-results .receptionist-hotel-results-grid')
    && str_contains($showroomCss, 'grid-template-columns: repeat(3, minmax(0, 1fr));')
    && str_contains($showroomCss, '.showroom-receptionist.is-hotel-results .receptionist-hotel-actions')
    && preg_match('/\.showroom-receptionist\.is-hotel-results \.receptionist-panel\s*\{[^}]*overflow:\s*visible;/s', $showroomCss)
    && preg_match('/\.showroom-receptionist\.is-hotel-results \.receptionist-choices\s*\{[^}]*overflow:\s*visible;/s', $showroomCss);
$checks['Hotel details preserve aligned cards and reuse the Event Hall and Villa disclosure design'] = str_contains($showroomJs, 'is-hotel-explanation-open')
    && str_contains($showroomJs, 'receptionist-shortlist-details receptionist-hotel-explanation')
    && str_contains($showroomJs, 'summary.className = "receptionist-shortlist-summary"')
    && str_contains($showroomJs, 'summary.textContent = "View details"')
    && str_contains($showroomJs, 'list.className = "receptionist-shortlist-facts"')
    && !str_contains($showroomJs, 'fa-solid fa-chevron-down')
    && str_contains($showroomJs, 'if (openDetails !== details) openDetails.open = false')
    && str_contains($showroomCss, 'align-items: stretch;')
    && str_contains($showroomCss, 'min-height: 12rem;')
    && str_contains($showroomCss, 'width: min(100%, 54rem);')
    && str_contains($showroomCss, '.receptionist-shortlist-details[open] .receptionist-shortlist-facts')
    && !preg_match('/\.showroom-receptionist\.is-hotel-results \.receptionist-portrait-wrap\s*\{[^}]*display:\s*none;/s', $showroomCss)
    && !preg_match('/\.showroom-receptionist\.is-hotel-results \.receptionist-panel\s*\{[^}]*height:\s*auto;/s', $showroomCss)
    && !preg_match('/@media \(min-width: 701px\)[\s\S]*?\.showroom-receptionist\.is-hotel-results \.receptionist-panel h2\s*\{/s', substr($showroomCss, strpos($showroomCss, 'Keep Hotel recommendations'), strpos($showroomCss, 'A short or zoomed landscape') - strpos($showroomCss, 'Keep Hotel recommendations')))
    && str_contains($showroomCss, '.showroom-receptionist.is-hotel-results.is-hotel-explanation-open .receptionist-panel')
    && !str_contains($showroomCss, '.showroom-receptionist.is-hotel-results.is-hotel-explanation-open .receptionist-hotel-results-grid')
    && str_contains($showroomCss, '@media (min-width: 701px) and (max-height: 620px)')
    && str_contains($showroomCss, '@media (max-width: 700px)')
    && str_contains($showroomCss, 'overflow-y: auto;')
    && str_contains($showroomCss, 'min-height: 44px;');
$checks['receptionist pointer exit ignores empty choice targets'] = str_contains($showroomJs, 'if (choice && choice === lastHoverChoice)');

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " — {$name}\n";
    if (!$passed) $failed[] = $name;
}
if ($failed) exit(1);
echo "All hotel recommendation contract checks passed.\n";
