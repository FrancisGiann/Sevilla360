<?php
session_start();
require '../../config/db_connect.php';

// ==========================================
// CSRF PROTECTION GUARD (TEXT)
// ==========================================
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo "Error|CSRF validation failed. Unauthorized request.";
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    try {
        $conn->begin_transaction();

        $ref_no = "SV-" . mt_rand(10000, 99999);
        $sDate = $_POST['start_date'];
        $eDate = $_POST['end_date'];
        $scheme = $_POST['payment_scheme'];
        $room_type = $_POST['room_type'];
        $room_name = $_POST['room_name'];
        $guests = (int)$_POST['guests'];

        // =========================================================================
        // SECURITY FIX: BACKEND PRICE VERIFICATION
        // Ensures the Walk-in price is 100% accurate based on the Database
        // =========================================================================
        // =========================================================================
        // SECURITY FIX: BACKEND PRICE VERIFICATION
        // Ensures the Walk-in price is 100% accurate based on the Database
        // =========================================================================
        if (!empty($_POST['venue_id'])) {
            $venue_id = (int)$_POST['venue_id'];
            $stmt_room = $conn->prepare("
                SELECT id, category FROM venues 
                WHERE id = ? AND status = 'Available'
                AND id NOT IN (SELECT venue_id FROM bookings WHERE booking_status IN ('Pending', 'Confirmed') AND (start_date < ? AND end_date > ?))
                LIMIT 1
            ");
            $stmt_room->bind_param("iss", $venue_id, $eDate, $sDate);
        } else {
            if ($room_type === 'Event Hall' || $room_type === 'Resort Villa') {
                $stmt_room = $conn->prepare("
                    SELECT id, category FROM venues 
                    WHERE category = ? AND name = ? AND status = 'Available'
                    AND id NOT IN (SELECT venue_id FROM bookings WHERE booking_status IN ('Pending', 'Confirmed') AND (start_date < ? AND end_date > ?))
                    LIMIT 1
                ");
            } else {
                $stmt_room = $conn->prepare("
                    SELECT v.id, v.category FROM venues v JOIN hotel_rooms h ON v.id = h.venue_id 
                    WHERE h.room_type = ? AND v.name = ? AND v.status = 'Available'
                    AND v.id NOT IN (SELECT venue_id FROM bookings WHERE booking_status IN ('Pending', 'Confirmed') AND (start_date < ? AND end_date > ?))
                    LIMIT 1
                ");
            }
            $stmt_room->bind_param("ssss", $room_type, $room_name, $eDate, $sDate);
        }
        $stmt_room->execute();
        $room_result = $stmt_room->get_result();
        
        if ($room_result->num_rows === 0) {
            throw new Exception("All units for this specific room were just booked by someone online! Please select different dates.");
        }
        $v_row = $room_result->fetch_assoc();
        $venue_id = $v_row['id'];
        $venue_category = $v_row['category'];

        // =========================================================================
        // SHARED PRICING LOGIC
        // =========================================================================
        require_once '../../includes/pricing.php';
        $stay_type = $_POST['stay_type'] ?? 'Day Time Stay';

        $pricing = calculate_booking_price($conn, $venue_id, $venue_category, $sDate, $eDate, $guests, $stay_type);
        $base_amount = $pricing['base_amount'];
        $true_total = $pricing['true_total'];

        $total_addons_cost = 0;
        $line_items_data = [];
        if (isset($_POST['custom_line_items'])) {
            $line_items_data = json_decode($_POST['custom_line_items'], true);
            if (is_array($line_items_data)) {
                foreach ($line_items_data as $item) {
                    $amt = floatval($item['amount']);
                    if ($amt > 0) {
                        $total_addons_cost += $amt;
                        $true_total += $amt;
                    }
                }
            }
        }
        // =========================================================================

        // Calculate Downpayment using True Total
        $amount_paid = 0;
        if ($scheme === '100% Full') $amount_paid = $true_total;
        elseif (strpos($scheme, '50%') !== false) $amount_paid = $true_total * 0.5;
        elseif (strpos($scheme, '20%') !== false) $amount_paid = $true_total * 0.2;

        $payment_status = ($amount_paid >= $true_total && $true_total > 0) ? 'Paid' : (($amount_paid > 0) ? 'Partial' : 'Unpaid');
        // =========================================================================

        $raw_method = strtolower($_POST['payment_method']);
        $pay_method = 'Cash';
        if ($raw_method === 'gcash') $pay_method = 'GCash';
        if ($raw_method === 'maya') $pay_method = 'Maya';
        if ($raw_method === 'bank') $pay_method = 'Bank Transfer';

        // Find or create customer
        $guest_email = trim($_POST['guest_email']);
        $guest_phone = trim($_POST['guest_phone']);
        $guest_name = trim($_POST['guest_name']);
        
        $name_parts = explode(' ', $guest_name, 2);
        $first_name = $name_parts[0];
        $last_name = $name_parts[1] ?? 'Walk-in';

        $stmt_check_cust = $conn->prepare("SELECT id FROM customers WHERE email = ?");
        $stmt_check_cust->bind_param("s", $guest_email);
        $stmt_check_cust->execute();
        $res_cust = $stmt_check_cust->get_result();

        if ($res_cust->num_rows > 0) {
            $customer_id = $res_cust->fetch_assoc()['id'];
            $stmt_update_cust = $conn->prepare("UPDATE customers SET phone = ? WHERE id = ?");
            $stmt_update_cust->bind_param("si", $guest_phone, $customer_id);
            $stmt_update_cust->execute();
        } else {
            $stmt_cust = $conn->prepare("INSERT INTO customers (first_name, last_name, email, phone) VALUES (?, ?, ?, ?)");
            $stmt_cust->bind_param("ssss", $first_name, $last_name, $guest_email, $guest_phone);
            $stmt_cust->execute();
            $customer_id = $conn->insert_id;
        }

        // Check active locks before finalizing
        $stmt_check_lock = $conn->prepare("SELECT id FROM booking_locks WHERE venue_id = ? AND session_id != ? AND expires_at > NOW() AND (start_date <= ? AND end_date >= ?)");
        $sid = session_id();
        $stmt_check_lock->bind_param("isss", $venue_id, $sid, $eDate, $sDate);
        $stmt_check_lock->execute();
        if ($stmt_check_lock->get_result()->num_rows > 0) {
            throw new Exception("Another admin is currently booking this room for these dates. Please try again.");
        }

        $stmt_book = $conn->prepare("
            INSERT INTO bookings (reference_no, customer_id, venue_id, start_date, end_date, guests_count, base_amount, addons_amount, total_amount, amount_paid, payment_scheme, booking_status, payment_status, source) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Confirmed', ?, 'Walk-in')
        ");
        $stmt_book->bind_param("siissiddddss", 
            $ref_no, $customer_id, $venue_id, $sDate, $eDate, 
            $guests, $base_amount, $total_addons_cost, $true_total, $amount_paid, $scheme, $payment_status
        );
        $stmt_book->execute();
        $booking_id = $conn->insert_id;

        // INSERT LINE ITEMS
        if (is_array($line_items_data) && count($line_items_data) > 0) {
            $stmt_li = $conn->prepare("INSERT INTO booking_line_items (booking_id, item_name, amount) VALUES (?, ?, ?)");
            foreach ($line_items_data as $item) {
                $name = trim($item['name']);
                $amt = floatval($item['amount']);
                if ($amt > 0) {
                    $stmt_li->bind_param("isd", $booking_id, $name, $amt);
                    $stmt_li->execute();
                }
            }
        }

        // =========================================================================
        // REAL HOTEL ROOM ALLOCATION LOGIC
        // =========================================================================
        if (isset($_POST['room_groups']) && $venue_category === 'Event Hall') {
            $room_groups = json_decode($_POST['room_groups'], true);
            if (is_array($room_groups) && count($room_groups) > 0) {
                
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
                            AND (start_date < ? AND end_date > ?)
                      )
                      AND v.id NOT IN (
                          SELECT br.venue_id FROM booking_rooms br
                          JOIN bookings b2 ON br.booking_id = b2.id
                          WHERE b2.booking_status IN ('Pending', 'Confirmed', 'Completed')
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
                        $stmt_alloc->bind_param('sssssssssi', $building, $type, $eDate, $sDate, $eDate, $sDate, $sid, $eDate, $sDate, $qty);
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

        // SAVE EVENT DETAILS AND NOTES
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

        $transaction_id = !empty($_POST['transaction_id']) ? $_POST['transaction_id'] : null;
        $stmt_pay = $conn->prepare("INSERT INTO payments (booking_id, transaction_id, payment_method, amount, status) VALUES (?, ?, ?, ?, 'Success')");
        $stmt_pay->bind_param("issd", $booking_id, $transaction_id, $pay_method, $amount_paid);
        $stmt_pay->execute();

        // Release any locks held by this session
        $unlock = $conn->prepare("DELETE FROM booking_locks WHERE session_id = ?");
        $unlock->bind_param("s", $sid);
        $unlock->execute();
        unset($_SESSION['locked_venue_id']);

        // Log walk-in creation using POST guest name
        if (isset($_SESSION['user_id'])) {
            $log_user = $_SESSION['user_id'];
            $log_module = 'Walk-in Bookings';
            $log_action = "Created walk-in booking $ref_no for " . $_POST['guest_name'];
            $log_ip = $_SERVER['REMOTE_ADDR'];

            $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, ?, ?, ?)");
            $audit_stmt->bind_param("isss", $log_user, $log_module, $log_action, $log_ip);
            $audit_stmt->execute();
        }

        // Commit database before sending receipt email
        $conn->commit();

        // EMAIL MAILER
        try {
            require_once '../../includes/mailer.php';
            send_booking_receipt($_POST['guest_email'], $_POST['guest_name'], $ref_no, $_POST['room_name'], $amount_paid, 'Confirmed (Walk-in)');
        } catch (Exception $mail_e) {
            // Log silently, don't crash
            file_put_contents(__DIR__ . '/email_error.log', "[" . date('Y-m-d H:i:s') . "] " . $mail_e->getMessage() . "\n", FILE_APPEND);
        }

        $venue_display_name = $room_name; // from POST
        $dates_str = ($sDate === $eDate) ? $sDate : "$sDate to $eDate";

        // Success|REF_NO|guest_name|venue_name|dates|payment_status
        echo "Success|$ref_no|" . $_POST['guest_name'] . "|$venue_display_name|$dates_str|$payment_status";

    } catch (Exception $e) {
        $conn->rollback();
        echo "Error|" . $e->getMessage();
    }
}
?>