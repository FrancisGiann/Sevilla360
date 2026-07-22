<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

if (!isset($data['id'])) {
    echo json_encode(['success' => false, 'message' => 'Media ID missing.']);
    exit;
}

$media_id = intval($data['id']);

try {
    // Get the file path first
    $stmt = $conn->prepare("SELECT file_path, slot_assignment FROM media_cms WHERE id = ?");
    $stmt->bind_param("i", $media_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows === 0) throw new Exception("Media not found.");
    $media = $res->fetch_assoc();

    // Prevent deletion of core system slots
    if ($media['slot_assignment'] === 'home-hero' || strpos($media['slot_assignment'], '_360') !== false) {
        throw new Exception("Core website slots and 360 Panoramas cannot be deleted, they can only be replaced.");
    }

    // Delete physically from folder
    $physical_path = '../../' . $media['file_path'];
    if (file_exists($physical_path)) {
        unlink($physical_path);
    }

    // Delete from database
    $stmt_del = $conn->prepare("DELETE FROM media_cms WHERE id = ?");
    $stmt_del->bind_param("i", $media_id);
    $stmt_del->execute();

    echo json_encode(['success' => true, 'message' => 'Media deleted successfully.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>