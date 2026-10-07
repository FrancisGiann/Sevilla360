<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../includes/database_backup.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Use POST to request a database restore.']);
    exit;
}
database_backup_require_admin();
if (database_backup_maintenance_state() !== null) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Database maintenance is already active. Use the CLI recovery path before requesting another restore.']);
    exit;
}
$raw = file_get_contents('php://input');
if (!is_string($raw) || strlen($raw) > 16384) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'The restore request is too large.']);
    exit;
}
try {
    $request = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid restore request.']);
    exit;
}
if (!is_array($request) || !is_string($request['archive_id'] ?? null) || !is_string($request['password'] ?? null) || !is_string($request['confirmation'] ?? null) || !hash_equals('RESTORE', $request['confirmation'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Choose an archive, enter your current password, and type RESTORE to confirm.']);
    exit;
}
$capabilities = database_backup_capabilities();
if (!$capabilities['restore_enabled']) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => $capabilities['restore_message'] ?: implode(' ', $capabilities['messages'])]);
    exit;
}
try {
    $archive = database_backup_archive_path($request['archive_id'], true);
    $metadata = database_backup_validate_archive($archive);
    database_backup_remove_validation_temp($metadata);
    require_once __DIR__ . '/../../config/db_connect.php';
    $actorId = (int)$_SESSION['user_id'];
    if (!database_backup_verify_admin_password($conn, $actorId, $request['password'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Current administrator password verification failed.']);
        exit;
    }
    $job = database_backup_queue_job('restore', $actorId, $request['archive_id'], true);
    $conn->close();
    echo json_encode(['success' => true, 'job_id' => $job['id'], 'message' => 'Restore queued. The worker will test the archive on staging before production changes.']);
} catch (Throwable $error) {
    error_log('Database restore request could not be queued: ' . get_class($error));
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $error instanceof RuntimeException ? $error->getMessage() : 'The restore request could not be accepted.']);
}
