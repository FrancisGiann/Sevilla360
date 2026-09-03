<?php
// Start the session so we can remember the user after they log in
require_once __DIR__ . '/../../includes/session_init.php';

// Connect to the database
require '../../config/db_connect.php';
require_once '../../includes/rate_limit.php';
require_once '../../includes/request_context.php';
require_once '../../includes/customer_login_recovery.php';
require_once '../../includes/booking_intent.php';

const LOGIN_GENERIC_CREDENTIAL_ERROR = 'Invalid email or password.';
const LOGIN_DUMMY_PASSWORD_HASH = '$2y$10$9sTJDhVVvlZJODtYDMceGOw3XrXaMOu6lifw216.R.eAwDb6XXZmy';

// Check if the form was actually submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // CSRF PROTECTION FOR FORMS
    $session_csrf_token = $_SESSION['csrf_token'] ?? null;
    $submitted_csrf_token = $_POST['csrf_token'] ?? null;
    if (!is_string($session_csrf_token) || $session_csrf_token === ''
        || !is_string($submitted_csrf_token) || $submitted_csrf_token === ''
        || !hash_equals($session_csrf_token, $submitted_csrf_token)) {
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
    
    // Grab and validate the data from the HTML form's 'name' attributes.
    // Invalid input is a generic failure but is not an incorrect credential
    // attempt for the customer recovery nudge.
    $email_input = $_POST['email'] ?? null;
    $password_input = $_POST['password'] ?? null;
    $email = is_string($email_input) ? trim($email_input) : '';
    $password = is_string($password_input) ? $password_input : '';
    $login_type_input = $_POST['login_type'] ?? null;
    $login_type = is_string($login_type_input) ? $login_type_input : '';
    $is_customer_login = $login_type === 'customer';
    if (!in_array($login_type, ['customer', 'admin'], true) || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => LOGIN_GENERIC_CREDENTIAL_ERROR, 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }

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

    $user = $result->num_rows === 1 ? $result->fetch_assoc() : null;
    $has_usable_password_hash = is_array($user)
        && is_string($user['password_hash'] ?? null)
        && $user['password_hash'] !== '';
    // Always invoke password_verify, including for unknown accounts, so the
    // nonexistent-email path does not skip the expensive password hash work.
    $password_hash = $has_usable_password_hash ? $user['password_hash'] : LOGIN_DUMMY_PASSWORD_HASH;
    $password_matches = password_verify($password, $password_hash);
    $password_verified = $has_usable_password_hash && $password_matches;

    if (!$password_verified) {
        if ($is_customer_login) customer_login_recovery_record_failure();
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => LOGIN_GENERIC_CREDENTIAL_ERROR, 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }

    // A password match proves ownership, but a role/login-type mismatch still
    // receives the generic response so portal membership is not disclosed.
    $role_matches_login = ($login_type === 'admin' && in_array($user['role'], ['admin', 'staff'], true))
        || ($login_type === 'customer' && $user['role'] === 'customer');
    if (!$role_matches_login) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => LOGIN_GENERIC_CREDENTIAL_ERROR, 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }

    // Account-specific status messages are reachable only after the submitted
    // password has been verified and the login portal matches the role.
    if ((int)$user['is_verified'] === 0) {
        $_SESSION['auth_alert'] = ['title' => 'Notice', 'message' => 'Please verify your email address first!', 'type' => 'warning'];
        // Reopen the verification dialog after an interrupted attempt so the
        // customer can enter or resend a code without registering again.
        header("Location: ../../auth.php?verify_email=" . urlencode($email));
        exit();
    }

    $account_status = in_array($user['role'], ['admin', 'staff'], true)
        ? ($user['staff_status'] ?? '')
        : ($user['user_status'] ?? '');
    if (strcasecmp((string)$account_status, 'active') !== 0) {
        $_SESSION['auth_alert'] = [
            'title' => 'Account unavailable',
            'message' => 'This account is suspended or inactive. Please contact an administrator.',
            'type' => 'error'
        ];
        header("Location: ../../auth.php");
        exit();
    }

    // Password and account state are valid at this point. Only a successful
    // customer login clears the customer-specific nudge state.
    if ($is_customer_login) customer_login_recovery_clear();
            
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
            session_policy_mark_authenticated();

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
                    $ip_address = request_client_ip();
                    $audit->bind_param("iss", $user['id'], $login_action, $ip_address);
                    $audit->execute();
                    $audit->close();
                }
            } catch (Throwable $e) {
                // Authentication remains available if audit logging is unavailable.
            }


            // Redirect based on their role
            if ($user['role'] === 'staff' || $user['role'] === 'admin') {
                booking_auth_clear_destination();
                header("Location: ../../admin_dashboard.php");
            } elseif ($user['role'] === 'customer') {
                $resume_target = booking_auth_consume_destination('customer');
                header("Location: ../../" . ($resume_target ?? 'user_dashboard.php'));
            } else {
                header("Location: ../../index.php");
            }
            exit();

    $stmt->close();
}
$conn->close();
?>
