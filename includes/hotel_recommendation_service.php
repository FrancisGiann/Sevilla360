<?php
declare(strict_types=1);

require_once __DIR__ . '/hotel_rooms.php';
require_once __DIR__ . '/media_helper.php';

/** Keep exact room type filtering separate from the preference ranking rules. */
function hotel_filter_recommendation_groups_by_type(array $groups, ?string $roomTypeCode): array
{
    if ($roomTypeCode === null || $roomTypeCode === 'any') return $groups;
    return array_values(array_filter($groups, static fn(array $group): bool => ($group['room_type_code'] ?? null) === $roomTypeCode));
}

/** Check the next seven same-length stays and return up to three real room/date pairs. */
function hotel_recommendation_nearby_date_options(
    mysqli $conn,
    array $groups,
    string $checkIn,
    string $checkOut,
    int $nights,
    int $guestCount,
    string $priority,
    ?string $sessionId = null
): array {
    if ($nights < 1 || $guestCount < 1 || !$groups) return [];
    $groupIds = array_values(array_map(static fn(array $group): int => (int)$group['id'], $groups));
    $options = [];
    $requestedStart = new DateTimeImmutable($checkIn);
    for ($offset = 1; $offset <= 7 && count($options) < 3; $offset++) {
        $start = $requestedStart->modify('+' . $offset . ' days');
        $end = $start->modify('+' . $nights . ' days');
        $units = hotel_available_group_units($conn, $start->format('Y-m-d'), $end->format('Y-m-d'), $sessionId ?? session_id(), $groupIds);
        $candidates = [];
        foreach ($groups as $group) {
            $group['available_unit_count'] = count($units[(int)$group['id']] ?? []);
            if ($group['available_unit_count'] > 0) $candidates[] = $group;
        }
        $ranked = hotel_rank_recommendation_groups($candidates, $priority, $nights, $guestCount, 1, true);
        if (!$ranked) continue;
        $room = $ranked[0];
        $available = $units[(int)$room['id']] ?? [];
        $options[] = [
            'check_in' => $start->format('Y-m-d'), 'check_out' => $end->format('Y-m-d'),
            'title' => (string)($room['display_name'] ?: ((string)$room['building_name'] . ' — ' . (string)$room['room_type'])),
            'building_name' => (string)$room['building_name'], 'room_type' => (string)$room['room_type'],
            'room_type_code' => (string)$room['room_type_code'], 'venue_id' => (int)($available[0]['venue_id'] ?? $room['venue_id']),
            'room_group_id' => (int)$room['id'],
        ];
    }
    return $options;
}

/** Shared recommendation query used by the public endpoint and receptionist. */
function hotel_recommendation_search(mysqli $conn, array $requestData, ?string $sessionId = null): array
{
    $guestRangeKey = $requestData['guest_range'] ?? null;
    $guestRange = hotel_parse_guest_range($guestRangeKey);
    $priority = hotel_parse_recommendation_priority($requestData['priority'] ?? null);
    if (!$guestRange || !$priority) throw new InvalidArgumentException('Choose a guest count and recommendation priority.');
    $requestedType = $requestData['room_type_code'] ?? null;
    if ($requestedType !== null && $requestedType !== '' && $requestedType !== 'any') $requestedType = hotel_validate_room_type_code($requestedType);
    else $requestedType = $requestedType === 'any' ? 'any' : null;
    $requestedTypeLabel = is_string($requestedType) && $requestedType !== 'any' ? hotel_room_type_label($requestedType) : null;
    [$checkIn, $checkOut, $nights, $availabilityChecked] = hotel_validate_optional_recommendation_dates($requestData['check_in'] ?? null, $requestData['check_out'] ?? null);
    $pricingBasis = $availabilityChecked ? 'full_stay' : 'estimated_nightly';
    if (!hotel_group_schema_ready($conn)) throw new RuntimeException('Hotel room recommendation schema is not available.');

    $rangeMaximum = (int)$guestRange['max'];
    $exactGuestCount = array_key_exists('group_size', $requestData);
    $guestCount = $rangeMaximum;
    if ($exactGuestCount) {
        $guestCount = filter_var($requestData['group_size'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($guestCount === false) throw new InvalidArgumentException('Choose a valid exact guest count.');
    }
    $maxGuests = (int)$guestCount;
    $undatedInventoryFilter = $availabilityChecked ? '' : " AND EXISTS (
            SELECT 1 FROM hotel_rooms active_h
            INNER JOIN venues active_v ON active_v.id = active_h.venue_id
                AND active_v.category = 'Hotel Room' AND active_v.status = 'Available'
            WHERE active_h.room_group_id = g.id
        )";
    $groupStmt = $conn->prepare("SELECT g.id, g.building_name, g.display_name, g.description, g.amenities,
            g.legacy_room_type, g.room_type_code, t.display_name AS room_type,
            (SELECT MIN(media_h.venue_id) FROM hotel_rooms media_h
             INNER JOIN venues media_v ON media_v.id = media_h.venue_id
                AND media_v.category = 'Hotel Room' AND media_v.status = 'Available'
             WHERE media_h.room_group_id = g.id) AS venue_id,
            (SELECT CASE WHEN COUNT(DISTINCT media_h.room_type) = 1 THEN MIN(media_h.room_type) ELSE NULL END
             FROM hotel_rooms media_h
             INNER JOIN venues media_v ON media_v.id = media_h.venue_id
                AND media_v.category = 'Hotel Room' AND media_v.status = 'Available'
             WHERE media_h.room_group_id = g.id) AS legacy_media_room_type,
            t.comfort_rank, g.base_capacity, g.max_capacity, g.bed_count, g.nightly_rate,
            g.extra_pax_rate, g.check_in_time, g.check_out_time,
            g.sort_order, g.media_slot_key,
            (SELECT COUNT(*) FROM hotel_rooms active_h
             INNER JOIN venues active_v ON active_v.id = active_h.venue_id
                AND active_v.category = 'Hotel Room' AND active_v.status = 'Available'
             WHERE active_h.room_group_id = g.id) AS active_unit_count
        FROM hotel_room_groups g
        INNER JOIN hotel_room_types t ON t.type_code = g.room_type_code AND t.active = 1
        WHERE g.max_capacity >= ?{$undatedInventoryFilter}
        ORDER BY t.sort_order ASC, g.sort_order ASC, g.id ASC");
    if (!$groupStmt) throw new RuntimeException('Unable to prepare hotel recommendation query.');
    $groupStmt->bind_param('i', $maxGuests);
    if (!$groupStmt->execute()) throw new RuntimeException('Unable to load hotel room groups.');
    $groups = $groupStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $groupStmt->close();

    $groupIds = array_map(static fn(array $group): int => (int)$group['id'], $groups);
    $availableUnits = $availabilityChecked
        ? hotel_available_group_units($conn, $checkIn->format('Y-m-d'), $checkOut->format('Y-m-d'), $sessionId ?? session_id(), $groupIds)
        : [];
    foreach ($groups as &$group) {
        $id = (int)$group['id'];
        $group['id'] = $id;
        $group['venue_id'] = (int)($group['venue_id'] ?? 0);
        $group['comfort_rank'] = (int)$group['comfort_rank'];
        $group['base_capacity'] = (int)$group['base_capacity'];
        $group['max_capacity'] = (int)$group['max_capacity'];
        $group['bed_count'] = (int)$group['bed_count'];
        $group['nightly_rate'] = (float)$group['nightly_rate'];
        $group['extra_pax_rate'] = (float)$group['extra_pax_rate'];
        $group['sort_order'] = (int)$group['sort_order'];
        $group['active_unit_count'] = (int)$group['active_unit_count'];
        $group['available_unit_count'] = count($availableUnits[$id] ?? []);
        $group['gallery'] = [];
        $group['pano_urls'] = [];
        $group['pano_media_ids'] = [];
        $group['hotspots_by_pano_index'] = [];
    }
    unset($group);

    $pricingIssueCount = 0;
    foreach ($groups as $group) if (hotel_group_common_recommendation_issue($group, $maxGuests, $availabilityChecked) === 'pricing_metadata_missing') $pricingIssueCount++;

    $standardSlotToGroup = [];
    $panoSlotToGroup = [];
    foreach ($groups as $group) {
        $slot = hotel_room_group_media_slot_key($group);
        if ($slot !== null) {
            $standardSlotToGroup[$slot][] = (int)$group['id'];
            $panoSlotToGroup[$slot . '_360'][] = (int)$group['id'];
        }
    }
    $mediaSlotParams = array_values(array_unique([...array_keys($standardSlotToGroup), ...array_keys($panoSlotToGroup)]));
    if ($mediaSlotParams) {
        $slotPlaceholders = implode(',', array_fill(0, count($mediaSlotParams), '?'));
        $mediaStmt = $conn->prepare("SELECT id, slot_assignment, file_path, media_type FROM media_cms
            WHERE slot_assignment IN ({$slotPlaceholders}) ORDER BY is_primary DESC, id ASC");
        if (!$mediaStmt) throw new RuntimeException('Unable to prepare hotel media lookup.');
        hotel_bind_values($mediaStmt, str_repeat('s', count($mediaSlotParams)), $mediaSlotParams);
        if (!$mediaStmt->execute()) throw new RuntimeException('Unable to load hotel group media.');
        $groupsById = [];
        foreach ($groups as $index => $group) $groupsById[(int)$group['id']] =& $groups[$index];
        $mediaResult = $mediaStmt->get_result();
        while ($media = $mediaResult->fetch_assoc()) {
            $slot = (string)$media['slot_assignment'];
            if ($media['media_type'] === '360') {
                foreach ($panoSlotToGroup[$slot] ?? [] as $groupId) if (isset($groupsById[$groupId])) {
                    $groupsById[$groupId]['pano_urls'][] = (string)$media['file_path'];
                    $groupsById[$groupId]['pano_media_ids'][] = (int)$media['id'];
                }
            } elseif ($media['media_type'] === 'standard') {
                foreach ($standardSlotToGroup[$slot] ?? [] as $groupId) if (isset($groupsById[$groupId])) $groupsById[$groupId]['gallery'][] = (string)$media['file_path'];
            }
        }
        $mediaStmt->close();
    }

    $rankCandidates = hotel_filter_recommendation_groups_by_type($groups, $requestedType);
    $ranked = hotel_rank_recommendation_groups($rankCandidates, $priority, $nights, $maxGuests, max(1, count($rankCandidates)), $availabilityChecked);
    $preferredTypeUnavailable = false;
    if (!$ranked && $availabilityChecked && is_string($requestedType) && $requestedType !== 'any') {
        $alternativeCandidates = array_values(array_filter($groups, static fn(array $group): bool => ($group['room_type_code'] ?? null) !== $requestedType));
        $ranked = hotel_rank_recommendation_groups($alternativeCandidates, $priority, $nights, $maxGuests, max(1, count($alternativeCandidates)), true);
        $preferredTypeUnavailable = (bool)$ranked;
    }
    $totalMatches = count($ranked);
    $results = [];
    foreach (array_slice($ranked, 0, 3) as $group) {
        $id = (int)$group['id'];
        if ((int)($group['venue_id'] ?? 0) < 1) continue;
        $result = [
            'id' => 'hotel-group-' . $id,
            'room_group_id' => $id,
            'venue_id' => (int)$group['venue_id'],
            'building_name' => (string)$group['building_name'],
            'room_type_code' => (string)$group['room_type_code'],
            'room_type' => (string)$group['room_type'],
            'comfort_rank' => (int)$group['comfort_rank'],
            'title' => (string)($group['display_name'] ?: ((string)$group['building_name'] . ' — ' . (string)$group['room_type'])),
            'category' => 'Hotel Room',
            'status' => $availabilityChecked ? 'Available' : 'Availability not checked',
            'review_key' => 'hotel-group-' . $id,
            'description' => (string)($group['description'] ?? ''),
            'amenities' => array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)($group['amenities'] ?? '')) ?: []), static fn(string $item): bool => $item !== '')),
            'capacity' => (int)$group['max_capacity'] . ' guests maximum',
            'capacity_value' => (int)$group['max_capacity'],
            'beds' => (int)$group['bed_count'] . ' bed' . ((int)$group['bed_count'] === 1 ? '' : 's'),
            'beds_value' => (int)$group['bed_count'],
            'rate' => '₱' . number_format((float)$group['nightly_rate'], 2) . ' /night',
            'rate_value' => (float)$group['nightly_rate'],
            'availability_checked' => $availabilityChecked,
            'pricing_basis' => $pricingBasis,
            'base_capacity' => (int)$group['base_capacity'],
            'max_capacity' => (int)$group['max_capacity'],
            'nightly_rate' => (float)$group['nightly_rate'],
            'extra_pax_rate' => (float)$group['extra_pax_rate'],
            'check_in_time' => (string)$group['check_in_time'],
            'check_out_time' => (string)$group['check_out_time'],
            'reasons' => hotel_recommendation_reasons($group, $priority, $nights, $maxGuests, $availabilityChecked, $exactGuestCount),
            'gallery' => array_values($group['gallery']),
            'pano_urls' => array_values($group['pano_urls']),
            'pano_media_ids' => array_values($group['pano_media_ids']),
            'hotspots_by_pano_index' => [],
        ];
        if ($preferredTypeUnavailable) {
            $result['is_alternative'] = true;
            $result['requested_room_type_code'] = $requestedType;
        }
        if ($availabilityChecked) $result['estimated_total'] = hotel_estimated_total($group, $nights, $maxGuests);
        else $result['estimated_nightly_amount'] = hotel_estimated_nightly_amount($group, $maxGuests);
        $results[] = $result;
    }
    $state = hotel_recommendation_state($totalMatches);
    $response = [
        'success' => true, 'state' => $state, 'guest_range' => (string)$guestRangeKey, 'group_size' => $exactGuestCount ? $guestCount : null, 'priority' => $priority,
        'room_type_code' => $requestedType,
        'check_in' => $checkIn?->format('Y-m-d'), 'check_out' => $checkOut?->format('Y-m-d'),
        'nights' => $availabilityChecked ? $nights : null, 'availability_checked' => $availabilityChecked,
        'pricing_basis' => $pricingBasis, 'checked_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        'total_matches' => $totalMatches, 'partial_match' => $state === 'partial', 'results' => $results,
    ];
    if ($preferredTypeUnavailable) {
        $response['preferred_type_unavailable'] = true;
        $response['requested_room_type_code'] = $requestedType;
        $response['requested_room_type'] = $requestedTypeLabel;
    }
    if ($availabilityChecked && $totalMatches === 0 && $checkIn !== null && $checkOut !== null) {
        $response['nearby_dates'] = hotel_recommendation_nearby_date_options($conn, $groups,
            $checkIn->format('Y-m-d'), $checkOut->format('Y-m-d'), $nights, $maxGuests, $priority, $sessionId);
    }
    if ($totalMatches === 0 && $pricingIssueCount > 0) {
        $response['reason_code'] = 'pricing_metadata_missing';
        if (($issueMessage = hotel_recommendation_issue_message('pricing_metadata_missing')) !== null) $response['message'] = $issueMessage;
    }
    return $response;
}

/** Exact room-group inventory lookup for a named availability question; party size is intentionally optional. */
function hotel_recommendation_exact_availability(mysqli $conn, int $venueId, int $roomGroupId, string $checkIn, string $checkOut, ?string $sessionId = null): array
{
    if ($venueId < 1 || $roomGroupId < 1) throw new InvalidArgumentException('Choose an exact hotel room group.');
    [$start, $end, $nights, $checked] = hotel_validate_optional_recommendation_dates($checkIn, $checkOut);
    if (!$checked || !$start || !$end) throw new InvalidArgumentException('Choose both hotel stay dates.');
    if (!hotel_group_schema_ready($conn)) throw new RuntimeException('Hotel room recommendation schema is not available.');

    $stmt = $conn->prepare("SELECT g.id, g.building_name, g.display_name, g.description, g.amenities,
            t.display_name AS room_type, g.base_capacity, g.max_capacity, g.bed_count,
            g.nightly_rate, g.extra_pax_rate, v.name AS venue_name
        FROM hotel_room_groups g
        INNER JOIN hotel_room_types t ON t.type_code = g.room_type_code AND t.active = 1
        INNER JOIN hotel_rooms h ON h.room_group_id = g.id
        INNER JOIN venues v ON v.id = h.venue_id AND v.category = 'Hotel Room'
        WHERE v.id = ? AND g.id = ? LIMIT 1");
    if (!$stmt) throw new RuntimeException('Unable to prepare hotel availability lookup.');
    $stmt->bind_param('ii', $venueId, $roomGroupId);
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to load the selected hotel room.'); }
    $group = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$group) throw new InvalidArgumentException('That hotel room group is no longer available in the catalog.');

    $availableUnits = hotel_available_group_units($conn, $start->format('Y-m-d'), $end->format('Y-m-d'), $sessionId ?? session_id(), [$roomGroupId]);
    $available = false;
    foreach ($availableUnits[$roomGroupId] ?? [] as $unit) if ((int)($unit['venue_id'] ?? 0) === $venueId) { $available = true; break; }
    $rate = is_numeric($group['nightly_rate'] ?? null) ? (float)$group['nightly_rate'] : null;
    $amenities = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)($group['amenities'] ?? '')) ?: []), static fn(string $value): bool => $value !== ''));
    $result = [
        'id' => 'hotel-group-' . $roomGroupId, 'venue_id' => $venueId, 'room_group_id' => $roomGroupId,
        'category' => 'Hotel Room', 'title' => (string)($group['display_name'] ?: ((string)$group['building_name'] . ' — ' . (string)$group['room_type'])),
        'building_name' => (string)$group['building_name'], 'room_type' => (string)$group['room_type'],
        'status' => $available ? 'Available' : 'Unavailable for these dates', 'availability_checked' => true,
        'description' => (string)($group['description'] ?? ''), 'amenities' => $amenities,
        'capacity' => (int)$group['max_capacity'] . ' guests maximum', 'capacity_value' => (int)$group['max_capacity'],
        'beds' => (int)$group['bed_count'] . ' bed' . ((int)$group['bed_count'] === 1 ? '' : 's'),
        'beds_value' => (int)$group['bed_count'],
        'rate' => $rate === null ? 'Not listed' : '₱' . number_format($rate, 2) . ' /night', 'rate_value' => $rate,
        'reasons' => [],
    ];
    return [
        'success' => true, 'state' => $available ? 'matched' : 'unavailable', 'intent' => 'Hotel Room',
        'exact_target' => true, 'target_found' => true, 'group_size' => null, 'priority' => null,
        'check_in' => $start->format('Y-m-d'), 'check_out' => $end->format('Y-m-d'), 'nights' => $nights,
        'availability_checked' => true, 'checked_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        'total_matches' => $available ? 1 : 0, 'results' => [$result],
    ];
}
