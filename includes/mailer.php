<?php
// includes/mailer.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php'; // Load env variables

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function get_biz_info() {
    global $conn;
    $info = [
        'biz_name' => 'Sevilla360',
        'biz_tagline' => 'LUXURY RESORT & EVENTS',
        'biz_policies' => '',
        'biz_email' => 'reservations@sevilla360.com',
        'biz_phone' => '+63 912 345 6789',
        'biz_address' => '123 Resort Drive, Paradise City'
    ];
    if ($conn && $conn instanceof mysqli) {
        $res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'biz_%'");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $info[$row['setting_key']] = $row['setting_value'];
            }
        }
    }
    return $info;
}

function send_booking_receipt($customer_email, $customer_name, $ref_no, $venue_name, $amount_paid, $status) {
    global $conn;
    $smtp_email = $_ENV['SMTP_EMAIL']; 
    $smtp_password = $_ENV['SMTP_PASSWORD']; 
    $biz = get_biz_info();

    // 1. Fetch Booking Details
    $stmt = $conn->prepare("
        SELECT b.id, b.start_date, b.end_date, b.guests_count, b.base_amount, b.total_amount, b.amount_paid, v.category 
        FROM bookings b JOIN venues v ON b.venue_id = v.id 
        WHERE b.reference_no = ?
    ");
    $stmt->bind_param("s", $ref_no);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();

    // 2. Fetch Itemized Line Items!
    $stmt_li = $conn->prepare("SELECT item_name, amount FROM booking_line_items WHERE booking_id = ?");
    $stmt_li->bind_param("i", $booking['id']);
    $stmt_li->execute();
    $line_items = $stmt_li->get_result()->fetch_all(MYSQLI_ASSOC);

    // 3. CHECK-IN / CHECK-OUT LOGIC
    $check_in_time = '2:00 PM';
    $check_out_time = '12:00 PM';

    if ($booking['category'] === 'Resort Villa') {
        $stmt_villa = $conn->prepare("SELECT stay_type FROM booking_villa_details WHERE booking_id = ?");
        $stmt_villa->bind_param("i", $booking['id']);
        $stmt_villa->execute();
        $villa_details = $stmt_villa->get_result()->fetch_assoc();
        
        if ($villa_details && $villa_details['stay_type'] === 'Day Time Stay') {
            $check_in_time = '7:00 AM'; $check_out_time = '5:00 PM';
        }
    } elseif ($booking['category'] === 'Event Hall') {
        $check_in_time = 'Per Event Schedule'; $check_out_time = 'Per Event Schedule';
    }

    $check_in = date('F j, Y', strtotime($booking['start_date'])) . ' <br><span style="color:#d6a870; font-size:12px;">' . $check_in_time . '</span>';
    $check_out = date('F j, Y', strtotime($booking['end_date'])) . ' <br><span style="color:#d6a870; font-size:12px;">' . $check_out_time . '</span>';
    
    $guests = $booking['guests_count'];
    $total_amt = floatval($booking['total_amount']);
    $total_paid = floatval($booking['amount_paid']);
    $balance = $total_amt - $total_paid;

    $subject = "{$biz['biz_name']}: Your Booking Itinerary [$ref_no]";

    // Build the Itemized Breakdown HTML (Always show items, but hide total for inquiries)
    $breakdown_html = "";
    $is_inquiry = (strpos($status, 'Inquiry') !== false);

    if (count($line_items) > 0 || $booking['category'] === 'Event Hall') {
        $breakdown_html .= "<tr><td colspan='2' style='padding: 15px 12px 5px; border-top: 1px dashed #ccc; color: #2a2522;'><strong>Itemized Estimate:</strong></td></tr>";
        $breakdown_html .= "<tr><td style='padding: 5px 12px; color: #666; font-size: 14px;'>Venue Base Rate</td><td style='padding: 5px 12px; text-align: right; color: #666; font-size: 14px;'>₱" . number_format($booking['base_amount'], 2) . "</td></tr>";
        
        foreach ($line_items as $item) {
            $breakdown_html .= "<tr><td style='padding: 5px 12px; color: #666; font-size: 14px;'>" . htmlspecialchars($item['item_name']) . "</td><td style='padding: 5px 12px; text-align: right; color: #666; font-size: 14px;'>₱" . number_format($item['amount'], 2) . "</td></tr>";
        }
    }

    // Fetch Payment Records & Transaction IDs
    $stmt_pay = $conn->prepare("SELECT transaction_id, payment_method, amount, payment_date FROM payments WHERE booking_id = ? AND status = 'Success' ORDER BY payment_date ASC");
    $stmt_pay->bind_param("i", $booking['id']);
    $stmt_pay->execute();
    $payments_arr = $stmt_pay->get_result()->fetch_all(MYSQLI_ASSOC);

    $pay_details_html = "";
    if (!empty($payments_arr)) {
        foreach ($payments_arr as $p) {
            $pay_date = date('M j, Y', strtotime($p['payment_date']));
            $tx_id = htmlspecialchars($p['transaction_id']);
            $p_method = htmlspecialchars($p['payment_method']);
            $p_amt = number_format($p['amount'], 2);
            $pay_details_html .= "<tr><td style='padding: 8px 12px; border-bottom: 1px solid #eee; font-size: 13px;'>Payment ($p_method)<br><span style='font-size: 11px; color: #888;'>TXN: $tx_id ($pay_date)</span></td><td style='padding: 8px 12px; border-bottom: 1px solid #eee; text-align: right; color: #4ade80;'>- ₱$p_amt</td></tr>";
        }
    } else {
        $pay_details_html = "<tr><td style='padding: 12px; border-bottom: 1px solid #eee;'><strong>Amount Paid:</strong></td><td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right; color: #4ade80;'>₱" . number_format($total_paid, 2) . "</td></tr>";
    }

    // Hide all money math if it is just a pending inquiry
    if ($is_inquiry) {
        $money_block = $breakdown_html . "
            <tr><td style='padding: 15px 12px; border-top: 2px solid #2a2522;'><strong>Estimated Total:</strong></td><td style='padding: 15px 12px; border-top: 2px solid #2a2522; text-align: right; font-size: 16px; color: #d6a870;'><strong>To Be Arranged</strong></td></tr>
            <tr><td colspan='2' style='padding: 5px 12px; text-align: center; color: #888; font-size: 12px; font-style: italic;'>*Our coordinator will consult with you to provide an exact quotation.</td></tr>
        ";
        $header_title = "EVENT INQUIRY RECEIVED";
        $header_desc = "We have received your event inquiry. Based on your selections, here is your preliminary estimate. Our coordinator will contact you shortly to finalize the details and exact pricing.";
    } else {
        $money_block = $breakdown_html . "
            <tr><td style='padding: 12px; border-top: 2px solid #2a2522;'><strong>Total Amount:</strong></td><td style='padding: 12px; border-top: 2px solid #2a2522; text-align: right;'>₱" . number_format($total_amt, 2) . "</td></tr>
            $pay_details_html
            <tr><td style='padding: 12px; border-bottom: 2px solid #2a2522; font-size: 18px;'><strong>BALANCE DUE:</strong></td><td style='padding: 12px; border-bottom: 2px solid #2a2522; text-align: right; font-size: 18px; color: #e06666;'><strong>₱" . number_format($balance, 2) . "</strong></td></tr>
        ";
        $header_title = "OFFICIAL BOOKING ITINERARY";
        $header_desc = "Thank you for choosing {$biz['biz_name']}. Please present this e-ticket at the front desk upon arrival.";
    }

    $biz_policies_html = nl2br(htmlspecialchars($biz['biz_policies']));

    $html_content = "
    <div style='background-color: #f4f4f4; padding: 40px 0; font-family: \"Helvetica Neue\", Helvetica, Arial, sans-serif;'>
        <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);'>
            <div style='background-color: #2a2522; padding: 30px; text-align: center;'>
                <h1 style='color: #d6a870; margin: 0; font-size: 28px; letter-spacing: 2px; text-transform: uppercase;'>" . htmlspecialchars($biz['biz_name']) . "</h1>
                <p style='color: #a3a3a3; margin: 5px 0 0 0; font-size: 12px; letter-spacing: 1px; text-transform: uppercase;'>" . htmlspecialchars($biz['biz_tagline']) . "</p>
            </div>
            <div style='padding: 40px;'>
                <h2 style='color: #2a2522; margin-top: 0; font-size: 20px;'>$header_title</h2>
                <p style='color: #555; font-size: 15px; line-height: 1.6;'>Hello <strong>$customer_name</strong>,<br>$header_desc</p>
                <div style='background: #faf9f7; border: 1px solid #e5e5e5; border-radius: 6px; padding: 25px; margin-top: 30px;'>
                    <table style='width: 100%; border-collapse: collapse; font-size: 15px; color: #2a2522;'>
                        <tr>
                            <td style='padding: 12px; border-bottom: 1px solid #eee; width: 40%;'><strong>Reference No:</strong></td>
                            <td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right; font-family: monospace; font-size: 16px; color: #d6a870;'><strong>$ref_no</strong></td>
                        </tr>
                        <tr><td style='padding: 12px; border-bottom: 1px solid #eee;'><strong>Venue:</strong></td><td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right;'>$venue_name</td></tr>
                        <tr><td style='padding: 12px; border-bottom: 1px solid #eee;'><strong>Check-in:</strong></td><td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right;'>$check_in</td></tr>
                        <tr><td style='padding: 12px; border-bottom: 1px solid #eee;'><strong>Check-out:</strong></td><td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right;'>$check_out</td></tr>
                        <tr><td style='padding: 12px;'><strong>Guests:</strong></td><td style='padding: 12px; text-align: right;'>$guests Persons</td></tr>
                        $money_block
                        <tr><td style='padding: 12px; border-bottom: none;'><strong>Status:</strong></td><td style='padding: 12px; border-bottom: none; text-align: right;'><strong>$status</strong></td></tr>
                    </table>
                </div>
                <div style='margin-top: 40px; border-top: 1px solid #eee; padding-top: 20px; font-size: 13px; color: #888; line-height: 1.5;'>
                    <strong>Policies:</strong><br>
                    $biz_policies_html
                </div>
                <div style='margin-top: 20px; font-size: 12px; color: #aaa; text-align: center;'>
                    " . htmlspecialchars($biz['biz_address']) . " | " . htmlspecialchars($biz['biz_phone']) . " | " . htmlspecialchars($biz['biz_email']) . "
                </div>
            </div>
        </div>
    </div>
    ";

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->CharSet = 'UTF-8';
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_email;
        $mail->Password = $smtp_password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));
        $mail->setFrom($smtp_email, $biz['biz_name'] . ' Reservations');
        $mail->addAddress($customer_email, $customer_name); 
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html_content;
        $mail->send();
        return true;
    } catch (Exception $e) {
        throw new Exception("Mailer Error: {$mail->ErrorInfo}");
    }
}

// -------------------------------------------------------------
// STANDALONE FUNCTION (Fixed from being nested!)
// -------------------------------------------------------------
function send_custom_email($to_email, $to_name, $subject, $html_content) {
    $smtp_email = $_ENV['SMTP_EMAIL']; 
    $smtp_password = $_ENV['SMTP_PASSWORD']; 
    $biz = get_biz_info();
    
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->CharSet = 'UTF-8';
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp_email;
        $mail->Password   = $smtp_password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));

        $mail->setFrom($smtp_email, $biz['biz_name'] . ' Accounts');
        $mail->addAddress($to_email, $to_name); 
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_content;

        $mail->send();
        return true;
    } catch (Exception $e) { throw new Exception("Mailer Error: {$mail->ErrorInfo}"); }
}

/**
 * Sends the branded notification used when a booking is cancelled or refunded.
 * This deliberately mirrors the booking itinerary so customers can quickly
 * identify the affected reservation and its final payment status.
 */
function send_booking_cancellation_email($customer_email, $customer_name, $booking_id, $type, $refund_amount = null, $reason = '') {
    global $conn;

    $stmt = $conn->prepare("
        SELECT b.reference_no, b.start_date, b.end_date, b.guests_count, v.name AS venue_name
        FROM bookings b
        JOIN venues v ON b.venue_id = v.id
        WHERE b.id = ?
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();

    if (!$booking) {
        throw new Exception('Booking not found for cancellation email.');
    }

    $biz = get_biz_info();
    $is_refund = $type === 'refund';
    $subject_ref_no = $booking['reference_no'];
    $ref_no = htmlspecialchars($booking['reference_no'], ENT_QUOTES, 'UTF-8');
    $venue_name = htmlspecialchars($booking['venue_name'], ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars(trim($customer_name), ENT_QUOTES, 'UTF-8');
    $check_in = date('F j, Y', strtotime($booking['start_date']));
    $check_out = date('F j, Y', strtotime($booking['end_date']));
    $stay_dates = $check_in === $check_out ? $check_in : "$check_in – $check_out";
    $refund_text = $refund_amount !== null ? '₱' . number_format((float)$refund_amount, 2) : null;
    $reason_html = trim($reason) !== ''
        ? "<div style='margin-top:20px; padding:16px; background:#fff7f7; border-left:4px solid #c05a5a; color:#5d4545; font-size:14px; line-height:1.6;'><strong>Reason for cancellation</strong><br>" . nl2br(htmlspecialchars(trim($reason), ENT_QUOTES, 'UTF-8')) . "</div>"
        : '';

    if ($is_refund) {
        $title = 'REFUND PROCESSED';
        $message = 'Your cancellation request has been approved and your booking has been cancelled.';
        $payment_note = $refund_text
            ? "A refund of <strong style='color:#2f7d5d;'>$refund_text</strong> has been processed to your original payment method. Please allow 5–10 business days for it to appear in your account."
            : 'Your refund has been processed to your original payment method. Please allow 5–10 business days for it to appear in your account.';
        $status = 'Refunded & Cancelled';
        $status_color = '#2f7d5d';
    } else {
        $title = 'BOOKING CANCELLED';
        $message = 'We regret to inform you that your booking has been cancelled by the administration.';
        $payment_note = $refund_text
            ? "A full refund of <strong style='color:#2f7d5d;'>$refund_text</strong> has been issued to your original payment method. Please allow 5–10 business days for it to appear in your account."
            : 'If a refund applies to your reservation, it will be returned to your original payment method.';
        $status = 'Cancelled';
        $status_color = '#c05a5a';
    }

    $html_content = "
    <div style='background-color:#f4f4f4; padding:40px 0; font-family:Helvetica, Arial, sans-serif;'>
        <div style='max-width:600px; margin:0 auto; background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 4px 15px rgba(0,0,0,0.05);'>
            <div style='background-color:#2a2522; padding:30px; text-align:center;'>
                <h1 style='color:#d6a870; margin:0; font-size:28px; letter-spacing:2px; text-transform:uppercase;'>" . htmlspecialchars($biz['biz_name'], ENT_QUOTES, 'UTF-8') . "</h1>
                <p style='color:#a3a3a3; margin:5px 0 0; font-size:12px; letter-spacing:1px; text-transform:uppercase;'>" . htmlspecialchars($biz['biz_tagline'], ENT_QUOTES, 'UTF-8') . "</p>
            </div>
            <div style='padding:40px;'>
                <h2 style='color:#2a2522; margin:0; font-size:20px;'>$title</h2>
                <p style='color:#555; font-size:15px; line-height:1.6;'>Hello <strong>$name</strong>,<br>$message</p>
                <div style='background:#faf9f7; border:1px solid #e5e5e5; border-radius:6px; padding:25px; margin-top:30px;'>
                    <table style='width:100%; border-collapse:collapse; font-size:15px; color:#2a2522;'>
                        <tr><td style='padding:12px; border-bottom:1px solid #eee; width:42%;'><strong>Reference No:</strong></td><td style='padding:12px; border-bottom:1px solid #eee; text-align:right; font-family:monospace; font-size:16px; color:#d6a870;'><strong>$ref_no</strong></td></tr>
                        <tr><td style='padding:12px; border-bottom:1px solid #eee;'><strong>Venue:</strong></td><td style='padding:12px; border-bottom:1px solid #eee; text-align:right;'>$venue_name</td></tr>
                        <tr><td style='padding:12px; border-bottom:1px solid #eee;'><strong>Stay / Event Date:</strong></td><td style='padding:12px; border-bottom:1px solid #eee; text-align:right;'>$stay_dates</td></tr>
                        <tr><td style='padding:12px; border-bottom:1px solid #eee;'><strong>Guests:</strong></td><td style='padding:12px; border-bottom:1px solid #eee; text-align:right;'>" . (int)$booking['guests_count'] . " Persons</td></tr>
                        <tr><td style='padding:12px;'><strong>Status:</strong></td><td style='padding:12px; text-align:right; color:$status_color;'><strong>$status</strong></td></tr>
                    </table>
                </div>
                $reason_html
                <div style='margin-top:24px; padding:18px 20px; background:#f8f8f8; border-radius:6px; color:#555; font-size:14px; line-height:1.6;'>
                    <strong style='color:#2a2522;'>Payment update</strong><br>$payment_note
                </div>
                <div style='margin-top:32px; border-top:1px solid #eee; padding-top:20px; font-size:12px; color:#aaa; text-align:center; line-height:1.6;'>
                    " . htmlspecialchars($biz['biz_address'], ENT_QUOTES, 'UTF-8') . " | " . htmlspecialchars($biz['biz_phone'], ENT_QUOTES, 'UTF-8') . " | " . htmlspecialchars($biz['biz_email'], ENT_QUOTES, 'UTF-8') . "
                </div>
            </div>
        </div>
    </div>";

    $subject = $is_refund
        ? "{$biz['biz_name']}: Refund Processed [$subject_ref_no]"
        : "{$biz['biz_name']}: Booking Cancelled [$subject_ref_no]";

    return send_custom_email($customer_email, $customer_name, $subject, $html_content);
}

/** Sends the branded confirmation for an approved reschedule request. */
function send_reschedule_approved_email($customer_email, $customer_name, $booking_id) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT b.reference_no, b.start_date, b.end_date, b.guests_count, v.name AS venue_name
        FROM bookings b
        JOIN venues v ON b.venue_id = v.id
        WHERE b.id = ?
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();

    if (!$booking) {
        throw new Exception('Booking not found for reschedule email.');
    }

    $biz = get_biz_info();
    $ref_no = htmlspecialchars($booking['reference_no'], ENT_QUOTES, 'UTF-8');
    $venue_name = htmlspecialchars($booking['venue_name'], ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars(trim($customer_name), ENT_QUOTES, 'UTF-8');
    $check_in = date('F j, Y', strtotime($booking['start_date']));
    $check_out = date('F j, Y', strtotime($booking['end_date']));
    $stay_dates = $check_in === $check_out ? $check_in : "$check_in – $check_out";

    $html_content = "
    <div style='background-color:#f4f4f4; padding:40px 0; font-family:Helvetica, Arial, sans-serif;'>
        <div style='max-width:600px; margin:0 auto; background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 4px 15px rgba(0,0,0,0.05);'>
            <div style='background-color:#2a2522; padding:30px; text-align:center;'>
                <h1 style='color:#d6a870; margin:0; font-size:28px; letter-spacing:2px; text-transform:uppercase;'>" . htmlspecialchars($biz['biz_name'], ENT_QUOTES, 'UTF-8') . "</h1>
                <p style='color:#a3a3a3; margin:5px 0 0; font-size:12px; letter-spacing:1px; text-transform:uppercase;'>" . htmlspecialchars($biz['biz_tagline'], ENT_QUOTES, 'UTF-8') . "</p>
            </div>
            <div style='padding:40px;'>
                <h2 style='color:#2a2522; margin:0; font-size:20px;'>RESCHEDULE APPROVED</h2>
                <p style='color:#555; font-size:15px; line-height:1.6;'>Hello <strong>$name</strong>,<br>Good news—your request to reschedule your booking has been approved. Your reservation is now confirmed for the updated dates below.</p>
                <div style='margin-top:26px; padding:18px 20px; background:#fffaf1; border-left:4px solid #d6a870; border-radius:4px; color:#51483d; font-size:14px; line-height:1.6;'>
                    <strong style='color:#2a2522;'>Your updated stay / event date</strong><br><span style='font-size:17px; color:#a4783d;'><strong>$stay_dates</strong></span>
                </div>
                <div style='background:#faf9f7; border:1px solid #e5e5e5; border-radius:6px; padding:25px; margin-top:24px;'>
                    <table style='width:100%; border-collapse:collapse; font-size:15px; color:#2a2522;'>
                        <tr><td style='padding:12px; border-bottom:1px solid #eee; width:42%;'><strong>Reference No:</strong></td><td style='padding:12px; border-bottom:1px solid #eee; text-align:right; font-family:monospace; font-size:16px; color:#d6a870;'><strong>$ref_no</strong></td></tr>
                        <tr><td style='padding:12px; border-bottom:1px solid #eee;'><strong>Venue:</strong></td><td style='padding:12px; border-bottom:1px solid #eee; text-align:right;'>$venue_name</td></tr>
                        <tr><td style='padding:12px; border-bottom:1px solid #eee;'><strong>Updated Date:</strong></td><td style='padding:12px; border-bottom:1px solid #eee; text-align:right;'>$stay_dates</td></tr>
                        <tr><td style='padding:12px; border-bottom:1px solid #eee;'><strong>Guests:</strong></td><td style='padding:12px; border-bottom:1px solid #eee; text-align:right;'>" . (int)$booking['guests_count'] . " Persons</td></tr>
                        <tr><td style='padding:12px;'><strong>Status:</strong></td><td style='padding:12px; text-align:right; color:#2f7d5d;'><strong>Rescheduled & Confirmed</strong></td></tr>
                    </table>
                </div>
                <p style='margin:24px 0 0; color:#666; font-size:14px; line-height:1.6;'>Please keep this email for your records and present your updated itinerary at check-in.</p>
                <div style='margin-top:32px; border-top:1px solid #eee; padding-top:20px; font-size:12px; color:#aaa; text-align:center; line-height:1.6;'>
                    " . htmlspecialchars($biz['biz_address'], ENT_QUOTES, 'UTF-8') . " | " . htmlspecialchars($biz['biz_phone'], ENT_QUOTES, 'UTF-8') . " | " . htmlspecialchars($biz['biz_email'], ENT_QUOTES, 'UTF-8') . "
                </div>
            </div>
        </div>
    </div>";

    $subject = "{$biz['biz_name']}: Reschedule Approved [{$booking['reference_no']}]";
    return send_custom_email($customer_email, $customer_name, $subject, $html_content);
}

// -------------------------------------------------------------
// STANDALONE FUNCTION (Fixed from being nested!)
// -------------------------------------------------------------
function send_invoice_ready_email($customer_email, $customer_name, $ref_no, $total_amount, $dashboard_link) {
    global $conn;
    $smtp_email = $_ENV['SMTP_EMAIL']; 
    $smtp_password = $_ENV['SMTP_PASSWORD']; 
    $biz = get_biz_info();

    // 1. Fetch Booking Details
    $stmt = $conn->prepare("
        SELECT b.id, b.start_date, b.end_date, b.guests_count, b.base_amount, b.total_amount, b.payment_scheme, v.name as venue_name 
        FROM bookings b JOIN venues v ON b.venue_id = v.id 
        WHERE b.reference_no = ?
    ");
    $stmt->bind_param("s", $ref_no);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();

    // 2. Fetch Itemized Line Items!
    $stmt_li = $conn->prepare("SELECT item_name, amount FROM booking_line_items WHERE booking_id = ?");
    $stmt_li->bind_param("i", $booking['id']);
    $stmt_li->execute();
    $line_items = $stmt_li->get_result()->fetch_all(MYSQLI_ASSOC);

    $subject = "{$biz['biz_name']}: Your Final Event Invoice [$ref_no]";
    
    $check_in = date('F j, Y', strtotime($booking['start_date']));
    $check_out = date('F j, Y', strtotime($booking['end_date']));
    $date_str = ($check_in === $check_out) ? $check_in : "$check_in - $check_out";

    // 3. Build the Itemized Breakdown HTML
    $breakdown_html = "";
    $breakdown_html .= "<tr><td colspan='2' style='padding: 15px 12px 5px; border-top: 1px dashed #ccc; color: #2a2522;'><strong>Finalized Itemized Invoice:</strong></td></tr>";
    $breakdown_html .= "<tr><td style='padding: 5px 12px; color: #666; font-size: 14px;'>Venue Base Rate</td><td style='padding: 5px 12px; text-align: right; color: #666; font-size: 14px;'>₱" . number_format($booking['base_amount'], 2) . "</td></tr>";
    
    foreach ($line_items as $item) {
        $breakdown_html .= "<tr><td style='padding: 5px 12px; color: #666; font-size: 14px;'>" . htmlspecialchars($item['item_name']) . "</td><td style='padding: 5px 12px; text-align: right; color: #666; font-size: 14px;'>₱" . number_format($item['amount'], 2) . "</td></tr>";
    }
    
    // 4. Calculate the required downpayment based on their scheme
    $scheme = $booking['payment_scheme'];
    $downpayment = 0;
    if (strpos($scheme, '50%') !== false) $downpayment = $booking['total_amount'] * 0.50;
    elseif (strpos($scheme, '20%') !== false) $downpayment = $booking['total_amount'] * 0.20;
    else $downpayment = $booking['total_amount'];

    // 5. Build the Email
    $html_content = "
    <div style='background-color: #f4f4f4; padding: 40px 0; font-family: \"Helvetica Neue\", Helvetica, Arial, sans-serif;'>
        <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);'>
            <div style='background-color: #2a2522; padding: 30px; text-align: center;'>
                <h1 style='color: #d6a870; margin: 0; font-size: 28px; letter-spacing: 2px; text-transform: uppercase;'>" . htmlspecialchars($biz['biz_name']) . "</h1>
                <p style='color: #a3a3a3; margin: 5px 0 0 0; font-size: 12px; letter-spacing: 1px; text-transform: uppercase;'>" . htmlspecialchars($biz['biz_tagline']) . "</p>
            </div>
            <div style='padding: 40px;'>
                <h2 style='color: #2a2522; margin-top: 0;'>Your Event Consultation is Complete!</h2>
                <p style='color: #555; font-size: 15px; line-height: 1.6;'>
                    Hello <strong>$customer_name</strong>,<br><br>
                    Our administration has finalized your customized event quotation based on our recent consultation. Please review the updated details below.
                </p>
                
                <div style='background: #faf9f7; border: 1px solid #e5e5e5; border-radius: 6px; padding: 25px; margin-top: 20px;'>
                    <table style='width: 100%; border-collapse: collapse; font-size: 15px; color: #2a2522;'>
                        <tr>
                            <td style='padding: 12px; border-bottom: 1px solid #eee; width: 40%;'><strong>Reference No:</strong></td>
                            <td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right; color: #d6a870;'><strong>$ref_no</strong></td>
                        </tr>
                        <tr><td style='padding: 12px; border-bottom: 1px solid #eee;'><strong>Venue:</strong></td><td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right;'>" . $booking['venue_name'] . "</td></tr>
                        <tr><td style='padding: 12px; border-bottom: 1px solid #eee;'><strong>Event Date:</strong></td><td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right;'>$date_str</td></tr>
                        <tr><td style='padding: 12px;'><strong>Guests:</strong></td><td style='padding: 12px; text-align: right;'>" . $booking['guests_count'] . " Persons</td></tr>
                        
                        $breakdown_html
                        
                        <tr>
                            <td style='padding: 15px 12px; border-top: 2px solid #2a2522;'><strong>Final Total Amount:</strong></td>
                            <td style='padding: 15px 12px; border-top: 2px solid #2a2522; text-align: right; font-size: 18px; color: #d6a870;'><strong>₱" . number_format($booking['total_amount'], 2) . "</strong></td>
                        </tr>
                        <tr>
                            <td style='padding: 12px; border-bottom: none;'><strong>Required to Secure Dates ($scheme):</strong></td>
                            <td style='padding: 12px; border-bottom: none; text-align: right; color: #e06666;'><strong>₱" . number_format($downpayment, 2) . "</strong></td>
                        </tr>
                    </table>
                </div>

                <div style='text-align: center; margin-top: 30px;'>
                    <a href='$dashboard_link' style='background-color: #d6a870; color: white; padding: 14px 28px; text-decoration: none; font-weight: bold; border-radius: 4px; display: inline-block;'>Pay Now to Secure Booking</a>
                </div>
                
                <div style='margin-top: 40px; border-top: 1px solid #eee; padding-top: 20px; font-size: 13px; color: #888; line-height: 1.5;'>
                    <strong>Policies:</strong><br>
                    " . nl2br(htmlspecialchars($biz['biz_policies'])) . "
                </div>
                <div style='margin-top: 20px; font-size: 12px; color: #aaa; text-align: center;'>
                    " . htmlspecialchars($biz['biz_address']) . " | " . htmlspecialchars($biz['biz_phone']) . " | " . htmlspecialchars($biz['biz_email']) . "
                </div>
            </div>
        </div>
    </div>
    ";

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->CharSet = 'UTF-8';
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp_email;
        $mail->Password   = $smtp_password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));

        $mail->setFrom($smtp_email, $biz['biz_name'] . ' Accounts');
        $mail->addAddress($customer_email, $customer_name); 
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_content;
        $mail->send();
    } catch (Exception $e) { throw new Exception("Mailer Error: {$mail->ErrorInfo}"); }
}
// -------------------------------------------------------------
// STANDALONE FUNCTION: Password Reset Email
// -------------------------------------------------------------
function send_password_reset_email($to_email, $to_name, $reset_link) {
    global $conn;
    $smtp_email = $_ENV['SMTP_EMAIL']; 
    $smtp_password = $_ENV['SMTP_PASSWORD']; 
    $biz = get_biz_info();
    
    $subject = "{$biz['biz_name']}: Password Reset Request";
    
    $html_content = "
    <div style='background-color: #f4f4f4; padding: 40px 0; font-family: \"Helvetica Neue\", Helvetica, Arial, sans-serif;'>
        <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);'>
            <div style='background-color: #2a2522; padding: 30px; text-align: center;'>
                <h1 style='color: #d6a870; margin: 0; font-size: 28px; letter-spacing: 2px; text-transform: uppercase;'>" . htmlspecialchars($biz['biz_name']) . "</h1>
            </div>
            <div style='padding: 40px;'>
                <h2 style='color: #2a2522; margin-top: 0; font-size: 20px;'>PASSWORD RESET REQUEST</h2>
                <p style='color: #555; font-size: 15px; line-height: 1.6;'>Hello <strong>" . htmlspecialchars($to_name) . "</strong>,</p>
                <p style='color: #555; font-size: 15px; line-height: 1.6;'>We received a request to reset your password. If you didn't make this request, you can safely ignore this email.</p>
                
                <div style='text-align: center; margin: 40px 0;'>
                    <a href='" . htmlspecialchars($reset_link) . "' style='background-color: #d6a870; color: #fff; padding: 14px 28px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 16px; display: inline-block;'>RESET PASSWORD</a>
                </div>
                
                <p style='color: #888; font-size: 13px; line-height: 1.5;'><em>Note: This link will expire in 1 hour.</em></p>
                
                <div style='margin-top: 40px; border-top: 1px solid #eee; padding-top: 20px; font-size: 12px; color: #aaa; text-align: center;'>
                    " . htmlspecialchars($biz['biz_address']) . " | " . htmlspecialchars($biz['biz_phone']) . " | " . htmlspecialchars($biz['biz_email']) . "
                </div>
            </div>
        </div>
    </div>
    ";
    
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->CharSet = 'UTF-8';
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp_email;
        $mail->Password   = $smtp_password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));

        $mail->setFrom($smtp_email, $biz['biz_name'] . ' Accounts');
        $mail->addAddress($to_email, $to_name); 
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_content;

        $mail->send();
        return true;
    } catch (Exception $e) { throw new Exception("Mailer Error: {$mail->ErrorInfo}"); }
}

/**
 * Sends a welcome email notification when account registration/verification is successful.
 */
function send_welcome_email($to_email, $to_name) {
    $biz = get_biz_info();
    $subject = "Welcome to {$biz['biz_name']}!";
    $first_name = htmlspecialchars(trim($to_name), ENT_QUOTES, 'UTF-8');
    
    $html_content = "
    <div style='background-color:#f4f4f4; padding:40px 0; font-family:Helvetica, Arial, sans-serif;'>
        <div style='max-width:600px; margin:0 auto; background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 4px 15px rgba(0,0,0,0.05);'>
            <div style='background-color:#2a2522; padding:30px; text-align:center;'>
                <h1 style='color:#d6a870; margin:0; font-size:28px; letter-spacing:2px; text-transform:uppercase;'>" . htmlspecialchars($biz['biz_name'], ENT_QUOTES, 'UTF-8') . "</h1>
                <p style='color:#a3a3a3; margin:5px 0 0; font-size:12px; letter-spacing:1px; text-transform:uppercase;'>" . htmlspecialchars($biz['biz_tagline'], ENT_QUOTES, 'UTF-8') . "</p>
            </div>
            <div style='padding:40px;'>
                <h2 style='color:#2a2522; margin-top:0; font-size:20px;'>WELCOME TO SEVILLA360!</h2>
                <p style='color:#555; font-size:15px; line-height:1.6;'>Hello <strong>$first_name</strong>,<br><br>Your account has been successfully verified! Welcome to Sevilla360. You can now explore our virtual 360° showroom, view venue pricing, and make online reservations for your events.</p>
                <div style='margin-top:32px; border-top:1px solid #eee; padding-top:20px; font-size:12px; color:#aaa; text-align:center; line-height:1.6;'>
                    " . htmlspecialchars($biz['biz_address'], ENT_QUOTES, 'UTF-8') . " | " . htmlspecialchars($biz['biz_phone'], ENT_QUOTES, 'UTF-8') . " | " . htmlspecialchars($biz['biz_email'], ENT_QUOTES, 'UTF-8') . "
                </div>
            </div>
        </div>
    </div>";

    return send_custom_email($to_email, $to_name, $subject, $html_content);
}
?>
