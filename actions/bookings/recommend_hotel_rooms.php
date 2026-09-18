<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/hotel_rooms.php';
require_once __DIR__ . '/../../includes/media_helper.php';
require_once __DIR__ . '/../../includes/rate_limit.php';

header('Content-Type: application/json; charset=UTF-8');

function hotel_recommendation_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hotel_recommendation_response(['success' => false, 'message' => 'Recommendations are available by request.'], 405);
}

try {
    if (!check_rate_limit($conn, 'hotel_room_recommendation', 30, 5)) {
        hotel_recommendation_response(['success' => false, 'message' => 'Too many room searches. Please wait a few minutes and try again.'], 429);
    }
} catch (Throwable $error) {
    error_log('Hotel recommendation rate limit failed: ' . get_class($error));
    hotel_recommendation_response(['success' => false, 'message' => 'Room recommendations are temporarily unavailable. Please try again.'], 503);
}

$requestData = $_POST;
$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType === 'application/json') {
    $rawBody = file_get_contents('php://input');
    $decodedBody = is_string($rawBody) ? json_decode($rawBody, true) : null;
    if (!is_array($decodedBody)) hotel_recommendation_response(['success' => false, 'message' => 'Invalid recommendation request.'], 400);
    $requestData = $decodedBody;
}

$guestRangeKey = $requestData['guest_range'] ?? null;
$guestRange = hotel_parse_guest_range($guestRangeKey);
$priority = hotel_parse_recommendation_priority($requestData['priority'] ?? null);
if (!$guestRange || !$priority) {
    hotel_recommendation_response(['success' => false, 'message' => 'Choose a guest count and recommendation priority.'], 422);
}
$checkIn = $requestData['check_in'] ?? null;
$checkOut = $requestData['check_out'] ?? null;
try {
    [$checkIn, $checkOut, $nights, $availabilityChecked] = hotel_validate_optional_recommendation_dates($checkIn, $checkOut);
} catch (InvalidArgumentException $error) {
    hotel_recommendation_response(['success' => false, 'message' => $error->getMessage()], 422);
}
$pricingBasis = $availabilityChecked ? 'full_stay' : 'estimated_nightly';

try {
    if (!hotel_group_schema_ready($conn)) {
        hotel_recommendation_response(['success' => false, 'message' => 'Room recommendations are temporarily unavailable.'], 503);
    }

    $maxGuests = (int)$guestRange['max'];
    $undatedInventoryFilter = $availabilityChecked ? '' : " AND EXISTS (
            SELECT 1 FROM hotel_rooms active_h
            INNER JOIN venues active_v ON active_v.id = active_h.venue_id
                AND active_v.category = 'Hotel Room' AND active_v.status = 'Available'
            WHERE active_h.room_group_id = g.id
        )";
    $groupStmt = $conn->prepare("SELECT g.id, g.building_name, g.display_name, g.description, g.amenities,
            g.legacy_room_type, g.room_type_code, t.display_name AS room_type,
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
        ? hotel_available_group_units($conn, $checkIn->format('Y-m-d'), $checkOut->format('Y-m-d'), session_id(), $groupIds)
        : [];
    foreach ($groups as &$group) {
        $id = (int)$group['id'];
        $group['id'] = $id;
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
    foreach ($groups as $group) {
        $commonIssue = hotel_group_common_recommendation_issue($group, $maxGuests, $availabilityChecked);
        if ($commonIssue === 'pricing_metadata_missing') $pricingIssueCount++;
    }

    // Explicit group mappings win. Otherwise resolve the same legacy slot used
    // by admin_cms from the building and unambiguous physical room-type label.
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
        $mediaSql = "SELECT id, slot_assignment, file_path, media_type FROM media_cms
            WHERE slot_assignment IN ({$slotPlaceholders})
            ORDER BY is_primary DESC, id ASC";
        $mediaStmt = $conn->prepare($mediaSql);
        if (!$mediaStmt) throw new RuntimeException('Unable to prepare hotel media lookup.');
        hotel_bind_values($mediaStmt, str_repeat('s', count($mediaSlotParams)), $mediaSlotParams);
        if (!$mediaStmt->execute()) throw new RuntimeException('Unable to load hotel group media.');
        $groupsById = [];
        foreach ($groups as $index => $group) $groupsById[(int)$group['id']] =& $groups[$index];
        $mediaResult = $mediaStmt->get_result();
        while ($media = $mediaResult->fetch_assoc()) {
            $slot = (string)$media['slot_assignment'];
            if ($media['media_type'] === '360') {
                foreach ($panoSlotToGroup[$slot] ?? [] as $groupId) {
                    if (!isset($groupsById[$groupId])) continue;
                    $groupsById[$groupId]['pano_urls'][] = (string)$media['file_path'];
                    $groupsById[$groupId]['pano_media_ids'][] = (int)$media['id'];
                }
            } elseif ($media['media_type'] === 'standard') {
                foreach ($standardSlotToGroup[$slot] ?? [] as $groupId) {
                    if (!isset($groupsById[$groupId])) continue;
                    $groupsById[$groupId]['gallery'][] = (string)$media['file_path'];
                }
            }
        }
        $mediaStmt->close();
    }

    $ranked = hotel_rank_recommendation_groups($groups, $priority, $nights, $maxGuests, max(1, count($groups)), $availabilityChecked);
    $totalMatches = count($ranked);
    $results = [];
    foreach (array_slice($ranked, 0, 3) as $group) {
        $id = (int)$group['id'];
        $result = [
            'id' => 'hotel-group-' . $id,
            'room_group_id' => $id,
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
            'reasons' => hotel_recommendation_reasons($group, $priority, $nights, $maxGuests, $availabilityChecked),
            'gallery' => array_values($group['gallery']),
            'pano_urls' => array_values($group['pano_urls']),
            'pano_media_ids' => array_values($group['pano_media_ids']),
            'hotspots_by_pano_index' => [],
        ];
        if ($availabilityChecked) {
            $result['estimated_total'] = hotel_estimated_total($group, $nights, $maxGuests);
        } else {
            $result['estimated_nightly_amount'] = hotel_estimated_nightly_amount($group, $maxGuests);
        }
        $results[] = $result;
    }

    $state = hotel_recommendation_state($totalMatches);
    $response = [
        'success' => true,
        'state' => $state,
        'guest_range' => (string)$guestRangeKey,
        'priority' => $priority,
        'check_in' => $checkIn?->format('Y-m-d'),
        'check_out' => $checkOut?->format('Y-m-d'),
        'nights' => $availabilityChecked ? $nights : null,
        'availability_checked' => $availabilityChecked,
        'pricing_basis' => $pricingBasis,
        'checked_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        'total_matches' => $totalMatches,
        'partial_match' => $state === 'partial',
        'results' => $results,
    ];
    $issueCode = null;
    if ($totalMatches === 0 && $pricingIssueCount > 0) {
        $issueCode = 'pricing_metadata_missing';
    }
    if ($issueCode !== null && ($issueMessage = hotel_recommendation_issue_message($issueCode)) !== null) {
        $response['reason_code'] = $issueCode;
        $response['message'] = $issueMessage;
    }
    hotel_recommendation_response($response);
} catch (InvalidArgumentException $error) {
    hotel_recommendation_response(['success' => false, 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('Hotel recommendation lookup failed: ' . get_class($error));
    hotel_recommendation_response(['success' => false, 'message' => 'Room recommendations are temporarily unavailable. Please try again.'], 500);
}
