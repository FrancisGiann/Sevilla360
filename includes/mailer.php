<?php
// includes/mailer.php

// 1. Load PHPMailer from Composer
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_booking_receipt($customer_email, $customer_name, $ref_no, $venue_name, $amount_paid, $status) {
    
    // ==========================================
    // YOUR GMAIL CREDENTIALS
    // ==========================================
    $smtp_email = 'francisgiann25@gmail.com'; // Put your real Gmail here
    $smtp_password = 'oclcivfearkmzreq'; // Put your App Password here (no spaces!)
    // ==========================================

    $subject = "Sevilla360 Booking Update: $ref_no";

    // Format the money
    $formatted_amount = "₱" . number_format($amount_paid, 2);
    if ($amount_paid <= 0 && $status === 'Pending') {
        $formatted_amount = "To Be Arranged (Inquiry)";
    }

    // HTML Email Template
    $html_content = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 8px;'>
        <h2 style='color: #d6a870; text-align: center;'>SEVILLA360</h2>
        <div style='background: #faf9f7; padding: 20px; border-radius: 6px;'>
            <h3 style='margin-top: 0; color: #2a2522;'>Hello $customer_name,</h3>
            <p style='color: #555; font-size: 16px;'>Here is an update regarding your reservation.</p>
            
            <table style='width: 100%; border-collapse: collapse; margin-top: 20px;'>
                <tr>
                    <td style='padding: 10px; border-bottom: 1px solid #ddd; font-weight: bold;'>Reference No:</td>
                    <td style='padding: 10px; border-bottom: 1px solid #ddd; color: #d6a870; font-weight: bold;'>$ref_no</td>
                </tr>
                <tr>
                    <td style='padding: 10px; border-bottom: 1px solid #ddd; font-weight: bold;'>Venue:</td>
                    <td style='padding: 10px; border-bottom: 1px solid #ddd;'>$venue_name</td>
                </tr>
                <tr>
                    <td style='padding: 10px; border-bottom: 1px solid #ddd; font-weight: bold;'>Status:</td>
                    <td style='padding: 10px; border-bottom: 1px solid #ddd;'>$status</td>
                </tr>
                <tr>
                    <td style='padding: 10px; font-weight: bold;'>Amount Paid:</td>
                    <td style='padding: 10px; font-weight: bold; color: #4ade80;'>$formatted_amount</td>
                </tr>
            </table>
        </div>
        <p style='text-align: center; color: #888; font-size: 12px; margin-top: 30px;'>
            Thank you for choosing Sevilla360. If you have any questions, please contact our front desk.
        </p>
    </div>
    ";

    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp_email;
        $mail->Password   = $smtp_password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Bypasses SSL issues on Localhost (XAMPP/Fedora)
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        // Recipients
        $mail->setFrom($smtp_email, 'Sevilla360 Reservations');
        $mail->addAddress($customer_email, $customer_name); 

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_content;

        $mail->send();
        return true;
    } catch (Exception $e) {
        throw new Exception("Mailer Error: {$mail->ErrorInfo}");
    }
}
function send_custom_email($to_email, $to_name, $subject, $html_content) {
    // ==========================================
    // YOUR GMAIL CREDENTIALS
    // ==========================================
    $smtp_email = 'your.email@gmail.com'; 
    $smtp_password = 'your 16 letter app password'; 
    // ==========================================

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

        $mail->setFrom($smtp_email, 'Sevilla360 Accounts');
        $mail->addAddress($to_email, $to_name); 

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_content;

        $mail->send();
        return true;
    } catch (Exception $e) {
        throw new Exception("Mailer Error: {$mail->ErrorInfo}");
    }
}
?>