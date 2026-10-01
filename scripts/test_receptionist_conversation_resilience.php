<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/receptionist_ai.php';

$db = mysqli_init();
$faqs = receptionist_faq_defaults();
$rows = [
    ['id' => 1, 'category' => 'Event Hall', 'name' => 'Infinity Hall', 'description' => 'Large event space', 'amenities' => 'Free Wifi, Free Parking', 'event_rate' => 15000, 'event_base_capacity' => 50, 'event_max_capacity' => 1000, 'capacity_theater' => 1000],
    ['id' => 2, 'category' => 'Hotel Room', 'name' => 'Stellar', 'room_type' => 'Deluxe', 'room_group_id' => 12, 'bed_count' => 2, 'room_base_capacity' => 2, 'room_max_capacity' => 4, 'nightly_rate' => 3500, 'amenities' => 'Wi-Fi, Breakfast'],
    ['id' => 2, 'category' => 'Hotel Room', 'name' => 'Stellar', 'room_type' => 'Suite', 'room_group_id' => 13, 'bed_count' => 2, 'room_base_capacity' => 2, 'room_max_capacity' => 6, 'nightly_rate' => 5000, 'amenities' => 'Wi-Fi, Breakfast'],
    ['id' => 3, 'category' => 'Resort Villa', 'name' => 'Lagoon Villa', 'description' => 'Private villa', 'amenities' => 'Private pool, Breakfast', 'day_rate' => 8000, 'overnight_rate' => 12000, 'villa_base_capacity' => 4, 'villa_max_capacity' => 6],
];
$records = receptionist_knowledge_build_records($rows, ['biz_name' => 'Sevilla360', 'biz_address' => 'Lucena'], $faqs);
$catalog = [
    ['id' => 1, 'category' => 'Event Hall', 'name' => 'Infinity Hall', 'room_group_id' => null],
    ['id' => 2, 'category' => 'Hotel Room', 'name' => 'Stellar', 'room_type' => 'Deluxe', 'room_group_id' => 12],
    ['id' => 2, 'category' => 'Hotel Room', 'name' => 'Stellar', 'room_type' => 'Suite', 'room_group_id' => 13],
    ['id' => 3, 'category' => 'Resort Villa', 'name' => 'Lagoon Villa', 'room_group_id' => null],
];

$replay = static function (array $messages, array $initialSlots = [], array $requestContexts = []) use ($db, $records, $catalog): array {
    $slots = $initialSlots;
    $history = [];
    $answers = [];
    foreach ($messages as $index => $message) {
        $switch = receptionist_knowledge_explicit_category_switch($message);
        $resolution = receptionist_ai_resolve_context($slots, $requestContexts[$index] ?? [], $switch);
        $base = receptionist_ai_validate_slots($db, $resolution['request_slots'], $resolution['base_slots'], $catalog);
        $answer = receptionist_knowledge_reply($records, $message, 'en', $base, $history);
        // Match the production provider-unavailable fallback: local knowledge
        // and pending booking clarifications are attempted before generic copy.
        if ($answer === null) $answer = receptionist_knowledge_local_fallback($records, $message, 'en', $base, $history);
        if ($answer === null) $answer = ['action' => 'ask', 'reply' => receptionist_ai_guided_message('en'), 'slots' => $base, 'missing_slots' => []];
        $prepared = receptionist_ai_prepare_knowledge($db, $answer, $base, $catalog);
        $slots = $prepared['slots'] ?? $base;
        $history = receptionist_ai_append_history($history, $message, (string)($prepared['reply'] ?? ''), ($prepared['reset_context'] ?? false) === true);
        $answers[] = $prepared;
    }
    return ['slots' => $slots, 'history' => $history, 'answers' => $answers];
};

$checks = [];
$typoDigit = $replay(['io want to book hotel', '2']);
$checks['typo opener plus bare digit advances hotel guest count'] = ($typoDigit['slots']['intent'] ?? null) === 'Hotel Room'
    && ($typoDigit['slots']['group_size'] ?? null) === 2 && ($typoDigit['answers'][1]['missing_slots'][0] ?? null) === 'preference';
$typoWord = $replay(['io want to book hotel', 'two']);
$checks['bare number word is accepted only after the actual guest prompt'] = ($typoWord['slots']['group_size'] ?? null) === 2;
$punctuated = $replay(['I want a hotel room', '3.']);
$checks['punctuation around bare digits does not lose the pending count'] = ($punctuated['slots']['group_size'] ?? null) === 3;
$correctedCount = $replay(['I want a hotel room', '2 guests', 'actually 3']);
$checks['guest count correction replaces the prior validated value'] = ($correctedCount['slots']['group_size'] ?? null) === 3;
$affordable = $replay(['I want a hotel room', '4 guests', 'something affordable']);
$checks['preference paraphrase maps affordable to the lowest price option'] = ($affordable['slots']['preference'] ?? null) === 'save';
$comfortable = $replay(['I want a hotel room', '4 guests', 'more comfortable']);
$checks['preference paraphrase maps comfortable to comfort'] = ($comfortable['slots']['preference'] ?? null) === 'comfort';
$bestFit = $replay(['I want a hotel room', '4 guests', 'the one that fits us best']);
$checks['preference paraphrase maps fit wording to best fit'] = ($bestFit['slots']['preference'] ?? null) === 'best_fit';
$luxuryWhileDatePending = $replay(['hi', 'i wany yo book a hotel', '2', 'lowest price', 'What is check-in date?', 'actually i want a luxury']);
$luxuryReply = $luxuryWhileDatePending['answers'][5] ?? [];
$checks['luxury correction during the check-in prompt updates comfort and acknowledges the next step'] = ($luxuryReply['slots']['preference'] ?? null) === 'comfort'
    && ($luxuryReply['slots']['group_size'] ?? null) === 2
    && ($luxuryReply['missing_slots'][0] ?? null) === 'start_date'
    && str_contains(strtolower((string)($luxuryReply['reply'] ?? '')), 'prioritize comfort')
    && str_contains(strtolower((string)($luxuryReply['reply'] ?? '')), 'check-in date')
    && ($luxuryReply['reset_context'] ?? false) !== true;
$premiumWhileDatePending = $replay(['I want a hotel room', '2 guests', 'lowest price', 'Actually, comfort matters more']);
$premiumReply = $premiumWhileDatePending['answers'][3] ?? [];
$checks['comfort correction during a pending date updates the existing room preference'] = ($premiumReply['slots']['preference'] ?? null) === 'comfort'
    && ($premiumReply['missing_slots'][0] ?? null) === 'start_date'
    && str_contains(strtolower((string)($premiumReply['reply'] ?? '')), 'prioritize comfort');
$datedPreferenceCorrection = $replay(['actually I want luxury'], [
    'intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'save', 'start_date' => '2037-11-14', 'end_date' => '2037-11-16',
]);
$datedCorrection = $datedPreferenceCorrection['answers'][0] ?? [];
$checks['room preference correction preserves already selected hotel dates'] = ($datedCorrection['slots']['preference'] ?? null) === 'comfort'
    && ($datedCorrection['slots']['start_date'] ?? null) === '2037-11-14'
    && ($datedCorrection['slots']['end_date'] ?? null) === '2037-11-16'
    && ($datedCorrection['reset_context'] ?? false) !== true;
$event = $replay(['I want to book an event hall', 'wedding', '120 guests']);
$checks['event flow preserves occasion and guest count'] = ($event['slots']['intent'] ?? null) === 'Event Hall'
    && ($event['slots']['occasion'] ?? null) === 'wedding' && ($event['slots']['group_size'] ?? null) === 120;
$villa = $replay(['I want to book a villa', 'family', '4 guests']);
$checks['villa flow accepts short purpose and count answers'] = ($villa['slots']['intent'] ?? null) === 'Resort Villa'
    && ($villa['slots']['purpose'] ?? null) === 'family' && ($villa['slots']['group_size'] ?? null) === 4;
$interruptedSocial = $replay(['I want a hotel room', 'hi', 'two']);
$checks['social interruption leaves the unanswered count prompt recoverable'] = ($interruptedSocial['slots']['group_size'] ?? null) === 2;
$interruptedFaq = $replay(['I want a hotel room', 'What payment methods do you accept?', 'two']);
$checks['FAQ interruption leaves the unanswered count prompt recoverable'] = ($interruptedFaq['slots']['group_size'] ?? null) === 2;
$ambiguousYes = $replay(['I want a hotel room', 'yes']);
$checks['yes without a yes-no prompt gets a focused clarification and preserves intent'] = ($ambiguousYes['slots']['intent'] ?? null) === 'Hotel Room'
    && !isset($ambiguousYes['slots']['group_size']) && ($ambiguousYes['answers'][1]['missing_slots'] ?? []) === ['group_size'];
$ambiguousPunctuation = $replay(['I want a hotel room', '?']);
$checks['empty-token punctuation after an open question gets the same focused clarification'] = ($ambiguousPunctuation['answers'][1]['missing_slots'] ?? []) === ['group_size']
    && ($ambiguousPunctuation['slots']['intent'] ?? null) === 'Hotel Room';
$invalidCountsStayPending = true;
foreach (['0', '-1', '10001', '2.5', '10001 guests'] as $invalid) {
    $result = $replay(['I want a hotel room', $invalid]);
    if (($result['answers'][1]['missing_slots'][0] ?? null) !== 'group_size' || isset($result['slots']['group_size'])) $invalidCountsStayPending = false;
}
$checks['invalid counts repeat the pending count clarification without corrupting slots'] = $invalidCountsStayPending;
$overCapacity = $replay(['I want an event hall', 'wedding', '1001 guests']);
$checks['count above all published event capacities gets a local validation prompt'] = !isset($overCapacity['slots']['group_size'])
    && ($overCapacity['answers'][2]['missing_slots'] ?? []) === ['group_size']
    && str_contains((string)($overCapacity['answers'][2]['reply'] ?? ''), '1000');
$dates = $replay(['November 12, 2037'], ['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'comfort']);
$dateHistory = $dates['history'];
$checkout = $replay(['November 15, 2037'], $dates['slots'], $dateHistory ? array_fill(0, 1, []) : []);
$checks['one date followed by an unlabeled date becomes hotel check-in then checkout'] = ($checkout['slots']['start_date'] ?? null) === '2037-11-12'
    && ($checkout['slots']['end_date'] ?? null) === '2037-11-15';
$checkoutPromptHistory = [
    ['role' => 'user', 'content' => 'November 12, 2037'],
    ['role' => 'assistant', 'content' => 'What is the check-out date?'],
];
$correctedCheckout = $replay(['November 14, 2037'], ['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'comfort', 'start_date' => '2037-11-12', 'end_date' => '2037-11-15'], []);
$correctedCheckout = receptionist_knowledge_reply($records, 'November 14, 2037', 'en', ['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'comfort', 'start_date' => '2037-11-12', 'end_date' => '2037-11-15'], $checkoutPromptHistory);
$checks['checkout correction follows the last explicit checkout prompt'] = ($correctedCheckout['slots']['end_date'] ?? null) === '2037-11-14';
$misplacedCorrection = receptionist_knowledge_reply($records, 'actually 3', 'en', ['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'comfort', 'start_date' => '2037-11-12'], $checkoutPromptHistory);
$checks['guest correction wording is not mistaken for a date or room selection'] = ($misplacedCorrection['missing_slots'] ?? []) === ['end_date']
    && ($misplacedCorrection['slots']['group_size'] ?? null) === 2;
$badCheckout = receptionist_knowledge_reply($records, 'November 10, 2037', 'en', ['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'comfort', 'start_date' => '2037-11-12'], $checkoutPromptHistory);
$checks['checkout before check-in gets a focused date clarification'] = ($badCheckout['missing_slots'] ?? []) === ['end_date']
    && ($badCheckout['slots']['start_date'] ?? null) === '2037-11-12';
$wrongRoom = receptionist_knowledge_reply($records, 'what are its details?', 'en');
$checks['unknown room pronoun requests a venue clarification rather than greeting'] = str_contains(strtolower((string)($wrongRoom['reply'] ?? '')), 'which venue or hotel room')
    && !str_contains(strtolower((string)($wrongRoom['reply'] ?? '')), 'hello');
$categorySwitch = $replay(['I want a hotel room', '2 guests', 'Comfort', 'I want an event hall'], ['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'comfort', 'start_date' => '2037-11-12']);
$checks['category switch clears stale hotel-only search state'] = ($categorySwitch['slots']['intent'] ?? null) === 'Event Hall'
    && !isset($categorySwitch['slots']['preference'], $categorySwitch['slots']['start_date']);
$startOver = receptionist_knowledge_reply($records, 'I want to book', 'en', ['intent' => 'Event Hall', 'occasion' => 'wedding', 'group_size' => 100, 'start_date' => '2037-10-26']);
$checks['intentional generic restart alone clears stale booking context'] = ($startOver['reset_context'] ?? false) === true && ($startOver['slots'] ?? null) === [];
$faqPreservesFlow = $replay(['I want a hotel room', 'What payment methods do you accept?']);
$checks['approved FAQ answer preserves active booking fields'] = ($faqPreservesFlow['slots']['intent'] ?? null) === 'Hotel Room'
    && !str_contains(strtolower((string)($faqPreservesFlow['answers'][1]['reply'] ?? '')), 'hello');
$recover = receptionist_ai_recover_session_slots($db, [
    'intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'comfort',
    'start_date' => '2037-11-14', 'end_date' => '2037-11-10', 'active_venue_id' => 2, 'active_room_group_id' => 12,
], $catalog);
$checks['trusted session repair drops only the invalid dependent checkout and preserves valid fields'] = ($recover['intent'] ?? null) === 'Hotel Room'
    && ($recover['group_size'] ?? null) === 2 && ($recover['start_date'] ?? null) === '2037-11-14'
    && !isset($recover['end_date']) && ($recover['active_room_group_id'] ?? null) === 12;
$recoveredRequest = receptionist_ai_filter_recovered_session_dates(
    ['intent' => 'Hotel Room', 'start_date' => '2037-11-14', 'end_date' => '2037-11-10', 'active_venue_id' => 999],
    ['intent' => 'Hotel Room', 'start_date' => '2037-11-14', 'end_date' => '2037-11-10'],
    ['intent' => 'Hotel Room', 'start_date' => '2037-11-14']
);
$checks['recovery drops only an exactly echoed stale browser date and leaves forged venue identity subject to strict validation'] = !isset($recoveredRequest['end_date'])
    && ($recoveredRequest['active_venue_id'] ?? null) === 999;
$freshContext = receptionist_ai_resolve_context(['intent' => 'Hotel Room', 'start_date' => '2037-11-17'], ['intent' => 'Hotel Room', 'start_date' => '2037-11-14'], null);
$checks['same-category guided picker context remains eligible to update a populated server date'] = ($freshContext['request_slots']['start_date'] ?? null) === '2037-11-14';

$today = new DateTimeImmutable('today');
$expiredStart = $today->modify('-2 days')->format('Y-m-d');
$expiredEnd = $today->modify('-1 day')->format('Y-m-d');
$expiredSession = [
    'intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'comfort',
    'start_date' => $expiredStart, 'end_date' => $expiredEnd,
];
$recoveredExpired = receptionist_ai_recover_session_slots($db, $expiredSession, $catalog);
$expiredDateClears = receptionist_ai_recovered_session_date_clears($expiredSession, $recoveredExpired);
$clientSnapshot = $expiredSession;
$firstRequestContext = receptionist_ai_filter_recovered_session_dates($clientSnapshot, $expiredSession, $recoveredExpired);
$firstResolution = receptionist_ai_resolve_context($recoveredExpired, $firstRequestContext, null);
$firstBase = receptionist_ai_validate_slots($db, $firstResolution['base_slots'], [], $catalog);
$firstReply = receptionist_knowledge_reply($records, $today->modify('+1 day')->format('F j, Y'), 'en', $firstBase);
$firstPrepared = receptionist_ai_prepare_knowledge($db, is_array($firstReply) ? $firstReply : [], $firstBase, $catalog);
$firstClearSlots = array_values(array_filter($expiredDateClears, static fn(string $key): bool => !array_key_exists($key, $firstPrepared['slots'] ?? [])));
foreach (['start_date', 'end_date'] as $key) {
    if (isset($firstPrepared['slots'][$key])) $clientSnapshot[$key] = $firstPrepared['slots'][$key];
}
foreach ($firstClearSlots as $key) unset($clientSnapshot[$key]);
$nextClientContext = receptionist_ai_filter_recovered_session_dates($clientSnapshot, $expiredSession, $firstPrepared['slots']);
$secondResolution = receptionist_ai_resolve_context($firstPrepared['slots'], $nextClientContext, null);
$secondRequestSlots = receptionist_ai_validate_slots($db, $secondResolution['request_slots'], [], $catalog);
$secondBase = receptionist_ai_validate_slots($db, $secondResolution['base_slots'], [], $catalog);
$secondReply = receptionist_knowledge_reply($records, $today->modify('+3 days')->format('F j, Y'), 'en', $secondBase, [
    ['role' => 'user', 'content' => $today->modify('+1 day')->format('F j, Y')],
    ['role' => 'assistant', 'content' => (string)($firstPrepared['reply'] ?? '')],
]);
$checks['expired hotel dates clear from the client snapshot before a second request without resetting the new check-in'] = in_array('start_date', $expiredDateClears, true)
    && in_array('end_date', $expiredDateClears, true)
    && in_array('end_date', $firstClearSlots, true)
    && ($firstPrepared['slots']['start_date'] ?? null) === $today->modify('+1 day')->format('Y-m-d')
    && !array_key_exists('end_date', $nextClientContext)
    && ($secondRequestSlots['start_date'] ?? null) === $today->modify('+1 day')->format('Y-m-d')
    && ($secondReply['slots']['start_date'] ?? null) === $today->modify('+1 day')->format('Y-m-d')
    && ($secondReply['slots']['end_date'] ?? null) === $today->modify('+3 days')->format('Y-m-d')
    && ($secondReply['reset_context'] ?? false) !== true;

$failed = array_filter($checks, static fn(bool $passed): bool => !$passed);
foreach ($checks as $label => $passed) echo ($passed ? 'PASS' : 'FAIL') . ' - ' . $label . "\n";
echo 'RESULT - ' . count($checks) . ' checks, ' . count($failed) . ' failures' . "\n";
exit($failed ? 1 : 0);
