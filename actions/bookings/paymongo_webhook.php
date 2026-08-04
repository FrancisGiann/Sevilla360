<?php
require '../../config/db_connect.php';

$payload = file_get_contents('php://input');
if (empty($payload)) { http_response_code(400); echo "Empty"; exit(); }

$data = json_decode($payload, true);
if (!$data || !isset($data['data']['attributes']['type'])) { http_response_code(400); echo "Invalid"; exit(); }

if ($data['data']['attributes']['type'] === 'checkout_session.payment.paid') {
    try {
        $checkout = $data['data']['attributes']['data']['attributes'];
        $raw_ref = $checkout['reference_number'] ?? '';
        
        // 1. EXTRACT REAL BOOKING REF (Chop off "BAL_123456_")
        $ref_array = explode('_', $raw_ref);
        $reference_no = end($ref_array); // Always grabs the last part (e.g., SV-123)

        // 2. GET AMOUNT AND ID
        $payments = $checkout['payments'] ?? [];
        if (!empty($payments)) {
            $amount_paid = floatval($payments[0]['attributes']['amount']) / 100;
            $transaction_id = $payments[0]['id'];
            $method_str = $checkout['payment_method_used'] ?? 'card';
        } else {
            // Fallback for auto-accept cards
            $amount_paid = floatval($checkout['line_items'][0]['amount']) / 100;
            $transaction_id = $checkout['payment_intent']['id'] ?? 'PM_' . time();
            $method_str = 'card';
        }

        $payment_method = 'Card';
        if (strpos($method_str, 'gcash') !== false) $payment_method = 'GCash';
        if (strpos($method_str, 'paymaya') !== false) $payment_method = 'Maya';

        // 3. DATABASE UPDATE
        $conn->begin_transaction();
        
        $stmt_find = $conn->prepare("SELECT id, total_amount, amount_paid FROM bookings WHERE reference_no = ?");
        $stmt_find->bind_param("s", $reference_no);
        $stmt_find->execute();
        $res = $stmt_find->get_result();
        
        if ($res->num_rows === 0) {
            echo "FAILED: Ref $reference_no not found in DB."; 
            http_response_code(400); 
            exit(); 
        }
        $booking = $res->fetch_assoc();

        $new_amount = floatval($booking['amount_paid']) + $amount_paid;
        $status = ($new_amount >= floatval($booking['total_amount'])) ? 'Paid' : 'Partial';

        $stmt_pay = $conn->prepare("INSERT INTO payments (booking_id, transaction_id, payment_method, amount, status) VALUES (?, ?, ?, ?, 'Success')");
        $stmt_pay->bind_param("issd", $booking['id'], $transaction_id, $payment_method, $amount_paid);
        $stmt_pay->execute();

        $stmt_up = $conn->prepare("UPDATE bookings SET booking_status = 'Confirmed', payment_status = ?, amount_paid = ? WHERE id = ?");
        $stmt_up->bind_param("sdi", $status, $new_amount, $booking['id']);
        $stmt_up->execute();

        $conn->commit();
        echo "SUCCESS: Updated Booking $reference_no to $status.";

    } catch (Exception $e) {
        $conn->rollback();
        echo "ERROR: " . $e->getMessage();
        http_response_code(400);
    }
} else {
    echo "IGNORED: Not a paid event.";
}
?>