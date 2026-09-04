<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');

function set_primary_media_response(array $payload, int $status_code = 200): void
{
    http_response_code($status_code);
    echo json_encode($payload);
}

final class SetPrimaryMediaException extends RuntimeException
{
    public function __construct(string $message, private int $statusCode = 500)
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    set_primary_media_response(['success' => false, 'message' => 'Invalid request method.'], 405);
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    set_primary_media_response(['success' => false, 'message' => 'Unauthorized access.'], 401);
    exit;
}

// ==========================================
// CSRF PROTECTION GUARD (JSON)
// ==========================================
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$session_csrf_token = $_SESSION['csrf_token'] ?? null;
if (!is_string($session_csrf_token) || !is_string($client_csrf_token) || !hash_equals($session_csrf_token, $client_csrf_token)) {
    set_primary_media_response(['success' => false, 'message' => 'CSRF validation failed. Unauthorized request.'], 403);
    exit;
}

$rawData = file_get_contents('php://input');
$decodedData = null;
try {
    $decodedData = json_decode($rawData ?: '', false, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    set_primary_media_response(['success' => false, 'message' => 'Invalid JSON request.'], 400);
    exit;
}

if (!is_object($decodedData)) {
    set_primary_media_response(['success' => false, 'message' => 'A JSON object is required.'], 400);
    exit;
}

$data = get_object_vars($decodedData);
if (!array_key_exists('id', $data) || !array_key_exists('slot_assignment', $data)) {
    set_primary_media_response(['success' => false, 'message' => 'Missing data.'], 422);
    exit;
}

if (!is_int($data['id']) || $data['id'] < 1) {
    set_primary_media_response(['success' => false, 'message' => 'A valid media ID is required.'], 422);
    exit;
}

$media_id = $data['id'];
$slot = is_string($data['slot_assignment']) ? trim($data['slot_assignment']) : '';
if ($slot === '' || preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', $slot) !== 1) {
    set_primary_media_response(['success' => false, 'message' => 'A valid media slot is required.'], 422);
    exit;
}

$transaction_started = false;
$conn = null;
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once __DIR__ . '/../../config/db_connect.php';
    if (!($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection unavailable.');
    }
    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Could not start transaction.');
    }
    $transaction_started = true;

    // Lock and verify the exact row before resetting any primary in the slot.
    $stmt_target = $conn->prepare('SELECT id FROM media_cms WHERE id = ? AND slot_assignment = ? LIMIT 1 FOR UPDATE');
    if (!$stmt_target || !$stmt_target->bind_param('is', $media_id, $slot) || !$stmt_target->execute()) {
        throw new RuntimeException('Could not verify media.');
    }
    $target_result = $stmt_target->get_result();
    if (!$target_result || $target_result->num_rows !== 1) {
        throw new SetPrimaryMediaException('Media was not found in the selected slot.', 404);
    }

    // Remove the primary status only from photos in this exact slot.
    $stmt_reset = $conn->prepare("UPDATE media_cms SET is_primary = 0 WHERE slot_assignment = ?");
    if (!$stmt_reset || !$stmt_reset->bind_param('s', $slot) || !$stmt_reset->execute()) {
        throw new RuntimeException('Could not reset media primary state.');
    }

    // Set the verified photo as primary, retaining the slot predicate.
    $stmt_set = $conn->prepare('UPDATE media_cms SET is_primary = 1 WHERE id = ? AND slot_assignment = ?');
    if (!$stmt_set || !$stmt_set->bind_param('is', $media_id, $slot) || !$stmt_set->execute()) {
        throw new RuntimeException('Could not set media primary state.');
    }

    $stmt_verify = $conn->prepare('SELECT is_primary FROM media_cms WHERE id = ? AND slot_assignment = ? LIMIT 1');
    if (!$stmt_verify || !$stmt_verify->bind_param('is', $media_id, $slot) || !$stmt_verify->execute()) {
        throw new RuntimeException('Could not verify media primary state.');
    }
    $verify_result = $stmt_verify->get_result();
    $verified_row = $verify_result ? $verify_result->fetch_assoc() : null;
    if (!$verified_row || (int)$verified_row['is_primary'] !== 1) {
        throw new RuntimeException('Media primary state could not be verified.');
    }

    $stmt_count = $conn->prepare('SELECT COUNT(*) AS primary_count FROM media_cms WHERE slot_assignment = ? AND is_primary = 1');
    if (!$stmt_count || !$stmt_count->bind_param('s', $slot) || !$stmt_count->execute()) {
        throw new RuntimeException('Could not verify slot primary state.');
    }
    $count_result = $stmt_count->get_result();
    $count_row = $count_result ? $count_result->fetch_assoc() : null;
    if (!$count_row || (int)$count_row['primary_count'] !== 1) {
        throw new RuntimeException('Slot primary state could not be verified.');
    }

    if (!$conn->commit()) {
        throw new RuntimeException('Could not commit media primary state.');
    }
    $transaction_started = false;
    set_primary_media_response(['success' => true, 'message' => 'Primary image updated successfully!', 'primary_id' => $media_id]);
} catch (Throwable $e) {
    if ($transaction_started && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $rollback_error) {
            // Preserve the generic response if rollback itself fails.
        }
    }
    error_log('Primary media update failed: ' . get_class($e));
    $status_code = $e instanceof SetPrimaryMediaException ? $e->statusCode() : 500;
    $message = $e instanceof SetPrimaryMediaException ? $e->getMessage() : 'Primary image could not be updated.';
    set_primary_media_response(['success' => false, 'message' => $message], $status_code);
}
?>
