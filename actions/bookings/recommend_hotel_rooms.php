<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/hotel_recommendation_service.php';
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
if (!array_key_exists('group_size', $requestData) && array_key_exists('guest_count', $requestData)) {
    $requestData['group_size'] = $requestData['guest_count'];
}

try {
    $response = hotel_recommendation_search($conn, $requestData, session_id());
    hotel_recommendation_response($response);
} catch (InvalidArgumentException $error) {
    hotel_recommendation_response(['success' => false, 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('Hotel recommendation lookup failed: ' . get_class($error));
    hotel_recommendation_response(['success' => false, 'message' => 'Room recommendations are temporarily unavailable. Please try again.'], 500);
}
