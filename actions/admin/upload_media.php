<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/request_context.php';

// 1. Auth Guard: Only Super Admins manage CMS
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
    $media_type = $_POST['media_type'] ?? '';
    $website_slot = $_POST['website_slot'] ?? '';
    
    // Validate inputs
    if (empty($media_type) || empty($website_slot)) {
        echo json_encode(['success' => false, 'message' => 'Missing media type or slot assignment.']);
        exit;
    }

    if (!isset($_FILES['fileInput']) || empty($_FILES['fileInput']['name'][0])) {
        echo json_encode(['success' => false, 'message' => 'No files uploaded.']);
        exit;
    }

    // Ensure upload directory exists with least required permissions.
    $upload_dir = __DIR__ . '/../../assets/uploads/';
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
            echo json_encode(['success' => false, 'message' => 'Upload directory is unavailable.']);
            exit;
        }
    }

    // =========================================================================
    // SECURITY FIX: STRICT MIME TYPE MAPPING
    // Map genuine binary MIME types to safe, forced extensions
    // =========================================================================
    $allowed_mimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];
    
    // Initialize PHP's secure File Information resource
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    
    // Explicitly define which slots are strictly 1-to-1
    $is_strict_slot = false;
    if (strpos($website_slot, 'home-') === 0) {
        $is_strict_slot = true; 
    }

    $max_bytes = max(1, (int)($_ENV['MEDIA_MAX_IMAGE_BYTES'] ?? getenv('MEDIA_MAX_IMAGE_BYTES') ?: 12582912));
    $max_pixels = max(1, (int)($_ENV['MEDIA_MAX_IMAGE_PIXELS'] ?? getenv('MEDIA_MAX_IMAGE_PIXELS') ?: 40000000));
    $max_width = max(1, (int)($_ENV['MEDIA_MAX_IMAGE_WIDTH'] ?? getenv('MEDIA_MAX_IMAGE_WIDTH') ?: 10000));
    $max_height = max(1, (int)($_ENV['MEDIA_MAX_IMAGE_HEIGHT'] ?? getenv('MEDIA_MAX_IMAGE_HEIGHT') ?: 10000));
    $uploaded_count = is_array($_FILES['fileInput']['name']) ? count($_FILES['fileInput']['name']) : 0;
    if ($uploaded_count < 1) throw new Exception('No valid files were uploaded.');

    // Validate every replacement candidate before touching existing DB rows or
    // media. A strict-slot replacement is all-or-nothing for its one file.
    $validated = [];
    for ($i = 0; $i < $uploaded_count; $i++) {
        if ($_FILES['fileInput']['error'][$i] !== UPLOAD_ERR_OK) throw new Exception('One or more uploads failed validation.');
        $tmp_path = $_FILES['fileInput']['tmp_name'][$i];
        $size = filesize($tmp_path);
        $dimensions = @getimagesize($tmp_path);
        $true_mime = $finfo->file($tmp_path);
        if ($size === false || $size > $max_bytes || !array_key_exists($true_mime, $allowed_mimes) || $dimensions === false || $dimensions[0] > $max_width || $dimensions[1] > $max_height || ((int)$dimensions[0] * (int)$dimensions[1]) > $max_pixels) {
            throw new Exception('Image exceeds the configured size or dimension limits, or is not a valid JPG, PNG, or WEBP image.');
        }
        $safe_slot = preg_replace('/[^a-zA-Z0-9_-]/', '', $website_slot);
        $new_filename = $safe_slot . '_' . bin2hex(random_bytes(8)) . '.' . $allowed_mimes[$true_mime];
        // Strict slots process only their first file, but every selected file
        // is validated before existing records/media can be removed.
        if (!$is_strict_slot || $i === 0) {
            $validated[] = ['tmp' => $tmp_path, 'destination' => $upload_dir . $new_filename, 'filename' => $new_filename, 'db_path' => 'assets/uploads/' . $new_filename];
        }
    }

    $new_files = []; $old_files = []; $successful_uploads = 0; $transaction_started = false; $committed = false;
    try {
        if (!$conn->begin_transaction()) throw new Exception('Could not start upload transaction.');
        $transaction_started = true;
        // Keep old DB records until the transaction includes the new records;
        // old physical files are deleted only after commit below.
        if ($is_strict_slot) {
            $stmt_check = $conn->prepare("SELECT id, file_path FROM media_cms WHERE slot_assignment = ? FOR UPDATE");
            if (!$stmt_check) throw new Exception('Could not load existing media.');
            $stmt_check->bind_param('s', $website_slot); if (!$stmt_check->execute()) throw new Exception('Could not load existing media.');
            $res = $stmt_check->get_result();
            while ($old_media = $res->fetch_assoc()) { $old_files[] = ['id' => (int)$old_media['id'], 'path' => dirname(__DIR__, 2) . '/' . ltrim($old_media['file_path'], '/')]; }
            $stmt_del = $conn->prepare("DELETE FROM media_cms WHERE slot_assignment = ?");
            if (!$stmt_del) throw new Exception('Could not replace existing media records.');
            $stmt_del->bind_param('s', $website_slot); if (!$stmt_del->execute()) throw new Exception('Could not replace existing media records.');
        }
        foreach ($validated as $file) {
            if (!move_uploaded_file($file['tmp'], $file['destination'])) throw new Exception('Failed to move uploaded image.');
            $new_files[] = $file['destination'];
            $stmt_insert = $conn->prepare("INSERT INTO media_cms (file_name, file_path, media_type, slot_assignment) VALUES (?, ?, ?, ?)");
            if (!$stmt_insert) throw new Exception('Could not record uploaded media.');
            $stmt_insert->bind_param('ssss', $file['filename'], $file['db_path'], $media_type, $website_slot);
            if (!$stmt_insert->execute()) throw new Exception('Could not record uploaded media.');
            $successful_uploads++;
        }
        if ($successful_uploads < 1) throw new Exception('No valid files were uploaded.');
        if (isset($_SESSION['user_id'])) {
            $log_user = (int)$_SESSION['user_id']; $log_module = 'Media CMS'; $log_action = "Uploaded $successful_uploads file(s) to slot: $website_slot"; $log_ip = request_client_ip();
            $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, ?, ?, ?)");
            if (!$audit_stmt) throw new Exception('Could not record media audit entry.');
            $audit_stmt->bind_param('isss', $log_user, $log_module, $log_action, $log_ip); if (!$audit_stmt->execute()) throw new Exception('Could not record media audit entry.');
        }
        if (!$conn->commit()) throw new Exception('Could not commit uploaded media.');
        $committed = true; $transaction_started = false;
        $cleanup_failures = [];
        foreach ($old_files as $old) { if (is_file($old['path']) && !unlink($old['path'])) $cleanup_failures[] = $old['path']; }
        if ($cleanup_failures) error_log('Media replacement committed but old-file cleanup failed: ' . implode(', ', $cleanup_failures));
        echo json_encode(['success' => true, 'message' => "Successfully uploaded $successful_uploads file(s)!", 'cleanup_warning' => $cleanup_failures ? 'Old media files were retained because physical cleanup failed.' : null]);
    } catch (Throwable $e) {
        if ($transaction_started && !$committed) $conn->rollback();
        foreach ($new_files as $path) { if (is_file($path)) unlink($path); }
        throw $e;
    }
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>
