<?php
require_once __DIR__ . '/../../includes/session_init.php';
require '../../config/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['customer', 'staff', 'admin'], true)) {
    http_response_code(401);
    echo "Error|Your session has expired. Please sign in again.";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Error|POST is required.";
    exit;
}

// ==========================================
// CSRF PROTECTION GUARD (TEXT)
// ==========================================
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo "Error|CSRF validation failed. Unauthorized request.";
    exit;
}

// Release every temporary hold for this session, including tracked add-on
// room locks. Customer sessions use the same endpoint, so scope by session ID.
$session_id = session_id();
$stmt = $conn->prepare("DELETE FROM booking_locks WHERE session_id = ?");
$stmt->bind_param("s", $session_id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo "Error|Temporary holds could not be released.";
    $stmt->close();
    $conn->close();
    exit;
}
$stmt->close();

unset($_SESSION['locked_venue_id'], $_SESSION['walkin_addon_lock_ids']);
echo "Success|Unlocked";
$conn->close();
?>
