<?php
session_start();
header('Content-Type: application/json');

// 1. Auth Guard: Ensure only admins can execute this
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superadmin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

require_once '../../config/db_connect.php'; 

// ==========================================
// NEW: INCLUDE MAILER FOR NOTIFICATIONS
// ==========================================
require_once '../../includes/mailer.php'; 

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
        SELECT b.reference_no, c.email, c.first_name, c.last_name, v.name as venue_name 
        FROM bookings b 
        JOIN customers c ON b.customer_id = c.id 
        JOIN venues v ON b.venue_id = v.id 
        WHERE b.id = ?
    ");
    $stmt_info->bind_param("i", $booking_id);
    $stmt_info->execute();
    $b_info = $stmt_info->get_result()->fetch_assoc();
    
    $c_email = $b_info['email'] ?? '';
    $c_name = ($b_info['first_name'] ?? '') . ' ' . ($b_info['last_name'] ?? '');
    $ref_no = $b_info['reference_no'] ?? '';
    $v_name = $b_info['venue_name'] ?? '';

    // ==========================================
    // ACTIONS
    // ==========================================
    if ($action === 'confirm') {
        $stmt = $conn->prepare("UPDATE bookings SET booking_status = 'Confirmed' WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $message = "Booking #$booking_id has been confirmed!";
        
    } 
    elseif ($action === 'cancel') {
        $stmt = $conn->prepare("UPDATE bookings SET booking_status = 'Cancelled' WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $message = "Booking #$booking_id has been cancelled!";
        
    } 
    elseif ($action === 'update_price') {
        if (!isset($data['new_total'])) {
            throw new Exception("New total amount is required.");
        }
        $new_total = floatval($data['new_total']);
        
        $stmt = $conn->prepare("UPDATE bookings SET total_amount = ? WHERE id = ?");
        $stmt->bind_param("di", $new_total, $booking_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update price in the database.");
        }
        $message = "Invoice price successfully updated to ₱" . number_format($new_total, 2) . "!";
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
        $new_payment_status = ($new_amount_paid >= $total) ? 'Paid' : 'Partial';

        $stmt_pay = $conn->prepare("INSERT INTO payments (booking_id, transaction_id, payment_method, amount, status) VALUES (?, ?, ?, ?, 'Success')");
        $stmt_pay->bind_param("issd", $booking_id, $trans_id, $method, $amount_to_add);
        $stmt_pay->execute();

        $stmt_update = $conn->prepare("UPDATE bookings SET payment_status = ?, amount_paid = ?, booking_status = 'Confirmed' WHERE id = ?");
        $stmt_update->bind_param("sdi", $new_payment_status, $new_amount_paid, $booking_id);
        $stmt_update->execute();
        
        $message = "Payment of ₱" . number_format($amount_to_add, 2) . " received successfully!";
        
        // =========================================================
        // NEW FIX: SEND EMAIL RECEIPT FOR ADMIN MANUAL PAYMENTS
        // =========================================================
        try {
            $email_status = ($new_payment_status === 'Paid') ? 'Fully Paid' : 'Partially Paid (Manual Payment)';
            send_booking_receipt($c_email, $c_name, $ref_no, $v_name, $new_amount_paid, $email_status);
        } catch (Exception $mail_e) {
            // Silently fail so the admin doesn't get an error popup if Gmail is slow
            error_log("Failed to send admin payment receipt: " . $mail_e->getMessage());
        }
        // =========================================================
    }
    elseif ($action === 'reschedule') {
        if (!isset($data['new_start_date']) || !isset($data['new_end_date'])) {
            throw new Exception("Missing new dates for reschedule.");
        }
        $new_start = $data['new_start_date'];
        $new_end = $data['new_end_date'];
        
        $stmt_venue = $conn->prepare("SELECT venue_id FROM bookings WHERE id = ?");
        $stmt_venue->bind_param("i", $booking_id);
        $stmt_venue->execute();
        $venue_id = $stmt_venue->get_result()->fetch_assoc()['venue_id'];

        $check_overlap = $conn->prepare("
            SELECT id FROM bookings 
            WHERE venue_id = ? AND booking_status IN ('Pending', 'Confirmed') AND id != ? AND (start_date < ? AND end_date > ?)
        ");
        $check_overlap->bind_param("iiss", $venue_id, $booking_id, $new_end, $new_start);
        $check_overlap->execute();
        
        if ($check_overlap->get_result()->num_rows > 0) {
            throw new Exception("Collision Error: Those dates were just taken by another customer. Cannot reschedule.");
        }

        $stmt = $conn->prepare("UPDATE bookings SET start_date = ?, end_date = ? WHERE id = ?");
        $stmt->bind_param("ssi", $new_start, $new_end, $booking_id);
        $stmt->execute();

        $stmt_req = $conn->prepare("UPDATE reschedule_requests SET status = 'Approved' WHERE booking_id = ? AND status = 'Pending'");
        $stmt_req->bind_param("i", $booking_id);
        $stmt_req->execute();
        
        $message = "Booking #$booking_id successfully rescheduled to $new_start!";
        
        // EMAIL NOTIFICATION
        $html = "<div style='font-family:Arial; padding:20px;'><h2 style='color:#d6a870;'>Reschedule Approved</h2><p>Hello $c_name,</p><p>Good news! Your request to reschedule your booking at <strong>$v_name</strong> to <strong>$new_start</strong> has been approved.</p><p>You can view your updated itinerary on your dashboard.</p></div>";
        try { send_custom_email($c_email, $c_name, "Reschedule Approved - $ref_no", $html); } catch (Exception $e) {}
    }
    elseif ($action === 'reject_reschedule') {
        $admin_reply = isset($data['admin_reply']) ? trim($data['admin_reply']) : "No reason provided.";

        $stmt_req = $conn->prepare("UPDATE reschedule_requests SET status = 'Rejected', admin_reply = ? WHERE booking_id = ? AND status = 'Pending'");
        $stmt_req->bind_param("si", $admin_reply, $booking_id);
        $stmt_req->execute();
        
        $message = "Reschedule request rejected successfully.";

        // EMAIL NOTIFICATION
        $html = "<div style='font-family:Arial; padding:20px;'><h2 style='color:#d6a870;'>Reschedule Update</h2><p>Hello $c_name,</p><p>Regarding your request to reschedule your booking at <strong>$v_name</strong>, the administration left the following note:</p><p style='padding:10px; background:#f4f4f4; border-left:3px solid #d6a870;'><em>$admin_reply</em></p><p>Your original dates remain secured.</p></div>";
        try { send_custom_email($c_email, $c_name, "Reschedule Update - $ref_no", $html); } catch (Exception $e) {}
    }
    elseif ($action === 'refund') {
        $stmt = $conn->prepare("UPDATE bookings SET booking_status = 'Cancelled', payment_status = 'Refunded' WHERE id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        
        $stmt_cx = $conn->prepare("UPDATE cancellations SET status = 'Processed' WHERE booking_id = ?");
        $stmt_cx->bind_param("i", $booking_id);
        $stmt_cx->execute();
        
        $message = "Refund processed and booking cancelled!";

        // EMAIL NOTIFICATION
        $html = "<div style='font-family:Arial; padding:20px;'><h2 style='color:#e06666;'>Refund Processed</h2><p>Hello $c_name,</p><p>Your cancellation request for <strong>$v_name</strong> has been approved.</p><p>Your refund has been processed. Please allow 5-10 business days for the funds to reflect in your account.</p></div>";
        try { send_custom_email($c_email, $c_name, "Refund Processed - $ref_no", $html); } catch (Exception $e) {}
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
        
        $message = "Booking #$booking_id forcefully cancelled. 100% refund recorded.";

        // EMAIL NOTIFICATION
        $html = "<div style='font-family:Arial; padding:20px;'><h2 style='color:#e06666;'>Booking Cancelled</h2><p>Hello $c_name,</p><p>Unfortunately, your booking for <strong>$v_name</strong> has been cancelled by the administration for the following reason:</p><p style='padding:10px; background:#f4f4f4; border-left:3px solid #e06666;'><em>$reason</em></p><p>A 100% full refund of ₱".number_format($refund_amount, 2)." has been issued to your original payment method.</p></div>";
        try { send_custom_email($c_email, $c_name, "Booking Cancelled - $ref_no", $html); } catch (Exception $e) {}
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