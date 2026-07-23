<?php
session_start();
header('Content-Type: application/json');

// --- TEMPORARY DEBUG BLOCK START ---
$post_max = ini_get('post_max_size');
$up_max = ini_get('upload_max_filesize');
$content_len_bytes = $_SERVER['CONTENT_LENGTH'] ?? 0;
$content_len_mb = round($content_len_bytes / 1048576, 2);

if (empty($_POST) && empty($_FILES) && $content_len_bytes > 0) {
    echo json_encode([
        'success' => false, 
        'message' => "DEBUG FAIL | PostMax: $post_max | UpMax: $up_max | Uploaded Size: {$content_len_mb}MB"
    ]);
    exit;
}
// --- TEMPORARY DEBUG BLOCK END ---
require_once '../../config/db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // NEW BLOCK: Detect if post_max_size was exceeded by checking Content-Length
    if (empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
        echo json_encode(['success' => false, 'message' => 'Upload failed. The image file size exceeds the server limits.']);
        exit;
    }

    $media_type = $_POST['media_type'] ?? '';
    $website_slot = $_POST['website_slot'] ?? '';
    
    if (empty($media_type) || empty($website_slot)) {
        echo json_encode(['success' => false, 'message' => 'Missing media type or slot assignment.']);
        exit;
    }

    if (!isset($_FILES['fileInput']) || empty($_FILES['fileInput']['name'][0])) {
        echo json_encode(['success' => false, 'message' => 'No files uploaded.']);
        exit;
    }

    $upload_dir = '../../assets/uploads/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

    $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
    
    // Determine if this is a slot that can only hold ONE image (Hero or 360)
    $is_strict_slot = ($website_slot === 'home-hero' || strpos($website_slot, '_360') !== false);

    try {
        $conn->begin_transaction();

        // If it's a strict slot, delete the old one first!
        if ($is_strict_slot) {
            $stmt_check = $conn->prepare("SELECT id, file_path FROM media_cms WHERE slot_assignment = ?");
            $stmt_check->bind_param("s", $website_slot);
            $stmt_check->execute();
            $res = $stmt_check->get_result();

            if ($res->num_rows > 0) {
                $old_media = $res->fetch_assoc();
                if (file_exists('../../' . $old_media['file_path'])) unlink('../../' . $old_media['file_path']);
                $stmt_del = $conn->prepare("DELETE FROM media_cms WHERE id = ?");
                $stmt_del->bind_param("i", $old_media['id']);
                $stmt_del->execute();
            }
        }

        // Loop through all uploaded files
        $file_count = count($_FILES['fileInput']['name']);
        for ($i = 0; $i < $file_count; $i++) {
            
            if ($_FILES['fileInput']['error'][$i] !== UPLOAD_ERR_OK) continue; // Skip failed individual files
            if (!in_array($_FILES['fileInput']['type'][$i], $allowed_types)) continue; // Skip invalid types

            $ext = pathinfo($_FILES['fileInput']['name'][$i], PATHINFO_EXTENSION);
            $new_filename = $website_slot . '_' . time() . '_' . $i . '.' . $ext; // Added $i so they don't overwrite each other in the loop
            $destination = $upload_dir . $new_filename;
            $db_file_path = 'assets/uploads/' . $new_filename;

            if (move_uploaded_file($_FILES['fileInput']['tmp_name'][$i], $destination)) {
                $stmt_insert = $conn->prepare("INSERT INTO media_cms (file_name, file_path, media_type, slot_assignment) VALUES (?, ?, ?, ?)");
                $stmt_insert->bind_param("ssss", $new_filename, $db_file_path, $media_type, $website_slot);
                $stmt_insert->execute();
            }
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => "Successfully uploaded $file_count file(s)!"]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>