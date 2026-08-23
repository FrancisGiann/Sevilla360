<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/backup_helper.php';
require_once __DIR__ . '/../../includes/request_context.php';

try {
$backupDir = BackupHelper::resolveBackupDir(true);
$signingKey = BackupHelper::getSigningKey();
if ($signingKey === null) {
    echo json_encode(['success' => false, 'error' => 'Backup creation is unavailable: configure a strong APP_KEY (at least 32 randomly generated characters).']);
    exit;
}
$timestamp = date('Y-m-d_H-i-s');
$filename = "sevilla360_backup_{$timestamp}_" . bin2hex(random_bytes(4)) . ".sql";
$filePath = BackupHelper::backupFilePath($filename, false);

if (BackupHelper::exportDatabase($conn, $database, $filePath)) {
    $fileSize = filesize($filePath);
    $adminId = $_SESSION['user_id'];

    if (BackupHelper::registerBackup($conn, $filename, $fileSize, $adminId)) {
        BackupHelper::cleanupNormalBackups($conn, $backupDir, 30);
        // Log to audit
        $auditStmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Backup & Recovery', ?, ?)");
        $details = "Created database backup: {$filename}";
        $ip = request_client_ip();
        $auditStmt->bind_param("iss", $adminId, $details, $ip);
        $auditStmt->execute();

        echo json_encode(['success' => true, 'message' => 'Backup created successfully!']);
    } else {
        // DB insert failed, but file was created. We should probably delete the orphan file.
        @unlink($filePath);
        echo json_encode(['success' => false, 'error' => 'Database error recording backup.']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to generate backup file. Check directory permissions.']);
}
} catch (Throwable $e) {
    error_log('Manual backup failed: ' . get_class($e));
    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Backup & Recovery', 'Manual backup failed', ?)");
    if ($audit) { $auditAdmin = (int)($_SESSION['user_id'] ?? 0); $auditIp = request_client_ip(); $audit->bind_param('is', $auditAdmin, $auditIp); $audit->execute(); }
    echo json_encode(['success' => false, 'error' => 'Backup creation failed.']);
}
?>
