<?php
session_start();
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

$backupDir = __DIR__ . '/../../storage/backups';
$signingKey = BackupHelper::getSigningKey();
if ($signingKey === null) {
    echo json_encode(['success' => false, 'error' => 'Backup creation is unavailable: configure a strong APP_KEY (at least 32 randomly generated characters).']);
    exit;
}
$timestamp = date('Y-m-d_H-i-s');
$filename = "sevilla360_backup_{$timestamp}.sql";
$filePath = "{$backupDir}/{$filename}";

if (BackupHelper::exportDatabase($conn, $database, $filePath)) {
    $fileSize = filesize($filePath);
    $adminId = $_SESSION['user_id'];

    if (BackupHelper::registerBackup($conn, $filename, $fileSize, $adminId)) {
        BackupHelper::cleanupNormalBackups($conn, $backupDir, 30);
        // Log to audit
        $auditStmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Backup & Recovery', ?, ?)");
        $details = "Created database backup: {$filename}";
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
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
?>
