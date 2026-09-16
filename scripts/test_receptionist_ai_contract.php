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
$knowledgePrice = receptionist_knowledge_reply($knowledgeRecords, 'How much for an event?', 'en');
$knowledgePriceFil = receptionist_knowledge_reply($knowledgeRecords, 'Magkano ang event hall?', 'fil');
$knowledgePriceTaglish = receptionist_knowledge_reply($knowledgeRecords, 'Magkano ang event hall?', 'taglish');
$knowledgeCapacity = receptionist_knowledge_reply($knowledgeRecords, 'How many can Infinity Hall fit?', 'en', ['active_venue_id' => 1, 'intent' => 'Event Hall']);
$knowledgeAmenities = receptionist_knowledge_reply($knowledgeRecords, 'What amenities are included?', 'en');
$knowledgePolicy = receptionist_knowledge_reply($knowledgeRecords, 'How does payment and cancellation work?', 'en');
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
$checks['public knowledge selected venue filtering is bounded and safe'] = $knowledgeSelected !== null
    && str_contains($knowledgeSelected['reply'], 'Infinity Hall')
    && !str_contains($knowledgeSelected['reply'], 'Stellar')
    && count($knowledgeRecords) <= RECEPTIONIST_KNOWLEDGE_MAX_RECORDS
    && !array_intersect(['booking_id', 'customer_id', 'payment_submission_id', 'admin_note', 'staff_id'], $knowledgeKeys);
$checks['unknown custom quotes do not receive unrelated venue prices'] = $knowledgeUnknownQuote === null;
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

$endpoint = $source('actions/public/receptionist_chat.php');
$resetEndpoint = $source('actions/public/receptionist_chat_reset.php');
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
    && str_contains($endpoint, "check_rate_limit(\$conn, 'receptionist_chat', 30, 10)")
    && str_contains($endpoint, 'receptionist_ai_message_count')
    && str_contains($endpoint, 'array_slice($history, -16)')
    && str_contains($endpoint, 'receptionist_ai_shortlist_faq')
    && str_contains($endpoint, 'normalize_output($result[\'payload\'], $conn, $baseSlots, $shortlist')
    && str_contains($endpoint, "'validated_slots'");
$checks['endpoint answers bounded public knowledge before provider use and passes only a shortlist to prompts'] = str_contains($endpoint, 'receptionist_public_knowledge_records')
    && str_contains($endpoint, 'receptionist_knowledge_reply')
    && str_contains($endpoint, "'mode' => 'knowledge'")
    && str_contains($endpoint, "'validated_slots' => \$knowledgeAnswer['slots']")
    && str_contains($endpoint, 'receptionist_knowledge_select')
    && str_contains($endpoint, '$knowledgePatch')
    && str_contains($endpoint, 'receptionist_ai_validate_slots($conn, $knowledgePatch')
    && str_contains($aiSource, 'Bounded approved public knowledge')
    && str_contains($chatJs, 'data.mode === "knowledge"')
    && str_contains($chatJs, 'options.onKnowledge')
    && str_contains($knowledgeSource, 'RECEPTIONIST_KNOWLEDGE_MAX_RECORDS')
    && str_contains($knowledgeSource, "v.status = 'Available'");
$checks['server context errors are distinguishable from provider schema fallback'] = str_contains($endpoint, "'code' => " . '$contextError' . " ? 'invalid_context' : 'invalid_request'")
    && str_contains($chatJs, "data.code === \"invalid_context\"")
    && str_contains($showroomJs, 'onInvalidContext');
$checks['endpoint has no transcript persistence or outbound mail'] = !str_contains($endpoint, 'mail(')
    && !str_contains($endpoint, 'create_user_notification')
    && !str_contains($endpoint, 'INSERT INTO');
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
$checks['chat client is safe text-only, bounded, page-scoped, and supports in-chat guided fallback'] = str_contains($chatJs, 'textContent = message')
    && str_contains($showroomPhp, 'maxlength="500"')
    && str_contains($chatJs, 'sessionStorage')
    && str_contains($chatJs, 'slice(-16)')
    && str_contains($chatJs, 'sessionStorage.removeItem(STORAGE_KEY)')
    && !str_contains($chatJs, 'sessionStorage.setItem')
    && str_contains($chatJs, 'serverResetPromise = resetServerSession()')
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
$checks['same-page chat reopen does not duplicate the greeting'] = str_contains($chatJs, 'if (stored.messages.length || transcript.children.length) return;')
    && str_contains($chatJs, 'chatShell.hidden = !next')
    && str_contains($chatJs, 'setChatOpen(!chatOpen)');
$checks['Start over clears turns and reseeds the greeting while suppressing guided duplicate copy'] = str_contains($chatJs, 'stored = { messages: [], context: {} };')
    && str_contains($chatJs, 'transcript.replaceChildren();')
    && substr_count($chatJs, 'ensureGreeting();') >= 2
    && str_contains($chatJs, 'suppressDialogueEvents++');
$guidedOrder = strpos($showroomPhp, 'id="receptionist-choices"');
$chatToggleOrder = strpos($showroomPhp, 'data-receptionist-chat-toggle');
$chatShellOrder = strpos($showroomPhp, 'id="receptionist-chat-shell"');
$checks['guided choices remain primary before the secondary chat DOM path'] = $guidedOrder !== false
    && $chatToggleOrder !== false && $chatShellOrder !== false
    && $guidedOrder < $chatToggleOrder && $chatToggleOrder < $chatShellOrder;
$checks['chat uses an exclusive root mode and keeps closed aria state consistent'] = str_contains($chatJs, 'is-chat-open')
    && str_contains($chatJs, 'const setNonChatMode')
    && str_contains($chatJs, 'gated || !chatOpen ? "true" : "false"')
    && str_contains($showroomCss, '.showroom-receptionist.is-chat-open .receptionist-choices')
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
    && str_contains($showroomCss, 'min-height: clamp(5rem, 14svh, 10rem)')
    && str_contains($showroomCss, '.receptionist-chat-toggle')
    && str_contains($showroomCss, '.receptionist-chat-form-actions .receptionist-choice { width: auto; flex: 0 0 auto; }')
    && str_contains($showroomCss, 'flex: 1 0 100%')
    && str_contains($showroomCss, '@media (max-width: 700px)');
$checks['expanded chat gives the transcript flexible viewport space without clipping controls'] = str_contains($showroomCss, 'height: min(60svh, 38rem, calc(78svh - 3.5rem))')
    && str_contains($showroomCss, 'flex: 1 1 auto;')
    && str_contains($showroomCss, 'max-height: none;')
    && str_contains($showroomCss, 'min-height: clamp(5rem, 14svh, 10rem)')
    && str_contains($showroomCss, 'height: min(90svh, calc(100svh - 2rem))');
$checks['restored hotel context validates values and maps numeric ranges safely'] = str_contains($showroomJs, 'normalizeRestoredGuideContext')
    && str_contains($showroomJs, 'restorableHotelRange')
    && str_contains($showroomJs, 'restorableDate')
    && str_contains($chatJs, 'const range = text.match')
    && str_contains($chatJs, 'Number(range[2])');
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

$failed = array_filter($checks, static fn(bool $passed): bool => !$passed);
foreach ($checks as $label => $passed) echo ($passed ? 'PASS' : 'FAIL') . " - {$label}\n";
exit($failed ? 1 : 0);
