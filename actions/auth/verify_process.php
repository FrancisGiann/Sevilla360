<?php
require_once __DIR__ . '/../../includes/session_init.php';
require '../../config/db_connect.php';
require_once '../../includes/notifications.php';
require_once '../../includes/mailer.php';
require_once '../../includes/rate_limit.php';
require_once '../../includes/booking_intent.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {

// CSRF PROTECTION
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Security token expired. Please refresh the page and try again.', 'type' => 'error'];
        header("Location: ../../auth.php");
        exit();
    }
    
    $email = trim($_POST['email']);
    $code = trim($_POST['verification_code']);
    $otp_rate_key = 'verify_otp_' . substr(hash('sha256', strtolower($email)), 0, 48);

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
            check_rate_limit($conn, $otp_rate_key, 5, 15);
            $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'This verification code has expired. Please request a new one.', 'type' => 'error'];
            header("Location: ../../auth.php?verify_email=" . urlencode($email));
            exit();
        }

        // 3. Check if the code matches
        if ($code === $user['verification_code']) {
            $transaction_started = false;
            $transitioned = false;
            $display_name = 'Customer';
            try {
                if (!$conn->begin_transaction()) throw new RuntimeException('Unable to start verification transaction.');
                $transaction_started = true;

                // Only an active customer can transition, and the conditional
                // predicate makes concurrent OTP submissions mutually exclusive.
                $update_stmt = $conn->prepare("UPDATE users SET is_verified = TRUE, verification_code = NULL, verification_expires_at = NULL WHERE id = ? AND role = 'customer' AND status = 'active' AND is_verified = FALSE");
                if (!$update_stmt) throw new RuntimeException('Unable to prepare verification update.');
                $update_stmt->bind_param("i", $user['id']);
                if (!$update_stmt->execute()) throw new RuntimeException('Unable to execute verification update.');

                if ($update_stmt->affected_rows === 1) {
                    $transitioned = true;
                } else {
                    // A concurrent request may have completed the transition.
                    // Authenticate only when the locked row proves that this is
                    // an active, verified customer; all other outcomes fail.
                    $state_stmt = $conn->prepare("SELECT is_verified, role, status FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
                    if (!$state_stmt) throw new RuntimeException('Unable to re-check verification state.');
                    $state_stmt->bind_param("i", $user['id']);
                    if (!$state_stmt->execute()) throw new RuntimeException('Unable to re-check verification state.');
                    $state = $state_stmt->get_result()->fetch_assoc();
                    if (!$state || (int)$state['is_verified'] !== 1 || $state['role'] !== 'customer' || strcasecmp((string)$state['status'], 'active') !== 0) {
                        throw new RuntimeException('Verification state did not transition.');
                    }
                }

                $name_stmt = $conn->prepare("SELECT first_name FROM customers WHERE user_id = ? LIMIT 1");
                if (!$name_stmt) throw new RuntimeException('Unable to load customer profile.');
                $name_stmt->bind_param("i", $user['id']);
                if (!$name_stmt->execute()) throw new RuntimeException('Unable to load customer profile.');
                $name_result = $name_stmt->get_result();
                if ($name_result->num_rows === 1) {
                    $customer_data = $name_result->fetch_assoc();
                    $display_name = trim((string)($customer_data['first_name'] ?? '')) ?: 'Customer';
                }

                // The conditional user transition owns the one-time welcome
                // notification. Keep it in the same transaction as verification.
                if ($transitioned) {
                    $welcome_title = 'Welcome to Sevilla360!';
                    $welcome_msg = 'Your account has been successfully verified. Welcome to Sevilla360! You can now explore our virtual showroom and use the available booking features.';
                    $check_notif = $conn->prepare('SELECT id FROM user_notifications WHERE user_id = ? AND title = ? LIMIT 1 FOR UPDATE');
                    if (!$check_notif) throw new RuntimeException('Unable to check welcome notification.');
                    $check_notif->bind_param('is', $user['id'], $welcome_title);
                    if (!$check_notif->execute()) throw new RuntimeException('Unable to check welcome notification.');
                    if ($check_notif->get_result()->num_rows === 0 && !create_user_notification($conn, $user['id'], $welcome_title, $welcome_msg)) {
                        throw new RuntimeException('Unable to create welcome notification.');
                    }
                }

                if (!$conn->commit()) throw new RuntimeException('Unable to commit verification.');
                $transaction_started = false;
            } catch (Throwable $verification_error) {
                if ($transaction_started) $conn->rollback();
                error_log('Account verification failed: ' . get_class($verification_error));
                $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Account verification could not be completed. Please try again.', 'type' => 'error'];
                header("Location: ../../auth.php?verify_email=" . urlencode($email));
                exit();
            }

            // Session authentication occurs only after the database proves a
            // successful transition or an already-verified active account.
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = 'customer';
            $_SESSION['logged_in'] = true;
            $_SESSION['first_name'] = $display_name;
            session_policy_mark_authenticated();

            if ($transitioned) {
                clear_rate_limit($conn, $otp_rate_key);
                try {
                    send_welcome_email($email, $display_name);
                } catch (Throwable $email_e) {
                    error_log('Welcome email delivery failed: ' . get_class($email_e));
                }
                $_SESSION['auth_alert'] = ['title' => 'Success', 'message' => 'Account verified successfully! Welcome to SEVILLA360.', 'type' => 'success'];
            } else {
                $_SESSION['auth_alert'] = ['title' => 'Notice', 'message' => 'This account was already verified. Please continue to your dashboard.', 'type' => 'info'];
            }
            $resume_target = booking_auth_consume_destination('customer');
            header("Location: ../../" . ($resume_target ?? 'user_dashboard.php'));
            exit();

        } else {
            // Code was wrong
            if (!check_rate_limit($conn, $otp_rate_key, 5, 15)) {
                $_SESSION['auth_alert'] = ['title' => 'Error', 'message' => 'Too many invalid verification attempts. Please request a new code later.', 'type' => 'error'];
                header("Location: ../../auth.php?verify_email=" . urlencode($email));
                exit();
            }
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
