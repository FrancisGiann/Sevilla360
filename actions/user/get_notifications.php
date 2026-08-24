<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';

if (($_SESSION['role'] ?? '') !== 'customer' || empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$stmt = $conn->prepare('SELECT id, title, message, is_read, created_at FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$count_stmt = $conn->prepare('SELECT COUNT(*) AS unread_count FROM user_notifications WHERE user_id = ? AND is_read = 0');
$count_stmt->bind_param('i', $_SESSION['user_id']);
$count_stmt->execute();
$unread = (int)($count_stmt->get_result()->fetch_assoc()['unread_count'] ?? 0);
$count_stmt->close();
echo json_encode(['success' => true, 'notifications' => $rows, 'unread_count' => $unread]);
