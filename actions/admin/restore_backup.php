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

$id = $_POST['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'No backup ID provided.']);
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/backup_helper.php';
require_once __DIR__ . '/../../includes/request_context.php';

if (BackupHelper::getSigningKey() === null) {
    echo json_encode(['success' => false, 'error' => 'Restore is unavailable: configure a strong APP_KEY (at least 32 randomly generated characters).']);
    exit;
}

// First fetch the filename securely from the DB
$stmt = $conn->prepare("SELECT filename FROM backups WHERE id = ?");
if (!$stmt) { echo json_encode(['success' => false, 'error' => 'Unable to load backup metadata.']); exit; }
$stmt->bind_param("i", $id);
if (!$stmt->execute()) { echo json_encode(['success' => false, 'error' => 'Unable to load backup metadata.']); exit; }
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Backup not found in database.']);
    exit;
}

$row = $result->fetch_assoc();
$filename = $row['filename'];
try { $filePath = BackupHelper::backupFilePath(basename($filename)); } catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'The physical backup file is missing from the server.']);
    exit;
}

try { $backupDir = BackupHelper::resolveBackupDir(true); }
catch (Throwable $e) {
    error_log('Restore storage resolution failed: ' . get_class($e));
    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Backup & Recovery', 'Restore storage resolution failed', ?)");
    if ($audit) { $auditAdmin = (int)($_SESSION['user_id'] ?? 0); $auditIp = request_client_ip(); $audit->bind_param('is', $auditAdmin, $auditIp); $audit->execute(); }
    echo json_encode(['success' => false, 'error' => 'Backup storage is unavailable.']);
    exit;
}
try {
    // MySQL DDL cannot be rolled back. Validate and execute the signed dump in
    // a disposable schema before the production safety backup/import.
    BackupHelper::preflightImport($conn, $filePath, $database);
} catch (Throwable $e) {
    error_log('Backup restore preflight failed: ' . get_class($e));
    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Backup & Recovery', 'Restore preflight failed', ?)");
    if ($audit) { $auditAdmin = (int)($_SESSION['user_id'] ?? 0); $auditIp = request_client_ip(); $audit->bind_param('is', $auditAdmin, $auditIp); $audit->execute(); }
    echo json_encode(['success' => false, 'error' => 'Restore cancelled: isolated preflight failed. Production was not modified.']);
    exit;
}

$safetyBackup = BackupHelper::createBackupFile($conn, $database, $backupDir, 'sevilla360_pre_restore_');
if ($safetyBackup === false) {
    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Backup & Recovery', 'Pre-restore safety backup creation failed', ?)");
    if ($audit) { $auditAdmin = (int)($_SESSION['user_id'] ?? 0); $auditIp = request_client_ip(); $audit->bind_param('is', $auditAdmin, $auditIp); $audit->execute(); }
    echo json_encode(['success' => false, 'error' => 'Restore cancelled: the pre-restore safety backup could not be created.']);
    exit;
}

// Proceed with restore only after successful isolated preflight.
if (BackupHelper::importDatabase($conn, $filePath)) {
    $adminId = (int)($_SESSION['user_id'] ?? 0);
    $adminCheck = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
    if (!$adminCheck) { echo json_encode(['success' => false, 'error' => 'Database restored, but administrator verification failed.']); exit; }
    $adminCheck->bind_param("i", $adminId);
    if (!$adminCheck->execute()) { echo json_encode(['success' => false, 'error' => 'Database restored, but administrator verification failed.']); exit; }
    if ($adminCheck->get_result()->num_rows === 0) {
        $fallback_result = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
        if (!$fallback_result) { echo json_encode(['success' => false, 'error' => 'Database restored, but administrator verification failed.']); exit; }
        $fallback = $fallback_result->fetch_assoc();
        $adminId = (int)($fallback['id'] ?? 0);
    }
    if ($adminId <= 0 || !BackupHelper::registerBackup($conn, $safetyBackup['filename'], $safetyBackup['file_size'], $adminId)) {
        echo json_encode(['success' => false, 'error' => 'Database restored, but the pre-restore safety backup could not be registered.']);
        exit;
    }

    $auditStmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Backup & Recovery', ?, ?)");
    if (!$auditStmt) { echo json_encode(['success' => false, 'error' => 'Database restored, but audit logging failed.']); exit; }
    $details = "Restored database from backup: {$filename}; safety backup: {$safetyBackup['filename']}";
    $ip = request_client_ip();
    $auditStmt->bind_param("iss", $adminId, $details, $ip);
    if (!$auditStmt->execute()) { echo json_encode(['success' => false, 'error' => 'Database restored, but audit logging failed.']); exit; }

    echo json_encode(['success' => true]);
} else {
    // Production import may have partially applied DDL. Immediately replay
    // the pre-restore snapshot and report the recovery result explicitly.
    $recovered = BackupHelper::importDatabase($conn, $safetyBackup['path']);
    error_log('Backup restore/import failed; recovery attempted: ' . ($recovered ? 'yes' : 'no'));
    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Backup & Recovery', ?, ?)");
    if ($audit) { $auditAdmin = (int)($_SESSION['user_id'] ?? 0); $auditAction = $recovered ? 'Restore failed; safety backup replayed' : 'Restore and safety recovery failed'; $auditIp = request_client_ip(); $audit->bind_param('iss', $auditAdmin, $auditAction, $auditIp); $audit->execute(); }
    echo json_encode([
        'success' => false,
        'recovery_succeeded' => $recovered,
        'error' => $recovered
            ? 'Database restore failed; production was recovered from the safety backup.'
            : 'Database restore failed and automatic recovery also failed. Stop writes and restore the safety backup manually.'
    ]);
}
?>
