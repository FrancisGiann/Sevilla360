<?php
session_start();
require '../../config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    echo "Error|You must be logged in to make a booking.";
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    try {
        $conn->begin_transaction();

        $ref_no = "SV-" . mt_rand(10000, 99999);
        $sDate = $_POST['start_date'];
        $eDate = $_POST['end_date'];
        $scheme = $_POST['payment_scheme'];
        $guests = (int)$_POST['guests'];

        // 1. Get Customer ID & Email
        $stmt_cust = $conn->prepare("SELECT id, first_name, last_name, email FROM customers WHERE user_id = ?");
        $stmt_cust->bind_param("i", $_SESSION['user_id']);
        $stmt_cust->execute();
        $cust_result = $stmt_cust->get_result();
        if ($cust_result->num_rows === 0) throw new Exception("Customer profile not found.");
        $customer = $cust_result->fetch_assoc();
        $customer_id = $customer['id'];
        $customer_name = $customer['first_name'] . ' ' . $customer['last_name'];
        $customer_email = $customer['email'];

        // 2. Update Phone Number
        $contact_phone = isset($_POST['contact_phone']) ? trim($_POST['contact_phone']) : null;
        if (!empty($contact_phone)) {
            $stmt_phone = $conn->prepare("UPDATE customers SET phone = ? WHERE id = ?");
            $stmt_phone->bind_param("si", $contact_phone, $customer_id);
            $stmt_phone->execute();
        }

        if (!isset($_SESSION['locked_venue_id'])) {
            throw new Exception("Session expired or dates were not locked properly.");
        }
        $venue_id = $_SESSION['locked_venue_id'];

        // =========================================================================
        // SECURITY FIX: BACKEND PRICE VERIFICATION
        // =========================================================================
        
        // Find the true category of this venue directly from the database
        $stmt_cat = $conn->prepare("SELECT category FROM venues WHERE id = ?");
        $stmt_cat->bind_param("i", $venue_id);
        $stmt_cat->execute();
        $venue_category = $stmt_cat->get_result()->fetch_assoc()['category'];

        // Calculate Nights/Days
        $start_dt = new DateTime($sDate);
        $end_dt = new DateTime($eDate);
        $nights = $start_dt->diff($end_dt)->days;
        if ($nights === 0) $nights = 1; 

        $true_total = 0;
        $base_amount = 0;

        if ($venue_category === 'Hotel Room') {
            $stmt = $conn->prepare("SELECT nightly_rate, base_capacity, extra_pax_rate FROM hotel_rooms WHERE venue_id = ?");
            $stmt->bind_param("i", $venue_id);
            $stmt->execute();
            $room = $stmt->get_result()->fetch_assoc();

            $base_amount = floatval($room['nightly_rate']);
            $true_total = $base_amount * $nights;
            
            if ($guests > $room['base_capacity']) {
                $extra_pax = $guests - $room['base_capacity'];
                $true_total += ($extra_pax * floatval($room['extra_pax_rate']) * $nights);
            }
        } 
        elseif ($venue_category === 'Resort Villa') {
            $stmt = $conn->prepare("SELECT day_rate, base_capacity, extra_pax_rate FROM villas WHERE venue_id = ?");
            $stmt->bind_param("i", $venue_id);
            $stmt->execute();
            $villa = $stmt->get_result()->fetch_assoc();

            $base_amount = floatval($villa['day_rate']);
            $stay_type = $_POST['stay_type'] ?? 'Day Time Stay';
            
            $stay_upgrade = ($stay_type === 'Overnight') ? (3000 * $nights) : 0;
            $true_total = ($base_amount * $nights) + $stay_upgrade;

            if ($guests > $villa['base_capacity']) {
                $extra_pax = $guests - $villa['base_capacity'];
                $true_total += ($extra_pax * floatval($villa['extra_pax_rate']) * $nights);
            }
        } 
        elseif ($venue_category === 'Event Hall') {
            $stmt = $conn->prepare("SELECT base_rate FROM event_halls WHERE venue_id = ?");
            $stmt->bind_param("i", $venue_id);
            $stmt->execute();
            $hall = $stmt->get_result()->fetch_assoc();

            $base_amount = floatval($hall['base_rate']);
            $true_total = $base_amount * $nights; 
        }
        // =========================================================================

        // 3. Save Booking (Using the secure $base_amount and $true_total)
        $stmt_book = $conn->prepare("
            INSERT INTO bookings (reference_no, customer_id, venue_id, start_date, end_date, guests_count, base_amount, total_amount, payment_scheme, booking_status, payment_status, source) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'Unpaid', 'Online')
        ");
        $stmt_book->bind_param("siissidds", 
            $ref_no, $customer_id, $venue_id, $sDate, $eDate, 
            $guests, $base_amount, $true_total, $scheme
        );
        $stmt_book->execute();
        $booking_id = $conn->insert_id;

        // 4. Save Event/Villa Details
        $custom_notes = isset($_POST['custom_notes']) ? trim($_POST['custom_notes']) : null;
        $event_type = isset($_POST['event_type']) ? trim($_POST['event_type']) : null;
        $event_style = isset($_POST['event_style']) ? trim($_POST['event_style']) : null;

        if (!empty($custom_notes) || !empty($event_type) || !empty($event_style)) {
            $stmt_notes = $conn->prepare("INSERT INTO booking_event_details (booking_id, event_style, event_type, custom_notes) VALUES (?, ?, ?, ?)");
            $stmt_notes->bind_param("isss", $booking_id, $event_style, $event_type, $custom_notes);
            $stmt_notes->execute();
        }

        if ($venue_category === 'Resort Villa' && isset($_POST['stay_type'])) {
            $stay_type = $_POST['stay_type'];
            $stmt_villa = $conn->prepare("INSERT INTO booking_villa_details (booking_id, stay_type) VALUES (?, ?)");
            $stmt_villa->bind_param("is", $booking_id, $stay_type);
            $stmt_villa->execute();
        }

        // 5. Unlock Dates
        $session_id = session_id();
        $stmt_unlock = $conn->prepare("DELETE FROM booking_locks WHERE venue_id = ? AND session_id = ?");
        $stmt_unlock->bind_param("is", $venue_id, $session_id);
        $stmt_unlock->execute();
        unset($_SESSION['locked_venue_id']);

        // =========================================================================
        // PAYMONGO CHECKOUT INTEGRATION 
        // =========================================================================
        
        $amount_due = 0;
        if ($scheme === '100% Full') $amount_due = $true_total;
        elseif (strpos($scheme, '50%') !== false) $amount_due = $true_total * 0.5;
        elseif (strpos($scheme, '20%') !== false) $amount_due = $true_total * 0.2;

        // STRICT GUARD: Prevent bypassing PayMongo if it's a Hotel or Villa
        if ($venue_category !== 'Event Hall' && $amount_due <= 0) {
            throw new Exception("Error calculating price. Amount due cannot be zero for Hotels/Villas.");
        }

        // TRIGGER PAYMONGO IF AMOUNT IS GREATER THAN ZERO
        if ($amount_due > 0) {
            $paymongo_sk = 'sk_test_xcJMk42B2XeNY1TzgksSXgPh'; 
            
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $domain = $_SERVER['HTTP_HOST'];
            $success_url = $protocol . "://" . $domain . "/Sevilla360/user_dashboard.php?payment=success";
            $cancel_url = $protocol . "://" . $domain . "/Sevilla360/user_dashboard.php?payment=failed";

            $centavos = (int)round($amount_due * 100);
            $safe_room_name = preg_replace('/[^a-zA-Z0-9\s]/', '', $_POST['room_type']); // Just for display
            $safe_phone = !empty($contact_phone) ? $contact_phone : '09171234567';

            $payload = [
                'data' => [
                    'attributes' => [
                        'billing' => [
                            'name' => $customer_name,
                            'email' => $customer_email,
                            'phone' => $safe_phone
                        ],
                        'send_email_receipt' => false,
                        'show_description' => false,
                        'show_line_items' => true,
                        'description' => "Sevilla360 Booking: $ref_no",
                        'line_items' => [
                            [
                                'currency' => 'PHP',
                                'amount' => $centavos,
                                'name' => "Booking Deposit ($scheme)",
                                'description' => $safe_room_name,
                                'quantity' => 1
                            ]
                        ],
                        'payment_method_types' => ['card', 'gcash', 'paymaya'],
                        'reference_number' => $ref_no,
                        'success_url' => $success_url,
                        'cancel_url' => $cancel_url
                    ]
                ]
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.paymongo.com/v1/checkout_sessions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); 
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
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

            if (isset($res_data['errors'])) throw new Exception("PayMongo API Error: " . $res_data['errors'][0]['detail']);

            if (isset($res_data['data']['attributes']['checkout_url'])) {
                $conn->commit();
                echo "CheckoutUrl|" . $res_data['data']['attributes']['checkout_url'];
                exit();
            } else {
                throw new Exception("Failed to generate PayMongo link. Unknown API response.");
            }
        }
        
        // IF AMOUNT IS 0 (EVENT INQUIRY), BYPASS PAYMONGO
        // Send Inquiry Confirmation Email!
        try {
            require_once '../../includes/mailer.php';
            send_booking_receipt($customer_email, $customer_name, $ref_no, $_POST['room_name'], 0, 'Inquiry Sent (Pending)');
        } catch (Exception $mail_e) {
            // Log silently
        }
        $conn->commit();
        echo "Success|" . $ref_no;

    } catch (Exception $e) {
        $conn->rollback();
        echo "Error|" . $e->getMessage();
    }
}
?>