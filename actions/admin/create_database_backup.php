<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../includes/database_backup.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Use POST to create a database backup.']);
    exit;
}
database_backup_require_admin();
try {
    database_backup_verify_request_admin();
} catch (Throwable $error) {
    http_response_code($error->getMessage() === 'The administrator account is no longer active.' ? 403 : 503);
    echo json_encode(['success' => false, 'message' => 'The active administrator account could not be revalidated.']);
    exit;
}
$capabilities = database_backup_capabilities();
if (!$capabilities['enabled']) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => implode(' ', $capabilities['messages'])]);
    exit;
}
if (database_backup_maintenance_state() !== null) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'A database restore is in progress.']);
    exit;
}
try {
    $job = database_backup_queue_job('create', (int)$_SESSION['user_id']);
    echo json_encode(['success' => true, 'job_id' => $job['id'], 'message' => 'Database backup queued for the CLI worker.']);
} catch (Throwable $error) {
    error_log('Database backup request could not be queued: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The database backup could not be queued.']);
}
