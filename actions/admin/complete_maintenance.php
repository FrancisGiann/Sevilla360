<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$maint_id = intval($data['id']);

try {
    $conn->begin_transaction();

    // 1. Get maintenance details
    $stmt_get = $conn->prepare("SELECT venue_id, start_date, is_blocking FROM maintenance WHERE id = ?");
    $stmt_get->bind_param("i", $maint_id);
    $stmt_get->execute();
    $maint = $stmt_get->get_result()->fetch_assoc();

    // 2. Change End Date to Yesterday (frees up the calendar for today onwards)
    $stmt_maint = $conn->prepare("UPDATE maintenance SET end_date = (CURDATE() - INTERVAL 1 DAY) WHERE id = ?");
    $stmt_maint->bind_param("i", $maint_id);
    $stmt_maint->execute();

    // 3. Update the dummy booking lock
    if ($maint['is_blocking']) {
        $stmt_book = $conn->prepare("UPDATE bookings SET end_date = (CURDATE() - INTERVAL 1 DAY) WHERE source = 'Maintenance' AND venue_id = ? AND start_date = ?");
        $stmt_book->bind_param("is", $maint['venue_id'], $maint['start_date']);
        $stmt_book->execute();
    }

    $conn->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>