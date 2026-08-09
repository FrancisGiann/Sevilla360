<?php
session_start();
require '../../config/db_connect.php';

// 1. Security Check: Only Super Admins can change system settings
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    echo "Error: Unauthorized access.";
    exit();
}
// ==========================================
// CSRF PROTECTION GUARD (TEXT)
// ==========================================
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo "Error|CSRF validation failed. Unauthorized request.";
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 2. Checkboxes in HTML only send data if they are checked. 
    // If they are missing from $_POST, it means they are 'false' (unchecked).
    $maintenance_mode = isset($_POST['maintenance_mode']) ? 'true' : 'false';
    $allow_walkins = isset($_POST['allow_walkins']) ? 'true' : 'false';

    // 3. Update Maintenance Mode
    // We use "ON DUPLICATE KEY UPDATE" so it creates the setting if it doesn't exist, or updates it if it does.
    $stmt1 = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('maintenance_mode', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt1->bind_param("ss", $maintenance_mode, $maintenance_mode);
    $stmt1->execute();
    $stmt1->close();

    // 4. Update Allow Walk-ins
    $stmt2 = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('allow_walkins', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt2->bind_param("ss", $allow_walkins, $allow_walkins);
    $stmt2->execute();
    $stmt2->close();

    // 5. Tell Javascript it was successful
    echo "Success";
}
$conn->close();
?>