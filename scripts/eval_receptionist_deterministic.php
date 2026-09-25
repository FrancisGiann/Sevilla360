<?php
declare(strict_types=1);

// Offline corpus/evaluation only. No database or provider is used.
require_once dirname(__DIR__) . '/includes/receptionist_faq.php';
require_once dirname(__DIR__) . '/includes/receptionist_ai.php';
require_once dirname(__DIR__) . '/includes/receptionist_knowledge.php';

$rows = [
    ['id' => 1, 'category' => 'Event Hall', 'name' => 'Infinity Hall', 'description' => 'Large event space', 'amenities' => 'Free Wifi, Free Parking', 'event_rate' => 15000, 'event_base_capacity' => 50, 'event_max_capacity' => 1000, 'capacity_theater' => 1000, 'capacity_classroom' => 500, 'capacity_banquet' => 350],
    ['id' => 2, 'category' => 'Hotel Room', 'name' => 'Stellar', 'room_type' => 'Deluxe', 'room_group_id' => 12, 'bed_count' => 2, 'room_base_capacity' => 2, 'room_max_capacity' => 4, 'nightly_rate' => 3500, 'amenities' => 'Wi-Fi, Breakfast'],
    ['id' => 2, 'category' => 'Hotel Room', 'name' => 'Stellar', 'room_type' => 'Suite', 'room_group_id' => 13, 'bed_count' => 3, 'room_base_capacity' => 2, 'room_max_capacity' => 6, 'nightly_rate' => 5000, 'amenities' => 'Wi-Fi, Breakfast, Ocean view'],
    ['id' => 3, 'category' => 'Resort Villa', 'name' => 'Lagoon Villa', 'description' => 'Private villa', 'amenities' => 'Private pool, Breakfast', 'day_rate' => 8000, 'overnight_rate' => 12000, 'villa_base_capacity' => 4, 'villa_max_capacity' => 6, 'has_private_pool' => 1],
];
$records = receptionist_knowledge_build_records($rows, ['biz_name' => 'Sevilla360', 'biz_address' => 'Lucena', 'biz_policies' => 'Public cancellations are reviewed.'], receptionist_faq_defaults());
$today = new DateTimeImmutable('2026-09-17');
$cases = [
    ['wnt to book a party of twenty five', fn(string $m): bool => receptionist_knowledge_booking_group_size($m) === 25],
    ['group of 12 guests', fn(string $m): bool => receptionist_knowledge_booking_group_size($m) === 12],
    ['kami dalawa', fn(string $m): bool => receptionist_knowledge_booking_group_size($m) === 2],
    ['solo stay', fn(string $m): bool => receptionist_knowledge_booking_group_size($m) === 1],
    ['30 pax', fn(string $m): bool => receptionist_knowledge_booking_group_size($m) === 30],
    ['party of twenty one', fn(string $m): bool => receptionist_knowledge_booking_group_size($m) === 21],
    ['for 8 people', fn(string $m): bool => receptionist_knowledge_booking_group_size($m) === 8],
    ['next weekend', fn(string $m): bool => receptionist_knowledge_booking_date($m, $today) === '2026-09-26'],
    ['this weekend', fn(string $m): bool => receptionist_knowledge_booking_date($m, $today) === '2026-09-19'],
    ['next week sat', fn(string $m): bool => receptionist_knowledge_booking_date($m, $today) === '2026-09-26'],
    ['tomorrow', fn(string $m): bool => receptionist_knowledge_booking_date($m, $today) === '2026-09-18'],
    ['March 14, 2027', fn(string $m): bool => receptionist_knowledge_booking_date($m, $today) === '2027-03-14'],
    ['14 March 2027', fn(string $m): bool => receptionist_knowledge_booking_date($m, $today) === '2027-03-14'],
    ['12/31/2027', fn(string $m): bool => receptionist_knowledge_booking_date($m, $today) === '2027-12-31'],
    ['31/12/2027', fn(string $m): bool => receptionist_knowledge_booking_date($m, $today) === '2027-12-31'],
    ['03/04/2027', fn(string $m): bool => receptionist_knowledge_booking_date($m, $today) === null],
    ['March 14-16, 2027', fn(string $m): bool => receptionist_knowledge_booking_dates($m, $today) === ['2027-03-14', '2027-03-16']],
    ['check-in March 14, 2027 check-out March 16, 2027', fn(string $m): bool => count(receptionist_knowledge_booking_dates($m, $today)) === 2],
    ['wnt to book a hotel', fn(string $m): bool => receptionist_knowledge_intent($m)['kind'] === 'booking'],
    ['I want to book an event', fn(string $m): bool => receptionist_knowledge_intent($m)['category'] === 'Event Hall'],
    ['Gusto kong mag-book ng hotel room', fn(string $m): bool => receptionist_knowledge_intent($m)['category'] === 'Hotel Room'],
    ['wanna book a villa', fn(string $m): bool => receptionist_knowledge_intent($m)['category'] === 'Resort Villa'],
    ['How do I book a hotel room?', fn(string $m): bool => receptionist_knowledge_intent($m)['kind'] === 'booking_process'],
    ['What is the booking process for an event?', fn(string $m): bool => receptionist_knowledge_intent($m)['kind'] === 'booking_process'],
    ['What policies and FAQs can you help with?', fn(string $m): bool => receptionist_knowledge_intent($m)['kind'] === 'support_faq'],
    ['mga madalas na tanong', fn(string $m): bool => receptionist_knowledge_intent($m)['kind'] === 'support_faq'],
    ['gusto ko ng reception sa Infinity', fn(string $m): bool => receptionist_knowledge_category_hint($m) === 'Event Hall'],
    ['hotel overnight', fn(string $m): bool => receptionist_knowledge_category_hint($m) === 'Hotel Room'],
    ['private villa staycation', fn(string $m): bool => receptionist_knowledge_category_hint($m) === 'Resort Villa'],
    ['Infinity', fn(string $m): bool => receptionist_knowledge_booking_venue($records, $m, 'Event Hall')['venue_id'] === 1],
    ['infinity hall', fn(string $m): bool => receptionist_knowledge_booking_venue($records, $m, 'Event Hall')['venue_id'] === 1],
    ['Lagoon Villa', fn(string $m): bool => receptionist_knowledge_booking_venue($records, $m, 'Resort Villa')['venue_id'] === 3],
    ['Stellar Deluxe', fn(string $m): bool => receptionist_knowledge_booking_venue($records, $m, 'Hotel Room')['room_group_id'] === 12],
    ['Stellar Suite', fn(string $m): bool => receptionist_knowledge_booking_venue($records, $m, 'Hotel Room')['room_group_id'] === 13],
    ['ambiguous Stellar building name', fn(string $m): bool => receptionist_knowledge_booking_venue($records, $m, 'Hotel Room') === null],
    ['How much for an event?', fn(string $m): bool => str_contains((string)receptionist_knowledge_reply($records, $m)['reply'], '₱15,000')],
    ['how much is event hall prce?', fn(string $m): bool => receptionist_knowledge_reply($records, $m) !== null],
    ['How many can Infinity Hall fit?', fn(string $m): bool => str_contains((string)receptionist_knowledge_reply($records, $m, 'en', ['active_venue_id' => 1, 'intent' => 'Event Hall'])['reply'], '1,000')],
    ['What amenities are included?', fn(string $m): bool => str_contains((string)receptionist_knowledge_reply($records, $m)['reply'], 'Free Wifi')],
    ['May private pool ba?', fn(string $m): bool => str_contains((string)receptionist_knowledge_reply($records, $m, 'fil')['reply'], 'pool')],
    ['How does payment and cancellation work?', fn(string $m): bool => receptionist_knowledge_reply($records, $m) !== null],
    ['is Infinity Hall available?', fn(string $m): bool => str_contains((string)receptionist_knowledge_reply($records, $m)['reply'], 'selected venue')],
    ['what are the resort rules?', fn(string $m): bool => receptionist_knowledge_reply($records, $m) !== null],
    ['Magkano ang event hall?', fn(string $m): bool => str_contains((string)receptionist_knowledge_reply($records, $m, 'fil')['reply'], 'starting')],
    ['Magkano ang event hall?', fn(string $m): bool => receptionist_knowledge_reply($records, $m, 'taglish') !== null],
    ['I want a hotel for 4 guests', fn(string $m): bool => receptionist_knowledge_reply($records, $m)['slots']['group_size'] === 4],
    ['book villa for five', fn(string $m): bool => receptionist_knowledge_reply($records, $m)['slots']['group_size'] === 5],
    ['wedding for 100 next weekend', fn(string $m): bool => receptionist_knowledge_reply($records, $m)['slots']['group_size'] === 100],
    ['wnt a wedding', fn(string $m): bool => receptionist_knowledge_reply($records, $m, 'en', ['intent' => 'Event Hall'])['slots']['occasion'] === 'wedding'],
    ['budget hotel room', fn(string $m): bool => receptionist_knowledge_reply($records, $m)['slots']['preference'] === 'save'],
    ['family villa', fn(string $m): bool => receptionist_knowledge_reply($records, $m)['slots']['purpose'] === 'family'],
    ['private villa', fn(string $m): bool => receptionist_knowledge_reply($records, $m)['slots']['purpose'] === 'private'],
    ['unknown custom flower arrangement price', fn(string $m): bool => receptionist_knowledge_reply($records, $m) === null],
    ['what is qwasda', fn(string $m): bool => receptionist_faq_find(receptionist_faq_merge_defaults([['question' => $m, 'answer' => 'x']]), receptionist_faq_stable_id($m)) === null],
];
$transcriptContext = [];
$transcript = [];
foreach (['a event hall', 'Infinity Hall', 'where'] as $utterance) {
    $answer = receptionist_knowledge_reply($records, $utterance, 'en', $transcriptContext) ?? [];
    $transcript[] = ['user' => $utterance, 'assistant' => $answer['reply'] ?? null, 'action' => $answer['action'] ?? null];
    if (is_array($answer['slots'] ?? null)) $transcriptContext = receptionist_ai_merge_slots($transcriptContext, $answer['slots']);
}
$expectedTranscript = [
    ['user' => 'a event hall', 'assistant' => 'What kind of event is this (for example, a wedding)? I’ll also need the guest count and event date.', 'action' => 'ask'],
    ['user' => 'Infinity Hall', 'assistant' => 'Infinity Hall selected. I’ll open the venue details for you to review.', 'action' => 'venue'],
    ['user' => 'where', 'assistant' => 'Here are the details for Infinity Hall.', 'action' => 'venue'],
];
$locationAnswer = receptionist_knowledge_reply($records, 'Where is Infinity Hall located?', 'en', ['intent' => 'Event Hall', 'active_venue_id' => 1]);
$detailAnswer = receptionist_knowledge_reply($records, 'See details', 'en', ['intent' => 'Event Hall', 'active_venue_id' => 1]);
$passed = 0;
foreach ($cases as $index => [$utterance, $assert]) {
    try { $ok = $assert($utterance); } catch (Throwable $error) { $ok = false; }
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . ($index + 1) . ': ' . $utterance . "\n";
    if ($ok) $passed++;
}
echo "Summary: {$passed}/" . count($cases) . " deterministic cases passed\n";
$transcriptPassed = $transcript === $expectedTranscript
    && ($locationAnswer['action'] ?? null) === 'ask'
    && str_contains((string)($locationAnswer['reply'] ?? ''), 'Lucena')
    && ($detailAnswer['action'] ?? null) === 'venue'
    && ($detailAnswer['slots']['active_venue_id'] ?? null) === 1;
echo ($transcriptPassed ? 'PASS' : 'FAIL') . " exact receptionist transcript keeps category, venue, and bare-where deterministic\n";
exit($passed === count($cases) && $transcriptPassed ? 0 : 1);
