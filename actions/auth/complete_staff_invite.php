<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/rate_limit.php';
require_once __DIR__ . '/../../includes/password_policy.php';
require_once __DIR__ . '/../../includes/request_context.php';

function staff_invite_reply(int $statusCode, array $body): never
{
    http_response_code($statusCode);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') staff_invite_reply(405, ['success' => false, 'message' => 'Only POST requests are accepted.']);
$clientCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionCsrf = $_SESSION['csrf_token'] ?? '';
if (!is_string($clientCsrf) || !is_string($sessionCsrf) || $sessionCsrf === '' || !hash_equals($sessionCsrf, $clientCsrf)) {
    staff_invite_reply(403, ['success' => false, 'message' => 'Security token expired. Refresh the invitation link and try again.']);
}
if (!check_rate_limit($conn, 'staff_invitation_complete', 8, 15)) {
    staff_invite_reply(429, ['success' => false, 'message' => 'Too many setup attempts. Please try again in 15 minutes.']);
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) staff_invite_reply(422, ['success' => false, 'message' => 'Invalid account setup request.']);
$token = is_string($body['token'] ?? null) ? $body['token'] : '';
$password = is_string($body['password'] ?? null) ? $body['password'] : '';
$confirmation = is_string($body['confirm_password'] ?? null) ? $body['confirm_password'] : '';
if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) staff_invite_reply(422, ['success' => false, 'message' => 'This invitation is invalid, expired, or already used. Ask your administrator to send a new invitation.']);
if ($password === '' || $password !== $confirmation) staff_invite_reply(422, ['success' => false, 'message' => 'Enter matching passwords.']);
$policy = password_policy_validate($password);
if (!$policy['valid']) staff_invite_reply(422, ['success' => false, 'message' => $policy['message']]);

$tokenHash = hash('sha256', $token);
$transactionStarted = false;
try {
    if (!$conn->begin_transaction()) throw new RuntimeException('Unable to begin staff setup transaction.');
    $transactionStarted = true;
    $lookup = $conn->prepare("SELECT i.id AS invitation_id, i.user_id, u.role, u.is_verified, s.status AS staff_status
                              FROM staff_invitations i
                              INNER JOIN users u ON u.id = i.user_id
                              INNER JOIN staff s ON s.user_id = u.id
                              WHERE i.token_hash = ? AND i.purpose = 'staff_setup'
                                AND i.consumed_at IS NULL AND i.expires_at > NOW()
                                AND u.role = 'staff' AND u.is_verified = 0
                                AND s.status = 'inactive'
                              LIMIT 1 FOR UPDATE");
    if (!$lookup) throw new RuntimeException('Unable to prepare staff invitation lookup.');
    $lookup->bind_param('s', $tokenHash);
    if (!$lookup->execute()) throw new RuntimeException('Unable to load the staff invitation.');
    $invite = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if (!is_array($invite)) {
        $conn->rollback();
        $transactionStarted = false;
        staff_invite_reply(410, ['success' => false, 'message' => 'This invitation is invalid, expired, or already used. Ask your administrator to send a new invitation.']);
    }

    $userId = (int)$invite['user_id'];
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($passwordHash)) throw new RuntimeException('Unable to secure the new password.');
    $updateUser = $conn->prepare("UPDATE users SET password_hash = ?, is_verified = 1, auth_version = auth_version + 1,
                                  reset_token = NULL, reset_token_hash = NULL, reset_expires_at = NULL
                                  WHERE id = ? AND role = 'staff' AND is_verified = 0");
    if (!$updateUser) throw new RuntimeException('Unable to prepare staff account activation.');
    $updateUser->bind_param('si', $passwordHash, $userId);
    if (!$updateUser->execute() || $updateUser->affected_rows !== 1) throw new RuntimeException('Unable to activate staff account.');
    $updateUser->close();

    $updateStaff = $conn->prepare("UPDATE staff SET status = 'active', archived_at = NULL WHERE user_id = ? AND status = 'inactive'");
    if (!$updateStaff) throw new RuntimeException('Unable to prepare staff profile activation.');
    $updateStaff->bind_param('i', $userId);
    if (!$updateStaff->execute() || $updateStaff->affected_rows !== 1) throw new RuntimeException('Unable to activate staff profile.');
    $updateStaff->close();

    $consume = $conn->prepare("UPDATE staff_invitations SET consumed_at = NOW() WHERE id = ? AND token_hash = ? AND purpose = 'staff_setup' AND consumed_at IS NULL AND expires_at > NOW()");
    if (!$consume) throw new RuntimeException('Unable to prepare invitation consumption.');
    $invitationId = (int)$invite['invitation_id'];
    $consume->bind_param('is', $invitationId, $tokenHash);
    if (!$consume->execute() || $consume->affected_rows !== 1) throw new RuntimeException('Unable to consume the staff invitation.');
    $consume->close();

    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Staff Onboarding', 'Completed staff invitation and activated account', ?)");
    if (!$audit) throw new RuntimeException('Unable to prepare onboarding audit entry.');
    $ipAddress = request_client_ip();
    $audit->bind_param('is', $userId, $ipAddress);
    if (!$audit->execute()) throw new RuntimeException('Unable to record onboarding audit entry.');
    $audit->close();

    if (!$conn->commit()) throw new RuntimeException('Unable to commit staff account setup.');
    $transactionStarted = false;
    staff_invite_reply(200, ['success' => true, 'message' => 'Your staff account is active. Sign in with your work email and the password you chose.']);
} catch (Throwable $error) {
    if ($transactionStarted) $conn->rollback();
    error_log('Staff invitation completion failed: ' . get_class($error));
    staff_invite_reply(500, ['success' => false, 'message' => 'Account setup could not be completed. Try again or ask your administrator to send a new invitation.']);
}
