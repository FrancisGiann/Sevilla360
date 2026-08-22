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

$id = $_POST['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'No backup ID provided.']);
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/backup_helper.php';

// First fetch the filename securely from the DB
$stmt = $conn->prepare("SELECT filename FROM backups WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Backup not found in database.']);
    exit;
}

$row = $result->fetch_assoc();
$filename = $row['filename'];
$filePath = __DIR__ . '/../../storage/backups/' . basename($filename);

if (!file_exists($filePath)) {
    echo json_encode(['success' => false, 'error' => 'The physical backup file is missing from the server.']);
    exit;
}

$backupDir = __DIR__ . '/../../storage/backups';
$safetyBackup = BackupHelper::createBackupFile($conn, $database, $backupDir, 'sevilla360_pre_restore_');
if ($safetyBackup === false) {
    echo json_encode(['success' => false, 'error' => 'Restore cancelled: the pre-restore safety backup could not be created.']);
    exit;
}

// Proceed with restore
if (BackupHelper::importDatabase($conn, $filePath)) {
    $adminId = (int)($_SESSION['user_id'] ?? 0);
    $adminCheck = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
    $adminCheck->bind_param("i", $adminId);
    $adminCheck->execute();
    if ($adminCheck->get_result()->num_rows === 0) {
        $fallback = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
        $adminId = (int)($fallback['id'] ?? 0);
    }
    if ($adminId <= 0 || !BackupHelper::registerBackup($conn, $safetyBackup['filename'], $safetyBackup['file_size'], $adminId)) {
        echo json_encode(['success' => false, 'error' => 'Database restored, but the pre-restore safety backup could not be registered.']);
        exit;
    }

    $auditStmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Backup & Recovery', ?, ?)");
    $details = "Restored database from backup: {$filename}; safety backup: {$safetyBackup['filename']}";
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $auditStmt->bind_param("iss", $adminId, $details, $ip);
    $auditStmt->execute();

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database restore failed. The file might be corrupted.']);
}
?>
