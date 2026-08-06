<?php
// includes/mailer.php

function send_booking_receipt($customer_email, $customer_name, $ref_no, $venue_name, $amount_paid, $status) {
    // YOUR RESEND API KEY
    $resend_api_key = 're_jQepU2zL_E1CcErKXYwhBYV7YjUPgZfwi'; 
    
    // NOTE: On Resend's free tier, you can only send FROM 'onboarding@resend.dev' 
    // to the exact email address you used to sign up for Resend! 
    // (Once you add a real domain later, you can send to anyone).
    $from_email = 'onboarding@resend.dev'; 

    $subject = "Sevilla360 Booking Update: $ref_no";

    // Format the money
    $formatted_amount = "₱" . number_format($amount_paid, 2);
    if ($amount_paid <= 0 && $status === 'Pending') {
        $formatted_amount = "To Be Arranged (Inquiry)";
    }

    // A beautiful, modern HTML Email Template!
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

    // Setup the Payload for Resend
    $payload = json_encode([
        'from' => 'Sevilla360 <' . $from_email . '>',
        'to' => [$customer_email], // The customer's email
        'subject' => $subject,
        'html' => $html_content
    ]);

    // Send using cURL!
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    
    // XAMPP SSL bypass
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $resend_api_key,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    curl_close($ch);
    
    return $response;
}
?>