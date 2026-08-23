<?php
/** Central session policy. Include this file before any session_start call. */
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/request_context.php';
if (session_status() === PHP_SESSION_NONE) {
    $directHttps = isset($_SERVER['HTTPS']) && in_array(strtolower((string)$_SERVER['HTTPS']), ['on', '1', 'https'], true);
    $trustedProxyHttps = false;
    if (!$directHttps && request_peer_is_trusted(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')))) {
        $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''), 2)[0]));
        $trustedProxyHttps = $forwardedProto === 'https';
    }
    $https = $directHttps || $trustedProxyHttps;
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
