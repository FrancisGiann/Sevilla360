<?php
session_start();
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

try {
    $conn->begin_transaction();

    // ==========================================
    // FETCH CUSTOMER DATA FOR EMAILS
    // ==========================================
    $stmt_info = $conn->prepare("
        SELECT b.reference_no, b.venue_id, b.start_date, b.end_date, b.booking_status, v.category, c.email, c.first_name, c.last_name, v.name as venue_name, c.user_id
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

    // ==========================================
    // ACTIONS
    // ==========================================
    if ($action === 'confirm') {
        $was_already_confirmed = ($b_info['booking_status'] === 'Confirmed');
        $stmt_conflict = $conn->prepare("SELECT id FROM bookings WHERE venue_id = ? AND id != ? AND booking_status IN ('Confirmed', 'Completed') AND source <> 'Maintenance' AND start_date < ? AND end_date > ? LIMIT 1");
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
        $guests = intval($data['guests']);
        $event_type = trim($data['event_type']);
        $base_rate = floatval($data['base_rate']);
        $scheme = $data['payment_scheme'] ?? '100% Full'; // <--- GRABS IT FROM JS
        $line_items = $data['line_items'] ?? [];

        // 1. Calculate new math
        $addons_amount = 0;
        foreach ($line_items as $item) {
            $addons_amount += floatval($item['amount']);
        }
        $new_total = $base_rate + $addons_amount;

        // 2. Update Main Bookings Table (SAVES THE SCHEME!)
        $stmt_b = $conn->prepare("UPDATE bookings SET guests_count = ?, base_amount = ?, addons_amount = ?, total_amount = ?, payment_scheme = ?, booking_status = 'Confirmed' WHERE id = ?");
        $stmt_b->bind_param("idddsi", $guests, $base_rate, $addons_amount, $new_total, $scheme, $booking_id);
        $stmt_b->execute();

        // 3. Update Event Details (including internal admin notes)
        $admin_notes = isset($data['admin_notes']) ? trim($data['admin_notes']) : null;
        $stmt_e = $conn->prepare("UPDATE booking_event_details SET event_type = ?, admin_notes = ? WHERE booking_id = ?");
        $stmt_e->bind_param("ssi", $event_type, $admin_notes, $booking_id);
        $stmt_e->execute();

        // 4. Wipe old initial addons & rewrite new line items
        $conn->query("DELETE FROM booking_addons WHERE booking_id = $booking_id");
        $conn->query("DELETE FROM booking_line_items WHERE booking_id = $booking_id");

        if (!empty($line_items)) {
            $stmt_li = $conn->prepare("INSERT INTO booking_line_items (booking_id, item_name, amount) VALUES (?, ?, ?)");
            foreach ($line_items as $item) {
                $name = trim($item['name']);
                $amt = floatval($item['amount']);
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
            error_log("Failed to send invoice email: " . $mail_e->getMessage());
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
        } catch (Exception $mail_e) {
            // Silently fail so the admin doesn't get an error popup if Gmail is slow
            error_log("Failed to send admin payment receipt: " . $mail_e->getMessage());
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
            ");
            $stmt_inv->bind_param("ss", $r_type, $r_name);
            $stmt_inv->execute();
            $res_inv = $stmt_inv->get_result();

            $assigned_venue_id = null;
            while ($row = $res_inv->fetch_assoc()) {
                $vid = $row['id'];
                
                // Check Maintenance
                $maint = $conn->query("SELECT id FROM maintenance WHERE venue_id = $vid AND is_blocking = 1 AND status = 'Scheduled' AND (start_date <= '$new_end' AND end_date >= '$new_start')");
                if ($maint->num_rows > 0) continue;
                
                // Check Bookings (Exclude this specific booking)
                $bk = $conn->query("SELECT id FROM bookings WHERE venue_id = $vid AND booking_status IN ('Pending', 'Confirmed') AND source <> 'Maintenance' AND id != $booking_id AND (start_date < '$new_end' AND end_date > '$new_start')");
                if ($bk->num_rows > 0) continue;

                // Check Add-on Bookings
                $addons = $conn->query("
                    SELECT br.id FROM booking_rooms br
                    JOIN bookings b ON br.booking_id = b.id
                    WHERE br.venue_id = $vid AND b.booking_status IN ('Pending', 'Confirmed') AND b.source <> 'Maintenance' AND b.id != $booking_id
                    AND (br.start_date < '$new_end' AND br.end_date > '$new_start')
                ");
                if ($addons->num_rows > 0) continue;

                $assigned_venue_id = $vid;
                break;
            }

            if (!$assigned_venue_id) {
                throw new Exception("Collision Error: All rooms of this type are booked on the requested dates.");
            }
            $venue_id = $assigned_venue_id;

        } else {
            // For Event Halls / Villas, just check the specific unit
            $check_overlap = $conn->prepare("
                SELECT id FROM bookings 
                WHERE venue_id = ? AND booking_status IN ('Pending', 'Confirmed') AND source <> 'Maintenance' AND id != ? AND (start_date < ? AND end_date > ?)
            ");
            $check_overlap->bind_param("iiss", $venue_id, $booking_id, $new_end, $new_start);
            $check_overlap->execute();
            
            if ($check_overlap->get_result()->num_rows > 0) {
                throw new Exception("Collision Error: Those dates were just taken by another customer. Cannot reschedule.");
            }
        }

        // 2. Validate and re-allocate any Room Add-ons
        $stmt_addons = $conn->prepare("
            SELECT br.id, br.venue_id, v.name as building_name, h.room_type 
            FROM booking_rooms br
            JOIN venues v ON br.venue_id = v.id
            JOIN hotel_rooms h ON v.id = h.venue_id
            WHERE br.booking_id = ?
        ");
        $stmt_addons->bind_param("i", $booking_id);
        $stmt_addons->execute();
        $res_addons = $stmt_addons->get_result();

        $new_allocations = []; // [ br_id => new_venue_id ]

        while ($addon = $res_addons->fetch_assoc()) {
            $br_id = $addon['id'];
            $br_vid = $addon['venue_id'];
            $r_type = $addon['room_type'];
            $r_name = $addon['building_name'];

            // Find ANY available unit in this building and room_type for the new dates
            $stmt_inv = $conn->prepare("
                SELECT v.id FROM venues v JOIN hotel_rooms h ON v.id = h.venue_id 
                WHERE h.room_type = ? AND v.name = ? AND v.status = 'Available'
            ");
            $stmt_inv->bind_param("ss", $r_type, $r_name);
            $stmt_inv->execute();
            $res_inv = $stmt_inv->get_result();

            $assigned_venue_id = null;
            while ($row = $res_inv->fetch_assoc()) {
                $vid = $row['id'];
                
                // If we already assigned this $vid in this loop or for the main venue, skip it
                if (in_array($vid, $new_allocations) || $vid === $venue_id) continue;

                // Check Maintenance
                $maint = $conn->query("SELECT id FROM maintenance WHERE venue_id = $vid AND is_blocking = 1 AND status = 'Scheduled' AND (start_date <= '$new_end' AND end_date >= '$new_start')");
                if ($maint->num_rows > 0) continue;
                
                // Check Bookings
                $bk = $conn->query("SELECT id FROM bookings WHERE venue_id = $vid AND booking_status IN ('Pending', 'Confirmed') AND source <> 'Maintenance' AND id != $booking_id AND (start_date < '$new_end' AND end_date > '$new_start')");
                if ($bk->num_rows > 0) continue;

                // Check Add-on Bookings
                $addons_check = $conn->query("
                    SELECT br.id FROM booking_rooms br
                    JOIN bookings b ON br.booking_id = b.id
                    WHERE br.venue_id = $vid AND b.booking_status IN ('Pending', 'Confirmed') AND b.source <> 'Maintenance' AND b.id != $booking_id
                    AND (br.start_date < '$new_end' AND br.end_date > '$new_start')
                ");
                if ($addons_check->num_rows > 0) continue;

                $assigned_venue_id = $vid;
                break;
            }

            if (!$assigned_venue_id) {
                throw new Exception("Collision Error: Not enough available '$r_name - $r_type' units for the new dates. Cannot reschedule.");
            }
            $new_allocations[$br_id] = $assigned_venue_id;
        }

        // Apply new allocations for add-ons
        foreach ($new_allocations as $br_id => $new_vid) {
            $new_nights = $new_start_dt->diff($new_end_dt)->days;
            if ($new_nights === 0) $new_nights = 1;
            $stmt_upd_br = $conn->prepare("UPDATE booking_rooms SET venue_id = ?, start_date = ?, end_date = ?, nights = ?, line_total = nightly_rate * ? WHERE id = ?");
            $stmt_upd_br->bind_param("issiii", $new_vid, $new_start, $new_end, $new_nights, $new_nights, $br_id);
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
        
        // EMAIL NOTIFICATION
        try { send_reschedule_approved_email($c_email, $c_name, $booking_id); } catch (Exception $e) {}
        
        create_user_notification($conn, $c_user_id, "Reschedule Approved", "Your request to reschedule $v_name to $new_start has been approved.");
    }
    elseif ($action === 'reject_reschedule') {
        $admin_reply = isset($data['admin_reply']) ? trim($data['admin_reply']) : "No reason provided.";

        $stmt_req = $conn->prepare("UPDATE reschedule_requests SET status = 'Rejected', admin_reply = ? WHERE booking_id = ? AND status = 'Pending'");
        $stmt_req->bind_param("si", $admin_reply, $booking_id);
        $stmt_req->execute();
        
        $message = "Reschedule request for Booking #$ref_no rejected.";

        // EMAIL NOTIFICATION
        $html = "<div style='font-family:Arial; padding:20px;'><h2 style='color:#d6a870;'>Reschedule Update</h2><p>Hello $c_name,</p><p>Regarding your request to reschedule your booking at <strong>$v_name</strong>, the administration left the following note:</p><p style='padding:10px; background:#f4f4f4; border-left:3px solid #d6a870;'><em>$admin_reply</em></p><p>Your original dates remain secured.</p></div>";
        try { send_custom_email($c_email, $c_name, "Reschedule Update - $ref_no", $html); } catch (Exception $e) {}
        
        create_user_notification($conn, $c_user_id, "Reschedule Rejected", "Your request to reschedule $v_name was declined. Please check your email for details.");
    }
    elseif ($action === 'refund') {
        $stmt_refund = $conn->prepare("SELECT refund_amount FROM cancellations WHERE booking_id = ? LIMIT 1");
        $stmt_refund->bind_param("i", $booking_id);
        $stmt_refund->execute();
        $refund_row = $stmt_refund->get_result()->fetch_assoc();
        $refund_amount = $refund_row ? floatval($refund_row['refund_amount']) : null;

        $refund_tx_id = isset($data['refund_transaction_id']) ? trim($data['refund_transaction_id']) : null;

        $stmt = $conn->prepare("UPDATE bookings SET booking_status = 'Cancelled', payment_status = 'Refunded' WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        
        $stmt_cancel = $conn->prepare("UPDATE cancellations SET status = 'Processed', refund_transaction_id = ? WHERE booking_id = ?");
        $stmt_cancel->bind_param("si", $refund_tx_id, $booking_id);
        $stmt_cancel->execute();

        // Also clean up any pending reschedule request for this booking
        $stmt_resched = $conn->prepare("UPDATE reschedule_requests SET status = 'Rejected', admin_reply = 'Booking Cancelled' WHERE booking_id = ? AND status = 'Pending'");
        $stmt_resched->bind_param("i", $booking_id);
        $stmt_resched->execute();
        
        $message = "Refund processed and Booking #$ref_no cancelled.";

        // EMAIL NOTIFICATION
        try { send_booking_cancellation_email($c_email, $c_name, $booking_id, 'refund', $refund_amount); } catch (Exception $e) {}
        
        create_user_notification($conn, $c_user_id, "Refund Processed", "Your refund for $v_name has been processed. Your booking is cancelled.");
    } 
    elseif ($action === 'admin_force_cancel') {
        if (!isset($data['reason'])) throw new Exception("Reason is required.");
        
        $reason = trim($data['reason']);
        $refund_amount = floatval($data['refund_amount']);
        $fee = 0.00; // Resort shoulders the fee

        $stmt_cx = $conn->prepare("INSERT INTO cancellations (booking_id, reason, refund_amount, fee_deducted, status, admin_reply) VALUES (?, ?, ?, ?, 'Processed', 'Admin Initiated (Force Majeure)')");
        $stmt_cx->bind_param("isdd", $booking_id, $reason, $refund_amount, $fee);
        $stmt_cx->execute();

        $stmt = $conn->prepare("UPDATE bookings SET booking_status = 'Cancelled', payment_status = 'Refunded' WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();

        // Also clean up any pending reschedule request for this booking
        $stmt_resched = $conn->prepare("UPDATE reschedule_requests SET status = 'Rejected', admin_reply = 'Booking Cancelled' WHERE booking_id = ? AND status = 'Pending'");
        $stmt_resched->bind_param("i", $booking_id);
        $stmt_resched->execute();
        
        $message = "Booking #$ref_no forcefully cancelled. 100% refund recorded.";

        // EMAIL NOTIFICATION
        try { send_booking_cancellation_email($c_email, $c_name, $booking_id, 'cancelled', $refund_amount, $reason); } catch (Exception $e) {}
        
        create_user_notification($conn, $c_user_id, "Booking Cancelled", "Your booking for $v_name has been cancelled by the admin. Check your email for details.");
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
        $log_ip = $_SERVER['REMOTE_ADDR'];

        $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, ?, ?, ?)");
        $audit_stmt->bind_param("isss", $log_user, $log_module, $log_action, $log_ip);
        $audit_stmt->execute();
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => $message]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>
