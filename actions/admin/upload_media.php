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
    
    if (empty($media_type) || empty($website_slot)) {
        echo json_encode(['success' => false, 'message' => 'Missing media type or slot assignment.']);
        exit;
    }

    if (!isset($_FILES['fileInput']) || $_FILES['fileInput']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error occurred.']);
        exit;
    }

    $file = $_FILES['fileInput'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
    
    if (!in_array($file['type'], $allowed_types)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file format. Only JPG, PNG, and WEBP are allowed.']);
        exit;
    }

    // Generate a clean, unique file name
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $new_filename = $website_slot . '_' . time() . '.' . $ext;
    $upload_dir = '../../assets/uploads/';
    $destination = $upload_dir . $new_filename;
    
    // The path we will save in the database
    $db_file_path = 'assets/uploads/' . $new_filename;

    try {
        $conn->begin_transaction();

        // 2. Check if this slot already has an image (unless it's the general gallery)
        if ($website_slot !== 'gallery') {
            $stmt_check = $conn->prepare("SELECT id, file_path FROM media_cms WHERE slot_assignment = ?");
            $stmt_check->bind_param("s", $website_slot);
            $stmt_check->execute();
            $res = $stmt_check->get_result();

            if ($res->num_rows > 0) {
                // Delete old record and physical file
                $old_media = $res->fetch_assoc();
                if (file_exists('../../' . $old_media['file_path'])) {
                    unlink('../../' . $old_media['file_path']);
                }
                $stmt_del = $conn->prepare("DELETE FROM media_cms WHERE id = ?");
                $stmt_del->bind_param("i", $old_media['id']);
                $stmt_del->execute();
            }
        }

        // 3. Move the physical file to the uploads folder
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new Exception("Failed to move uploaded file to server directory.");
        }

        // 4. Save to Database
        $stmt_insert = $conn->prepare("INSERT INTO media_cms (file_name, file_path, media_type, slot_assignment) VALUES (?, ?, ?, ?)");
        $stmt_insert->bind_param("ssss", $new_filename, $db_file_path, $media_type, $website_slot);
        $stmt_insert->execute();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Media uploaded successfully!']);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>