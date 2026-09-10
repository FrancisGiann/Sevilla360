<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=60');

/**
 * Featured reviews are deliberately a separate contract from the venue modal
 * endpoint. Keep this response to fields that are safe to publish on the
 * homepage, even if the query needs private identifiers internally.
 */
$emptyResponse = static function (): never {
    echo json_encode(['success' => true, 'reviews' => []], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

$tableResult = $conn->query("SHOW TABLES LIKE 'venue_reviews'");
if (!$tableResult instanceof mysqli_result || $tableResult->num_rows === 0) {
    $emptyResponse();
}

// The candidate pool is intentionally bounded before the diversity pass. This
// keeps the public endpoint predictable even when the review table grows.
$reviewStmt = $conn->prepare("SELECT vr.id, vr.rating, vr.review_text, vr.created_at,
        c.first_name, c.last_name,
        v.id AS venue_id, v.name AS venue_name, v.category,
        h.room_type
    FROM venue_reviews vr
    INNER JOIN bookings b ON b.id = vr.booking_id
    INNER JOIN venues v ON v.id = vr.venue_id AND v.status = 'Available'
    LEFT JOIN hotel_rooms h ON h.venue_id = v.id
    INNER JOIN customers c ON c.id = vr.customer_id
    WHERE vr.moderation_status = 'Approved'
      AND TRIM(COALESCE(vr.review_text, '')) <> ''
      AND b.booking_status <> 'Cancelled'
      AND COALESCE(b.payment_status, '') <> 'Refunded'
      AND v.category IN ('Event Hall', 'Hotel Room', 'Resort Villa')
      AND (v.category <> 'Hotel Room' OR TRIM(COALESCE(h.room_type, '')) <> '')
    ORDER BY vr.created_at DESC, vr.id DESC
    LIMIT 50");
if (!$reviewStmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Featured reviews are temporarily unavailable.'], JSON_UNESCAPED_SLASHES);
    exit;
}

if (!$reviewStmt->execute()) {
    $reviewStmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Featured reviews are temporarily unavailable.'], JSON_UNESCAPED_SLASHES);
    exit;
}
$result = $reviewStmt->get_result();
if (!$result instanceof mysqli_result) {
    $reviewStmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Featured reviews are temporarily unavailable.'], JSON_UNESCAPED_SLASHES);
    exit;
}
$candidates = [];
$allowedCategories = ['Event Hall', 'Hotel Room', 'Resort Villa'];

while ($row = $result->fetch_assoc()) {
    $category = trim((string)($row['category'] ?? ''));
    $venueNameRaw = (string)($row['venue_name'] ?? '');
    $roomTypeRaw = (string)($row['room_type'] ?? '');
    $venueName = trim($venueNameRaw);
    $roomType = trim($roomTypeRaw);
    $reviewText = trim(strip_tags((string)($row['review_text'] ?? '')));
    $firstName = trim((string)($row['first_name'] ?? ''));
    $lastName = trim((string)($row['last_name'] ?? ''));
    $rating = filter_var($row['rating'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);

    if (!in_array($category, $allowedCategories, true) || $venueName === '' || $reviewText === '' || $firstName === '' || $rating === false) {
        continue;
    }

    if ($category === 'Hotel Room') {
        if ($roomType === '') continue;
        $reviewKey = 'hotel-' . md5($venueNameRaw . ' - ' . $roomTypeRaw);
    } elseif ($category === 'Event Hall') {
        $reviewKey = 'event-' . (int)$row['venue_id'];
    } else {
        $reviewKey = 'villa-' . (int)$row['venue_id'];
    }

    $initial = function_exists('mb_substr') ? mb_substr($lastName, 0, 1, 'UTF-8') : substr($lastName, 0, 1);
    $reviewer = $firstName . ($initial !== '' ? ' ' . $initial . '.' : '');
    $candidates[] = [
        'id' => (int)$row['id'],
        'review_key' => $reviewKey,
        'rating' => (int)$rating,
        'review_text' => $reviewText,
        'reviewer' => $reviewer,
        'created_at' => (string)$row['created_at'],
        'category' => $category,
        'venue_name' => $venueName,
        'room_type' => $roomType,
    ];
}
$reviewStmt->close();

// First give each available offering its newest review, then fill the cap in
// the original newest-first order. Candidate order is already deterministic.
$selected = [];
$selectedKeys = [];
$selectedIds = [];
foreach ($candidates as $candidate) {
    if (isset($selectedKeys[$candidate['review_key']])) continue;
    $selected[] = $candidate;
    $selectedKeys[$candidate['review_key']] = true;
    $selectedIds[$candidate['id']] = true;
    if (count($selected) >= 12) break;
}
if (count($selected) < 12) {
    foreach ($candidates as $candidate) {
        if (isset($selectedIds[$candidate['id']])) continue;
        $selected[] = $candidate;
        $selectedIds[$candidate['id']] = true;
        if (count($selected) >= 12) break;
    }
}

$reviews = array_map(static function (array $review): array {
    return [
        'rating' => $review['rating'],
        'review_text' => $review['review_text'],
        'reviewer' => $review['reviewer'],
        'created_at' => $review['created_at'],
        'category' => $review['category'],
        'venue_name' => $review['venue_name'],
        'room_type' => $review['room_type'],
        'review_key' => $review['review_key'],
    ];
}, $selected);

echo json_encode(['success' => true, 'reviews' => $reviews], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
