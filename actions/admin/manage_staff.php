<?php
// actions/admin/manage_staff.php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/session_account_guard.php';
require_once __DIR__ . '/../../includes/request_context.php';
require_once __DIR__ . '/../../includes/password_policy.php';
require_once __DIR__ . '/../../includes/rate_limit.php';
require_once __DIR__ . '/../../includes/staff_invitation.php';

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
        'email' => strtolower($email),
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
    // Every lifecycle/promotion target must still be Staff. This excludes all
    // Admins, including the only active Admin; directory visibility grants no access.
    $sql = "SELECT u.email, u.role, u.is_verified, s.full_name, s.status
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

function staff_management_current_admin_password(mysqli $conn): string
{
    $adminId = (int)($_SESSION['user_id'] ?? 0);
    $statement = staff_management_prepare($conn, "SELECT u.password_hash
                                                   FROM users u
                                                   INNER JOIN staff s ON s.user_id = u.id
                                                   WHERE u.id = ? AND u.role = 'admin' AND s.status = 'active'
                                                   LIMIT 1");
    $statement->bind_param('i', $adminId);
    if (!$statement->execute()) throw new RuntimeException('Unable to verify the acting administrator.');
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    $hash = $row['password_hash'] ?? '';
    return is_string($hash) ? $hash : '';
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
if (!session_account_require_active($conn, ['admin'], static function (string $state): never { staff_management_error($state === 'invalid' ? 403 : 503, $state === 'invalid' ? 'Unauthorized.' : 'Account status could not be verified.'); }, 403)) exit;

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
if (!in_array($action, ['add', 'archive', 'restore', 'promote', 'resend_invite'], true)) {
    staff_management_error(422, 'Invalid staff action.');
}
if ($action !== 'add') {
    try {
        $directoryTargetId = staff_management_user_id($data);
    } catch (StaffManagementException $error) {
        staff_management_error($error->statusCode(), $error->getMessage());
    }
    if ($directoryTargetId === (int)($_SESSION['user_id'] ?? 0)) {
        staff_management_error(409, 'Your current account cannot be changed from this directory. Use Settings to edit your profile.');
    }
}
if ($action === 'add' && array_key_exists('password', $data)) {
    staff_management_error(422, 'Staff members set their own password from the invitation email.');
}
if ($action === 'add' && array_key_exists('role', $data)) {
    $requestedRole = $data['role'];
    if (!is_string($requestedRole) || strcasecmp(trim($requestedRole), 'staff') !== 0) {
        staff_management_error(422, 'Only staff accounts can be created from User Management.');
    }
}

if ($action === 'promote') {
    $currentPassword = $data['current_password'] ?? null;
    if (!is_string($currentPassword) || $currentPassword === '' || strlen($currentPassword) > 255) {
        staff_management_error(422, 'Enter your current administrator password to promote a staff account.');
    }
    if (!check_rate_limit($conn, 'staff_promotion_confirmation', 5, 15)) {
        staff_management_error(429, 'Too many password confirmation attempts. Please try again in 15 minutes.');
    }
    $adminHash = staff_management_current_admin_password($conn);
    if ($adminHash === '' || !password_verify($currentPassword, $adminHash)) {
        staff_management_error(403, 'The administrator password could not be confirmed.');
    }
}

if (in_array($action, ['add', 'resend_invite'], true)
    && !check_rate_limit($conn, 'staff_invitation_send', 5, 15)) {
    staff_management_error(429, 'Too many staff invitation emails have been requested. Please try again in 15 minutes.');
}

$transactionStarted = false;
$inviteEmail = null;
$inviteName = null;
$inviteToken = null;
$inviteUserId = 0;
try {
    $conn->begin_transaction();
    $transactionStarted = true;

    if ($action === 'add') {
        $profile = staff_management_validate_profile($data);

        $duplicateStatement = staff_management_prepare($conn, 'SELECT id FROM users WHERE email = ? LIMIT 1');
        $duplicateStatement->bind_param('s', $profile['email']);
        if (!$duplicateStatement->execute()) throw new RuntimeException('Unable to check the work email address.');
        if ($duplicateStatement->get_result()->num_rows > 0) {
            throw new StaffManagementException('That work email address is already in use.', 409);
        }
        $duplicateStatement->close();

        // This random verifier is never disclosed. Both account status fields
        // remain inactive and email verification stays false until invite setup.
        $unusablePasswordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        if (!is_string($unusablePasswordHash)) throw new RuntimeException('Unable to prepare the staff account credential.');
        $userStatement = staff_management_prepare($conn, 'INSERT INTO users (email, password_hash, role, is_verified) VALUES (?, ?, \'staff\', 0)');
        $userStatement->bind_param('ss', $profile['email'], $unusablePasswordHash);
        if (!$userStatement->execute()) throw new RuntimeException('Unable to create the staff login.');
        $newUserId = $conn->insert_id;
        $userStatement->close();

        $staffStatement = staff_management_prepare($conn, 'INSERT INTO staff (user_id, full_name, phone, address, department, job_title, hire_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, \'inactive\')');
        $staffStatement->bind_param('issssss', $newUserId, $profile['name'], $profile['phone'], $profile['address'], $profile['department'], $profile['job_title'], $profile['hire_date']);
        if (!$staffStatement->execute()) throw new RuntimeException('Unable to create the staff profile.');
        $staffStatement->close();

        [$inviteToken, $inviteTokenHash] = staff_invitation_new_token();
        $inviteStatement = staff_management_prepare($conn, 'INSERT INTO staff_invitations (user_id, purpose, token_hash, expires_at, last_sent_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())');
        $invitePurpose = STAFF_INVITATION_PURPOSE;
        $inviteStatement->bind_param('iss', $newUserId, $invitePurpose, $inviteTokenHash);
        if (!$inviteStatement->execute()) throw new RuntimeException('Unable to create the staff invitation.');
        $inviteStatement->close();

        staff_management_audit($conn, "Created staff account {$profile['email']} with pending invitation");
        $inviteEmail = $profile['email'];
        $inviteName = $profile['name'];
        $inviteUserId = (int)$newUserId;
        $message = 'Staff account created. The account remains inactive until the invitee sets a password.';
    } elseif ($action === 'resend_invite') {
        $userId = staff_management_user_id($data);
        $inviteLookup = staff_management_prepare($conn, "SELECT u.email, s.full_name, i.id AS invitation_id
                                                          FROM users u
                                                          INNER JOIN staff s ON s.user_id = u.id
                                                          INNER JOIN staff_invitations i ON i.user_id = u.id AND i.purpose = 'staff_setup'
                                                          WHERE u.id = ? AND u.role = 'staff' AND u.is_verified = 0
                                                            AND s.status = 'inactive'
                                                            AND i.consumed_at IS NULL
                                                          LIMIT 1 FOR UPDATE");
        $inviteLookup->bind_param('i', $userId);
        if (!$inviteLookup->execute()) throw new RuntimeException('Unable to load the pending staff invitation.');
        $pendingInvite = $inviteLookup->get_result()->fetch_assoc();
        $inviteLookup->close();
        if (!$pendingInvite) throw new StaffManagementException('That staff account has no pending invitation.', 409);

        [$inviteToken, $inviteTokenHash] = staff_invitation_new_token();
        $inviteId = (int)$pendingInvite['invitation_id'];
        $replaceInvite = staff_management_prepare($conn, 'UPDATE staff_invitations SET token_hash = ?, expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR), consumed_at = NULL, last_sent_at = NOW() WHERE id = ? AND consumed_at IS NULL');
        $replaceInvite->bind_param('si', $inviteTokenHash, $inviteId);
        if (!$replaceInvite->execute() || $replaceInvite->affected_rows !== 1) throw new StaffManagementException('That invitation has already been completed or changed.', 409);
        $replaceInvite->close();
        staff_management_audit($conn, "Renewed staff invitation for {$pendingInvite['email']} (target user ID {$userId})");
        $inviteEmail = (string)$pendingInvite['email'];
        $inviteName = (string)$pendingInvite['full_name'];
        $inviteUserId = $userId;
        $message = 'A new staff invitation was generated. The account remains inactive until setup is complete.';
    } elseif ($action === 'promote') {
        $userId = staff_management_user_id($data);
        $target = staff_management_find_target($conn, $userId);
        if ($target['status'] !== 'active') {
            throw new StaffManagementException('Only active staff accounts can be promoted.', 409);
        }
        $promoteStatement = staff_management_prepare($conn, "UPDATE users SET role = 'admin' WHERE id = ? AND role = 'staff'");
        $promoteStatement->bind_param('i', $userId);
        if (!$promoteStatement->execute() || $promoteStatement->affected_rows !== 1) {
            throw new StaffManagementException('That staff account is no longer eligible for promotion.', 409);
        }
        $promoteStatement->close();
        $actorId = (int)($_SESSION['user_id'] ?? 0);
        staff_management_audit($conn, "Promoted staff account {$target['full_name']} ({$target['email']}, target user ID {$userId}) to admin; actor user ID {$actorId}");
        clear_rate_limit($conn, 'staff_promotion_confirmation');
        $message = 'Staff account promoted to administrator. The new role takes effect on the next sign-in.';
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
            if ((int)($target['is_verified'] ?? 0) !== 1) {
                throw new StaffManagementException('An unverified staff account can only be activated by completing its invitation.', 409);
            }
            $staffStatement = staff_management_prepare($conn, "UPDATE staff SET status = 'active', archived_at = NULL WHERE user_id = ? AND status = 'inactive'");
            $staffStatement->bind_param('i', $userId);
            if (!$staffStatement->execute() || $staffStatement->affected_rows !== 1) throw new RuntimeException('Unable to restore the staff account.');
            $staffStatement->close();
            staff_management_audit($conn, "Restored staff account {$target['full_name']} ({$target['email']}, {$userId})");
            $message = 'Staff account restored and sign-in access is active.';
        }
    }

    $conn->commit();
    $transactionStarted = false;
    $deliverySucceeded = false;
    $deliveryMessage = '';
    if ($inviteEmail !== null && $inviteName !== null && $inviteToken !== null) {
        try {
            $inviteLink = staff_invitation_link($inviteToken);
            if ($inviteLink === null) throw new RuntimeException('APP_BASE_URL is missing or invalid.');
            staff_invitation_send_email($inviteEmail, $inviteName, $inviteLink);
            $deliverySucceeded = true;
            $deliveryMessage = ' Invitation email sent.';
            try { staff_management_audit($conn, "Sent staff invitation to {$inviteEmail} (target user ID {$inviteUserId})"); }
            catch (Throwable $auditError) { error_log('Staff invitation delivery audit failed: ' . get_class($auditError)); }
        } catch (Throwable $mailError) {
            error_log('Staff invitation email delivery failed: ' . get_class($mailError));
            $deliveryMessage = ' The account is safely blocked, but the email could not be sent. Check mail configuration and use Resend invite.';
            try { staff_management_audit($conn, "Staff invitation email delivery failed for {$inviteEmail} (target user ID {$inviteUserId})"); }
            catch (Throwable $auditError) { error_log('Staff invitation failure audit failed: ' . get_class($auditError)); }
        }
    }
    echo json_encode(['success' => true, 'message' => $message . $deliveryMessage, 'delivery_success' => $deliverySucceeded], JSON_UNESCAPED_SLASHES);
} catch (StaffManagementException $e) {
    if ($transactionStarted) $conn->rollback();
    staff_management_error($e->statusCode(), $e->getMessage());
} catch (Throwable $e) {
    if ($transactionStarted) $conn->rollback();
    if ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062) {
        staff_management_error(409, 'That work email address is already in use.');
    }
    error_log('Staff management action failed: ' . get_class($e) . ': ' . $e->getMessage());
    staff_management_error(500, 'The staff account could not be saved. Please try again.');
}
