<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';

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

    // Ensure upload directory exists
    $upload_dir = '../../assets/uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
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

    try {
        $conn->begin_transaction();
        
        $file_count = count($_FILES['fileInput']['name']);

        // ONLY delete old images if it is a 1-to-1 strict homepage slot
        if ($is_strict_slot) {
            $stmt_check = $conn->prepare("SELECT id, file_path FROM media_cms WHERE slot_assignment = ?");
            $stmt_check->bind_param("s", $website_slot);
            $stmt_check->execute();
            $res = $stmt_check->get_result();

            if ($res->num_rows > 0) {
                while ($old_media = $res->fetch_assoc()) {
                    if (file_exists('../../' . $old_media['file_path'])) {
                        unlink('../../' . $old_media['file_path']);
                    }
                    $stmt_del = $conn->prepare("DELETE FROM media_cms WHERE id = ?");
                    $stmt_del->bind_param("i", $old_media['id']);
                    $stmt_del->execute();
                }
            }
            // Strictly override loop to only process the FIRST file for 1-to-1 slots
            $file_count = 1;
        }

        // Loop through uploaded files and insert them
        $successful_uploads = 0;

        for ($i = 0; $i < $file_count; $i++) {
            
            // Skip failed individual uploads
            if ($_FILES['fileInput']['error'][$i] !== UPLOAD_ERR_OK) continue; 
            
            $tmp_path = $_FILES['fileInput']['tmp_name'][$i];
            
            // =========================================================================
            // SECURITY FIX: DEEP BINARY VERIFICATION
            // 1. Read the actual file bytes to get the true MIME type
            $true_mime = $finfo->file($tmp_path);
            
            // 2. Reject if it's not a verified image
            if (!array_key_exists($true_mime, $allowed_mimes)) {
                continue; // Skip malicious or invalid files
            }
            
            // 3. Optional Secondary Check: Ensure it has valid image dimensions
            if (getimagesize($tmp_path) === false) {
                continue; // Skip fake image files
            }
            // =========================================================================

            // =========================================================================
            // SECURITY FIX: FILENAME SANITIZATION
            // Force the extension based on the TRUE binary mime, completely ignoring the user's uploaded filename
            $safe_ext = $allowed_mimes[$true_mime];
            
            // Sanitize slot assignment just in case it was tampered with
            $safe_slot = preg_replace('/[^a-zA-Z0-9_-]/', '', $website_slot);
            
            // Generate a bulletproof, random filename: slot_randomHex.ext
            $new_filename = $safe_slot . '_' . bin2hex(random_bytes(8)) . '.' . $safe_ext; 
            // =========================================================================
            
            $destination = $upload_dir . $new_filename;
            $db_file_path = 'assets/uploads/' . $new_filename;

            // Move the physical file to the uploads folder
            if (move_uploaded_file($tmp_path, $destination)) {
                // Save to Database
                $stmt_insert = $conn->prepare("INSERT INTO media_cms (file_name, file_path, media_type, slot_assignment) VALUES (?, ?, ?, ?)");
                $stmt_insert->bind_param("ssss", $new_filename, $db_file_path, $media_type, $website_slot);
                $stmt_insert->execute();
                $successful_uploads++;
            }
        }

        if ($successful_uploads > 0) {
            
            // AUDIT LOG
            if (isset($_SESSION['user_id'])) {
                $log_user = $_SESSION['user_id'];
                $log_module = 'Media CMS';
                $log_action = "Uploaded $successful_uploads file(s) to slot: $website_slot";
                $log_ip = $_SERVER['REMOTE_ADDR'];

                $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, ?, ?, ?)");
                $audit_stmt->bind_param("isss", $log_user, $log_module, $log_action, $log_ip);
                $audit_stmt->execute();
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => "Successfully uploaded $successful_uploads file(s)!"]);
        } else {
            throw new Exception("No valid files were uploaded. Please ensure they are valid JPG, PNG, or WEBP images.");
        }

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>