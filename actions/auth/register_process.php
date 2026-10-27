<?php
require_once __DIR__ . '/../../includes/session_init.php';
require '../../config/db_connect.php';
require_once '../../includes/rate_limit.php';
require_once '../../includes/password_policy.php';
require_once __DIR__ . '/../../includes/contact_verification.php';

function registration_fail(string $message, string $title = 'Error', string $type = 'error'): never
{
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        http_response_code(400);
        echo json_encode(['state' => 'error', 'message' => $message]);
        exit;
    }
    $_SESSION['auth_alert'] = ['title' => $title, 'message' => $message, 'type' => $type];
    header('Location: ../../auth.php');
    exit;
}

function registration_success(string $url): never
{
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        echo json_encode(['state' => 'redirect', 'url' => $url]);
        exit;
    }
    header('Location: ' . $url);
    exit;
}
if (phone_signup_enabled()) require __DIR__ . '/phone_register_process.php';

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
        registration_fail('Security token expired. Please refresh the page and try again.');
    }
    
    // HONEYPOT CHECK
    if (!empty($_POST['website_url_honeypot'])) {
        // Bot detected
        registration_fail('Please try again later.');
    }
    
    // RATE LIMITING CHECK
    if (!check_rate_limit($conn, 'register_attempt', 3, 60)) {
        registration_fail('Too many registration attempts. Please try again later.');
    }
    
    //  Sanitize and retrieve POST data
    $first_name = trim((string)($_POST['first_name'] ?? ''));
    $last_name = trim((string)($_POST['last_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));

    $password = (string)($_POST['password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    //  Basic Validation
    if ($password !== $confirm_password) {
        registration_fail('Passwords do not match!');
    }

    if (empty($first_name) || empty($last_name) || empty($email) || empty($password) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        registration_fail('Please fill in all required fields.');
    }
    $password_policy = password_policy_validate($password);
    if (!$password_policy['valid']) {
        registration_fail($password_policy['message']);
    }
    if (!isset($_POST['consent']) || $_POST['consent'] !== '1') {
        registration_fail('You must accept the Terms of Service and Privacy Policy.');
    }

    // Check if the user ALREADY exists in the 'users' table (True duplicates)
    $stmt_check_user = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $stmt_check_user->bind_param("s", $email);
    $stmt_check_user->execute();
    $res_check = $stmt_check_user->get_result();

    if ($res_check->num_rows > 0) {
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
            http_response_code(409);
            echo json_encode(['state' => 'unavailable']);
            exit();
        }
        registration_fail('This email cannot be used to create an account. Try another email or sign in.', 'Notice', 'warning');
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
        // Send the verification code through the configured email provider.
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
            registration_success('../../auth.php?verify_email=' . urlencode($email));

        } catch (Exception $mail_e) {
            // Keep the existing cleanup behavior if verification delivery fails.
            $conn->query("DELETE FROM users WHERE id = $new_user_id");
            $mailDiagnostic = $mail_e instanceof ResendMailException
                ? $mail_e->getMessage()
                : 'Unexpected mail exception.';
            error_log('Registration verification email delivery failed: ' . $mailDiagnostic);
            registration_fail('We could not send your verification email. Please try again later or contact the resort for help.');
        }
        // =========================================================================

    } catch (Throwable $e) {
        $conn->rollback();
        if ((int)$e->getCode() === 1062 && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
            http_response_code(409);
            echo json_encode(['state' => 'unavailable']);
            exit();
        }
        registration_fail('We could not create your account right now. Please try again.');
    }

    if (isset($stmt1)) $stmt1->close();
}

$conn->close();
?>
