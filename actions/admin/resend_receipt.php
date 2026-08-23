<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';

// 1. SECURITY: Must be Admin/Staff
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'staff')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 2. CSRF PROTECTION GUARD
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed. Unauthorized request.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['booking_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing booking ID']);
    exit;
}

$booking_id = intval($data['booking_id']);

try {
    // 3. Fetch Booking Data
    $stmt = $conn->prepare("
        SELECT b.reference_no, b.total_amount, b.amount_paid, b.booking_status, b.payment_status,
               c.first_name, c.last_name, c.email, 
               v.name AS venue_name
        FROM bookings b
        JOIN customers c ON b.customer_id = c.id
        JOIN venues v ON b.venue_id = v.id
        WHERE b.id = ?
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) throw new Exception("Booking not found.");
    $booking = $result->fetch_assoc();

    $customer_name = $booking['first_name'] . ' ' . $booking['last_name'];
    $customer_email = $booking['email'];
    $ref_no = $booking['reference_no'];
    $venue_name = $booking['venue_name'];
    $amount_paid = floatval($booking['amount_paid']);
    
    if (in_array($booking['booking_status'], ['Cancelled', 'Pending'])) {
        throw new Exception("Receipt cannot be resent for cancelled or pending bookings.");
    }

    // Determine the email status
    $email_status = 'Confirmed (Unpaid)';
    if ($booking['payment_status'] === 'Paid') $email_status = 'Fully Paid';
    elseif ($booking['payment_status'] === 'Partial') $email_status = 'Partially Paid (Downpayment)';

    // 4. Send Email
    require_once '../../includes/mailer.php';
    send_booking_receipt($customer_email, $customer_name, $ref_no, $venue_name, $amount_paid, $email_status);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
