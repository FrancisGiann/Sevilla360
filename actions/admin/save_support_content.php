<?php
require_once __DIR__ . '/../../includes/session_init.php';
require '../../config/db_connect.php';

header('Content-Type: application/json');
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
    exit;
}

$faq_items = json_decode($_POST['support_faq_json'] ?? '[]', true);
if (!is_array($faq_items)) {
    echo json_encode(['success' => false, 'message' => 'Invalid FAQ configuration.']);
    exit;
}

$clean_faq = [];
foreach ($faq_items as $faq) {
    if (!is_array($faq)) continue;
    $question = trim((string)($faq['question'] ?? ''));
    $answer = trim((string)($faq['answer'] ?? ''));
    if ($question === '' && $answer === '') continue;
    if ($question === '' || $answer === '' || mb_strlen($question) > 240 || mb_strlen($answer) > 3000) {
        echo json_encode(['success' => false, 'message' => 'Each FAQ needs a question and answer.']);
        exit;
    }
    $clean_faq[] = ['question' => $question, 'answer' => $answer];
}

$settings = [
    'support_intro' => trim((string)($_POST['support_intro'] ?? '')),
    'support_contact_heading' => trim((string)($_POST['support_contact_heading'] ?? '')),
    'support_contact_description' => trim((string)($_POST['support_contact_description'] ?? '')),
    'support_faq_json' => json_encode($clean_faq, JSON_UNESCAPED_SLASHES),
    'support_privacy' => trim((string)($_POST['support_privacy'] ?? '')),
    'support_terms' => trim((string)($_POST['support_terms'] ?? ''))
];

if ($settings['support_intro'] === '' || $settings['support_contact_heading'] === '' || $settings['support_contact_description'] === '') {
    echo json_encode(['success' => false, 'message' => 'Page introduction and contact content are required.']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
foreach ($settings as $key => $value) {
    $stmt->bind_param('sss', $key, $value, $value);
    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'message' => 'Unable to save support content.']);
        exit;
    }
}
$stmt->close();
$conn->close();
echo json_encode(['success' => true, 'message' => 'Support content saved.']);
