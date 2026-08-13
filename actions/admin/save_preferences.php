<?php
session_start();
require '../../config/db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo "Error: Unauthorized access."; exit();
}

$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo "Error|CSRF validation failed."; exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Checkboxes
    $maintenance_mode = isset($_POST['maintenance_mode']) ? 'true' : 'false';
    $allow_walkins = isset($_POST['allow_walkins']) ? 'true' : 'false';

    // Build our settings array
    $settings = [
        'maintenance_mode' => $maintenance_mode,
        'allow_walkins' => $allow_walkins,

        'event_type_wedding' => floatval($_POST['event_type_wedding'] ?? 10000),
        'event_type_birthday' => floatval($_POST['event_type_birthday'] ?? 5000),
        'catering_silver' => floatval($_POST['catering_silver'] ?? 750),
        'catering_gold' => floatval($_POST['catering_gold'] ?? 1200),
        'catering_platinum' => floatval($_POST['catering_platinum'] ?? 1800),
        'av_setup' => floatval($_POST['av_setup'] ?? 5000),
        
        // Business Information
        'biz_name' => trim($_POST['biz_name'] ?? 'Sevilla360'),
        'biz_tagline' => trim($_POST['biz_tagline'] ?? 'LUXURY RESORT & EVENTS'),
        'biz_email' => trim($_POST['biz_email'] ?? 'reservations@sevilla360.com'),
        'biz_phone' => trim($_POST['biz_phone'] ?? '+63 912 345 6789'),
        'biz_address' => trim($_POST['biz_address'] ?? '123 Resort Drive, Paradise City'),
        'biz_policies' => trim($_POST['biz_policies'] ?? ''),
    ];

    // Upsert into database
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    
    foreach ($settings as $key => $val) {
        $str_val = (string)$val;
        $stmt->bind_param("sss", $key, $str_val, $str_val);
        $stmt->execute();
    }
    $stmt->close();
    
    echo "Success";
}
$conn->close();
?>