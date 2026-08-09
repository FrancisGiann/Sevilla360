<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require '../../config/db_connect.php';

// ==========================================
// CSRF PROTECTION GUARD (TEXT)
// ==========================================
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo "Error: CSRF validation failed. Unauthorized request.";
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['email'])) {
    $email = trim($_POST['email']);
    
    // 1. Generate a new secure 6-digit code
    $new_code = sprintf("%06d", random_int(1, 999999));
    $new_expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    // 2. Update the database ONLY if the account exists and is NOT verified yet
    $stmt = $conn->prepare("UPDATE users SET verification_code = ?, verification_expires_at = ? WHERE email = ? AND is_verified = FALSE");
    $stmt->bind_param("sss", $new_code, $new_expires_at, $email);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        // Success! In production, use PHPMailer to email $new_code to $email here.
        // For our test, we just echo the code back to the JavaScript so it can alert it.
        echo $new_code;
    } else {
        echo "Error: Could not resend. Account may already be verified.";
    }

    $stmt->close();
}
$conn->close();
?>