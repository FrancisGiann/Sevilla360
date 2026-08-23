<?php
// actions/user/save_settings.php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}
// ==========================================
// CSRF PROTECTION GUARD (JSON)
// ==========================================
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed. Unauthorized request.']);
    exit;
}

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

try {
    if ($action === 'update_profile') {
        $fname = trim($data['fname']);
        $lname = trim($data['lname']);
        $phone = trim($data['phone']);
        if (empty($fname) || empty($lname)) {
            throw new Exception("First and Last name are required.");
        }

        $stmt = $conn->prepare("UPDATE customers SET first_name = ?, last_name = ?, phone = ? WHERE user_id = ?");
        $stmt->bind_param("sssi", $fname, $lname, $phone, $user_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update profile.");
        }

        echo json_encode(['success' => true, 'message' => 'Profile updated successfully!']);
    }
    
    elseif ($action === 'update_prefs') {
        $prefs = trim($data['prefs']);

        $stmt = $conn->prepare("UPDATE customers SET special_req = ? WHERE user_id = ?");
        $stmt->bind_param("si", $prefs, $user_id);
        if (!$stmt->execute()) throw new Exception("Failed to update preferences.");

        echo json_encode(['success' => true, 'message' => 'Preferences saved successfully!']);
    }

    elseif ($action === 'update_password') {
        $old_pass = (string) ($data['old_pass'] ?? '');
        $new_pass = (string) ($data['new_pass'] ?? '');
        $confirm_pass = (string) ($data['confirm_pass'] ?? '');

        if ($old_pass === '' || $new_pass === '' || $confirm_pass === '') {
            throw new Exception("Current password, new password, and confirmation are required.");
        }
        if (strlen($new_pass) < 8) {
        throw new Exception("New password must be at least 8 characters.");
        }
        if (!hash_equals($new_pass, $confirm_pass)) {
            throw new Exception("New password and confirmation do not match.");
        }

        // Fetch current password hash
        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user || !password_verify($old_pass, $user['password_hash'])) {
            throw new Exception("Incorrect current password.");
        }

        // Hash new password and save
        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $stmt_update = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt_update->bind_param("si", $new_hash, $user_id);
        $stmt_update->execute();

        echo json_encode(['success' => true, 'message' => 'Password updated securely!']);
    } 
    
    else {
        throw new Exception("Invalid action.");
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
