<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../includes/database_backup.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Use POST to upload a database archive.']);
    exit;
}
database_backup_require_admin();
if (database_backup_maintenance_state() !== null) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Database maintenance is active. Upload a new archive after the site reopens.']);
    exit;
}
try {
    database_backup_verify_request_admin();
} catch (Throwable $error) {
    http_response_code($error->getMessage() === 'The administrator account is no longer active.' ? 403 : 503);
    echo json_encode(['success' => false, 'message' => 'The active administrator account could not be revalidated.']);
    exit;
}
$capabilities = database_backup_capabilities();
if (!$capabilities['restore_enabled']) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => $capabilities['restore_message'] ?: implode(' ', $capabilities['messages'])]);
    exit;
}
$upload = $_FILES['backup_file'] ?? null;
if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['size']) || $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Choose a complete signed Sevilla360 database archive.']);
    exit;
}
if ((int)$upload['size'] < 12 || (int)$upload['size'] > database_backup_max_bytes()) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'The archive exceeds BACKUP_MAX_BYTES or is empty.']);
    exit;
}

$id = 'db-' . bin2hex(random_bytes(16)) . '.s360db';
$archiveStored = false;
try {
    $destination = database_backup_archive_path($id, false);
    if (file_exists($destination) || is_link($destination)) throw new RuntimeException('Unable to allocate a unique private archive path.');
    if (!move_uploaded_file((string)$upload['tmp_name'], $destination)) throw new RuntimeException('Unable to store the private archive.');
    $archiveStored = true;
    @chmod($destination, 0600);
    $metadata = database_backup_validate_archive($destination);
    database_backup_remove_validation_temp($metadata);
    $job = database_backup_queue_job('validate', (int)$_SESSION['user_id'], $id);
    try {
        require_once __DIR__ . '/../../config/db_connect.php';
        database_backup_audit($conn, (int)$_SESSION['user_id'], 'database.backup_uploaded', 'Uploaded signed database archive', ['archive_id' => $id, 'job_id' => $job['id'], 'kind' => 'uploaded', 'status' => 'queued']);
        $conn->close();
    } catch (Throwable $auditError) {
        error_log('Database archive upload audit could not be written.');
    }
    echo json_encode(['success' => true, 'archive_id' => $id, 'job_id' => $job['id'], 'message' => 'Signed archive verified. Staging compatibility check queued.']);
} catch (Throwable $error) {
    if ($archiveStored && isset($destination) && is_file($destination) && !is_link($destination)) @unlink($destination);
    error_log('Database archive upload rejected: ' . get_class($error));
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $error instanceof RuntimeException ? $error->getMessage() : 'The database archive could not be accepted.']);
}
