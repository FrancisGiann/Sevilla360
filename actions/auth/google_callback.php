<?php
require_once __DIR__ . '/../../includes/session_init.php';
require '../../config/db_connect.php';
require_once '../../includes/google_oauth.php';
require_once '../../includes/notifications.php';
require_once '../../includes/request_context.php';

function google_oauth_fail(string $message = 'Google sign-in could not be completed.'): never
{
    $_SESSION['auth_alert'] = ['title' => 'Google sign-in failed', 'message' => $message, 'type' => 'error'];
    header('Location: ../../auth.php');
    exit;
}

$config = google_oauth_config();
$state = (string)($_GET['state'] ?? '');
$expected_state = (string)($_SESSION['google_oauth_state'] ?? '');
$nonce = (string)($_SESSION['google_oauth_nonce'] ?? '');
$started_at = (int)($_SESSION['google_oauth_started_at'] ?? 0);
unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_nonce'], $_SESSION['google_oauth_started_at']);
if ($config === null || $state === '' || $expected_state === '' || !hash_equals($expected_state, $state) || $started_at < 1 || time() - $started_at > 600) google_oauth_fail('The Google sign-in session expired. Please try again.');
if (!empty($_GET['error']) || empty($_GET['code'])) google_oauth_fail('Google sign-in was cancelled or denied.');

try {
    $client = google_oauth_client($config);
    $token = $client->fetchAccessTokenWithAuthCode((string)$_GET['code']);
    if (!empty($token['error']) || empty($token['id_token'])) throw new RuntimeException('Google did not return a valid token.');
    $claims = $client->verifyIdToken((string)$token['id_token']);
    if (!is_array($claims)) throw new RuntimeException('Google ID token signature verification failed.');
    if (!google_oauth_claims_valid($claims, $config, $nonce)) throw new RuntimeException('Google identity claims failed validation.');

    $email = strtolower(trim((string)$claims['email']));
    $subject = (string)$claims['sub'];
    $first_name = trim((string)($claims['given_name'] ?? ''));
    $last_name = trim((string)($claims['family_name'] ?? ''));
    if ($first_name === '') $first_name = trim(explode(' ', (string)($claims['name'] ?? 'Customer'), 2)[0]);
    if ($last_name === '') $last_name = 'Customer';

    $conn->begin_transaction();
    // Google `sub` is the stable identity key. Prefer it before email so an
    // address change cannot create a second local account.
    $stmt_user = $conn->prepare('SELECT id, email, role, status, is_verified, google_subject FROM users WHERE google_subject = ? LIMIT 1 FOR UPDATE');
    $stmt_user->bind_param('s', $subject);
    $stmt_user->execute();
    $user = $stmt_user->get_result()->fetch_assoc();
    $subject_linked = (bool)$user;
    if ($user) $email = strtolower((string)$user['email']);
    if (!$user) {
        $stmt_user = $conn->prepare('SELECT id, email, role, status, is_verified, google_subject FROM users WHERE email = ? LIMIT 1 FOR UPDATE');
        $stmt_user->bind_param('s', $email);
        $stmt_user->execute();
        $user = $stmt_user->get_result()->fetch_assoc();
    }
    $is_new = false;
    if ($user) {
        if ((string)$user['role'] !== 'customer' || strcasecmp((string)$user['status'], 'active') !== 0 || (int)$user['is_verified'] !== 1) throw new RuntimeException('This email is not eligible for customer Google sign-in.');
        if (!empty($user['google_subject']) && !hash_equals((string)$user['google_subject'], $subject)) throw new RuntimeException('This Google identity is not linked to the account.');
        $user_id = (int)$user['id'];
        if (empty($user['google_subject'])) {
            $link = $conn->prepare('UPDATE users SET google_subject = ? WHERE id = ? AND google_subject IS NULL');
            $link->bind_param('si', $subject, $user_id);
            if (!$link->execute() || $link->affected_rows !== 1) throw new RuntimeException('The Google identity could not be linked safely.');
        }
    } else {
        $stmt_insert = $conn->prepare("INSERT INTO users (email, password_hash, role, is_verified, status, google_subject, consented_at) VALUES (?, NULL, 'customer', 1, 'active', ?, NOW())");
        $stmt_insert->bind_param('ss', $email, $subject);
        if (!$stmt_insert->execute()) throw new RuntimeException('Unable to create the customer account.');
        $user_id = (int)$conn->insert_id;
        $is_new = true;
    }

    $stmt_customer = $subject_linked
        ? $conn->prepare('SELECT id, user_id FROM customers WHERE user_id = ? LIMIT 1 FOR UPDATE')
        : $conn->prepare('SELECT id, user_id FROM customers WHERE email = ? LIMIT 1 FOR UPDATE');
    if ($subject_linked) $stmt_customer->bind_param('i', $user_id);
    else $stmt_customer->bind_param('s', $email);
    $stmt_customer->execute();
    $customer = $stmt_customer->get_result()->fetch_assoc();
    if ($customer && !empty($customer['user_id']) && (int)$customer['user_id'] !== $user_id) throw new RuntimeException('This email is already linked to another customer profile.');
    if ($customer) {
        $customer_update = $conn->prepare('UPDATE customers SET user_id = ?, first_name = ?, last_name = ? WHERE id = ? AND (user_id IS NULL OR user_id = ?)');
        $customer_id = (int)$customer['id'];
        $customer_update->bind_param('issii', $user_id, $first_name, $last_name, $customer_id, $user_id);
        if (!$customer_update->execute()) throw new RuntimeException('Unable to link the customer profile.');
    } else {
        $customer_insert = $conn->prepare('INSERT INTO customers (user_id, first_name, last_name, email) VALUES (?, ?, ?, ?)');
        $customer_insert->bind_param('isss', $user_id, $first_name, $last_name, $email);
        if (!$customer_insert->execute()) throw new RuntimeException('Unable to create the customer profile.');
    }
    if ($is_new) create_user_notification($conn, $user_id, 'Welcome to Sevilla360!', 'Your Google account has been verified. Welcome to Sevilla360! You can now explore our virtual showroom and booking features.');
    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Authentication', 'Successful Google customer login', ?)");
    if ($audit) { $ip = request_client_ip(); $audit->bind_param('is', $user_id, $ip); $audit->execute(); }
    if (!$conn->commit()) throw new RuntimeException('Unable to complete Google sign-in.');

    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['user_id'] = $user_id;
    $_SESSION['role'] = 'customer';
    $_SESSION['logged_in'] = true;
    $_SESSION['first_name'] = $first_name !== '' ? $first_name : 'Customer';
    header('Location: ../../user_dashboard.php');
    exit;
} catch (Throwable $error) {
    $conn->rollback();
    error_log('Google OAuth callback failed: ' . get_class($error));
    google_oauth_fail();
}
