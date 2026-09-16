<?php
require_once __DIR__ . '/../../includes/session_init.php';
require '../../config/db_connect.php';
require_once __DIR__ . '/../../includes/receptionist_faq.php';

header('Content-Type: application/json');
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!is_string($_SESSION['csrf_token'] ?? null) || !is_string($client_csrf_token) || $client_csrf_token === '' || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
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
$seen_faq_ids = [];
$seen_faq_questions = [];
foreach ($faq_items as $faq) {
    if (!is_array($faq)) continue;
    $hasContent = trim((string)($faq['question'] ?? $faq['q'] ?? '')) !== '' || trim((string)($faq['answer'] ?? $faq['a'] ?? '')) !== '';
    if (!$hasContent) continue;
    $clean = receptionist_faq_normalize_item($faq);
    if ($clean === null) {
        echo json_encode(['success' => false, 'message' => 'Each FAQ needs a valid question, answer, category, and phrase set.']);
        exit;
    }
    $idKey = strtolower($clean['id']);
    $questionKey = strtolower($clean['question']);
    if (isset($seen_faq_ids[$idKey]) || isset($seen_faq_questions[$questionKey])) {
        echo json_encode(['success' => false, 'message' => 'FAQ ids and questions must be unique.']);
        exit;
    }
    if (count($clean_faq) >= 50) {
        echo json_encode(['success' => false, 'message' => 'You can save up to 50 FAQs.']);
        exit;
    }
    $seen_faq_ids[$idKey] = true;
    $seen_faq_questions[$questionKey] = true;
    $clean_faq[] = $clean;
}

$settings = [
    'support_intro' => trim((string)($_POST['support_intro'] ?? '')),
    'support_contact_heading' => trim((string)($_POST['support_contact_heading'] ?? '')),
    'support_contact_description' => trim((string)($_POST['support_contact_description'] ?? '')),
    'support_faq_json' => receptionist_faq_json($clean_faq),
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
