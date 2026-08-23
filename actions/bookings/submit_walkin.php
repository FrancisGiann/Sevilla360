<?php
session_start();
require '../../config/db_connect.php';
require_once '../../includes/booking_reference.php';
require_once '../../includes/phone_helper.php';

function submit_walkin_bind_params(mysqli_stmt $statement, string $types, array $values): void
{
    $params = [$types];
    foreach ($values as $index => $value) $params[] = &$values[$index];
    call_user_func_array([$statement, 'bind_param'], $params);
}

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['staff', 'admin'], true)) {
    http_response_code(401);
    echo "Error|Unauthorized access.";
    exit;
}

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
        $sid = session_id();

        $sDate = trim($_POST['start_date'] ?? '');
        $eDate = trim($_POST['end_date'] ?? '');
        $scheme = $_POST['payment_scheme'] ?? '';
        $room_type = $_POST['room_type'];
        $room_name = $_POST['room_name'];
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

        // =========================================================================
        // SECURITY FIX: BACKEND PRICE VERIFICATION
        // Ensures the Walk-in price is 100% accurate based on the Database
        // =========================================================================
        // =========================================================================
        // SECURITY FIX: BACKEND PRICE VERIFICATION
        // Ensures the Walk-in price is 100% accurate based on the Database
        // =========================================================================
        $posted_venue_id = (int)($_POST['venue_id'] ?? 0);
        $tracked_primary_venue = (int)($_SESSION['locked_venue_id'] ?? 0);
        if ($tracked_primary_venue > 0 && $posted_venue_id > 0 && $posted_venue_id !== $tracked_primary_venue) {
            throw new Exception('The selected venue does not match the confirmed date hold.');
        }

        // A confirmed walk-in hold identifies one concrete venue. Always use it
        // when available; grouped hotel submissions must not reselect an arbitrary
        // unit and then compare it after the fact.
        $primary_lookup_id = $tracked_primary_venue > 0 ? $tracked_primary_venue : $posted_venue_id;
        if ($primary_lookup_id > 0) {
            $is_hotel_lookup = !($room_type === 'Event Hall' || $room_type === 'Resort Villa');
            $booking_overlap = $is_hotel_lookup ? '(start_date < ? AND end_date > ?)' : '(start_date <= ? AND end_date >= ?)';
            $stmt_room = $conn->prepare("SELECT v.id, v.category, v.name, h.room_type FROM venues v LEFT JOIN hotel_rooms h ON h.venue_id = v.id WHERE v.id = ? AND v.status = 'Available' AND v.id NOT IN (SELECT venue_id FROM bookings WHERE booking_status IN ('Pending', 'Confirmed', 'Completed') AND source <> 'Maintenance' AND $booking_overlap) AND v.id NOT IN (SELECT venue_id FROM maintenance WHERE is_blocking = 1 AND status = 'Scheduled' AND (start_date <= ? AND end_date >= ?)) FOR UPDATE");
            $stmt_room->bind_param('issss', $primary_lookup_id, $eDate, $sDate, $eDate, $sDate);
        } else {
            throw new Exception('The primary date hold is missing. Please select the dates again.');
        }
        $stmt_room->execute();
        $room_result = $stmt_room->get_result();

        if ($room_result->num_rows === 0) {
            throw new Exception("All units for this specific room were just booked by someone online! Please select different dates.");
        }
        $v_row = $room_result->fetch_assoc();
        $venue_id = $v_row['id'];
        $venue_category = $v_row['category'];

        $is_hotel_context = !($room_type === 'Event Hall' || $room_type === 'Resort Villa');
        $venue_matches_context = $is_hotel_context
            ? $v_row['name'] === $room_name && $v_row['room_type'] === $room_type
            : $v_row['name'] === $room_name && $v_row['category'] === $room_type;
        if (!$venue_matches_context) {
            throw new Exception('The selected venue does not match the requested room group.');
        }
        if ($tracked_primary_venue > 0 && $tracked_primary_venue !== (int)$venue_id) {
            throw new Exception('The primary date hold has expired. Please select the dates again.');
        }
        if ($tracked_primary_venue > 0) {
            $stmt_primary_proof = $conn->prepare("SELECT id FROM booking_locks WHERE session_id = ? AND source = 'walkin' AND venue_id = ? AND expires_at > NOW() AND start_date = ? AND end_date = ? FOR UPDATE");
            $stmt_primary_proof->bind_param('siss', $sid, $venue_id, $sDate, $eDate);
            $stmt_primary_proof->execute();
            if ($stmt_primary_proof->get_result()->num_rows !== 1) {
                throw new Exception('The primary date hold has expired. Please select the dates again.');
            }
        }

        // Add-on room rows must be backed by this session's concrete, unexpired
        // locks. The browser's state is only a hint; the database is authoritative.
        $addon_room_groups = [];
        $addon_room_start = '';
        $addon_room_end = '';
        $addon_locked_by_group = [];
        if ($venue_category === 'Event Hall' && isset($_POST['room_groups'])) {
            if ($tracked_primary_venue <= 0) {
                throw new Exception('The primary date hold is missing. Please select the event dates again.');
            }
            $addon_room_groups = json_decode((string)$_POST['room_groups'], true);
            if (!is_array($addon_room_groups) || count($addon_room_groups) < 1 || count($addon_room_groups) > 20) {
                throw new Exception('Invalid hotel room add-on selection.');
            }
            $addon_room_start = trim((string)($_POST['room_start_date'] ?? ''));
            $addon_room_end = trim((string)($_POST['room_end_date'] ?? ''));
            $addon_start_dt = DateTime::createFromFormat('!Y-m-d', $addon_room_start);
            $addon_end_dt = DateTime::createFromFormat('!Y-m-d', $addon_room_end);
            if (!$addon_start_dt || !$addon_end_dt || $addon_start_dt->format('Y-m-d') !== $addon_room_start || $addon_end_dt->format('Y-m-d') !== $addon_room_end || $addon_start_dt < new DateTime('today') || $addon_end_dt <= $addon_start_dt) {
                throw new Exception('Please select a valid hotel stay of at least one night.');
            }

            $requested_groups = [];
            $requested_total = 0;
            foreach ($addon_room_groups as $group) {
                if (!is_array($group)) throw new Exception('Invalid hotel room add-on group.');
                $building = trim((string)($group['building_name'] ?? ''));
                $type = trim((string)($group['room_type'] ?? ''));
                $quantity = filter_var($group['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
                if ($building === '' || $type === '' || !$quantity || strlen($building) > 120 || strlen($type) > 120 || preg_match('/[\x00-\x1F\x7F]/', $building . $type)) {
                    throw new Exception('Invalid hotel room add-on group.');
                }
                $group_key = $building . "\0" . $type;
                if (isset($requested_groups[$group_key])) throw new Exception('Duplicate hotel room add-on groups are not allowed.');
                $requested_groups[$group_key] = $quantity;
                $requested_total += $quantity;
                if ($requested_total > 50) throw new Exception('The total hotel room add-on quantity is limited to 50 rooms.');
            }

            $tracked_addon_ids = array_values(array_filter(array_map('intval', (array)($_SESSION['walkin_addon_lock_ids'] ?? [])), static fn(int $id): bool => $id > 0));
            if (!$tracked_addon_ids || count($tracked_addon_ids) !== $requested_total) {
                throw new Exception('The hotel room hold has expired. Please select the rooms again.');
            }
            $id_placeholders = implode(',', array_fill(0, count($tracked_addon_ids), '?'));
            $stmt_addon_proof = $conn->prepare("SELECT bl.id, bl.venue_id, bl.start_date, bl.end_date, bl.expires_at, v.name AS building_name, h.room_type FROM booking_locks bl INNER JOIN venues v ON v.id = bl.venue_id INNER JOIN hotel_rooms h ON h.venue_id = bl.venue_id WHERE bl.session_id = ? AND bl.source = 'walkin' AND bl.expires_at > NOW() AND bl.id IN ($id_placeholders) FOR UPDATE");
            submit_walkin_bind_params($stmt_addon_proof, 's' . str_repeat('i', count($tracked_addon_ids)), [$sid, ...$tracked_addon_ids]);
            $stmt_addon_proof->execute();
            $addon_proof_result = $stmt_addon_proof->get_result();
            while ($held = $addon_proof_result->fetch_assoc()) {
                if ($held['start_date'] !== $addon_room_start || $held['end_date'] !== $addon_room_end || (int)$held['venue_id'] === (int)$venue_id) {
                    throw new Exception('The hotel room hold does not match the requested stay. Please select the rooms again.');
                }
                $group_key = $held['building_name'] . "\0" . $held['room_type'];
                $addon_locked_by_group[$group_key][] = (int)$held['venue_id'];
            }
            $held_total = 0;
            foreach ($addon_locked_by_group as $held_group) $held_total += count($held_group);
            if ($held_total !== $requested_total) {
                throw new Exception('The hotel room hold has expired. Please select the rooms again.');
            }
            foreach ($requested_groups as $group_key => $quantity) {
                if (count($addon_locked_by_group[$group_key] ?? []) !== $quantity) {
                    throw new Exception('The hotel room hold no longer matches the requested quantities.');
                }
            }
            $nights = $addon_start_dt->diff($addon_end_dt)->days;
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
        if ($pricing['max_capacity'] > 0 && $guests > $pricing['max_capacity']) {
            throw new Exception("Guest count exceeds this venue's maximum capacity.");
        }
        $base_amount = $pricing['base_amount'];
        $true_total = $pricing['true_total'];

        $total_addons_cost = 0;
        $line_items_data = [];
        if ($venue_category === 'Event Hall' && isset($_POST['custom_line_items'])) {
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
        $guest_phone = normalize_contact_phone((string)($_POST['guest_phone'] ?? ''));
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
        $lock_overlap = ($venue_category === 'Hotel Room')
            ? '(start_date < ? AND end_date > ?)'
            : '(start_date <= ? AND end_date >= ?)';
        $stmt_check_lock = $conn->prepare("SELECT id FROM booking_locks WHERE venue_id = ? AND session_id != ? AND expires_at > NOW() AND $lock_overlap");
        $stmt_check_lock->bind_param("isss", $venue_id, $sid, $eDate, $sDate);
        $stmt_check_lock->execute();
        if ($stmt_check_lock->get_result()->num_rows > 0) {
            throw new Exception("Another admin is currently booking this room for these dates. Please try again.");
        }

        $stmt_book = $conn->prepare("
            INSERT INTO bookings (reference_no, customer_id, venue_id, start_date, end_date, guests_count, contact_phone, base_amount, addons_amount, total_amount, amount_paid, payment_scheme, booking_status, payment_status, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Confirmed', ?, 'Walk-in')
        ");
        $stmt_book->bind_param("siissisddddss",
            $ref_no, $customer_id, $venue_id, $sDate, $eDate,
            $guests, $guest_phone, $base_amount, $total_addons_cost, $true_total, $amount_paid, $scheme, $payment_status
        );
        $stmt_book->execute();
        $booking_id = $conn->insert_id;

        // INSERT LINE ITEMS
        if ($venue_category === 'Event Hall' && is_array($line_items_data) && count($line_items_data) > 0) {
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
        if ($venue_category === 'Event Hall' && $addon_room_groups) {
            $room_start = $addon_room_start;
            $room_end = $addon_room_end;
            $stmt_insert = $conn->prepare("INSERT INTO booking_rooms (booking_id, venue_id, nightly_rate, start_date, end_date, nights, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)");

            foreach ($addon_room_groups as $group) {
                $building = trim($group['building_name']);
                $type = trim($group['room_type']);
                $qty = (int)$group['quantity'];
                $group_key = $building . "\0" . $type;
                $held_ids = $addon_locked_by_group[$group_key] ?? [];
                if (count($held_ids) !== $qty) {
                    throw new Exception("The hotel room hold no longer matches the requested quantities.");
                }
                $held_id_list = implode(',', array_map('intval', $held_ids));
                $stmt_alloc = $conn->prepare("
                    SELECT v.id, h.nightly_rate
                    FROM venues v
                    JOIN hotel_rooms h ON v.id = h.venue_id
                    WHERE v.id IN ($held_id_list)
                      AND v.name = ?
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
                          WHERE b2.booking_status IN ('Pending', 'Confirmed', 'Completed')
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

                $stmt_alloc->bind_param('sssssssssssi', $building, $type, $room_end, $room_start, $room_end, $room_start, $room_end, $room_start, $sid, $room_end, $room_start, $qty);
                $stmt_alloc->execute();
                $alloc_res = $stmt_alloc->get_result();
                if ($alloc_res->num_rows < $qty) {
                    throw new Exception("The held inventory for $building - $type is no longer available.");
                }

                while ($room = $alloc_res->fetch_assoc()) {
                    $r_venue_id = (int)$room['id'];
                    $r_rate = (float)$room['nightly_rate'];
                    $r_line_total = $r_rate * $nights;

                    $stmt_insert->bind_param("iidssid", $booking_id, $r_venue_id, $r_rate, $room_start, $room_end, $nights, $r_line_total);
                    $stmt_insert->execute();

                    // Ensure calculated room add-on totals are saved in booking_line_items
                    $li_name = "Room Add-on: $building - $type";
                    $stmt_li_add = $conn->prepare("INSERT INTO booking_line_items (booking_id, item_name, amount) VALUES (?, ?, ?)");
                    $stmt_li_add->bind_param("isd", $booking_id, $li_name, $r_line_total);
                    $stmt_li_add->execute();
                }
            }
        }
        // =========================================================================

        // SAVE EVENT DETAILS AND NOTES
        $custom_notes = isset($_POST['custom_notes']) ? trim($_POST['custom_notes']) : null;
        $admin_notes = isset($_POST['admin_notes']) ? trim($_POST['admin_notes']) : null;
        $event_type = isset($_POST['event_type']) ? trim($_POST['event_type']) : null;
        $event_style = isset($_POST['event_style']) ? trim($_POST['event_style']) : null;

        if ($venue_category === 'Event Hall' && (!empty($custom_notes) || !empty($admin_notes) || !empty($event_type) || !empty($event_style))) {
            $stmt_notes = $conn->prepare("INSERT INTO booking_event_details (booking_id, event_style, event_type, custom_notes, admin_notes) VALUES (?, ?, ?, ?, ?)");
            $stmt_notes->bind_param("issss", $booking_id, $event_style, $event_type, $custom_notes, $admin_notes);
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
        if (!$conn->commit()) throw new RuntimeException('The walk-in booking could not be committed.');
        unset($_SESSION['locked_venue_id'], $_SESSION['walkin_addon_lock_ids']);

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
