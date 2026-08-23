<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/env.php'; // Load environment variables
require_once '../../config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

// ==========================================
// CSRF PROTECTION GUARD (JSON)
// ==========================================
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
    // 1. ADDED b.payment_scheme TO THE QUERY
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

    // Respect chosen payment scheme on first payment
    $amount_to_pay = $balance_due; // Default to the full remaining balance
    $payment_label = 'Balance Payment';

    // If they haven't paid anything yet, respect their chosen payment scheme!
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
    // =========================================================================

    // BULLETPROOF UNIQUE ID: Example "BAL_169999_SV-123"
    $unique_ref = "BAL_" . time() . "_" . $booking['reference_no'];

    $paymongo_sk = trim((string)($_ENV['PAYMONGO_SECRET_KEY'] ?? ''));
    if ($paymongo_sk === '') throw new Exception('Online payment is temporarily unavailable. Please contact support.');
    $domain = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://";
    $domain .= $_SERVER['HTTP_HOST'];

    $payload = [
        'data' => [
            'attributes' => [
                'billing' => [
                    'name' => trim($booking['first_name'] . ' ' . $booking['last_name']),
                    'email' => $booking['email'],
                    'phone' => '09171234567' // Forced to bypass strict validation
                ],
                'send_email_receipt' => false,
                'show_description' => false,
                'show_line_items' => true,
                'line_items' => [[
                    'currency' => 'PHP',
                    'amount' => (int) round($amount_to_pay * 100), // USE THE CALCULATED AMOUNT!
                    'name' => $payment_label,
                    'quantity' => 1
                ]],
                'payment_method_types' => ['card', 'gcash', 'paymaya'],
                'reference_number' => $unique_ref,
                'success_url' => $domain . "/Sevilla360/user_dashboard.php?payment=success",
                'cancel_url' => $domain . "/Sevilla360/user_dashboard.php?payment=failed"
            ]
        ]
    ];

    $ch = curl_init();
    if ($ch === false) throw new Exception('Online payment is temporarily unavailable. Please try again later.');
    curl_setopt($ch, CURLOPT_URL, 'https://api.paymongo.com/v1/checkout_sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($paymongo_sk . ':')
    ]);

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $curl_error !== '') {
        throw new Exception('Unable to reach the payment provider. Please try again.');
    }
    if ($http_status < 200 || $http_status >= 300) {
        throw new Exception('Payment provider rejected the checkout request. Please try again.');
    }
    $res_data = json_decode($response, true);
    if (!is_array($res_data)) throw new Exception('Payment provider returned an invalid response.');

    if (isset($res_data['errors'])) {
        $detail = $res_data['errors'][0]['detail'] ?? 'Checkout could not be created.';
        throw new Exception("PayMongo: " . $detail);
    }
    if (isset($res_data['data']['attributes']['checkout_url'])) {
        echo json_encode(['success' => true, 'checkout_url' => $res_data['data']['attributes']['checkout_url']]);
    } else {
        throw new Exception('Payment provider returned no checkout URL.');
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
