<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['staff', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$name = trim($data['name'] ?? '');
$phone = trim($data['phone'] ?? '');
$currPass = $data['curr_pass'] ?? '';
$newPass = $data['new_pass'] ?? '';
$confPass = $data['conf_pass'] ?? '';
$uid = $_SESSION['user_id'];

if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Full name cannot be empty.']);
    exit;
}

$conn->begin_transaction();

try {
    // 1. Update Full Name and Phone in staff table
    $stmt_name = $conn->prepare("UPDATE staff SET full_name = ?, phone = ? WHERE user_id = ?");
    $stmt_name->bind_param("ssi", $name, $phone, $uid);
    $stmt_name->execute();

    // 2. Update Password if provided
    if (!empty($currPass) || !empty($newPass) || !empty($confPass)) {
        if (empty($currPass) || empty($newPass) || empty($confPass)) {
            throw new Exception("Please fill out all password fields if you wish to change your password.");
        }
        if ($newPass !== $confPass) {
            throw new Exception("New password and confirm password do not match.");
        }

        // Verify current password
        $stmt_check = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt_check->bind_param("i", $uid);
        $stmt_check->execute();
        $user = $stmt_check->get_result()->fetch_assoc();

        if (!$user || !password_verify($currPass, $user['password_hash'])) {
            throw new Exception("Current password is incorrect.");
        }

        $newHash = password_hash($newPass, PASSWORD_DEFAULT);
        $stmt_pass = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt_pass->bind_param("si", $newHash, $uid);
        $stmt_pass->execute();
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Profile updated successfully!']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
