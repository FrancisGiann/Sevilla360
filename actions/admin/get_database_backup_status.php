<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../includes/database_backup.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
database_backup_require_admin(false);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['success' => false, 'message' => 'Use GET to load backup status.']);
    exit;
}
try {
    database_backup_verify_request_admin(true);
} catch (Throwable $error) {
    http_response_code($error->getMessage() === 'The administrator account is no longer active.' ? 403 : 503);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
    exit;
}
$jobs = [];
foreach (database_backup_list_jobs(12) as $job) {
    $jobs[] = [
        'id' => (string)($job['id'] ?? ''),
        'action' => (string)($job['action'] ?? ''),
        'status' => (string)($job['status'] ?? ''),
        'phase' => (string)($job['phase'] ?? ''),
        'message' => (string)($job['message'] ?? ''),
        'created_at' => $job['created_at'] ?? null,
        'completed_at' => $job['completed_at'] ?? null,
        'archive_id' => $job['archive_id'] ?? null,
        'safety_archive_id' => $job['safety_archive_id'] ?? null,
    ];
}
echo json_encode([
    'success' => true,
    'capabilities' => database_backup_capabilities(),
    'maintenance' => database_backup_maintenance_state(),
    'archives' => database_backup_list_archives(),
    'jobs' => $jobs,
    'retention_days' => DATABASE_BACKUP_RETENTION_DAYS,
], JSON_INVALID_UTF8_SUBSTITUTE);
