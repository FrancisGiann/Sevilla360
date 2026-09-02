<?php
require_once __DIR__ . '/../../includes/session_init.php';
require '../../config/db_connect.php';
require_once '../../includes/rate_limit.php';
require_once '../../includes/password_policy.php';

// =========================================================================
// DATABASE HYGIENE: The "Piggyback" Auto-Delete
// Automatically delete unverified accounts that are older than 24 hours.
// =========================================================================
$cleanup_stmt = $conn->prepare("
    DELETE FROM users 
    WHERE is_verified = FALSE 
    AND verification_expires_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
");
$cleanup_stmt->execute();
$cleanup_stmt->close();
// =========================================================================

if ($_SERVER["REQUEST_METHOD"] == "POST") {

// CSRF PROTECTION FOR FORMS
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Security token expired. Please refresh the page and try again.', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }
    
    // HONEYPOT CHECK
    if (!empty($_POST['website_url_honeypot'])) {
        // Bot detected
        echo "<script>window.location.href = '../../auth.php';</script>";
        exit();
    }
    
    // RATE LIMITING CHECK
    if (!check_rate_limit($conn, 'register_attempt', 3, 60)) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Too many registration attempts. Please try again later.', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }
    
    //  Sanitize and retrieve POST data
    $first_name = trim((string)($_POST['first_name'] ?? ''));
    $last_name = trim((string)($_POST['last_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));

    $password = (string)($_POST['password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    //  Basic Validation
    if ($password !== $confirm_password) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Passwords do not match!', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }

    if (empty($first_name) || empty($last_name) || empty($email) || empty($password) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Please fill in all required fields.', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }
    $password_policy = password_policy_validate($password);
    if (!$password_policy['valid']) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => $password_policy['message'], 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }
    if (!isset($_POST['consent']) || $_POST['consent'] !== '1') {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'You must accept the Terms of Service and Privacy Policy.', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }

    // Check if the user ALREADY exists in the 'users' table (True duplicates)
    $stmt_check_user = $conn->prepare("SELECT id, is_verified FROM users WHERE email = ?");
    $stmt_check_user->bind_param("s", $email);
    $stmt_check_user->execute();
    $res_check = $stmt_check_user->get_result();

    if ($res_check->num_rows > 0) {
        $existing_user = $res_check->fetch_assoc();
        if ($existing_user['is_verified']) {
            $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Error: That email address is already registered. Please log in.', 'type' => 'error'];
            header("Location: ../../auth.php");
            exit();
        } else {
            $_SESSION['auth_alert'] = ['title' => 'Notice', 'message' => 'An unverified account with this email exists. Please verify it or try again later.', 'type' => 'warning'];
            header("Location: ../../auth.php");
            exit();
        }
    }
    $stmt_check_user->close();

    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    //  Generate Verification Details
    $verification_code = sprintf("%06d", random_int(1, 999999));
    $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    try {
        // START TRANSACTION
        $conn->begin_transaction();

        //  Insert into USERS table
        $stmt1 = $conn->prepare("INSERT INTO users (email, password_hash, role, verification_code, verification_expires_at, consented_at) VALUES (?, ?, 'customer', ?, ?, NOW())");
        $stmt1->bind_param("ssss", $email, $hashed_password, $verification_code, $expires_at);
        $stmt1->execute();
        
        $new_user_id = $conn->insert_id; 

        // Link to existing walk-in profile or create a new one
        $stmt_check_cust = $conn->prepare("SELECT id, user_id FROM customers WHERE email = ?");
        $stmt_check_cust->bind_param("s", $email);
        $stmt_check_cust->execute();
        $cust_res = $stmt_check_cust->get_result();

        if ($cust_res->num_rows > 0) {
            $existing_customer = $cust_res->fetch_assoc();
            
            // If they are a Walk-in (user_id is NULL), link the new account!
            if ($existing_customer['user_id'] === null) {
                $stmt_link = $conn->prepare("UPDATE customers SET user_id = ?, first_name = ?, last_name = ? WHERE id = ?");
                $stmt_link->bind_param("issi", $new_user_id, $first_name, $last_name, $existing_customer['id']);
                $stmt_link->execute();
                $stmt_link->close();
            } else {
                // If they somehow have a user_id but weren't caught by the users table check (Corrupted DB state)
                throw new Exception("This email is tied to a corrupted profile.");
            }
        } else {
            // Completely new customer! Insert them normally.
            $stmt2 = $conn->prepare("INSERT INTO customers (user_id, first_name, last_name, email) VALUES (?, ?, ?, ?)");
            $stmt2->bind_param("isss", $new_user_id, $first_name, $last_name, $email);
            $stmt2->execute();
            $stmt2->close();
        }
        $stmt_check_cust->close();
        // =========================================================================

        // COMMIT TRANSACTION
        $conn->commit();

        // =========================================================================
        // PRODUCTION MODE: SEND OTP VIA PHPMAILER
        // =========================================================================
        require_once '../../includes/mailer.php';
        
        $subject = "Verify Your Sevilla360 Account";
        $html_content = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 8px;'>
            <h2 style='color: #d6a870; text-align: center;'>SEVILLA360</h2>
            <div style='background: #faf9f7; padding: 20px; border-radius: 6px; text-align: center;'>
                <h3 style='margin-top: 0; color: #2a2522;'>Welcome, $first_name!</h3>
                <p style='color: #555; font-size: 16px;'>Please use the following 6-digit code to verify your account. This code will expire in 15 minutes.</p>
                
                <div style='background: #fff; border: 2px dashed #d6a870; padding: 15px; font-size: 24px; font-weight: bold; letter-spacing: 5px; color: #2a2522; margin: 20px auto; width: fit-content;'>
                    $verification_code
                </div>
                
                <p style='color: #888; font-size: 12px; margin-top: 20px;'>If you did not request this code, please ignore this email.</p>
            </div>
        </div>
        ";

        try {
            // Re-using our universal email function from mailer.php!
            // We pass the raw HTML content instead of the standard receipt variables.
            send_custom_email($email, "$first_name $last_name", $subject, $html_content);
            
            // Redirect to Verification Page silently
            header("Location: ../../auth.php?verify_email=" . urlencode($email));
            exit();

        } catch (Exception $mail_e) {
            // If the email fails to send (e.g. invalid email address), rollback the account creation!
            $conn->query("DELETE FROM users WHERE id = $new_user_id");
            $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Failed to send verification email. Please check if your email address is valid.', 'type' => 'error'];
            header("Location: ../../auth.php");
            exit();
        }
        // =========================================================================

    } catch (Exception $e) {
        // ROLLBACK IF DATABASE ERROR
    }

    if (isset($stmt1)) $stmt1->close();
}

$conn->close();
?>
