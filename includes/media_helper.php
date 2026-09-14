<?php
/**
 * SEVILLA360 - CMS Media Helper
 * 
 * Resolves the CMS image URL for a venue/room display name.
 * Uses the exact normalization convention from admin_cms.php so slot keys match.
 * 
 * Slot naming convention:
 *   Event halls / villas:  venue_<normalized venue name>
 *   Hotel rooms:           venue_<normalized "Building Name - Room Type">
 * 
 * @param mysqli $conn       Active database connection
 * @param string $display_name  The human-readable name to look up
 * @return string            Relative file_path from media_cms, or placeholder
 */
/** Build a CMS venue slot exactly as includes/admin-page/admin_cms.php does. */
function media_cms_venue_slot_key(string $display_name): string
{
    $safe_id = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $display_name));
    $safe_id = trim($safe_id, '_');
    return 'venue_' . $safe_id;
}

/**
 * Resolve a hotel's media slot: a saved explicit mapping wins, otherwise use
 * the legacy CMS convention with the exact physical room-type label retained
 * by the group's active inventory. This never infers a type/tier from names.
 */
function hotel_room_group_media_slot_key(array $group): ?string
{
    $explicit = trim((string)($group['media_slot_key'] ?? ''));
    if ($explicit !== '') {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/', $explicit) === 1 ? $explicit : null;
    }

    $building = trim((string)($group['building_name'] ?? ''));
    $roomType = trim((string)($group['legacy_media_room_type'] ?? ''));
    if ($roomType === '') $roomType = trim((string)($group['legacy_room_type'] ?? ''));
    if ($roomType === '') $roomType = trim((string)($group['room_type'] ?? ''));
    if ($building === '' || $roomType === '') return null;
    return media_cms_venue_slot_key($building . ' - ' . $roomType);
}

/** Resolve homepage media without excluding inventory that has no photo. */
function hotel_room_group_public_images(array $group, array $mediaBySlot): array
{
    $slotKey = hotel_room_group_media_slot_key($group);
    $images = $slotKey !== null && isset($mediaBySlot[$slotKey]) && is_array($mediaBySlot[$slotKey])
        ? $mediaBySlot[$slotKey]
        : [];
    $images = array_values(array_filter(array_map(static fn($path): string => trim((string)$path), $images)));
    return $images ?: ['assets/img/placeholder.jpg'];
}

function get_venue_image(mysqli $conn, string $display_name): string {
    $slot_key = media_cms_venue_slot_key($display_name);

    $stmt = $conn->prepare(
        "SELECT file_path FROM media_cms 
         WHERE slot_assignment = ? AND media_type = 'standard' 
         ORDER BY is_primary DESC, id DESC 
         LIMIT 1"
    );
    if (!$stmt) return 'assets/img/placeholder.jpg';

    $stmt->bind_param('s', $slot_key);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['file_path'];
    }

    return 'assets/img/placeholder.jpg';
}

/** Resolve a room-group image through its explicit mapping or exact legacy CMS slot. */
function get_hotel_room_group_image(mysqli $conn, int $room_group_id): string {
    if ($room_group_id < 1) return 'assets/img/placeholder.jpg';
    $groupStmt = $conn->prepare("SELECT g.media_slot_key, g.building_name, g.legacy_room_type,
            COALESCE(NULLIF(t.display_name, ''), g.legacy_room_type) AS room_type,
            (SELECT CASE WHEN COUNT(DISTINCT h.room_type) = 1 THEN MIN(h.room_type) ELSE NULL END
             FROM hotel_rooms h
             INNER JOIN venues v ON v.id = h.venue_id AND v.category = 'Hotel Room' AND v.status = 'Available'
             WHERE h.room_group_id = g.id) AS legacy_media_room_type
        FROM hotel_room_groups g
        LEFT JOIN hotel_room_types t ON t.type_code = g.room_type_code
        WHERE g.id = ? LIMIT 1");
    if (!$groupStmt) return 'assets/img/placeholder.jpg';
    $groupStmt->bind_param('i', $room_group_id);
    if (!$groupStmt->execute()) return 'assets/img/placeholder.jpg';
    $group = $groupStmt->get_result()->fetch_assoc();
    $groupStmt->close();
    if (!is_array($group)) return 'assets/img/placeholder.jpg';
    $slotKey = hotel_room_group_media_slot_key($group);
    if ($slotKey === null) return 'assets/img/placeholder.jpg';

    $stmt = $conn->prepare("SELECT file_path FROM media_cms
        WHERE slot_assignment = ? AND media_type = 'standard'
        ORDER BY is_primary DESC, id DESC LIMIT 1");
    if (!$stmt) return 'assets/img/placeholder.jpg';
    $stmt->bind_param('s', $slotKey);
    if (!$stmt->execute()) return 'assets/img/placeholder.jpg';
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return is_array($row) && !empty($row['file_path']) ? (string)$row['file_path'] : 'assets/img/placeholder.jpg';
}
?>
