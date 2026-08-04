<?php
// actions/bookings/paymongo_webhook.php
require '../../config/db_connect.php';

// Create a log file path in the same directory
$log_file = __DIR__ . '/webhook_debug.log';

// 1. Receive the JSON payload from PayMongo
$payload = file_get_contents('php://input');
$data = json_decode($payload, true);

// Log the exact payload we received
file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] RECEIVED PAYLOAD:\n" . print_r($data, true) . "\n\n", FILE_APPEND);

if (!$data || !isset($data['data']['attributes']['type'])) {
    file_put_contents($log_file, "ERROR: Invalid Payload Structure.\n", FILE_APPEND);
    http_response_code(400);
    exit();
}

$event_type = $data['data']['attributes']['type'];

if ($event_type === 'checkout_session.payment.paid') {
    try {
        $checkout_data = $data['data']['attributes']['data']['attributes'];
        $reference_no = $checkout_data['reference_number'] ?? null;
        
        file_put_contents($log_file, "Attempting to process Ref No: " . $reference_no . "\n", FILE_APPEND);

        // Safely extract payment details
        $payments = $checkout_data['payments'] ?? [];
        if (empty($payments)) {
            throw new Exception("PayMongo didn't send the 'payments' array.");
        }

        $amount_paid = floatval($payments[0]['attributes']['amount']) / 100;
        $transaction_id = $payments[0]['id'];
        
        $raw_method = $payments[0]['attributes']['source']['type'] ?? '';
        $payment_method = 'Card';
        if (strpos($raw_method, 'gcash') !== false) $payment_method = 'GCash';
        if (strpos($raw_method, 'paymaya') !== false) $payment_method = 'Maya';
        if (strpos($raw_method, 'dob') !== false) $payment_method = 'Bank Transfer';

        $conn->begin_transaction();

        // Find the booking
        $stmt_find = $conn->prepare("SELECT id, total_amount, amount_paid FROM bookings WHERE reference_no = ?");
        $stmt_find->bind_param("s", $reference_no);
        $stmt_find->execute();
        $res = $stmt_find->get_result();

        if ($res->num_rows === 0) {
            throw new Exception("Booking Ref '$reference_no' NOT FOUND in database.");
        }

        $booking = $res->fetch_assoc();
        $booking_id = $booking['id'];

        // Math
        $new_amount_paid = floatval($booking['amount_paid']) + $amount_paid;
        $payment_status = ($new_amount_paid >= floatval($booking['total_amount'])) ? 'Paid' : 'Partial';

        // Insert Receipt
        $stmt_pay = $conn->prepare("INSERT INTO payments (booking_id, transaction_id, payment_method, amount, status) VALUES (?, ?, ?, ?, 'Success')");
        $stmt_pay->bind_param("issd", $booking_id, $transaction_id, $payment_method, $amount_paid);
        if (!$stmt_pay->execute()) throw new Exception("Failed to insert into payments table.");

        // Update Booking
        $stmt_update = $conn->prepare("UPDATE bookings SET booking_status = 'Confirmed', payment_status = ?, amount_paid = ? WHERE id = ?");
        $stmt_update->bind_param("sdi", $payment_status, $new_amount_paid, $booking_id);
        if (!$stmt_update->execute()) throw new Exception("Failed to update bookings table.");

        // Save to Audit Log
        $log_action = "Automated Webhook: Received ₱" . number_format($amount_paid, 2) . " via $payment_method for Booking $reference_no";
        $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (NULL, 'PayMongo Webhook', ?, 'PayMongo Server')");
        $audit->bind_param("s", $log_action);
        if (!$audit->execute()) throw new Exception("Failed to insert audit log.");

        $conn->commit();
        file_put_contents($log_file, "SUCCESS! Database updated to Confirmed/Paid.\n\n", FILE_APPEND);

    } catch (Exception $e) {
        $conn->rollback();
        file_put_contents($log_file, "SQL/LOGIC ERROR: " . $e->getMessage() . "\n\n", FILE_APPEND);
    }
} else {
    file_put_contents($log_file, "SKIPPED: Event was not checkout_session.payment.paid.\n\n", FILE_APPEND);
}

http_response_code(200);
echo "Webhook received.";
?>