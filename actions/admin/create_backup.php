<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');

function create_backup_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
}

function create_backup_setup_message(string $message): ?string
{
    $knownMessages = [
        'Private backup directory is not configured.',
        'Private backup directory is unavailable.',
        'Private backup directory parent is unavailable.',
        'Private backup directory is not writable.',
        'Private backup directory could not be created.',
        'Backup directory is too broad or unsafe.',
        'Backup directory must be outside the document root.',
        'Backup path is not a directory.',
        'A strong APP_KEY is required for backup signing.'
    ];
    if ($message === 'A strong APP_KEY is required for backup signing.') {
        return 'Backup creation is unavailable: configure a strong APP_KEY (at least 32 randomly generated characters).';
    }
    return in_array($message, $knownMessages, true) ? 'Backup creation is unavailable: ' . $message : null;
}

function create_backup_failure_audit($conn): void
{
    try {
        $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Backup & Recovery', 'Manual backup failed', ?)");
        if (!$audit) return;
        $auditAdmin = (int)($_SESSION['user_id'] ?? 0);
        $auditIp = request_client_ip();
        $audit->bind_param('is', $auditAdmin, $auditIp);
        $audit->execute();
    } catch (Throwable $auditError) {
        error_log('Manual backup failure audit failed: ' . get_class($auditError));
    }
}

function create_backup_cleanup_artifacts(string $filePath): void
{
    $paths = [$filePath];
    $temporaryPaths = glob($filePath . '.tmp.*');
    if (is_array($temporaryPaths)) $paths = array_merge($paths, $temporaryPaths);
    foreach (array_unique($paths) as $path) {
        if (is_file($path) && !is_link($path)) @unlink($path);
    }
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    create_backup_response(['success' => false, 'error' => 'Unauthorized access.'], 401);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    create_backup_response(['success' => false, 'error' => 'Invalid request method.'], 405);
    exit;
}

if (!isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) || $_POST['csrf_token'] === '' || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    create_backup_response(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
    exit;
}

try {
    require_once __DIR__ . '/../../config/db_connect.php';
    require_once __DIR__ . '/../../includes/backup_helper.php';
    require_once __DIR__ . '/../../includes/request_context.php';

    $backupDir = BackupHelper::resolveBackupDir(true);
    if (BackupHelper::getSigningKey() === null) {
        throw new RuntimeException('A strong APP_KEY is required for backup signing.');
    }

    $adminId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($adminId === false || $adminId < 1) {
        throw new RuntimeException('Authenticated admin identity is unavailable.');
    }

    $timestamp = date('Y-m-d_H-i-s');
    $filename = "sevilla360_backup_{$timestamp}_" . bin2hex(random_bytes(4)) . ".sql";
    $filePath = BackupHelper::backupFilePath($filename, false);
    $registered = false;

    if (!BackupHelper::exportDatabase($conn, $database, $filePath)) {
        create_backup_cleanup_artifacts($filePath);
        throw new RuntimeException('Backup export could not be completed.');
    }

    $fileSize = filesize($filePath);
    if ($fileSize === false || $fileSize <= 0) {
        create_backup_cleanup_artifacts($filePath);
        throw new RuntimeException('Backup export produced an invalid file.');
    }

    if (!BackupHelper::registerBackup($conn, $filename, $fileSize, $adminId)) {
        create_backup_cleanup_artifacts($filePath);
        throw new RuntimeException('Backup metadata could not be recorded.');
    }
    $registered = true;

    try {
        BackupHelper::cleanupNormalBackups($conn, $backupDir, 30);
    } catch (Throwable $cleanupError) {
        error_log('Manual backup retention cleanup failed: ' . get_class($cleanupError) . ': ' . $cleanupError->getMessage());
    }

    try {
        $auditStmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Backup & Recovery', ?, ?)");
        if (!$auditStmt) throw new RuntimeException('Audit statement could not be prepared.');
        $details = "Created database backup: {$filename}";
        $ip = request_client_ip();
        $auditStmt->bind_param('iss', $adminId, $details, $ip);
        if (!$auditStmt->execute()) throw new RuntimeException('Audit entry could not be recorded.');
    } catch (Throwable $auditError) {
        error_log('Manual backup success audit failed: ' . get_class($auditError) . ': ' . $auditError->getMessage());
    }

    create_backup_response(['success' => true, 'message' => 'Backup created successfully!']);
} catch (Throwable $e) {
    if (isset($filePath) && (!isset($registered) || !$registered)) create_backup_cleanup_artifacts($filePath);
    error_log('Manual backup failed: ' . get_class($e) . ': ' . $e->getMessage());
    if (isset($conn)) create_backup_failure_audit($conn);
    $setupMessage = create_backup_setup_message($e->getMessage());
    if ($setupMessage !== null) {
        create_backup_response(['success' => false, 'error' => $setupMessage], 503);
    } else {
        create_backup_response(['success' => false, 'error' => 'Backup creation failed. Please check the server logs.'], 500);
    }
}
?>
