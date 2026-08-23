<?php
require '../../config/db_connect.php';
require_once '../../includes/payment_service.php';

$payload = file_get_contents('php://input');
if (empty($payload)) { http_response_code(400); echo "Empty"; exit(); }

// =========================================================================
// SECURITY FIX: VERIFY PAYMONGO WEBHOOK SIGNATURE
// =========================================================================
$signature_header = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

if (empty($signature_header)) { 
    http_response_code(400); 
    echo "Missing Paymongo-Signature header"; 
    exit(); 
}

$webhook_secret = $_ENV['PAYMONGO_WEBHOOK_SECRET'] ?? '';
if (empty($webhook_secret)) {
    http_response_code(500); 
    echo "Server Misconfiguration: Webhook secret not set."; 
    exit();
}

// Parse the signature header (Format: t=timestamp,te=test_signature,li=live_signature)
$signature_parts = explode(',', $signature_header);
$timestamp = '';
$test_signature = '';
$live_signature = '';

foreach ($signature_parts as $part) {
    $pair = explode('=', trim($part), 2);
    if (count($pair) === 2) {
        if ($pair[0] === 't') $timestamp = $pair[1];
        if ($pair[0] === 'te') $test_signature = $pair[1];
        if ($pair[0] === 'li') $live_signature = $pair[1];
    }
}

// PayMongo may retry a valid event after several minutes. Require a numeric
// timestamp for HMAC construction, but rely on the signature plus the unique
// transaction ID/database idempotency check below rather than rejecting a
// delayed provider retry solely because it is old.
if (!ctype_digit($timestamp)) {
    http_response_code(400);
    echo "Invalid Webhook Timestamp";
    exit();
}

// Compute the expected HMAC-SHA256 signature
$signature_payload = $timestamp . '.' . $payload;
$computed_signature = hash_hmac('sha256', $signature_payload, $webhook_secret);

// Validate (Secure comparison against both test and live signatures)
if (!hash_equals($computed_signature, $test_signature) && !hash_equals($computed_signature, $live_signature)) {
    http_response_code(400); 
    echo "Invalid Webhook Signature"; 
    exit();
}
// =========================================================================

$data = json_decode($payload, true);
if (!$data || !isset($data['data']['attributes']['type'])) { http_response_code(400); echo "Invalid"; exit(); }

if ($data['data']['attributes']['type'] === 'checkout_session.payment.paid') {
    try {
        $checkout = $data['data']['attributes']['data']['attributes'];
        $raw_ref = $checkout['reference_number'] ?? '';
        
        // 1. EXTRACT REAL BOOKING REF (Chop off "BAL_123456_")
        $ref_array = explode('_', $raw_ref);
        $reference_no = end($ref_array); // Always grabs the last part (e.g., SV-123)
        if ($raw_ref === '' || !preg_match('/^[A-Za-z0-9-]{3,80}$/', $reference_no)) {
            throw new Exception('Webhook reference number is invalid.');
        }

        // 2. GET AMOUNT AND ID
        $payments = $checkout['payments'] ?? [];
        if (!empty($payments) && isset($payments[0]['attributes']['amount'], $payments[0]['id'])) {
            $amount_paid = (int)$payments[0]['attributes']['amount'] / 100;
            $transaction_id = (string)$payments[0]['id'];
            $method_str = $checkout['payment_method_used'] ?? 'card';
            $currency = strtoupper((string)($payments[0]['attributes']['currency'] ?? ($checkout['line_items'][0]['currency'] ?? 'PHP')));
        } else {
            $amount_paid = isset($checkout['line_items'][0]['amount']) ? (int)$checkout['line_items'][0]['amount'] / 100 : 0;
            $transaction_id = (string)($checkout['payment_intent']['id'] ?? '');
            $method_str = 'card';
            $currency = strtoupper((string)($checkout['line_items'][0]['currency'] ?? 'PHP'));
        }

        if ($amount_paid <= 0 || $transaction_id === '') {
            throw new Exception('Webhook payment amount or transaction ID is invalid.');
        }
        if (isset($checkout['line_items'][0]['amount']) && $payments) {
            $expected_checkout_amount = (int)$checkout['line_items'][0]['amount'] / 100;
            if (abs($amount_paid - $expected_checkout_amount) > 0.01) {
                throw new Exception('Webhook amount does not match the checkout amount.');
            }
        }

        $payment_method = 'PayMongo'; 
        if (strpos($method_str, 'gcash') !== false) $payment_method = 'GCash';
        if (strpos($method_str, 'paymaya') !== false) $payment_method = 'Maya';

        // The webhook and admin reconciliation share one locked/idempotent
        // crediting path. Provider session ID is retained for reconciliation.
        $provider_session_id = (string)($data['data']['attributes']['data']['id'] ?? '');
        $credited = credit_verified_payment($conn, $reference_no, $amount_paid, $transaction_id, $payment_method, $provider_session_id !== '' ? $provider_session_id : null, $currency);
        $status = $credited['status'];
        $new_amount = (float)$credited['amount_paid'];
        if ($credited['duplicate']) {
            echo "IGNORED: Duplicate webhook for transaction $transaction_id (already processed).";
            exit();
        }
        
        // 5. SEND AUTOMATED EMAIL RECEIPT (Now it reads the committed data!)
        try {
            require_once '../../includes/mailer.php';
            require_once '../../includes/notifications.php';
            
            // We need to fetch the customer name and email again since we committed
            $stmt_cust = $conn->prepare("SELECT c.email, c.first_name, c.last_name, v.name as venue_name, c.user_id FROM bookings b JOIN customers c ON b.customer_id = c.id JOIN venues v ON b.venue_id = v.id WHERE b.reference_no = ?");
            $stmt_cust->bind_param("s", $reference_no);
            $stmt_cust->execute();
            $c_data = $stmt_cust->get_result()->fetch_assoc();

            $customer_name = $c_data['first_name'] . ' ' . $c_data['last_name'];
            $email_status = ($status === 'Paid') ? 'Fully Paid' : 'Partially Paid (Downpayment)';
            
            send_booking_receipt($c_data['email'], $customer_name, $reference_no, $c_data['venue_name'], $new_amount, $email_status);
            
            if (!empty($c_data['user_id'])) {
                create_user_notification($conn, $c_data['user_id'], "Payment Successful", "Your online payment of ₱" . number_format($amount_paid, 2) . " for " . $c_data['venue_name'] . " has been successfully processed.");
            }
        } catch (Exception $mail_e) {
            file_put_contents(__DIR__ . '/email_error.log', "[" . date('Y-m-d H:i:s') . "] " . $mail_e->getMessage() . "\n", FILE_APPEND);
        }

        echo "SUCCESS: Updated Booking $reference_no to $status.";

    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage();
        http_response_code(400);
    }
} else {
    echo "IGNORED: Not a paid event.";
}
?>
