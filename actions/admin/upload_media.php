<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

// 1. Auth Guard: Only Super Admins manage CMS
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
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

    $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
    
    // CRITICAL FIX: Explicitly define which slots are strictly 1-to-1
    $is_strict_slot = false;
    if (strpos($website_slot, 'home-') === 0) {
        $is_strict_slot = true; 
    }

    try {
        $conn->begin_transaction();
        
        $file_count = count($_FILES['fileInput']['name']);

        // 2. ONLY delete old images if it is a 1-to-1 strict homepage slot!
        if ($is_strict_slot) {
            $stmt_check = $conn->prepare("SELECT id, file_path FROM media_cms WHERE slot_assignment = ?");
            $stmt_check->bind_param("s", $website_slot);
            $stmt_check->execute();
            $res = $stmt_check->get_result();

            // FIX: Use a WHILE loop to scrub ALL duplicates if they somehow got orphaned in the DB
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
            
            // FIX: Strictly override loop to only process the FIRST file for 1-to-1 slots
            $file_count = 1;
        }

        // 3. Loop through uploaded files and insert them
        $successful_uploads = 0;

        for ($i = 0; $i < $file_count; $i++) {
            
            // Skip failed individual files or invalid types
            if ($_FILES['fileInput']['error'][$i] !== UPLOAD_ERR_OK) continue; 
            if (!in_array($_FILES['fileInput']['type'][$i], $allowed_types)) continue; 

            $ext = pathinfo($_FILES['fileInput']['name'][$i], PATHINFO_EXTENSION);
            
            // FIX: Use uniqid() instead of time() so bulk/fast uploads NEVER collide on disk
            $new_filename = $website_slot . '_' . uniqid() . '_' . $i . '.' . $ext; 
            
            $destination = $upload_dir . $new_filename;
            $db_file_path = 'assets/uploads/' . $new_filename;

            // Move the physical file to the uploads folder
            if (move_uploaded_file($_FILES['fileInput']['tmp_name'][$i], $destination)) {
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
            throw new Exception("No valid files were uploaded. Please check file types and sizes.");
        }

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>