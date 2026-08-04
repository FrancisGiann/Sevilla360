<?php
// Force PHP to report ALL errors to the screen and log
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../../config/db_connect.php';

// Put the log file in the same folder as this script
$log_file = dirname(__FILE__) . '/webhook_debug.log';

// 1. Receive the JSON payload from PayMongo
$payload = file_get_contents('php://input');

if (empty($payload)) {
    http_response_code(400);
    exit();
}

$data = json_decode($payload, true);
file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] RECEIVED PAYLOAD:\n" . print_r($data, true) . "\n\n", FILE_APPEND);

if (!$data || !isset($data['data']['attributes']['type'])) {
    http_response_code(400);
    exit();
}

$event_type = $data['data']['attributes']['type'];

if ($event_type === 'checkout_session.payment.paid') {
    try {
        $checkout_data = $data['data']['attributes']['data']['attributes'];
        $raw_reference = $checkout_data['reference_number'] ?? null;
        
        // CRITICAL FIX: Split the reference number at the underscore
        // If PayMongo sends "SV-12345_BAL169000", this chops off the extra junk and gives us "SV-12345"
        $ref_parts = explode('_', $raw_reference);
        $reference_no = $ref_parts[0]; 
        
        // $log_action output for debugging in Ngrok
        $msg = "Processing Ref No: " . $reference_no . " (Original from PayMongo: $raw_reference)\n";
        echo $msg;


        // BULLETPROOF FIX: Check multiple places for the amount and payment ID
        // If the 'payments' array is missing (Auto-Accept Card Bug), fallback to the session totals!
        $payments = $checkout_data['payments'] ?? [];
        
        if (!empty($payments)) {
            $amount_paid = floatval($payments[0]['attributes']['amount']) / 100;
            $transaction_id = $payments[0]['id'];
            $raw_method = $checkout_data['payment_method_used'] ?? $payments[0]['attributes']['source']['type'] ?? 'card';
        } else {
            // Fallback for instant auto-accept cards where 'payments' array is delayed
            $amount_paid = floatval($checkout_data['line_items'][0]['amount']) / 100;
            $transaction_id = $checkout_data['payment_intent']['id'] ?? 'PAYMONGO_' . time();
            $raw_method = $checkout_data['payment_method_used'] ?? 'card';
        }
        
        // Map the method string to our Database ENUM
        $payment_method = 'Card'; // Default to Card for testing
        if (strpos(strtolower($raw_method), 'gcash') !== false) $payment_method = 'GCash';
        elseif (strpos(strtolower($raw_method), 'paymaya') !== false || strpos(strtolower($raw_method), 'maya') !== false) $payment_method = 'Maya';
        elseif (strpos(strtolower($raw_method), 'dob') !== false || strpos(strtolower($raw_method), 'bank') !== false) $payment_method = 'Bank Transfer';

        $conn->begin_transaction();

        // Find the booking
        $stmt_find = $conn->prepare("SELECT id, total_amount, amount_paid, payment_status FROM bookings WHERE reference_no = ?");
        $stmt_find->bind_param("s", $reference_no);
        $stmt_find->execute();
        $res = $stmt_find->get_result();

        if ($res->num_rows === 0) {
            throw new Exception("Booking Ref '$reference_no' NOT FOUND in database.");
        }

        $booking = $res->fetch_assoc();
        $booking_id = $booking['id'];

        // Guard against duplicate webhooks (PayMongo sometimes sends the webhook twice!)
        if ($booking['payment_status'] === 'Paid' || $booking['payment_status'] === 'Partial') {
            throw new Exception("Booking already marked as paid. Ignoring duplicate webhook.");
        }

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
        file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] SUCCESS! Database updated.\n\n", FILE_APPEND);

    } catch (Exception $e) {
        $conn->rollback();
        file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n\n", FILE_APPEND);
    }
}

http_response_code(200);
echo "Webhook received.";
?>