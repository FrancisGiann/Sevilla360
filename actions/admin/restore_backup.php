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

// Proceed with restore
if (BackupHelper::importDatabase($conn, $filePath)) {
    // Log to audit (We have to reconnect or ensure connection is alive, but importDatabase doesn't kill it if successful)
    $adminId = $_SESSION['user_id'];
    $auditStmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Backup & Recovery', ?, ?)");
    $details = "Restored database from backup: {$filename}";
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $auditStmt->bind_param("iss", $adminId, $details, $ip);
    $auditStmt->execute();

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database restore failed. The file might be corrupted.']);
}
?>
