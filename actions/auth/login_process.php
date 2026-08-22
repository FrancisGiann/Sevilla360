<?php
// Start the session so we can remember the user after they log in
session_start();

// Connect to the database
require '../../config/db_connect.php';
require_once '../../includes/rate_limit.php';

// Check if the form was actually submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // CSRF PROTECTION FOR FORMS
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Security token expired. Please refresh the page and try again.', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }
    
    // HONEYPOT CHECK
    if (!empty($_POST['website_url_honeypot'])) {
        // Bot detected, pretend it succeeded or block silently
        echo "<script>window.location.href = '../../auth.php';</script>";
        exit();
    }
    
    // RATE LIMITING CHECK
    if (!check_rate_limit($conn, 'login_attempt', 5, 15)) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Too many login attempts. Please try again in 15 minutes.', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }
    
    // Grab the data from the HTML form's 'name' attributes
    $email = $_POST['email'];
    $password = $_POST['password'];

    // Secure Database Query (Prepared Statements prevent SQL Injection hacking)
    // Account status is intentionally checked here rather than only in the
    // administration screen.  A suspended customer must not be able to start
    // a new session, and an inactive staff member must not access the admin
    // portal. Staff availability lives in `staff.status`; customer suspension
    // lives in `users.status`.
    $stmt = $conn->prepare("
        SELECT u.id, u.password_hash, u.role, u.is_verified, u.status AS user_status,
               s.status AS staff_status
        FROM users u
        LEFT JOIN staff s ON s.user_id = u.id
        WHERE u.email = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    // check if we found a user with that email
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Check if the user is verified
        if ($user['is_verified'] == 0) {
            $_SESSION['auth_alert'] = ['title' => 'Notice', 'message' => 'Please verify your email address first!', 'type' => 'warning'];
            // Reopen the verification dialog after an interrupted attempt so
            // the customer can enter or resend a code without registering again.
            header("Location: ../../auth.php?verify_email=" . urlencode($email));
            exit();
        }

        //Verify the typed password against the encrypted one in the database
        if (password_verify($password, $user['password_hash'])) {

            $account_status = in_array($user['role'], ['admin', 'staff'], true)
                ? ($user['staff_status'] ?? '')
                : ($user['user_status'] ?? '');
            if (strcasecmp((string) $account_status, 'active') !== 0) {
                $_SESSION['auth_alert'] = [
                    'title' => 'Account unavailable',
                    'message' => 'This account is suspended or inactive. Please contact an administrator.',
                    'type' => 'error'
                ];
                header("Location: ../../auth.php");
                exit();
            }
            
            // ROLE VALIDATION GUARD
            $login_type = $_POST['login_type'] ?? 'customer';
            
            if ($login_type === 'admin' && !in_array($user['role'], ['admin', 'staff'])) {
                $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Unauthorized: Admin access required.', 'type' => 'error'];
                header("Location: ../../auth.php");
                exit();
            }
            
            if ($login_type === 'customer' && (in_array($user['role'], ['admin', 'staff']))) {
                $_SESSION['auth_alert'] = ['title' => 'Notice', 'message' => 'Please use the Administrator login portal.', 'type' => 'info'];
                header("Location: ../../auth.php");
                exit();
            }
            
            // =========================================================================
            // SECURITY FIX: SESSION FIXATION PREVENTION
            // Destroy the old, unauthenticated session ID and issue a brand new one
            // =========================================================================
            session_regenerate_id(true);
            
            // Clear rate limits on successful login
            clear_rate_limit($conn, 'login_attempt');
            
            // Success! Save user data into the Session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['logged_in'] = true;

            $display_name = 'Account'; 

            if ($user['role'] === 'customer') {
                $name_stmt = $conn->prepare("SELECT first_name FROM customers WHERE user_id = ?");
                $name_stmt->bind_param("i", $user['id']);
                $name_stmt->execute();
                $name_result = $name_stmt->get_result();
                
                if ($name_result->num_rows === 1) {
                    $customer_data = $name_result->fetch_assoc();
                    $display_name = $customer_data['first_name'];
                }
                $name_stmt->close();

            } else if ($user['role'] === 'staff' || $user['role'] === 'admin') {
                $name_stmt = $conn->prepare("SELECT full_name FROM staff WHERE user_id = ?");
                $name_stmt->bind_param("i", $user['id']);
                $name_stmt->execute();
                $name_result = $name_stmt->get_result();
                
                if ($name_result->num_rows === 1) {
                    $staff_data = $name_result->fetch_assoc();
                    $name_parts = explode(' ', trim($staff_data['full_name']));
                    $display_name = $name_parts[0]; 
                }
                $name_stmt->close();
            }

            $_SESSION['first_name'] = $display_name;

            // Successful staff/admin logins were previously absent from the
            // audit log. Keep this non-blocking: a logging failure must not
            // prevent a valid login.
            try {
                $login_action = 'Successful ' . $user['role'] . ' login: ' . $email;
                $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Authentication', ?, ?)");
                if ($audit) {
                    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                    $audit->bind_param("iss", $user['id'], $login_action, $ip_address);
                    $audit->execute();
                    $audit->close();
                }
            } catch (Throwable $e) {
                // Authentication remains available if audit logging is unavailable.
            }


            // Redirect based on their role
            if ($user['role'] === 'staff' || $user['role'] === 'admin') {
                header("Location: ../../admin_dashboard.php");
            } else {
                header("Location: ../../index.php");
            }
            exit();

        } else {
            // Password was wrong
            $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Incorrect password!', 'type' => 'error'];
            header("Location: ../../auth.php");
            exit();
        }
    } else {
        // Email not found
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Email not found!', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }

    $stmt->close();
}
$conn->close();
?>
