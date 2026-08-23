<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/rate_limit.php';
require_once __DIR__ . '/../../includes/paymongo.php';
require_once __DIR__ . '/../../includes/payment_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SESSION['role'] ?? '') !== 'customer') {
    http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit;
}
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
    http_response_code(403); echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']); exit;
}
if (!check_rate_limit($conn, 'sync_payment', 5, 15)) {
    http_response_code(429); echo json_encode(['success' => false, 'message' => 'Too many payment refresh attempts. Please wait a moment.']); exit;
}
$data = json_decode(file_get_contents('php://input'), true);
$booking_id = filter_var(is_array($data) ? ($data['booking_id'] ?? null) : null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$booking_id) { http_response_code(422); echo json_encode(['success' => false, 'message' => 'A valid booking is required.']); exit; }
try {
    $owner = $conn->prepare('SELECT b.id FROM bookings b JOIN customers c ON c.id = b.customer_id WHERE b.id = ? AND c.user_id = ? LIMIT 1');
    $owner->bind_param('ii', $booking_id, $_SESSION['user_id']); $owner->execute();
    if ($owner->get_result()->num_rows !== 1) throw new RuntimeException('Booking not found.');
    $result = reconcile_payment_for_booking($conn, (int)$booking_id);
    echo json_encode(['success' => true, 'duplicate' => (bool)$result['duplicate'], 'message' => $result['duplicate'] ? 'Payment was already credited.' : 'Payment status refreshed.', 'payment_status' => $result['status'], 'amount_paid' => $result['amount_paid']]);
} catch (Throwable $e) {
    error_log('Customer payment sync failed: class=' . get_class($e) . ' booking_id=' . (int)$booking_id);
    $providerMessage = strtolower((string)$e->getMessage());
    $safeMessage = 'Payment status could not be refreshed. Please try again shortly.';
    if (str_contains($providerMessage, 'not returned a paid') || str_contains($providerMessage, 'not yet paid')) {
        $safeMessage = 'Payment is not yet confirmed. Please try again shortly.';
    } elseif (str_contains($providerMessage, 'checkout session') || str_contains($providerMessage, 'checkout')) {
        $safeMessage = 'No valid payment session was found for this booking.';
    } elseif (str_contains($providerMessage, 'amount') || str_contains($providerMessage, 'currency')) {
        $safeMessage = 'The payment details could not be verified. Please contact support.';
    } elseif (str_contains($providerMessage, 'booking not found')) {
        $safeMessage = 'Booking not found or no longer available.';
    }
    http_response_code(422); echo json_encode(['success' => false, 'message' => $safeMessage]);
}
?>
