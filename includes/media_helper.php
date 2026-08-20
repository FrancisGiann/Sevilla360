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
function get_venue_image(mysqli $conn, string $display_name): string {
    // Normalize exactly as admin_cms.php does (lines 45-46)
    $safe_id = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $display_name));
    $safe_id = trim($safe_id, '_');
    $slot_key = 'venue_' . $safe_id;

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
?>
