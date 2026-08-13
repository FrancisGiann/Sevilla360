<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../../config/db_connect.php';

$user_id = $_SESSION['user_id'];
$notif_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($notif_id > 0) {
    $stmt = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND id = ? AND is_read = 0");
    $stmt->bind_param("ii", $user_id, $notif_id);
} else {
    $stmt = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
}
if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
$stmt->close();
$conn->close();
?>
