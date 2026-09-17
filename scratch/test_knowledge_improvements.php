<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/receptionist_ai.php';
require_once __DIR__ . '/../includes/receptionist_faq.php';
require_once __DIR__ . '/../includes/receptionist_knowledge.php';

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    fwrite(STDERR, "DB Connection failed: " . $conn->connect_error . "\n");
    exit(1);
}

$faqs = receptionist_faq_load($conn);
$records = receptionist_public_knowledge_records($conn, $faqs);

echo "Loaded " . count($records) . " public knowledge records.\n\n";

$queries = [
    'how much are the venues?' => 'en',
    'how much is a hotel room?' => 'en',
    'how much is an event?' => 'en',
    'how much is the villa?' => 'en',
    'magkano ang rooms?' => 'fil',
];

foreach ($queries as $query => $lang) {
    echo "========================================\n";
    echo "QUERY: \"$query\" (lang: $lang)\n";
    echo "========================================\n";
    $result = receptionist_knowledge_reply($records, $query, $lang);
    if ($result === null) {
        echo "REPLY: (null - falls through to AI)\n\n";
        continue;
    }

    echo "ACTION: " . ($result['action'] ?? 'none') . "\n";
    if (!empty($result['quick_replies'])) {
        echo "QUICK REPLIES: " . json_encode($result['quick_replies']) . "\n";
    }
    echo "REPLY:\n" . ($result['reply'] ?? '') . "\n\n";
}
