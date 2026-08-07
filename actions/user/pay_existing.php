<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$booking_id = intval($data['booking_id']);

try {
    $stmt = $conn->prepare("SELECT b.reference_no, b.total_amount, b.amount_paid, b.booking_status, b.payment_status, c.first_name, c.last_name, c.email FROM bookings b JOIN customers c ON b.customer_id = c.id WHERE b.id = ? AND c.user_id = ?");
    $stmt->bind_param("ii", $booking_id, $_SESSION['user_id']);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) throw new Exception("Booking not found.");
    $booking = $res->fetch_assoc();

    $balance_due = floatval($booking['total_amount']) - floatval($booking['amount_paid']);
    if ($balance_due <= 0) throw new Exception("Already fully paid.");

    // BULLETPROOF UNIQUE ID: Example "BAL_169999_SV-123"
    $unique_ref = "BAL_" . time() . "_" . $booking['reference_no'];

    $paymongo_sk = $_ENV['PAYMONGO_SECRET_KEY']; 
    $domain = isset($_SERVER['HTTPS']) ? "https://" : "http://";
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
                    'amount' => (int) round($balance_due * 100),
                    'name' => 'Balance Payment',
                    'quantity' => 1
                ]],
                'payment_method_types' => ['card', 'gcash', 'paymaya'],
                'reference_number' => $unique_ref,
                'success_url' => $domain . "/Sevilla360/user_dashboard.php",
                'cancel_url' => $domain . "/Sevilla360/user_dashboard.php"
            ]
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.paymongo.com/v1/checkout_sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($paymongo_sk . ':')
    ]);

    $response = curl_exec($ch);
    curl_close($ch);
    $res_data = json_decode($response, true);

    if (isset($res_data['errors'])) throw new Exception("PayMongo: " . $res_data['errors'][0]['detail']);
    if (isset($res_data['data']['attributes']['checkout_url'])) {
        echo json_encode(['success' => true, 'checkout_url' => $res_data['data']['attributes']['checkout_url']]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>