<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/receptionist_ai.php';
require_once __DIR__ . '/../includes/receptionist_knowledge.php';
require_once __DIR__ . '/../config/db_connect.php';

$conn = new mysqli($host, $username, $password, $database);
$faqs = receptionist_faq_load($conn);
$venueCatalog = receptionist_ai_public_venue_catalog($conn);
$records = receptionist_public_knowledge_records($conn, $faqs);
$baseSlots = ['intent' => null, 'group_size' => null];

$testCases = [
    [
        'id' => 1,
        'query' => 'may parking ba?',
        'expected' => 'Should give direct "Yes! Free parking..." answer',
        'validate' => function (?array $res): bool {
            return $res !== null && str_starts_with($res['reply'] ?? '', 'Yes! Free parking') && ($res['quick_replies'] ?? []) === ['See rates', 'Book now', 'More questions'];
        },
    ],
    [
        'id' => 2,
        'query' => 'do you have wifi?',
        'expected' => 'Should give direct "Yes! Free wifi..." answer',
        'validate' => function (?array $res): bool {
            return $res !== null && str_starts_with($res['reply'] ?? '', 'Yes! Free wifi') && ($res['quick_replies'] ?? []) === ['See rates', 'Book now', 'More questions'];
        },
    ],
    [
        'id' => 3,
        'query' => 'may pool ba kayo?',
        'expected' => 'Should give direct pool answer',
        'validate' => function (?array $res): bool {
            return $res !== null && str_contains($res['reply'] ?? '', 'swimming pool') && ($res['quick_replies'] ?? []) === ['See rates', 'Book now', 'More questions'];
        },
    ],
    [
        'id' => 4,
        'query' => 'pwede po ba mag walk in?',
        'expected' => 'Should NOT fall through to AI (should match policy/booking)',
        'validate' => function (?array $res): bool {
            return $res !== null && ($res['mode'] ?? '') === 'knowledge';
        },
    ],
    [
        'id' => 5,
        'query' => 'pano pumunta sa inyo?',
        'expected' => 'Should match contact/location',
        'validate' => function (?array $res): bool {
            return $res !== null && str_contains(strtolower($res['reply'] ?? ''), 'address');
        },
    ],
    [
        'id' => 6,
        'query' => 'magkano po?',
        'expected' => 'Should show category summary',
        'validate' => function (?array $res): bool {
            return $res !== null && str_contains($res['reply'] ?? '', 'starting rates by category') && ($res['quick_replies'] ?? []) === ['Event Halls', 'Hotel Rooms', 'Resort Villa'];
        },
    ],
    [
        'id' => 7,
        'query' => 'i want to book a room for 2',
        'expected' => 'Should pre-fill group_size=2 and NOT ask for guest count again',
        'validate' => function (?array $res): bool {
            return $res !== null
                && ($res['slots']['group_size'] ?? null) === 2
                && !in_array('group_size', $res['missing_slots'] ?? [], true)
                && str_contains($res['reply'] ?? '', '2 guest(s)')
                && !str_contains(strtolower($res['reply'] ?? ''), 'how many guests');
        },
    ],
    [
        'id' => 8,
        'query' => 'solo po ako, book room',
        'expected' => 'Should pre-fill group_size=1',
        'validate' => function (?array $res): bool {
            return $res !== null
                && ($res['slots']['group_size'] ?? null) === 1
                && !in_array('group_size', $res['missing_slots'] ?? [], true);
        },
    ],
    [
        'id' => 9,
        'query' => 'book for 5 guests',
        'expected' => 'Should pre-fill group_size=5',
        'validate' => function (?array $res): bool {
            return $res !== null
                && ($res['slots']['group_size'] ?? null) === 5
                && !in_array('group_size', $res['missing_slots'] ?? [], true);
        },
    ],
    [
        'id' => 10,
        'query' => 'ano yung pinakamura?',
        'expected' => 'Should match price intent',
        'validate' => function (?array $res): bool {
            return $res !== null && (str_contains($res['reply'] ?? '', 'starting rates') || str_contains($res['reply'] ?? '', '₱'));
        },
    ],
    [
        'id' => 11,
        'query' => 'how much is a hotel room?',
        'expected' => 'Check quick replies are action-oriented',
        'validate' => function (?array $res): bool {
            return $res !== null && ($res['quick_replies'] ?? []) === ['Book now', 'What\'s included?', 'Contact us'];
        },
    ],
    [
        'id' => 12,
        'query' => 'thank you!',
        'expected' => 'Falls through to AI (social action)',
        'validate' => function (?array $res): bool {
            return $res === null;
        },
    ],
];

echo "=== Sevilla360 Chat Quality Test Suite ===\n\n";
$allPassed = true;
foreach ($testCases as $tc) {
    $reply = receptionist_knowledge_reply($records, $tc['query'], 'en', $baseSlots);
    $ok = ($tc['validate'])($reply);
    if (!$ok) $allPassed = false;
    $status = $ok ? 'PASS' : 'FAIL';
    echo "Test {$tc['id']}: \"{$tc['query']}\" -> [{$status}]\n";
    echo "  Expected: {$tc['expected']}\n";
    if ($reply) {
        echo "  Result: action=" . ($reply['action'] ?? '') . ", slots=" . json_encode($reply['slots'] ?? []) . "\n";
        echo "  Reply: " . str_replace("\n", " | ", $reply['reply'] ?? '') . "\n";
        echo "  Quick: " . implode(', ', $reply['quick_replies'] ?? []) . "\n";
    } else {
        echo "  Result: (null - falls through to AI)\n";
    }
    echo "\n";
}

echo "Summary: " . ($allPassed ? "ALL 12 TESTS PASSED!" : "SOME TESTS FAILED!") . "\n";
exit($allPassed ? 0 : 1);
