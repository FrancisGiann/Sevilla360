<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../includes/database_backup.php';
database_backup_require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['success' => false, 'message' => 'Use POST to download a database archive.']));
}
try {
    database_backup_verify_request_admin();
} catch (Throwable $error) {
    http_response_code($error->getMessage() === 'The administrator account is no longer active.' ? 403 : 503);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['success' => false, 'message' => $error->getMessage()]));
}
$id = $_POST['id'] ?? '';
if (!is_string($id)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['success' => false, 'message' => 'Database archive unavailable.']));
}
try {
    $path = database_backup_archive_path($id, true);
    $metadata = database_backup_validate_archive($path);
    database_backup_remove_validation_temp($metadata);
} catch (Throwable $error) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['success' => false, 'message' => 'Database archive unavailable or invalid.']));
}
$downloadName = 'sevilla360-database-' . gmdate('Ymd-His', strtotime($metadata['created_at']) ?: time()) . '.s360db';
$archiveSize = filesize($path);
$stream = fopen($path, 'rb');
if ($stream === false || $archiveSize === false) {
    if (is_resource($stream)) fclose($stream);
    http_response_code(404);
    exit;
}
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string)$archiveSize);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
$bytesSent = fpassthru($stream);
fclose($stream);
if ($bytesSent === $archiveSize) {
    try {
        $auditConnection = database_backup_connect_production();
        database_backup_audit($auditConnection, (int)$_SESSION['user_id'], 'database.backup_downloaded', 'Downloaded signed database archive', ['archive_id' => $id, 'kind' => (string)$metadata['kind'], 'status' => 'succeeded']);
        $auditConnection->close();
    } catch (Throwable $auditError) {
        error_log('Successful database backup download audit could not be recorded.');
    }
}
