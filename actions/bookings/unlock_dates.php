<?php
session_start();
require '../../config/db_connect.php';

// ==========================================
// CSRF PROTECTION GUARD (TEXT)
// ==========================================
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo "Error|CSRF validation failed. Unauthorized request.";
    exit;
}

// If the user has a locked room in their session, delete it from the database!
if (isset($_SESSION['locked_venue_id'])) {
    $venue_id = $_SESSION['locked_venue_id'];
    $session_id = session_id();

    $stmt = $conn->prepare("DELETE FROM booking_locks WHERE venue_id = ? AND session_id = ?");
    $stmt->bind_param("is", $venue_id, $session_id);
    $stmt->execute();
    $stmt->close();

    unset($_SESSION['locked_venue_id']);
    echo "Success|Unlocked";
} else {
    echo "Success|Nothing to unlock";
}
$conn->close();
?>