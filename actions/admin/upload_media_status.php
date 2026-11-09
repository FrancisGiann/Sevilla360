<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cms_upload_status_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

if (($_SESSION['role'] ?? '') !== 'admin' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    cms_upload_status_response(['success' => false, 'message' => 'Sign in with an administrator account to check this upload.'], 403);
    exit;
}
$csrf = (string)($_SESSION['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    cms_upload_status_response(['success' => false, 'message' => 'Session validation failed. Refresh the CMS and try again.'], 403);
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/session_account_guard.php';
if (!session_account_require_active_json(
    $conn,
    ['admin'],
    ['success' => false, 'message' => 'Sign in with an administrator account to check this upload.'],
    ['success' => false, 'message' => 'Account status could not be verified.']
)) exit;

$raw = file_get_contents('php://input', false, null, 0, 1025);
if (!is_string($raw) || strlen($raw) > 1024) {
    cms_upload_status_response(['success' => false, 'message' => 'Invalid upload status request.'], 400);
    exit;
}
try {
    $data = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    cms_upload_status_response(['success' => false, 'message' => 'Invalid upload status request.'], 400);
    exit;
}
$uploadId = is_array($data) && is_string($data['upload_id'] ?? null) ? strtolower($data['upload_id']) : '';
if (!preg_match('/\A[a-f0-9]{32}\z/D', $uploadId)) {
    cms_upload_status_response(['success' => false, 'message' => 'Invalid upload status request.'], 400);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$marker = '[cms-upload:' . $uploadId . ']';
$stmt = $conn->prepare("SELECT 1 FROM audit_logs WHERE user_id=? AND module='Media CMS' AND LOCATE(?, action)>0 LIMIT 1");
if (!$stmt) {
    cms_upload_status_response(['success' => false, 'state' => 'unknown', 'message' => 'The upload result could not be checked yet.'], 503);
    exit;
}
$stmt->bind_param('is', $userId, $marker);
if (!$stmt->execute()) {
    cms_upload_status_response(['success' => false, 'state' => 'unknown', 'message' => 'The upload result could not be checked yet.'], 503);
    exit;
}
$completed = $stmt->get_result()->fetch_row() !== null;
cms_upload_status_response([
    'success' => true,
    'state' => $completed ? 'completed' : 'pending',
    'message' => $completed
        ? 'The server confirmed that the selected images were saved.'
        : 'The server has not confirmed this upload yet.',
]);
