<?php
// actions/admin/manage_staff.php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/request_context.php';
require_once __DIR__ . '/../../includes/password_policy.php';

final class StaffManagementException extends RuntimeException
{
    public function __construct(string $message, private int $statusCode = 422)
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}

function staff_management_error(int $statusCode, string $message): never
{
    http_response_code($statusCode);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    staff_management_error(405, 'Only POST requests are accepted.');
}

function staff_management_prepare(mysqli $conn, string $sql): mysqli_stmt
{
    $statement = $conn->prepare($sql);
    if (!$statement) throw new RuntimeException('Unable to prepare the staff account query.');
    return $statement;
}

function staff_management_string(array $data, string $key, bool $trim = true): string
{
    $value = $data[$key] ?? '';
    if (!is_string($value)) throw new StaffManagementException('Invalid staff account data.');
    return $trim ? trim($value) : $value;
}

function staff_management_user_id(array $data): int
{
    $value = $data['user_id'] ?? null;
    if (is_int($value)) $userId = $value;
    elseif (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value)) $userId = (int)$value;
    else $userId = 0;
    if ($userId < 1) throw new StaffManagementException('A valid staff account is required.');
    return $userId;
}

function staff_management_optional_field(array $data, string $key, int $maxLength, string $label): ?string
{
    $value = $data[$key] ?? '';
    if ($value === null) return null;
    if (!is_string($value)) throw new StaffManagementException('Invalid staff account data.');
    $value = trim($value);
    if ($value === '') return null;
    if (strlen($value) > $maxLength) {
        throw new StaffManagementException("{$label} must be {$maxLength} characters or fewer.");
    }
    return $value;
}

function staff_management_hire_date(array $data): ?string
{
    $value = $data['hire_date'] ?? '';
    if ($value === null) return null;
    if (!is_string($value)) throw new StaffManagementException('Invalid staff account data.');
    $value = trim($value);
    if ($value === '') return null;
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    $hasDateErrors = is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
    if (!$date || $hasDateErrors || $date->format('Y-m-d') !== $value || $value > date('Y-m-d')) {
        throw new StaffManagementException('Hire date must be a valid date that is not in the future.');
    }
    return $value;
}

function staff_management_validate_profile(array $data): array
{
    $name = staff_management_string($data, 'name');
    $email = staff_management_string($data, 'email');
    $phone = staff_management_optional_field($data, 'phone', 20, 'Work phone');
    $address = staff_management_optional_field($data, 'address', 255, 'Residential address');
    $department = staff_management_optional_field($data, 'department', 100, 'Department');
    $jobTitle = staff_management_optional_field($data, 'job_title', 100, 'Job title');
    $hireDate = staff_management_hire_date($data);

    if ($name === '' || strlen($name) > 150) {
        throw new StaffManagementException('Full name is required and must be 150 characters or fewer.');
    }
    if (strlen($email) > 254) {
        throw new StaffManagementException('Work email must be 254 characters or fewer.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new StaffManagementException('Enter a valid work email address.');
    }
    if ($phone !== null && !preg_match('/\A[0-9+().\-\s]{1,20}\z/D', $phone)) {
        throw new StaffManagementException('Work phone may contain only numbers, spaces, and + ( ) . - characters.');
    }

    return [
        'name' => $name,
        'email' => $email,
        // User Management can create staff accounts only; administrator
        // accounts are provisioned outside this endpoint.
        'role' => 'staff',
        'phone' => $phone,
        'address' => $address,
        'department' => $department,
        'job_title' => $jobTitle,
        'hire_date' => $hireDate,
    ];
}

function staff_management_find_target(mysqli $conn, int $userId, bool $forUpdate = true): array
{
    $sql = "SELECT u.email, u.role, s.full_name, s.status
            FROM users u
            INNER JOIN staff s ON s.user_id = u.id
            WHERE u.id = ? AND u.role = 'staff'
            LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
    $statement = staff_management_prepare($conn, $sql);
    $statement->bind_param('i', $userId);
    if (!$statement->execute()) throw new RuntimeException('Unable to load the staff account.');
    $target = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!$target) throw new StaffManagementException('Staff account not found.', 404);
    return $target;
}

function staff_management_audit(mysqli $conn, string $action): void
{
    $statement = staff_management_prepare($conn, "INSERT INTO audit_logs (user_id, module, action, ip_address)
                                                   VALUES (?, 'User Management', ?, ?)");
    $adminId = (int)($_SESSION['user_id'] ?? 0);
    $ipAddress = request_client_ip();
    $statement->bind_param('iss', $adminId, $action, $ipAddress);
    if (!$statement->execute()) throw new RuntimeException('Unable to record the staff account audit entry.');
    $statement->close();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    staff_management_error(403, 'Unauthorized.');
}

$clientCsrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionCsrfToken = $_SESSION['csrf_token'] ?? '';
if (!is_string($clientCsrfToken) || !is_string($sessionCsrfToken)
    || $sessionCsrfToken === '' || !hash_equals($sessionCsrfToken, $clientCsrfToken)) {
    staff_management_error(403, 'CSRF validation failed. Unauthorized request.');
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);
if (!is_array($data)) staff_management_error(422, 'Invalid JSON request.');

$action = $data['action'] ?? '';
if (!is_string($action)) staff_management_error(422, 'Invalid staff action.');
if ($action === 'delete') {
    staff_management_error(422, 'Staff accounts cannot be deleted. Archive the account to retain its records.');
}
if ($action === 'edit') {
    staff_management_error(422, 'Staff profiles are read-only in User Management. Staff may update their own name, phone, and password from Settings.');
}
if (!in_array($action, ['add', 'archive', 'restore'], true)) {
    staff_management_error(422, 'Invalid staff action.');
}
if ($action === 'add' && array_key_exists('role', $data)) {
    $requestedRole = $data['role'];
    if (!is_string($requestedRole) || strcasecmp(trim($requestedRole), 'staff') !== 0) {
        staff_management_error(422, 'Only staff accounts can be created from User Management.');
    }
}

$transactionStarted = false;
try {
    $conn->begin_transaction();
    $transactionStarted = true;

    if ($action === 'add') {
        $profile = staff_management_validate_profile($data);
        $passwordValue = $data['password'] ?? '';
        if ($passwordValue === null) $password = '';
        elseif (is_string($passwordValue)) $password = $passwordValue;
        else throw new StaffManagementException('Invalid staff account data.');
        if ($action === 'add' || $password !== '') {
            $passwordPolicy = password_policy_validate($password);
            if (!$passwordPolicy['valid']) throw new StaffManagementException($passwordPolicy['message']);
        }

        $duplicateStatement = staff_management_prepare($conn, 'SELECT id FROM users WHERE email = ? LIMIT 1');
        $duplicateStatement->bind_param('s', $profile['email']);
        if (!$duplicateStatement->execute()) throw new RuntimeException('Unable to check the work email address.');
        if ($duplicateStatement->get_result()->num_rows > 0) {
            throw new StaffManagementException('That work email address is already in use.', 409);
        }
        $duplicateStatement->close();

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $userStatement = staff_management_prepare($conn, 'INSERT INTO users (email, password_hash, role, is_verified, status) VALUES (?, ?, \'staff\', 1, \'active\')');
        $userStatement->bind_param('ss', $profile['email'], $hash);
        if (!$userStatement->execute()) throw new RuntimeException('Unable to create the staff login.');
        $newUserId = $conn->insert_id;
        $userStatement->close();

        $staffStatement = staff_management_prepare($conn, 'INSERT INTO staff (user_id, full_name, phone, address, department, job_title, hire_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, \'active\')');
        $staffStatement->bind_param('issssss', $newUserId, $profile['name'], $profile['phone'], $profile['address'], $profile['department'], $profile['job_title'], $profile['hire_date']);
        if (!$staffStatement->execute()) throw new RuntimeException('Unable to create the staff profile.');
        $staffStatement->close();

        staff_management_audit($conn, "Created staff account {$profile['email']}");
        $message = 'Staff account added successfully.';
    } else {
        $userId = staff_management_user_id($data);
        if ($action === 'archive' && $userId === (int)($_SESSION['user_id'] ?? 0)) {
            throw new StaffManagementException('You cannot archive your current account.', 409);
        }
        $target = staff_management_find_target($conn, $userId);
        if ($action === 'archive') {
            if ($target['status'] !== 'active') throw new StaffManagementException('That staff account is already archived.', 409);
            $staffStatement = staff_management_prepare($conn, "UPDATE staff SET status = 'inactive', archived_at = NOW() WHERE user_id = ? AND status = 'active'");
            $staffStatement->bind_param('i', $userId);
            if (!$staffStatement->execute() || $staffStatement->affected_rows !== 1) throw new RuntimeException('Unable to archive the staff account.');
            $staffStatement->close();
            staff_management_audit($conn, "Archived staff account {$target['full_name']} ({$target['email']}, {$userId})");
            $message = 'Staff account archived. Sign-in access is disabled and records are retained.';
        } else {
            if ($target['status'] === 'active') throw new StaffManagementException('That staff account is already active.', 409);
            $staffStatement = staff_management_prepare($conn, "UPDATE staff SET status = 'active', archived_at = NULL WHERE user_id = ? AND status = 'inactive'");
            $staffStatement->bind_param('i', $userId);
            if (!$staffStatement->execute() || $staffStatement->affected_rows !== 1) throw new RuntimeException('Unable to restore the staff account.');
            $staffStatement->close();
            staff_management_audit($conn, "Restored staff account {$target['full_name']} ({$target['email']}, {$userId})");
            $message = 'Staff account restored and sign-in access is active.';
        }
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => $message], JSON_UNESCAPED_SLASHES);
} catch (StaffManagementException $e) {
    if ($transactionStarted) $conn->rollback();
    staff_management_error($e->statusCode(), $e->getMessage());
} catch (Throwable $e) {
    if ($transactionStarted) $conn->rollback();
    error_log('Staff management action failed: ' . get_class($e) . ': ' . $e->getMessage());
    staff_management_error(500, 'The staff account could not be saved. Please try again.');
}
