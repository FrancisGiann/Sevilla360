<?php
// actions/admin/get_customer_history.php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/session_account_guard.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
if (!session_account_require_active_json($conn, ['admin'], ['success' => false, 'message' => 'Unauthorized'], ['success' => false, 'message' => 'Account status could not be verified.'])) exit;

$customer_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($customer_id === false) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid customer ID']);
    exit;
}

try {
    require_once __DIR__ . '/../../includes/booking_lifecycle.php';
    $completionSql = booking_completion_sql('b');
    $stmt = $conn->prepare("
        SELECT b.id, b.reference_no, b.start_date, b.end_date, v.name AS venue_name, v.category AS venue_category,
               b.total_amount, b.amount_paid,
               b.booking_status, CASE WHEN {$completionSql} THEN 'Completed' ELSE b.booking_status END AS display_booking_status,
               b.payment_status
        FROM bookings b
        JOIN venues v ON b.venue_id = v.id
        WHERE b.customer_id = ? 
        ORDER BY b.start_date DESC, b.end_date DESC, b.id DESC
    ");
    if (!$stmt) throw new RuntimeException('Unable to prepare booking history.');
    $stmt->bind_param("i", $customer_id);
    if (!$stmt->execute()) throw new RuntimeException('Unable to load booking history.');
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['success' => true, 'data' => $history], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('Customer booking history failed: ' . get_class($e));
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
