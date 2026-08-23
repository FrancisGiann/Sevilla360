<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/paymongo.php';
require_once __DIR__ . '/../../includes/payment_service.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'staff'], true)) {
    http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
}
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403); echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']); exit;
}
$data = json_decode(file_get_contents('php://input'), true);
$booking_id = filter_var($data['booking_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$booking_id) { http_response_code(422); echo json_encode(['success' => false, 'message' => 'A valid booking is required.']); exit; }

try {
    $stmt = $conn->prepare("SELECT b.id FROM bookings b WHERE b.id = ? LIMIT 1");
    if (!$stmt) throw new RuntimeException('Unable to load checkout session.');
    $stmt->bind_param('i', $booking_id); if (!$stmt->execute()) throw new RuntimeException('Unable to load checkout session.');
    if ($stmt->get_result()->num_rows !== 1) throw new RuntimeException('Booking not found.');
    $result = reconcile_payment_for_booking($conn, (int)$booking_id);
    echo json_encode(['success' => true, 'duplicate' => $result['duplicate'], 'message' => $result['duplicate'] ? 'Payment was already credited.' : 'Verified payment credited.', 'payment_status' => $result['status'], 'amount_paid' => $result['amount_paid']]);
} catch (Throwable $e) {
    http_response_code(422); echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
