<?php
declare(strict_types=1);

const RECEPTIONIST_FAQ_CATEGORIES = [
    'General',
    'Booking',
    'Payments',
    'Cancellations & Refunds',
    'Venue & Stay',
    'Policies',
];

function receptionist_faq_defaults(): array
{
    return [
        [
            'id' => 'faq-booking-window',
            'category' => 'Booking',
            'question' => 'How long are online dates held?',
            'answer' => 'Online Hotel Room and Resort Villa bookings have a 24-hour payment window. The window pauses while staff review your submitted payment reference and receipt; if proof is rejected, a fresh 24-hour window begins.',
            'phrases' => ['payment deadline', 'how long do I have to pay', 'reservation payment window'],
        ],
        [
            'id' => 'faq-hotel-nightly',
            'category' => 'Venue & Stay',
            'question' => 'Are hotel rooms priced per night?',
            'answer' => "Yes. Hotel stays require at least one night, and the checkout date may coincide with another guest's check-in.",
            'phrases' => ['hotel price', 'nightly rate', 'room per night'],
        ],
        [
            'id' => 'faq-event-inquiry',
            'category' => 'Booking',
            'question' => 'What happens after an Event Hall inquiry?',
            'answer' => 'The resort team reviews the inquiry and finalizes the quotation. No payment deadline starts until that quotation is finalized; then you can submit the reference and receipt from your dashboard.',
            'phrases' => ['event hall booking', 'event quotation', 'event inquiry'],
        ],
        [
            'id' => 'faq-booking-status',
            'category' => 'General',
            'question' => 'Where can I see my booking status?',
            'answer' => 'Sign in and open your User Dashboard to view status, payment information, notifications, and booking details.',
            'phrases' => ['reservation status', 'booking update', 'my reservation'],
        ],
        [
            'id' => 'faq-refund-policy',
            'category' => 'Cancellations & Refunds',
            'question' => 'How do cancellations and refunds work?',
            'answer' => 'Paid customer cancellation requests return the full amount paid when approved. The refund amount is recorded when the request is submitted, and staff review the request and destination details.',
            'phrases' => ['cancel booking', 'refund request', 'cancellation policy'],
        ],
        [
            'id' => 'faq-payment-proof',
            'category' => 'Payments',
            'question' => 'How do I submit payment proof?',
            'answer' => 'Open your User Dashboard, choose the booking payment action, select an available method, and submit your transaction reference with the receipt image. Staff review the proof before a payment is recorded.',
            'phrases' => ['receipt upload', 'payment receipt', 'proof of payment'],
        ],
        [
            'id' => 'faq-resort-policies',
            'category' => 'Policies',
            'question' => 'Where can I read the resort policies?',
            'answer' => 'Visit Support & Information for the current booking, privacy, terms, and resort policy details. Contact reception when a question is not covered there.',
            'phrases' => ['resort rules', 'terms', 'privacy policy'],
        ],
    ];
}

function receptionist_faq_text($value, int $maximum): ?string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) return null;
    $value = trim($value);
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    return $value !== '' && $length <= $maximum ? $value : null;
}

function receptionist_faq_stable_id(string $question): string
{
    return 'faq-' . substr(hash('sha256', strtolower(trim($question))), 0, 20);
}

function receptionist_faq_normalize_item($item, int $index = 0): ?array
{
    if (!is_array($item)) return null;
    $question = receptionist_faq_text($item['question'] ?? ($item['q'] ?? ''), 240);
    $answer = receptionist_faq_text($item['answer'] ?? ($item['a'] ?? ''), 3000);
    if ($question === null || $answer === null) return null;

    // CMS content is public model grounding. Ignore obvious placeholders and
    // keyboard-gibberish instead of allowing a weak configuration to replace
    // useful defaults or enter prompts/replies.
    if (receptionist_faq_is_junk($question) || receptionist_faq_is_junk($answer)) return null;

    $category = receptionist_faq_text($item['category'] ?? 'General', 80) ?? 'General';
    if (!in_array($category, RECEPTIONIST_FAQ_CATEGORIES, true)) $category = 'General';

    $rawId = receptionist_faq_text($item['id'] ?? '', 80);
    $id = $rawId !== null && preg_match('/\Afaq-[a-z0-9][a-z0-9_-]{0,78}\z/D', $rawId) === 1
        ? $rawId
        : receptionist_faq_stable_id($question);

    $phrases = $item['phrases'] ?? [];
    if (is_string($phrases)) $phrases = preg_split('/\r\n|\r|\n/', $phrases) ?: [];
    if (!is_array($phrases)) $phrases = [];
    $cleanPhrases = [];
    foreach ($phrases as $phrase) {
        if (count($cleanPhrases) >= 10) break;
        $clean = receptionist_faq_text($phrase, 160);
        if ($clean !== null && !in_array($clean, $cleanPhrases, true)) $cleanPhrases[] = $clean;
    }

    return [
        'id' => $id,
        'category' => $category,
        'question' => $question,
        'answer' => $answer,
        'phrases' => $cleanPhrases,
    ];
}

function receptionist_faq_is_junk(string $value): bool
{
    $normalized = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', ' ', $value)));
    if ($normalized === '' || preg_match('/\bqwasda\b|\b(?:test|lorem ipsum|asdf|foo bar)\b/i', $normalized) === 1) return true;
    if (preg_match('/^(.)\1{4,}$/u', $normalized) === 1) return true;
    $letters = preg_replace('/[^a-z]/', '', $normalized) ?? '';
    return strlen($letters) >= 5 && preg_match('/[aeiou]/', $letters) !== 1;
}

function receptionist_faq_normalize_items(array $items): array
{
    $normalized = [];
    $seenIds = [];
    $seenQuestions = [];
    foreach ($items as $index => $item) {
        if (count($normalized) >= 50) break;
        $clean = receptionist_faq_normalize_item($item, (int)$index);
        if ($clean === null) continue;
        $idKey = strtolower($clean['id']);
        $questionKey = strtolower($clean['question']);
        if (isset($seenIds[$idKey]) || isset($seenQuestions[$questionKey])) continue;
        $seenIds[$idKey] = true;
        $seenQuestions[$questionKey] = true;
        $normalized[] = $clean;
    }
    return $normalized;
}

function receptionist_faq_merge_defaults(array $items): array
{
    // Defaults are always retained. A valid CMS row may replace a matching
    // stable id, extend the list, or be ignored if it duplicates a question.
    $cms = receptionist_faq_normalize_items($items);
    $cmsById = [];
    foreach ($cms as $item) $cmsById[strtolower((string)$item['id'])] = $item;
    $result = [];
    $seenQuestions = [];
    foreach (receptionist_faq_defaults() as $default) {
        $id = strtolower((string)$default['id']);
        $item = $cmsById[$id] ?? $default;
        $question = strtolower((string)$item['question']);
        if (isset($seenQuestions[$question])) continue;
        $result[] = $item;
        $seenQuestions[$question] = true;
    }
    foreach ($cms as $item) {
        $id = strtolower((string)$item['id']);
        $question = strtolower((string)$item['question']);
        if (isset($seenQuestions[$question])) continue;
        $result[] = $item;
        $seenQuestions[$question] = true;
        if (count($result) >= 50) break;
    }
    return $result;
}

function receptionist_faq_load(mysqli $conn): array
{
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'support_faq_json' LIMIT 1");
    if (!$stmt || !$stmt->execute()) return receptionist_faq_defaults();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !is_string($row['setting_value'] ?? null)) return receptionist_faq_defaults();
    $decoded = json_decode($row['setting_value'], true);
    return is_array($decoded) ? receptionist_faq_merge_defaults($decoded) : receptionist_faq_defaults();
}

function receptionist_faq_find(array $items, string $id): ?array
{
    foreach ($items as $item) {
        if (is_array($item) && hash_equals((string)($item['id'] ?? ''), $id)) return $item;
    }
    return null;
}

function receptionist_faq_json(array $items): string
{
    return json_encode(receptionist_faq_normalize_items($items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
