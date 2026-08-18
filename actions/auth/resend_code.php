<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');
require '../../config/db_connect.php';

// ==========================================
// CSRF PROTECTION GUARD
// ==========================================
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed. Unauthorized request.']);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['email'])) {
    $email = trim($_POST['email']);
    
    // 1. Generate a new secure 6-digit code
    $new_code = sprintf("%06d", random_int(1, 999999));
    $new_expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    // 2. Update the database ONLY if the account exists and is NOT verified yet
    $stmt = $conn->prepare("UPDATE users SET verification_code = ?, verification_expires_at = ? WHERE email = ? AND is_verified = FALSE");
    $stmt->bind_param("sss", $new_code, $new_expires_at, $email);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        
        // 3. Fetch the customer's name for the email
        $name_stmt = $conn->prepare("SELECT first_name, last_name FROM customers WHERE email = ? LIMIT 1");
        $name_stmt->bind_param("s", $email);
        $name_stmt->execute();
        $name_res = $name_stmt->get_result();
        $customer_name = "Guest";
        if ($name_res->num_rows > 0) {
            $c = $name_res->fetch_assoc();
            $customer_name = trim($c['first_name'] . ' ' . $c['last_name']);
        }
        
        // 4. Send the Email!
        require_once '../../includes/mailer.php';
        $subject = "Your New Verification Code - Sevilla360";
        $html_content = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 8px;'>
            <h2 style='color: #d6a870; text-align: center;'>SEVILLA360</h2>
            <div style='background: #faf9f7; padding: 20px; border-radius: 6px; text-align: center;'>
                <h3 style='margin-top: 0; color: #2a2522;'>Hello, $customer_name!</h3>
                <p style='color: #555; font-size: 16px;'>You requested a new verification code. Please use the 6-digit code below to verify your account. It expires in 15 minutes.</p>
                
                <div style='background: #fff; border: 2px dashed #d6a870; padding: 15px; font-size: 24px; font-weight: bold; letter-spacing: 5px; color: #2a2522; margin: 20px auto; width: fit-content;'>
                    $new_code
                </div>
                
                <p style='color: #888; font-size: 12px; margin-top: 20px;'>If you did not request this code, please ignore this email.</p>
            </div>
        </div>
        ";
        
        try {
            send_custom_email($email, $customer_name, $subject, $html_content);
            echo json_encode(['success' => true, 'message' => 'A new verification code has been sent to your email inbox!']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to send email. Please try again later.']);
        }

    } else {
        echo json_encode(['success' => false, 'message' => 'Could not resend. Account may not exist or is already verified.']);
    }

    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request data.']);
}
$conn->close();
?>