<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrator access is required.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Use POST to save payment instructions.']);
    exit;
}
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/manual_payment.php';

$projectRoot = dirname(__DIR__, 2);
$newFiles = [];
$oldFiles = [];
$transactionStarted = false;
try {
    if (!$conn->begin_transaction()) throw new RuntimeException('Unable to start settings transaction.');
    $transactionStarted = true;
    $seed = '{"gcash":{"enabled":false,"account_name":"","account_number":"","details":"","qr_path":""},"maya":{"enabled":false,"account_name":"","account_number":"","details":"","qr_path":""},"bank_transfer":{"enabled":false,"account_name":"","account_number":"","details":"","qr_path":""}}';
    $seedStmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES ('manual_payment_instructions', ?, 'Customer payment instructions for GCash, Maya, and bank transfer') ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key)");
    if (!$seedStmt) throw new RuntimeException('Unable to initialize payment instructions.');
    $seedStmt->bind_param('s', $seed);
    if (!$seedStmt->execute()) throw new RuntimeException('Unable to initialize payment instructions.');
    $lockedSettings = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'manual_payment_instructions' LIMIT 1 FOR UPDATE");
    if (!$lockedSettings) throw new RuntimeException('Unable to lock current payment instructions.');
    $currentRow = $lockedSettings->fetch_assoc();
    if (!$currentRow) throw new RuntimeException('Unable to load current payment instructions.');
    $current = manual_payment_decode_instructions((string)$currentRow['setting_value']);

    $hoursRaw = trim((string)($_POST['deadline_hours'] ?? ''));
    if (!ctype_digit($hoursRaw) || (int)$hoursRaw < 1 || (int)$hoursRaw > 168) throw new RuntimeException('Deadline must be between 1 and 168 hours.');

    $settings = [];
    foreach (MANUAL_PAYMENT_METHODS as $key => $label) {
        $posted = $_POST['methods'][$key] ?? [];
        if (!is_array($posted)) throw new RuntimeException('Invalid payment instructions.');
        $enabled = isset($posted['enabled']) && $posted['enabled'] === '1';
        $name = trim((string)($posted['account_name'] ?? ''));
        $number = trim((string)($posted['account_number'] ?? ''));
        $details = trim((string)($posted['details'] ?? ''));
        if (strlen($name) > 120 || strlen($number) > 120 || strlen($details) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $name . $number . $details)) {
            throw new RuntimeException($label . ' instructions exceed the allowed length or contain invalid characters.');
        }
        if ($enabled && ($name === '' || $number === '')) throw new RuntimeException('Enter an account name and account number for every enabled method.');
        $settings[$key] = [
            'enabled' => $enabled,
            'account_name' => $name,
            'account_number' => $number,
            'details' => $details,
            'qr_path' => $current[$key]['qr_path'],
        ];
    }
    if (!array_filter($settings, static fn(array $method): bool => $method['enabled'])) throw new RuntimeException('Enable at least one customer payment method before saving.');

    foreach (MANUAL_PAYMENT_METHODS as $key => $label) {
        $field = 'qr_' . $key;
        $upload = $_FILES[$field] ?? null;
        if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
            throw new RuntimeException('Unable to receive the ' . $label . ' QR image.');
        }
        $image = manual_payment_store_qr_image((string)$upload['tmp_name']);
        $newFiles[] = $image['path'];
        $relative = 'assets/uploads/payment-qrs/' . $image['filename'];
        $settings[$key]['qr_path'] = $relative;
        $previous = $current[$key]['qr_path'];
        if ($previous !== '' && $previous !== $relative) $oldFiles[] = $previous;
    }

    $json = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)");
    if (!$stmt) throw new RuntimeException('Unable to prepare payment instructions.');
    $key = 'manual_payment_instructions';
    $description = 'Customer payment instructions for GCash, Maya, and bank transfer';
    $stmt->bind_param('sss', $key, $json, $description);
    if (!$stmt->execute()) throw new RuntimeException('Unable to save payment instructions.');
    $key = 'manual_payment_deadline_hours';
    $hours = (string)(int)$hoursRaw;
    $description = 'Hours to submit or resubmit manual payment proof';
    $stmt->bind_param('sss', $key, $hours, $description);
    if (!$stmt->execute()) throw new RuntimeException('Unable to save the payment deadline.');
    if (!$conn->commit()) throw new RuntimeException('Unable to commit payment settings.');
    $transactionStarted = false;

    foreach ($oldFiles as $oldFile) {
        $safe = manual_payment_safe_qr_path($oldFile);
        if ($safe !== '') @unlink($projectRoot . '/' . $safe);
    }
    echo json_encode(['success' => true, 'message' => 'Customer payment instructions saved.']);
} catch (Throwable $e) {
    if ($transactionStarted) try { $conn->rollback(); } catch (Throwable $ignored) {}
    foreach ($newFiles as $newFile) @unlink($newFile);
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Payment instructions could not be saved.']);
}
