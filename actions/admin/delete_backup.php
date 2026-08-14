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

// First fetch the filename
$stmt = $conn->prepare("SELECT filename FROM backups WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Backup not found.']);
    exit;
}

$row = $result->fetch_assoc();
$filename = $row['filename'];
$filePath = __DIR__ . '/../../storage/backups/' . basename($filename);

// Delete record
$delStmt = $conn->prepare("DELETE FROM backups WHERE id = ?");
$delStmt->bind_param("i", $id);

if ($delStmt->execute()) {
    // Delete file
    if (file_exists($filePath)) {
        @unlink($filePath);
    }
    
    // Log to audit
    $adminId = $_SESSION['user_id'];
    $auditStmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Backup & Recovery', ?, ?)");
    $details = "Deleted database backup: {$filename}";
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $auditStmt->bind_param("iss", $adminId, $details, $ip);
    $auditStmt->execute();

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to delete record.']);
}
?>
