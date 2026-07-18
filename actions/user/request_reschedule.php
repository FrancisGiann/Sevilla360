<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

if (!isset($data['booking_id']) || !isset($data['new_start_date']) || !isset($data['reason'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required data.']);
    exit;
}

$booking_id = intval($data['booking_id']);
$new_start = $data['new_start_date'];
$new_end = isset($data['new_end_date']) ? $data['new_end_date'] : $new_start;
$reason = trim($data['reason']);

// Security: Verify this booking belongs to the logged-in user
$stmt_check = $conn->prepare("
    SELECT b.id FROM bookings b 
    JOIN customers c ON b.customer_id = c.id 
    WHERE b.id = ? AND c.user_id = ?
");
$stmt_check->bind_param("ii", $booking_id, $_SESSION['user_id']);
$stmt_check->execute();
if ($stmt_check->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Booking not found or access denied.']);
    exit;
}

try {
    // Check if a request is already pending
    $chk_pending = $conn->prepare("SELECT id FROM reschedule_requests WHERE booking_id = ? AND status = 'Pending'");
    $chk_pending->bind_param("i", $booking_id);
    $chk_pending->execute();
    if ($chk_pending->get_result()->num_rows > 0) {
        throw new Exception("You already have a pending reschedule request for this booking.");
    }

    $stmt_insert = $conn->prepare("INSERT INTO reschedule_requests (booking_id, new_start_date, new_end_date, reason) VALUES (?, ?, ?, ?)");
    $stmt_insert->bind_param("isss", $booking_id, $new_start, $new_end, $reason);
    $stmt_insert->execute();

    echo json_encode(['success' => true, 'message' => 'Reschedule request submitted successfully! Staff will review it shortly.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>