<?php
function create_user_notification($conn, $user_id, $title, $message) {
    if (!$user_id) return false;
    $stmt = $conn->prepare("INSERT INTO user_notifications (user_id, title, message) VALUES (?, ?, ?)");
    if (!$stmt) return false;
    $stmt->bind_param("iss", $user_id, $title, $message);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}
?>
