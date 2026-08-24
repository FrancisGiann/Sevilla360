<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once '../../config/env.php';
require_once '../../config/db_connect.php';
require_once '../../includes/paymongo.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed. Unauthorized request.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data) || !isset($data['booking_id'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid payment request.']);
    exit;
}
$booking_id = filter_var($data['booking_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$booking_id) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid booking.']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT b.reference_no, b.total_amount, b.amount_paid, b.payment_scheme, b.booking_status, b.payment_status, v.category,
               c.first_name, c.last_name, c.email
        FROM bookings b
        JOIN customers c ON b.customer_id = c.id
        JOIN venues v ON b.venue_id = v.id
        WHERE b.id = ? AND c.user_id = ?
    ");
    $stmt->bind_param("ii", $booking_id, $_SESSION['user_id']);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) throw new Exception("Booking not found.");
    $booking = $res->fetch_assoc();

    if (!in_array($booking['booking_status'], ['Pending', 'Confirmed'], true) || $booking['payment_status'] === 'Refunded') {
        throw new Exception("This booking is no longer eligible for payment.");
    }
    if ($booking['category'] === 'Event Hall' && $booking['booking_status'] !== 'Confirmed') {
        throw new Exception("Your event quotation must be finalized before payment.");
    }

    $total_amount = floatval($booking['total_amount']);
    $amount_paid = floatval($booking['amount_paid']);
    $balance_due = $total_amount - $amount_paid;
    if ($balance_due <= 0) throw new Exception("This booking is already fully paid.");

    $amount_to_pay = $balance_due;
    $payment_label = 'Balance Payment';
    if ($amount_paid == 0) {
        $scheme = $booking['payment_scheme'];
        if (strpos($scheme, '50%') !== false) {
            $amount_to_pay = $total_amount * 0.50;
            $payment_label = '50% Downpayment';
        } elseif (strpos($scheme, '20%') !== false) {
            $amount_to_pay = $total_amount * 0.20;
            $payment_label = '20% Reservation Fee';
        } else {
            $payment_label = 'Full Payment';
        }
    }
    $amount_to_pay = min($amount_to_pay, $balance_due);
    if ($amount_to_pay <= 0) throw new Exception('No payable balance remains for this booking.');

    $unique_ref = "BAL_" . $booking_id . "_" . $booking['reference_no'];
    $domain = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://";
    $domain .= $_SERVER['HTTP_HOST'];
    $payload = [
        'data' => ['attributes' => [
            'billing' => [
                'name' => trim($booking['first_name'] . ' ' . $booking['last_name']),
                'email' => $booking['email'],
                'phone' => '09171234567'
            ],
            'send_email_receipt' => false,
            'show_description' => false,
            'show_line_items' => true,
            'line_items' => [[
                'currency' => 'PHP',
                'amount' => (int) round($amount_to_pay * 100),
                'name' => $payment_label,
                'quantity' => 1
            ]],
            'payment_method_types' => ['card', 'gcash', 'paymaya'],
            'reference_number' => $unique_ref,
            'success_url' => $domain . "/Sevilla360/user_dashboard.php?payment=success",
            'cancel_url' => $domain . "/Sevilla360/user_dashboard.php?payment=failed"
        ]]
    ];

    $checkout = paymongo_create_or_reuse_checkout($conn, $booking_id, $amount_to_pay, $amount_paid, $payload);
    echo json_encode(['success' => true, 'checkout_url' => $checkout['checkout_url'], 'reused' => $checkout['reused']]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
