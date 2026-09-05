<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/booking_lifecycle.php';

header('Content-Type: application/json; charset=UTF-8');

function venue_review_error(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SESSION['role'] ?? '') !== 'customer' || empty($_SESSION['user_id'])) {
    venue_review_error(403, 'Customer access is required.');
}
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (!is_string($csrf) || !is_string($_SESSION['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    venue_review_error(403, 'CSRF validation failed.');
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) $payload = $_POST;
$bookingId = filter_var($payload['booking_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$rating = filter_var($payload['rating'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
$reviewText = $payload['review_text'] ?? '';
if (!$bookingId || !$rating || !is_string($reviewText)) venue_review_error(422, 'Choose a rating and enter valid review details.');
$reviewText = trim($reviewText);
$reviewLength = function_exists('mb_strlen') ? mb_strlen($reviewText, 'UTF-8') : strlen($reviewText);
if ($reviewLength > 1000) venue_review_error(422, 'Review text must be 1000 characters or fewer.');
$reviewText = $reviewText === '' ? null : $reviewText;

$customerUserId = (int)$_SESSION['user_id'];
$bookingCompletionSql = booking_completion_sql('b');

try {
    $conn->begin_transaction();
    $bookingStmt = $conn->prepare("SELECT b.id, b.customer_id, b.venue_id, v.category
        FROM bookings b
        INNER JOIN customers c ON c.id = b.customer_id AND c.user_id = ?
        INNER JOIN venues v ON v.id = b.venue_id
        WHERE b.id = ?
          AND b.source <> 'Maintenance'
          AND b.booking_status NOT IN ('Cancelled')
          AND b.payment_status <> 'Refunded'
          AND ($bookingCompletionSql)
          AND v.category IN ('Event Hall', 'Hotel Room', 'Resort Villa')
        LIMIT 1 FOR UPDATE");
    $bookingStmt->bind_param('ii', $customerUserId, $bookingId);
    $bookingStmt->execute();
    $booking = $bookingStmt->get_result()->fetch_assoc();
    $bookingStmt->close();
    if (!$booking) throw new RuntimeException('This booking is not eligible for a venue review.');

    $existingStmt = $conn->prepare('SELECT id FROM venue_reviews WHERE booking_id = ? LIMIT 1 FOR UPDATE');
    $existingStmt->bind_param('i', $bookingId);
    $existingStmt->execute();
    $existing = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();

    if ($existing) {
        $reviewStmt = $conn->prepare("UPDATE venue_reviews SET rating = ?, review_text = ?, moderation_status = 'Pending', admin_note = NULL, moderated_by = NULL, moderated_at = NULL WHERE id = ? AND booking_id = ? AND customer_id = ?");
        $reviewStmt->bind_param('isiii', $rating, $reviewText, $existing['id'], $bookingId, $booking['customer_id']);
        if (!$reviewStmt->execute()) throw new RuntimeException('Unable to update the review.');
        $reviewStmt->close();
        $message = 'Your review was updated and returned to moderation.';
    } else {
        $reviewStmt = $conn->prepare("INSERT INTO venue_reviews (booking_id, customer_id, venue_id, rating, review_text) VALUES (?, ?, ?, ?, ?)");
        $reviewStmt->bind_param('iiiis', $bookingId, $booking['customer_id'], $booking['venue_id'], $rating, $reviewText);
        if (!$reviewStmt->execute()) throw new RuntimeException('Unable to save the review.');
        $reviewStmt->close();
        $message = 'Thank you. Your review is pending moderation.';
    }
    $conn->commit();
    echo json_encode(['success' => true, 'message' => $message, 'status' => 'Pending']);
} catch (Throwable $e) {
    $conn->rollback();
    if ($e instanceof RuntimeException) venue_review_error(422, $e->getMessage());
    error_log('Venue review save failed: ' . $e->getMessage());
    venue_review_error(500, 'The review could not be saved. Please try again.');
}
