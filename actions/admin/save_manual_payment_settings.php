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
    $seedStmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES ('manual_payment_instructions', ?, 'Customer-managed manual payment methods') ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key)");
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

    $postedMethods = $_POST['methods'] ?? null;
    if (!is_array($postedMethods) || count($postedMethods) > MANUAL_PAYMENT_MAX_METHODS || count($postedMethods) < count($current)) {
        throw new RuntimeException('The payment method list is incomplete or exceeds the 50-method limit. Refresh and try again.');
    }
    foreach ($current as $key => $_method) {
        if (!array_key_exists($key, $postedMethods)) throw new RuntimeException('Payment methods cannot be deleted from this screen. Disable a method to retire it.');
    }

    $settings = [];
    foreach ($postedMethods as $key => $posted) {
        if (!is_string($key) || !manual_payment_method_key_is_valid($key) || !is_array($posted)) {
            throw new RuntimeException('Invalid payment method data. Refresh and try again.');
        }
        $name = manual_payment_validate_method_name($posted['name'] ?? null);
        $accountName = manual_payment_clean_setting_text($posted['account_name'] ?? '', 120);
        $accountNumber = manual_payment_clean_setting_text($posted['account_number'] ?? '', 120);
        $details = manual_payment_clean_setting_text($posted['details'] ?? '', 1000);
        if (($posted['account_name'] ?? '') !== '' && $accountName === '') throw new RuntimeException($name . ' account name exceeds 120 characters or contains invalid text.');
        if (($posted['account_number'] ?? '') !== '' && $accountNumber === '') throw new RuntimeException($name . ' account number exceeds 120 characters or contains invalid text.');
        if (($posted['details'] ?? '') !== '' && $details === '') throw new RuntimeException($name . ' instructions exceed 1,000 characters or contain invalid text.');
        $enabled = isset($posted['enabled']) && $posted['enabled'] === '1';
        if ($enabled && ($accountName === '' || $accountNumber === '')) throw new RuntimeException('Enter an account holder name and account or mobile number for every active method.');
        $settings[$key] = [
            'name' => $name,
            'enabled' => $enabled,
            'account_name' => $accountName,
            'account_number' => $accountNumber,
            'details' => $details,
            'qr_path' => $current[$key]['qr_path'] ?? '',
            'sort_order' => count($settings),
        ];
    }

    foreach ($settings as $key => &$method) {
        $upload = $_FILES['qr_' . $key] ?? null;
        $uploadError = is_array($upload) ? (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        $removeQr = isset($postedMethods[$key]['remove_qr']) && $postedMethods[$key]['remove_qr'] === '1';
        if ($uploadError !== UPLOAD_ERR_NO_FILE && $removeQr) throw new RuntimeException('Choose either a replacement QR image or remove the current image for ' . $method['name'] . '.');
        if ($removeQr && $method['qr_path'] !== '') {
            $oldFiles[] = $method['qr_path'];
            $method['qr_path'] = '';
        }
        if ($uploadError === UPLOAD_ERR_NO_FILE) continue;
        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('QR images must be 5 MiB or smaller.');
        if ($uploadError !== UPLOAD_ERR_OK || !is_array($upload) || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
            throw new RuntimeException('Unable to receive the ' . $method['name'] . ' QR image.');
        }
        $temporaryPath = (string)$upload['tmp_name'];
        $uploadSize = filesize($temporaryPath);
        if ($uploadSize === false || $uploadSize < 1 || $uploadSize > MANUAL_PAYMENT_MAX_PROOF_BYTES) throw new RuntimeException('QR images must be 5 MiB or smaller.');
        $image = manual_payment_store_qr_image($temporaryPath);
        $newFiles[] = $image['path'];
        $relative = 'assets/uploads/payment-qrs/' . $image['filename'];
        $previous = $method['qr_path'];
        $method['qr_path'] = $relative;
        if ($previous !== '' && $previous !== $relative) $oldFiles[] = $previous;
    }
    unset($method);
    $retainedQrPaths = array_column($settings, 'qr_path');
    $oldFiles = array_values(array_unique(array_filter($oldFiles, static fn(string $path): bool => !in_array($path, $retainedQrPaths, true))));

    $json = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)");
    if (!$stmt) throw new RuntimeException('Unable to prepare payment instructions.');
    $key = 'manual_payment_instructions';
    $description = 'Customer-managed manual payment methods';
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
    $qrPaths = [];
    foreach ($settings as $methodId => $method) $qrPaths[$methodId] = $method['qr_path'];
    echo json_encode(['success' => true, 'message' => 'Customer payment instructions saved.', 'qr_paths' => $qrPaths], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    if ($transactionStarted) try { $conn->rollback(); } catch (Throwable $ignored) {}
    foreach ($newFiles as $newFile) @unlink($newFile);
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Payment instructions could not be saved.']);
}
