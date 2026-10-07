<?php
declare(strict_types=1);

// Explicit, synthetic live-model evaluation. This never posts to the public
// endpoint, writes bookings, or consumes production rate-limit buckets.
if (PHP_SAPI === 'cli' && in_array('--help', $argv, true)) {
    fwrite(STDOUT, "Usage: php scripts/eval_receptionist_natural_live.php --live [--case=01..20] [--trace]\n"
        . "Runs the bounded synthetic receptionist suite against the configured provider.\n"
        . "--case=NN runs one synthetic conversation; --trace prints synthetic proposal payloads only.\n");
    exit(0);
}
if (PHP_SAPI !== 'cli' || !in_array('--live', $argv, true)) {
    fwrite(STDERR, "Usage: php scripts/eval_receptionist_natural_live.php --live [--case=01] [--trace]\n");
    exit(2);
}
$traceEnabled = in_array('--trace', $argv, true);
$caseFilter = null;
foreach ($argv as $argument) if (preg_match('/\A--case=(\d{1,2})\z/', $argument, $match)) $caseFilter = (int)$match[1];
if ($caseFilter !== null && ($caseFilter < 1 || $caseFilter > 20)) {
    fwrite(STDERR, "Case filter must be between 01 and 20.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/config/env.php';
require_once dirname(__DIR__) . '/includes/receptionist_ai.php';
require_once dirname(__DIR__) . '/includes/receptionist_knowledge.php';
require_once dirname(__DIR__) . '/includes/receptionist_natural.php';

if (receptionist_ai_env('AI_ENABLED', '0') !== '1' || receptionist_ai_provider() === null) {
    fwrite(STDERR, "Live evaluation requires AI_ENABLED=1 with the configured provider credentials.\n");
    exit(2);
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

final class ReceptionistNaturalLiveProvider implements ReceptionistAiProviderInterface
{
    public function __construct(private object $inner, private Closure $onCall) {}
    public function complete(array $messages, int $maxOutputTokens, int $timeoutSeconds): array
    {
        ($this->onCall)();
        return $this->inner->complete($messages, $maxOutputTokens, $timeoutSeconds);
    }
    public function completeWithSchema(array $messages, int $maxOutputTokens, int $timeoutSeconds, array $schema, string $schemaName = '', ?int $attemptLimit = null): array
    {
        ($this->onCall)();
        return $this->inner->completeWithSchema($messages, $maxOutputTokens, $timeoutSeconds, $schema, $schemaName, $attemptLimit);
    }
}

final class ReceptionistNaturalLiveOutageProvider implements ReceptionistAiProviderInterface
{
    public function complete(array $messages, int $maxOutputTokens, int $timeoutSeconds): array
    {
        return ['success' => false, 'error_class' => 'provider_unavailable'];
    }
    public function completeWithSchema(array $messages, int $maxOutputTokens, int $timeoutSeconds, array $schema, string $schemaName = '', ?int $attemptLimit = null): array
    {
        return ['success' => false, 'error_class' => 'provider_unavailable'];
    }
}

$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
$dateA = $today->modify('+18 days')->format('Y-m-d');
$dateB = $today->modify('+20 days')->format('Y-m-d');
$dateC = $today->modify('+25 days')->format('Y-m-d');
$records = [
    ['id' => 'venue-hotel-room-101-group-77', 'kind' => 'venue', 'venue_id' => 101, 'category' => 'Hotel Room', 'name' => 'Rafael', 'room_type' => 'Standard Room', 'room_group_id' => 77,
        'description' => 'A comfortable standard room.', 'amenities' => ['Wi-Fi'], 'base_rate' => 1200, 'rate_unit' => 'per night', 'capacity_base' => 2, 'capacity_max' => 4, 'bed_count_min' => 2, 'bed_count_max' => 2, 'inclusions' => []],
    ['id' => 'venue-hotel-room-303-group-88', 'kind' => 'venue', 'venue_id' => 303, 'category' => 'Hotel Room', 'name' => 'Kristel', 'room_type' => 'Deluxe Room', 'room_group_id' => 88,
        'description' => 'A deluxe room with extra space.', 'amenities' => ['Wi-Fi', 'Balcony'], 'base_rate' => 1650, 'rate_unit' => 'per night', 'capacity_base' => 2, 'capacity_max' => 6, 'bed_count_min' => 2, 'bed_count_max' => 2, 'inclusions' => []],
    ['id' => 'venue-event-hall-202', 'kind' => 'venue', 'venue_id' => 202, 'category' => 'Event Hall', 'name' => 'Infinity Hall', 'room_type' => '', 'room_group_id' => null,
        'description' => 'A published event hall.', 'amenities' => ['Stage'], 'base_rate' => 10000, 'rate_unit' => 'per day', 'capacity_base' => 50, 'capacity_max' => 120, 'capacity_styles' => [], 'inclusions' => []],
    ['id' => 'venue-resort-villa-404', 'kind' => 'venue', 'venue_id' => 404, 'category' => 'Resort Villa', 'name' => 'Lagoon Villa', 'room_type' => '', 'room_group_id' => null,
        'description' => 'A published resort villa.', 'amenities' => ['Private pool'], 'base_rate' => 6500, 'overnight_rate' => 6500, 'rate_unit' => 'per night', 'capacity_base' => 4, 'capacity_max' => 8, 'inclusions' => []],
    ['id' => 'faq-cancellation-test', 'kind' => 'faq', 'question' => 'What is the cancellation policy?', 'answer' => 'Cancellation requests are reviewed by reception. Contact reception to discuss a request.', 'phrases' => ['free cancellation', 'cancellation terms']],
    ['id' => 'faq-breakfast-test', 'kind' => 'faq', 'question' => 'Is breakfast included?', 'answer' => 'Breakfast inclusion depends on the selected package; please confirm it with reception.', 'phrases' => ['breakfast included']],
];
$catalog = array_map(static fn(array $record): array => ['id' => $record['venue_id'], 'category' => $record['category'], 'name' => $record['name'],
    'room_type' => $record['room_type'], 'room_group_id' => $record['room_group_id']], array_filter($records, static fn(array $record): bool => ($record['kind'] ?? null) === 'venue'));
$results = [
    [ 'id' => 'hotel-group-77', 'venue_id' => 101, 'room_group_id' => 77, 'category' => 'Hotel Room', 'title' => 'Rafael Standard Room', 'building_name' => 'Rafael', 'room_type' => 'Standard Room',
        'status' => 'Available', 'availability_checked' => true, 'description' => 'A comfortable standard room.', 'amenities' => ['Wi-Fi'], 'capacity' => '4 guests maximum', 'capacity_value' => 4,
        'beds' => '2 beds', 'beds_value' => 2, 'rate' => '₱1,200.00 /night', 'rate_value' => 1200, 'estimated_total' => 2400,
        'reasons' => [['label' => 'Capacity fit', 'value' => 'fits 3 guests with space for 1 more']]],
    [ 'id' => 'hotel-group-88', 'venue_id' => 303, 'room_group_id' => 88, 'category' => 'Hotel Room', 'title' => 'Kristel Deluxe Room', 'building_name' => 'Kristel', 'room_type' => 'Deluxe Room',
        'status' => 'Available', 'availability_checked' => true, 'description' => 'A deluxe room with extra space.', 'amenities' => ['Wi-Fi', 'Balcony'], 'capacity' => '6 guests maximum', 'capacity_value' => 6,
        'beds' => '2 beds', 'beds_value' => 2, 'rate' => '₱1,650.00 /night', 'rate_value' => 1650, 'estimated_total' => 3300,
        'reasons' => [['label' => 'Capacity fit', 'value' => 'fits 3 guests with space for 3 more']]],
];
$eventResult = ['id' => 'venue-202', 'venue_id' => 202, 'room_group_id' => 0, 'category' => 'Event Hall', 'title' => 'Infinity Hall', 'building_name' => 'Infinity Hall',
    'room_type' => '', 'status' => 'Available', 'availability_checked' => true, 'description' => 'A published event hall.', 'amenities' => ['Stage'],
    'capacity' => '120 guests maximum', 'capacity_value' => 120, 'beds' => '', 'rate' => '₱10,000.00 /day', 'rate_value' => 10000,
    'reasons' => [['label' => 'Capacity fit', 'value' => 'fits your group']]];
$villaResult = ['id' => 'venue-404', 'venue_id' => 404, 'room_group_id' => 0, 'category' => 'Resort Villa', 'title' => 'Lagoon Villa', 'building_name' => 'Lagoon Villa',
    'room_type' => '', 'status' => 'Available', 'availability_checked' => true, 'description' => 'A published resort villa.', 'amenities' => ['Private pool'],
    'capacity' => '8 guests maximum', 'capacity_value' => 8, 'beds' => '', 'rate' => '₱6,500.00 /night', 'rate_value' => 6500,
    'reasons' => [['label' => 'Capacity fit', 'value' => 'fits your group']]];

$hotelSnapshot = static function (array $request) use ($results): array {
    return ['success' => true, 'state' => 'matched', 'intent' => 'Hotel Room', 'guest_range' => $request['guest_range'] ?? null,
        'group_size' => $request['group_size'] ?? null, 'priority' => $request['priority'] ?? 'best_fit', 'check_in' => $request['check_in'] ?? null,
        'check_out' => $request['check_out'] ?? null, 'availability_checked' => isset($request['check_in'], $request['check_out']),
        'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DateTimeInterface::ATOM),
        'pricing_basis' => isset($request['check_in'], $request['check_out']) ? 'full_stay' : 'estimated_nightly',
        'total_matches' => 2, 'results' => $results];
};
$exactHotel = static function (int $venueId, int $groupId, string $start, string $end) use ($results): array {
    foreach ($results as $item) if ($item['venue_id'] === $venueId && $item['room_group_id'] === $groupId) $selected = $item;
    $selected ??= $results[0];
    return ['success' => true, 'state' => 'matched', 'intent' => 'Hotel Room', 'exact_target' => true, 'group_size' => null,
        'check_in' => $start, 'check_out' => $end, 'availability_checked' => true,
        'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DateTimeInterface::ATOM),
        'pricing_basis' => 'availability_only', 'total_matches' => 1, 'results' => [$selected]];
};
$providerCalls = 0;
$replyTypes = ['natural' => 0, 'grounded_fallback' => 0, 'server_answer' => 0, 'local' => 0, 'unavailable' => 0];
$groundingReasons = [];
$turnReplyMetadata = null;
$clock = static fn(): float => microtime(true);
$validateFixtureSlots = static function (array $patch, array $base, array $catalog, array $clear): array {
    $allowed = ['intent', 'occasion', 'purpose', 'group_size', 'preference', 'start_date', 'end_date', 'active_venue_id', 'active_room_group_id'];
    foreach ($clear as $key) if (in_array($key, $allowed, true)) unset($base[$key]);
    foreach ($patch as $key => $value) {
        if (!in_array($key, $allowed, true)) continue;
        if ($value === null || $value === '') { unset($base[$key]); continue; }
        if ($key === 'intent' && !in_array($value, ['Event Hall', 'Hotel Room', 'Resort Villa'], true)) continue;
        if ($key === 'group_size' && (!is_int($value) || $value < 1 || $value > 10000)) continue;
        if ($key === 'preference' && !in_array($value, ['save', 'best_fit', 'comfort'], true)) continue;
        if (in_array($key, ['start_date', 'end_date'], true)) {
            if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value)) continue;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
            if (!$date || $date->format('Y-m-d') !== $value || $date < new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'))) continue;
        }
        $base[$key] = $value;
    }
    return $base;
};
$baseDependencies = static function (?object $provider = null) use (&$providerCalls, &$replyTypes, &$groundingReasons, &$turnReplyMetadata, $records, $catalog, $hotelSnapshot, $exactHotel, $eventResult, $villaResult, $validateFixtureSlots, $clock): array {
    return [
        'state_loader' => static fn(array $raw): array => array_replace(receptionist_natural_empty_state(), $raw),
        'faq_loader' => static fn(): array => [], 'records' => $records,
        'provider_factory' => static function () use ($provider, &$providerCalls): ?object {
            if ($provider instanceof ReceptionistNaturalLiveOutageProvider) return $provider;
            if ($provider !== null) return $provider;
            $actual = receptionist_ai_provider();
            return $actual ? new ReceptionistNaturalLiveProvider($actual, static function () use (&$providerCalls): void { $providerCalls++; }) : null;
        },
        'provider_limits' => receptionist_ai_limits(), 'rate_limit' => static fn(): bool => true,
        'validate_slots' => $validateFixtureSlots, 'fallback' => static fn(): null => null, 'clock' => $clock,
        'hotel_search' => static fn(mysqli $db, array $request): array => $hotelSnapshot($request),
        'hotel_exact_search' => static fn(mysqli $db, int $venue, int $group, string $start, string $end): array => $exactHotel($venue, $group, $start, $end),
        'venue_search' => static function (mysqli $db, string $category, int $groupSize, ?string $start, ?string $end) use ($eventResult, $villaResult): array {
            $item = $category === 'Event Hall' ? $eventResult : $villaResult;
            return ['success' => true, 'state' => 'matched', 'intent' => $category, 'group_size' => $groupSize, 'check_in' => $start,
                'check_out' => $end, 'availability_checked' => $start !== null, 'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DateTimeInterface::ATOM),
                'total_matches' => 1, 'results' => [$item]];
        },
        'venue_exact_search' => static function (mysqli $db, string $category, int $venue, string $start, string $end) use ($eventResult, $villaResult): array {
            $item = $category === 'Event Hall' ? $eventResult : $villaResult;
            return ['success' => true, 'state' => 'matched', 'intent' => $category, 'exact_target' => true, 'guest_count' => null,
                'check_in' => $start, 'check_out' => $end, 'availability_checked' => true, 'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DateTimeInterface::ATOM),
                'total_matches' => 1, 'results' => [$item]];
        },
        'reply_log' => static function (array $metadata) use (&$replyTypes, &$groundingReasons, &$turnReplyMetadata): void {
            $turnReplyMetadata = $metadata;
            $type = (string)($metadata['reply_type'] ?? 'local');
            if (!isset($replyTypes[$type])) $replyTypes[$type] = 0;
            $replyTypes[$type]++;
            $reason = (string)($metadata['grounding_reason'] ?? '');
            if ($reason !== '') $groundingReasons[$reason] = ($groundingReasons[$reason] ?? 0) + 1;
        },
    ];
};

$seedState = static function (array $slots = [], ?string $pending = null, ?array $snapshot = null): array {
    $state = array_replace(receptionist_natural_empty_state(), ['slots' => $slots, 'pending_question' => $pending]);
    if ($snapshot !== null) {
        $snapshot['criteria_signature'] = receptionist_natural_criteria_signature($slots);
        $state['recommendation_snapshot'] = $snapshot;
    }
    return $state;
};
$currentResultsSnapshot = static function (array $slots) use ($results, $dateA, $dateB): array {
    return ['intent' => 'Hotel Room', 'group_size' => $slots['group_size'] ?? null, 'check_in' => $slots['start_date'] ?? $dateA,
        'check_out' => $slots['end_date'] ?? $dateB, 'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DateTimeInterface::ATOM),
        'availability_checked' => true, 'pricing_basis' => 'full_stay', 'total_matches' => 2, 'results' => $results];
};

$cases = [
    ['hotel typo, then bare guest count', ['I need a hotle room', '2']],
    ['mixed multi-slot hotel booking', ["Book a hotel for 3 guests, check in {$dateA}, check out {$dateB}, prioritize comfort."]],
    ['comfort correction only', ['Actually, prioritize comfort over the lowest price.'], $seedState(['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'save', 'start_date' => $dateA, 'end_date' => $dateB])],
    ['guest-count correction keeps all other slots', ['Actually, we are 3 guests.'], $seedState(['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'save', 'start_date' => $dateA, 'end_date' => $dateB])],
    ['named why and bare why', ['Why was Rafael the best fit?', 'Why?'], $seedState(['intent' => 'Hotel Room', 'group_size' => 3, 'preference' => 'best_fit', 'start_date' => $dateA, 'end_date' => $dateB], null, $currentResultsSnapshot(['group_size' => 3, 'start_date' => $dateA, 'end_date' => $dateB]))],
    ['cheaper ordinal and pronoun', ['Is the second option cheaper? I might choose that one.'], $seedState(['intent' => 'Hotel Room', 'group_size' => 3, 'preference' => 'best_fit', 'start_date' => $dateA, 'end_date' => $dateB], null, $currentResultsSnapshot(['group_size' => 3, 'start_date' => $dateA, 'end_date' => $dateB]))],
    ['room details and follow-up', ['How many beds does Rafael Standard Room have?', 'What amenities does it offer?'], $seedState(['intent' => 'Hotel Room', 'group_size' => 3, 'preference' => 'best_fit', 'start_date' => $dateA, 'end_date' => $dateB], null, $currentResultsSnapshot(['group_size' => 3, 'start_date' => $dateA, 'end_date' => $dateB]))],
    ['FAQ detour and return to pending dates', ['What is the cancellation policy?', $dateA, $dateB], $seedState(['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'best_fit'], 'start_date')],
    ['Filipino booking', ["Gusto ko ng hotel para sa 3 bisita, check-in {$dateA} at check-out {$dateB}."], null, 'fil'],
    ['Taglish villa booking', ["Need a villa para sa family, 6 guests, on {$dateC}."], null, 'taglish'],
    ['explicit language request', ['Please reply in Filipino: kasama ba ang breakfast?']],
    ['Infinity Hall exact availability', ["Is Infinity Hall available on {$dateC}?"]],
    ['villa request', ["I need a villa for 5 guests on {$dateC}."]],
    ['ambiguous room availability', ["Is Standard Room available from {$dateA} to {$dateB}?"]],
    ['unknown amenity', ['Does Rafael Standard Room have a private jacuzzi?'], $seedState(['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'best_fit'], null, $currentResultsSnapshot(['group_size' => 2]))],
    ['unsupported price and cancellation policy', ['Is Rafael free to cancel, and will it cost ₱500?'], $seedState(['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'best_fit', 'start_date' => $dateA, 'end_date' => $dateB], null, $currentResultsSnapshot(['group_size' => 2, 'start_date' => $dateA, 'end_date' => $dateB]))],
    ['explicit Hotel availability refresh', ["Check whether Rafael Standard Room is available from {$dateA} to {$dateB}."]],
    ['category switch clears Hotel criteria', ['Switch me to an event hall.'], $seedState(['intent' => 'Hotel Room', 'group_size' => 3, 'preference' => 'comfort', 'start_date' => $dateA, 'end_date' => $dateB, 'active_venue_id' => 101, 'active_room_group_id' => 77], null, $currentResultsSnapshot(['group_size' => 3, 'start_date' => $dateA, 'end_date' => $dateB]))],
    ['invalid past date', ['yesterday'], $seedState(['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'best_fit'], 'start_date')],
    ['stale revision and provider outage recovery', ['Actually make it 3 guests.', 'Does the room include a Jacuzzi?'], $seedState(['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'best_fit'])],
];

$db = new mysqli();
$failed = 0;
$caseNumber = 0;
$selectedCases = 0;
$lastTurnUsedProvider = false;
foreach ($cases as $case) {
    $caseNumber++;
    if ($caseFilter !== null && $caseNumber !== $caseFilter) continue;
    $selectedCases++;
    [$title, $turns, $initialState, $locale] = array_pad($case, 4, null);
    $_SESSION['receptionist_natural_state'] = is_array($initialState) ? $initialState : receptionist_natural_empty_state();
    echo sprintf("CASE %02d %s\n", $caseNumber, $title);
    foreach ($turns as $turnIndex => $message) {
        // Keep live requests paced without spending the controller's 12s
        // per-turn deadline or distorting provider latency measurements.
        if ($lastTurnUsedProvider) sleep(5);
        $callsBeforeTurn = $providerCalls;
        $turnReplyMetadata = null;
        $current = $_SESSION['receptionist_natural_state'];
        $isMock = $caseNumber === 20 && $turnIndex === 1;
        $provider = $isMock ? new ReceptionistNaturalLiveOutageProvider() : null;
        $dependencies = $baseDependencies($provider);
        if ($traceEnabled) $dependencies['proposal_trace'] = static function (string $phase, array $proposal) use ($caseNumber, $turnIndex, $message): void {
            echo '  TRACE ' . json_encode(['case' => $caseNumber, 'turn' => $turnIndex + 1, 'phase' => $phase,
                'input' => $message, 'proposal' => $proposal], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        };
        $expectedRevision = (int)($current['revision'] ?? 0);
        if ($caseNumber === 20 && $turnIndex === 0) $expectedRevision = $expectedRevision > 0 ? $expectedRevision - 1 : 1;
        $language = is_string($locale) ? $locale : receptionist_ai_language('auto', $message);
        [$response, $status] = receptionist_natural_handle_turn($db, ['expected_revision' => $expectedRevision], $message, $language, $catalog,
            sprintf('synthetic_%02d_%d', $caseNumber, $turnIndex + 1), $dependencies);
        if ($status === 409 && $caseNumber === 20 && $turnIndex === 0) {
            $current = $_SESSION['receptionist_natural_state'];
            [$response, $status] = receptionist_natural_handle_turn($db, ['expected_revision' => (int)$current['revision']], $message, $language, $catalog,
                sprintf('synthetic_%02d_%d_retry', $caseNumber, $turnIndex + 1), $dependencies);
        }
        $reply = (string)($response['reply'] ?? '');
        $slotSummary = array_intersect_key(is_array($response['slots'] ?? null) ? $response['slots'] : [], array_flip(['intent', 'group_size', 'preference', 'start_date', 'end_date', 'active_venue_id', 'active_room_group_id']));
        $replyMetadata = is_array($turnReplyMetadata) ? [
            'type' => $turnReplyMetadata['reply_type'] ?? null,
            'grounding' => $turnReplyMetadata['grounding_status'] ?? null,
            'reason' => $turnReplyMetadata['grounding_reason'] ?? null,
            'provider_calls' => $turnReplyMetadata['provider_call_count'] ?? ($providerCalls - $callsBeforeTurn),
        ] : ['type' => null, 'grounding' => null, 'reason' => null, 'provider_calls' => $providerCalls - $callsBeforeTurn];
        echo sprintf("  turn %d status=%d presentation=%s eval=%s reply=%s slots=%s\n", $turnIndex + 1, $status,
            (string)($response['presentation'] ?? ($status === 409 ? 'stale' : 'unknown')), json_encode($replyMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($reply, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($slotSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($status < 200 || $status >= 300) $failed++;
        $lastTurnUsedProvider = $providerCalls > $callsBeforeTurn;
    }
}

echo 'SUMMARY ' . json_encode(['synthetic_conversations' => $selectedCases, 'provider_calls' => $providerCalls, 'reply_types' => $replyTypes, 'grounding_reasons' => $groundingReasons,
    'http_failures' => $failed, 'today_asia_manila' => $today->format('Y-m-d')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
