<?php
require_once __DIR__ . '/../../includes/session_init.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die('Unauthorized access.');
}

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/request_context.php';
require_once __DIR__ . '/../../includes/backup_helper.php';

$filename = $_GET['file'] ?? '';
if (empty($filename)) {
    die('No file specified.');
}

// Security: Ensure the file actually exists in the backups table 
// (prevents directory traversal attacks like ../../config/.env)
$stmt = $conn->prepare("SELECT filename FROM backups WHERE filename = ?");
$stmt->bind_param("s", $filename);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die('Backup not found in database.');
}

$row = $result->fetch_assoc();
$safeFilename = basename($row['filename']);
try { $filePath = BackupHelper::backupFilePath($safeFilename); } catch (Throwable $e) {
    http_response_code(404);
    die('The physical backup file is unavailable.');
}

if (!file_exists($filePath)) {
    die('The physical backup file is missing from the server.');
}

// Log to audit
$adminId = $_SESSION['user_id'];
$auditStmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Backup & Recovery', ?, ?)");
$details = "Downloaded database backup: {$safeFilename}";
$ip = request_client_ip();
$auditStmt->bind_param("iss", $adminId, $details, $ip);
$auditStmt->execute();

// Send headers to force download
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));

// Clean output buffer before streaming the file
if (ob_get_level()) {
    ob_end_clean();
}

readfile($filePath);
exit;
?>
