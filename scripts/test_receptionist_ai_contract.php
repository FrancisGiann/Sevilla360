<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/receptionist_faq.php';
require_once $root . '/includes/receptionist_ai.php';
require_once $root . '/includes/receptionist_knowledge.php';

$checks = [];
$source = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$rejects = static function (callable $callback): bool {
    try { $callback(); return false; } catch (Throwable $error) { return true; }
};

$defaults = receptionist_faq_defaults();
$knowledgeRows = [
    ['id' => 1, 'category' => 'Event Hall', 'name' => 'Infinity Hall', 'description' => 'Large event space', 'amenities' => 'Free Wifi, Free Parking', 'event_rate' => 15000, 'event_base_capacity' => 50, 'event_max_capacity' => 1000, 'capacity_theater' => 1000, 'capacity_classroom' => 500, 'capacity_banquet' => 350],
    ['id' => 70, 'category' => 'Event Hall', 'name' => 'Abelardo Hall', 'description' => '', 'amenities' => 'Free Wifi', 'event_rate' => 15000, 'event_base_capacity' => 100, 'event_max_capacity' => 500, 'capacity_theater' => 0, 'capacity_classroom' => 0, 'capacity_banquet' => 0],
    ['id' => 2, 'category' => 'Hotel Room', 'name' => 'Stellar', 'room_type' => 'Deluxe', 'room_group_id' => 12, 'bed_count' => 2, 'room_base_capacity' => 2, 'room_max_capacity' => 4, 'nightly_rate' => 3500, 'amenities' => 'Wi-Fi, Breakfast'],
    ['id' => 3, 'category' => 'Resort Villa', 'name' => 'Villa', 'description' => 'Private villa', 'amenities' => 'Private pool, Breakfast', 'day_rate' => 8000, 'overnight_rate' => 12000, 'villa_base_capacity' => 4, 'villa_max_capacity' => 6, 'has_private_pool' => 1],
];
$knowledgeSettings = [
    'event_type_wedding' => '20000', 'event_type_birthday' => '10000', 'catering_silver' => '750',
    'catering_gold' => '1200', 'catering_platinum' => '1800', 'av_setup' => '5000',
    'biz_name' => 'Sevilla360', 'biz_email' => 'reservations@example.test', 'biz_phone' => '+63 912 345 6789', 'biz_address' => 'Lucena, Philippines',
    'biz_policies' => "Public cancellations are reviewed by the resort team.\nAdmin-initiated force cancellations receive a 100% refund; processing fee percentage is snapshotted internally.",
];
$knowledgeRecords = receptionist_knowledge_build_records($knowledgeRows, $knowledgeSettings, $defaults);
$sequenceContext = [];
$sequenceAnswers = [];
foreach (['i want to book a venue', 'i wnt a wedding', 'next week sat', 'Event', '100 guest', 'i want infinity hall'] as $sequenceMessage) {
    $sequenceAnswer = receptionist_knowledge_reply($knowledgeRecords, $sequenceMessage, 'en', $sequenceContext);
    $sequenceAnswers[] = $sequenceAnswer;
    if (is_array($sequenceAnswer['slots'] ?? null)) $sequenceContext = array_replace($sequenceContext, $sequenceAnswer['slots']);
}
$knowledgePrice = receptionist_knowledge_reply($knowledgeRecords, 'How much for an event?', 'en');
$walkInReply = receptionist_knowledge_reply($knowledgeRecords, 'pwede po ba mag walk in?', 'fil', ['intent' => 'Hotel Room', 'group_size' => 2]);
$walkInEnglishReply = receptionist_knowledge_reply($knowledgeRecords, 'Can I come as a walk-in?', 'en');
$knowledgePriceFil = receptionist_knowledge_reply($knowledgeRecords, 'Magkano ang event hall?', 'fil');
$knowledgePriceTaglish = receptionist_knowledge_reply($knowledgeRecords, 'Magkano ang event hall?', 'taglish');
$knowledgeCapacity = receptionist_knowledge_reply($knowledgeRecords, 'How many can Infinity Hall fit?', 'en', ['active_venue_id' => 1, 'intent' => 'Event Hall']);
$knowledgeAmenities = receptionist_knowledge_reply($knowledgeRecords, 'What amenities are included?', 'en');
$knowledgePolicy = receptionist_knowledge_reply($knowledgeRecords, 'How does payment and cancellation work?', 'en');
$supportFaqPrompt = 'What policies and FAQs can you help with?';
$supportFaqIntent = receptionist_knowledge_intent($supportFaqPrompt);
$supportFaqReply = receptionist_knowledge_reply($knowledgeRecords, $supportFaqPrompt, 'en');
$knowledgeSelected = receptionist_knowledge_reply($knowledgeRecords, 'What is the capacity?', 'en', ['active_venue_id' => 1, 'intent' => 'Event Hall']);
$knowledgeUnknownQuote = receptionist_knowledge_reply($knowledgeRecords, 'How much for a custom flower arrangement?', 'en');
$knowledgeSafePolicy = array_values(array_filter($knowledgeRecords, static fn(array $record): bool => ($record['id'] ?? null) === 'public-policies'))[0] ?? null;
$hotelBookingIntent = function_exists('receptionist_knowledge_intent') ? receptionist_knowledge_intent('I want to book an hotel') : null;
$hotelBookingReply = receptionist_knowledge_reply($knowledgeRecords, 'I want to book a hotel', 'en');
$filBookingIntent = function_exists('receptionist_knowledge_intent') ? receptionist_knowledge_intent('Gusto kong mag-book ng hotel room') : null;
$taglishBookingIntent = function_exists('receptionist_knowledge_intent') ? receptionist_knowledge_intent('I want to book a room, saan ang check-in?') : null;
$eventBookingReply = receptionist_knowledge_reply($knowledgeRecords, 'I want to book an event venue', 'en');
$villaBookingReply = receptionist_knowledge_reply($knowledgeRecords, 'Gusto kong mag-book ng villa', 'fil');
$bookingPolicyReply = receptionist_knowledge_reply($knowledgeRecords, 'How do I book a hotel room?', 'en');
$cancellationPolicyReply = receptionist_knowledge_reply($knowledgeRecords, 'What is the cancellation policy?', 'en');
$hotelBookingProcessIntent = receptionist_knowledge_intent('How do I book a hotel room?');
$hotelBookingProcessReply = receptionist_knowledge_reply($knowledgeRecords, 'How do I book a hotel room?', 'en');
$eventBookingProcessIntent = receptionist_knowledge_intent('What is the booking process for an event?');
$eventBookingProcessReply = receptionist_knowledge_reply($knowledgeRecords, 'What is the booking process for an event?', 'en');
$genericBookingProcessIntent = receptionist_knowledge_intent('How can I reserve?');
$genericBookingProcessReply = receptionist_knowledge_reply($knowledgeRecords, 'How can I reserve?', 'en');
$knowledgeKeys = [];
foreach ($knowledgeRecords as $knowledgeRecord) $knowledgeKeys = array_merge($knowledgeKeys, array_keys($knowledgeRecord));
$checks['public knowledge answers EN/Filipino/Taglish event pricing without a provider'] = $knowledgePrice !== null
    && str_contains($knowledgePrice['reply'], 'Infinity Hall')
    && str_contains($knowledgePrice['reply'], 'Abelardo Hall')
    && str_contains($knowledgePrice['reply'], '₱15,000/day')
    && str_contains($knowledgePrice['reply'], 'final quotation')
    && $knowledgePriceFil !== null && str_contains($knowledgePriceFil['reply'], 'starting rates')
    && $knowledgePriceTaglish !== null && str_contains($knowledgePriceTaglish['reply'], 'starting rates');
$checks['public knowledge returns selected capacity and stored amenities only'] = $knowledgeCapacity !== null
    && str_contains($knowledgeCapacity['reply'], 'Infinity Hall')
    && str_contains($knowledgeCapacity['reply'], '1,000')
    && $knowledgeAmenities !== null
    && str_contains($knowledgeAmenities['reply'], 'Free Wifi')
    && str_contains($knowledgeAmenities['reply'], 'Private pool');
$checks['public knowledge routes approved payment and cancellation guidance'] = $knowledgePolicy !== null
    && str_contains($knowledgePolicy['reply'], 'payment')
    && str_contains($knowledgePolicy['reply'], 'cancellation');
$checks['walk-in requests ask guests to confirm and link to the current public contact details'] = ($walkInReply['action'] ?? null) === 'ask'
    && ($walkInReply['slots']['intent'] ?? null) === 'Hotel Room'
    && ($walkInReply['slots']['group_size'] ?? null) === 2
    && ($walkInReply['show_support_contact_cta'] ?? false) === true
    && str_contains(strtolower($walkInReply['reply'] ?? ''), 'reception')
    && str_contains(strtolower($walkInReply['reply'] ?? ''), 'makukumpirma')
    && str_contains(strtolower($walkInEnglishReply['reply'] ?? ''), 'can’t confirm')
    && ($walkInEnglishReply['show_support_contact_cta'] ?? false) === true
    && !str_contains($walkInReply['reply'] ?? '', 'event hall, hotel room, o resort villa');
$checks['explicit Support FAQs prompt gets useful bounded guidance and server-owned CTA metadata'] = ($supportFaqIntent['kind'] ?? null) === 'support_faq'
    && ($supportFaqReply['action'] ?? null) === 'ask'
    && ($supportFaqReply['show_support_faq_cta'] ?? false) === true
    && str_contains($supportFaqReply['reply'] ?? '', 'Support & FAQs')
    && !str_contains($supportFaqReply['reply'] ?? '', 'Reach our team for booking questions');
$checks['public knowledge selected venue filtering is bounded and safe'] = $knowledgeSelected !== null
    && str_contains($knowledgeSelected['reply'], 'Infinity Hall')
    && !str_contains($knowledgeSelected['reply'], 'Stellar')
    && count($knowledgeRecords) <= RECEPTIONIST_KNOWLEDGE_MAX_RECORDS
    && !array_intersect(['booking_id', 'customer_id', 'payment_submission_id', 'admin_note', 'staff_id'], $knowledgeKeys);
$checks['unknown custom quotes do not receive unrelated venue prices'] = $knowledgeUnknownQuote === null;
$checks['six-turn booking sequence is deterministic and accumulates validated slot candidates'] = count($sequenceAnswers) === 6
    && ($sequenceAnswers[0]['slots']['intent'] ?? null) === 'Event Hall'
    && ($sequenceAnswers[1]['slots']['occasion'] ?? null) === 'wedding'
    && ($sequenceAnswers[2]['slots']['start_date'] ?? null) === receptionist_knowledge_booking_date('next week sat')
    && ($sequenceAnswers[3]['slots']['intent'] ?? null) === 'Event Hall'
    && ($sequenceAnswers[4]['slots']['group_size'] ?? null) === 100
    && ($sequenceAnswers[5]['booking_continuation'] ?? false) === true
    && ($sequenceAnswers[5]['action'] ?? null) === 'venue'
    && ($sequenceAnswers[5]['slots']['active_venue_id'] ?? null) === 1
    && ($sequenceAnswers[5]['slots']['occasion'] ?? null) === 'wedding'
    && ($sequenceAnswers[5]['slots']['group_size'] ?? null) === 100;
$eventCategoryChoice = receptionist_knowledge_reply($knowledgeRecords, 'event hall', 'en');
$weddingGuidedChoice = receptionist_knowledge_reply($knowledgeRecords, 'wedding', 'en', $eventCategoryChoice['slots'] ?? []);
$otherGuidedChoice = receptionist_knowledge_reply($knowledgeRecords, 'other event', 'en', ['intent' => 'Event Hall']);
$fitGuidedChoice = receptionist_knowledge_reply($knowledgeRecords, 'best fit', 'en', ['intent' => 'Hotel Room']);
$villaGuidedChoice = receptionist_knowledge_reply($knowledgeRecords, 'family', 'en', ['intent' => 'Resort Villa']);
$checks['guided occasion and preference actions sync deterministic server slots instead of resetting to welcome'] = ($weddingGuidedChoice['slots']['occasion'] ?? null) === 'wedding'
    && ($weddingGuidedChoice['missing_slots'][0] ?? null) === 'group_size'
    && str_contains(strtolower($weddingGuidedChoice['reply'] ?? ''), 'how many guests')
    && ($otherGuidedChoice['slots']['occasion'] ?? null) === 'other'
    && ($fitGuidedChoice['slots']['preference'] ?? null) === 'best_fit'
    && ($villaGuidedChoice['slots']['purpose'] ?? null) === 'family';
$checks['public policy filtering removes internal cancellation and fee details'] = $knowledgeSafePolicy !== null
    && str_contains($knowledgeSafePolicy['text'], 'Public cancellations are reviewed')
    && !str_contains(strtolower($knowledgeSafePolicy['text']), 'processing fee')
    && !str_contains(strtolower($knowledgeSafePolicy['text']), 'admin-initiated');
$checks['transactional booking intent is separated from policy retrieval across locales'] = $hotelBookingIntent === null ? false : $hotelBookingIntent['kind'] === 'booking'
    && $hotelBookingIntent['category'] === 'Hotel Room'
    && $filBookingIntent !== null && $filBookingIntent['kind'] === 'booking' && $filBookingIntent['category'] === 'Hotel Room'
    && $taglishBookingIntent !== null && $taglishBookingIntent['kind'] === 'booking' && $taglishBookingIntent['category'] === 'Hotel Room';
$checks['direct hotel booking returns concise missing-detail guidance and retains intent slots'] = ($hotelBookingReply['mode'] ?? null) === 'knowledge'
    && ($hotelBookingReply['slots']['intent'] ?? null) === 'Hotel Room'
    && str_contains(strtolower($hotelBookingReply['reply'] ?? ''), 'guest')
    && str_contains(strtolower($hotelBookingReply['reply'] ?? ''), 'check-in')
    && str_contains(strtolower($hotelBookingReply['reply'] ?? ''), 'check-out')
    && strlen((string)($hotelBookingReply['reply'] ?? '')) <= 420;
$checks['event and villa booking intents receive category-specific guidance'] = ($eventBookingReply['slots']['intent'] ?? null) === 'Event Hall'
    && ($villaBookingReply['slots']['intent'] ?? null) === 'Resort Villa'
    && str_contains(strtolower($eventBookingReply['reply'] ?? ''), 'guest')
    && str_contains(strtolower($villaBookingReply['reply'] ?? ''), 'bisita');
$checks['informational booking and cancellation questions stay concise and prefer relevant approved guidance'] = ($bookingPolicyReply['action'] ?? null) !== 'booking'
    && strlen((string)($bookingPolicyReply['reply'] ?? '')) <= 900
    && !str_contains((string)($bookingPolicyReply['reply'] ?? ''), 'Where can I see my booking status?')
    && strlen((string)($cancellationPolicyReply['reply'] ?? '')) <= 900
    && !str_contains((string)($cancellationPolicyReply['reply'] ?? ''), 'Where can I see my booking status?');
$checks['informational booking process uses a dedicated intent route'] = ($hotelBookingProcessIntent['kind'] ?? null) === 'booking_process'
    && ($hotelBookingProcessIntent['category'] ?? null) === 'Hotel Room'
    && ($eventBookingProcessIntent['kind'] ?? null) === 'booking_process'
    && ($eventBookingProcessIntent['category'] ?? null) === 'Event Hall'
    && ($genericBookingProcessIntent['kind'] ?? null) === 'booking_process'
    && ($genericBookingProcessIntent['category'] ?? null) === null;
$checks['hotel booking process is concise and follows the real room flow'] = ($hotelBookingProcessReply['mode'] ?? null) === 'knowledge'
    && ($hotelBookingProcessReply['action'] ?? null) === 'ask'
    && str_contains(strtolower($hotelBookingProcessReply['reply'] ?? ''), 'guest count')
    && str_contains(strtolower($hotelBookingProcessReply['reply'] ?? ''), 'check-in')
    && str_contains(strtolower($hotelBookingProcessReply['reply'] ?? ''), 'check-out')
    && str_contains(strtolower($hotelBookingProcessReply['reply'] ?? ''), 'review')
    && str_contains(strtolower($hotelBookingProcessReply['reply'] ?? ''), 'booking page')
    && !str_contains(strtolower($hotelBookingProcessReply['reply'] ?? ''), 'priced per night')
    && strlen((string)($hotelBookingProcessReply['reply'] ?? '')) <= 700;
$checks['event booking process explains inquiry and staff-finalized quotation'] = ($eventBookingProcessReply['mode'] ?? null) === 'knowledge'
    && str_contains(strtolower($eventBookingProcessReply['reply'] ?? ''), 'event type')
    && str_contains(strtolower($eventBookingProcessReply['reply'] ?? ''), 'guest count')
    && str_contains(strtolower($eventBookingProcessReply['reply'] ?? ''), 'date')
    && str_contains(strtolower($eventBookingProcessReply['reply'] ?? ''), 'hall')
    && str_contains(strtolower($eventBookingProcessReply['reply'] ?? ''), 'inquiry')
    && str_contains(strtolower($eventBookingProcessReply['reply'] ?? ''), 'staff')
    && !str_contains(strtolower($eventBookingProcessReply['reply'] ?? ''), 'priced per night');
$checks['generic booking process stays bounded and does not leak unrelated rate or policy copy'] = ($genericBookingProcessReply['mode'] ?? null) === 'knowledge'
    && ($genericBookingProcessReply['action'] ?? null) === 'ask'
    && str_contains(strtolower($genericBookingProcessReply['reply'] ?? ''), 'event')
    && str_contains(strtolower($genericBookingProcessReply['reply'] ?? ''), 'hotel')
    && str_contains(strtolower($genericBookingProcessReply['reply'] ?? ''), 'villa')
    && str_contains(strtolower($genericBookingProcessReply['reply'] ?? ''), 'review')
    && !str_contains(strtolower($genericBookingProcessReply['reply'] ?? ''), 'priced per night')
    && strlen((string)($genericBookingProcessReply['reply'] ?? '')) <= 700;
$checks['booking process replies expose category choices and the fixed FAQ link action'] = array_slice($genericBookingProcessReply['quick_replies'] ?? [], 0, 3) === ['Event', 'Hotel', 'Villa']
    && in_array('Support FAQs', $genericBookingProcessReply['quick_replies'] ?? [], true)
    && in_array('Support FAQs', $hotelBookingProcessReply['quick_replies'] ?? [], true)
    && in_array('Support FAQs', $eventBookingProcessReply['quick_replies'] ?? [], true);
$checks['FAQ defaults use the fixed categories and normalized shape'] = count($defaults) > 0
    && count(array_unique(array_column($defaults, 'id'))) === count($defaults)
    && count(array_diff(array_column($defaults, 'category'), RECEPTIONIST_FAQ_CATEGORIES)) === 0
    && array_keys($defaults[0]) === ['id', 'category', 'question', 'answer', 'phrases'];
$legacy = receptionist_faq_normalize_item(['q' => 'Legacy question', 'a' => 'Legacy answer']);
$checks['legacy FAQ q/a values normalize to stable General entries'] = $legacy === null
    ? false
    : $legacy['category'] === 'General' && $legacy['phrases'] === [] && str_starts_with($legacy['id'], 'faq-');
$uniqueFaqs = array_map(static fn(int $index): array => ['question' => 'Q ' . $index, 'answer' => 'A'], range(1, 60));
$checks['FAQ limits are enforced'] = count(receptionist_faq_normalize_items($uniqueFaqs)) === 50
    && receptionist_faq_normalize_item(['question' => str_repeat('q', 241), 'answer' => 'A']) === null;
$duplicateFaqs = receptionist_faq_normalize_items([
    ['id' => 'faq-same', 'question' => 'Same question', 'answer' => 'First answer'],
    ['id' => 'faq-same', 'question' => 'Different question', 'answer' => 'Second answer'],
    ['question' => 'same question', 'answer' => 'Duplicate question'],
]);
$checks['legacy FAQ loading deduplicates IDs/questions while preserving the first stable entry'] = count($duplicateFaqs) === 1
    && $duplicateFaqs[0]['id'] === 'faq-same'
    && $duplicateFaqs[0]['answer'] === 'First answer';
$renamedFaq = receptionist_faq_normalize_item(['id' => 'faq-existing', 'question' => 'Edited question', 'answer' => 'Answer']);
$checks['existing FAQ ids survive question edits while new rows get safe ids'] = $renamedFaq['id'] === 'faq-existing'
    && preg_match('/\Afaq-[a-z0-9][a-z0-9_-]{0,78}\z/D', receptionist_faq_normalize_item(['question' => 'New question', 'answer' => 'Answer'])['id']) === 1;
$checks['language extraction supports Filipino and English fallback'] = receptionist_ai_language('auto', 'Magkano ang booking at paano magbayad?') === 'fil'
    && receptionist_ai_language('auto', 'pwede po ba mag walk in?') === 'fil'
    && receptionist_ai_language('auto', 'Which venue is available?') === 'en'
    && receptionist_ai_language('taglish', 'Any venue') === 'taglish';
$checks['sensitive input is rejected before provider use with localized recovery copy'] = $rejects(static fn() => receptionist_ai_clean_message('My payment reference is ABC12345'))
    && $rejects(static fn() => receptionist_ai_clean_message('Call me at 09171234567'))
    && $rejects(static fn() => receptionist_ai_clean_message('09171234567'))
    && str_contains(receptionist_ai_privacy_message('fil'), 'payment')
    && str_contains(receptionist_ai_privacy_message('taglish'), 'personal details');

final class ReceptionistContractFakeProvider implements ReceptionistAiProviderInterface
{
    public function complete(array $messages, int $maxOutputTokens, int $timeoutSeconds): array
    {
        return ['success' => true, 'payload' => ['language' => 'taglish', 'action' => 'ask', 'reply' => 'safe', 'slots' => [], 'quick_replies' => ['Event']]];
    }
}
$fake = new ReceptionistContractFakeProvider();
$fakeResult = $fake->complete([['role' => 'user', 'content' => 'hello']], 300, 12);
$checks['provider interface accepts fake payloads without transcript or database coupling'] = $fakeResult['success'] === true
    && $fakeResult['payload']['language'] === 'taglish'
    && array_key_exists('action', $fakeResult['payload']);
$previousAiEnv = [$_ENV['AI_ENABLED'] ?? null, $_ENV['AI_API_KEY'] ?? null, $_ENV['AI_MODEL'] ?? null];
$_ENV['AI_ENABLED'] = '0';
$disabledProvider = receptionist_ai_provider();
$_ENV['AI_ENABLED'] = '1';
$_ENV['AI_API_KEY'] = '';
$_ENV['AI_MODEL'] = '';
$missingProvider = receptionist_ai_provider();
foreach (['AI_ENABLED', 'AI_API_KEY', 'AI_MODEL'] as $index => $key) {
    if ($previousAiEnv[$index] === null) unset($_ENV[$key]); else $_ENV[$key] = $previousAiEnv[$index];
}
$checks['disabled or missing provider configuration falls back without a key'] = $disabledProvider === null && $missingProvider === null;

$db = mysqli_init();
$faq = $defaults[0];
$normalized = receptionist_ai_normalize_output([
    'language' => 'en', 'action' => 'faq', 'reply' => 'ignored', 'faq_id' => $faq['id'], 'slots' => [], 'quick_replies' => ['Book']
], $db, [], $defaults);
$checks['FAQ replies always come from the approved answer'] = $normalized['reply'] === $faq['answer'] && $normalized['faq_id'] === $faq['id'];
$futureStart = (new DateTimeImmutable('today'))->modify('+2 days')->format('Y-m-d');
$futureEnd = (new DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d');
$validatedDates = receptionist_ai_validate_slots($db, ['intent' => 'Hotel Room', 'start_date' => $futureStart, 'end_date' => $futureEnd], []);
$checks['past dates are rejected and valid future hotel dates preserve ordering'] = $validatedDates['start_date'] === $futureStart
    && $validatedDates['end_date'] === $futureEnd
    && $rejects(static fn() => receptionist_ai_validate_slots($db, ['start_date' => '2000-01-01'], []))
    && $rejects(static fn() => receptionist_ai_validate_slots($db, ['start_date' => $futureEnd, 'end_date' => $futureStart], []));
$recommendWithoutSlots = receptionist_ai_normalize_output(['language' => 'en', 'action' => 'recommend', 'reply' => 'safe', 'slots' => []], $db, [], $defaults);
$checks['recommendation semantics downgrade to ask with accurate missing slots'] = $recommendWithoutSlots['action'] === 'ask'
    && $recommendWithoutSlots['missing_slots'] === ['intent'];
$catalog = [
    ['id' => 10, 'category' => 'Hotel Room', 'name' => 'Garden Room', 'room_type' => 'Deluxe', 'room_group_id' => 2],
    ['id' => 11, 'category' => 'Event Hall', 'name' => 'Grand Hall', 'room_type' => '', 'room_group_id' => null],
];
$checks['venue catalog rejects unknown/category-mismatched identities and preserves hotel room group identity'] = receptionist_ai_catalog_venue($catalog, 999) === null
    && receptionist_ai_catalog_venue($catalog, 10, 'Event Hall') === null
    && receptionist_ai_catalog_venue($catalog, 10, 'Hotel Room') === null
    && receptionist_ai_catalog_venue($catalog, 10, 'Hotel Room', 2)['room_group_id'] === 2
    && receptionist_ai_catalog_venue($catalog, 10, 'Hotel Room', 3) === null;
$hotelVenueAsk = receptionist_ai_normalize_output([
    'language' => 'en', 'action' => 'venue', 'reply' => 'safe',
    'slots' => ['intent' => 'Hotel Room', 'active_venue_id' => 10]
], $db, [], $defaults, 'en', $catalog);
$checks['hotel venue actions without a room group ask instead of selecting the first room'] = $hotelVenueAsk['action'] === 'ask'
    && in_array('active_room_group_id', $hotelVenueAsk['missing_slots'], true);
$genericFaqShortlist = receptionist_ai_shortlist_faq($defaults, 'Support FAQs', 5);
$checks['generic support FAQ requests retain a bounded approved shortlist'] = count($genericFaqShortlist) > 0
    && count($genericFaqShortlist) <= 5
    && count(array_diff(array_column($genericFaqShortlist, 'id'), array_column($defaults, 'id'))) === 0;
$checks['malformed actions and FAQ IDs are rejected'] = $rejects(static fn() => receptionist_ai_normalize_output(['action' => 'delete', 'reply' => 'x', 'slots' => []], $db, [], $defaults))
    && $rejects(static fn() => receptionist_ai_normalize_output(['action' => 'faq', 'faq_id' => 'faq-missing', 'reply' => 'x', 'slots' => []], $db, [], $defaults))
    && $rejects(static fn() => receptionist_ai_normalize_output(['action' => 'ask', 'reply' => 'Visit https://example.com', 'slots' => []], $db, [], $defaults));
$fabricatedPrice = receptionist_ai_normalize_output(['language' => 'en', 'action' => 'ask', 'reply' => 'The event costs ₱999999.', 'slots' => [], 'quick_replies' => []], $db, [], $defaults);
$checks['fabricated model pricing cannot affect the server-authored reply'] = !str_contains($fabricatedPrice['reply'], '999999')
    && str_contains($fabricatedPrice['reply'], 'venue');
$clarifyingAsk = receptionist_ai_normalize_output(['language' => 'en', 'action' => 'ask', 'reply' => 'How many guests will be staying?', 'slots' => [], 'quick_replies' => []], $db, [], $defaults);
$unsupportedAsk = receptionist_ai_normalize_output(['language' => 'en', 'action' => 'ask', 'reply' => 'All our rooms include free Wi-Fi. Would you like a room?', 'slots' => [], 'quick_replies' => []], $db, [], $defaults);
$mixedClaimAsk = receptionist_ai_normalize_output(['language' => 'en', 'action' => 'ask', 'reply' => 'We have a pool. Which date works?', 'slots' => [], 'quick_replies' => []], $db, [], $defaults);
$checks['model clarifying questions survive normalization while factual ask claims stay server-authored'] = ($clarifyingAsk['reply'] ?? null) === 'How many guests will be staying?'
    && !str_contains($unsupportedAsk['reply'] ?? '', 'free Wi-Fi')
    && str_contains($unsupportedAsk['reply'] ?? '', 'venue')
    && !str_contains($mixedClaimAsk['reply'] ?? '', 'We have a pool');
$helperBaseSlots = ['intent' => 'Event Hall', 'occasion' => 'wedding'];
$helperVenueAttempt = receptionist_ai_normalize_helper_output([
    'language' => 'en', 'action' => 'venue', 'reply' => 'Opening venue 999.', 'faq_id' => null,
    'slots' => ['intent' => 'Hotel Room', 'active_venue_id' => 999], 'quick_replies' => ['Start over'],
], $db, $helperBaseSlots, $defaults);
$helperClaimAttempt = receptionist_ai_normalize_helper_output([
    'language' => 'en', 'action' => 'social', 'reply' => 'We have a pool and free Wi-Fi.', 'faq_id' => null,
    'slots' => [], 'quick_replies' => [],
], $db, [], $defaults);
$checks['optional AI helper cannot change venue navigation or invent resort facts'] = ($helperVenueAttempt['action'] ?? null) === 'ask'
    && ($helperVenueAttempt['slots'] ?? null) === $helperBaseSlots
    && ($helperVenueAttempt['quick_replies'] ?? null) === []
    && ($helperClaimAttempt['reply'] ?? null) === 'Hello! I\'m the virtual receptionist for M.I. Sevilla Resort & Events Place. How can I help you today?';

$endpoint = $source('actions/public/receptionist_chat.php');
$resetEndpoint = $source('actions/public/receptionist_chat_reset.php');
$historyEndpoint = $source('actions/public/receptionist_chat_history.php');
$showroomPhp = $source('showroom.php');
$showroomJs = $source('assets/js/showroom.js');
$showroomCss = $source('assets/css/showroom.css');
$chatJs = $source('assets/js/receptionist-chat.js');
$adminJs = $source('assets/js/admin-page/admin_settings.js');
$adminPhp = $source('includes/admin-page/admin_settings.php');
$supportPhp = $source('support.php');
$savePhp = $source('actions/admin/save_support_content.php');
$env = $source('.env.example');
$aiSource = $source('includes/receptionist_ai.php');
$knowledgeSource = $source('includes/receptionist_knowledge.php');
$checks['endpoint is JSON-only POST, CSRF-protected, bounded, and provider-neutral'] = str_contains($endpoint, "'application/json'")
    && str_contains($endpoint, "HTTP_X_CSRF_TOKEN")
    && str_contains($endpoint, "check_rate_limit(\$conn, 'receptionist_chat', 120, 10)")
    && str_contains($endpoint, "check_rate_limit(\$conn, 'receptionist_chat_provider', 30, 10)")
    && str_contains($endpoint, 'receptionist_ai_message_count')
    && str_contains($endpoint, 'array_slice($history, -16)')
    && str_contains($endpoint, 'receptionist_ai_shortlist_faq')
    && str_contains($endpoint, 'normalize_helper_output($result[\'payload\'], $conn, $baseSlots, $shortlist')
    && str_contains($endpoint, "'validated_slots'");
$checks['endpoint answers bounded public knowledge before provider use and passes only a shortlist to prompts'] = str_contains($endpoint, 'receptionist_public_knowledge_records')
    && str_contains($endpoint, 'receptionist_knowledge_reply')
    && str_contains($endpoint, "'mode' => 'knowledge'")
    && str_contains($endpoint, "'validated_slots' => \$prepared['slots']")
    && str_contains($endpoint, 'receptionist_knowledge_select')
    && str_contains($endpoint, 'receptionist_chat_prepare_knowledge')
    && str_contains($aiSource, 'function receptionist_ai_prepare_knowledge')
    && str_contains($aiSource, '$knowledgePatch')
    && str_contains($aiSource, 'Bounded approved public knowledge')
    && str_contains($chatJs, 'data.mode === "knowledge"')
    && str_contains($chatJs, 'options.onKnowledge')
    && str_contains($knowledgeSource, 'booking_continuation')
    && str_contains($showroomJs, 'data.booking_continuation === true')
    && str_contains($knowledgeSource, 'RECEPTIONIST_KNOWLEDGE_MAX_RECORDS')
    && str_contains($knowledgeSource, "v.status = 'Available'");
$providerFailureFallback = strpos($endpoint, 'catch (Throwable $providerError)') ?: false;
$deterministicFailureReply = strpos($endpoint, '$deterministicAnswer = receptionist_knowledge_reply($knowledgeRecords, $message, $language, $baseSlots, $history);', $providerFailureFallback ?: 0) ?: false;
$providerFailureBranch = $providerFailureFallback !== false ? substr($endpoint, $providerFailureFallback, 2500) : '';
$checks['known deterministic flow remains available after provider timeout'] = $providerFailureFallback !== false
    && $deterministicFailureReply !== false
    && str_contains($providerFailureBranch, 'if ($deterministicAnswer !== null) $respondDeterministic($deterministicAnswer, \'provider_unavailable\');')
    && str_contains($providerFailureBranch, 'receptionist_chat_guided($language, receptionist_chat_guided_copy($language, \'provider_unavailable\')');
$knowledgeDispatchPosition = strpos($endpoint, 'if ($knowledgeAnswer !== null) $respondDeterministic($knowledgeAnswer);');
$providerPosition = strpos($endpoint, '$provider = receptionist_ai_provider();');
$cheapRateLimitPosition = strpos($endpoint, "check_rate_limit(\$conn, 'receptionist_chat', 120, 10)");
$providerRateLimitPosition = strpos($endpoint, "check_rate_limit(\$conn, 'receptionist_chat_provider', 30, 10)");
$catalogPosition = strpos($endpoint, '$venueCatalog = receptionist_ai_public_venue_catalog($conn);');
$checks['deterministic booking turns bypass the provider burst limiter'] = $knowledgeDispatchPosition !== false
    && $providerPosition !== false
    && $providerRateLimitPosition !== false
    && $knowledgeDispatchPosition < $providerPosition
    && $providerPosition < $providerRateLimitPosition;
$checks['endpoint abuse bound runs before catalog work and uses a separate provider bucket'] = $cheapRateLimitPosition !== false
    && $catalogPosition !== false
    && $cheapRateLimitPosition < $catalogPosition
    && $cheapRateLimitPosition < $providerRateLimitPosition
    && $providerRateLimitPosition !== $cheapRateLimitPosition;
$checks['Support FAQs handoff uses deterministic metadata and one fixed local destination'] = str_contains($knowledgeSource, 'receptionist_knowledge_is_support_faq_request')
    && str_contains($endpoint, '$showSupportFaqCta')
    && str_contains($endpoint, "'show_support_faq_cta'")
    && str_contains($chatJs, 'const SUPPORT_FAQ_HREF = "support.php#faqs"')
    && str_contains($chatJs, 'data.show_support_faq_cta === true')
    && str_contains($chatJs, 'link.href = SUPPORT_FAQ_HREF')
    && str_contains($chatJs, 'View Support & FAQs')
    && !str_contains($chatJs, 'content.includes(')
    && !str_contains($chatJs, 'link.href = data.');
$checks['walk-in contact handoff uses a fixed local contact URL populated by Support settings'] = str_contains($endpoint, "'show_support_contact_cta'")
    && str_contains($chatJs, 'const SUPPORT_CONTACT_HREF = "support.php#contact"')
    && str_contains($chatJs, 'link.href = SUPPORT_CONTACT_HREF')
    && str_contains($chatJs, 'link.textContent = "Contact reception"')
    && str_contains($supportPhp, 'id="contact"')
    && str_contains($supportPhp, "\$support_settings['biz_email']")
    && str_contains($supportPhp, "\$support_settings['biz_phone']");
$checks['server context errors are distinguishable from provider schema fallback'] = str_contains($endpoint, "'code' => " . '$contextError' . " ? 'invalid_context' : 'invalid_request'")
    && str_contains($chatJs, "data.code === \"invalid_context\"")
    && str_contains($showroomJs, 'onInvalidContext');
$checks['chat history remains bounded to PHP session and avoids durable transcript writes'] = str_contains($endpoint, 'receptionist_ai_public_history')
    && str_contains($endpoint, 'receptionist_ai_history')
    && !str_contains($endpoint, 'create_user_notification')
    && !str_contains($endpoint, 'mail(')
    && !str_contains($endpoint, 'INSERT INTO');
$checks['history restore is same-origin CSRF-protected, no-store, owner-bound, and bounded'] = str_contains($historyEndpoint, "'POST'")
    && str_contains($historyEndpoint, "'application/json'")
    && str_contains($historyEndpoint, 'HTTP_X_CSRF_TOKEN')
    && str_contains($historyEndpoint, "Cache-Control: no-store, private")
    && str_contains($historyEndpoint, 'receptionist_ai_enforce_session_owner')
    && str_contains($historyEndpoint, 'receptionist_ai_public_history');
$checks['server reset clears AI session state and is CSRF-protected'] = str_contains($resetEndpoint, "unset(\$_SESSION['receptionist_ai_message_count']")
    && str_contains($resetEndpoint, "receptionist_ai_history")
    && str_contains($resetEndpoint, 'HTTP_X_CSRF_TOKEN')
    && str_contains($resetEndpoint, "'POST'");
$checks['model cannot author arbitrary URLs or factual recommendation copy'] = str_contains($aiSource, "preg_match('/(?:https?:\\/\\/|www\\.)/i'")
    && str_contains($aiSource, "'recommend' => 'I’ll use those details")
    && str_contains($aiSource, "'availability' => 'I’ll check the selected dates");
$checks['slot correction/context and provider safety hooks are present'] = str_contains($aiSource, 'array_replace($base, $raw)')
    && str_contains($aiSource, 'FILTER_VALIDATE_INT')
    && str_contains($aiSource, 'receptionist_ai_canonical_date')
    && str_contains($aiSource, 'receptionist_ai_capacity_max')
    && str_contains($aiSource, 'receptionist_ai_provider');
$checks['provider uses strict schema options and retries only compatible 4xx errors'] = str_contains($aiSource, 'receptionist_ai_sanitize_provider_error_code')
    && str_contains($aiSource, 'private function request')
    && str_contains($aiSource, "'type' => 'json_schema'")
    && str_contains($aiSource, "'require_parameters' => true")
    && str_contains($aiSource, "'id' => 'response-healing'")
    && str_contains($aiSource, '_retry_without_structured_output')
    && str_contains($aiSource, 'retried_without_response_format')
    && str_contains($aiSource, "'http_status'")
    && str_contains($aiSource, "'provider_error_code'")
    && str_contains($aiSource, 'receptionist_ai_should_retry_without_response_format');
$schema = receptionist_ai_response_schema();
$checks['receptionist response schema is strict and matches the normalized contract'] = $schema['type'] === 'object'
    && $schema['additionalProperties'] === false
    && $schema['required'] === ['language', 'action', 'reply', 'faq_id', 'slots', 'quick_replies']
    && $schema['properties']['language']['enum'] === ['en', 'fil', 'taglish']
    && $schema['properties']['action']['enum'] === ['ask', 'social', 'faq', 'recommend', 'venue', 'availability', 'contact', 'unsupported']
    && $schema['properties']['faq_id']['type'] === ['string', 'null']
    && $schema['properties']['slots']['additionalProperties'] === false
    && $schema['properties']['slots']['required'] === ['intent', 'occasion', 'purpose', 'group_size', 'preference', 'start_date', 'end_date', 'active_venue_id', 'active_room_group_id']
    && $schema['properties']['quick_replies']['maxItems'] === 4;
$checks['structured-output retry policy excludes non-format, rate-limit, and server failures'] = receptionist_ai_should_retry_without_response_format(true, 400, 'invalid_request_error', 'json schema is unsupported')
    && !receptionist_ai_should_retry_without_response_format(true, 400, 'invalid_request_error', 'model is unavailable')
    && !receptionist_ai_should_retry_without_response_format(true, 429, 'json_schema', 'json schema is unsupported')
    && !receptionist_ai_should_retry_without_response_format(true, 503, 'json_schema', 'json schema is unsupported')
    && !receptionist_ai_should_retry_without_response_format(false, 400, 'json_schema', 'json schema is unsupported');
$checks['provider diagnostic code sanitizer excludes raw provider details'] = receptionist_ai_sanitize_provider_error_code('unsupported_parameter') === 'unsupported_parameter'
    && receptionist_ai_sanitize_provider_error_code('message with spaces and secrets') === null
    && receptionist_ai_sanitize_provider_error_code(str_repeat('x', 81)) === null;
$checks['chat client is safe text-only, bounded, session-restored, and supports in-chat guided fallback'] = str_contains($chatJs, 'textContent = message')
    && str_contains($showroomPhp, 'maxlength="500"')
    && !str_contains($chatJs, 'sessionStorage')
    && str_contains($chatJs, 'slice(-16)')
    && str_contains($chatJs, 'restoreServerHistory')
    && str_contains($chatJs, 'receptionist_chat_history.php')
    && str_contains($chatJs, 'serverHistoryPromise')
    && !str_contains($chatJs, 'serverResetPromise = resetServerSession();')
    && str_contains($chatJs, 'await serverResetPromise')
    && !str_contains($chatJs, 'onGuidedFallback')
    && !str_contains($showroomJs, 'onGuidedFallback')
    && str_contains($chatJs, 'X-CSRF-Token');
$checks['chat warns about AI privacy, restores context, and gates all controls with Continue'] = str_contains($showroomPhp, 'Messages go to an AI service')
    && str_contains($chatJs, 'receptionist_chat_reset.php')
    && str_contains($chatJs, 'SevillaReceptionistGateChanged')
    && str_contains($chatJs, 'data-chat-gate-tabindex');
$checks['chat is collapsed by default with an explicit secondary toggle and close focus path'] = str_contains($showroomPhp, 'data-receptionist-chat-toggle')
    && str_contains($showroomPhp, 'data-receptionist-chat-close')
    && str_contains($showroomPhp, 'id="receptionist-chat-shell"')
    && str_contains($showroomPhp, 'aria-hidden="true" hidden')
    && str_contains($chatJs, 'const setChatOpen')
    && str_contains($chatJs, 'input.focus()')
    && str_contains($chatJs, 'setChatOpen(false)');
$checks['first typed-chat open seeds a localized virtual receptionist greeting'] = str_contains($chatJs, 'const greetingCopy')
    && str_contains($chatJs, 'virtual receptionist')
    && str_contains($chatJs, 'const selectedLocale')
    && str_contains($chatJs, 'const ensureGreeting')
    && preg_match('/if \(next\) \{\s*ensureGreeting\(\);/s', $chatJs) === 1;
$checks['fresh chat reconciles old guide state after history and preserves current-page guide actions'] = str_contains($chatJs, 'const hasPriorConversation = stored.messages.some(turn => turn.role === "user");')
    && str_contains($chatJs, 'options.onFreshChatOpen()')
    && str_contains($chatJs, 'before revealing chat')
    && str_contains($showroomJs, 'if (currentPageGuideInteraction) return;')
    && !str_contains($showroomJs, 'const visibleSelectedVenue =')
    && str_contains($showroomJs, 'markCurrentPageGuideInteraction();')
    && str_contains($showroomJs, 'sessionStorage.removeItem("guideContext");')
    && str_contains($showroomJs, 'renderGreeting({ announce: false });');
$checks['same-page chat reopen does not duplicate the greeting'] = str_contains($chatJs, 'if (stored.messages.length || transcript.children.length) return;')
    && str_contains($chatJs, 'chatShell.hidden = !next')
    && str_contains($chatJs, 'setChatOpen(true)')
    && str_contains($chatJs, 'if (chatOpen) { setChatOpen(false); return; }');
$checks['Start over clears server and visible turns and reseeds the greeting'] = str_contains($chatJs, 'conversationGeneration++')
    && str_contains($chatJs, 'requestGeneration !== conversationGeneration')
    && str_contains($chatJs, 'pendingMessage = "";')
    && str_contains($chatJs, 'serverHistoryPromise = Promise.resolve();')
    && str_contains($chatJs, 'serverResetPromise = resetServerSession()')
    && str_contains($chatJs, 'stored = { messages: [], context: {} };')
    && str_contains($chatJs, 'transcript.replaceChildren();')
    && substr_count($chatJs, 'ensureGreeting();') >= 2
    && str_contains($chatJs, 'suppressDialogueEvents++')
    && str_contains($showroomJs, 'data-receptionist-chat-start-over')
    && str_contains($showroomJs, 'Object.keys(guideContext).forEach(key => { guideContext[key] = null; });');
$guidedOrder = strpos($showroomPhp, 'id="receptionist-choices"');
$chatToggleOrder = strpos($showroomPhp, 'data-receptionist-chat-toggle');
$chatShellOrder = strpos($showroomPhp, 'id="receptionist-chat-shell"');
$checks['guided choices remain primary before the secondary chat DOM path'] = $guidedOrder !== false
    && $chatToggleOrder !== false && $chatShellOrder !== false
    && $guidedOrder < $chatToggleOrder && $chatToggleOrder < $chatShellOrder;
$checks['chat uses an exclusive root mode and keeps closed aria state consistent'] = str_contains($chatJs, 'is-chat-open')
    && str_contains($chatJs, 'const setNonChatMode')
    && str_contains($chatJs, 'gated || !chatOpen ? "true" : "false"')
    && str_contains($chatJs, 'restoreChoicesHome();')
    && str_contains($showroomCss, '.receptionist-chat-conversation #receptionist-choices')
    && str_contains($showroomCss, 'overflow: hidden;');
$checks['chat avoids closed-flow mirroring and normalizes stored duplicate turns'] = str_contains($chatJs, 'const normalizeMessages')
    && str_contains($chatJs, 'if (!chatOpen) return;')
    && str_contains($chatJs, 'SevillaReceptionistClosed')
    && str_contains($chatJs, 'SevillaReceptionistOpening');
$checks['successful AI action gate events do not collapse active typed chat'] = str_contains($chatJs, 'if (nextGated && chatOpen)')
    && str_contains($chatJs, 'deferredGate = true')
    && str_contains($chatJs, 'if (!next && deferredGate)')
    && str_contains($chatJs, 'setGated(Boolean(event.detail?.gated))');
$checks['chat panel is compact, dark, bounded, touch-safe, and responsive'] = str_contains($showroomCss, 'background-color: #17120e !important')
    && str_contains($showroomCss, '.receptionist-chat-conversation')
    && str_contains($showroomCss, 'overflow-y: auto;')
    && str_contains($showroomCss, '.receptionist-chat-toggle')
    && str_contains($showroomCss, '.receptionist-chat-form-actions .receptionist-choice { width: auto; flex: 0 0 auto; }')
    && str_contains($showroomCss, 'flex: 1 0 100%')
    && str_contains($showroomCss, '@media (max-width: 700px)');
$checks['unified panel keeps chat, guided choices, and venue details visible together'] = str_contains($showroomCss, '.showroom-receptionist.is-chat-open .receptionist-panel {')
    && str_contains($showroomCss, 'height: min(88svh, 48rem)')
    && str_contains($showroomCss, 'left: clamp(27rem, 34vw, 40rem)')
    && str_contains($showroomCss, '.receptionist-chat-conversation #receptionist-choices')
    && str_contains($showroomPhp, 'id="receptionist-chat-guided-content"')
    && str_contains($chatJs, 'guidedContent.appendChild(choices)')
    && str_contains($chatJs, 'choicesHomeAnchor.parentNode.insertBefore(choices, choicesHomeAnchor.nextSibling)')
    && !str_contains($showroomCss, '#receptionist-choices:has(> [data-receptionist-intent])')
    && !str_contains($showroomCss, '#receptionist-choices:has([data-receptionist-answer])')
    && str_contains($showroomCss, 'background: rgba(20, 16, 12, .98);')
    && str_contains($showroomCss, 'flex-direction: column;')
    && str_contains($showroomJs, 'showcase.dataset.receptionistVenueCard = "true";')
    && str_contains($showroomJs, 'appendFact(facts, "Capacity"')
    && str_contains($showroomJs, 'appendFact(facts, "Starting rate"')
    && str_contains($showroomJs, 'receptionist-amenities')
    && str_contains($showroomJs, 'const findReceptionistVenue')
    && str_contains($showroomJs, 'const restoreSelectedVenueCard');
$checks['stable quick-action IDs drive category switching without duplicate text dispatch'] = str_contains($chatJs, 'button.dataset.receptionistQuickAction')
    && str_contains($chatJs, 'button.dataset.receptionistChatPrompt')
    && str_contains($chatJs, 'options.onQuickAction(actionId, prompt => submit(prompt))')
    && str_contains($chatJs, 'if (chatOpen && event.isTrusted && guided')
    && str_contains($showroomJs, 'if (category) { markCurrentPageGuideInteraction(); return true; }')
    && str_contains($chatJs, 'category_event_hall: "event hall"')
    && str_contains($chatJs, 'category_hotel_room: "hotel room"')
    && str_contains($chatJs, 'submitCategoryAction(actionId)')
    && str_contains($chatJs, 'guidedCategory.getAttribute("data-receptionist-intent")')
    && !str_contains($showroomJs, 'querySelector(`[data-receptionist-intent="${category}"]`)');
$checks['chat moves the live guided node into one conversation scroll region without mirrored controls'] = str_contains($chatJs, 'moveChoicesIntoChat')
    && str_contains($chatJs, 'restoreChoicesHome')
    && str_contains($showroomPhp, 'id="receptionist-choices"')
    && substr_count($showroomPhp, 'id="receptionist-choices"') === 1
    && str_contains($showroomJs, 'data-answer-key": key')
    && str_contains($showroomCss, '.receptionist-chat-conversation #receptionist-choices')
    && !str_contains($chatJs, 'const guidedReplies =')
    && !str_contains($chatJs, 'const categoryReplies =');
$checks['hotel guest-range controls keep their canonical data on the moved guided node'] = str_contains($showroomJs, 'data-answer-key": key')
    && str_contains($chatJs, 'event.target.closest(".receptionist-choices [data-receptionist-answer]')
    && str_contains($chatJs, 'moveChoicesIntoChat')
    && !str_contains($chatJs, 'renderGuidedReplies');
$checks['retry fallback preserves the fixed category row and inserts Retry in suggestions'] = str_contains($chatJs, 'if (suggestedReplies.children.length >= 4) suggestedReplies.lastElementChild.remove();')
    && str_contains($chatJs, 'suggestedReplies.appendChild(retry);')
    && !str_contains($chatJs, 'quickReplies.lastElementChild.remove()');
$checks['venue cards require exact hotel room-group identity and clear unavailable stale cards'] = str_contains($showroomJs, 'requestedRoomGroupId > 0 && Number(item.room_group_id) === requestedRoomGroupId')
    && str_contains($showroomJs, 'guideContext.activeRoomGroupId = room.room_group_id ? Number(room.room_group_id) : null;')
    && str_contains($showroomJs, 'guideContext.activeVenueId = null;')
    && str_contains($showroomJs, 'Venue not in this showroom')
    && str_contains($showroomJs, 'Browse available options');
$checks['expanded chat gives the transcript flexible viewport space without clipping controls'] = str_contains($showroomCss, 'height: min(60svh, 38rem, calc(78svh - 3.5rem))')
    && str_contains($showroomCss, 'flex: 1 1 auto;')
    && str_contains($showroomCss, 'max-height: none;')
    && str_contains($showroomCss, '.receptionist-chat-conversation')
    && str_contains($showroomCss, 'min-height: 0;')
    && str_contains($showroomCss, 'height: min(90svh, calc(100svh - 2rem))');
$checks['restored hotel context validates values and maps numeric ranges safely'] = str_contains($showroomJs, 'normalizeRestoredGuideContext')
    && str_contains($showroomJs, 'restorableHotelRange')
    && str_contains($showroomJs, 'restorableDate')
    && str_contains($chatJs, 'groupSizeExact')
    && !str_contains($chatJs, 'return range ? Number(range[2]) : null');
$checks['server context is revalidated across turns and init does not reset it'] = str_contains($endpoint, "receptionist_ai_context")
    && str_contains($resetEndpoint, "receptionist_ai_context")
    && !str_contains($chatJs, 'serverResetPromise = resetServerSession();');
$checks['showroom keeps the deterministic source of truth and adds the chat module'] = str_contains($showroomPhp, 'assets/js/receptionist-chat.js?v=')
    && str_contains($showroomPhp, 'receptionist-chat-transcript')
    && str_contains($showroomJs, 'SevillaReceptionistChat.init')
    && str_contains($showroomJs, 'applyReceptionistChatAction')
    && str_contains($showroomJs, 'sevilla360-receptionist-auto-open-v1')
    && str_contains($showroomJs, 'if (!receptionistAutoOpen) receptionistReopen.hidden = false');
$checks['chat keeps booking handoff deterministic and never exposes provider credentials'] = str_contains($showroomJs, 'booking.php')
    && !str_contains($showroomJs, 'AI_API_KEY')
    && !str_contains($chatJs, 'AI_API_KEY')
    && str_contains($chatJs, 'credentials: "same-origin"');
$checks['Support and admin use the same normalized FAQ helper'] = str_contains($supportPhp, 'receptionist_faq_load($conn)')
    && str_contains($adminPhp, 'receptionist_faq_load($conn)')
    && str_contains($adminPhp, 'support-faq-category')
    && str_contains($adminPhp, 'support-faq-phrases')
    && str_contains($savePhp, 'receptionist_faq_normalize_item')
    && str_contains($savePhp, 'receptionist_faq_json')
    && str_contains($adminJs, 'id: row.dataset.faqId');
$checks['locale is enforced server-side and venue catalog identity is authoritative'] = str_contains($aiSource, 'requested response language')
    && str_contains($aiSource, 'receptionist_ai_public_venue_catalog')
    && str_contains($aiSource, 'receptionist_ai_catalog_venue')
    && str_contains($aiSource, 'active_room_group_id')
    && str_contains($endpoint, '$venueCatalog');
$savedLimitEnv = [];
foreach (['AI_TIMEOUT_SECONDS', 'AI_MAX_OUTPUT_TOKENS'] as $limitKey) {
    $savedLimitEnv[$limitKey] = array_key_exists($limitKey, $_ENV) ? $_ENV[$limitKey] : null;
}
$_ENV['AI_TIMEOUT_SECONDS'] = '999';
$_ENV['AI_MAX_OUTPUT_TOKENS'] = '999';
$boundedLimits = receptionist_ai_limits();
$_ENV['AI_TIMEOUT_SECONDS'] = '20';
$_ENV['AI_MAX_OUTPUT_TOKENS'] = '600';
$defaultLimits = receptionist_ai_limits();
foreach ($savedLimitEnv as $limitKey => $limitValue) {
    if ($limitValue === null) unset($_ENV[$limitKey]); else $_ENV[$limitKey] = $limitValue;
}
$checks['AI limits use router-safe defaults and bounded upper clamps'] = $defaultLimits === ['timeout' => 20, 'tokens' => 600]
    && $boundedLimits === ['timeout' => 30, 'tokens' => 800];
$checks['AI environment defaults are disabled and keyless'] = str_contains($env, 'AI_ENABLED=0')
    && str_contains($env, 'AI_PROVIDER=openrouter')
    && str_contains($env, 'AI_TIMEOUT_SECONDS=20')
    && str_contains($env, 'AI_MAX_OUTPUT_TOKENS=600')
    && preg_match('/^AI_API_KEY=\s*$/m', $env) === 1
    && preg_match('/^AI_MODEL=\s*$/m', $env) === 1;

$captureRequest = static function (string $model, string $providerId): array {
    $capturedRequest = [];
    $provider = new ReceptionistGenericOpenAiProvider('test-key', 'https://example.test/v1', $model, $providerId, '', 'Test',
        static function (string $url, array $headers, string $body, int $timeout, bool $structured) use (&$capturedRequest): array {
            $decoded = json_decode($body, true);
            $capturedRequest = is_array($decoded) ? $decoded : [];
            return ['status' => 200, 'raw' => json_encode(['choices' => [['message' => ['content' => json_encode(['language' => 'en', 'action' => 'social', 'reply' => 'Hi', 'faq_id' => null, 'slots' => [], 'quick_replies' => []])], 'finish_reason' => 'stop']]])];
        }, static function (int $milliseconds): void {}, static function (): float { return 1000.0; });
    $provider->complete([['role' => 'user', 'content' => 'hi']], 100, 5);
    return $capturedRequest;
};
$googleBareRequest = $captureRequest('gemini-3.6-flash', 'google');
$googlePrefixedRequest = $captureRequest('models/gemini-3.6-flash', 'google');
$openRouterRequest = $captureRequest('custom/model-id', 'openrouter');
$checks['Google request model IDs use one models prefix while other providers stay unchanged'] = ($googleBareRequest['model'] ?? null) === 'models/gemini-3.6-flash'
    && ($googlePrefixedRequest['model'] ?? null) === 'models/gemini-3.6-flash'
    && ($openRouterRequest['model'] ?? null) === 'custom/model-id';
$checks['Google requests omit unsupported structured-output options while OpenRouter retains strict schema'] = !array_key_exists('response_format', $googleBareRequest)
    && !array_key_exists('provider', $googleBareRequest)
    && !array_key_exists('plugins', $googleBareRequest)
    && is_array($openRouterRequest['response_format'] ?? null)
    && ($openRouterRequest['response_format']['type'] ?? null) === 'json_schema'
    && ($openRouterRequest['provider']['require_parameters'] ?? null) === true
    && ($openRouterRequest['plugins'][0]['id'] ?? null) === 'response-healing';

// Reliability/intelligence regression contracts. These are deliberately
// provider-free: transport behavior is exercised with injectable callbacks.
$checks['strict canonical dates reject rollover while accepting valid dates'] = receptionist_ai_canonical_date('2024-02-29', new DateTimeImmutable('2024-01-01')) === '2024-02-29'
    && receptionist_ai_canonical_date('2024-02-30', new DateTimeImmutable('2024-01-01')) === null
    && receptionist_ai_canonical_date('2023-02-29', new DateTimeImmutable('2023-01-01')) === null;
$mergedSlots = receptionist_ai_merge_slots(['intent' => 'Hotel Room', 'group_size' => 4, 'start_date' => '2035-01-01'], ['group_size' => null, 'start_date' => null, 'preference' => 'save']);
$resetSlots = receptionist_ai_merge_slots(['intent' => 'Hotel Room', 'group_size' => 4], ['group_size' => null], ['group_size']);
$checks['slot merge preserves valid context across model nulls and supports explicit resets'] = ($mergedSlots['group_size'] ?? null) === 4
    && ($mergedSlots['start_date'] ?? null) === '2035-01-01'
    && ($mergedSlots['preference'] ?? null) === 'save'
    && !array_key_exists('group_size', $resetSlots);
$checks['fallback classes map to safe machine-readable retry semantics'] = receptionist_ai_fallback_metadata('provider_rate_limit')['code'] === 'busy'
    && receptionist_ai_fallback_metadata('provider_timeout')['code'] === 'provider_timeout'
    && receptionist_ai_fallback_metadata('provider_transport')['code'] === 'network_error'
    && receptionist_ai_fallback_metadata('visit_limit')['retryable'] === false
    && receptionist_ai_fallback_metadata('provider_timeout')['retryable'] === true;
$checks['deterministic parser handles typo, aliases, exact counts, words, and weekend dates'] = receptionist_knowledge_booking_group_size('wnt to book a party of twenty five') === 25
    && receptionist_knowledge_booking_group_size('group of 12 guests') === 12
    && receptionist_knowledge_booking_group_size('kami dalawa') === 2
    && receptionist_knowledge_category_hint('gusto ko ng reception sa Infinity') === 'Event Hall'
    && receptionist_knowledge_booking_date('next weekend') !== null;
$hotelTurnOne = receptionist_knowledge_reply($knowledgeRecords, 'i want to book a hotel room');
$hotelPreparedOne = receptionist_ai_prepare_knowledge($db, $hotelTurnOne ?? [], [], []);
$staleGuideResolution = receptionist_ai_resolve_context($hotelPreparedOne['slots'] ?? [], [
    'intent' => 'Event Hall', 'occasion' => 'wedding', 'group_size' => 80,
], receptionist_knowledge_category_hint('we are two people'));
$hotelTurnTwo = receptionist_knowledge_reply($knowledgeRecords, 'we are two people', 'en', $staleGuideResolution['base_slots'] ?? []);
$hotelPreparedTwo = receptionist_ai_prepare_knowledge($db, $hotelTurnTwo ?? [], $staleGuideResolution['base_slots'] ?? [], []);
$checks['hotel booking follow-up keeps PHP session intent when stale showroom context conflicts'] = ($hotelPreparedOne['slots']['intent'] ?? null) === 'Hotel Room'
    && ($staleGuideResolution['request_slots'] ?? null) === []
    && ($hotelPreparedTwo['slots']['intent'] ?? null) === 'Hotel Room'
    && ($hotelPreparedTwo['slots']['group_size'] ?? null) === 2
    && ($hotelPreparedTwo['missing_slots'][0] ?? null) === 'preference'
    && str_contains(strtolower($hotelPreparedTwo['reply'] ?? ''), 'room search');
$poolFollowUpResolution = receptionist_ai_resolve_context(
    ['intent' => 'Hotel Room'], ['intent' => 'Event Hall'], receptionist_knowledge_explicit_category_switch('is there a pool?')
);
$checks['amenity words do not switch booking category while explicit villa references do'] = ($poolFollowUpResolution['base_slots']['intent'] ?? null) === 'Hotel Room'
    && ($poolFollowUpResolution['request_slots'] ?? null) === []
    && receptionist_knowledge_explicit_category_switch('What about in the villa?') === 'Resort Villa';
$memoryHistory = receptionist_ai_append_history([], 'I want to book a hotel room for two people.', 'How many guests?');
$memoryReply = receptionist_knowledge_reply($knowledgeRecords, 'what did I say?', 'en', ['intent' => 'Hotel Room', 'group_size' => 2], $memoryHistory);
$privateHistory = receptionist_ai_public_history([
    ['role' => 'user', 'content' => 'I want a hotel room for two people.'],
    ['role' => 'user', 'content' => 'My email is visitor@example.test'],
    ['role' => 'assistant', 'content' => 'Your phone number is 09171234567.'],
    ['role' => 'assistant', 'content' => 'What dates do you need?'],
]);
$checks['bounded session recall answers a direct memory question without returning sensitive user turns'] = str_contains($memoryReply['reply'] ?? '', 'hotel room for two people')
    && count($privateHistory) === 2
    && !str_contains(implode(' ', array_column($privateHistory, 'content')), 'visitor@example.test')
    && !str_contains(implode(' ', array_column($privateHistory, 'content')), '09171234567');
$priorSession = $_SESSION ?? [];
$_SESSION = [
    'receptionist_ai_history' => [['role' => 'user', 'content' => 'legacy guest turn']],
    'receptionist_ai_context' => ['intent' => 'Hotel Room'],
    'receptionist_ai_message_count' => 3,
];
receptionist_ai_enforce_session_owner();
$guestHistoryPreserved = ($_SESSION['receptionist_ai_owner'] ?? null) === 'guest'
    && isset($_SESSION['receptionist_ai_history'][0]['content']);
$_SESSION = [
    'logged_in' => true,
    'user_id' => 42,
    'role' => 'customer',
    'receptionist_ai_history' => [['role' => 'user', 'content' => 'previous owner turn']],
    'receptionist_ai_context' => ['intent' => 'Hotel Room'],
    'receptionist_ai_message_count' => 3,
];
receptionist_ai_enforce_session_owner();
$authenticatedHistoryCleared = str_starts_with((string)($_SESSION['receptionist_ai_owner'] ?? ''), 'authenticated:')
    && !isset($_SESSION['receptionist_ai_history'], $_SESSION['receptionist_ai_context'], $_SESSION['receptionist_ai_message_count']);
$_SESSION = $priorSession;
$checks['legacy guest history survives owner-marker introduction while unowned authenticated history is cleared'] = $guestHistoryPreserved && $authenticatedHistoryCleared;
$faqMerged = receptionist_faq_merge_defaults([
    ['id' => 'faq-cms-valid', 'category' => 'General', 'question' => 'Can I bring children?', 'answer' => 'Yes, please include them in the guest count.', 'phrases' => ['kids']],
    ['question' => 'qwasda', 'answer' => 'x'],
]);
$checks['FAQ CMS entries merge with defaults and reject obvious junk'] = count($faqMerged) > count($defaults)
    && receptionist_faq_find($faqMerged, 'faq-booking-window') !== null
    && receptionist_faq_find($faqMerged, 'faq-cms-valid') !== null
    && receptionist_faq_find($faqMerged, receptionist_faq_stable_id('qwasda')) === null;
$compactCatalog = receptionist_ai_compact_venue_catalog([
    ['id' => 2, 'category' => 'Hotel Room', 'name' => 'Stellar', 'room_group_id' => 8],
    ['id' => 2, 'category' => 'Hotel Room', 'name' => 'Stellar', 'room_group_id' => 8],
    ['id' => 2, 'category' => 'Hotel Room', 'name' => 'Stellar', 'room_group_id' => 9],
], 10);
$checks['venue prompt catalog keeps unique authoritative venue/group identities'] = count($compactCatalog) === 2
    && count(array_unique(array_map(static fn(array $row): string => implode('|', [(string)$row['id'], (string)($row['room_group_id'] ?? '')]), $compactCatalog))) === 2;
$overflowRows = [];
for ($overflowIndex = 1; $overflowIndex <= 120; $overflowIndex++) {
    $overflowRows[] = ['id' => 1000 + $overflowIndex, 'category' => 'Event Hall', 'name' => 'Overflow Hall ' . $overflowIndex, 'description' => 'Overflow event space', 'event_rate' => 10000, 'event_base_capacity' => 10, 'event_max_capacity' => 500];
}
$overflowRecords = receptionist_knowledge_build_records($overflowRows, [], $defaults);
$overflowAnswer = receptionist_knowledge_reply($overflowRecords, 'I want to book Overflow Hall 120', 'en');
$overflowCatalog = array_map(static fn(array $row): array => ['id' => $row['id'], 'category' => $row['category'], 'name' => $row['name'], 'room_group_id' => null], $overflowRows);
$prioritizedOverflow = receptionist_ai_compact_venue_catalog($overflowCatalog, 5, 'Please book Overflow Hall 120', []);
$checks['overflow venue identities remain directly selectable while prompts stay bounded'] = ($overflowAnswer['slots']['active_venue_id'] ?? null) === 1120
    && count($prioritizedOverflow) === 5
    && ($prioritizedOverflow[0]['id'] ?? null) === 1120;
$switchAnswer = receptionist_knowledge_reply($knowledgeRecords, 'book a hotel for 4 guests on March 14, 2037', 'en', [
    'intent' => 'Event Hall', 'occasion' => 'wedding', 'group_size' => 100, 'start_date' => '2036-01-01', 'active_venue_id' => 1,
]);
$categoryActionSwitch = receptionist_knowledge_reply($knowledgeRecords, 'hotel room', 'en', [
    'intent' => 'Event Hall', 'occasion' => 'wedding', 'group_size' => 100, 'start_date' => '2036-01-01', 'active_venue_id' => 1,
]);
$checks['intent switches preserve same-turn details without stale prior-flow slots'] = ($switchAnswer['slots']['intent'] ?? null) === 'Hotel Room'
    && ($switchAnswer['slots']['group_size'] ?? null) === 4
    && ($switchAnswer['slots']['start_date'] ?? null) === '2037-03-14'
    && !array_key_exists('occasion', $switchAnswer['slots'] ?? [])
    && !array_key_exists('active_venue_id', $switchAnswer['slots'] ?? [])
    && ($switchAnswer['slots']['group_size'] ?? null) !== 100;
$checks['stable hotel category command resets server context before guest follow-up'] = ($categoryActionSwitch['slots']['intent'] ?? null) === 'Hotel Room'
    && ($categoryActionSwitch['missing_slots'][0] ?? null) === 'group_size'
    && !array_key_exists('occasion', $categoryActionSwitch['slots'] ?? [])
    && !array_key_exists('active_venue_id', $categoryActionSwitch['slots'] ?? []);
$preparedSwitch = receptionist_ai_prepare_knowledge($db, [
    'slots' => ['intent' => 'Hotel Room', 'group_size' => 4, 'start_date' => '2037-03-14'],
    'missing_slots' => ['end_date'], 'quick_replies' => ['Check-out date'],
], ['intent' => 'Event Hall', 'occasion' => 'wedding', 'group_size' => 100, 'start_date' => '2036-01-01', 'active_venue_id' => 1], []);
$checks['knowledge preparation preserves same-turn switch patch after clearing stale context'] = ($preparedSwitch['slots']['intent'] ?? null) === 'Hotel Room'
    && ($preparedSwitch['slots']['group_size'] ?? null) === 4
    && ($preparedSwitch['slots']['start_date'] ?? null) === '2037-03-14'
    && !array_key_exists('occasion', $preparedSwitch['slots'] ?? [])
    && !array_key_exists('active_venue_id', $preparedSwitch['slots'] ?? [])
    && ($preparedSwitch['slots']['start_date'] ?? null) !== '2036-01-01';
$staleBookingContext = [
    'intent' => 'Event Hall', 'occasion' => 'wedding', 'group_size' => 100,
    'start_date' => '2036-01-01', 'active_venue_id' => 1,
];
$genericBookingReply = receptionist_knowledge_reply($knowledgeRecords, 'i want to book', 'en', $staleBookingContext);
$genericBookingCatalog = [['id' => 1, 'category' => 'Event Hall', 'name' => 'Infinity Hall', 'room_group_id' => null]];
$preparedGenericBooking = receptionist_ai_prepare_knowledge($db, $genericBookingReply ?? [], $staleBookingContext, $genericBookingCatalog);
$dateAfterGenericReset = receptionist_knowledge_reply($knowledgeRecords, 'oct 26', 'en', $preparedGenericBooking['slots'] ?? []);
$genuineContinuation = receptionist_knowledge_reply($knowledgeRecords, '100 guests', 'en', [
    'intent' => 'Event Hall', 'occasion' => 'wedding', 'start_date' => '2037-10-26',
]);
$genericBookingVariants = array_map(static fn(string $prompt): ?array => receptionist_knowledge_reply($knowledgeRecords, $prompt, 'en', $staleBookingContext), [
    'I would like to reserve', 'I want to make a reservation', 'make a booking', 'reserve',
]);
$clearedKeys = ['occasion', 'purpose', 'group_size', 'preference', 'start_date', 'end_date', 'active_venue_id', 'active_room_group_id'];
$checks['generic booking start explicitly resets stale context and asks for venue type'] = ($genericBookingReply['booking_continuation'] ?? false) === true
    && ($genericBookingReply['missing_slots'] ?? []) === ['intent']
    && ($genericBookingReply['slots'] ?? []) === []
    && ($genericBookingReply['reset_context'] ?? false) === true
    && ($genericBookingReply['quick_replies'] ?? []) === ['Event', 'Hotel', 'Villa', 'Support FAQs']
    && ($preparedGenericBooking['slots'] ?? []) === []
    && !array_intersect($clearedKeys, array_keys($preparedGenericBooking['slots'] ?? []));
$checks['standalone date after generic reset cannot revive event or wedding context'] = ($dateAfterGenericReset['missing_slots'] ?? []) === ['intent']
    && ($dateAfterGenericReset['slots']['intent'] ?? null) === null
    && !array_intersect(['occasion', 'active_venue_id', 'group_size'], array_keys($dateAfterGenericReset['slots'] ?? []))
    && !str_contains(strtolower((string)($dateAfterGenericReset['reply'] ?? '')), 'event date');
$checks['genuine booking continuation retains current flow context'] = ($genuineContinuation['slots']['intent'] ?? null) === 'Event Hall'
    && ($genuineContinuation['slots']['occasion'] ?? null) === 'wedding'
    && ($genuineContinuation['slots']['start_date'] ?? null) === '2037-10-26'
    && ($genuineContinuation['slots']['group_size'] ?? null) === 100;
$checks['generic reserve and make-a-booking equivalents also start clean'] = count($genericBookingVariants) === 4
    && count(array_filter($genericBookingVariants, static fn(?array $answer): bool => is_array($answer)
        && ($answer['reset_context'] ?? false) === true
        && ($answer['missing_slots'] ?? []) === ['intent']
        && ($answer['slots'] ?? []) === [])) === 4;
$resetSession = $preparedGenericBooking['slots'] ?? [];
$freshHotelRequestContext = ['intent' => 'Hotel Room', 'group_size' => 4];
$freshNextSlots = receptionist_ai_validate_slots($db, $freshHotelRequestContext, $resetSession, $genericBookingCatalog);
$oldWeddingHistory = [
    ['role' => 'user', 'content' => 'I want an Event Hall wedding on October 26 for 100 guests.'],
    ['role' => 'assistant', 'content' => 'What kind of event is this?'],
];
$startOverSession = [
    'receptionist_ai_context' => $staleBookingContext,
    'receptionist_ai_history' => $oldWeddingHistory,
    'receptionist_ai_message_count' => 12,
];
unset($startOverSession['receptionist_ai_context'], $startOverSession['receptionist_ai_history'], $startOverSession['receptionist_ai_message_count']);
$freshAfterStartOver = receptionist_ai_validate_slots($db, $freshHotelRequestContext, $startOverSession['receptionist_ai_context'] ?? [], $genericBookingCatalog);
$resetHistory = receptionist_ai_append_history($oldWeddingHistory, 'i want to book', 'Which venue would you like to book?', true);
$continuedHistory = receptionist_ai_append_history($resetHistory, 'Hotel', 'How many guests?', false);
$hotelCountReply = receptionist_knowledge_reply($knowledgeRecords, '4 guests', 'en', ['intent' => 'Hotel Room']);
$checks['generic reset replaces the session flow and the next valid client context is accepted'] = $resetSession === []
    && $freshNextSlots['intent'] === 'Hotel Room'
    && $freshNextSlots['group_size'] === 4
    && !array_key_exists('occasion', $freshNextSlots)
    && !array_key_exists('start_date', $freshNextSlots)
    && !array_key_exists('active_venue_id', $freshNextSlots);
$checks['Start over clears the session flow before accepting a new booking context'] = !array_key_exists('receptionist_ai_context', $startOverSession)
    && !array_key_exists('receptionist_ai_history', $startOverSession)
    && !array_key_exists('receptionist_ai_message_count', $startOverSession)
    && $freshAfterStartOver['intent'] === 'Hotel Room'
    && $freshAfterStartOver['group_size'] === 4
    && !array_key_exists('occasion', $freshAfterStartOver);
$checks['generic reset replaces provider history while ordinary turns append'] = count($resetHistory) === 2
    && count($continuedHistory) === 4
    && !str_contains(strtolower(implode(' ', array_column($resetHistory, 'content'))), 'wedding')
    && $continuedHistory[2]['content'] === 'Hotel';
$checks['hotel guest count advances to a concrete preference question'] = ($hotelCountReply['missing_slots'][0] ?? null) === 'preference'
    && str_contains(strtolower((string)($hotelCountReply['reply'] ?? '')), 'best fit')
    && str_contains(strtolower((string)($hotelCountReply['reply'] ?? '')), 'lowest price')
    && !str_contains(strtolower((string)($hotelCountReply['reply'] ?? '')), 'choose a venue');
$checks['generic booking reset is explicit across server and client context boundaries'] = str_contains($knowledgeSource, "'reset_context' => true")
    && str_contains($aiSource, "\$answer['reset_context']")
    && !str_contains($endpoint, "receptionist_ai_context_reset")
    && !str_contains($resetEndpoint, "receptionist_ai_context_reset")
    && str_contains($showroomJs, 'result.reset_context === true');
$checks['chat initialization is idempotent and hotel follow-up asks for a concrete count'] = str_contains($chatJs, 'root.dataset.receptionistChatReady === "true"')
    && str_contains($showroomJs, 'window.__sevilla360ShowroomInitialized === true')
    && str_contains($showroomJs, 'Choose the option that includes the total number of adults and children')
    && !str_contains($showroomJs, 'message = "Choose a guest range for your room search."');
$fakeAttempts = 0;
$fakeProvider = new ReceptionistGenericOpenAiProvider('test-key', 'https://example.test/v1', 'test-model', 'test', '', 'Test',
    static function (string $url, array $headers, string $body, int $timeout, bool $structured) use (&$fakeAttempts): array {
        $fakeAttempts++;
        if ($fakeAttempts < 3) return ['status' => 503, 'raw' => '{}'];
        $payload = ['language' => 'en', 'action' => 'social', 'reply' => 'Hi', 'faq_id' => null, 'slots' => [], 'quick_replies' => []];
        return ['status' => 200, 'raw' => json_encode(['choices' => [['message' => ['content' => json_encode($payload)], 'finish_reason' => 'stop']]])];
    }, static function (int $milliseconds): void {}, static function (): float { return 1000.0; });
$retryResult = $fakeProvider->complete([['role' => 'user', 'content' => 'hi']], 100, 5);
$checks['provider retries bounded transient failures under one deadline'] = $retryResult['success'] === true
    && $fakeAttempts === 3 && ($retryResult['diagnostic']['attempt_count'] ?? 0) === 3;
$parseablePayload = static function (string $finishReason, array $extra = []): string {
    $payload = ['language' => 'en', 'action' => 'social', 'reply' => 'Hi', 'faq_id' => null, 'slots' => [], 'quick_replies' => []];
    return json_encode(['choices' => [['message' => ['content' => json_encode($payload)], 'finish_reason' => $finishReason]] + $extra]);
};
$truncatedProvider = new ReceptionistGenericOpenAiProvider('test-key', 'https://example.test/v1', 'test-model', 'test', '', 'Test',
    static fn(string $url, array $headers, string $body, int $timeout, bool $structured): array => ['status' => 200, 'raw' => $parseablePayload('length')],
    static function (int $milliseconds): void {}, static function (): float { return 1000.0; });
$truncatedResult = $truncatedProvider->complete([['role' => 'user', 'content' => 'hi']], 100, 5);
$blockedProvider = new ReceptionistGenericOpenAiProvider('test-key', 'https://example.test/v1', 'test-model', 'test', '', 'Test',
    static fn(string $url, array $headers, string $body, int $timeout, bool $structured): array => ['status' => 200, 'raw' => json_encode(['blocked' => true, 'choices' => [['message' => ['content' => json_encode(['language' => 'en', 'action' => 'social', 'reply' => 'Hi', 'faq_id' => null, 'slots' => [], 'quick_replies' => []])], 'finish_reason' => 'stop']]])],
    static function (int $milliseconds): void {}, static function (): float { return 1000.0; });
$blockedResult = $blockedProvider->complete([['role' => 'user', 'content' => 'hi']], 100, 5);
$checks['parseable truncation or safety blocks never return provider success'] = !$truncatedResult['success']
    && ($truncatedResult['error_class'] ?? null) === 'provider_truncated'
    && ($truncatedResult['diagnostic']['truncated'] ?? false) === true
    && !$blockedResult['success']
    && ($blockedResult['error_class'] ?? null) === 'provider_blocked'
    && ($blockedResult['diagnostic']['blocked'] ?? false) === true;
$deadlineNow = 1000.0;
$deadlineAttempts = 0;
$deadlineSleeps = [];
$deadlineProvider = new ReceptionistGenericOpenAiProvider('test-key', 'https://example.test/v1', 'test-model', 'test', '', 'Test',
    static function (string $url, array $headers, string $body, int $timeout, bool $structured) use (&$deadlineAttempts, &$deadlineNow): array {
        $deadlineAttempts++;
        $deadlineNow += $deadlineAttempts === 1 ? 4.0 : min($timeout / 1000, 2.0);
        return ['status' => 503, 'raw' => '{}'];
    }, static function (int $milliseconds) use (&$deadlineSleeps, &$deadlineNow): void { $deadlineSleeps[] = $milliseconds; $deadlineNow += $milliseconds / 1000; }, static function () use (&$deadlineNow): float { return $deadlineNow; });
$deadlineResult = $deadlineProvider->complete([['role' => 'user', 'content' => 'hi']], 100, 5);
$compatNow = 1000.0;
$compatCalls = [];
$compatSleeps = [];
$compatProvider = new ReceptionistGenericOpenAiProvider('test-key', 'https://example.test/v1', 'test-model', 'test', '', 'Test',
    static function (string $url, array $headers, string $body, int $timeout, bool $structured) use (&$compatCalls, &$compatNow): array {
        $compatCalls[] = $structured;
        if ($structured) { $compatNow += 4.0; return ['status' => 400, 'raw' => json_encode(['error' => ['code' => 'response_format', 'message' => 'json schema unsupported']])]; }
        $compatNow += min($timeout / 1000, 2.0);
        return ['status' => 503, 'raw' => '{}'];
    }, static function (int $milliseconds) use (&$compatSleeps): void { $compatSleeps[] = $milliseconds; }, static function () use (&$compatNow): float { return $compatNow; });
$compatResult = $compatProvider->complete([['role' => 'user', 'content' => 'hi']], 100, 5);
$compatDeadlineNow = 1000.0;
$compatDeadlineCalls = [];
$compatDeadlineSleeps = [];
$compatDeadlineProvider = new ReceptionistGenericOpenAiProvider('test-key', 'https://example.test/v1', 'test-model', 'test', '', 'Test',
    static function (string $url, array $headers, string $body, int $timeout, bool $structured) use (&$compatDeadlineCalls, &$compatDeadlineNow): array {
        $compatDeadlineCalls[] = $structured;
        $compatDeadlineNow += min($timeout / 1000, 5.0);
        return ['status' => 400, 'raw' => json_encode(['error' => ['code' => 'response_format', 'message' => 'json schema unsupported']])];
    }, static function (int $milliseconds) use (&$compatDeadlineSleeps): void { $compatDeadlineSleeps[] = $milliseconds; }, static function () use (&$compatDeadlineNow): float { return $compatDeadlineNow; });
$compatDeadlineResult = $compatDeadlineProvider->complete([['role' => 'user', 'content' => 'hi']], 100, 5);
$checks['provider deadline stops retries and structured fallback before another request or sleep'] = !$deadlineResult['success']
    && $deadlineAttempts === 2 && $deadlineSleeps === [250] && ($deadlineNow - 1000.0) <= 5.000001
    && !$compatResult['success'] && $compatCalls === [true, false] && $compatSleeps === []
    && ($compatResult['diagnostic']['attempt_count'] ?? 0) === 2 && ($compatNow - 1000.0) <= 5.000001
    && !$compatDeadlineResult['success'] && $compatDeadlineCalls === [true] && $compatDeadlineSleeps === []
    && ($compatDeadlineResult['error_class'] ?? null) === 'provider_timeout' && ($compatDeadlineNow - 1000.0) <= 5.000001;
$checks['endpoint exposes fallback metadata and uses the default server logger'] = !str_contains($endpoint, "ini_set('error_log'")
    && str_contains($endpoint, 'fallback_code') && str_contains($endpoint, 'request_id') && str_contains($endpoint, 'prompt_bytes');
$checks['client retains exact guest counts separately from hotel display ranges'] = str_contains($showroomJs, 'groupSizeExact')
    && str_contains($chatJs, 'fallback_code') && str_contains($chatJs, 'pendingMessage');

$failed = array_filter($checks, static fn(bool $passed): bool => !$passed);
foreach ($checks as $label => $passed) echo ($passed ? 'PASS' : 'FAIL') . " - {$label}\n";
exit($failed ? 1 : 0);
