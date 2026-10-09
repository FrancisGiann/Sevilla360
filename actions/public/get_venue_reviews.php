<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../includes/hotel_rooms.php';
require_once __DIR__ . '/../../includes/public_venue_reviews.php';

header('Content-Type: application/json; charset=UTF-8');

function venue_reviews_log_failure(VenueReviewsQueryFailure $failure): void
{
    $stage = preg_match('/\A[a-z_]+\z/', $failure->stage) === 1 ? $failure->stage : 'unknown';
    $causeClass = preg_match('/\A[A-Za-z0-9_\\\\]+\z/', $failure->causeClass) === 1 ? $failure->causeClass : 'Throwable';
    $sqlState = preg_match('/\A[A-Z0-9]{5}\z/', $failure->sqlState) === 1 ? $failure->sqlState : '00000';
    error_log(sprintf('venue_reviews failure stage=%s exception=%s sqlstate=%s code=%d', $stage, $causeClass, $sqlState, $failure->errorCode));
}

function venue_reviews_send_failure(VenueReviewsQueryFailure $failure): never
{
    venue_reviews_log_failure($failure);
    http_response_code(503);
    echo '{"success":false,"message":"Reviews are temporarily unavailable."}';
    exit;
}

define('SEVILLA_PUBLIC_VENUE_REVIEWS', true);
try {
    require_once __DIR__ . '/../../config/db_connect.php';
} catch (Throwable $cause) {
    venue_reviews_send_failure(new VenueReviewsQueryFailure(
        'db_connection',
        get_class($cause),
        method_exists($cause, 'getSqlState') ? (string)$cause->getSqlState() : '00000',
        (int)$cause->getCode(),
        $cause
    ));
}

$venueKey = $_GET['venue_key'] ?? '';
$response = venue_reviews_public_response($conn, $venueKey, 'venue_reviews_log_failure');
http_response_code($response['status']);
$json = json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($json === false) {
    venue_reviews_send_failure(new VenueReviewsQueryFailure('response_encoding', 'JsonException', '00000', json_last_error()));
}
echo $json;
