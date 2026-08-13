<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// ==========================================
// CSRF PROTECTION GUARD (JSON)
// ==========================================
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed. Unauthorized request.']);
    exit;
}

$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

// Support both single id (existing code) and bulk ids (new code)
$ids = [];
if (isset($data['ids']) && is_array($data['ids'])) {
    $ids = array_map('intval', $data['ids']);
} elseif (isset($data['id'])) {
    $ids = [intval($data['id'])];
}

if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No Media IDs provided.']);
    exit;
}

try {
    $conn->begin_transaction();

    // Dynamically build the IN (...) placeholders
    $inQuery = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    // Get the file paths first to delete them physically
    $stmt = $conn->prepare("SELECT id, file_path, slot_assignment FROM media_cms WHERE id IN ($inQuery)");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows === 0) throw new Exception("Media not found.");

    $files_to_delete = [];
    while ($media = $res->fetch_assoc()) {
        // Prevent deletion of the ONE core system slot that breaks the website layout if missing
        if ($media['slot_assignment'] === 'home-hero') {
            throw new Exception("The Homepage Hero Banner cannot be deleted, it can only be replaced.");
        }
        $files_to_delete[] = $media;
    }

    // Delete physically from folder
    foreach ($files_to_delete as $media) {
        $physical_path = '../../' . $media['file_path'];
        if (file_exists($physical_path)) {
            unlink($physical_path);
        }
    }

    // Delete from database
    $stmt_del = $conn->prepare("DELETE FROM media_cms WHERE id IN ($inQuery)");
    $stmt_del->bind_param($types, ...$ids);
    $stmt_del->execute();

    // AUDIT LOG
    if (isset($_SESSION['user_id'])) {
        $log_user = $_SESSION['user_id'];
        $log_module = 'Media CMS';
        
        $action_msg = count($ids) > 1 
            ? "Bulk deleted " . count($ids) . " media files."
            : "Deleted media file: " . basename($files_to_delete[0]['file_path']);

        $log_ip = $_SERVER['REMOTE_ADDR'];
        $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, ?, ?, ?)");
        $audit_stmt->bind_param("isss", $log_user, $log_module, $action_msg, $log_ip);
        $audit_stmt->execute();
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => count($ids) . ' file(s) deleted successfully.']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>