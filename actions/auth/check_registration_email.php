<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../includes/rate_limit.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function registration_email_reply(int $status, string $state): void
{
    http_response_code($status);
    echo json_encode(['state' => $state]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') registration_email_reply(405, 'error');

$sessionToken = $_SESSION['csrf_token'] ?? null;
$requestToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!is_string($sessionToken) || !is_string($requestToken) || $sessionToken === ''
    || !hash_equals($sessionToken, $requestToken)) {
    registration_email_reply(403, 'error');
}

$email = trim((string)($_POST['email'] ?? ''));
if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    registration_email_reply(422, 'error');
}

try {
    require __DIR__ . '/../../config/db_connect.php';
    // A separate, durable IP budget limits this intentional existence check.
    if (!check_rate_limit($conn, 'register_email_check', 12, 15)) {
        registration_email_reply(429, 'error');
    }
    // Match register_process.php's 24-hour cleanup rule without mutating data.
    $stmt = $conn->prepare('SELECT 1 FROM users WHERE LOWER(email) = LOWER(?)'
        . ' AND (is_verified = 1 OR verification_expires_at IS NULL'
        . ' OR verification_expires_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $unavailable = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    registration_email_reply(200, $unavailable ? 'unavailable' : 'available');
} catch (Throwable $error) {
    registration_email_reply(503, 'error');
}
