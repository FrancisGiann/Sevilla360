<?php
session_start();
require '../../config/db_connect.php';
require_once '../../includes/notifications.php';
require_once '../../includes/mailer.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {

// CSRF PROTECTION
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Security token expired. Please refresh the page and try again.', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }
    
    $email = trim($_POST['email']);
    $code = trim($_POST['verification_code']);

    // 1. Find the user by email and check their code and expiration
    $stmt = $conn->prepare("SELECT id, verification_code, verification_expires_at FROM users WHERE email = ? AND is_verified = FALSE");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $current_time = date('Y-m-d H:i:s');

        // 2. Check if the code has expired
        if ($current_time > $user['verification_expires_at']) {
            $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'This verification code has expired. Please request a new one.', 'type' => 'error'];
            header("Location: ../../auth.php?verify_email=" . urlencode($email));
            exit();
        }

        // 3. Check if the code matches
        if ($code === $user['verification_code']) {
            
            // 4. Update the user to verified and clear the code
            $update_stmt = $conn->prepare("UPDATE users SET is_verified = TRUE, verification_code = NULL, verification_expires_at = NULL WHERE id = ?");
            $update_stmt->bind_param("i", $user['id']);
            
            // Protect against session fixation attacks
            session_regenerate_id(true);
            
            // Log them in immediately!
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = 'customer';
            $_SESSION['logged_in'] = true;

            // Fetch their name for the session
            $name_stmt = $conn->prepare("SELECT first_name FROM customers WHERE user_id = ?");
            $name_stmt->bind_param("i", $user['id']);
            $name_stmt->execute();
            $name_result = $name_stmt->get_result();
            if ($name_result->num_rows === 1) {
                $customer_data = $name_result->fetch_assoc();
                $_SESSION['first_name'] = $customer_data['first_name'];
            }
            
            if ($update_stmt->execute()) {
                
                // 1. Create persistent In-App Welcome Notification upon successful OTP verification
                $welcome_title = "Welcome to Sevilla360!";
                $welcome_msg = "Your account has been successfully verified. Welcome to Sevilla360! You can now explore our virtual showroom and use the available booking features.";

                // Duplicate Protection Check for In-App Notification
                $check_notif = $conn->prepare("SELECT id FROM user_notifications WHERE user_id = ? AND title = ? LIMIT 1");
                if ($check_notif) {
                    $check_notif->bind_param("is", $user['id'], $welcome_title);
                    $check_notif->execute();
                    $notif_res = $check_notif->get_result();
                    if ($notif_res->num_rows === 0) {
                        try {
                            create_user_notification($conn, $user['id'], $welcome_title, $welcome_msg);
                        } catch (Exception $notif_e) {
                            // Non-blocking error handling: notification failure does not prevent user verification
                        }
                    }
                    $check_notif->close();
                }

                // 2. Send Welcome Email Notification to the user's email inbox
                try {
                    $user_first_name = $_SESSION['first_name'] ?? 'Customer';
                    send_welcome_email($email, $user_first_name);
                } catch (Exception $email_e) {
                    // Non-blocking error handling: email delivery failure does not disrupt login
                }
                
                $_SESSION['auth_alert'] = ['title' => 'Success', 'message' => 'Account verified successfully! Welcome to SEVILLA360.', 'type' => 'success'];
                header("Location: ../../user_dashboard.php");
                exit();
            }

        } else {
            // Code was wrong
            $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Invalid verification code. Please try again.', 'type' => 'error'];
            header("Location: ../../auth.php?verify_email=" . urlencode($email));
            exit();
        }
    } else {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Error: Account not found or already verified.', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }
}
?>