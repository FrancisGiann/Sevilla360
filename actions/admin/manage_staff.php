<?php
// actions/admin/manage_staff.php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
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

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

try {
    $conn->begin_transaction();

    if ($action === 'add' || $action === 'edit') {
        $name = trim($data['name']);
        $email = trim($data['email']);
        $role = $data['role'];
        $status = $data['status'];
        $password = $data['password'];

        if (empty($name) || empty($email)) throw new Exception("Name and email are required.");

        if ($action === 'add') {
            // Check if email exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) throw new Exception("Email already exists.");

            if (empty($password)) throw new Exception("Password is required for new staff.");
            
            $hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert User
            $stmt_u = $conn->prepare("INSERT INTO users (email, password_hash, role, is_verified) VALUES (?, ?, ?, 1)");
            $stmt_u->bind_param("sss", $email, $hash, $role);
            $stmt_u->execute();
            $new_user_id = $conn->insert_id;

            // Insert Staff
            $stmt_s = $conn->prepare("INSERT INTO staff (user_id, full_name, status) VALUES (?, ?, ?)");
            $stmt_s->bind_param("iss", $new_user_id, $name, $status);
            $stmt_s->execute();

            $log_action = "Created new staff account: $email ($role)";
            $message = "Staff added successfully.";

        } else {
            // EDIT EXISTING
            $user_id = intval($data['user_id']);
            
            // Superadmin Safeguard for Demotion
            $check = $conn->prepare("SELECT role FROM users WHERE id = ?");
            $check->bind_param("i", $user_id);
            $check->execute();
            $current_role = $check->get_result()->fetch_assoc()['role'];
            
            if ($current_role === 'admin' && $role !== 'admin') {
                $count = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 'admin'")->fetch_assoc()['c'];
                if ($count <= 1) {
                    throw new Exception("Action blocked: System must have at least one admin.");
                }
            }

            // Update User
            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt_u = $conn->prepare("UPDATE users SET email = ?, role = ?, password_hash = ? WHERE id = ?");
                $stmt_u->bind_param("sssi", $email, $role, $hash, $user_id);
            } else {
                $stmt_u = $conn->prepare("UPDATE users SET email = ?, role = ? WHERE id = ?");
                $stmt_u->bind_param("ssi", $email, $role, $user_id);
            }
            $stmt_u->execute();

            // Update Staff
            $stmt_s = $conn->prepare("UPDATE staff SET full_name = ?, status = ? WHERE user_id = ?");
            $stmt_s->bind_param("ssi", $name, $status, $user_id);
            $stmt_s->execute();

            $log_action = "Updated staff account: $email";
            $message = "Staff updated successfully.";
        }
    } 
    elseif ($action === 'delete') {
        $user_id = intval($data['user_id']);
        if ($user_id === $_SESSION['user_id']) throw new Exception("You cannot delete yourself.");

        // Superadmin Safeguard for Deletion
        $check = $conn->prepare("SELECT role FROM users WHERE id = ?");
        $check->bind_param("i", $user_id);
        $check->execute();
        $target_role = $check->get_result()->fetch_assoc()['role'];

        if ($target_role === 'admin') {
            $count = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 'admin'")->fetch_assoc()['c'];
            if ($count <= 1) {
                throw new Exception("Deletion blocked: System must have at least one admin.");
            }
        }

        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?"); // Cascade deletes staff table
        $stmt->bind_param("i", $user_id);
        $stmt->execute();

        $log_action = "Deleted staff user_id: $user_id";
        $message = "Staff deleted successfully.";
    }

    // AUDIT LOG
    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'User Management', ?, ?)");
    $audit->bind_param("iss", $_SESSION['user_id'], $log_action, $_SERVER['REMOTE_ADDR']);
    $audit->execute();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => $message]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>