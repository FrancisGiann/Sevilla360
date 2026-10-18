<?php
declare(strict_types=1);

require_once __DIR__ . '/media_helper.php';
require_once __DIR__ . '/hotel_rooms.php';

/** Classify the finite set of CMS upload destinations without trusting client slot patterns. */
function media_upload_classify_website_slot(string $websiteSlot): ?array
{
    $homeSlots = [
        'home-hero' => 'standard',
        'home-about' => 'standard',
        'home-exp-1' => 'standard',
        'home-exp-2' => 'standard',
        'home-exp-3' => 'standard',
    ];
    if (isset($homeSlots[$websiteSlot])) {
        return ['expected_media_type' => $homeSlots[$websiteSlot], 'canonical_gallery_slot' => null];
    }
    if ($websiteSlot === 'gallery') {
        return ['expected_media_type' => null, 'canonical_gallery_slot' => null];
    }
    if (preg_match('/\Avenue_[a-z0-9]+(?:_[a-z0-9]+)*_360\z/D', $websiteSlot) === 1) {
        return ['expected_media_type' => '360', 'canonical_gallery_slot' => null];
    }
    if (preg_match('/\Avenue_[a-z0-9]+(?:_[a-z0-9]+)*\z/D', $websiteSlot) === 1) {
        return ['expected_media_type' => 'standard', 'canonical_gallery_slot' => null];
    }
    if (preg_match('/\A(hotel_type_[a-f0-9]{40})(_360)?\z/D', $websiteSlot, $matches) === 1) {
        return [
            'expected_media_type' => ($matches[2] ?? '') === '_360' ? '360' : 'standard',
            'canonical_gallery_slot' => $matches[1],
        ];
    }
    if (preg_match('/\Ahotel_option_([1-9][0-9]*)(_360)?\z/D', $websiteSlot, $matches) === 1) {
        $groupId = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($groupId === false) return null;
        return [
            'expected_media_type' => ($matches[2] ?? '') === '_360' ? '360' : 'standard',
            'canonical_gallery_slot' => null,
            'option_group_id' => (int)$groupId,
        ];
    }

    return null;
}

/** Validate deterministic option slots against active inventory before any media row is written. */
function media_upload_option_slot_exists(mysqli $conn, int $groupId, string $websiteSlot): bool
{
    $tour = str_ends_with($websiteSlot, '_360');
    if (hotel_room_group_option_media_slot_key($groupId, $tour) !== $websiteSlot) return false;
    return hotel_room_group_option_media_identity($conn, $groupId, true) !== null;
}

/** Match a canonical CMS gallery key against one actual hotel inventory identity. */
function media_upload_canonical_slot_matches_inventory_row(string $canonicalSlot, array $row): bool
{
    if (preg_match('/\Ahotel_type_[a-f0-9]{40}\z/D', $canonicalSlot) !== 1) return false;
    return hotel_room_group_canonical_media_slot_key($row) === $canonicalSlot;
}

/** Validate canonical upload destinations against the same non-inactive inventory CMS cards use. */
function media_upload_canonical_slot_exists(mysqli $conn, string $canonicalSlot): bool
{
    if (preg_match('/\Ahotel_type_[a-f0-9]{40}\z/D', $canonicalSlot) !== 1) return false;

    if (hotel_group_schema_ready($conn)) {
        $sql = "SELECT DISTINCT g.building_name, g.legacy_room_type,
                       COALESCE(t.display_name, g.legacy_room_type, 'Hotel Room') AS room_type,
                       g.room_type_code
                FROM hotel_room_groups g
                LEFT JOIN hotel_room_types t ON t.type_code = g.room_type_code
                INNER JOIN hotel_rooms h ON h.room_group_id = g.id
                INNER JOIN venues v ON v.id = h.venue_id AND v.category = 'Hotel Room' AND v.status <> 'Inactive'";
    } else {
        $sql = "SELECT DISTINCT v.name AS building_name, h.room_type,
                       h.room_type AS legacy_room_type, '' AS room_type_code
                FROM venues v
                INNER JOIN hotel_rooms h ON h.venue_id = v.id
                WHERE v.category = 'Hotel Room' AND v.status <> 'Inactive'";
    }

    $result = $conn->query($sql);
    if (!$result) throw new RuntimeException('Unable to validate hotel gallery destination.');
    while ($row = $result->fetch_assoc()) {
        if (media_upload_canonical_slot_matches_inventory_row($canonicalSlot, $row)) return true;
    }
    return false;
}
