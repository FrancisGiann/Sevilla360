<?php
require_once __DIR__ . '/../../includes/session_init.php';
require '../../config/db_connect.php';
require_once '../../includes/booking_reference.php';
require_once '../../includes/phone_helper.php';
require_once '../../includes/booking_rules.php';
require_once '../../includes/paymongo.php';
require_once '../../includes/realtime.php';
require_once '../../includes/notifications.php';

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
    $db_committed = false;
    try {
        $conn->begin_transaction();

        if (($_POST['policy_consent'] ?? '') !== '1') {
            throw new Exception('Please accept the booking terms before proceeding.');
        }
        // The accepted legal text is server-owned.  A posted version may be
        // retained by older clients for compatibility, but it can never
        // select which policy is recorded for this booking.
        $policy_version = 'terms-v2-refund-fee';

        $sDate = trim($_POST['start_date'] ?? '');
        $eDate = trim($_POST['end_date'] ?? '');
        $room_type = trim((string)($_POST['room_type'] ?? ''));
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

        // 2. Snapshot the booking contact and update the profile only by request.
        $contact_phone = normalize_contact_phone((string)($_POST['contact_phone'] ?? ''));
        if (($_POST['save_contact_default'] ?? '0') === '1') {
            $stmt_phone = $conn->prepare("UPDATE customers SET phone = ? WHERE id = ?");
            $stmt_phone->bind_param("si", $contact_phone, $customer_id);
            $stmt_phone->execute();
        }

        $posted_venue_id = filter_var($_POST['venue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $is_event_request = (($room_type ?? '') === 'Event Hall');
        if ($is_event_request) {
            if (!$posted_venue_id) throw new Exception('Please select an Event Hall before submitting the inquiry.');
            $venue_id = (int)$posted_venue_id;
            $stmt_event_venue = $conn->prepare("SELECT id FROM venues WHERE id = ? AND category = 'Event Hall' AND status = 'Available' LIMIT 1");
            $stmt_event_venue->bind_param('i', $venue_id);
            $stmt_event_venue->execute();
            if ($stmt_event_venue->get_result()->num_rows !== 1) throw new Exception('The selected Event Hall is no longer available.');
        } elseif (!isset($_SESSION['locked_venue_id'])) {
            throw new Exception("Session expired or dates were not locked properly.");
        } else {
            $venue_id = (int)$_SESSION['locked_venue_id'];
        }

        // Find the true category before applying category-specific overlap rules.
        $stmt_cat = $conn->prepare("SELECT category FROM venues WHERE id = ? FOR UPDATE");
        $stmt_cat->bind_param("i", $venue_id);
        $stmt_cat->execute();
        $venue = $stmt_cat->get_result()->fetch_assoc();
        if (!$venue) {
            throw new Exception("Selected venue no longer exists.");
        }
        $venue_category = $venue['category'];

        // The final request must match an active lock created by this session
        // for overnight inventory. Event Hall inquiries are non-exclusive and
        // intentionally neither require nor honor booking_locks; use the
        // server-derived venue category rather than the posted room_type.
        $sid = session_id();
        if ($venue_category !== 'Event Hall') {
            $stmt_own_lock = $conn->prepare("SELECT id FROM booking_locks WHERE venue_id = ? AND session_id = ? AND start_date = ? AND end_date = ? AND expires_at > NOW()");
            $stmt_own_lock->bind_param("isss", $venue_id, $sid, $sDate, $eDate);
            $stmt_own_lock->execute();
            if ($stmt_own_lock->get_result()->num_rows === 0) {
                throw new Exception("Your date reservation has expired or changed. Please select the dates again.");
            }
        }

        // RE-VALIDATE DATE AVAILABILITY
        $overlap_condition = booking_overlap_sql($venue_category);
        // Event Hall submissions are inquiries until an administrator confirms
        // them, so pending inquiries must not reserve the hall. Overnight
        // venues retain their existing pending/confirmed/completed semantics.
        $booking_status_filter = $venue_category === 'Event Hall'
            ? "IN ('Confirmed', 'Completed')"
            : "IN ('Pending', 'Confirmed', 'Completed')";
        $stmt_overlap = $conn->prepare("
            SELECT id FROM bookings
            WHERE venue_id = ?
            AND booking_status $booking_status_filter
            AND source <> 'Maintenance'
            AND $overlap_condition
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
            AND " . maintenance_overlap_sql() . "
        ");
        $stmt_maint->bind_param("iss", $venue_id, $eDate, $sDate);
        $stmt_maint->execute();
        if ($stmt_maint->get_result()->num_rows > 0) {
            throw new Exception("These dates are currently under maintenance. Please choose different dates.");
        }

        $ref_no = generate_booking_reference($conn, $venue_category);
        if ($venue_category !== 'Event Hall' && $end_dt <= $start_dt) {
            throw new Exception("Hotel and villa bookings must end after their start date.");
        }

        // =========================================================================
        // SHARED PRICING LOGIC
        // =========================================================================
        require_once '../../includes/pricing.php';
        $stay_type = $_POST['stay_type'] ?? 'Day Time Stay';

        $pricing = calculate_booking_price($conn, $venue_id, $venue_category, $sDate, $eDate, $guests, $stay_type);
        if ($venue_category === 'Event Hall') {
            $event_style = normalize_event_style($_POST['event_style_key'] ?? $_POST['event_style'] ?? null);
            $style_capacity = get_event_style_capacity($conn, (int)$venue_id, $event_style);
            if ($style_capacity === null || $style_capacity <= 0) {
                throw new Exception("Please select a valid Event Hall seating style.");
            }
            if ($guests > $style_capacity) {
                throw new Exception("Guest count exceeds the selected seating style's capacity of {$style_capacity}.");
            }
        } elseif ($pricing['max_capacity'] <= 0) {
            throw new Exception("This venue has no valid guest capacity configured.");
        } elseif ($guests > $pricing['max_capacity']) {
            throw new Exception("Guest count exceeds this venue's maximum capacity of {$pricing['max_capacity']}.");
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
        // Check active locks before finalizing overnight requests. Event Hall
        // inquiries intentionally do not honor booking_locks because they are
        // non-exclusive availability inquiries until staff confirmation.
        $sid = session_id();
        if ($venue_category !== 'Event Hall') {
            $lock_overlap = booking_overlap_sql($venue_category);
            $stmt_check_lock = $conn->prepare("SELECT id FROM booking_locks WHERE venue_id = ? AND session_id != ? AND expires_at > NOW() AND $lock_overlap");
            $stmt_check_lock->bind_param("isss", $venue_id, $sid, $eDate, $sDate);
            $stmt_check_lock->execute();
            if ($stmt_check_lock->get_result()->num_rows > 0) {
                throw new Exception("Another user is currently booking this room for these dates. Please try again.");
            }
        }

        $stmt_book = $conn->prepare("
            INSERT INTO bookings (reference_no, customer_id, venue_id, start_date, end_date, guests_count, contact_phone, base_amount, total_amount, payment_scheme, booking_status, payment_status, source, policy_accepted_at, policy_version)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'Unpaid', 'Online', NOW(), ?)
        ");
        $stmt_book->bind_param("siissisddss",
            $ref_no, $customer_id, $venue_id, $sDate, $eDate,
            $guests, $contact_phone, $base_amount, $true_total, $scheme, $policy_version
        );
        $stmt_book->execute();
        $booking_id = $conn->insert_id;

        // 4. Save Event/Villa Details
        $custom_notes = isset($_POST['custom_notes']) ? trim($_POST['custom_notes']) : null;
        $event_type = isset($_POST['event_type']) ? trim($_POST['event_type']) : null;
        $event_style = normalize_event_style($_POST['event_style_key'] ?? $_POST['event_style'] ?? null);

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
            $room_groups = json_decode((string)$_POST['room_groups'], true);
            if (!is_array($room_groups) || array_keys($room_groups) !== range(0, count($room_groups) - 1) || count($room_groups) < 1 || count($room_groups) > 20) {
                throw new Exception('Invalid hotel room selection.');
            }
            $normalized_room_groups = [];
            $seen_group_keys = [];
            $requested_room_total = 0;
            foreach ($room_groups as $group) {
                if (!is_array($group) || count($group) !== 3 || array_diff(['building_name', 'room_type', 'quantity'], array_keys($group)) !== []) {
                    throw new Exception('Invalid hotel room selection.');
                }
                $building = trim((string)$group['building_name']);
                $type = trim((string)$group['room_type']);
                $qty = $group['quantity'];
                if ($building === '' || $type === '' || strlen($building) > 120 || strlen($type) > 120 || preg_match('/[\x00-\x1F\x7F]/', $building . $type) || !is_int($qty) || $qty < 1 || $qty > 50) {
                    throw new Exception('Invalid hotel room selection.');
                }
                $group_key = strtolower($building) . "\0" . strtolower($type);
                if (isset($seen_group_keys[$group_key])) {
                    throw new Exception('Duplicate hotel room groups are not allowed.');
                }
                $seen_group_keys[$group_key] = true;
                $requested_room_total += $qty;
                if ($requested_room_total > 50) throw new Exception('Too many hotel rooms requested.');
                $normalized_room_groups[] = ['building_name' => $building, 'room_type' => $type, 'quantity' => $qty];
            }
            $room_groups = $normalized_room_groups;

                $room_start = trim($_POST['room_start_date'] ?? '');
                $room_end = trim($_POST['room_end_date'] ?? '');
                $start_dt = DateTime::createFromFormat('!Y-m-d', $room_start);
                $end_dt = DateTime::createFromFormat('!Y-m-d', $room_end);
                if (!$start_dt || !$end_dt || $start_dt->format('Y-m-d') !== $room_start || $end_dt->format('Y-m-d') !== $room_end || $end_dt <= $start_dt || $start_dt < new DateTime('today')) {
                    throw new Exception('Please select a valid hotel stay of at least 1 night.');
                }
                $nights = $start_dt->diff($end_dt)->days;

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
                          WHERE is_blocking = 1 AND (status = 'Scheduled' OR status IS NULL)
                            AND (start_date <= ? AND end_date >= ?)
                      )
                      AND v.id NOT IN (
                          SELECT br.venue_id FROM booking_rooms br
                          JOIN bookings b2 ON br.booking_id = b2.id
                          JOIN venues parent_v ON parent_v.id = b2.venue_id
                          WHERE b2.booking_status IN ('Pending', 'Confirmed', 'Completed')
                            AND NOT (b2.booking_status = 'Pending' AND parent_v.category = 'Event Hall')
                            AND b2.source <> 'Maintenance'
                            AND (br.start_date < ? AND br.end_date > ?)
                      )
                      AND v.id NOT IN (
                          SELECT venue_id FROM booking_locks
                          WHERE session_id != ? AND expires_at > NOW()
                            AND (start_date < ? AND end_date > ?)
                      )
                    ORDER BY v.id
                    LIMIT ? FOR UPDATE
                ");
                if (!$stmt_alloc) throw new Exception('Unable to prepare room availability check.');

                $stmt_insert = $conn->prepare("INSERT INTO booking_rooms (booking_id, venue_id, nightly_rate, start_date, end_date, nights, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt_insert) throw new Exception('Unable to prepare room allocation.');
                $used_room_ids = [];

                foreach ($room_groups as $group) {
                    $building = $group['building_name'];
                    $type = $group['room_type'];
                    $qty = $group['quantity'];

                    if ($qty > 0) {
                        $allocation_limit = min(50, $qty + count($used_room_ids));
                        if (!$stmt_alloc->bind_param('sssssssssssi', $building, $type, $room_end, $room_start, $room_end, $room_start, $room_end, $room_start, $sid, $room_end, $room_start, $allocation_limit) || !$stmt_alloc->execute()) {
                            throw new Exception('Unable to check hotel room availability.');
                        }
                        $alloc_res = $stmt_alloc->get_result();
                        if (!$alloc_res) throw new Exception('Unable to read hotel room availability.');
                        $allocated_count = 0;
                        while ($room = $alloc_res->fetch_assoc()) {
                            $r_venue_id = (int)$room['id'];
                            if (isset($used_room_ids[$r_venue_id])) continue;
                            $used_room_ids[$r_venue_id] = true;
                            $allocated_count++;
                            $r_rate = floatval($room['nightly_rate']);
                            $r_line_total = $r_rate * $nights;

                            if (!$stmt_insert->bind_param("iidssid", $booking_id, $r_venue_id, $r_rate, $room_start, $room_end, $nights, $r_line_total) || !$stmt_insert->execute()) {
                                throw new Exception('Unable to save hotel room allocation.');
                            }

                            // Ensure calculated room add-on totals are saved in booking_line_items
                            $li_name = "Room Add-on: $building - $type";
                            $stmt_li_add = $conn->prepare("INSERT INTO booking_line_items (booking_id, item_name, amount) VALUES (?, ?, ?)");
                            if (!$stmt_li_add || !$stmt_li_add->bind_param("isd", $booking_id, $li_name, $r_line_total) || !$stmt_li_add->execute()) {
                                throw new Exception('Unable to save hotel room pricing.');
                            }
                            if ($allocated_count >= $qty) break;
                        }
                        if ($allocated_count < $qty) throw new Exception("Not enough inventory available for $building - $type. Please try again.");
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

        // Online hotel/villa requests still use PayMongo. Event Hall remains
        // an inquiry with an estimated quote and no upfront payment.
        $amount_due = 0.0;
        if ($venue_category !== 'Event Hall') {
            if ($scheme === '100% Full') $amount_due = $true_total;
            elseif (strpos($scheme, '50%') !== false) $amount_due = $true_total * 0.5;
            elseif (strpos($scheme, '20%') !== false) $amount_due = $true_total * 0.2;
            if ($amount_due <= 0) throw new Exception('Error calculating price. Amount due cannot be zero for Hotels/Villas.');
        }

        if ($amount_due > 0) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $domain = $_SERVER['HTTP_HOST'];
            $success_url = $protocol . '://' . $domain . '/Sevilla360/user_dashboard.php?payment=success';
            $cancel_url = $protocol . '://' . $domain . '/Sevilla360/user_dashboard.php?payment=failed';
            $centavos = (int)round($amount_due * 100);
            $safe_room_name = preg_replace('/[^a-zA-Z0-9\s]/', '', (string)($_POST['room_type'] ?? $venue_category));
            $safe_phone = !empty($contact_phone) ? $contact_phone : '09171234567';
            $payload = ['data' => ['attributes' => [
                'billing' => ['name' => $customer_name, 'email' => $customer_email, 'phone' => $safe_phone],
                'send_email_receipt' => false,
                'show_description' => false,
                'show_line_items' => true,
                'description' => "Sevilla360 Booking: $ref_no",
                'line_items' => [[
                    'currency' => 'PHP',
                    'amount' => $centavos,
                    'name' => "Booking Deposit ($scheme)",
                    'description' => $safe_room_name,
                    'quantity' => 1
                ]],
                'payment_method_types' => ['card', 'gcash', 'paymaya'],
                'reference_number' => $ref_no,
                'success_url' => $success_url,
                'cancel_url' => $cancel_url
            ]]];

            create_user_notification(
                $conn,
                $_SESSION['user_id'],
                'Booking Submitted',
                "Your booking request for " . trim((string)($_POST['room_name'] ?? $venue_category)) . " has been saved. Complete the PayMongo payment to confirm it."
            );
            realtime_enqueue_event($conn, 'admin', 'booking.created', [
                'booking_id' => (int)$booking_id,
                'reference_no' => (string)$ref_no,
                'customer_id' => (int)$_SESSION['user_id'],
                'venue_category' => (string)$venue_category,
            ]);
            if (!$conn->commit()) throw new Exception('Unable to save booking.');
            $db_committed = true;
            try {
                $checkout = paymongo_create_or_reuse_checkout($conn, $booking_id, $amount_due, 0.0, $payload);
                echo 'CheckoutUrl|' . $checkout['checkout_url'];
            } catch (Throwable $provider_error) {
                error_log('Online PayMongo checkout creation failed: ' . get_class($provider_error));
                echo "Error|Booking {$ref_no} was saved as Pending/Unpaid. Payment setup could not be completed; open your dashboard and retry. Reference: {$ref_no}";
            }
            exit();
        }

        $event_message = "Your event inquiry for " . trim((string)($_POST['room_name'] ?? 'Event Hall')) . " has been sent successfully. An admin will review it shortly.";
        create_user_notification($conn, $_SESSION['user_id'], 'Booking Submitted', $event_message);
        realtime_enqueue_event($conn, 'admin', 'booking.created', [
            'booking_id' => (int)$booking_id,
            'reference_no' => (string)$ref_no,
            'customer_id' => (int)$_SESSION['user_id'],
            'venue_category' => (string)$venue_category,
        ]);
        if (!$conn->commit()) throw new Exception('Unable to save booking.');
        $db_committed = true;
        try {
            require_once '../../includes/mailer.php';
            send_booking_receipt($customer_email, $customer_name, $ref_no, $_POST['room_name'] ?? 'Event Hall', 0, 'Inquiry Sent (Pending)');
        } catch (Exception $mail_e) {
            error_log('Online booking email delivery failed: ' . get_class($mail_e));
        }
        echo 'Success|' . $ref_no;

    } catch (Exception $e) {
        if (!$db_committed) $conn->rollback();
        $prefix = $db_committed && !empty($ref_no) ? "Booking {$ref_no} was saved. " : '';
        echo "Error|" . $prefix . $e->getMessage();
    }
}
?>
