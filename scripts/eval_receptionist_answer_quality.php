<?php
declare(strict_types=1);

// Offline end-to-end replay: fake provider only; no credentials or network.
require_once dirname(__DIR__) . '/includes/receptionist_ai.php';

final class ReceptionistReplayProvider implements ReceptionistAiProviderInterface
{
    public int $calls = 0;

    public function __construct(private array $payload = [], private bool $unavailable = false) {}

    public function complete(array $messages, int $maxOutputTokens, int $timeoutSeconds): array
    {
        $this->calls++;
        if ($this->unavailable) throw new RuntimeException('simulated provider outage');
        return ['success' => true, 'payload' => $this->payload];
    }
}

$rows = [
    ['id' => 1, 'category' => 'Event Hall', 'name' => 'Infinity Hall', 'description' => 'Large event space', 'amenities' => 'Free Wifi, Free Parking', 'event_rate' => 15000, 'event_base_capacity' => 50, 'event_max_capacity' => 1000, 'capacity_theater' => 1000],
    ['id' => 2, 'category' => 'Hotel Room', 'name' => 'Stellar', 'room_type' => 'Deluxe', 'room_group_id' => 12, 'room_base_capacity' => 2, 'room_max_capacity' => 4, 'nightly_rate' => 3500, 'amenities' => 'Wi-Fi, Breakfast'],
    ['id' => 2, 'category' => 'Hotel Room', 'name' => 'Stellar', 'room_type' => 'Suite', 'room_group_id' => 13, 'room_base_capacity' => 2, 'room_max_capacity' => 6, 'nightly_rate' => 5000, 'amenities' => 'Wi-Fi, Breakfast, Ocean view'],
    ['id' => 3, 'category' => 'Resort Villa', 'name' => 'Lagoon Villa', 'description' => 'Private villa', 'amenities' => 'Private pool, Breakfast', 'day_rate' => 8000, 'overnight_rate' => 12000, 'villa_base_capacity' => 4, 'villa_max_capacity' => 6],
];
$faqs = receptionist_faq_defaults();
$records = receptionist_knowledge_build_records($rows, ['biz_name' => 'Sevilla360', 'biz_address' => 'Lucena', 'biz_policies' => 'Public cancellations are reviewed.'], $faqs);
$db = mysqli_init();

$replay = static function (string $message, array $context, ReceptionistReplayProvider $provider) use ($records, $faqs, $db): array {
    $candidates = receptionist_knowledge_model_candidates($records, $message, $context, 8);
    $messages = [
        ['role' => 'system', 'content' => receptionist_ai_system_prompt($faqs, $context, 'en', [], $candidates, $message)],
        ['role' => 'user', 'content' => $message],
    ];
    try {
        $result = $provider->complete($messages, 128, 8);
        if (!empty($result['success']) && is_array($result['payload'] ?? null)) {
            $normalized = receptionist_ai_normalize_helper_output($result['payload'], $db, $context, $faqs, 'en', null, $records, $candidates, $message);
            if (is_array($normalized['knowledge_answer'] ?? null)) return ['answer' => $normalized['knowledge_answer'], 'candidates' => $candidates];
            return ['answer' => $normalized, 'candidates' => $candidates];
        }
    } catch (Throwable $error) {
        // Expected in the explicit unavailable-provider case below.
    }
    return ['answer' => receptionist_knowledge_local_fallback($records, $message, 'en', $context), 'candidates' => $candidates];
};

$faqPayment = array_values(array_filter($records, static fn(array $row): bool => ($row['id'] ?? null) === 'faq-payment-proof'))[0] ?? null;
$deluxe = array_values(array_filter($records, static fn(array $row): bool => ($row['kind'] ?? null) === 'venue' && (int)($row['room_group_id'] ?? 0) === 12))[0] ?? null;
$suite = array_values(array_filter($records, static fn(array $row): bool => ($row['kind'] ?? null) === 'venue' && (int)($row['room_group_id'] ?? 0) === 13))[0] ?? null;

$checks = [];
$paymentProvider = new ReceptionistReplayProvider(['action' => 'ask', 'language' => 'en', 'reply' => 'ignored model text', 'knowledge_id' => 'faq-payment-proof', 'knowledge_property' => 'faq_answer']);
$payment = $replay('Can I send a screenshot to show I already paid?', [], $paymentProvider);
$checks['paraphrased FAQ uses a bounded approved FAQ candidate and server answer'] = $faqPayment !== null
    && in_array('faq-payment-proof', array_column($payment['candidates'], 'id'), true)
    && ($payment['answer']['reply'] ?? null) === receptionist_faq_text($faqPayment['answer'], 3000);

$capacityProvider = new ReceptionistReplayProvider(['action' => 'ask', 'language' => 'en', 'reply' => 'fake capacity', 'knowledge_id' => 'venue-event-hall-1', 'knowledge_property' => 'capacity']);
$capacity = $replay('How many people can it accommodate?', ['intent' => 'Event Hall', 'active_venue_id' => 1], $capacityProvider);
$checks['paraphrased follow-up stays on the validated selected venue'] = str_contains((string)($capacity['answer']['reply'] ?? ''), '1,000')
    && ($capacity['answer']['slots']['active_venue_id'] ?? null) === 1;

$wrongHotelProvider = new ReceptionistReplayProvider(['action' => 'ask', 'language' => 'en', 'reply' => 'fake suite price', 'knowledge_id' => (string)($suite['id'] ?? ''), 'knowledge_property' => 'price']);
$hotel = $replay('what about its price?', ['intent' => 'Hotel Room', 'active_venue_id' => 2, 'active_room_group_id' => 12], $wrongHotelProvider);
$candidateGroups = array_values(array_map(static fn(array $row): int => (int)($row['room_group_id'] ?? 0), array_filter($hotel['candidates'], static fn(array $row): bool => ($row['kind'] ?? null) === 'venue')));
$checks['wrong hotel room group is rejected and local reply preserves exact group'] = $deluxe !== null
    && !in_array((string)($suite['id'] ?? ''), array_column($hotel['candidates'], 'id'), true)
    && $candidateGroups === [12]
    && str_contains((string)($hotel['answer']['reply'] ?? ''), '₱3,500/night')
    && !str_contains((string)($hotel['answer']['reply'] ?? ''), 'Suite');

$wrongFaqProvider = new ReceptionistReplayProvider(['action' => 'ask', 'language' => 'en', 'reply' => 'untrusted text', 'knowledge_id' => 'faq-resort-policies', 'knowledge_property' => 'faq_answer']);
$pet = $replay('pet policy', [], $wrongFaqProvider);
$checks['unrelated FAQ cannot answer an unsupported pet policy'] = ($pet['answer']['show_support_contact_cta'] ?? false) === true
    && str_contains(strtolower((string)($pet['answer']['reply'] ?? '')), 'can’t confirm');

$downProvider = new ReceptionistReplayProvider([], true);
$down = $replay('outside catering allowed', [], $downProvider);
$checks['provider outage returns a relevant local unconfirmed answer'] = $downProvider->calls === 1
    && ($down['answer']['show_support_contact_cta'] ?? false) === true
    && str_contains(strtolower((string)($down['answer']['reply'] ?? '')), 'can’t confirm');

$jacuzziSocialProvider = new ReceptionistReplayProvider(['action' => 'social', 'language' => 'en', 'reply' => 'Yes, it does.', 'knowledge_id' => null, 'knowledge_property' => null]);
$jacuzzi = $replay('Does it have a jacuzzi?', ['intent' => 'Event Hall', 'active_venue_id' => 1], $jacuzziSocialProvider);
$checks['active venue unknown amenity cannot pass as affirmative social reply'] = receptionist_knowledge_is_fact_request('Does it have a jacuzzi?', ['active_venue_id' => 1])
    && ($jacuzzi['answer']['show_support_contact_cta'] ?? false) === true
    && str_contains(strtolower((string)($jacuzzi['answer']['reply'] ?? '')), 'can’t confirm')
    && !str_contains(strtolower((string)($jacuzzi['answer']['reply'] ?? '')), 'yes, it does');

$jacuzziFactProvider = new ReceptionistReplayProvider(['action' => 'ask', 'language' => 'en', 'reply' => 'ignored feature text', 'knowledge_id' => 'venue-event-hall-1', 'knowledge_property' => 'amenities']);
$jacuzziFact = $replay('Does it have a jacuzzi?', ['intent' => 'Event Hall', 'active_venue_id' => 1], $jacuzziFactProvider);
$checks['unlisted amenity selection is answered as unconfirmed, never as the full amenity list'] = ($jacuzziFact['answer']['show_support_contact_cta'] ?? false) === true
    && str_contains(strtolower((string)($jacuzziFact['answer']['reply'] ?? '')), 'jacuzzi')
    && str_contains(strtolower((string)($jacuzziFact['answer']['reply'] ?? '')), 'can’t confirm')
    && !str_contains(strtolower((string)($jacuzziFact['answer']['reply'] ?? '')), 'published amenities');

$wrongActiveFaqProvider = new ReceptionistReplayProvider(['action' => 'ask', 'language' => 'en', 'reply' => 'ignored FAQ text', 'knowledge_id' => 'faq-resort-policies', 'knowledge_property' => 'faq_answer']);
$wrongActiveFaq = $replay('Does it have a jacuzzi?', ['intent' => 'Event Hall', 'active_venue_id' => 1], $wrongActiveFaqProvider);
$checks['active venue property question rejects an unrelated FAQ selection'] = ($wrongActiveFaq['answer']['show_support_contact_cta'] ?? false) === true
    && str_contains(strtolower((string)($wrongActiveFaq['answer']['reply'] ?? '')), 'can’t confirm')
    && ($wrongActiveFaq['answer']['faq_id'] ?? null) === null;

$conversationProvider = new ReceptionistReplayProvider(['action' => 'ask', 'language' => 'en', 'reply' => 'ignored model answer', 'knowledge_id' => null, 'knowledge_property' => 'unknown']);
$hotelConversation = [];
$hotelHistory = [];
$hotelFocus = null;
$hotelMessages = [
    'I want to book a hotel room',
    '2 guests',
    'Comfort',
    'Check-in November 12, 2037',
    'November 15, 2037',
    'I choose Stellar Deluxe',
    'Is this venue available on this date?',
];
$hotelAnswers = [];
foreach ($hotelMessages as $message) {
    $messageCategory = receptionist_knowledge_explicit_category_switch($message);
    $resolution = receptionist_ai_resolve_context($hotelConversation, [], $messageCategory);
    $hotelConversation = $resolution['base_slots'];
    $answer = receptionist_knowledge_reply($records, $message, 'en', $hotelConversation, $hotelHistory, $hotelFocus);
    if ($answer === null) {
        $candidates = receptionist_knowledge_model_candidates($records, $message, $hotelConversation, 8, $hotelFocus);
        $summary = receptionist_ai_model_context_summary($hotelConversation, $hotelHistory, $records, $hotelFocus);
        $messages = [
            ['role' => 'system', 'content' => receptionist_ai_system_prompt($faqs, $summary, 'en', [], $candidates, $message)],
            ['role' => 'user', 'content' => $message],
        ];
        $providerResult = $conversationProvider->complete($messages, 128, 8);
        $normalized = receptionist_ai_normalize_helper_output($providerResult['payload'], $db, $hotelConversation, $faqs, 'en', null, $records, $candidates, $message);
        $answer = $normalized['knowledge_answer'] ?? $normalized;
    }
    $hotelAnswers[] = $answer;
    $hotelConversation = array_replace($hotelConversation, $answer['slots'] ?? []);
    $hotelHistory = receptionist_ai_append_history($hotelHistory, $message, (string)($answer['reply'] ?? ''));
}
$availabilityAnswer = $hotelAnswers[6] ?? [];
$checks['seven-turn hotel conversation retains intent, sequential dates, and the exact room group before local availability handoff'] = $conversationProvider->calls === 0
    && ($hotelAnswers[0]['slots']['intent'] ?? null) === 'Hotel Room'
    && ($hotelAnswers[4]['slots']['start_date'] ?? null) === '2037-11-12'
    && ($hotelAnswers[4]['slots']['end_date'] ?? null) === '2037-11-15'
    && ($hotelAnswers[5]['slots']['active_venue_id'] ?? null) === 2
    && ($hotelAnswers[5]['slots']['active_room_group_id'] ?? null) === 12
    && ($availabilityAnswer['action'] ?? null) === 'availability'
    && ($availabilityAnswer['booking_continuation'] ?? false) === true
    && ($availabilityAnswer['slots']['intent'] ?? null) === 'Hotel Room'
    && ($availabilityAnswer['slots']['start_date'] ?? null) === '2037-11-12'
    && ($availabilityAnswer['slots']['end_date'] ?? null) === '2037-11-15'
    && ($availabilityAnswer['slots']['active_room_group_id'] ?? null) === 12
    && str_contains(strtolower((string)($availabilityAnswer['reply'] ?? '')), 'does not reserve');

$missingCheckout = receptionist_knowledge_reply($records, 'Is this hotel available on November 12, 2037?', 'en', [
    'intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'comfort', 'active_venue_id' => 2, 'active_room_group_id' => 12,
]);
$checks['incomplete hotel availability asks for checkout and keeps the selected room/date'] = ($missingCheckout['action'] ?? null) === 'ask'
    && ($missingCheckout['missing_slots'] ?? []) === ['end_date']
    && ($missingCheckout['slots']['start_date'] ?? null) === '2037-11-12'
    && ($missingCheckout['slots']['active_room_group_id'] ?? null) === 12;

$faqFocus = $faqPayment['id'] ?? null;
$faqFollowup = receptionist_knowledge_reply($records, 'What about that?', 'en', [], [
    ['role' => 'user', 'content' => 'Can I send a screenshot to show I already paid?'],
    ['role' => 'assistant', 'content' => $faqPayment['answer'] ?? ''],
], $faqFocus);
$faqFollowupCandidates = receptionist_knowledge_model_candidates($records, 'What about that?', [], 8, $faqFocus);
$checks['a short FAQ follow-up stays scoped to the last published FAQ and needs no provider call'] = ($faqFollowup['faq_id'] ?? null) === $faqFocus
    && ($faqFollowup['reply'] ?? null) === ($faqPayment['answer'] ?? null)
    && array_column($faqFollowupCandidates, 'id') === [$faqFocus];

$privacySummary = receptionist_ai_model_context_summary([
    'intent' => 'Hotel Room', 'group_size' => 2, 'start_date' => '2037-11-12', 'end_date' => '2037-11-15', 'active_venue_id' => 2, 'active_room_group_id' => 12,
], [
    ['role' => 'user', 'content' => 'I want a hotel room for two people.'],
    ['role' => 'user', 'content' => 'My email is visitor@example.test'],
], $records, $faqFocus);
$checks['model context summary includes validated dates and topics without raw or sensitive turns'] = ($privacySummary['intent'] ?? null) === 'Hotel Room'
    && ($privacySummary['start_date'] ?? null) === '2037-11-12'
    && ($privacySummary['end_date'] ?? null) === '2037-11-15'
    && ($privacySummary['last_user_topic'] ?? null) === 'booking'
    && !str_contains(json_encode($privacySummary), 'visitor@example.test')
    && !str_contains(json_encode($privacySummary), 'I want a hotel room');

$hotelCategorySwitch = receptionist_knowledge_reply($records, 'I want an event hall', 'en', $hotelConversation);
$checks['switching from hotel to event clears dates and the selected hotel room identity'] = ($hotelCategorySwitch['slots']['intent'] ?? null) === 'Event Hall'
    && !isset($hotelCategorySwitch['slots']['active_venue_id'], $hotelCategorySwitch['slots']['active_room_group_id'], $hotelCategorySwitch['slots']['start_date'], $hotelCategorySwitch['slots']['end_date']);

$smallTalkProvider = new ReceptionistReplayProvider(['action' => 'social', 'language' => 'en', 'reply' => 'I’m doing well, thanks for asking! How can I help today?', 'knowledge_id' => null, 'knowledge_property' => null]);
$smallTalk = $replay('How are you?', [], $smallTalkProvider);
$checks['allowlisted small talk may use harmless model-authored social text'] = receptionist_knowledge_is_social_input('How are you?')
    && ($smallTalk['answer']['action'] ?? null) === 'social'
    && str_contains((string)($smallTalk['answer']['reply'] ?? ''), 'doing well');

$failed = array_filter($checks, static fn(bool $passed): bool => !$passed);
foreach ($checks as $label => $passed) echo ($passed ? 'PASS' : 'FAIL') . ' - ' . $label . "\n";
exit($failed ? 1 : 0);
