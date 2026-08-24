<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');

// 1. Auth Guard: Ensure only admins can execute this
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'staff' && $_SESSION['role'] !== 'admin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/booking_rules.php';
require_once __DIR__ . '/../../includes/request_context.php';
require_once __DIR__ . '/../../includes/refund_helper.php';
require_once __DIR__ . '/../../includes/realtime.php';

// Include mailer for notifications
require_once '../../includes/mailer.php';
require_once '../../includes/notifications.php';

// 2. Get POST JSON Data
$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

if (!isset($data['booking_id']) || !isset($data['action'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request. Missing data.']);
    exit;
}

$booking_id = intval($data['booking_id']);
$action = $data['action'];
$postCommitActions = [];

try {
    $conn->begin_transaction();

    // ==========================================
    // FETCH CUSTOMER DATA FOR EMAILS
    // ==========================================
    $stmt_info = $conn->prepare("
        SELECT b.reference_no, b.venue_id, b.start_date, b.end_date, b.booking_status, b.amount_paid, v.category, c.email, c.first_name, c.last_name, v.name as venue_name, c.user_id
        FROM bookings b
        JOIN customers c ON b.customer_id = c.id
        JOIN venues v ON b.venue_id = v.id
        WHERE b.id = ?
    ");
    $stmt_info->bind_param("i", $booking_id);
    $stmt_info->execute();
    $b_info = $stmt_info->get_result()->fetch_assoc();
    if (!$b_info) {
        throw new Exception("Booking not found.");
    }

    $c_email = $b_info['email'] ?? '';
    $c_name = ($b_info['first_name'] ?? '') . ' ' . ($b_info['last_name'] ?? '');
    $ref_no = $b_info['reference_no'] ?? '';
    $v_name = $b_info['venue_name'] ?? '';
    $c_user_id = $b_info['user_id'] ?? null;
    $is_pending_event_hall = ($b_info['category'] === 'Event Hall' && $b_info['booking_status'] === 'Pending');

    // All booking/payment/cancellation paths acquire the booking row first.
    // This deterministic order prevents a refund transition from racing a
    // provider reconciliation or customer request that also locks bookings.
    $stmt_booking_lock = $conn->prepare('SELECT id, booking_status, payment_status, amount_paid, total_amount FROM bookings WHERE id = ? FOR UPDATE');
    $stmt_booking_lock->bind_param('i', $booking_id);
    if (!$stmt_booking_lock->execute()) throw new Exception('Booking lock unavailable.');
    $locked_booking = $stmt_booking_lock->get_result()->fetch_assoc();
    if (!$locked_booking) throw new Exception('Booking not found.');
    // All state-dependent decisions below use this locked snapshot rather
    // than the initial display query, which may have become stale.
    $b_info['booking_status'] = $locked_booking['booking_status'];
    $b_info['amount_paid'] = $locked_booking['amount_paid'];
    $b_info['payment_status'] = $locked_booking['payment_status'];
    $b_info['total_amount'] = $locked_booking['total_amount'];
    $is_pending_event_hall = ($b_info['category'] === 'Event Hall' && $locked_booking['booking_status'] === 'Pending');

    // Serialize inventory decisions for this venue within the transaction.
    // This protects cooperating paths; a schema-level exclusion constraint is
    // still unavailable in the current MySQL schema.
    $stmt_venue_lock = $conn->prepare("SELECT id FROM venues WHERE id = ? FOR UPDATE");
    $stmt_venue_lock->bind_param('i', $b_info['venue_id']);
    if (!$stmt_venue_lock->execute() || $stmt_venue_lock->get_result()->num_rows === 0) throw new Exception('Venue not found.');

    // ==========================================
    // ACTIONS
    // ==========================================
    if ($action === 'confirm') {
        if ($is_pending_event_hall) {
            throw new Exception('Finalize the Event Hall invoice first before approving this inquiry.');
        }
        $was_already_confirmed = ($b_info['booking_status'] === 'Confirmed');
        $stmt_conflict = $conn->prepare("SELECT id FROM bookings WHERE venue_id = ? AND id != ? AND booking_status IN ('Confirmed', 'Completed') AND source <> 'Maintenance' AND " . booking_overlap_sql($b_info['category']) . " LIMIT 1");
        $stmt_conflict->bind_param("iiss", $b_info['venue_id'], $booking_id, $b_info['end_date'], $b_info['start_date']);
        $stmt_conflict->execute();
        if ($stmt_conflict->get_result()->num_rows > 0) {
            throw new Exception("This venue is already confirmed for the selected dates.");
        }
        $stmt_maintenance = $conn->prepare("SELECT id FROM maintenance WHERE venue_id = ? AND is_blocking = 1 AND status = 'Scheduled' AND start_date <= ? AND end_date >= ? LIMIT 1");
        $stmt_maintenance->bind_param("iss", $b_info['venue_id'], $b_info['end_date'], $b_info['start_date']);
        $stmt_maintenance->execute();
        if ($stmt_maintenance->get_result()->num_rows > 0) {
            throw new Exception("This venue is under maintenance for the selected dates.");
        }
        $stmt = $conn->prepare("UPDATE bookings SET booking_status = 'Confirmed' WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $message = "Booking #$ref_no has been confirmed!";

        // Notify only on an actual transition to Confirmed. Repeating the
        // same admin action must not create duplicate customer alerts.
        if (!$was_already_confirmed) {
            create_user_notification(
                $conn,
                $c_user_id,
                "Booking Confirmed",
                "Your booking for $v_name (Reference: $ref_no) has been approved."
            );
        }

    }
    elseif ($action === 'cancel') {
        $stmt = $conn->prepare("UPDATE bookings SET booking_status = 'Cancelled' WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $message = "Booking #$ref_no has been cancelled!";

    }
    elseif ($action === 'finalize_event_invoice') {
        if ($b_info['category'] !== 'Event Hall') throw new Exception('Only Event Hall bookings can receive an event quotation.');

        // Finalizing an invoice confirms the inquiry, so re-check the same
        // inclusive Event Hall inventory conditions as the explicit confirm
        // action while the venue row remains locked by this transaction.
        $stmt_event_conflict = $conn->prepare("SELECT id FROM bookings WHERE venue_id = ? AND id != ? AND booking_status IN ('Confirmed', 'Completed') AND source <> 'Maintenance' AND " . booking_overlap_sql('Event Hall') . " LIMIT 1");
        if (!$stmt_event_conflict) throw new Exception('Unable to validate Event Hall availability.');
        $stmt_event_conflict->bind_param('iiss', $b_info['venue_id'], $booking_id, $b_info['end_date'], $b_info['start_date']);
        if (!$stmt_event_conflict->execute()) throw new Exception('Unable to validate Event Hall availability.');
        if ($stmt_event_conflict->get_result()->num_rows > 0) {
            throw new Exception('This Event Hall is already confirmed for the selected dates.');
        }

        $stmt_event_maintenance = $conn->prepare("SELECT id FROM maintenance WHERE venue_id = ? AND is_blocking = 1 AND status = 'Scheduled' AND " . maintenance_overlap_sql() . " LIMIT 1");
        if (!$stmt_event_maintenance) throw new Exception('Unable to validate Event Hall maintenance availability.');
        $stmt_event_maintenance->bind_param('iss', $b_info['venue_id'], $b_info['end_date'], $b_info['start_date']);
        if (!$stmt_event_maintenance->execute()) throw new Exception('Unable to validate Event Hall maintenance availability.');
        if ($stmt_event_maintenance->get_result()->num_rows > 0) {
            throw new Exception('This Event Hall is under maintenance for the selected dates.');
        }

        $guests = intval($data['guests']);
        $event_type = trim((string)($data['event_type'] ?? ''));
        $event_style_key = normalize_event_style($data['event_style_key'] ?? null);
        $base_rate = floatval($data['base_rate']);
        if ($guests < 1 || !is_finite($base_rate) || $base_rate < 0) throw new Exception('Invalid Event Hall quotation values.');

        // Staff must choose a canonical style. Legacy decorative labels are
        // intentionally not mapped to a capacity without an explicit choice.
        $style_capacity = get_event_style_capacity($conn, (int)$b_info['venue_id'], $event_style_key);
        if ($style_capacity === null || $style_capacity <= 0) {
            throw new Exception('Select a canonical Event Hall seating style with a valid configured capacity.');
        }
        if ($guests > $style_capacity) {
            throw new Exception("Guest count exceeds the selected seating style capacity of {$style_capacity}.");
        }
        $scheme = $data['payment_scheme'] ?? '100% Full'; // <--- GRABS IT FROM JS
        $line_items = $data['line_items'] ?? [];

        // Pending Event Hall add-ons are provisional requests. Reallocate them
        // against currently available rooms before using their subtotal.
        $room_subtotal = $b_info['booking_status'] === 'Pending'
            ? reallocate_event_hall_addons($conn, $booking_id)
            : 0.0;

        // 1. Calculate new math
        $addons_amount = 0;
        $normalized_line_items = [];
        foreach ($line_items as $item) {
            $name = trim((string)($item['name'] ?? ''));
            $amount = (float)($item['amount'] ?? 0);
            if ($name === '' || !is_finite($amount) || $amount < 0) continue;
            // Allocated room inventory is authoritative; do not let an edited
            // browser line item replace or duplicate its database subtotal.
            if (str_starts_with($name, 'Room Add-on:') || $name === 'Hotel Add-on Rooms' || $name === 'Event Hall + Hotel Bundle Discount (20%)') continue;
            $normalized_line_items[] = ['name' => $name, 'amount' => $amount];
            $addons_amount += $amount;
        }
        if ($b_info['booking_status'] !== 'Pending') {
            $stmt_rooms_total = $conn->prepare("SELECT COALESCE(SUM(line_total), 0) AS room_subtotal FROM booking_rooms WHERE booking_id = ?");
            $stmt_rooms_total->bind_param('i', $booking_id);
            $stmt_rooms_total->execute();
            $room_subtotal = (float)($stmt_rooms_total->get_result()->fetch_assoc()['room_subtotal'] ?? 0);
        }
        if ($room_subtotal > 0) {
            $normalized_line_items[] = ['name' => 'Hotel Add-on Rooms', 'amount' => $room_subtotal];
            $addons_amount += $room_subtotal;
            $bundle_discount = round(($base_rate + $room_subtotal) * 0.20, 2);
            $normalized_line_items[] = ['name' => 'Event Hall + Hotel Bundle Discount (20%)', 'amount' => -$bundle_discount];
            $addons_amount -= $bundle_discount;
        }
        $new_total = $base_rate + $addons_amount;

        // 2. Update Main Bookings Table (SAVES THE SCHEME!)
        $stmt_b = $conn->prepare("UPDATE bookings SET guests_count = ?, base_amount = ?, addons_amount = ?, total_amount = ?, payment_scheme = ?, booking_status = 'Confirmed' WHERE id = ?");
        $stmt_b->bind_param("idddsi", $guests, $base_rate, $addons_amount, $new_total, $scheme, $booking_id);
        $stmt_b->execute();

        // 3. Update Event Details (including internal admin notes)
        $admin_notes = isset($data['admin_notes']) ? trim($data['admin_notes']) : null;
        $stmt_e = $conn->prepare("INSERT INTO booking_event_details (booking_id, event_style, event_type, admin_notes) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE event_style = VALUES(event_style), event_type = VALUES(event_type), admin_notes = VALUES(admin_notes)");
        $stmt_e->bind_param("isss", $booking_id, $event_style_key, $event_type, $admin_notes);
        $stmt_e->execute();

        // 4. Wipe old initial addons & rewrite new line items
        $stmt_delete_addons = $conn->prepare("DELETE FROM booking_addons WHERE booking_id = ?");
        $stmt_delete_addons->bind_param('i', $booking_id);
        $stmt_delete_addons->execute();
        $stmt_delete_lines = $conn->prepare("DELETE FROM booking_line_items WHERE booking_id = ?");
        $stmt_delete_lines->bind_param('i', $booking_id);
        $stmt_delete_lines->execute();

        if (!empty($normalized_line_items)) {
            $stmt_li = $conn->prepare("INSERT INTO booking_line_items (booking_id, item_name, amount) VALUES (?, ?, ?)");
            foreach ($normalized_line_items as $item) {
                $name = $item['name'];
                $amt = $item['amount'];
                $stmt_li->bind_param("isd", $booking_id, $name, $amt);
                $stmt_li->execute();
            }
        }

        // 5. Send Notification Email
        try {
            // Generates a link straight to their dashboard
            $domain = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://";
            $dash_link = $domain . $_SERVER['HTTP_HOST'] . "/Sevilla360/user_dashboard.php";
            send_invoice_ready_email($c_email, $c_name, $ref_no, $new_total, $dash_link);
        } catch (Throwable $mail_e) {
            error_log('Invoice email delivery failed: ' . get_class($mail_e) . ' booking_id=' . (int)$booking_id);
        }

        create_user_notification($conn, $c_user_id, "Quotation Ready", "Your event quotation for $v_name is ready. Please review it on your dashboard.");

        $message = "Invoice finalized and sent to customer!";
    }elseif ($action === 'add_payment') {
        $amount_to_add = floatval($data['amount']);
        $method = $data['method'];
        $trans_id = !empty($data['transaction_id']) ? $data['transaction_id'] : 'CASH-' . time();

        $stmt_check = $conn->prepare("SELECT total_amount, amount_paid FROM bookings WHERE id = ?");
        $stmt_check->bind_param("i", $booking_id);
        $stmt_check->execute();
        $res = $stmt_check->get_result();

        if ($res->num_rows === 0) throw new Exception('Booking not found.');
        $booking = $res->fetch_assoc();

        if ($is_pending_event_hall) {
            throw new Exception('Finalize the Event Hall invoice first before recording payment for this inquiry.');
        }

        // GUARD AGAINST OVERPAYMENT
        $current_paid = floatval($booking['amount_paid']);
        $total = floatval($booking['total_amount']);
        $remaining_due = $total - $current_paid;

        if ($amount_to_add <= 0) {
            throw new Exception("Payment amount must be greater than zero.");
        }
        if ($remaining_due <= 0) {
            throw new Exception("This booking is already fully paid. No balance remaining.");
        }
        if ($amount_to_add > $remaining_due + 0.01) {
            throw new Exception("Amount exceeds the remaining balance of ₱" . number_format($remaining_due, 2) . ".");
        }

        // Idempotency
        if (!empty($data['transaction_id'])) {
            $stmt_dupe = $conn->prepare("SELECT id FROM payments WHERE transaction_id = ?");
            $stmt_dupe->bind_param("s", $trans_id);
            $stmt_dupe->execute();
            if ($stmt_dupe->get_result()->num_rows > 0) {
                throw new Exception("This transaction ID has already been recorded.");
            }
        }

        $new_amount_paid = $current_paid + $amount_to_add;
        $new_payment_status = ($new_amount_paid >= $total && $total > 0) ? 'Paid' : (($new_amount_paid > 0) ? 'Partial' : 'Unpaid');

        $stmt_pay = $conn->prepare("INSERT INTO payments (booking_id, transaction_id, payment_method, amount, status) VALUES (?, ?, ?, ?, 'Success')");
        $stmt_pay->bind_param("issd", $booking_id, $trans_id, $method, $amount_to_add);
        $stmt_pay->execute();

        $stmt_update = $conn->prepare("UPDATE bookings SET payment_status = ?, amount_paid = ?, booking_status = 'Confirmed' WHERE id = ?");
        $stmt_update->bind_param("sdi", $new_payment_status, $new_amount_paid, $booking_id);
        $stmt_update->execute();

        $message = "Payment of ₱" . number_format($amount_to_add, 2) . " received successfully!";

        // Send email receipt for admin manual payments
        try {
            $email_status = ($new_payment_status === 'Paid') ? 'Fully Paid' : 'Partially Paid (Manual Payment)';
            send_booking_receipt($c_email, $c_name, $ref_no, $v_name, $new_amount_paid, $email_status);
        } catch (Throwable $mail_e) {
            // Silently fail so the admin doesn't get an error popup if Gmail is slow
            error_log('Admin payment receipt delivery failed: ' . get_class($mail_e) . ' booking_id=' . (int)$booking_id);
        }

        create_user_notification($conn, $c_user_id, "Payment Received", "A payment of ₱" . number_format($amount_to_add, 2) . " for your booking at $v_name has been confirmed.");
        // =========================================================
    }
    elseif ($action === 'reschedule') {
        if (!isset($data['new_start_date']) || !isset($data['new_end_date'])) {
            throw new Exception("Missing new dates for reschedule.");
        }
        $new_start = $data['new_start_date'];
        $new_end = $data['new_end_date'];

        $new_start_dt = DateTime::createFromFormat('!Y-m-d', $new_start);
        $new_end_dt = DateTime::createFromFormat('!Y-m-d', $new_end);
        $today = new DateTime('today');
        $original_start_dt = new DateTime($b_info['start_date']);
        $original_end_dt = new DateTime($b_info['end_date']);
        if (!$new_start_dt || !$new_end_dt || $new_start_dt->format('Y-m-d') !== $new_start || $new_end_dt->format('Y-m-d') !== $new_end || $new_end_dt < $new_start_dt || $new_start_dt < $today) {
            throw new Exception("Invalid reschedule date range.");
        }
        if ($new_start_dt->diff($new_end_dt)->days !== $original_start_dt->diff($original_end_dt)->days) {
            throw new Exception("Rescheduling must keep the original booking duration.");
        }

        // 1. Get original venue info
        $stmt_venue = $conn->prepare("
            SELECT b.venue_id, v.category, v.name, h.room_type
            FROM bookings b
            JOIN venues v ON b.venue_id = v.id
            LEFT JOIN hotel_rooms h ON v.id = h.venue_id
            WHERE b.id = ?
        ");
        $stmt_venue->bind_param("i", $booking_id);
        $stmt_venue->execute();
        $b_data = $stmt_venue->get_result()->fetch_assoc();
        $venue_id = $b_data['venue_id'];

        if ($b_data['category'] === 'Hotel Room') {
            // Find ANY available unit in this building and room_type
            $r_type = $b_data['room_type'];
            $r_name = $b_data['name'];
            $stmt_inv = $conn->prepare("
                SELECT v.id FROM venues v JOIN hotel_rooms h ON v.id = h.venue_id
                WHERE h.room_type = ? AND v.name = ? AND v.status = 'Available'
                ORDER BY v.id
                FOR UPDATE
            ");
            if (!$stmt_inv) throw new Exception('Unable to lock candidate hotel rooms.');
            $stmt_inv->bind_param("ss", $r_type, $r_name);
            if (!$stmt_inv->execute()) throw new Exception('Unable to lock candidate hotel rooms.');
            $res_inv = $stmt_inv->get_result();

            $assigned_venue_id = null;
            while ($row = $res_inv->fetch_assoc()) {
                $vid = (int)$row['id'];

                // Check maintenance, direct bookings, and add-on bookings with
                // prepared predicates; hotel checkout remains exclusive.
                $maint = $conn->prepare("SELECT id FROM maintenance WHERE venue_id = ? AND is_blocking = 1 AND status = 'Scheduled' AND " . maintenance_overlap_sql());
                if (!$maint) throw new Exception('Unable to check room maintenance.');
                $maint->bind_param('iss', $vid, $new_end, $new_start);
                if (!$maint->execute()) throw new Exception('Unable to check room maintenance.');
                if ($maint->get_result()->num_rows > 0) continue;

                $locks = $conn->prepare("SELECT id FROM booking_locks WHERE venue_id = ? AND expires_at > NOW() AND " . booking_overlap_sql('Hotel Room') . " LIMIT 1");
                if (!$locks) throw new Exception('Unable to check active room holds.');
                $locks->bind_param('iss', $vid, $new_end, $new_start);
                if (!$locks->execute()) throw new Exception('Unable to check active room holds.');
                if ($locks->get_result()->num_rows > 0) continue;

                $booking_overlap = booking_overlap_sql('Hotel Room');
                $bk = $conn->prepare("SELECT id FROM bookings WHERE venue_id = ? AND booking_status IN ('Pending', 'Confirmed') AND source <> 'Maintenance' AND id != ? AND $booking_overlap");
                $bk->bind_param('iiss', $vid, $booking_id, $new_end, $new_start);
                $bk->execute();
                if ($bk->get_result()->num_rows > 0) continue;

                $addons = $conn->prepare("SELECT br.id FROM booking_rooms br JOIN bookings b ON br.booking_id = b.id JOIN venues parent_v ON parent_v.id = b.venue_id WHERE br.venue_id = ? AND b.booking_status IN ('Pending', 'Confirmed') AND NOT (b.booking_status = 'Pending' AND parent_v.category = 'Event Hall') AND b.source <> 'Maintenance' AND b.id != ? AND " . booking_overlap_sql('Hotel Room', 'br.start_date', 'br.end_date'));
                $addons->bind_param('iiss', $vid, $booking_id, $new_end, $new_start);
                $addons->execute();
                if ($addons->get_result()->num_rows > 0) continue;

                $assigned_venue_id = $vid;
                break;
            }

            if (!$assigned_venue_id) {
                throw new Exception("Collision Error: All rooms of this type are booked on the requested dates.");
            }
            $venue_id = $assigned_venue_id;

        } else {
            // For Event Halls / Villas, just check the specific unit
            $reschedule_status_filter = $b_data['category'] === 'Event Hall'
                ? "IN ('Confirmed', 'Completed')"
                : "IN ('Pending', 'Confirmed')";
            $check_overlap = $conn->prepare("SELECT id FROM bookings WHERE venue_id = ? AND booking_status $reschedule_status_filter AND source <> 'Maintenance' AND id != ? AND " . booking_overlap_sql($b_data['category']));
            $check_overlap->bind_param("iiss", $venue_id, $booking_id, $new_end, $new_start);
            $check_overlap->execute();

            if ($check_overlap->get_result()->num_rows > 0) {
                throw new Exception("Collision Error: Those dates were just taken by another customer. Cannot reschedule.");
            }
        }

        // 2. Validate and re-allocate any Room Add-ons
        $stmt_addons = $conn->prepare("
            SELECT br.id, br.venue_id, br.start_date, br.end_date, br.nights, v.name as building_name, h.room_type
            FROM booking_rooms br
            JOIN venues v ON br.venue_id = v.id
            JOIN hotel_rooms h ON v.id = h.venue_id
            WHERE br.booking_id = ?
            ORDER BY v.name, h.room_type, br.id
        ");
        $stmt_addons->bind_param("i", $booking_id);
        $stmt_addons->execute();
        $res_addons = $stmt_addons->get_result();

        $new_allocations = [];
        $reserved_addon_venues = [];
        $shift_days = (int)$original_start_dt->diff($new_start_dt)->format('%r%a');

        while ($addon = $res_addons->fetch_assoc()) {
            $br_id = $addon['id'];
            $br_vid = $addon['venue_id'];
            $r_type = $addon['room_type'];
            $r_name = $addon['building_name'];
            $addon_start_dt = new DateTime($addon['start_date']);
            $addon_end_dt = new DateTime($addon['end_date']);
            if ($shift_days !== 0) {
                $addon_start_dt->modify(($shift_days > 0 ? '+' : '') . $shift_days . ' days');
                $addon_end_dt->modify(($shift_days > 0 ? '+' : '') . $shift_days . ' days');
            }
            $addon_new_start = $addon_start_dt->format('Y-m-d');
            $addon_new_end = $addon_end_dt->format('Y-m-d');
            $addon_nights = (int)$addon['nights'];
            if ($addon_start_dt < $today) {
                throw new Exception('The room add-on would move into the past. Choose a later event date.');
            }

            // Find ANY available unit in this building and room_type for the new dates
            $stmt_inv = $conn->prepare("
                SELECT v.id FROM venues v JOIN hotel_rooms h ON v.id = h.venue_id
                WHERE h.room_type = ? AND v.name = ? AND v.status = 'Available'
                ORDER BY v.id
                FOR UPDATE
            ");
            if (!$stmt_inv) throw new Exception('Unable to lock candidate add-on rooms.');
            $stmt_inv->bind_param("ss", $r_type, $r_name);
            if (!$stmt_inv->execute()) throw new Exception('Unable to lock candidate add-on rooms.');
            $res_inv = $stmt_inv->get_result();

            $assigned_venue_id = null;
            while ($row = $res_inv->fetch_assoc()) {
                $vid = (int)$row['id'];

                // If we already assigned this $vid in this loop or for the main venue, skip it
                if (in_array($vid, $reserved_addon_venues, true) || $vid === $venue_id) continue;

                // Check maintenance, direct bookings, and add-on bookings using
                // bound values to prevent reschedule SQL injection.
                $maint = $conn->prepare("SELECT id FROM maintenance WHERE venue_id = ? AND is_blocking = 1 AND status = 'Scheduled' AND " . maintenance_overlap_sql());
                if (!$maint) throw new Exception('Unable to check add-on maintenance.');
                $maint->bind_param('iss', $vid, $addon_new_end, $addon_new_start);
                if (!$maint->execute()) throw new Exception('Unable to check add-on maintenance.');
                if ($maint->get_result()->num_rows > 0) continue;

                $locks = $conn->prepare("SELECT id FROM booking_locks WHERE venue_id = ? AND expires_at > NOW() AND " . booking_overlap_sql('Hotel Room') . " LIMIT 1");
                if (!$locks) throw new Exception('Unable to check active add-on room holds.');
                $locks->bind_param('iss', $vid, $addon_new_end, $addon_new_start);
                if (!$locks->execute()) throw new Exception('Unable to check active add-on room holds.');
                if ($locks->get_result()->num_rows > 0) continue;

                $bk = $conn->prepare("SELECT id FROM bookings WHERE venue_id = ? AND booking_status IN ('Pending', 'Confirmed') AND source <> 'Maintenance' AND id != ? AND " . booking_overlap_sql('Hotel Room'));
                $bk->bind_param('iiss', $vid, $booking_id, $addon_new_end, $addon_new_start);
                $bk->execute();
                if ($bk->get_result()->num_rows > 0) continue;

                $addons_check = $conn->prepare("SELECT br.id FROM booking_rooms br JOIN bookings b ON br.booking_id = b.id JOIN venues parent_v ON parent_v.id = b.venue_id WHERE br.venue_id = ? AND b.booking_status IN ('Pending', 'Confirmed') AND NOT (b.booking_status = 'Pending' AND parent_v.category = 'Event Hall') AND b.source <> 'Maintenance' AND b.id != ? AND " . booking_overlap_sql('Hotel Room', 'br.start_date', 'br.end_date'));
                $addons_check->bind_param('iiss', $vid, $booking_id, $addon_new_end, $addon_new_start);
                $addons_check->execute();
                if ($addons_check->get_result()->num_rows > 0) continue;

                $assigned_venue_id = $vid;
                break;
            }

            if (!$assigned_venue_id) {
                throw new Exception("Collision Error: Not enough available '$r_name - $r_type' units for the new dates. Cannot reschedule.");
            }
            $reserved_addon_venues[] = $assigned_venue_id;
            $new_allocations[$br_id] = [
                'venue_id' => $assigned_venue_id,
                'start_date' => $addon_new_start,
                'end_date' => $addon_new_end,
                'nights' => $addon_nights
            ];
        }

        // Apply new allocations for add-ons
        foreach ($new_allocations as $br_id => $allocation) {
            $allocation_venue_id = $allocation['venue_id'];
            $allocation_start = $allocation['start_date'];
            $allocation_end = $allocation['end_date'];
            $allocation_nights = $allocation['nights'];
            $stmt_upd_br = $conn->prepare("UPDATE booking_rooms SET venue_id = ?, start_date = ?, end_date = ?, nights = ?, line_total = nightly_rate * ? WHERE id = ?");
            $stmt_upd_br->bind_param("issiii", $allocation_venue_id, $allocation_start, $allocation_end, $allocation_nights, $allocation_nights, $br_id);
            $stmt_upd_br->execute();
        }

        // Update the main booking dates (and potentially the auto-assigned venue_id)
        $stmt = $conn->prepare("UPDATE bookings SET venue_id = ?, start_date = ?, end_date = ? WHERE id = ?");
        $stmt->bind_param("issi", $venue_id, $new_start, $new_end, $booking_id);
        $stmt->execute();

        $stmt_req = $conn->prepare("UPDATE reschedule_requests SET status = 'Approved' WHERE booking_id = ? AND status = 'Pending'");
        $stmt_req->bind_param("i", $booking_id);
        $stmt_req->execute();

        $message = "Booking #$ref_no successfully rescheduled to $new_start!";

        // External mail and customer-facing notifications are dispatched only
        // after the date/allocation transaction commits successfully.
        $postCommitActions[] = [
            'kind' => 'reschedule_approved',
            'email' => $c_email,
            'name' => $c_name,
            'booking_id' => $booking_id,
            'user_id' => $c_user_id,
            'venue_name' => $v_name,
            'new_start' => $new_start
        ];
    }
    elseif ($action === 'reject_reschedule') {
        $admin_reply = isset($data['admin_reply']) ? trim((string)$data['admin_reply']) : "No reason provided.";
        if ($admin_reply === '' || strlen($admin_reply) > 500 || preg_match('/[\x00-\x1F\x7F]/', $admin_reply)) throw new Exception('A bounded rejection reason is required.');

        $stmt_req = $conn->prepare("UPDATE reschedule_requests SET status = 'Rejected', admin_reply = ? WHERE booking_id = ? AND status = 'Pending'");
        $stmt_req->bind_param("si", $admin_reply, $booking_id);
        $stmt_req->execute();

        $message = "Reschedule request for Booking #$ref_no rejected.";

        $postCommitActions[] = [
            'kind' => 'reschedule_rejected',
            'email' => $c_email,
            'name' => $c_name,
            'booking_id' => $booking_id,
            'user_id' => $c_user_id,
            'venue_name' => $v_name,
            'reason' => $admin_reply
        ];
    }
    elseif ($action === 'refund') {
        $stmt_refund = $conn->prepare("SELECT id, reason, refund_amount, fee_deducted, fee_percent, status FROM cancellations WHERE booking_id = ? AND status = 'Pending' LIMIT 1 FOR UPDATE");
        $stmt_refund->bind_param("i", $booking_id);
        $stmt_refund->execute();
        $refund_row = $stmt_refund->get_result()->fetch_assoc();
        if (!$refund_row) throw new Exception('No pending refund request exists for this booking.');
        $refund_amount = $refund_row ? floatval($refund_row['refund_amount']) : null;

        $refund_tx_id = trim((string)($data['refund_transaction_id'] ?? ''));
        if ($refund_tx_id === '' || strlen($refund_tx_id) > 160 || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,159}\z/D', $refund_tx_id)) {
            throw new Exception('A valid refund transaction/reference ID is required.');
        }

        $stmt = $conn->prepare("UPDATE bookings SET booking_status = 'Cancelled', payment_status = 'Refunded' WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();

        $stmt_cancel = $conn->prepare("UPDATE cancellations SET status = 'Processed', refund_transaction_id = ? WHERE id = ? AND status = 'Pending'");
        $stmt_cancel->bind_param("si", $refund_tx_id, $refund_row['id']);
        $stmt_cancel->execute();
        if ($stmt_cancel->affected_rows !== 1) throw new Exception('Refund request changed before it could be processed.');
        record_cancellation_history($conn, $booking_id, (int)$refund_row['id'], 'processed', (string)$refund_row['reason'], (float)$refund_row['refund_amount'], (float)$refund_row['fee_deducted'], (float)$refund_row['fee_percent'], null, (int)$_SESSION['user_id']);

        // Also clean up any pending reschedule request for this booking
        $stmt_resched = $conn->prepare("UPDATE reschedule_requests SET status = 'Rejected', admin_reply = 'Booking Cancelled' WHERE booking_id = ? AND status = 'Pending'");
        $stmt_resched->bind_param("i", $booking_id);
        $stmt_resched->execute();

        $message = "Refund processed and Booking #$ref_no cancelled.";

        // Email and customer notifications are dispatched only after the
        // booking/cancellation transaction commits below.
        $postCommitActions[] = [
            'kind' => 'refund_processed',
            'email' => $c_email,
            'name' => $c_name,
            'booking_id' => $booking_id,
            'refund_amount' => $refund_amount,
            'user_id' => $c_user_id,
            'venue_name' => $v_name
        ];
    }
    elseif ($action === 'reject_refund') {
        if ($_SESSION['role'] !== 'admin') throw new Exception('Only administrators may reject refund requests.');
        $admin_reply = trim((string)($data['reason'] ?? ''));
        if ($admin_reply === '' || strlen($admin_reply) > 500 || preg_match('/[\x00-\x1F\x7F]/', $admin_reply)) throw new Exception('A bounded rejection reason is required.');
        $stmt_refund = $conn->prepare("SELECT id FROM cancellations WHERE booking_id = ? AND status = 'Pending' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $stmt_refund->bind_param('i', $booking_id);
        $stmt_refund->execute();
        $refund_row = $stmt_refund->get_result()->fetch_assoc();
        if (!$refund_row) throw new Exception('No pending refund request exists for this booking.');
        $stmt_cancel = $conn->prepare("UPDATE cancellations SET status = 'Rejected', admin_reply = ? WHERE id = ? AND status = 'Pending'");
        $stmt_cancel->bind_param('si', $admin_reply, $refund_row['id']);
        if (!$stmt_cancel->execute() || $stmt_cancel->affected_rows !== 1) throw new Exception('Refund request changed before rejection could be saved.');
        $stmt_snapshot = $conn->prepare('SELECT reason, refund_amount, fee_deducted, fee_percent FROM cancellations WHERE id = ? FOR UPDATE');
        $stmt_snapshot->bind_param('i', $refund_row['id']); $stmt_snapshot->execute();
        $snapshot = $stmt_snapshot->get_result()->fetch_assoc();
        record_cancellation_history($conn, $booking_id, (int)$refund_row['id'], 'rejected', (string)($snapshot['reason'] ?? ''), (float)($snapshot['refund_amount'] ?? 0), (float)($snapshot['fee_deducted'] ?? 0), (float)($snapshot['fee_percent'] ?? 3), $admin_reply, (int)$_SESSION['user_id']);
        $message = "Refund request for Booking #$ref_no rejected.";
        // Email and customer notifications are dispatched only after the
        // rejection transaction commits below.
        $postCommitActions[] = [
            'kind' => 'refund_rejected',
            'email' => $c_email,
            'name' => $c_name,
            'reference' => $ref_no,
            'venue_name' => $v_name,
            'reason' => $admin_reply,
            'user_id' => $c_user_id
        ];
    }
    elseif ($action === 'admin_force_cancel') {
        if (!isset($data['reason'])) throw new Exception("Reason is required.");

        $reason = trim((string)$data['reason']);
        if ($reason === '' || strlen($reason) > 500 || preg_match('/[\x00-\x1F\x7F]/', $reason)) throw new Exception('A bounded cancellation reason is required.');
        // Force cancellation is always a full refund of the amount actually
        // paid; never trust the client-provided display amount.
        $refund_amount = max(0.0, (float)$locked_booking['amount_paid']);
        $fee = 0.00; // Resort shoulders the fee

        $stmt_cx = $conn->prepare("SELECT id, status FROM cancellations WHERE booking_id = ? LIMIT 1 FOR UPDATE");
        $stmt_cx->bind_param('i', $booking_id); $stmt_cx->execute();
        $current_cancellation = $stmt_cx->get_result()->fetch_assoc();
        if ($current_cancellation && $current_cancellation['status'] === 'Processed') throw new Exception('This booking has already been refunded.');
        if ($current_cancellation) {
            $stmt_cx = $conn->prepare("UPDATE cancellations SET reason = ?, refund_amount = ?, fee_deducted = ?, fee_percent = 0, status = 'Processed', admin_reply = 'Admin Initiated (Force Majeure)', refund_transaction_id = NULL WHERE id = ?");
            $stmt_cx->bind_param('sddi', $reason, $refund_amount, $fee, $current_cancellation['id']); $stmt_cx->execute();
            $cancellation_id = (int)$current_cancellation['id'];
        } else {
            $stmt_cx = $conn->prepare("INSERT INTO cancellations (booking_id, reason, refund_amount, fee_deducted, fee_percent, status, admin_reply) VALUES (?, ?, ?, ?, 0, 'Processed', 'Admin Initiated (Force Majeure)')");
            $stmt_cx->bind_param("isdd", $booking_id, $reason, $refund_amount, $fee); $stmt_cx->execute();
            $cancellation_id = (int)$conn->insert_id;
        }
        record_cancellation_history($conn, $booking_id, $cancellation_id, 'processed', $reason, $refund_amount, $fee, 0.0, 'Admin Initiated (Force Majeure)', (int)$_SESSION['user_id']);

        $stmt = $conn->prepare("UPDATE bookings SET booking_status = 'Cancelled', payment_status = 'Refunded' WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();

        // Also clean up any pending reschedule request for this booking
        $stmt_resched = $conn->prepare("UPDATE reschedule_requests SET status = 'Rejected', admin_reply = 'Booking Cancelled' WHERE booking_id = ? AND status = 'Pending'");
        $stmt_resched->bind_param("i", $booking_id);
        $stmt_resched->execute();

        $message = "Booking #$ref_no forcefully cancelled. 100% refund recorded.";

        // Keep customer-facing refund/cancellation delivery after commit.
        $postCommitActions[] = [
            'kind' => 'force_cancelled',
            'email' => $c_email,
            'name' => $c_name,
            'booking_id' => $booking_id,
            'refund_amount' => $refund_amount,
            'reason' => $reason,
            'user_id' => $c_user_id,
            'venue_name' => $v_name
        ];
    }
    else {
        throw new Exception('Invalid action provided.');
    }

    // ==========================================
    // YOUR ORIGINAL AUDIT LOG
    // ==========================================
    if (isset($_SESSION['user_id'])) {
        $log_user = $_SESSION['user_id'];
        $log_module = 'Booking Management';
        $log_action = $message;
        $log_ip = request_client_ip();

        $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, ?, ?, ?)");
        $audit_stmt->bind_param("isss", $log_user, $log_module, $log_action, $log_ip);
        $audit_stmt->execute();
    }

    $realtime_payload = [
        'booking_id' => $booking_id,
        'reference_no' => (string)$ref_no,
        'action' => (string)$action,
    ];
    realtime_enqueue_event($conn, 'admin', 'booking.updated', $realtime_payload);
    if ((int)$c_user_id > 0) {
        realtime_enqueue_event($conn, 'customer:' . (int)$c_user_id, 'booking.updated', $realtime_payload);
    }

    if (!$conn->commit()) throw new Exception('Unable to commit the booking update.');

    // Nothing in this block can roll back the committed state.  Delivery or
    // notification failures are logged with safe context and reported only as
    // nonfatal post-commit warnings.
    foreach ($postCommitActions as $postCommitAction) {
        if ($postCommitAction['kind'] === 'refund_processed') {
            try {
                send_booking_cancellation_email(
                    $postCommitAction['email'],
                    $postCommitAction['name'],
                    $postCommitAction['booking_id'],
                    'refund',
                    $postCommitAction['refund_amount']
                );
            } catch (Throwable $mailError) {
                error_log('Refund processed email delivery failed: ' . get_class($mailError) . ' booking_id=' . (int)$booking_id);
            }
            try {
                create_user_notification(
                    $conn,
                    $postCommitAction['user_id'],
                    'Refund Processed',
                    'Your refund for ' . $postCommitAction['venue_name'] . ' has been processed. Your booking is cancelled.'
                );
            } catch (Throwable $notificationError) {
                error_log('Refund processed notification failed: ' . get_class($notificationError) . ' booking_id=' . (int)$booking_id);
            }
        } elseif ($postCommitAction['kind'] === 'refund_rejected') {
            try {
                send_refund_rejected_email(
                    $postCommitAction['email'],
                    $postCommitAction['name'],
                    $postCommitAction['booking_id'],
                    $postCommitAction['reason']
                );
            } catch (Throwable $mailError) {
                error_log('Refund rejection email delivery failed: ' . get_class($mailError) . ' booking_id=' . (int)$booking_id);
            }
            try {
                create_user_notification(
                    $conn,
                    $postCommitAction['user_id'],
                    'Refund Request Rejected',
                    'Your refund request for ' . $postCommitAction['venue_name'] . ' was rejected. You may submit a new request from your dashboard.'
                );
            } catch (Throwable $notificationError) {
                error_log('Refund rejection notification failed: ' . get_class($notificationError) . ' booking_id=' . (int)$booking_id);
            }
        } elseif ($postCommitAction['kind'] === 'reschedule_approved') {
            try {
                send_reschedule_approved_email($postCommitAction['email'], $postCommitAction['name'], $postCommitAction['booking_id']);
            } catch (Throwable $mailError) {
                error_log('Reschedule approval email delivery failed: ' . get_class($mailError) . ' booking_id=' . (int)$booking_id);
            }
            try {
                create_user_notification($conn, $postCommitAction['user_id'], 'Reschedule Approved', 'Your request to reschedule ' . $postCommitAction['venue_name'] . ' to ' . $postCommitAction['new_start'] . ' has been approved.');
            } catch (Throwable $notificationError) {
                error_log('Reschedule approval notification failed: ' . get_class($notificationError) . ' booking_id=' . (int)$booking_id);
            }
        } elseif ($postCommitAction['kind'] === 'reschedule_rejected') {
            try {
                send_reschedule_rejected_email($postCommitAction['email'], $postCommitAction['name'], $postCommitAction['booking_id'], $postCommitAction['reason']);
            } catch (Throwable $mailError) {
                error_log('Reschedule rejection email delivery failed: ' . get_class($mailError) . ' booking_id=' . (int)$booking_id);
            }
            try {
                create_user_notification($conn, $postCommitAction['user_id'], 'Reschedule Rejected', 'Your request to reschedule ' . $postCommitAction['venue_name'] . ' was declined. Your original dates remain secured.');
            } catch (Throwable $notificationError) {
                error_log('Reschedule rejection notification failed: ' . get_class($notificationError) . ' booking_id=' . (int)$booking_id);
            }
        } elseif ($postCommitAction['kind'] === 'force_cancelled') {
            try {
                send_booking_cancellation_email($postCommitAction['email'], $postCommitAction['name'], $postCommitAction['booking_id'], 'cancelled', $postCommitAction['refund_amount'], $postCommitAction['reason']);
            } catch (Throwable $mailError) {
                error_log('Force cancellation email delivery failed: ' . get_class($mailError) . ' booking_id=' . (int)$booking_id);
            }
            try {
                create_user_notification($conn, $postCommitAction['user_id'], 'Booking Cancelled', 'Your booking for ' . $postCommitAction['venue_name'] . ' has been cancelled by the admin.');
            } catch (Throwable $notificationError) {
                error_log('Force cancellation notification failed: ' . get_class($notificationError) . ' booking_id=' . (int)$booking_id);
            }
        }
    }

    echo json_encode(['success' => true, 'message' => $message]);

} catch (Exception $e) {
    $conn->rollback();
    error_log('Admin booking action failed: ' . get_class($e));
    $safeMessage = $e->getMessage();
    if (str_contains(strtolower($safeMessage), 'sql') || str_contains(strtolower($safeMessage), 'query') || str_contains(strtolower($safeMessage), 'column')) $safeMessage = 'Unable to update the booking right now.';
    echo json_encode(['success' => false, 'message' => $safeMessage]);
}

$conn->close();
?>
