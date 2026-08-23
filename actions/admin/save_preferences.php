<?php
require_once __DIR__ . '/../../includes/session_init.php';
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

    $refund_fee_raw = $_POST['refund_fee_percent'] ?? null;
    if (!is_string($refund_fee_raw)) {
        echo "Error|Enter a payment-processing fee between 0 and 100."; exit;
    }
    $refund_fee_raw = trim($refund_fee_raw);
    if (!preg_match('/\A(?:\d+(?:\.\d{1,2})?|\.\d{1,2})\z/D', $refund_fee_raw)) {
        echo "Error|Enter a valid payment-processing fee between 0 and 100."; exit;
    }
    $refund_fee_value = (float)$refund_fee_raw;
    if (!is_finite($refund_fee_value) || $refund_fee_value < 0 || $refund_fee_value > 100) {
        echo "Error|Enter a payment-processing fee between 0 and 100."; exit;
    }
    $refund_fee_percent = number_format($refund_fee_value, 2, '.', '');

    // Build our settings array
    $settings = [
        'maintenance_mode' => $maintenance_mode,
        'allow_walkins' => $allow_walkins,
        'refund_fee_percent' => $refund_fee_percent,

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

    $social_links = json_decode($_POST['social_links_json'] ?? '[]', true);
    if (!is_array($social_links)) { echo "Error|Invalid social media configuration."; exit; }
    $clean_social_links = [];
    foreach ($social_links as $social) {
        if (!is_array($social)) continue;
        $label = trim((string)($social['label'] ?? ''));
        $url = trim((string)($social['url'] ?? ''));
        if ($label === '' && $url === '') continue;
        if ($label === '' || strlen($label) > 40 || strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $url)) {
            echo "Error|Each social media link needs a name and a valid http(s) URL."; exit;
        }
        $clean_social_links[] = ['label' => $label, 'url' => $url];
    }
    $settings['social_links_json'] = json_encode($clean_social_links, JSON_UNESCAPED_SLASHES);

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
