<?php
declare(strict_types=1);
require_once __DIR__ . '/booking_rules.php';
require_once __DIR__ . '/seminars.php';

/** Advisory state for one physical room or villa; inventory claims still recheck under venue locks. */
function booking_selection_availability_state(mysqli $conn, int $venueId, string $category, string $start, string $end, string $sessionId): string
{
    $venue = $conn->prepare('SELECT category, status FROM venues WHERE id = ? LIMIT 1');
    $venue->bind_param('i', $venueId);
    if (!$venue->execute()) throw new RuntimeException('Unable to check the selected venue.');
    $row = $venue->get_result()->fetch_assoc();
    if (!$row || $row['category'] !== $category || $row['status'] !== 'Available') return 'unavailable';
    $overlap = booking_overlap_sql($category);
    $booked = $conn->prepare("SELECT id FROM bookings WHERE venue_id = ? AND booking_status IN ('Pending','Confirmed','Completed') AND COALESCE(source,'') <> 'Maintenance' AND {$overlap} LIMIT 1");
    $booked->bind_param('iss', $venueId, $end, $start);
    if (!$booked->execute()) throw new RuntimeException('Unable to check bookings.');
    if ($booked->get_result()->num_rows > 0) return 'unavailable';
    $addonOverlap = booking_overlap_sql($category, 'br.start_date', 'br.end_date');
    $addon = $conn->prepare("SELECT br.id FROM booking_rooms br JOIN bookings b ON b.id = br.booking_id JOIN venues parent_v ON parent_v.id = b.venue_id WHERE br.venue_id = ? AND b.booking_status IN ('Pending','Confirmed','Completed') AND NOT (b.booking_status = 'Pending' AND parent_v.category = 'Event Hall') AND {$addonOverlap} LIMIT 1");
    $addon->bind_param('iss', $venueId, $end, $start);
    if (!$addon->execute()) throw new RuntimeException('Unable to check room allocation.');
    if ($addon->get_result()->num_rows > 0) return 'unavailable';
    $maintenance = $conn->prepare("SELECT id FROM maintenance WHERE venue_id = ? AND is_blocking = 1 AND (status = 'Scheduled' OR status IS NULL) AND start_date <= ? AND end_date >= ? LIMIT 1");
    $maintenance->bind_param('iss', $venueId, $end, $start);
    if (!$maintenance->execute()) throw new RuntimeException('Unable to check maintenance.');
    if ($maintenance->get_result()->num_rows > 0 || seminar_has_resource_conflict($conn, $venueId, $start, $end, null, false)) return 'unavailable';
    $held = $conn->prepare("SELECT id FROM booking_locks WHERE venue_id = ? AND session_id <> ? AND expires_at > NOW() AND {$overlap} LIMIT 1");
    $held->bind_param('isss', $venueId, $sessionId, $end, $start);
    if (!$held->execute()) throw new RuntimeException('Unable to check temporary holds.');
    return $held->get_result()->num_rows > 0 ? 'held' : 'available';
}
