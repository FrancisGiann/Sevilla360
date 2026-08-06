<?php
// includes/mailer.php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_booking_receipt($customer_email, $customer_name, $ref_no, $venue_name, $amount_paid, $status) {
    
    // ==========================================
    // YOUR GMAIL CREDENTIALS
    // ==========================================
    $smtp_email = 'francisgiann25@gmail.com'; 
    $smtp_password = 'oclcivfearkmzreq'; 
    // ==========================================

    // 1. SMART FETCH: Grab the full booking details from the DB!
    require __DIR__ . '/../config/db_connect.php';
    $stmt = $conn->prepare("SELECT start_date, end_date, guests_count, total_amount, amount_paid FROM bookings WHERE reference_no = ?");
    $stmt->bind_param("s", $ref_no);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();

    // Format Data
    $check_in = date('F j, Y', strtotime($booking['start_date']));
    $check_out = date('F j, Y', strtotime($booking['end_date']));
    $guests = $booking['guests_count'];
    $total_amt = floatval($booking['total_amount']);
    $total_paid = floatval($booking['amount_paid']);
    $balance = $total_amt - $total_paid;

    $subject = "Sevilla360: Your Booking Itinerary [$ref_no]";

    // If it's an inquiry, mask the prices
    if ($total_amt <= 0 || strpos($status, 'Inquiry') !== false) {
        $money_block = "
            <tr><td style='padding: 12px; border-bottom: 1px solid #eee;'><strong>Estimated Total:</strong></td><td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right; color:#b5884e; font-style:italic;'>To Be Arranged</td></tr>
            <tr><td style='padding: 12px; border-bottom: 1px solid #eee;'><strong>Amount Paid:</strong></td><td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right;'>₱0.00</td></tr>
        ";
        $header_title = "EVENT INQUIRY RECEIVED";
        $header_desc = "We have received your event inquiry. Our coordinator will contact you shortly to finalize the details and pricing.";
    } else {
        $money_block = "
            <tr><td style='padding: 12px; border-bottom: 1px solid #eee;'><strong>Total Amount:</strong></td><td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right;'>₱" . number_format($total_amt, 2) . "</td></tr>
            <tr><td style='padding: 12px; border-bottom: 1px solid #eee;'><strong>Amount Paid:</strong></td><td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right; color: #4ade80;'>₱" . number_format($total_paid, 2) . "</td></tr>
            <tr><td style='padding: 12px; border-bottom: 2px solid #2a2522; font-size: 18px;'><strong>BALANCE DUE:</strong></td><td style='padding: 12px; border-bottom: 2px solid #2a2522; text-align: right; font-size: 18px; color: #e06666;'><strong>₱" . number_format($balance, 2) . "</strong></td></tr>
        ";
        $header_title = "OFFICIAL BOOKING ITINERARY";
        $header_desc = "Thank you for choosing Sevilla360. Please present this e-ticket at the front desk upon arrival.";
    }

    // A beautiful, premium HTML Email Template!
    $html_content = "
    <div style='background-color: #f4f4f4; padding: 40px 0; font-family: \"Helvetica Neue\", Helvetica, Arial, sans-serif;'>
        <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);'>
            
            <!-- Header -->
            <div style='background-color: #2a2522; padding: 30px; text-align: center;'>
                <h1 style='color: #d6a870; margin: 0; font-size: 28px; letter-spacing: 2px;'>SEVILLA360</h1>
                <p style='color: #a3a3a3; margin: 5px 0 0 0; font-size: 12px; letter-spacing: 1px;'>LUXURY RESORT & EVENTS</p>
            </div>

            <!-- Body -->
            <div style='padding: 40px;'>
                <h2 style='color: #2a2522; margin-top: 0; font-size: 20px;'>$header_title</h2>
                <p style='color: #555; font-size: 15px; line-height: 1.6;'>Hello <strong>$customer_name</strong>,<br>$header_desc</p>
                
                <!-- Receipt Card -->
                <div style='background: #faf9f7; border: 1px solid #e5e5e5; border-radius: 6px; padding: 25px; margin-top: 30px;'>
                    <table style='width: 100%; border-collapse: collapse; font-size: 15px; color: #2a2522;'>
                        <tr>
                            <td style='padding: 12px; border-bottom: 1px solid #eee; width: 40%;'><strong>Reference No:</strong></td>
                            <td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right; font-family: monospace; font-size: 16px; color: #d6a870;'><strong>$ref_no</strong></td>
                        </tr>
                        <tr>
                            <td style='padding: 12px; border-bottom: 1px solid #eee;'><strong>Venue:</strong></td>
                            <td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right;'>$venue_name</td>
                        </tr>
                        <tr>
                            <td style='padding: 12px; border-bottom: 1px solid #eee;'><strong>Check-in:</strong></td>
                            <td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right;'>$check_in</td>
                        </tr>
                        <tr>
                            <td style='padding: 12px; border-bottom: 1px solid #eee;'><strong>Check-out:</strong></td>
                            <td style='padding: 12px; border-bottom: 1px solid #eee; text-align: right;'>$check_out</td>
                        </tr>
                        <tr>
                            <td style='padding: 12px; border-bottom: 2px solid #2a2522;'><strong>Guests:</strong></td>
                            <td style='padding: 12px; border-bottom: 2px solid #2a2522; text-align: right;'>$guests Persons</td>
                        </tr>
                        
                        <!-- Financials injected here -->
                        $money_block
                        
                        <tr>
                            <td style='padding: 12px; border-bottom: none;'><strong>Status:</strong></td>
                            <td style='padding: 12px; border-bottom: none; text-align: right;'><strong>$status</strong></td>
                        </tr>
                    </table>
                </div>

                <div style='margin-top: 40px; border-top: 1px solid #eee; padding-top: 20px; font-size: 13px; color: #888; line-height: 1.5;'>
                    <strong>Resort Policies:</strong><br>
                    • Standard Check-in is at 2:00 PM. Check-out is at 12:00 PM.<br>
                    • Please bring a valid Government ID matching the name on this itinerary.<br>
                    • Cancellations made less than 7 days before arrival are subject to fees.
                </div>
            </div>

            <!-- Footer -->
            <div style='background-color: #faf9f7; padding: 20px; text-align: center; border-top: 1px solid #eee;'>
                <p style='margin: 0; color: #888; font-size: 12px;'>
                    Sevilla360 Resort<br>
                    123 Paradise Road, Beachfront City, Philippines<br>
                    Contact: +63 917 123 4567 | frontdesk@sevilla360.com
                </p>
            </div>
        </div>
    </div>
    ";

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp_email;
        $mail->Password   = $smtp_password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $mail->setFrom($smtp_email, 'Sevilla360 Reservations');
        $mail->addAddress($customer_email, $customer_name); 

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_content;

        $mail->send();
        return true;
    } catch (Exception $e) {
        throw new Exception("Mailer Error: {$mail->ErrorInfo}");
    }
}

// ... (KEEP THE send_custom_email OTP FUNCTION AT THE BOTTOM) ...
function send_custom_email($to_email, $to_name, $subject, $html_content) {
    $smtp_email = 'your.email@gmail.com'; 
    $smtp_password = 'your 16 letter app password'; 
    
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
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
?>