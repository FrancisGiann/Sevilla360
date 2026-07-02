<?php
session_start();
require '../../config/db_connect.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
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
            echo "<script>
                alert('This verification code has expired. Please request a new one.'); 
                window.location.href = '../../auth.php?verify_email=" . urlencode($email) . "';
            </script>";
            exit();
        }

        // 3. Check if the code matches
        if ($code === $user['verification_code']) {
            
            // 4. Update the user to verified and clear the code
            $update_stmt = $conn->prepare("UPDATE users SET is_verified = TRUE, verification_code = NULL, verification_expires_at = NULL WHERE id = ?");
            $update_stmt->bind_param("i", $user['id']);
            $update_stmt->execute();
            
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
            
            echo "<script>
                alert('Account verified successfully! Welcome to SEVILLA360.'); 
                window.location.href = '../../index.php';
            </script>";
            exit();

        } else {
            // Code was wrong
            echo "<script>
                alert('Invalid verification code. Please try again.'); 
                window.location.href = '../../auth.php?verify_email=" . urlencode($email) . "';
            </script>";
            exit();
        }
    } else {
        echo "<script>
            alert('Error: Account not found or already verified.'); 
            window.location.href = '../../auth.php';
        </script>";
    }
}
?>