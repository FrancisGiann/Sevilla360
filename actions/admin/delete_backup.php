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
require_once __DIR__ . '/../../includes/request_context.php';
require_once __DIR__ . '/../../includes/backup_helper.php';

try {
    $transactionActive = false;
    $commitSucceeded = false;
    $originalPath = null;
    $quarantinePath = null;
    $restoreFailed = false;
    $conn->begin_transaction();
    $transactionActive = true;
    // Lock the metadata row while the corresponding private file is removed.
    $stmt = $conn->prepare("SELECT filename FROM backups WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) throw new RuntimeException('Backup not found.');

    $filename = (string)$row['filename'];
    if (BackupHelper::isProtectedFilename($filename)) {
        throw new RuntimeException('Imported and pre-restore backups are protected from deletion.');
    }
    try {
        $filePath = BackupHelper::backupFilePath(basename($filename), false);
    } catch (Throwable $e) {
        throw new RuntimeException('Backup file path is invalid.');
    }

    // Move the file to a same-directory quarantine name before changing the
    // metadata.  rename() is atomic within the private backup directory, so a
    // failed DELETE/COMMIT can restore the original filename without exposing
    // a row that points at a removed file.
    if (is_link($filePath) || (file_exists($filePath) && !is_file($filePath))) {
        throw new RuntimeException('Backup file cannot be deleted safely.');
    }
    $originalPath = $filePath;
    if (is_file($filePath)) {
        $quarantinePath = dirname($filePath) . '/.' . basename($filePath) . '.deleting.' . bin2hex(random_bytes(8));
        if (file_exists($quarantinePath) || is_link($quarantinePath) || !rename($filePath, $quarantinePath)) {
            throw new RuntimeException('Backup file could not be prepared for deletion; metadata was preserved.');
        }
    }

    $delStmt = $conn->prepare("DELETE FROM backups WHERE id = ?");
    $delStmt->bind_param("i", $id);
    if (!$delStmt->execute() || $delStmt->affected_rows !== 1) {
        throw new RuntimeException('Backup metadata could not be deleted.');
    }
    if (!$conn->commit()) throw new RuntimeException('Backup deletion could not be committed.');
    $transactionActive = false;
    $commitSucceeded = true;

    // Physical cleanup happens only after metadata commit.  If it fails, the
    // row is already gone; report a cleanup warning rather than claiming the
    // backup is still usable or pretending the file was deleted.
    $cleanupWarning = null;
    if ($quarantinePath !== null) {
        if (is_link($quarantinePath) || (file_exists($quarantinePath) && !is_file($quarantinePath)) || (is_file($quarantinePath) && !unlink($quarantinePath))) {
            $cleanupWarning = 'Backup metadata was deleted, but physical cleanup needs administrator attention.';
            error_log('Backup deletion cleanup warning: private file unlink failed for backup ' . (int)$id);
        }
    }

    // Audit only after metadata deletion committed.  A cleanup warning is
    // included so the audit does not falsely state that physical cleanup
    // succeeded.
    try {
        $adminId = (int)$_SESSION['user_id'];
        $auditStmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Backup & Recovery', ?, ?)");
        $details = 'Deleted database backup: ' . basename($filename);
        if ($cleanupWarning !== null) $details .= ' (physical cleanup warning)';
        $ip = request_client_ip();
        $auditStmt->bind_param("iss", $adminId, $details, $ip);
        if (!$auditStmt->execute()) error_log('Backup deletion audit failed for admin ' . $adminId);
    } catch (Throwable $auditError) {
        error_log('Backup deletion audit failed: ' . get_class($auditError));
    }
    $response = ['success' => true];
    if ($cleanupWarning !== null) $response['cleanup_warning'] = $cleanupWarning;
    echo json_encode($response);
} catch (Throwable $e) {
    if (!empty($transactionActive)) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log('Backup deletion rollback failed: ' . get_class($rollbackError));
        }
    }
    if (empty($commitSucceeded) && $quarantinePath !== null) {
        if (is_link($quarantinePath) || (file_exists($quarantinePath) && !is_file($quarantinePath))) {
            $restoreFailed = true;
            error_log('Backup deletion restore found an unsafe quarantine path for backup ' . (int)$id);
        } elseif (is_file($quarantinePath)) {
            if ($originalPath === null || file_exists($originalPath) || is_link($originalPath) || !rename($quarantinePath, $originalPath)) {
                $restoreFailed = true;
                error_log('Backup deletion restore failed for backup ' . (int)$id);
            }
        } elseif ($originalPath === null || !is_file($originalPath)) {
            $restoreFailed = true;
            error_log('Backup deletion quarantine file disappeared for backup ' . (int)$id);
        }
    }
    error_log('Backup deletion failed: ' . get_class($e));
    $message = $e->getMessage();
    $safe = in_array($message, ['Backup not found.', 'Imported and pre-restore backups are protected from deletion.', 'Backup file path is invalid.', 'Backup file cannot be deleted safely.', 'Backup file could not be prepared for deletion; metadata was preserved.'], true)
        ? $message : 'Backup could not be deleted; metadata was preserved where possible.';
    if ($restoreFailed) $safe = 'Backup deletion failed; metadata was preserved, but physical restore needs administrator attention.';
    echo json_encode(['success' => false, 'error' => $safe]);
}
?>
