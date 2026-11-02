<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../includes/catering_packages.php';
require_once __DIR__ . '/../../includes/request_context.php';
require_once __DIR__ . '/../../includes/session_account_guard.php';
require '../../config/db_connect.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$respond = static function (int $status, array $payload) use ($conn): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $conn->close();
    exit;
};

if (($_SESSION['role'] ?? null) !== 'admin') {
    $respond(403, ['success' => false, 'message' => 'Unauthorized access.']);
}
$sessionCsrf = $_SESSION['csrf_token'] ?? null;
$requestCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!is_string($sessionCsrf) || $sessionCsrf === '' || !is_string($requestCsrf) || $requestCsrf === '' || !hash_equals($sessionCsrf, $requestCsrf)) {
    $respond(403, ['success' => false, 'message' => 'CSRF validation failed.']);
}
if (!session_account_require_active_json($conn, ['admin'], ['success' => false, 'message' => 'Unauthorized access.'], ['success' => false, 'message' => 'Account status could not be verified.'], 403)) exit;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $respond(405, ['success' => false, 'message' => 'Use POST to save a catering package.']);
}

$packageKey = $_POST['package_key'] ?? null;
if (!is_string($packageKey)) $respond(422, ['success' => false, 'message' => 'Choose a valid catering package.']);
try {
    $settings = catering_package_settings_for_post($packageKey, $_POST);
} catch (InvalidArgumentException $error) {
    $respond(422, ['success' => false, 'message' => $error->getMessage()]);
}
$package = catering_packages_from_settings($settings)[$packageKey];

$transactionStarted = false;
try {
    if (!$conn->begin_transaction()) throw new RuntimeException('Unable to start the settings transaction.');
    $transactionStarted = true;
    $settingsStatement = $conn->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?');
    if (!$settingsStatement) throw new RuntimeException('Settings statement unavailable.');
    foreach ($settings as $settingKey => $settingValue) {
        $value = (string)$settingValue;
        $settingsStatement->bind_param('sss', $settingKey, $value, $value);
        if (!$settingsStatement->execute()) throw new RuntimeException('Settings save failed.');
    }
    $settingsStatement->close();

    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false) throw new RuntimeException('Authenticated administrator is unavailable for audit logging.');
    $auditModule = 'System Settings';
    $auditAction = 'Updated catering package ' . $package['package_id'];
    $auditIp = request_client_ip();
    $auditStatement = $conn->prepare('INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, ?, ?, ?)');
    if (!$auditStatement) throw new RuntimeException('Audit statement unavailable.');
    $auditStatement->bind_param('isss', $userId, $auditModule, $auditAction, $auditIp);
    if (!$auditStatement->execute()) throw new RuntimeException('Audit entry could not be recorded.');
    $auditStatement->close();

    if (!$conn->commit()) throw new RuntimeException('Settings commit failed.');
    $transactionStarted = false;
    $respond(200, ['success' => true, 'message' => 'Catering package saved.', 'package' => $package]);
} catch (Throwable $error) {
    if ($transactionStarted) $conn->rollback();
    $respond(500, ['success' => false, 'message' => 'Unable to save this catering package. Your other settings were not changed.']);
}
