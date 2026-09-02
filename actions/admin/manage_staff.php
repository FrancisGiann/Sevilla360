<?php
// actions/admin/manage_staff.php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/request_context.php';
require_once __DIR__ . '/../../includes/password_policy.php';

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
if (!is_array($data)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON request.']);
    exit;
}
$action = $data['action'] ?? '';
if (!in_array($action, ['add', 'edit', 'delete'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid staff action.']);
    exit;
}
if ($action === 'add' || $action === 'edit') {
    $inputName = trim((string)($data['name'] ?? ''));
    $inputEmail = trim((string)($data['email'] ?? ''));
    $inputRole = (string)($data['role'] ?? '');
    $inputStatus = (string)($data['status'] ?? '');
    $inputPassword = (string)($data['password'] ?? '');
    if ($inputName === '' || strlen($inputName) > 150 || !filter_var($inputEmail, FILTER_VALIDATE_EMAIL) || !in_array($inputRole, ['admin', 'staff'], true) || !in_array($inputStatus, ['active', 'inactive'], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid staff name, email, role, or status.']);
        exit;
    }
    $password_policy = ($action === 'add' || $inputPassword !== '')
        ? password_policy_validate($inputPassword)
        : ['valid' => true, 'message' => ''];
    if (!$password_policy['valid']) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $password_policy['message']]);
        exit;
    }
} elseif (!filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid staff account is required.']);
    exit;
}

try {
    $conn->begin_transaction();

    if ($action === 'add' || $action === 'edit') {
        $name = trim((string)($data['name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $role = (string)($data['role'] ?? '');
        $status = (string)($data['status'] ?? '');
        $password = (string)($data['password'] ?? '');

        if ($name === '' || strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("A valid name and email are required.");
        if (!in_array($role, ['admin', 'staff'], true) || !in_array($status, ['active', 'inactive'], true)) throw new Exception("Invalid staff role or status.");

        if ($action === 'add') {
            // Check if email exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) throw new Exception("Email already exists.");

            $password_policy = password_policy_validate($password);
            if (!$password_policy['valid']) throw new Exception($password_policy['message']);
            
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
            $user_id = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$user_id) throw new Exception('A valid staff account is required.');
            
            // Superadmin Safeguard for Demotion
            $check = $conn->prepare("SELECT role FROM users WHERE id = ?");
            $check->bind_param("i", $user_id);
            $check->execute();
            $current_user = $check->get_result()->fetch_assoc();
            if (!$current_user) throw new Exception('Staff account not found.');
            $current_role = $current_user['role'];
            
            if ($current_role === 'admin' && $role !== 'admin') {
                $count = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 'admin'")->fetch_assoc()['c'];
                if ($count <= 1) {
                    throw new Exception("Action blocked: System must have at least one admin.");
                }
            }

            // Update User
            if ($password !== '') {
                $password_policy = password_policy_validate($password);
                if (!$password_policy['valid']) throw new Exception($password_policy['message']);
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
        $user_id = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$user_id) throw new Exception('A valid staff account is required.');
        if ($user_id === $_SESSION['user_id']) throw new Exception("You cannot delete yourself.");

        // Superadmin Safeguard for Deletion
        $check = $conn->prepare("SELECT role FROM users WHERE id = ?");
        $check->bind_param("i", $user_id);
        $check->execute();
        $target_user = $check->get_result()->fetch_assoc();
        if (!$target_user) throw new Exception('Staff account not found.');
        $target_role = $target_user['role'];

        if ($target_role === 'admin') {
            $count = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 'admin'")->fetch_assoc()['c'];
            if ($count <= 1) {
                throw new Exception("Deletion blocked: System must have at least one admin.");
            }
        }

        // Get user details for logging before deleting
        $stmt_info = $conn->prepare("
            SELECT u.email, s.full_name 
            FROM users u
            LEFT JOIN staff s ON u.id = s.user_id
            WHERE u.id = ?
        ");
        $stmt_info->bind_param("i", $user_id);
        $stmt_info->execute();
        $user_info = $stmt_info->get_result()->fetch_assoc();
        $email = $user_info['email'] ?? 'Unknown Email';
        $staff_name = $user_info['full_name'] ?? 'Unknown Name';

        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?"); // Cascade deletes staff table
        $stmt->bind_param("i", $user_id);
        $stmt->execute();

        $log_action = "Deleted staff account: $staff_name ($email, user ID: $user_id)";
        $message = "Staff deleted successfully.";
    }

    // AUDIT LOG
    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'User Management', ?, ?)");
        $audit_ip = request_client_ip();
        $audit->bind_param("iss", $_SESSION['user_id'], $log_action, $audit_ip);
    $audit->execute();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => $message]);

} catch (Exception $e) {
    $conn->rollback();
    error_log('Staff management action failed: ' . get_class($e));
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
