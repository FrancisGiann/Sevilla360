<?php
// actions/user/pay_existing.php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['booking_id'])) {
    echo json_encode(['success' => false, 'message' => 'Booking ID is required']);
    exit;
}

$booking_id = intval($data['booking_id']);

try {
    // 1. Get Booking and Customer Details
    $stmt = $conn->prepare("
        SELECT b.reference_no, b.total_amount, b.amount_paid, b.booking_status, b.payment_status, b.payment_scheme,
               c.first_name, c.last_name, c.email, c.phone, v.name as venue_name
        FROM bookings b
        JOIN customers c ON b.customer_id = c.id
        JOIN venues v ON b.venue_id = v.id
        WHERE b.id = ? AND c.user_id = ?
    ");
    $stmt->bind_param("ii", $booking_id, $_SESSION['user_id']);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
        throw new Exception("Booking not found or unauthorized.");
    }

    $booking = $res->fetch_assoc();

    // 2. Calculate remaining balance
    $balance_due = floatval($booking['total_amount']) - floatval($booking['amount_paid']);

    if ($balance_due <= 0 || $booking['payment_status'] === 'Paid' || $booking['booking_status'] === 'Cancelled') {
        throw new Exception("This booking is already fully paid or cancelled.");
    }

    // 3. Setup PayMongo Payload
    $paymongo_sk = 'sk_test_xcJMk42B2XeNY1TzgksSXgPh'; // Your Test Key
    
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $domain = $_SERVER['HTTP_HOST'];
    $success_url = $protocol . "://" . $domain . "/Sevilla360/user_dashboard.php?payment=success";
    $cancel_url = $protocol . "://" . $domain . "/Sevilla360/user_dashboard.php?payment=failed";

    $centavos = (int)round($balance_due * 100);
    $customer_name = $booking['first_name'] . ' ' . $booking['last_name'];
    $payment_description = ($booking['amount_paid'] > 0) ? "Balance Payment" : "Initial Payment";
    
    // Clean string to prevent JSON breaking
    $safe_venue_name = preg_replace('/[^a-zA-Z0-9\s]/', '', $booking['venue_name']);

    // CRITICAL FIX 1: Make Reference Number unique by appending "_BAL" and a timestamp!
    // Example: SV-12345_BAL169000
    $unique_paymongo_ref = $booking['reference_no'] . '_BAL' . time();

    // CRITICAL FIX 2: Ensure phone is strictly a string, not empty
    $safe_phone = !empty($booking['phone']) ? $booking['phone'] : '09000000000';

    $payload = [
        'data' => [
            'attributes' => [
                'billing' => [
                    'name' => $customer_name,
                    'email' => $booking['email'],
                    'phone' => $safe_phone
                ],
                'send_email_receipt' => true,
                'show_description' => true,
                'show_line_items' => true,
                'description' => "Sevilla360: " . $booking['reference_no'],
                'line_items' => [
                    [
                        'currency' => 'PHP',
                        'amount' => $centavos,
                        'name' => "$payment_description",
                        'description' => $safe_venue_name,
                        'quantity' => 1
                    ]
                ],
                'payment_method_types' => ['card', 'gcash', 'paymaya'],
                'reference_number' => $unique_paymongo_ref, // Use the new UNIQUE reference!
                'success_url' => $success_url,
                'cancel_url' => $cancel_url
            ]
        ]
    ];

    // 4. Send cURL Request to PayMongo
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.paymongo.com/v1/checkout_sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    
    // Timeouts and local SSL bypass
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Keep this for XAMPP/Localhost testing
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'accept: application/json',
        'Authorization: Basic ' . base64_encode($paymongo_sk . ':')
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) throw new Exception("cURL Error: " . $err);

    $res_data = json_decode($response, true);

    if (isset($res_data['errors'])) {
        throw new Exception("PayMongo Error: " . $res_data['errors'][0]['detail']);
    }

    if (isset($res_data['data']['attributes']['checkout_url'])) {
        echo json_encode(['success' => true, 'checkout_url' => $res_data['data']['attributes']['checkout_url']]);
    } else {
        throw new Exception("Failed to generate PayMongo link.");
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>