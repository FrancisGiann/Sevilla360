<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';

header('Content-Type: application/json; charset=UTF-8');

function venue_reviews_public_error(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$venueKey = $_GET['venue_key'] ?? '';
if (!is_string($venueKey) || !preg_match('/\A(?:event|villa)-[1-9][0-9]*\z|\Ahotel-[a-f0-9]{32}\z/', $venueKey)) {
    venue_reviews_public_error(422, 'A valid venue key is required.');
}

$venueIds = [];
$keyType = str_starts_with($venueKey, 'hotel-') ? 'hotel' : (str_starts_with($venueKey, 'event-') ? 'event' : 'villa');
if ($keyType === 'hotel') {
    $digest = substr($venueKey, 6);
    $stmt = $conn->prepare("SELECT DISTINCT h.venue_id
        FROM hotel_rooms h INNER JOIN venues v ON v.id = h.venue_id
        WHERE v.category = 'Hotel Room' AND v.status = 'Available'
          AND MD5(CONCAT(v.name, ' - ', h.room_type)) = ?");
    $stmt->bind_param('s', $digest);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $venueIds = array_map(static fn($row) => (int)$row['venue_id'], $rows);
} else {
    $id = (int)substr($venueKey, strpos($venueKey, '-') + 1);
    $category = $keyType === 'event' ? 'Event Hall' : 'Resort Villa';
    $stmt = $conn->prepare('SELECT id FROM venues WHERE id = ? AND category = ? AND status = \'Available\' LIMIT 1');
    $stmt->bind_param('is', $id, $category);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) $venueIds[] = (int)$row['id'];
    $stmt->close();
}
if (!$venueIds) venue_reviews_public_error(404, 'Venue not found.');

$placeholders = implode(',', array_fill(0, count($venueIds), '?'));
$types = str_repeat('i', count($venueIds));
$bindValues = $venueIds;
$bindRefs = [];
foreach ($bindValues as $index => &$bindValue) $bindRefs[$index] =& $bindValue;
$bindRefs = array_values($bindRefs);
$avgStmt = $conn->prepare("SELECT COALESCE(AVG(vr.rating), 0) AS rating_average, COUNT(*) AS rating_count
    FROM venue_reviews vr INNER JOIN bookings b ON b.id = vr.booking_id
    WHERE vr.moderation_status = 'Approved' AND b.booking_status <> 'Cancelled'
      AND COALESCE(b.payment_status, '') <> 'Refunded' AND vr.venue_id IN ($placeholders)");
$avgStmt->bind_param($types, ...$bindRefs);
$avgStmt->execute();
$aggregate = $avgStmt->get_result()->fetch_assoc() ?: ['rating_average' => 0, 'rating_count' => 0];
$avgStmt->close();

$reviewStmt = $conn->prepare("SELECT vr.rating, vr.review_text, vr.created_at, c.first_name, c.last_name
    FROM venue_reviews vr INNER JOIN customers c ON c.id = vr.customer_id
    INNER JOIN bookings b ON b.id = vr.booking_id
    WHERE vr.moderation_status = 'Approved' AND b.booking_status <> 'Cancelled'
      AND COALESCE(b.payment_status, '') <> 'Refunded' AND vr.venue_id IN ($placeholders)
    ORDER BY vr.created_at DESC, vr.id DESC LIMIT 3");
$reviewStmt->bind_param($types, ...$bindRefs);
$reviewStmt->execute();
$reviewResult = $reviewStmt->get_result();
$reviews = [];
while ($row = $reviewResult->fetch_assoc()) {
    $lastName = trim((string)($row['last_name'] ?? ''));
    $initial = function_exists('mb_substr') ? mb_substr($lastName, 0, 1) : substr($lastName, 0, 1);
    $reviews[] = [
        'rating' => (int)$row['rating'],
        // Strip markup before JSON encoding; clients still render through
        // textContent so a hostile review cannot become executable HTML.
        'review_text' => trim(strip_tags((string)($row['review_text'] ?? ''))),
        'reviewer' => trim((string)$row['first_name']) . ($lastName !== '' ? ' ' . $initial . '.' : ''),
        'created_at' => (string)$row['created_at'],
    ];
}
$reviewStmt->close();

echo json_encode([
    'success' => true,
    'venue_key' => $venueKey,
    'rating_average' => round((float)$aggregate['rating_average'], 1),
    'rating_count' => (int)$aggregate['rating_count'],
    'reviews' => $reviews,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
