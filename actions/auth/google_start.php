<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../includes/google_oauth.php';

$config = google_oauth_config();
if ($config === null || !google_oauth_library_available()) {
    $_SESSION['auth_alert'] = ['title' => 'Google sign-in unavailable', 'message' => 'Google sign-in is not configured on this deployment.', 'type' => 'warning'];
    header('Location: ../../auth.php');
    exit;
}

$_SESSION['google_oauth_state'] = bin2hex(random_bytes(32));
$_SESSION['google_oauth_nonce'] = bin2hex(random_bytes(32));
$_SESSION['google_oauth_started_at'] = time();
$client = google_oauth_client($config);
$client->setState($_SESSION['google_oauth_state']);
$auth_url = $client->createAuthUrl();
$auth_url .= (str_contains($auth_url, '?') ? '&' : '?') . 'nonce=' . rawurlencode($_SESSION['google_oauth_nonce']);
header('Location: ' . $auth_url);
exit;
