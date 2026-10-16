<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/hotel_rooms.php';
require_once __DIR__ . '/../../includes/request_context.php';

function hotel_media_mode_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    hotel_media_mode_response(['success' => false, 'message' => 'Invalid request method.'], 405);
    exit;
}
if (($_SESSION['role'] ?? null) !== 'admin') {
    hotel_media_mode_response(['success' => false, 'message' => 'Unauthorized access.'], 401);
    exit;
}
$csrf = $_SESSION['csrf_token'] ?? null;
$requestCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($csrf) || !is_string($requestCsrf) || !hash_equals($csrf, $requestCsrf)) {
    hotel_media_mode_response(['success' => false, 'message' => 'CSRF validation failed.'], 403);
    exit;
}

try {
    $payloadObject = json_decode((string)file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
    if (!$payloadObject instanceof stdClass) throw new InvalidArgumentException('A JSON object is required.');
    $payload = get_object_vars($payloadObject);
    $groupId = filter_var($payload['room_group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $kind = $payload['kind'] ?? null;
    $enabled = $payload['enabled'] ?? null;
    if ($groupId === false || !in_array($kind, ['standard', '360'], true) || !in_array($enabled, [0, 1, false, true], true)) {
        throw new InvalidArgumentException('A valid room option and gallery mode are required.');
    }
    require_once __DIR__ . '/../../config/db_connect.php';
    if (!hotel_group_media_overrides_schema_ready($conn)) {
        hotel_media_mode_response(['success' => false, 'message' => 'Room-option galleries are not available yet. Apply migration 032 and refresh this page.'], 503);
        exit;
    }
    if (!$conn->begin_transaction()) throw new RuntimeException('Could not start transaction.');
    $transactionStarted = true;
    $identity = hotel_room_group_option_media_identity($conn, (int)$groupId, true);
    if (!$identity) throw new InvalidArgumentException('This room option is invalid or no longer has active inventory.');
    $column = $kind === '360' ? 'tour_media_override_enabled' : 'standard_media_override_enabled';
    $value = (int)(bool)$enabled;
    $stmt = $conn->prepare("UPDATE hotel_room_groups SET {$column} = ? WHERE id = ?");
    if (!$stmt) throw new RuntimeException('Could not update room-option gallery mode.');
    $stmt->bind_param('ii', $value, $groupId);
    if (!$stmt->execute()) throw new RuntimeException('Could not update room-option gallery mode.');
    $stmt->close();
    if (!$conn->commit()) throw new RuntimeException('Could not save room-option gallery mode.');
    $transactionStarted = false;
    hotel_media_mode_response(['success' => true, 'room_group_id' => (int)$groupId, 'kind' => $kind, 'enabled' => $value]);
} catch (JsonException|InvalidArgumentException $error) {
    if (!empty($transactionStarted) && isset($conn) && $conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $rollbackError) { error_log('Hotel option media rollback failed: ' . $rollbackError->getMessage()); }
    }
    hotel_media_mode_response(['success' => false, 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    if (!empty($transactionStarted) && isset($conn) && $conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $rollbackError) { error_log('Hotel option media rollback failed: ' . $rollbackError->getMessage()); }
    }
    error_log('Hotel option media mode update failed: ' . $error->getMessage());
    hotel_media_mode_response(['success' => false, 'message' => 'The room-option gallery could not be updated. Please try again.'], 500);
}
