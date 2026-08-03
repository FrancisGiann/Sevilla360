<?php
// actions/admin/get_customer_history.php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$customer_id = intval($_GET['id'] ?? 0);

try {
    $stmt = $conn->prepare("
        SELECT b.start_date, v.name as venue_name, b.total_amount 
        FROM bookings b JOIN venues v ON b.venue_id = v.id 
        WHERE b.customer_id = ? 
        ORDER BY b.start_date DESC
    ");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['success' => true, 'data' => $history]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>