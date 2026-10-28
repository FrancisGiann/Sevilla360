<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json; charset=utf-8');
$csrf = (string)($_SESSION['csrf_token'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SESSION['role'] ?? '') !== 'customer'
    || $csrf === '' || !hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    exit;
}
$bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$token = is_string($_POST['hold_token'] ?? null) ? $_POST['hold_token'] : '';
if (!$bookingId || !preg_match('/\A[a-f0-9]{32}\z/D', $token)) {
    http_response_code(422);
    echo json_encode(['success' => false]);
    exit;
}
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/manual_payment.php';
require_once __DIR__ . '/../../includes/session_account_guard.php';
if (!session_account_require_active_json($conn, ['customer'], ['success' => false], ['success' => false])) exit;
try {
    if (!$conn->begin_transaction()) throw new RuntimeException('Unable to start release.');
    $stmt = $conn->prepare('SELECT b.id, b.venue_id, b.start_date, b.end_date, b.payment_step_token, b.booking_status, b.proof_first_submitted_at FROM bookings b JOIN customers c ON c.id = b.customer_id WHERE b.id = ? AND c.user_id = ? FOR UPDATE');
    $userId = (int)$_SESSION['user_id'];
    $stmt->bind_param('ii', $bookingId, $userId);
    if (!$stmt->execute()) throw new RuntimeException('Unable to inspect payment hold.');
    $booking = $stmt->get_result()->fetch_assoc();
    // A delayed exit from an old tab cannot release a new token or a proof claim.
    if ($booking) manual_payment_exit_step_locked($conn, $booking, $token);
    if (!$conn->commit()) throw new RuntimeException('Unable to release payment hold.');
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Payment hold could not be released. It will expire automatically.']);
}
