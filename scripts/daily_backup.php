<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/backup_helper.php';

$lockPath = sys_get_temp_dir() . '/sevilla360_daily_backup.lock';
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Daily backup skipped: another backup is running.\n");
    exit(1);
}

try {
    $admin = $conn->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' LIMIT 1")->fetch_assoc();
    if (!$admin) throw new RuntimeException('No active admin account is available for backup ownership.');

    $backupDir = BackupHelper::resolveBackupDir(true);
    $backup = BackupHelper::createBackupFile($conn, $database, $backupDir, 'sevilla360_auto_');
    if ($backup === false) throw new RuntimeException('Backup export failed.');
    if (!BackupHelper::registerBackup($conn, $backup['filename'], $backup['file_size'], (int)$admin['id'])) {
        @unlink($backup['path']);
        throw new RuntimeException('Backup metadata could not be recorded.');
    }

    BackupHelper::cleanupNormalBackups($conn, $backupDir, 30);
    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Backup & Recovery', ?, '127.0.0.1')");
    $details = "Created automatic daily backup: {$backup['filename']}";
    $adminId = (int)$admin['id'];
    $audit->bind_param('is', $adminId, $details);
    $audit->execute();
    echo "Daily backup created: {$backup['filename']}\n";
} catch (Throwable $e) {
    error_log('Daily backup failed: ' . get_class($e));
    try {
        $adminId = (int)($admin['id'] ?? 0);
        if ($adminId > 0) {
            $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Backup & Recovery', 'Automatic daily backup failed', '127.0.0.1')");
            if ($audit) { $audit->bind_param('i', $adminId); $audit->execute(); }
        }
    } catch (Throwable $auditError) { error_log('Daily backup failure audit failed: ' . get_class($auditError)); }
    fwrite(STDERR, "Daily backup failed. Check the private PHP error log.\n");
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
