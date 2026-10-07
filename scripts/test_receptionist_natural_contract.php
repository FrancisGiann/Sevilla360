<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/receptionist_ai.php';
require_once __DIR__ . '/../includes/receptionist_natural.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

final class ReceptionistNaturalContractProvider
{
    public array $calls = [];
    public function __construct(private array $responses) {}
    public function complete(array $messages, int $maxOutputTokens, int $timeoutSeconds): array
    {
        return $this->completeWithSchema($messages, $maxOutputTokens, $timeoutSeconds, [], '', 1);
    }
    public function completeWithSchema(array $messages, int $maxOutputTokens, int $timeoutSeconds, array $schema, string $schemaName = '', ?int $attemptLimit = null): array
    {
        $this->calls[] = ['timeout' => $timeoutSeconds, 'max_tokens' => $maxOutputTokens, 'attempt_limit' => $attemptLimit, 'messages' => $messages];
        $next = array_shift($this->responses);
        if ($next === null) return ['success' => false, 'error_class' => 'provider_unavailable', 'diagnostic' => ['attempt_count' => 1]];
        return is_array($next['payload'] ?? null)
            ? ['success' => true, 'payload' => $next['payload'], 'diagnostic' => ['attempt_count' => 1]]
            : $next;
    }
}

$checks = [];
$assert = static function (string $name, bool $pass) use (&$checks): void { $checks[$name] = $pass; };
$future = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
$date1 = $future->modify('+14 days')->format('Y-m-d');
$date2 = $future->modify('+16 days')->format('Y-m-d');
$date3 = $future->modify('+20 days')->format('Y-m-d');

$hotelRecord = [
    'id' => 'venue-hotel-room-101-group-77', 'kind' => 'venue', 'venue_id' => 101, 'category' => 'Hotel Room',
    'name' => 'Rafael', 'room_type' => 'Standard Room', 'room_group_id' => 77,
    'description' => 'Published hotel room description.', 'amenities' => ['Wi-Fi'], 'base_rate' => 1200,
    'rate_unit' => 'per night', 'capacity_base' => 2, 'capacity_max' => 4,
    'bed_count_min' => 2, 'bed_count_max' => 2, 'inclusions' => [],
];
$eventRecord = [
    'id' => 'venue-event-hall-202', 'kind' => 'venue', 'venue_id' => 202, 'category' => 'Event Hall',
    'name' => 'Infinity Hall', 'room_type' => '', 'room_group_id' => null,
    'description' => 'Published event hall description.', 'amenities' => ['Stage'], 'base_rate' => 10000,
    'rate_unit' => 'per day', 'capacity_base' => 50, 'capacity_max' => 120, 'capacity_styles' => [], 'inclusions' => [],
];
$records = [$hotelRecord, $eventRecord];
$catalog = array_map(static fn(array $record): array => [
    'id' => $record['venue_id'], 'category' => $record['category'], 'name' => $record['name'],
    'room_type' => $record['room_type'], 'room_group_id' => $record['room_group_id'],
], $records);

$slots = static function (array $patch, array $base, array $catalog, array $clear = []): array {
    foreach ($clear as $key) unset($base[$key]);
    foreach ($patch as $key => $value) {
        if ($value === null || $value === '') unset($base[$key]);
        else $base[$key] = $value;
    }
    return $base;
};
$emptyPatch = [
    'intent' => null, 'occasion' => null, 'purpose' => null, 'group_size' => null, 'preference' => null, 'room_type_code' => null,
    'start_date' => null, 'end_date' => null, 'active_venue_id' => null, 'active_room_group_id' => null,
];
$payload = static function (string $kind, array $patch = [], string $reply = '', array $segments = [], ?int $venueId = null, ?int $groupId = null, string $language = 'en', array $claims = []): array {
    return [
        'language' => $language, 'kind' => $kind, 'slots_patch' => $patch, 'clear_slots' => [],
        'knowledge_id' => null, 'knowledge_property' => null, 'target_venue_id' => $venueId,
        'target_room_group_id' => $groupId, 'missing_field' => 'none', 'reply' => $reply, 'reply_segments' => $segments, 'claims' => $claims,
    ];
};
$resultItem = static fn(int $venueId, int $groupId, string $title, string $status = 'Available'): array => [
    'id' => 'hotel-group-' . $groupId, 'venue_id' => $venueId, 'room_group_id' => $groupId,
    'category' => 'Hotel Room', 'title' => $title, 'building_name' => 'Rafael', 'room_type' => 'Standard Room',
    'status' => $status, 'availability_checked' => true, 'description' => 'Published hotel room description.',
    'amenities' => ['Wi-Fi'], 'capacity' => '4 guests maximum', 'capacity_value' => 4,
    'beds' => '2 beds', 'beds_value' => 2, 'rate' => '₱1,200.00 /night', 'rate_value' => 1200,
    'reasons' => [['label' => 'Capacity fit', 'value' => 'fits 3 guests with space for 1 more']],
];
$fixtureSnapshot = static function (array $state, string $start, string $end) use ($resultItem): array {
    return ['success' => true, 'state' => 'matched', 'intent' => 'Hotel Room', 'group_size' => $state['slots']['group_size'] ?? null,
        'check_in' => $start, 'check_out' => $end, 'availability_checked' => true, 'checked_at' => '2026-10-03T12:00:00+08:00',
        'total_matches' => 1, 'results' => [$resultItem(101, 77, 'Rafael Standard Room')]];
};
$testConn = new mysqli();
$legacyHotelSlots = ['intent' => 'Hotel Room', 'group_size' => 2, 'start_date' => $date1, 'end_date' => $date2];
$legacyHotelState = receptionist_natural_state([
    'slots' => $legacyHotelSlots,
    'recommendation_snapshot' => ['intent' => 'Hotel Room', 'criteria_signature' => receptionist_natural_criteria_signature($legacyHotelSlots),
        'results' => [$resultItem(101, 77, 'Rafael Standard Room')]],
], $testConn, $catalog);
$assert('legacy hotel state restores implicit defaults without discarding same-criteria room cards',
    ($legacyHotelState['slots']['preference'] ?? null) === 'best_fit' && ($legacyHotelState['slots']['room_type_code'] ?? null) === 'any'
    && ($legacyHotelState['recommendation_snapshot']['results'][0]['venue_id'] ?? null) === 101
    && ($legacyHotelState['recommendation_snapshot']['criteria_signature'] ?? null) === receptionist_natural_criteria_signature($legacyHotelState['slots']));
$run = static function (array $turnState, string $message, ReceptionistNaturalContractProvider $provider, array $extra = []) use ($testConn, $catalog, $records, $slots): array {
    $_SESSION['receptionist_natural_state'] = $turnState;
    $clockNow = 1000.0;
    $deps = array_replace([
        'state_loader' => static fn(array $raw): array => array_replace(receptionist_natural_empty_state(), $raw),
        'faq_loader' => static fn(): array => [],
        'records' => $records,
        'provider_factory' => static fn() => $provider,
        'provider_limits' => ['timeout' => 30, 'tokens' => 800],
        'rate_limit' => static fn(): bool => true,
        'validate_slots' => $slots,
        'fallback' => static fn(): null => null,
        'clock' => static function () use (&$clockNow): float { $clockNow += 0.01; return $clockNow; },
    ], $extra);
    $expectedRevision = (int)($extra['_expected_revision'] ?? $turnState['revision'] ?? 0);
    unset($deps['_expected_revision']);
    $request = array_replace(['expected_revision' => $expectedRevision], is_array($extra['_request'] ?? null) ? $extra['_request'] : []);
    unset($deps['_request']);
    $language = is_string($extra['_language'] ?? null) ? $extra['_language'] : 'en';
    unset($extra['_language']);
    return receptionist_natural_handle_turn($testConn, $request, $message, $language, $catalog, 'test_request', $deps);
};

$baseState = receptionist_natural_empty_state();
$firstInterpretation = $payload('booking', [
    'intent' => 'Hotel Room', 'group_size' => 3, 'preference' => 'comfort', 'room_type_code' => 'any', 'start_date' => $date1, 'end_date' => $date2,
], 'Sure.');
$wording = $payload('booking', [], 'I found a Rafael Standard Room with enough room for your group.', [], 101, 77, 'en', [
    ['source_id' => 'recommendation-101-77', 'field' => 'name', 'value' => 'Rafael Standard Room'],
    ['source_id' => 'recommendation-101-77', 'field' => 'capacity', 'value' => '4 guests maximum'],
]);
$provider = new ReceptionistNaturalContractProvider([['payload' => $firstInterpretation], ['payload' => $wording]]);
$searchRequest = null;
[$result, $status] = $run($baseState, "Please book any room type for 3 guests, prioritize comfort, check-in {$date1}, checkout {$date2}.", $provider, [
    'hotel_search' => static function (mysqli $db, array $request) use (&$searchRequest, $fixtureSnapshot): array {
        $searchRequest = $request;
        $state = ['slots' => ['group_size' => $request['group_size']]];
        return $fixtureSnapshot($state, (string)$request['check_in'], (string)$request['check_out']);
    },
]);
$assert('full typed booking interprets multiple slots and makes a grounded wording pass', $status === 200
    && ($result['slots']['intent'] ?? null) === 'Hotel Room' && ($result['slots']['group_size'] ?? null) === 3
    && ($result['slots']['preference'] ?? null) === 'comfort' && ($result['slots']['start_date'] ?? null) === $date1
    && ($result['slots']['end_date'] ?? null) === $date2 && ($searchRequest['group_size'] ?? null) === 3
    && ($searchRequest['room_type_code'] ?? null) === 'any'
    && count($provider->calls) === 2 && array_column($provider->calls, 'attempt_limit') === [1, 1]
    && array_column($provider->calls, 'max_tokens') === [1400, 1400]
    && max(array_column($provider->calls, 'timeout')) <= 6 && str_contains((string)$result['reply'], 'Rafael')
    && str_contains((string)$result['reply'], 'enough room for your group') && !str_contains((string)$result['reply'], '4 beds')
    && str_contains($provider->calls[0]['messages'][0]['content'], 'Claimable evidence')
    && str_contains($provider->calls[0]['messages'][0]['content'], '₱1,200.00 per night'));

$seed = array_replace(receptionist_natural_empty_state(), [
    'revision' => 4, 'slots' => ['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'save', 'room_type_code' => 'any', 'start_date' => $date1, 'end_date' => $date2],
    'recommendation_snapshot' => ['intent' => 'Hotel Room', 'criteria_signature' => receptionist_natural_criteria_signature(['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'save', 'room_type_code' => 'any', 'start_date' => $date1, 'end_date' => $date2]),
        'checked_at' => '2026-10-03T12:00:00+08:00', 'results' => [$resultItem(101, 77, 'Rafael Standard Room')]],
]);
$provider = new ReceptionistNaturalContractProvider([['payload' => $payload('booking', ['group_size' => 3], 'Got it.')], ['payload' => $wording]]);
$searchRequest = null;
[$result, $status] = $run($seed, 'Actually, make it 3 guests.', $provider, [
    'hotel_search' => static function (mysqli $db, array $request) use (&$searchRequest, $fixtureSnapshot): array {
        $searchRequest = $request;
        return $fixtureSnapshot(['slots' => ['group_size' => $request['group_size']]], (string)$request['check_in'], (string)$request['check_out']);
    },
]);
$assert('count correction changes only count, retains dates and preference, and invalidates old results', $status === 200
    && ($result['slots']['group_size'] ?? null) === 3 && ($result['slots']['start_date'] ?? null) === $date1
    && ($result['slots']['end_date'] ?? null) === $date2 && ($result['slots']['preference'] ?? null) === 'save'
    && ($searchRequest['guest_range'] ?? null) === '3-4' && ($searchRequest['group_size'] ?? null) === 3
    && count($provider->calls) === 2);

$typeCorrectionState = array_replace(receptionist_natural_empty_state(), [
    'revision' => 9,
    'slots' => ['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'comfort', 'room_type_code' => 'any', 'start_date' => $date1, 'end_date' => $date2],
    'recommendation_snapshot' => ['intent' => 'Hotel Room', 'criteria_signature' => receptionist_natural_criteria_signature([
        'intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'comfort', 'room_type_code' => 'any', 'start_date' => $date1, 'end_date' => $date2,
    ]), 'results' => [$resultItem(101, 77, 'Rafael Standard Room')]],
]);
$typeCorrectionProvider = new ReceptionistNaturalContractProvider([]);
$typeCorrectionSearch = null;
[$typeCorrectionResult, $typeCorrectionStatus] = $run($typeCorrectionState, 'Actually, Dormitory Room instead.', $typeCorrectionProvider, [
    'hotel_search' => static function (mysqli $db, array $request) use (&$typeCorrectionSearch, $fixtureSnapshot, $resultItem): array {
        $typeCorrectionSearch = $request;
        $snapshot = $fixtureSnapshot(['slots' => ['group_size' => $request['group_size']]], (string)$request['check_in'], (string)$request['check_out']);
        $snapshot['results'][0] = array_replace($resultItem(101, 77, 'Rafael Dormitory Room'), ['room_type_code' => 'dormitory_room', 'room_type' => 'Dormitory Room']);
        return $snapshot;
    },
]);
$assert('room type correction keeps guests and dates, filters the re-search, and invalidates the old snapshot', $typeCorrectionStatus === 200
    && ($typeCorrectionResult['slots']['room_type_code'] ?? null) === 'dormitory_room'
    && ($typeCorrectionResult['slots']['group_size'] ?? null) === 2 && ($typeCorrectionResult['slots']['preference'] ?? null) === 'comfort'
    && ($typeCorrectionResult['slots']['start_date'] ?? null) === $date1 && ($typeCorrectionResult['slots']['end_date'] ?? null) === $date2
    && ($typeCorrectionSearch['room_type_code'] ?? null) === 'dormitory_room'
    && ($typeCorrectionResult['recommendation_snapshot']['results'][0]['room_type_code'] ?? null) === 'dormitory_room'
    && $typeCorrectionProvider->calls === []);

$guidedTypeChangeState = array_replace(receptionist_natural_empty_state(), [
    'revision' => 12,
    'slots' => ['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'best_fit', 'room_type_code' => 'dormitory_room', 'start_date' => $date1, 'end_date' => $date2],
    'recommendation_snapshot' => ['intent' => 'Hotel Room', 'criteria_signature' => receptionist_natural_criteria_signature([
        'intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'best_fit', 'room_type_code' => 'dormitory_room', 'start_date' => $date1, 'end_date' => $date2,
    ]), 'results' => [$resultItem(101, 77, 'Rafael Dormitory Room')]],
]);
$guidedTypeChangeProvider = new ReceptionistNaturalContractProvider([]);
$guidedTypeChangeRequest = ['action_id' => 'guided_search_update', 'guided_context' => [
    'intent' => 'Hotel Room', 'groupSizeExact' => 2, 'preference' => 'best_fit', 'roomTypeCode' => null, 'roomTypeRequested' => true,
    'startDate' => $date1, 'endDate' => $date2, 'activeVenueId' => null, 'activeRoomGroupId' => null,
]];
[$guidedTypeChangeResult, $guidedTypeChangeStatus] = $run($guidedTypeChangeState, 'guided search update', $guidedTypeChangeProvider, [
    '_request' => $guidedTypeChangeRequest,
]);
$assert('guided type change clears only the type, retains dates and guests, marks type pending, and invalidates results', $guidedTypeChangeStatus === 200
    && !isset($guidedTypeChangeResult['slots']['room_type_code'])
    && ($guidedTypeChangeResult['slots']['group_size'] ?? null) === 2
    && ($guidedTypeChangeResult['slots']['start_date'] ?? null) === $date1 && ($guidedTypeChangeResult['slots']['end_date'] ?? null) === $date2
    && ($guidedTypeChangeResult['natural_state']['pending_question'] ?? null) === 'room_type_code'
    && ($guidedTypeChangeResult['natural_state']['recommendation_snapshot'] ?? null) === null
    && $guidedTypeChangeProvider->calls === []);

$preferenceState = array_replace(receptionist_natural_empty_state(), ['revision' => 2, 'pending_question' => 'preference',
    'slots' => ['intent' => 'Hotel Room', 'group_size' => 3, 'preference' => 'best_fit', 'room_type_code' => 'dormitory_room', 'start_date' => $date1, 'end_date' => $date2]]);
$preferenceProposal = $payload('booking', ['preference' => 'save'], 'I’ll prioritize lower prices.');
$preferenceWording = $payload('booking', [], 'I’ll prioritize lower prices.');
$preferenceProvider = new ReceptionistNaturalContractProvider([['payload' => $preferenceProposal], ['payload' => $preferenceWording]]);
$preferenceSearches = 0;
$preferenceSearchRequest = null;
[$preferenceResult, $preferenceStatus] = $run($preferenceState, 'lowest price', $preferenceProvider, [
    'hotel_search' => static function (mysqli $db, array $request) use (&$preferenceSearches, &$preferenceSearchRequest, $fixtureSnapshot): array {
        $preferenceSearches++;
        $preferenceSearchRequest = $request;
        return $fixtureSnapshot(['slots' => ['group_size' => $request['group_size']]], (string)$request['check_in'], (string)$request['check_out']);
    },
]);
$assert('priority correction preserves guests, dates, and explicit type, then searches once', $preferenceStatus === 200
    && ($preferenceResult['slots']['preference'] ?? null) === 'save'
    && ($preferenceResult['slots']['start_date'] ?? null) === $date1 && ($preferenceResult['slots']['end_date'] ?? null) === $date2
    && ($preferenceResult['slots']['group_size'] ?? null) === 3 && ($preferenceResult['slots']['room_type_code'] ?? null) === 'dormitory_room'
    && ($preferenceSearchRequest['priority'] ?? null) === 'save' && ($preferenceSearchRequest['room_type_code'] ?? null) === 'dormitory_room'
    && $preferenceSearches === 1 && $preferenceProvider->calls === []);

$hotelAvailability = $payload('availability', ['intent' => 'Hotel Room', 'start_date' => $date1, 'end_date' => $date2]);
$hotelAvailabilityWorded = $payload('availability', [], 'Rafael Standard Room is not available for those dates.', [], 101, 77, 'en', [
    ['source_id' => 'recommendation-101-77', 'field' => 'name', 'value' => 'Rafael Standard Room'],
    ['source_id' => 'recommendation-101-77', 'field' => 'availability', 'value' => 'Unavailable for these dates'],
]);
$provider = new ReceptionistNaturalContractProvider([['payload' => $hotelAvailability], ['payload' => $hotelAvailabilityWorded]]);
$exactHotel = null;
[$result, $status] = $run($baseState, "Is Rafael Standard Room available from {$date1} to {$date2}?", $provider, [
    'hotel_exact_search' => static function (mysqli $db, int $venue, int $group, string $start, string $end) use (&$exactHotel, $resultItem): array {
        $exactHotel = [$venue, $group, $start, $end];
        $item = $resultItem($venue, $group, 'Rafael Standard Room', 'Unavailable for these dates');
        return ['success' => true, 'state' => 'unavailable', 'intent' => 'Hotel Room', 'exact_target' => true, 'group_size' => null,
            'checked_at' => '2026-10-03T12:00:00+08:00', 'check_in' => $start, 'check_out' => $end, 'total_matches' => 0,
            'results' => [$item]];
    },
]);
$assert('named hotel availability uses exact venue and group without requiring party size', $status === 200
    && $exactHotel === [101, 77, $date1, $date2] && !isset($result['slots']['group_size'])
    && ($result['recommendation_snapshot']['exact_target'] ?? false) === true
    && ($result['recommendation_snapshot']['results'][0]['status'] ?? null) === 'Unavailable for these dates'
    && preg_match('/not available|unavailable/i', (string)$result['reply']) === 1);

$eventAvailability = $payload('availability', ['intent' => 'Event Hall', 'start_date' => $date3]);
$provider = new ReceptionistNaturalContractProvider([['payload' => $eventAvailability], ['payload' => $payload('availability', [], 'Infinity Hall is not available on that date.', [], 202, 0, 'en', [
    ['source_id' => 'recommendation-202-0', 'field' => 'name', 'value' => 'Infinity Hall'],
    ['source_id' => 'recommendation-202-0', 'field' => 'availability', 'value' => 'Unavailable for these dates'],
])]]);
$exactVenue = null;
[$result, $status] = $run($baseState, "Is Infinity Hall available on {$date3}?", $provider, [
    'venue_exact_search' => static function (mysqli $db, string $category, int $venue, string $start, string $end) use (&$exactVenue): array {
        $exactVenue = [$category, $venue, $start, $end];
        return ['success' => true, 'intent' => $category, 'state' => 'unavailable', 'exact_target' => true, 'guest_count' => null,
            'checked_at' => '2026-10-03T12:00:00+08:00', 'check_in' => $start, 'check_out' => $end, 'total_matches' => 0,
            'results' => [['venue_id' => $venue, 'room_group_id' => 0, 'category' => $category, 'title' => 'Infinity Hall', 'status' => 'Unavailable for these dates']]];
    },
]);
$assert('named event hall availability refreshes without party size and retains an unavailable target', $status === 200
    && $exactVenue === ['Event Hall', 202, $date3, $date3] && !isset($result['slots']['group_size'])
    && ($result['recommendation_snapshot']['results'][0]['venue_id'] ?? null) === 202);

$availabilityBooking = $payload('booking', ['intent' => 'Hotel Room', 'start_date' => $date1, 'end_date' => $date2],
    'Rafael Standard Room is available for those dates.', [], 101, null, 'en', [
        ['source_id' => 'recommendation-101-77', 'field' => 'availability', 'value' => 'Available'],
    ]);
$availabilityBooking['knowledge_id'] = 'venue-hotel-room-101-group-77';
$availabilityBooking['knowledge_property'] = 'availability';
$availabilityWorded = $payload('booking', [], 'Rafael Standard Room is available for those dates.', [], 101, null, 'en', [
    ['source_id' => 'recommendation-101-77', 'field' => 'availability', 'value' => 'Available'],
]);
$provider = new ReceptionistNaturalContractProvider([['payload' => $availabilityBooking], ['payload' => $availabilityWorded]]);
$availabilitySearch = 0;
[$result, $status] = $run($baseState, "Is Standard Room available from {$date1} to {$date2}?", $provider, [
    'hotel_exact_search' => static function (mysqli $db, int $venue, int $group, string $start, string $end) use (&$availabilitySearch, $resultItem): array {
        $availabilitySearch++;
        return ['success' => true, 'state' => 'matched', 'intent' => 'Hotel Room', 'exact_target' => true,
            'availability_checked' => true, 'check_in' => $start, 'check_out' => $end, 'total_matches' => 1,
            'results' => [$resultItem($venue, $group, 'Rafael Standard Room', 'Available')]];
    },
]);
$assert('availability property routes a booking-classified turn to unique exact room refresh without guest count', $status === 200
    && $availabilitySearch === 1 && count($provider->calls) === 2
    && ($result['slots']['active_room_group_id'] ?? null) === 77
    && ($result['recommendation_snapshot']['exact_target'] ?? false) === true
    && str_contains(strtolower((string)$result['reply']), 'available')
    && array_column($provider->calls, 'max_tokens') === [1400, 1400]);
$unverifiedAvailabilityReason = null;
$unverifiedAvailabilityState = array_replace(receptionist_natural_empty_state(), [
    'slots' => ['intent' => 'Hotel Room', 'group_size' => 2, 'start_date' => $date1, 'end_date' => $date2],
    'recommendation_snapshot' => $fixtureSnapshot(['slots' => ['group_size' => 2]], $date1, $date2),
]);
$assert('availability wording is rejected until this turn completes a checked refresh',
    receptionist_natural_grounded_reply('Rafael Standard Room is available for those dates.', [
        ['source_id' => 'recommendation-101-77', 'field' => 'availability', 'value' => 'Available'],
    ], $records, $unverifiedAvailabilityState, [], 'Is it available?', $unverifiedAvailabilityReason) === null
    && $unverifiedAvailabilityReason === 'availability_not_checked');
$ambiguousHotelRecords = [$hotelRecord, array_replace($hotelRecord, ['id' => 'venue-hotel-room-303-group-88', 'venue_id' => 303, 'name' => 'Kristel', 'room_group_id' => 88])];
$ambiguousRoom = receptionist_natural_named_availability_target($ambiguousHotelRecords, "Is Standard Room available on {$date1}?", []);
$namedRoom = receptionist_natural_named_availability_target($ambiguousHotelRecords, "Is Rafael Standard Room available on {$date1}?", []);
$assert('named availability ignores model-selected ties and resolves exact building plus room type',
    ($ambiguousRoom['ambiguous'] ?? false) === true && ($ambiguousRoom['target'] ?? null) === null
    && ($namedRoom['target']['venue_id'] ?? null) === 101 && ($namedRoom['target']['room_group_id'] ?? null) === 77);

$failedRefreshState = array_replace($seed, ['revision' => 5]);
$failedRefreshProposal = $payload('booking', [], 'Rafael Standard Room is available.', [], 101, null, 'en', [
    ['source_id' => 'recommendation-101-77', 'field' => 'availability', 'value' => 'Available'],
]);
$failedRefreshProposal['knowledge_id'] = 'venue-hotel-room-101-group-77';
$failedRefreshProposal['knowledge_property'] = 'availability';
$provider = new ReceptionistNaturalContractProvider([['payload' => $failedRefreshProposal]]);
[$result, $status] = $run($failedRefreshState, "Is Rafael Standard Room available from {$date1} to {$date2}?", $provider, [
    'hotel_exact_search' => static function (): array { throw new RuntimeException('synthetic refresh failure'); },
]);
$assert('failed explicit availability refresh clears stale checked snapshot but keeps current cards', $status === 200
    && ($result['presentation'] ?? null) === 'keep' && array_key_exists('recommendation_snapshot', $result['natural_state'] ?? [])
    && $result['natural_state']['recommendation_snapshot'] === null
    && !isset($result['recommendation_snapshot']) && str_contains(strtolower((string)$result['reply']), 'couldn’t check')
    && stripos((string)$result['reply'], 'available') === false && count($provider->calls) === 1);

$pending = array_replace(receptionist_natural_empty_state(), ['revision' => 8, 'pending_question' => 'group_size',
    'slots' => ['intent' => 'Hotel Room', 'preference' => 'comfort', 'start_date' => $date1, 'end_date' => $date2]]);
$provider = new ReceptionistNaturalContractProvider([]);
$searchRequest = null;
[$result, $status] = $run($pending, '3', $provider, [
    'hotel_search' => static function (mysqli $db, array $request) use (&$searchRequest, $fixtureSnapshot): array {
        $searchRequest = $request;
        return $fixtureSnapshot(['slots' => ['group_size' => $request['group_size']]], (string)$request['check_in'], (string)$request['check_out']);
    },
]);
$assert('unambiguous pending count stays local, preserves dates, defaults type, and searches once', $status === 200 && $provider->calls === []
    && ($result['slots']['group_size'] ?? null) === 3 && ($result['slots']['start_date'] ?? null) === $date1
    && ($result['slots']['end_date'] ?? null) === $date2 && ($result['slots']['room_type_code'] ?? null) === 'any'
    && ($result['presentation'] ?? null) === 'show_results' && ($searchRequest['room_type_code'] ?? null) === 'any'
    && ($searchRequest['priority'] ?? null) === 'comfort');

$invalid = array_replace(receptionist_natural_empty_state(), ['revision' => 3, 'pending_question' => 'start_date',
    'slots' => ['intent' => 'Event Hall', 'group_size' => 50]]);
$provider = new ReceptionistNaturalContractProvider([]);
[$result, $status] = $run($invalid, 'yesterday', $provider);
$assert('invalid past date keeps current booking state and bypasses provider', $status === 200 && $provider->calls === []
    && !isset($result['slots']['start_date']) && ($result['natural_state']['pending_question'] ?? null) === 'start_date'
    && ($result['presentation'] ?? null) === 'show_dates');

$stale = array_replace(receptionist_natural_empty_state(), ['revision' => 9, 'slots' => ['intent' => 'Hotel Room', 'group_size' => 2]]);
$provider = new ReceptionistNaturalContractProvider([]);
$staleResult = $run($stale, 'Actually, 3 guests.', $provider, ['_expected_revision' => 8]);
$assert('stale revision returns current state without provider call or mutation', $staleResult[1] === 409
    && ($staleResult[0]['revision'] ?? null) === 9 && $provider->calls === []
    && ($_SESSION['receptionist_natural_state']['revision'] ?? null) === 9);

$outageState = array_replace(receptionist_natural_empty_state(), ['revision' => 2, 'slots' => ['intent' => 'Hotel Room', 'group_size' => 2, 'start_date' => $date1]]);
$provider = new ReceptionistNaturalContractProvider([['success' => false, 'error_class' => 'provider_timeout']]);
[$result, $status] = $run($outageState, 'Actually, make it luxury.', $provider);
$assert('broad luxury maps to comfort priority without inventing a room type or calling the provider', $status === 200
    && ($result['slots']['preference'] ?? null) === 'comfort' && ($result['slots']['room_type_code'] ?? null) === 'any'
    && ($result['natural_state']['pending_question'] ?? null) === 'end_date' && $provider->calls === []);

$assert('group count validation requires the proposed number to match the user evidence',
    receptionist_natural_group_count_evidence('we are 3 guests', [], 3)
    && !receptionist_natural_group_count_evidence('we are 3 guests', [], 4)
    && !receptionist_natural_group_count_evidence('the room has 4 beds', ['slots' => ['group_size' => 2]], 4)
    && receptionist_natural_group_count_evidence('actually 3', ['slots' => ['group_size' => 2]], 3));
$assert('mixed check-in and checkout text binds dates by position rather than first-date parsing',
    receptionist_natural_date_evidence("Check-in {$date1}, checkout {$date2}", [], 'start_date', $date1)
    && receptionist_natural_date_evidence("Check-in {$date1}, checkout {$date2}", [], 'end_date', $date2)
    && !receptionist_natural_date_evidence("Check-in {$date1}, checkout {$date2}", [], 'end_date', $date1));

$renderState = ['recommendation_snapshot' => ['intent' => 'Hotel Room', 'results' => [$resultItem(101, 77, 'Rafael Standard Room')]]];
$renderProposal = ['target_venue_id' => 101, 'target_room_group_id' => 77];
$duplicateHotelRecord = array_replace($hotelRecord, ['id' => 'venue-hotel-room-303-group-88', 'venue_id' => 303, 'room_group_id' => 88, 'name' => 'Kristel']);
$faqPolicyRecord = ['id' => 'faq-policy-test', 'kind' => 'faq', 'question' => 'Cancellation policy', 'answer' => 'Cancellation requests are reviewed by reception.'];
$groundRecords = [$hotelRecord, $duplicateHotelRecord, $eventRecord, $faqPolicyRecord];
$groundItem = array_replace($resultItem(101, 77, 'Rafael Standard Room'), ['estimated_total' => 2500]);
$groundOtherItem = array_replace($resultItem(303, 88, 'Kristel Standard Room'), ['rate' => '₱3,300.00 /night', 'rate_value' => 3300]);
$groundState = ['slots' => ['intent' => 'Hotel Room', 'group_size' => 3, 'start_date' => $date1, 'end_date' => $date2],
    'recommendation_snapshot' => ['intent' => 'Hotel Room', 'group_size' => 3, 'check_in' => $date1, 'check_out' => $date2,
        'total_matches' => 3, 'results' => [$groundItem, $groundOtherItem]]];
$groundProposal = ['target_venue_id' => 101, 'target_room_group_id' => 77];
$identityClaims = [
    ['source_id' => 'recommendation-101-77', 'field' => 'name', 'value' => 'Rafael Standard Room'],
];
$capacityClaims = [...$identityClaims,
    ['source_id' => 'recommendation-101-77', 'field' => 'capacity', 'value' => '4 guests maximum'],
    ['source_id' => 'recommendation-101-77', 'field' => 'beds', 'value' => '2 beds'],
];
$summaryClaims = [
    ['source_id' => 'recommendation-summary', 'field' => 'result_count', 'value' => '3'],
    ['source_id' => 'recommendation-summary', 'field' => 'guest_count', 'value' => '3'],
    ['source_id' => 'recommendation-summary', 'field' => 'check_in', 'value' => $date1],
    ['source_id' => 'recommendation-summary', 'field' => 'check_out', 'value' => $date2],
    ['source_id' => 'recommendation-summary', 'field' => 'nights', 'value' => (string)((strtotime($date2) - strtotime($date1)) / 86400)],
    ['source_id' => 'recommendation-101-77', 'field' => 'estimated_total', 'value' => '₱2,500.00 full stay'],
];
$validSegments = [
    ['kind' => 'fact', 'source_id' => 'recommendation-101-77', 'field' => 'name'],
    ['kind' => 'text', 'text' => ' is listed with '],
    ['kind' => 'fact', 'source_id' => 'recommendation-101-77', 'field' => 'capacity'],
];
$assert('factual segments render exact fields and reject semantic swaps or unapproved claims',
    receptionist_natural_render_segments($validSegments, [], $renderState, $renderProposal, 'en') === 'Rafael Standard Room is listed with 4 guests maximum'
    && receptionist_natural_render_segments([['kind' => 'text', 'text' => 'This room has 4 beds']], [], $renderState, $renderProposal, 'en') === null
    && receptionist_natural_render_segments([['kind' => 'fact', 'source_id' => 'recommendation-101-77', 'field' => 'beds']], [], $renderState, ['target_venue_id' => 101, 'target_room_group_id' => 78], 'en') === null
    && receptionist_natural_nonfactual_reply('This room has free cancellation.') === null);
$groundedReply = 'Rafael Standard Room fits 3 guests, has 2 beds, and costs ₱1,200 per night.';
$crossRoomPriceClaims = [...$identityClaims,
    ['source_id' => 'recommendation-303-88', 'field' => 'rate', 'value' => '₱3,300.00 /night'],
];
$freeCancellationClaims = [...$identityClaims,
    ['source_id' => 'faq-policy-test', 'field' => 'answer', 'value' => $faqPolicyRecord['answer']],
];
$breakfastClaims = [...$identityClaims,
    ['source_id' => 'recommendation-101-77', 'field' => 'amenities', 'value' => 'Wi-Fi, Breakfast'],
];
$assert('natural claim accepts exact capacity, bed, and price facts',
    receptionist_natural_grounded_reply($groundedReply, [...$capacityClaims, ['source_id' => 'recommendation-101-77', 'field' => 'rate', 'value' => '₱1,200.00 /night']], $groundRecords, $groundState, $groundProposal, 'Tell me about Rafael.') === $groundedReply);
$assert('natural claim rejects capacity recast as bed count',
    receptionist_natural_grounded_reply('Rafael Standard Room has 4 beds.', [...$identityClaims, ['source_id' => 'recommendation-101-77', 'field' => 'capacity', 'value' => '4 guests maximum']], $groundRecords, $groundState, $groundProposal, 'Tell me about Rafael.') === null);
$assert('natural claim rejects unlisted jacuzzi',
    receptionist_natural_grounded_reply('Rafael Standard Room has a private jacuzzi.', [...$identityClaims, ['source_id' => 'recommendation-101-77', 'field' => 'amenities', 'value' => 'Wi-Fi']], $groundRecords, $groundState, $groundProposal, 'Does it have a jacuzzi?') === null);
$assert('natural claim rejects unsupported free cancellation',
    receptionist_natural_grounded_reply('Free cancellation comes with Rafael Standard Room.', $freeCancellationClaims, $groundRecords, $groundState, $groundProposal, 'What is the cancellation policy?') === null);
$assert('natural claim rejects unlisted breakfast',
    receptionist_natural_grounded_reply('Rafael Standard Room includes free breakfast.', $breakfastClaims, $groundRecords, $groundState, $groundProposal, 'Does it include breakfast?') === null);
$assert('natural claim rejects one room price attached to another room name',
    receptionist_natural_grounded_reply('Rafael Standard Room costs ₱3,300 per night.', $crossRoomPriceClaims, $groundRecords, $groundState, $groundProposal, 'What is Rafael’s price?') === null);
$multiRoomClaims = [
    ['source_id' => 'recommendation-101-77', 'field' => 'name', 'value' => 'Rafael Standard Room'],
    ['source_id' => 'recommendation-101-77', 'field' => 'rate', 'value' => '₱1,200.00 /night'],
    ['source_id' => 'recommendation-303-88', 'field' => 'name', 'value' => 'Kristel Standard Room'],
    ['source_id' => 'recommendation-303-88', 'field' => 'rate', 'value' => '₱3,300.00 /night'],
];
$assert('multi-room comparison can cite exact sources independently of proposal focus',
    receptionist_natural_grounded_reply('Rafael Standard Room is ₱1,200.00 per night, while Kristel Standard Room is ₱3,300.00 per night.',
        $multiRoomClaims, $groundRecords, $groundState, $groundProposal, 'Compare their nightly rates.') !== null);
$catalogKnowledgeProposal = ['knowledge_id' => 'faq-policy-test', 'knowledge_property' => 'amenities', 'target_venue_id' => null, 'target_room_group_id' => null];
$assert('catalog facts bind to exact public room identity even when knowledge classification is wrong',
    receptionist_natural_grounded_reply('Rafael Standard Room has 2 beds.', [
        ['source_id' => 'venue-hotel-room-101-group-77', 'field' => 'bed_count_min', 'value' => '2 beds'],
    ], $groundRecords, $groundState, $catalogKnowledgeProposal, 'How many beds does Rafael Standard Room have?') === 'Rafael Standard Room has 2 beds.'
    && receptionist_natural_grounded_reply('Rafael Standard Room offers Wi-Fi.', [
        ['source_id' => 'venue-hotel-room-101-group-77', 'field' => 'amenities', 'value' => 'Wi-Fi'],
    ], $groundRecords, $groundState, ['knowledge_id' => null, 'knowledge_property' => 'description'], 'What amenities does it offer?') === 'Rafael Standard Room offers Wi-Fi.');
$assert('a mixed factual reply may cite room and FAQ sources independently of one knowledge classifier',
    receptionist_natural_grounded_reply('Rafael Standard Room has 2 beds. Cancellation requests are reviewed by reception.', [
        ['source_id' => 'venue-hotel-room-101-group-77', 'field' => 'bed_count_min', 'value' => '2 beds'],
        ['source_id' => 'faq-policy-test', 'field' => 'answer', 'value' => $faqPolicyRecord['answer']],
    ], $groundRecords, $groundState, ['knowledge_id' => 'faq-policy-test', 'knowledge_property' => 'policy'], 'How many beds, and what is the cancellation policy?') !== null);
$comparisonState = $groundState;
$comparisonState['recommendation_snapshot']['pricing_basis'] = 'full_stay';
$comparisonState['recommendation_snapshot']['results'][0]['estimated_total'] = 2500;
$comparisonState['recommendation_snapshot']['results'][1]['estimated_total'] = 3300;
$comparisonProposal = ['target_venue_id' => 303, 'target_room_group_id' => 88];
$comparisonClaims = [
    ['source_id' => 'recommendation-303-88', 'field' => 'name', 'value' => 'Kristel Standard Room'],
    ['source_id' => 'recommendation-303-88', 'field' => 'estimated_total', 'value' => '₱3,300.00 full stay'],
];
$assert('cheaper comparisons validate against exact full-stay estimates',
    receptionist_natural_grounded_reply('Kristel Standard Room is not cheaper for the stay.', $comparisonClaims, $groundRecords, $comparisonState, $comparisonProposal, 'Is the second option cheaper?') !== null
    && receptionist_natural_grounded_reply('Kristel Standard Room is cheaper for the stay.', $comparisonClaims, $groundRecords, $comparisonState, $comparisonProposal, 'Is the second option cheaper?') === null);
$assert('immediate number units keep guest capacity distinct from bed count',
    receptionist_natural_grounded_reply('Rafael Standard Room fits four guests and has two beds.', [
        ...$identityClaims,
        ['source_id' => 'recommendation-101-77', 'field' => 'capacity', 'value' => '4 guests maximum'],
        ['source_id' => 'recommendation-101-77', 'field' => 'beds', 'value' => '2 beds'],
    ], $groundRecords, $groundState, $groundProposal, 'Tell me about Rafael.') === 'Rafael Standard Room fits four guests and has two beds.');
$whyCapacityOnly = 'Rafael Standard Room fits up to 4 guests.';
$whyReasonClaim = [...$identityClaims, ['source_id' => 'recommendation-101-77', 'field' => 'reason', 'value' => 'fits 3 guests with space for 1 more']];
$assert('why answers require an explained recommendation reason',
    receptionist_natural_grounded_reply($whyCapacityOnly, [...$identityClaims, ['source_id' => 'recommendation-101-77', 'field' => 'capacity', 'value' => '4 guests maximum'], ...$whyReasonClaim], $groundRecords, $groundState, $groundProposal, 'Why was Rafael the best fit?') === null
    && receptionist_natural_grounded_reply('Rafael Standard Room was recommended because it fits 3 guests with space for 1 more.', $whyReasonClaim, $groundRecords, $groundState, $groundProposal, 'Why was Rafael the best fit?') !== null);
$liveWhyClaims = [
    ['source_id' => 'recommendation-101-77', 'field' => 'reason', 'value' => 'fits 3 guests with space for 1 more'],
    ['source_id' => 'recommendation-101-77', 'field' => 'beds', 'value' => '2 beds'],
    ['source_id' => 'recommendation-101-77', 'field' => 'rate', 'value' => '₱1,200.00 /night'],
];
$assert('specific natural explanation binds the room name to exact snapshot claims without redundant target IDs',
    receptionist_natural_grounded_reply('Rafael was recommended because it fits 3 guests with space for 1 more, featuring 2 beds and a rate of ₱1,200.00 /night.',
        $liveWhyClaims, $groundRecords, $groundState, ['target_venue_id' => null, 'target_room_group_id' => null], 'Why was Rafael the best fit?') !== null);
$assert('claimable evidence prompt gives the exact canonical field values used by validation', (static function () use ($groundRecords, $groundState): bool {
    $claimable = receptionist_natural_claimable_evidence($groundRecords, $groundState);
    return ($claimable['venue-hotel-room-101-group-77']['base_rate'] ?? null) === '₱1,200.00 per night'
        && ($claimable['venue-hotel-room-101-group-77']['capacity_max'] ?? null) === 'up to 4 guests'
        && ($claimable['recommendation-101-77']['rate'] ?? null) === '₱1,200.00 /night'
        && ($claimable['recommendation-summary']['guest_count'] ?? null) === '3';
})());
$dateSummaryReply = "I found 3 rooms for 3 guests from {$future->modify('+14 days')->format('M j')}–{$future->modify('+16 days')->format('j')}, with a ₱2,500 stay total for Rafael Standard Room.";
$assert('summary claims support checked counts, exact dates, night count, and full-stay price',
    receptionist_natural_claim_value('recommendation-summary', 'result_count', $groundRecords, $groundState, $groundProposal) === '3'
    && receptionist_natural_claim_value('recommendation-summary', 'guest_count', $groundRecords, $groundState, $groundProposal) === '3'
    && receptionist_natural_claim_value('recommendation-summary', 'nights', $groundRecords, $groundState, $groundProposal) === (string)((strtotime($date2) - strtotime($date1)) / 86400)
    && receptionist_natural_grounded_reply($dateSummaryReply, [...$identityClaims, ...$summaryClaims], $groundRecords, $groundState, $groundProposal, 'Show options for these dates.') === $dateSummaryReply);
$compactRangeState = $groundState;
$compactRangeState['recommendation_snapshot']['total_matches'] = 2;
$compactRangeReply = 'I found two options for your 3 guests from October ' . (int)substr($date1, 8, 2) . ' to ' . (int)substr($date2, 8, 2) . ', ' . substr($date1, 0, 4) . '.';
$compactRangeClaims = [
    ['source_id' => 'recommendation-summary', 'field' => 'check_in', 'value' => $date1],
    ['source_id' => 'recommendation-summary', 'field' => 'check_out', 'value' => $date2],
];
$assert('natural summary verifies option count from snapshot and compact written date ranges',
    receptionist_natural_grounded_reply($compactRangeReply, $compactRangeClaims, $groundRecords, $compactRangeState, [], 'Show me the options.') === $compactRangeReply);
$mixedFactsReply = 'Cancellation requests are reviewed by reception. For Rafael, the rate is ₱1,200.00 /night, so it does not cost ₱500.';
$mixedFactsClaims = [
    ['source_id' => 'faq-policy-test', 'field' => 'answer', 'value' => $faqPolicyRecord['answer']],
    ['source_id' => 'recommendation-101-77', 'field' => 'rate', 'value' => '₱1,200.00 /night'],
];
$assert('a clearly denied user-quoted price can accompany the verified rate and FAQ answer',
    receptionist_natural_grounded_reply($mixedFactsReply, $mixedFactsClaims, $groundRecords, $groundState, [],
        'I thought Rafael cost ₱500, but what is the actual rate and cancellation policy?') === $mixedFactsReply
    && receptionist_natural_grounded_reply('Rafael costs ₱500 per night.', $mixedFactsClaims, $groundRecords, $groundState, [],
        'What is Rafael’s nightly rate?') === null);
$schemaWithSummary = receptionist_natural_provider_schema($groundRecords, $groundState);
$assert('natural schema exposes summary evidence without losing duplicate catalog room labels', in_array('recommendation-summary', $schemaWithSummary['properties']['claims']['items']['properties']['source_id']['enum'], true)
    && in_array('result_count', $schemaWithSummary['properties']['claims']['items']['properties']['field']['enum'], true));
$assert('history retains six complete sanitized pairs within the 6000 character budget', (static function (): bool {
    $history = [];
    for ($i = 0; $i < 8; $i++) { $history[] = ['role' => 'user', 'content' => 'user-' . $i]; $history[] = ['role' => 'assistant', 'content' => 'assistant-' . $i]; }
    $history[] = ['role' => 'assistant', 'content' => 'orphan'];
    $bounded = receptionist_natural_bound_history($history);
    return count($bounded) === 12 && $bounded[0]['content'] === 'user-2' && $bounded[1]['content'] === 'assistant-2'
        && strlen((string)json_encode($bounded)) <= 6000;
})());

$assert('natural follow-up stripping leaves one app-owned question', receptionist_natural_strip_followup_question('Sure. How many guests will be staying?') === 'Sure.');
$assert('natural follow-up stripping removes a declarative duplicate date request but keeps the FAQ answer', receptionist_natural_strip_followup_question(
    'Cancellation requests are reviewed by reception. Let me know your intended check-in date when you are ready.', 'start_date'
) === 'Cancellation requests are reviewed by reception.');
$assert('common transposed hotel spelling is still validated as Hotel Room', receptionist_natural_category_evidence('I need a hotle for my stay.') === 'Hotel Room');

$hotelOpenState = array_replace(receptionist_natural_empty_state(), ['pending_question' => 'intent']);
$hotelOpenProvider = new ReceptionistNaturalContractProvider([['payload' => $payload('booking', ['intent' => 'Hotel Room'], 'Sure. How many guests should I plan for?')]]);
[$hotelOpen, $status] = $run($hotelOpenState, 'I need a hotle.', $hotelOpenProvider);
$hotelCountProvider = new ReceptionistNaturalContractProvider([]);
[$hotelCount, $countStatus] = $run($_SESSION['receptionist_natural_state'], '2', $hotelCountProvider);
$assert('hotel typo opener asks once then a bare count asks optional priority locally with any type', $status === 200 && $countStatus === 200
    && substr_count((string)$hotelOpen['reply'], '?') === 1 && ($hotelOpen['natural_state']['pending_question'] ?? null) === 'group_size'
    && ($hotelCount['slots']['intent'] ?? null) === 'Hotel Room' && ($hotelCount['slots']['group_size'] ?? null) === 2
    && ($hotelCount['slots']['room_type_code'] ?? null) === 'any'
    && ($hotelCount['natural_state']['pending_question'] ?? null) === 'preference' && $hotelCount['presentation'] === 'keep'
    && str_contains((string)$hotelCount['reply'], 'Optional') && str_contains((string)$hotelCount['reply'], 'best fit')
    && $hotelCountProvider->calls === []);

$exactOpenProvider = new ReceptionistNaturalContractProvider([['success' => false, 'error_class' => 'provider_unavailable']]);
[$exactOpen, $exactOpenStatus] = $run(receptionist_natural_empty_state(), 'hotel', $exactOpenProvider);
$exactCountProvider = new ReceptionistNaturalContractProvider([]);
[$exactCount, $exactCountStatus] = $run($_SESSION['receptionist_natural_state'], '2', $exactCountProvider);
$exactPriorityProvider = new ReceptionistNaturalContractProvider([]);
[$exactPriority, $exactPriorityStatus] = $run($_SESSION['receptionist_natural_state'], 'i want a value for money', $exactPriorityProvider);
$assert('provider-unavailable hotel opener keeps intent through count and value-for-money turns', $exactOpenStatus === 200 && $exactCountStatus === 200 && $exactPriorityStatus === 200
    && ($exactOpen['slots']['intent'] ?? null) === 'Hotel Room' && ($exactOpen['natural_state']['pending_question'] ?? null) === 'group_size'
    && ($exactCount['slots']['intent'] ?? null) === 'Hotel Room' && ($exactCount['slots']['group_size'] ?? null) === 2
    && ($exactCount['natural_state']['pending_question'] ?? null) === 'preference'
    && ($exactPriority['slots']['preference'] ?? null) === 'save' && ($exactPriority['slots']['room_type_code'] ?? null) === 'any'
    && ($exactPriority['natural_state']['pending_question'] ?? null) === 'start_date'
    && $exactOpenProvider->calls === [] && $exactCountProvider->calls === [] && $exactPriorityProvider->calls === []);

$oldTypePendingState = array_replace(receptionist_natural_empty_state(), ['revision' => 20, 'pending_question' => 'room_type_code',
    'slots' => ['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'best_fit', 'room_type_code' => 'any']]);
$oldTypePendingProvider = new ReceptionistNaturalContractProvider([]);
[$oldTypePendingResult, $oldTypePendingStatus] = $run($oldTypePendingState, 'i want a value for money', $oldTypePendingProvider);
$assert('old pending room-type session accepts value-for-money locally and advances to dates', $oldTypePendingStatus === 200
    && ($oldTypePendingResult['slots']['preference'] ?? null) === 'save' && ($oldTypePendingResult['slots']['room_type_code'] ?? null) === 'any'
    && ($oldTypePendingResult['natural_state']['pending_question'] ?? null) === 'start_date'
    && ($oldTypePendingResult['missing_slots'][0] ?? null) === 'start_date' && $oldTypePendingProvider->calls === []);

$explicitTypeMenuState = array_replace(receptionist_natural_empty_state(), ['pending_question' => 'preference',
    'slots' => ['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'best_fit', 'room_type_code' => 'any']]);
$explicitTypeMenuProvider = new ReceptionistNaturalContractProvider([]);
[$explicitTypeMenuResult, $explicitTypeMenuStatus] = $run($explicitTypeMenuState, 'choose a room type', $explicitTypeMenuProvider);
$assert('room-type menu appears only after an explicit choose action', $explicitTypeMenuStatus === 200
    && ($explicitTypeMenuResult['natural_state']['pending_question'] ?? null) === 'room_type_code'
    && ($explicitTypeMenuResult['missing_slots'][0] ?? null) === 'room_type_code'
    && count($explicitTypeMenuResult['quick_replies'] ?? []) === 6 && $explicitTypeMenuProvider->calls === []);
$roomTypeState = $_SESSION['receptionist_natural_state'];

$priorityAnswerChecks = [
    ['Best fit', 'best_fit'], ['Lowest price', 'save'], ['Comfort', 'comfort'], ['No preference', 'best_fit'],
    ['skip', 'best_fit'], ['value for money', 'save'], ['bang for my buck', 'save'], ['luxury', 'comfort'], ['premium', 'comfort'],
];
$assert('all priority and skip phrases map to the stable three server values', (static function () use ($priorityAnswerChecks): bool {
    foreach ($priorityAnswerChecks as [$message, $expected]) {
        if (receptionist_natural_preference_answer($message, 'room_type_code') !== $expected) return false;
    }
    return receptionist_natural_preference_answer('Why is this a comfort fit?', 'preference') === null;
})());

$mixedPriorityMessage = "I want Deluxe, lowest price, check-in {$date1}, checkout {$date2}.";
$assert('priority parsing leaves factual questions and mixed booking turns to structured interpretation',
    receptionist_natural_preference_answer('Does that room have comfortable beds?', 'preference') === null
    && receptionist_natural_preference_answer('What is the most comfortable room?', 'preference') === null
    && receptionist_natural_preference_evidence('comfortable beds', 'preference') === null
    && receptionist_natural_preference_evidence($mixedPriorityMessage, 'preference') === 'save'
    && receptionist_natural_local_pending_patch($mixedPriorityMessage, ['pending_question' => 'preference', 'slots' => ['intent' => 'Hotel Room']]) === null);

$factualQuestionState = array_replace(receptionist_natural_empty_state(), ['revision' => 22, 'pending_question' => 'preference',
    'slots' => ['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'best_fit', 'room_type_code' => 'any', 'start_date' => $date1, 'end_date' => $date2]]);
$factualQuestionSearches = 0;
$factualQuestionPass = true;
foreach (['Does that room have comfortable beds?', 'What is the most comfortable room?'] as $question) {
    $questionProvider = new ReceptionistNaturalContractProvider([['payload' => $payload('question', [], 'I do not have a published detail confirming bed comfort.')]]);
    [$questionResult, $questionStatus] = $run($factualQuestionState, $question, $questionProvider, [
        'hotel_search' => static function () use (&$factualQuestionSearches): array { $factualQuestionSearches++; return []; },
    ]);
    $factualQuestionPass = $factualQuestionPass && $questionStatus === 200 && count($questionProvider->calls) === 1
        && ($questionResult['slots']['preference'] ?? null) === 'best_fit'
        && ($questionResult['slots']['room_type_code'] ?? null) === 'any'
        && ($questionResult['slots']['start_date'] ?? null) === $date1 && ($questionResult['slots']['end_date'] ?? null) === $date2
        && ($questionResult['natural_state']['pending_question'] ?? null) === 'preference';
}
$assert('comfortable-bed questions go to the provider and preserve the pending booking without re-searching',
    $factualQuestionPass && $factualQuestionSearches === 0);

$explicitPriorityState = array_replace(receptionist_natural_empty_state(), ['revision' => 23, 'pending_question' => 'group_size',
    'slots' => ['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'best_fit', 'room_type_code' => 'any']]);
$explicitPriorityProvider = new ReceptionistNaturalContractProvider([['payload' => $payload('booking', ['group_size' => 3, 'preference' => 'comfort'], 'Got it.')]]);
[$explicitPriorityResult, $explicitPriorityStatus] = $run($explicitPriorityState, 'Actually, make it 3 guests and prioritize comfort.', $explicitPriorityProvider);
$assert('explicit mixed priority suppresses a duplicate optional priority prompt', $explicitPriorityStatus === 200
    && ($explicitPriorityResult['slots']['group_size'] ?? null) === 3
    && ($explicitPriorityResult['slots']['preference'] ?? null) === 'comfort'
    && ($explicitPriorityResult['natural_state']['pending_question'] ?? null) === 'start_date'
    && $explicitPriorityProvider->calls !== []);

$roomTypeProvider = new ReceptionistNaturalContractProvider([]);
[$roomTypeResult, $roomTypeStatus] = $run($roomTypeState, 'Dormitory Room', $roomTypeProvider);
$assert('published room type answer advances locally without an AI provider call', $roomTypeStatus === 200
    && ($roomTypeResult['slots']['room_type_code'] ?? null) === 'dormitory_room'
    && ($roomTypeResult['natural_state']['pending_question'] ?? null) === 'start_date'
    && $roomTypeProvider->calls === []);

$mixedBooking = $payload('question', ['intent' => 'Hotel Room', 'group_size' => 3, 'preference' => 'comfort', 'room_type_code' => 'any', 'start_date' => $date1, 'end_date' => $date2], 'I can help with that.');
$mixedWording = $payload('booking', [], 'We have the Rafael Standard Room, which fits 3 guests with space for 1 more at ₱1,200.00 /night, and the Kristel Deluxe Room, which fits 3 guests with space for 3 more at ₱1,650.00 /night.', [], null, null, 'en', [
    ['source_id' => 'recommendation-101-77', 'field' => 'name', 'value' => 'Rafael Standard Room'],
    ['source_id' => 'recommendation-101-77', 'field' => 'reason', 'value' => 'fits 3 guests with space for 1 more'],
    ['source_id' => 'recommendation-101-77', 'field' => 'rate', 'value' => '₱1,200.00 /night'],
    ['source_id' => 'recommendation-303-88', 'field' => 'name', 'value' => 'Kristel Deluxe Room'],
    ['source_id' => 'recommendation-303-88', 'field' => 'reason', 'value' => 'fits 3 guests with space for 3 more'],
    ['source_id' => 'recommendation-303-88', 'field' => 'rate', 'value' => '₱1,650.00 /night'],
]);
$mixedProvider = new ReceptionistNaturalContractProvider([['payload' => $mixedBooking], ['payload' => $mixedWording]]);
$mixedSearch = 0;
[$mixedResult, $mixedStatus] = $run(receptionist_natural_empty_state(), "Please find any room type for 3 guests, check-in {$date1}, check-out {$date2}, prioritize comfort, and tell me what options fit.", $mixedProvider, [
    'hotel_search' => static function (mysqli $db, array $request) use (&$mixedSearch, $fixtureSnapshot, $resultItem): array {
        $mixedSearch++;
        $snapshot = $fixtureSnapshot(['slots' => ['group_size' => $request['group_size']]], (string)$request['check_in'], (string)$request['check_out']);
        $second = array_replace($resultItem(303, 88, 'Kristel Deluxe Room'), ['capacity' => '6 guests maximum', 'capacity_value' => 6,
            'beds' => '2 beds', 'beds_value' => 2, 'rate' => '₱1,650.00 /night', 'rate_value' => 1650,
            'reasons' => [['label' => 'Capacity fit', 'value' => 'fits 3 guests with space for 3 more']]]);
        $snapshot['total_matches'] = 2;
        $snapshot['results'][] = $second;
        return $snapshot;
    },
]);
$assert('a mixed question and multi-slot booking still runs one search and presents results', $mixedStatus === 200 && $mixedSearch === 1
    && ($mixedResult['presentation'] ?? null) === 'show_results' && ($mixedResult['recommendation_snapshot']['total_matches'] ?? null) === 2
    && ($mixedResult['slots']['preference'] ?? null) === 'comfort' && count($mixedProvider->calls) === 2
    && str_contains((string)$mixedResult['reply'], 'Rafael Standard Room') && str_contains((string)$mixedResult['reply'], 'Kristel Deluxe Room')
    && str_contains((string)$mixedResult['reply'], '₱1,650.00 /night'));

$defaultMixedProposal = $payload('booking', ['intent' => 'Hotel Room', 'group_size' => 2, 'start_date' => $date1, 'end_date' => $date2], 'I can help with that.');
$defaultMixedProvider = new ReceptionistNaturalContractProvider([['payload' => $defaultMixedProposal], ['payload' => $wording]]);
$defaultMixedSearches = 0;
$defaultMixedRequest = null;
[$defaultMixedResult, $defaultMixedStatus] = $run(receptionist_natural_empty_state(), "Find a hotel room for 2 guests from {$date1} through {$date2}.", $defaultMixedProvider, [
    'hotel_search' => static function (mysqli $db, array $request) use (&$defaultMixedSearches, &$defaultMixedRequest, $fixtureSnapshot): array {
        $defaultMixedSearches++;
        $defaultMixedRequest = $request;
        return $fixtureSnapshot(['slots' => ['group_size' => $request['group_size']]], (string)$request['check_in'], (string)$request['check_out']);
    },
]);
$assert('complete guest and date request searches once with default best fit and any type', $defaultMixedStatus === 200
    && $defaultMixedSearches === 1 && ($defaultMixedRequest['priority'] ?? null) === 'best_fit'
    && ($defaultMixedRequest['room_type_code'] ?? null) === 'any' && count($defaultMixedProvider->calls) === 2
    && ($defaultMixedResult['presentation'] ?? null) === 'show_results');

$mixedTypeState = array_replace(receptionist_natural_empty_state(), ['revision' => 24, 'pending_question' => 'preference',
    'slots' => ['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'best_fit', 'room_type_code' => 'any']]);
$mixedTypeProposal = $payload('booking', ['preference' => 'save', 'room_type_code' => 'deluxe', 'start_date' => $date1, 'end_date' => $date2], 'I’ll check those details.');
$mixedTypeProvider = new ReceptionistNaturalContractProvider([['payload' => $mixedTypeProposal]]);
$mixedTypeRequest = null;
[$mixedTypeResult, $mixedTypeStatus] = $run($mixedTypeState, $mixedPriorityMessage, $mixedTypeProvider, [
    'hotel_search' => static function (mysqli $db, array $request) use (&$mixedTypeRequest): array {
        $mixedTypeRequest = $request;
        return ['success' => true, 'state' => 'no_match', 'intent' => 'Hotel Room', 'check_in' => $request['check_in'],
            'check_out' => $request['check_out'], 'availability_checked' => true, 'checked_at' => '2026-10-03T12:00:00+08:00',
            'total_matches' => 0, 'results' => []];
    },
]);
$assert('mixed exact type, lowest-price priority, and dates reach validation and one filtered search', $mixedTypeStatus === 200
    && count($mixedTypeProvider->calls) === 1
    && ($mixedTypeResult['slots']['room_type_code'] ?? null) === 'deluxe'
    && ($mixedTypeResult['slots']['preference'] ?? null) === 'save'
    && ($mixedTypeResult['slots']['start_date'] ?? null) === $date1 && ($mixedTypeResult['slots']['end_date'] ?? null) === $date2
    && ($mixedTypeRequest['room_type_code'] ?? null) === 'deluxe' && ($mixedTypeRequest['priority'] ?? null) === 'save'
    && ($mixedTypeRequest['check_in'] ?? null) === $date1 && ($mixedTypeRequest['check_out'] ?? null) === $date2);

$mixedFaqMessage = "I want Deluxe at the lowest price for check-in {$date1} and checkout {$date2}; does that include breakfast?";
$mixedFaqProposal = $payload('booking', ['preference' => 'save', 'room_type_code' => 'deluxe', 'start_date' => $date1, 'end_date' => $date2], 'I’ll check those details and the breakfast information.');
$mixedFaqProvider = new ReceptionistNaturalContractProvider([['payload' => $mixedFaqProposal]]);
$mixedFaqRequest = null;
[$mixedFaqResult, $mixedFaqStatus] = $run($mixedTypeState, $mixedFaqMessage, $mixedFaqProvider, [
    'hotel_search' => static function (mysqli $db, array $request) use (&$mixedFaqRequest): array {
        $mixedFaqRequest = $request;
        return ['success' => true, 'state' => 'no_match', 'intent' => 'Hotel Room', 'check_in' => $request['check_in'],
            'check_out' => $request['check_out'], 'availability_checked' => true, 'checked_at' => '2026-10-03T12:00:00+08:00',
            'total_matches' => 0, 'results' => []];
    },
]);
$assert('mixed priority and type survive a trailing factual question for structured interpretation', $mixedFaqStatus === 200
    && count($mixedFaqProvider->calls) === 1 && ($mixedFaqResult['slots']['preference'] ?? null) === 'save'
    && ($mixedFaqResult['slots']['room_type_code'] ?? null) === 'deluxe'
    && ($mixedFaqResult['slots']['start_date'] ?? null) === $date1 && ($mixedFaqResult['slots']['end_date'] ?? null) === $date2
    && ($mixedFaqRequest['priority'] ?? null) === 'save' && ($mixedFaqRequest['room_type_code'] ?? null) === 'deluxe');

$whySnapshot = $seed['recommendation_snapshot'];
$whyState = array_replace(receptionist_natural_empty_state(), ['slots' => $seed['slots'], 'recommendation_snapshot' => $whySnapshot]);
$whyProvider = new ReceptionistNaturalContractProvider([['payload' => $payload('question', [],
    'Rafael Standard Room is a strong fit because it fits 3 guests with space for 1 more.', [], 101, 77, 'en', $whyReasonClaim)]]);
$whySearches = 0;
[$whyFirst, $whyStatus] = $run($whyState, 'Why was Rafael the best fit?', $whyProvider, ['hotel_search' => static function () use (&$whySearches): array { $whySearches++; return []; }]);
$whyFollowupProvider = new ReceptionistNaturalContractProvider([['payload' => $payload('question', [],
    'Rafael Standard Room was recommended because it fits 3 guests with space for 1 more.', [], null, null, 'en', $whyReasonClaim)]]);
[$whySecond, $whySecondStatus] = $run($_SESSION['receptionist_natural_state'], 'Why?', $whyFollowupProvider, ['hotel_search' => static function () use (&$whySearches): array { $whySearches++; return []; }]);
$assert('named and short why retain Rafael focus, explain its reason, and never search again', $whyStatus === 200 && $whySecondStatus === 200
    && str_contains(strtolower((string)$whyFirst['reply']), 'because') && str_contains(strtolower((string)$whySecond['reply']), 'because')
    && ($whySecond['slots']['active_venue_id'] ?? null) === 101 && $whySearches === 0
    && ($whySecond['natural_state']['focused_room']['room_group_id'] ?? null) === 77);

$filipinoDetailsProvider = new ReceptionistNaturalContractProvider([['payload' => $payload('question', [],
    'Rafael Standard Room fits up to 4 guests, has 2 beds, and costs ₱1,200.00 /night.', [], 101, 77, 'en', [
        ['source_id' => 'recommendation-101-77', 'field' => 'name', 'value' => 'Rafael Standard Room'],
        ['source_id' => 'recommendation-101-77', 'field' => 'capacity', 'value' => '4 guests maximum'],
        ['source_id' => 'recommendation-101-77', 'field' => 'beds', 'value' => '2 beds'],
        ['source_id' => 'recommendation-101-77', 'field' => 'rate', 'value' => '₱1,200.00 /night'],
    ])]]);
[$filipinoDetails, $filipinoDetailsStatus] = $run($whyState, 'Tell me more about Rafael Standard Room.', $filipinoDetailsProvider, ['_language' => 'fil']);
$assert('an English model draft receives a concise Filipino evidence fallback when Filipino was requested',
    $filipinoDetailsStatus === 200 && str_contains((string)$filipinoDetails['reply'], 'bisita')
    && str_contains((string)$filipinoDetails['reply'], 'kama')
    && receptionist_natural_reply_matches_language((string)$filipinoDetails['reply'], 'fil'));

$cheapState = array_replace(receptionist_natural_empty_state(), [
    'slots' => ['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'save', 'room_type_code' => 'any', 'start_date' => $date1, 'end_date' => $date2],
    'recommendation_snapshot' => ['intent' => 'Hotel Room', 'pricing_basis' => 'full_stay', 'group_size' => 2, 'check_in' => $date1, 'check_out' => $date2,
        'results' => [array_replace($groundItem, ['estimated_total' => 4000]), array_replace($groundOtherItem, ['estimated_total' => 3300])]],
]);
$cheaperProvider = new ReceptionistNaturalContractProvider([['payload' => $payload('booking', [], 'Kristel Standard Room is a lower-priced option.', [], 303, 88, 'en', [
    ['source_id' => 'recommendation-303-88', 'field' => 'name', 'value' => 'Kristel Standard Room'],
])]]);
$cheaperSearches = 0;
[$cheaperResult, $cheaperStatus] = $run($cheapState, 'Show me the cheaper one.', $cheaperProvider, ['hotel_search' => static function () use (&$cheaperSearches): array { $cheaperSearches++; return []; }]);
$assert('cheaper resolves against the shown full-stay snapshot without changing criteria or searching', $cheaperStatus === 200
    && ($cheaperResult['presentation'] ?? null) === 'show_venue' && ($cheaperResult['slots']['active_venue_id'] ?? null) === 303
    && ($cheaperResult['slots']['group_size'] ?? null) === 2 && ($cheaperResult['slots']['preference'] ?? null) === 'save'
    && $cheaperSearches === 0);

$faq = ['id' => 'faq-policy-test', 'kind' => 'faq', 'question' => 'What is the cancellation policy?', 'answer' => 'Cancellation requests are reviewed by reception. Contact reception to discuss a request.'];
$faqPayload = $payload('question', [], 'Cancellation requests are reviewed by reception. Contact reception to discuss a request.', [], null, null, 'en', [
    ['source_id' => 'faq-policy-test', 'field' => 'answer', 'value' => $faq['answer']],
]);
$faqPayload['knowledge_id'] = 'faq-policy-test';
$faqPayload['knowledge_property'] = 'policy';
$faqState = array_replace(receptionist_natural_empty_state(), ['pending_question' => 'start_date',
    'slots' => ['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'best_fit']]);
$faqProvider = new ReceptionistNaturalContractProvider([['payload' => $faqPayload]]);
[$faqResult, $faqStatus] = $run($faqState, 'What is the cancellation policy?', $faqProvider, ['records' => [...$records, $faq]]);
$assert('FAQ detour answers first and preserves the hotel check-in follow-up', $faqStatus === 200
    && str_starts_with((string)$faqResult['reply'], 'Cancellation requests are reviewed by reception.')
    && substr_count((string)$faqResult['reply'], '?') === 1 && str_contains((string)$faqResult['reply'], 'check in')
    && ($faqResult['natural_state']['pending_question'] ?? null) === 'start_date');

$mixedPriceState = array_replace(receptionist_natural_empty_state(), [
    'slots' => ['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'best_fit', 'room_type_code' => 'any', 'start_date' => $date1, 'end_date' => $date2],
    'recommendation_snapshot' => ['intent' => 'Hotel Room', 'group_size' => 2, 'check_in' => $date1, 'check_out' => $date2,
        'criteria_signature' => receptionist_natural_criteria_signature(['intent' => 'Hotel Room', 'group_size' => 2, 'preference' => 'best_fit', 'room_type_code' => 'any', 'start_date' => $date1, 'end_date' => $date2]),
        'results' => [$resultItem(101, 77, 'Rafael Standard Room')]],
]);
$mixedPricePayload = $payload('question', [], 'Cancellation requests are reviewed by reception. Rafael Standard Room is free to cancel and costs ₱500.', [], 101, 77, 'en', [
    ['source_id' => 'faq-policy-test', 'field' => 'answer', 'value' => $faq['answer']],
    ['source_id' => 'recommendation-101-77', 'field' => 'name', 'value' => 'Rafael Standard Room'],
    ['source_id' => 'recommendation-101-77', 'field' => 'rate', 'value' => '₱1,200.00 /night'],
]);
$mixedPricePayload['knowledge_id'] = 'faq-policy-test';
$mixedPricePayload['knowledge_property'] = 'policy';
$mixedPriceProvider = new ReceptionistNaturalContractProvider([['payload' => $mixedPricePayload], ['payload' => $mixedPricePayload]]);
[$mixedPriceResult, $mixedPriceStatus] = $run($mixedPriceState, 'Is Rafael free to cancel, and will it cost ₱500?', $mixedPriceProvider,
    ['records' => [...$records, $faq]]);
$assert('invalid mixed FAQ and price prose falls back to both verified answers', $mixedPriceStatus === 200
    && str_contains((string)$mixedPriceResult['reply'], 'Cancellation requests are reviewed by reception.')
    && str_contains((string)$mixedPriceResult['reply'], 'Rafael Standard Room')
    && str_contains((string)$mixedPriceResult['reply'], '₱1,200.00 /night')
    && !str_contains((string)$mixedPriceResult['reply'], 'free to cancel')
    && !str_contains((string)$mixedPriceResult['reply'], '₱500'));

$badProvider = new ReceptionistNaturalContractProvider([['payload' => ['kind' => 'not-a-kind', 'reply' => 'DROP ALL SAVED STATE']]]);
$malformedState = array_replace(receptionist_natural_empty_state(), ['revision' => 4, 'slots' => ['intent' => 'Hotel Room', 'group_size' => 2]]);
[$malformedResult, $malformedStatus] = $run($malformedState, 'Tell me about that room.', $badProvider);
$assert('malformed provider output preserves validated slots and uses a safe local fallback', $malformedStatus === 200
    && ($malformedResult['slots']['intent'] ?? null) === 'Hotel Room' && ($malformedResult['slots']['group_size'] ?? null) === 2
    && !str_contains((string)$malformedResult['reply'], 'DROP ALL SAVED STATE') && $badProvider->calls !== []);

$staleGuided = array_replace(receptionist_natural_empty_state(), ['revision' => 6, 'slots' => ['intent' => 'Hotel Room', 'group_size' => 2]]);
$noProvider = new ReceptionistNaturalContractProvider([]);
[$staleGuidedResult, $staleGuidedStatus] = $run($staleGuided, 'guided search update', $noProvider, [
    '_expected_revision' => 5,
    '_request' => ['action_id' => 'guided_search_update', 'guided_context' => ['intent' => 'Event Hall', 'group_size' => 40]],
]);
$assert('stale guided state cannot overwrite the selected category or guest count', $staleGuidedStatus === 409
    && ($staleGuidedResult['natural_state']['slots']['intent'] ?? null) === 'Hotel Room'
    && ($staleGuidedResult['natural_state']['slots']['group_size'] ?? null) === 2 && $noProvider->calls === []);

$guidedStartState = array_replace(receptionist_natural_empty_state(), [
    'slots' => ['intent' => 'Hotel Room', 'group_size' => 3, 'preference' => 'best_fit', 'room_type_code' => 'any'], 'pending_question' => 'start_date',
]);
$guidedStartRequest = ['action_id' => 'guided_search_update', 'guided_context' => [
    'intent' => 'Hotel Room', 'occasion' => null, 'purpose' => null, 'groupSizeExact' => 3, 'preference' => 'best_fit', 'roomTypeCode' => 'any',
    'startDate' => $date1, 'endDate' => null, 'activeVenueId' => null, 'activeRoomGroupId' => null,
]];
$guidedStartProvider = new ReceptionistNaturalContractProvider([]);
[$guidedStartResult, $guidedStartStatus] = $run($guidedStartState, 'guided search update', $guidedStartProvider, ['_request' => $guidedStartRequest]);
$guidedCheckoutRequest = ['action_id' => 'guided_search_update', 'guided_context' => [
    'intent' => 'Hotel Room', 'occasion' => null, 'purpose' => null, 'groupSizeExact' => 3, 'preference' => 'best_fit', 'roomTypeCode' => 'any',
    'startDate' => $date1, 'endDate' => $date2, 'activeVenueId' => null, 'activeRoomGroupId' => null,
]];
$guidedCheckoutProvider = new ReceptionistNaturalContractProvider([]);
$guidedSearches = 0;
[$guidedCheckoutResult, $guidedCheckoutStatus] = $run($_SESSION['receptionist_natural_state'], 'guided search update', $guidedCheckoutProvider, [
    '_request' => $guidedCheckoutRequest,
    'hotel_search' => static function (mysqli $db, array $request) use (&$guidedSearches, $fixtureSnapshot): array {
        $guidedSearches++;
        return $fixtureSnapshot(['slots' => ['group_size' => $request['group_size']]], (string)$request['check_in'], (string)$request['check_out']);
    },
]);
$guidedHistory = $_SESSION['receptionist_natural_state']['history'] ?? [];
$assert('guided dates persist as sanitized user and assistant history pairs and checkout refreshes once', $guidedStartStatus === 200 && $guidedCheckoutStatus === 200
    && ($guidedStartResult['natural_state']['slots']['start_date'] ?? null) === $date1
    && ($guidedStartResult['presentation'] ?? null) === 'show_dates' && ($guidedStartResult['missing_slots'][0] ?? null) === 'end_date'
    && ($guidedStartResult['guided_user_message'] ?? null) === receptionist_natural_guided_history_label([], ['start_date' => $date1])
    && ($guidedCheckoutResult['slots']['start_date'] ?? null) === $date1 && ($guidedCheckoutResult['slots']['end_date'] ?? null) === $date2
    && ($guidedCheckoutResult['recommendation_snapshot']['check_in'] ?? null) === $date1
    && ($guidedCheckoutResult['recommendation_snapshot']['check_out'] ?? null) === $date2
    && count($guidedHistory) >= 4 && $guidedHistory[count($guidedHistory) - 4]['role'] === 'user'
    && str_contains($guidedHistory[count($guidedHistory) - 4]['content'], 'check-in')
    && $guidedHistory[count($guidedHistory) - 3]['role'] === 'assistant'
    && $guidedHistory[count($guidedHistory) - 2]['role'] === 'user'
    && str_contains($guidedHistory[count($guidedHistory) - 2]['content'], 'check-out')
    && $guidedHistory[count($guidedHistory) - 1]['role'] === 'assistant'
    && !str_contains(json_encode($guidedHistory, JSON_UNESCAPED_UNICODE), 'guided search update')
    && $guidedSearches === 1 && $guidedStartProvider->calls === [] && $guidedCheckoutProvider->calls === []);

$clockValue = 1000.0;
$deadlineProvider = new ReceptionistNaturalContractProvider([['payload' => $mixedBooking], ['payload' => $mixedWording]]);
$deadlineSearches = 0;
[$deadlineResult, $deadlineStatus] = $run(receptionist_natural_empty_state(), "Please search any room type for 3 guests from {$date1} to {$date2}.", $deadlineProvider, [
    'clock' => static function () use (&$clockValue): float { $clockValue += $clockValue === 1000.0 ? 0.01 : 13.0; return $clockValue; },
    'hotel_search' => static function (mysqli $db, array $request) use (&$deadlineSearches, $fixtureSnapshot): array {
        $deadlineSearches++;
        return $fixtureSnapshot(['slots' => ['group_size' => $request['group_size']]], (string)$request['check_in'], (string)$request['check_out']);
    },
]);
$assert('an exhausted 12-second deadline skips wording and never makes a third provider call', $deadlineStatus === 200
    && $deadlineSearches === 1 && count($deadlineProvider->calls) === 1 && ($deadlineResult['presentation'] ?? null) === 'show_results');

$failed = array_filter($checks, static fn(bool $pass): bool => !$pass);
foreach ($checks as $name => $pass) echo ($pass ? 'PASS' : 'FAIL') . ' - ' . $name . PHP_EOL;
if ($failed) exit(1);
