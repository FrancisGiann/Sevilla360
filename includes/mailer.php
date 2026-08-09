<?php
// includes/mailer.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php'; // Load env variables

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_booking_receipt($customer_email, $customer_name, $ref_no, $venue_name, $amount_paid, $status) {
    global $conn;
    $smtp_email = $_ENV['SMTP_EMAIL']; 
    $smtp_password = $_ENV['SMTP_PASSWORD']; 

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

    $subject = "Sevilla360: Your Booking Itinerary [$ref_no]";

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
            <tr><td style='padding: 12px; border-bottom: 1px solid #eee;'><strong>Amount Paid:</strong></td><td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right; color: #4ade80;'>₱" . number_format($total_paid, 2) . "</td></tr>
            <tr><td style='padding: 12px; border-bottom: 2px solid #2a2522; font-size: 18px;'><strong>BALANCE DUE:</strong></td><td style='padding: 12px; border-bottom: 2px solid #2a2522; text-align: right; font-size: 18px; color: #e06666;'><strong>₱" . number_format($balance, 2) . "</strong></td></tr>
        ";
        $header_title = "OFFICIAL BOOKING ITINERARY";
        $header_desc = "Thank you for choosing Sevilla360. Please present this e-ticket at the front desk upon arrival.";
    }

    $html_content = "
    <div style='background-color: #f4f4f4; padding: 40px 0; font-family: \"Helvetica Neue\", Helvetica, Arial, sans-serif;'>
        <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);'>
            <div style='background-color: #2a2522; padding: 30px; text-align: center;'>
                <h1 style='color: #d6a870; margin: 0; font-size: 28px; letter-spacing: 2px;'>SEVILLA360</h1>
                <p style='color: #a3a3a3; margin: 5px 0 0 0; font-size: 12px; letter-spacing: 1px;'>LUXURY RESORT & EVENTS</p>
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
                    <strong>Resort Policies:</strong><br>
                    • Standard Check-in is at 2:00 PM. Check-out is at 12:00 PM (Unless booking Day Time Stay).<br>
                    • Please bring a valid Government ID matching the name on this itinerary.<br>
                    • Cancellations made less than 7 days before arrival are subject to fees.
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
        $mail->setFrom($smtp_email, 'Sevilla360 Reservations');
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

        $mail->setFrom($smtp_email, 'Sevilla360 Accounts');
        $mail->addAddress($to_email, $to_name); 
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_content;

        $mail->send();
        return true;
    } catch (Exception $e) { throw new Exception("Mailer Error: {$mail->ErrorInfo}"); }
}

// -------------------------------------------------------------
// STANDALONE FUNCTION (Fixed from being nested!)
// -------------------------------------------------------------
function send_invoice_ready_email($customer_email, $customer_name, $ref_no, $total_amount, $dashboard_link) {
    global $conn;
    $smtp_email = $_ENV['SMTP_EMAIL']; 
    $smtp_password = $_ENV['SMTP_PASSWORD']; 

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

    $subject = "Sevilla360: Your Final Event Invoice [$ref_no]";
    
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
                <h1 style='color: #d6a870; margin: 0; font-size: 28px; letter-spacing: 2px;'>SEVILLA360</h1>
                <p style='color: #a3a3a3; margin: 5px 0 0 0; font-size: 12px; letter-spacing: 1px;'>LUXURY RESORT & EVENTS</p>
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
            </div>
        </div>
    </div>";

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

        $mail->setFrom($smtp_email, 'Sevilla360 Events');
        $mail->addAddress($customer_email, $customer_name); 
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_content;
        $mail->send();
    } catch (Exception $e) { throw new Exception("Mailer Error: {$mail->ErrorInfo}"); }
}
?>