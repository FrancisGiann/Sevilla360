<?php
session_start();
require '../../config/db_connect.php';
require_once '../../includes/booking_reference.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    http_response_code(401);
    echo "Error|You must be logged in to make a booking.";
    exit();
}

// ==========================================
// CSRF PROTECTION GUARD (TEXT)
// ==========================================
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo "Error: CSRF validation failed. Unauthorized request.";
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    try {
        $conn->begin_transaction();

        $ref_no = generate_booking_reference($conn);
        $sDate = trim($_POST['start_date'] ?? '');
        $eDate = trim($_POST['end_date'] ?? '');
        $scheme = $_POST['payment_scheme'] ?? '';
        $guests = (int)$_POST['guests'];

        $start_dt = DateTime::createFromFormat('!Y-m-d', $sDate);
        $end_dt = DateTime::createFromFormat('!Y-m-d', $eDate);
        $today = new DateTime('today');
        if (!$start_dt || !$end_dt || $start_dt->format('Y-m-d') !== $sDate || $end_dt->format('Y-m-d') !== $eDate || $end_dt < $start_dt || $start_dt < $today) {
            throw new Exception("Invalid booking date range.");
        }
        if ($guests < 1) {
            throw new Exception("Guest count must be at least one.");
        }

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

        // The final request must match an active lock created by this session.
        $sid = session_id();
        $stmt_own_lock = $conn->prepare("SELECT id FROM booking_locks WHERE venue_id = ? AND session_id = ? AND start_date = ? AND end_date = ? AND expires_at > NOW()");
        $stmt_own_lock->bind_param("isss", $venue_id, $sid, $sDate, $eDate);
        $stmt_own_lock->execute();
        if ($stmt_own_lock->get_result()->num_rows === 0) {
            throw new Exception("Your date reservation has expired or changed. Please select the dates again.");
        }

        // RE-VALIDATE DATE AVAILABILITY
        $stmt_overlap = $conn->prepare("
            SELECT id FROM bookings 
            WHERE venue_id = ? 
            AND booking_status IN ('Pending', 'Confirmed', 'Completed')
            AND source <> 'Maintenance'
            AND (start_date < ? AND end_date > ?)
        ");
        $stmt_overlap->bind_param("iss", $venue_id, $eDate, $sDate);
        $stmt_overlap->execute();
        if ($stmt_overlap->get_result()->num_rows > 0) {
            throw new Exception("Sorry, these dates were just booked by another guest. Please choose different dates.");
        }

        // Check maintenance blocks
        $stmt_maint = $conn->prepare("
            SELECT id FROM maintenance 
            WHERE venue_id = ? AND is_blocking = 1 AND status = 'Scheduled'
            AND (start_date <= ? AND end_date >= ?)
        ");
        $stmt_maint->bind_param("iss", $venue_id, $eDate, $sDate);
        $stmt_maint->execute();
        if ($stmt_maint->get_result()->num_rows > 0) {
            throw new Exception("These dates are currently under maintenance. Please choose different dates.");
        }

        // Find the true category
        $stmt_cat = $conn->prepare("SELECT category FROM venues WHERE id = ?");
        $stmt_cat->bind_param("i", $venue_id);
        $stmt_cat->execute();
        $venue = $stmt_cat->get_result()->fetch_assoc();
        if (!$venue) {
            throw new Exception("Selected venue no longer exists.");
        }
        $venue_category = $venue['category'];
        if ($venue_category !== 'Event Hall' && $end_dt <= $start_dt) {
            throw new Exception("Hotel and villa bookings must end after their start date.");
        }

        // =========================================================================
        // SHARED PRICING LOGIC
        // =========================================================================
        require_once '../../includes/pricing.php';
        $stay_type = $_POST['stay_type'] ?? 'Day Time Stay';

        $pricing = calculate_booking_price($conn, $venue_id, $venue_category, $sDate, $eDate, $guests, $stay_type);
        if ($pricing['max_capacity'] > 0 && $guests > $pricing['max_capacity']) {
            throw new Exception("Guest count exceeds this venue's maximum capacity.");
        }
        $base_amount = $pricing['base_amount'];
        $true_total = $pricing['true_total'];

        // Online Event Halls strictly act as inquiries ($0 upfront)
        if ($venue_category === 'Event Hall') {
            $true_total = 0; 
            $scheme = 'To Be Arranged'; 
        }
        // =========================================================================


        // 3. Save Booking
        // Check active locks before finalizing
        $lock_overlap = ($venue_category === 'Hotel Room')
            ? '(start_date < ? AND end_date > ?)'
            : '(start_date <= ? AND end_date >= ?)';
        $stmt_check_lock = $conn->prepare("SELECT id FROM booking_locks WHERE venue_id = ? AND session_id != ? AND expires_at > NOW() AND $lock_overlap");
        $sid = session_id();
        $stmt_check_lock->bind_param("isss", $venue_id, $sid, $eDate, $sDate);
        $stmt_check_lock->execute();
        if ($stmt_check_lock->get_result()->num_rows > 0) {
            throw new Exception("Another user is currently booking this room for these dates. Please try again.");
        }

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

        // Save custom line items to database
        // Catering and A/V are Event Hall-only line items. Ignore any stale
        // hidden controls posted after a tab switch.
        if ($venue_category === 'Event Hall' && isset($_POST['custom_line_items'])) {
            $line_items = json_decode($_POST['custom_line_items'], true);
            $total_addons_cost = 0;

            if (is_array($line_items) && count($line_items) > 0) {
                $stmt_li = $conn->prepare("INSERT INTO booking_line_items (booking_id, item_name, amount) VALUES (?, ?, ?)");
                
                foreach ($line_items as $item) {
                    $name = trim($item['name']);
                    $amt = floatval($item['amount']);
                    
                    if ($amt > 0) {
                        $total_addons_cost += $amt;
                        $stmt_li->bind_param("isd", $booking_id, $name, $amt);
                        $stmt_li->execute();
                    }
                }

                // Event Hall submissions are inquiries. Their quote is set only when staff finalizes it.
                if ($venue_category !== 'Event Hall' && $total_addons_cost > 0) {
                    $true_total += $total_addons_cost;
                    $stmt_upd = $conn->prepare("UPDATE bookings SET addons_amount = ?, total_amount = ? WHERE id = ?");
                    $stmt_upd->bind_param("ddi", $total_addons_cost, $true_total, $booking_id);
                    $stmt_upd->execute();
                }
            }
        }

        // =========================================================================
        // REAL HOTEL ROOM ALLOCATION LOGIC
        // =========================================================================
        if (isset($_POST['room_groups']) && $venue_category === 'Event Hall') {
            $room_groups = json_decode($_POST['room_groups'], true);
            if (is_array($room_groups) && count($room_groups) > 0) {
                
                // Determine nights
                $start_dt = new DateTime($sDate);
                $end_dt = new DateTime($eDate);
                $nights = $start_dt->diff($end_dt)->days;
                if ($nights === 0) $nights = 1;

                $stmt_alloc = $conn->prepare("
                    SELECT v.id, h.nightly_rate
                    FROM venues v
                    JOIN hotel_rooms h ON v.id = h.venue_id
                    WHERE v.name = ? 
                      AND h.room_type = ? 
                      AND v.status = 'Available'
                      AND v.id NOT IN (
                          SELECT venue_id FROM bookings
                          WHERE booking_status IN ('Pending', 'Confirmed', 'Completed')
                            AND source <> 'Maintenance'
                            AND (start_date < ? AND end_date > ?)
                      )
                      AND v.id NOT IN (
                          SELECT venue_id FROM maintenance
                          WHERE is_blocking = 1 AND status = 'Scheduled'
                            AND (start_date <= ? AND end_date >= ?)
                      )
                      AND v.id NOT IN (
                          SELECT br.venue_id FROM booking_rooms br
                          JOIN bookings b2 ON br.booking_id = b2.id
                          WHERE b2.booking_status IN ('Pending', 'Confirmed', 'Completed')
                            AND b2.source <> 'Maintenance'
                            AND (br.start_date < ? AND br.end_date > ?)
                      )
                      AND v.id NOT IN (
                          SELECT venue_id FROM booking_locks
                          WHERE session_id != ? AND expires_at > NOW()
                            AND (start_date < ? AND end_date > ?)
                      )
                    LIMIT ?
                ");
                
                $stmt_insert = $conn->prepare("INSERT INTO booking_rooms (booking_id, venue_id, nightly_rate, start_date, end_date, nights, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)");

                foreach ($room_groups as $group) {
                    $building = trim($group['building_name']);
                    $type = trim($group['room_type']);
                    $qty = (int)$group['quantity'];
                    
                    if ($qty > 0) {
                        $stmt_alloc->bind_param('sssssssssssi', $building, $type, $eDate, $sDate, $eDate, $sDate, $eDate, $sDate, $sid, $eDate, $sDate, $qty);
                        $stmt_alloc->execute();
                        $alloc_res = $stmt_alloc->get_result();
                        
                        if ($alloc_res->num_rows < $qty) {
                            throw new Exception("Not enough inventory available for $building - $type. Please try again.");
                        }

                        while ($room = $alloc_res->fetch_assoc()) {
                            $r_venue_id = $room['id'];
                            $r_rate = floatval($room['nightly_rate']);
                            $r_line_total = $r_rate * $nights;
                            
                            $stmt_insert->bind_param("iidssid", $booking_id, $r_venue_id, $r_rate, $sDate, $eDate, $nights, $r_line_total);
                            $stmt_insert->execute();

                            // Ensure calculated room add-on totals are saved in booking_line_items
                            $li_name = "Room Add-on: $building - $type";
                            $stmt_li_add = $conn->prepare("INSERT INTO booking_line_items (booking_id, item_name, amount) VALUES (?, ?, ?)");
                            $stmt_li_add->bind_param("isd", $booking_id, $li_name, $r_line_total);
                            $stmt_li_add->execute();
                        }
                    }
                }
            }
        }
        // =========================================================================

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
        
        // Only calculate amount due if venue is not an Event Hall
        if ($venue_category !== 'Event Hall') {
            if ($scheme === '100% Full') $amount_due = $true_total;
            elseif (strpos($scheme, '50%') !== false) $amount_due = $true_total * 0.5;
            elseif (strpos($scheme, '20%') !== false) $amount_due = $true_total * 0.2;
        }

        // STRICT GUARD: Prevent bypassing PayMongo if it's a Hotel or Villa
        if ($venue_category !== 'Event Hall' && $amount_due <= 0) {
            throw new Exception("Error calculating price. Amount due cannot be zero for Hotels/Villas.");
        }

        // TRIGGER PAYMONGO IF AMOUNT IS GREATER THAN ZERO
        if ($amount_due > 0) {
            // NOTE: Make sure you changed this to $_ENV['PAYMONGO_SECRET_KEY'] as discussed earlier!
            $paymongo_sk = $_ENV['PAYMONGO_SECRET_KEY'];
            
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $domain = $_SERVER['HTTP_HOST'];
            $success_url = $protocol . "://" . $domain . "/Sevilla360/user_dashboard.php?payment=success";
            $cancel_url = $protocol . "://" . $domain . "/Sevilla360/user_dashboard.php?payment=failed";

            $centavos = (int)round($amount_due * 100);
            $safe_room_name = preg_replace('/[^a-zA-Z0-9\s]/', '', $_POST['room_type']); 
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
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
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
        
        $conn->commit();

        // IF AMOUNT IS 0 (EVENT INQUIRY), SEND EMAIL AND SUCCESS
        try {
            require_once '../../includes/mailer.php';
            require_once '../../includes/notifications.php';
            send_booking_receipt($customer_email, $customer_name, $ref_no, $_POST['room_name'], 0, 'Inquiry Sent (Pending)');
            create_user_notification($conn, $_SESSION['user_id'], "Booking Submitted", "Your inquiry for " . $_POST['room_name'] . " has been sent successfully. An admin will review it shortly.");
        } catch (Exception $mail_e) {
            // Log silently
        }
        
        echo "Success|" . $ref_no;

    } catch (Exception $e) {
        $conn->rollback();
        echo "Error|" . $e->getMessage();
    }
}
?>
