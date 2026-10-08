<?php
declare(strict_types=1);

require_once __DIR__ . '/hotel_recommendation_service.php';
require_once __DIR__ . '/venue_recommendation_service.php';

function receptionist_natural_enabled(): bool
{
    $value = $_ENV['RECEPTIONIST_NATURAL_HYBRID_ENABLED'] ?? getenv('RECEPTIONIST_NATURAL_HYBRID_ENABLED');
    return is_string($value) && trim($value) === '1';
}

function receptionist_natural_empty_state(): array
{
    return ['slots' => [], 'pending_question' => null, 'focused_room' => null, 'focused_faq_id' => null,
        'recommendation_snapshot' => null, 'revision' => 0, 'turn_count' => 0, 'provider_turn_count' => 0, 'history' => []];
}

function receptionist_natural_bound_history(array $history): array
{
    // Filter sensitive/invalid items first, then rebuild adjacent complete
    // user+assistant pairs. This prevents filtering from leaving an orphan
    // assistant message that can be mistaken for a response to a new user turn.
    $pairs = [];
    $pendingUser = null;
    foreach (receptionist_ai_public_history($history) as $turn) {
        if (($turn['role'] ?? null) === 'user') {
            $pendingUser = $turn;
            continue;
        }
        if (($turn['role'] ?? null) === 'assistant' && $pendingUser !== null) {
            $pairs[] = [$pendingUser, $turn];
            $pendingUser = null;
        }
    }

    $pairs = array_slice($pairs, -6);
    $characters = static fn(array $pair): int => array_sum(array_map(
        static fn(array $turn): int => strlen((string)($turn['content'] ?? '')),
        $pair
    ));
    $total = array_sum(array_map($characters, $pairs));
    while ($pairs && $total > 6000) $total -= $characters(array_shift($pairs));

    $bounded = [];
    foreach ($pairs as $pair) array_push($bounded, ...$pair);
    return $bounded;
}

function receptionist_natural_append_history(array $history, string $message, string $reply): array
{
    return receptionist_natural_bound_history(receptionist_ai_append_history($history, $message, $reply));
}

function receptionist_natural_state(array $raw, mysqli $conn, array $catalog): array
{
    $state = receptionist_natural_empty_state();
    $state['revision'] = max(0, (int)($raw['revision'] ?? 0));
    $state['turn_count'] = max(0, (int)($raw['turn_count'] ?? 0));
    $state['provider_turn_count'] = max(0, (int)($raw['provider_turn_count'] ?? 0));
    $rawSlots = is_array($raw['slots'] ?? null) ? $raw['slots'] : [];
    $state['slots'] = receptionist_ai_recover_session_slots($conn, $rawSlots, $catalog);
    $legacySlots = $state['slots'];
    if (($state['slots']['intent'] ?? null) === 'Hotel Room') {
        $state['slots']['preference'] ??= 'best_fit';
        $state['slots']['room_type_code'] ??= 'any';
    }
    $pending = $raw['pending_question'] ?? null;
    if (is_string($pending) && in_array($pending, ['intent', 'group_size', 'room_type_code', 'preference', 'occasion', 'purpose', 'start_date', 'end_date'], true)) $state['pending_question'] = $pending;
    if (is_array($raw['focused_room'] ?? null)) {
        $venueId = filter_var($raw['focused_room']['venue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $groupId = filter_var($raw['focused_room']['room_group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($venueId !== false && $groupId !== false && receptionist_ai_catalog_venue($catalog, $venueId, 'Hotel Room', $groupId) !== null) {
            $state['focused_room'] = ['venue_id' => (int)$venueId, 'room_group_id' => (int)$groupId];
        }
    }
    if (is_string($raw['focused_faq_id'] ?? null) && preg_match('/\A[a-zA-Z0-9._:-]{1,100}\z/', $raw['focused_faq_id'])) $state['focused_faq_id'] = $raw['focused_faq_id'];
    $snapshot = $raw['recommendation_snapshot'] ?? null;
    if (is_array($snapshot) && is_array($snapshot['results'] ?? null)) {
        $snapshotCategory = is_string($snapshot['intent'] ?? null) && in_array($snapshot['intent'], ['Hotel Room', 'Event Hall', 'Resort Villa'], true)
            ? $snapshot['intent'] : null;
        $items = [];
        foreach (array_slice($snapshot['results'], 0, 3) as $result) {
            if (!is_array($result)) continue;
            $venueId = filter_var($result['venue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $rawGroupId = $result['room_group_id'] ?? null;
            $groupId = $snapshotCategory === 'Hotel Room'
                ? filter_var($rawGroupId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : (in_array($rawGroupId, [null, 0, '0'], true) ? 0 : false);
            if ($venueId !== false && $groupId !== false && $snapshotCategory !== null
                && receptionist_ai_catalog_venue($catalog, $venueId, $snapshotCategory, $snapshotCategory === 'Hotel Room' ? $groupId : null) !== null) {
                $result['room_group_id'] = (int)$groupId;
                $result['category'] = $snapshotCategory;
                $items[] = $result;
            }
        }
        $state['recommendation_snapshot'] = $snapshot;
        $state['recommendation_snapshot']['results'] = $items;
        $state['recommendation_snapshot']['intent'] = $snapshotCategory;
        $state['recommendation_snapshot']['checked_at'] = is_string($snapshot['checked_at'] ?? null) ? substr($snapshot['checked_at'], 0, 40) : null;
        $state['recommendation_snapshot']['criteria_signature'] = is_string($snapshot['criteria_signature'] ?? null) ? substr($snapshot['criteria_signature'], 0, 200) : '';
        $nearbyDates = [];
        foreach (array_slice(is_array($snapshot['nearby_dates'] ?? null) ? $snapshot['nearby_dates'] : [], 0, 3) as $option) {
            if (!is_array($option)) continue;
            $venueId = filter_var($option['venue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $groupId = filter_var($option['room_group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $typeCode = $option['room_type_code'] ?? null;
            $start = is_string($option['check_in'] ?? null) ? receptionist_ai_canonical_date($option['check_in']) : null;
            $end = is_string($option['check_out'] ?? null) ? receptionist_ai_canonical_date($option['check_out']) : null;
            $catalogRoom = $venueId !== false && $groupId !== false
                ? receptionist_ai_catalog_venue($catalog, $venueId, 'Hotel Room', $groupId) : null;
            if ($start === null || $end === null || $end <= $start || $catalogRoom === null
                || !is_string($typeCode) || hotel_room_type_label($typeCode) === null) continue;
            $requestedStart = is_string($state['slots']['start_date'] ?? null) ? DateTimeImmutable::createFromFormat('!Y-m-d', $state['slots']['start_date']) : false;
            $suggestedStart = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
            $suggestedEnd = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
            if (!$requestedStart || !$suggestedStart || !$suggestedEnd
                || (int)$requestedStart->diff($suggestedStart)->days < 1 || (int)$requestedStart->diff($suggestedStart)->days > 7
                || ($state['slots']['end_date'] ?? null) !== null && (strtotime($end) - strtotime($start)) !== (strtotime((string)$state['slots']['end_date']) - strtotime((string)($state['slots']['start_date'] ?? '')))) continue;
            $nearbyDates[] = ['check_in' => $start, 'check_out' => $end,
                'title' => is_string($option['title'] ?? null) ? substr($option['title'], 0, 200) : '',
                'room_type' => hotel_room_type_label($typeCode), 'room_type_code' => $typeCode,
                'venue_id' => (int)$venueId, 'room_group_id' => (int)$groupId];
        }
        $state['recommendation_snapshot']['nearby_dates'] = $nearbyDates;
        $snapshotSignature = $state['recommendation_snapshot']['criteria_signature'];
        $currentSignature = receptionist_natural_criteria_signature($state['slots']);
        $legacySignature = receptionist_natural_legacy_criteria_signature($legacySlots);
        $rawSignature = receptionist_natural_legacy_criteria_signature($rawSlots);
        if ($snapshotSignature !== '' && (hash_equals($snapshotSignature, $legacySignature) || hash_equals($snapshotSignature, $rawSignature))
            && !hash_equals($snapshotSignature, $currentSignature)) {
            // Older sessions did not persist the implicit hotel defaults.
            // Carry the same-criteria result snapshot forward under the
            // normalized criteria signature.
            $state['recommendation_snapshot']['criteria_signature'] = $currentSignature;
        } elseif ($snapshotSignature === '' || !hash_equals($snapshotSignature, $currentSignature)) {
            $state['recommendation_snapshot'] = null;
        }
    }
    $state['history'] = receptionist_natural_bound_history(is_array($raw['history'] ?? null) ? $raw['history'] : []);
    return $state;
}

function receptionist_natural_criteria_signature(array $slots): string
{
    $criteria = array_intersect_key($slots, array_flip(['intent', 'group_size', 'room_type_code', 'preference', 'start_date', 'end_date']));
    ksort($criteria);
    return hash('sha256', json_encode($criteria, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
}

/** Recognize persisted signatures written before criteria keys were normalized. */
function receptionist_natural_legacy_criteria_signature(array $slots): string
{
    $criteria = array_intersect_key($slots, array_flip(['intent', 'group_size', 'room_type_code', 'preference', 'start_date', 'end_date']));
    return hash('sha256', json_encode($criteria, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
}

function receptionist_natural_snapshot_has_fresh_availability(array $snapshot): bool
{
    if (!empty($snapshot['availability_checked'])) return true;
    foreach ($snapshot['results'] ?? [] as $result) if (is_array($result) && !empty($result['availability_checked'])) return true;
    return false;
}

function receptionist_natural_public_state(array $state): array
{
    return ['revision' => (int)$state['revision'], 'slots' => $state['slots'], 'pending_question' => $state['pending_question'],
        'focused_room' => $state['focused_room'], 'focused_faq_id' => $state['focused_faq_id'],
        'recommendation_snapshot' => $state['recommendation_snapshot']];
}

/** Restore a clicked recommendation only when the server's current snapshot contains it. */
function receptionist_natural_restore_selected_context(array $state, array $request, array $catalog): array
{
    $context = is_array($request['context'] ?? null) ? $request['context'] : [];
    $venueId = filter_var($context['active_venue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $groupId = filter_var($context['active_room_group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($venueId === false || $groupId === false || ($state['slots']['intent'] ?? null) !== 'Hotel Room') return $state;

    $snapshot = $state['recommendation_snapshot'] ?? null;
    if (!is_array($snapshot) || ($snapshot['intent'] ?? null) !== 'Hotel Room') return $state;
    $catalogRoom = receptionist_ai_catalog_venue($catalog, $venueId, 'Hotel Room', $groupId);
    if ($catalogRoom === null) return $state;

    foreach ($snapshot['results'] ?? [] as $result) {
        if (!is_array($result) || (int)($result['venue_id'] ?? 0) !== (int)$venueId
            || (int)($result['room_group_id'] ?? 0) !== (int)$groupId) continue;
        $state['slots']['active_venue_id'] = (int)$catalogRoom['id'];
        $state['slots']['active_room_group_id'] = (int)$catalogRoom['room_group_id'];
        $state['focused_room'] = ['venue_id' => (int)$catalogRoom['id'], 'room_group_id' => (int)$catalogRoom['room_group_id']];
        break;
    }
    return $state;
}

function receptionist_natural_provider_schema(array $records, array $state = []): array
{
    $ids = [];
    foreach ($records as $record) if (is_string($record['id'] ?? null)) $ids[] = $record['id'];
    $sourceIds = $ids;
    foreach ($state['recommendation_snapshot']['results'] ?? [] as $item) {
        if (is_array($item)) $sourceIds[] = 'recommendation-' . (int)($item['venue_id'] ?? 0) . '-' . (int)($item['room_group_id'] ?? 0);
    }
    if (is_array($state['recommendation_snapshot'] ?? null)) $sourceIds[] = 'recommendation-summary';
    $nullableEnum = static fn(array $values): array => ['type' => ['string', 'null'], 'enum' => array_values(array_unique([...$values, null]))];
    $slots = [
        'intent' => $nullableEnum(['Event Hall', 'Hotel Room', 'Resort Villa']),
        'occasion' => $nullableEnum(['wedding', 'celebration', 'corporate', 'other']),
        'purpose' => $nullableEnum(['relaxation', 'family', 'private']),
        'group_size' => ['type' => ['integer', 'null']],
        'preference' => $nullableEnum(['save', 'best_fit', 'comfort']),
        'room_type_code' => $nullableEnum([...array_keys(hotel_fixed_room_types()), 'any']),
        'start_date' => ['type' => ['string', 'null']], 'end_date' => ['type' => ['string', 'null']],
        'active_venue_id' => ['type' => ['integer', 'null']], 'active_room_group_id' => ['type' => ['integer', 'null']],
    ];
    return [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['language', 'kind', 'slots_patch', 'clear_slots', 'knowledge_id', 'knowledge_property', 'target_venue_id', 'target_room_group_id', 'missing_field', 'reply', 'claims'],
        'properties' => [
            'language' => ['type' => 'string', 'enum' => ['en', 'fil', 'taglish']],
            'kind' => ['type' => 'string', 'enum' => ['booking', 'question', 'availability', 'reference', 'social', 'ambiguous']],
            'slots_patch' => ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($slots), 'properties' => $slots],
        'clear_slots' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys($slots)], 'maxItems' => 10],
            'knowledge_id' => $nullableEnum($ids),
            'knowledge_property' => $nullableEnum(['price', 'overnight_price', 'capacity', 'amenities', 'description', 'faq_answer', 'policy', 'contact', 'location', 'event_options', 'availability', 'unknown']),
            'target_venue_id' => ['type' => ['integer', 'null']], 'target_room_group_id' => ['type' => ['integer', 'null']],
            'missing_field' => ['type' => 'string', 'enum' => ['intent', 'group_size', 'room_type_code', 'preference', 'occasion', 'purpose', 'start_date', 'end_date', 'none']],
            'reply' => ['type' => 'string', 'maxLength' => 600],
            'claims' => ['type' => 'array', 'maxItems' => 12, 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['source_id', 'field', 'value'],
                'properties' => [
                    'source_id' => ['type' => 'string', 'enum' => array_values(array_unique($sourceIds))],
                    'field' => ['type' => 'string', 'enum' => ['name', 'room_type', 'base_rate', 'overnight_rate', 'capacity_base', 'capacity_max', 'capacity_styles', 'bed_count_min', 'bed_count_max', 'amenities', 'inclusions', 'description', 'question', 'answer', 'text', 'capacity', 'beds', 'rate', 'estimated_total', 'reason', 'availability', 'result_count', 'guest_count', 'check_in', 'check_out', 'nights']],
                    'value' => ['type' => 'string', 'maxLength' => 500],
                ],
            ]],
        ],
    ];
}

function receptionist_natural_connectors(): array
{
    return [
        '', 'I found ', 'For your group, ', 'For your dates, ', 'The listing shows ', ' — ', ': ', ', ', ' and ', '. ', '; ', ' (', ') ',
        ' is listed as ', ' is listed with ', ' has ', ' includes ', ' fits ', ' accommodates ',
        ' starts at ', ' is priced at ', ' per night: ', ' based on the last search: ',
        ' from the last search: ', ' according to the published listing: ', ' was recommended because ',
        ' at ', ' may ', ' kasama ang ', ' akma para sa ', ' kasya ang ', ' ayon sa listing: ',
        ' batay sa huling search: ', ' lumabas dahil ', ' mula sa huling search: ',
        ' based sa last search: ', ' according sa published listing: ', ' dahil ',
        ' Nakita ko ang ', ' Para sa grupo ninyo, ', ' Para sa dates ninyo, ', ' Nasa listing ang ',
        ' Para sa group mo, ', ' Para sa dates mo, ', ' Nakalista ang ',
    ];
}

function receptionist_natural_system_prompt(array $state, array $records, string $language): string
{
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $snapshot = $state['recommendation_snapshot'];
    if (is_array($snapshot)) {
        $snapshot = ['intent' => $snapshot['intent'] ?? null, 'state' => $snapshot['state'] ?? null, 'exact_target' => $snapshot['exact_target'] ?? false,
            'total_matches' => $snapshot['total_matches'] ?? null, 'group_size' => $snapshot['group_size'] ?? ($snapshot['guest_count'] ?? null),
            'pricing_basis' => $snapshot['pricing_basis'] ?? null, 'criteria_signature' => $snapshot['criteria_signature'] ?? null, 'checked_at' => $snapshot['checked_at'] ?? null,
            'check_in' => $snapshot['check_in'] ?? null, 'check_out' => $snapshot['check_out'] ?? null,
            'results' => array_map(static fn(array $item): array => array_intersect_key($item, array_flip(['venue_id', 'room_group_id', 'category', 'title', 'building_name', 'room_type', 'rate', 'rate_value', 'estimated_total', 'estimated_nightly_amount', 'capacity', 'capacity_value', 'beds', 'reasons', 'amenities', 'description', 'status', 'availability_checked'])), $snapshot['results'] ?? [])];
    }
    $evidence = receptionist_knowledge_candidate_projection(array_slice($records, 0, 80));
    $claimable = receptionist_natural_claimable_evidence($records, $state);
    return "You are the warm, concise virtual receptionist for M.I. Sevilla Resort & Events Place. Today is {$today} in Asia/Manila; resolve relative dates from that date and reject dates in the past. Answer naturally in {$language}; honor explicit language requests. If the requested language is fil, write natural Filipino; if taglish, use natural Filipino-English Taglish. Classify the turn and propose only partial slot changes supported by the user's meaning. Preserve omitted slots; clear dependent fields only for a clear category switch or correction. For Hotel Room searches, guest count is required, room_type_code defaults to any and is an independent optional filter, and preference defaults to best_fit. After a guest count without dates, the app may ask one brief optional priority question (best fit, lowest price, or comfort; no preference means best_fit), then ask for dates. If a request already includes guest count and dates, search directly with best_fit when no priority was stated. Ask about exact room type only after an explicit choose/change type request or a genuine clarification; use only the five published labels or any. Map value for money and bang for my buck to lowest-price priority; broad luxury, premium, upscale, high-end, or comfort means comfort priority and never implies a room type. A room type choice never changes priority. 'Why is Rafael a best fit?' asks for a reason and must not change priority or trigger a new search. Occasion and purpose are optional unless a real handoff requires them. Use the last six complete turns, pending field, validated slots, and shown snapshot to understand references, cheaper options, and follow-ups. Resolve a room to its exact venue and room-group identity; if ambiguous, ask which one. Answer questions and interruptions first, then let the app ask at most one necessary booking follow-up; do not ask for any booking detail yourself. For mixed booking and factual requests, return both the supported slot patch and the answer, and classify the turn as booking when it starts or changes a search. Speak like a helpful receptionist in one to three short sentences. Vary wording to fit the request; do not sound like a menu, repeat a template, or add a routine search disclaimer. When asked why a room was recommended, state its actual recommendation reason before adding capacity or price. When discussing capacity and beds, keep those facts distinct. Use approved FAQs and published venue data only. For each factual claim in reply, include a claims entry using the exact source_id, field, and value from the claimable-evidence table below; values are preformatted for claim validation. Claim values do not constrain your wording, only verify the facts. Do not invent price, capacity, beds, amenities, dates, availability, inclusions, or policy. If evidence does not answer the question, say what is unconfirmed and offer a relevant next step. Return only the JSON schema.\nValidated state: " . json_encode(['slots' => $state['slots'], 'pending_question' => $state['pending_question'], 'focused_room' => $state['focused_room'], 'focused_faq_id' => $state['focused_faq_id'], 'recommendations' => $snapshot], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\nPublished evidence: " . json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\nClaimable evidence (copy exact source_id, field, value): " . json_encode($claimable, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\nRecent sanitized turns: " . json_encode($state['history'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function receptionist_natural_local_reply(string $key, string $language, ?string $intent = null): string
{
    $datePrompts = match ($intent) {
        'Event Hall' => ['en' => 'What date is your event?', 'fil' => 'Anong petsa ang event ninyo?', 'taglish' => 'Anong date ang event ninyo?'],
        'Resort Villa' => ['en' => 'What date would you like to visit?', 'fil' => 'Anong petsa ninyo gustong pumunta?', 'taglish' => 'Anong date ninyo gustong pumunta?'],
        default => ['en' => 'What date would you like to check in?', 'fil' => 'Anong petsa ang check-in ninyo?', 'taglish' => 'Anong date ang check-in ninyo?'],
    };
    $copy = [
        'intent' => ['en' => 'What would you like to explore: an event hall, hotel room, or resort villa?', 'fil' => 'Ano ang gusto mong tingnan: event hall, hotel room, o resort villa?', 'taglish' => 'Ano ang gusto mong i-explore: event hall, hotel room, or resort villa?'],
        'group_size' => ['en' => 'How many guests should I plan for?', 'fil' => 'Ilang bisita ang isasama ko sa paghahanap?', 'taglish' => 'Ilang guests ang isasama ko sa search?'],
        'room_type_code' => ['en' => 'Which published hotel room type would you like to filter for: Standard Room, Dormitory Room, Family Room / Superior, Deluxe, VIP Suite, or any room type?', 'fil' => 'Aling published hotel room type ang gusto mong gawing filter: Standard Room, Dormitory Room, Family Room / Superior, Deluxe, VIP Suite, o anumang uri?', 'taglish' => 'Aling published hotel room type ang gusto mong i-filter: Standard Room, Dormitory Room, Family Room / Superior, Deluxe, VIP Suite, or any room type?'],
        'preference' => ['en' => 'Optional: what matters most—best fit, lowest price, or comfort? Choose “No preference” to use best fit.', 'fil' => 'Opsyonal: ano ang mahalaga—best fit, pinakamababang presyo, o comfort? Piliin ang “No preference” para best fit ang default.', 'taglish' => 'Optional: ano ang priority—best fit, lowest price, or comfort? Piliin ang “No preference” para best fit ang default.'],
        'start_date' => $datePrompts,
        'end_date' => ['en' => 'What checkout date should I use?', 'fil' => 'Anong petsa ang checkout ninyo?', 'taglish' => 'Anong date ang checkout ninyo?'],
        'unknown' => ['en' => 'I don’t have a confirmed published detail for that. I can help with rooms, venues, amenities, rates, or booking policies.', 'fil' => 'Wala akong kumpirmadong published detail tungkol diyan. Matutulungan kita sa rooms, venues, amenities, rates, o booking policies.', 'taglish' => 'Wala akong confirmed published detail tungkol diyan. I can help with rooms, venues, amenities, rates, or booking policies.'],
        'stale' => ['en' => 'Your conversation has been refreshed. Please send that again so I can use the latest details.', 'fil' => 'Na-refresh ang usapan natin. Pakisend ulit para magamit ko ang pinakabagong detalye.', 'taglish' => 'Na-refresh ang usapan natin. Please send that again para latest details ang magamit ko.'],
        'outage' => ['en' => 'I’m having trouble understanding that just now. Your saved details are still here. You can rephrase it or choose a venue below.', 'fil' => 'Nagkaproblema ako sa pag-intindi ngayon, pero nandito pa rin ang mga detalye mo. Maaari mo itong sabihin muli o pumili ng venue sa ibaba.', 'taglish' => 'Nagka-issue ako sa pag-intindi ngayon, pero nandito pa rin details mo. Rephrase mo lang o pumili ng venue sa ibaba.'],
        'invalid_date' => ['en' => 'That date is in the past or invalid. Please choose a valid date from today onward.', 'fil' => 'Past o invalid ang petsang iyon. Pumili ng valid na petsa mula ngayon pataas.', 'taglish' => 'Past or invalid ang date na iyon. Pumili ng valid date from today onward.'],
        'search_error' => ['en' => 'I couldn’t check availability just now. Your dates are still saved; you can try again or choose another date.', 'fil' => 'Hindi ko ma-check ang availability ngayon. Naka-save pa rin ang dates mo; subukan ulit o pumili ng ibang petsa.', 'taglish' => 'Hindi ko ma-check ang availability ngayon. Naka-save pa rin dates mo; try ulit o pumili ng ibang date.'],
    ];
    return $copy[$key][$language] ?? $copy[$key]['en'] ?? '';
}

function receptionist_natural_category(string $message): ?string
{
    if (preg_match('/\b(?:hotel|room|stay|accommodat|check[ -]?in|dorm|suite|kwarto|silid|tulugan|kuwarto)\b/i', $message)) return 'Hotel Room';
    if (preg_match('/\b(?:villa|pool villa|swimming pool|bilya)\b/i', $message)) return 'Resort Villa';
    if (preg_match('/\b(?:event hall|event venue|function hall|infinity hall|abelardo hall|wedding venue|event place|bulwagan|venue ng kasal)\b/i', $message)) return 'Event Hall';
    return null;
}

function receptionist_natural_hotel_start_message(string $message): bool
{
    // Short, unambiguous category starts should not depend on a model to save
    // the selected intent before asking for the next required slot.
    return preg_match('/\A\s*(?:please\s+)?(?:(?:i\s+(?:want|need|would like)(?:\s+to book)?)|(?:i[’\']?m\s+looking for)|(?:looking for)|(?:find(?:\s+me)?)|(?:show me)|(?:book))?\s+(?:a\s+)?hotel(?:\s+room)?s?\s*(?:please)?[.!]?\s*\z/i', $message) === 1
        || preg_match('/\A\s*hotel(?:\s+room)?s?\s*(?:please)?[.!]?\s*\z/i', $message) === 1;
}

function receptionist_natural_parse_preference(string $message): ?string
{
    if (preg_match('/\b(?:comfortable|comfort|luxur(?:y|ious)|premium|upscale|high[- ]end|higher room category)\b/i', $message)) return 'comfort';
    if (preg_match('/\b(?:value for money|bang for (?:my|our) buck|good value|best value|cheapest|lowest price|budget|save|affordable|inexpensive)\b/i', $message)) return 'save';
    if (preg_match('/\b(?:best fit|best[- ]fit|balanced|most suitable|no preference|skip(?: this)?|doesn[’\']?t matter|either is fine)\b/i', $message)) return 'best_fit';
    return null;
}

function receptionist_natural_preference_answer(string $message, ?string $pending = null): ?string
{
    $text = mb_strtolower(trim($message), 'UTF-8');
    if ($text === '' || str_contains($text, '?')
        || preg_match('/\A(?:what|which|who|where|when|why|how|does|do|is|are|can|could|would|will|did|have|has|tell me|explain)\b/u', $text) === 1
        || preg_match('/\b(?:why|reason|because|explain|wonder|want to know)\b/u', $text) === 1) return null;

    $choice = preg_replace('/[.!]+$/u', '', $text) ?? $text;
    $choice = preg_replace('/^(?:(?:actually|instead|please|okay|ok)[,:]?\s*)+/u', '', $choice) ?? $choice;
    $choice = preg_replace('/^(?:(?:i|we)\s+(?:want|need|prefer|would like)(?:\s+to\s+(?:prioritize|focus on))?|(?:please\s+)?prioriti[sz]e|(?:my|our)\s+(?:priority|preference)(?:\s+is)?|(?:make it|go with|focus on))\s+/u', '', $choice) ?? $choice;
    $choice = preg_replace('/^(?:a|the)\s+/u', '', $choice) ?? $choice;
    $choice = preg_replace('/[,;]?\s*please$/u', '', $choice) ?? $choice;
    $choice = trim(preg_replace('/\s+/u', ' ', $choice) ?? $choice, " \t\n\r\0\x0B,.;:!");
    $choices = [
        'best fit', 'best-fit', 'balanced', 'most suitable', 'no preference', 'skip', 'skip this', "doesn't matter", 'either is fine',
        'lowest price', 'value for money', 'bang for my buck', 'bang for our buck', 'good value', 'best value', 'cheapest', 'budget', 'save', 'affordable', 'inexpensive',
        'comfort', 'comfortable', 'luxury', 'luxurious', 'premium', 'upscale', 'high-end', 'higher room category',
    ];
    if (!in_array($choice, $choices, true)) return null;
    return receptionist_natural_parse_preference($choice);
}

function receptionist_natural_preference_evidence(string $message, ?string $pending = null): ?string
{
    if (($answer = receptionist_natural_preference_answer($message, $pending)) !== null) return $answer;
    $text = trim($message);
    if ($text === '') return null;
    // For a mixed request, only use the explicit preference clause before a
    // factual question. The question itself may mention comfort or value but
    // must not override the user's stated booking priority.
    $questionPosition = strpos($text, '?');
    $evidenceText = $questionPosition === false ? $text : trim(substr($text, 0, $questionPosition));
    if ($evidenceText === ''
        || preg_match('/\A(?:what|which|who|where|when|why|how|does|do|is|are|can|could|would|will|did|have|has|tell me|explain)\b/i', $evidenceText) === 1
        || preg_match('/\b(?:why|reason|because|explain|wonder|want to know)\b/i', $evidenceText) === 1) return null;
    $value = receptionist_natural_parse_preference($evidenceText);
    if ($value === null) return null;
    // Mixed booking turns may contain type, count, and date details. Accept a
    // preference from model output only when the user explicitly frames it as
    // a choice or priority, never from a descriptive adjective alone.
    return preg_match('/\b(?:want|need|prefer|prioriti[sz]e|priority|preference|focus on|go with|make it)\b/i', $evidenceText) === 1
        ? $value : null;
}

function receptionist_natural_room_type_menu_requested(string $message): bool
{
    return preg_match('/\b(?:which|what)\s+(?:(?:published|hotel)\s+)?room\s+types?\b|\b(?:choose|select|change|switch|another)\s+(?:a\s+|the\s+)?room\s+type\b|\broom\s+types?\s+(?:are available|can i choose|should i choose)\b/i', $message) === 1;
}

/** Resolve only explicit labels in the published fixed room taxonomy. */
function receptionist_natural_parse_room_type_choice(string $message): ?string
{
    $matches = [];
    $rules = [
        'standard_room' => '/\b(?:standard(?:\s+room)?|regular\s+room)\b/i',
        'dormitory_room' => '/\b(?:dormitory(?:\s+room)?|dorm)\b/i',
        'family_room_superior' => '/\b(?:family\s+room(?:\s*\/\s*superior|\s+superior)?|superior\s+room)\b/i',
        'deluxe' => '/\bdeluxe\b/i',
        'vip_suite' => '/\b(?:vip\s+suite|vip)\b/i',
        'any' => '/\b(?:any(?:\s+room(?:\s+type)?)?|no\s+preference|doesn[’\']?t\s+matter|either)\b/i',
    ];
    foreach ($rules as $code => $pattern) if (preg_match($pattern, $message)) $matches[] = $code;
    $matches = array_values(array_unique($matches));
    return count($matches) === 1 ? $matches[0] : null;
}

function receptionist_natural_local_room_type_answer(string $message): ?string
{
    $text = mb_strtolower(trim($message), 'UTF-8');
    if ($text === '' || str_contains($text, '?')
        || preg_match('/\A(?:what|which|who|where|when|why|how|does|do|is|are|can|could|would|will|did|have|has|tell me)\b/u', $text) === 1) return null;
    $choice = preg_replace('/[.!]+$/u', '', $text) ?? $text;
    $choice = preg_replace('/^(?:(?:actually|instead|please|okay|ok)[,:]?\s*)+/u', '', $choice) ?? $choice;
    $choice = preg_replace('/^(?:(?:i|we)\s+(?:want|need|prefer|would like)|(?:choose|select|switch|change)(?:\s+to)?|(?:go with|make it))\s+/u', '', $choice) ?? $choice;
    $choice = preg_replace('/\s+(?:instead|please)$/u', '', $choice) ?? $choice;
    $choice = trim(preg_replace('/\s+/u', ' ', $choice) ?? $choice, " \t\n\r\0\x0B,.;:!");
    $choices = [
        'standard', 'standard room', 'regular room', 'dorm', 'dormitory', 'dormitory room',
        'family room', 'family room superior', 'family room / superior', 'superior room', 'deluxe', 'vip', 'vip suite',
        'any', 'any room', 'any room type', 'no preference', 'either', "doesn't matter",
    ];
    return in_array($choice, $choices, true) ? receptionist_natural_parse_room_type_choice($choice) : null;
}

function receptionist_natural_room_type_clarification_requested(string $message): bool
{
    return preg_match('/\b(?:luxury|luxurious|premium|upscale|high[- ]end)\b/i', $message) === 1;
}

function receptionist_natural_local_pending_patch(string $message, array $state): ?array
{
    $pending = $state['pending_question'] ?? null;
    $text = trim($message);
    if (($state['slots']['intent'] ?? null) !== 'Hotel Room' && receptionist_natural_hotel_start_message($text)) {
        return ['slots_patch' => ['intent' => 'Hotel Room'], 'clear_slots' => [], 'kind' => 'booking'];
    }
    if ($pending === 'group_size' && preg_match('/\A(?:actually\s+)?(?:we are |there are |for )?(\d{1,4})(?:\s+(?:people|persons|guests|guest|pax))?[.!]?\z/i', $text, $match)) {
        return ['slots_patch' => ['group_size' => (int)$match[1]], 'clear_slots' => [], 'kind' => 'booking'];
    }
    if ($pending === 'group_size' && preg_match('/\A(dalawa|tatlo|apat|lima|anim|pito|walo|siyam|sampu)[.!]?\z/i', $text, $match)) {
        $numbers = ['dalawa' => 2, 'tatlo' => 3, 'apat' => 4, 'lima' => 5, 'anim' => 6, 'pito' => 7, 'walo' => 8, 'siyam' => 9, 'sampu' => 10];
        return ['slots_patch' => ['group_size' => $numbers[strtolower($match[1])]], 'clear_slots' => [], 'kind' => 'booking'];
    }
    $preference = receptionist_natural_preference_answer($text, is_string($pending) ? $pending : null);
    if (($state['slots']['intent'] ?? null) === 'Hotel Room' && $preference !== null) {
        $patch = ['preference' => $preference];
        $patch['room_type_code'] = $state['slots']['room_type_code'] ?? 'any';
        $dates = receptionist_knowledge_booking_dates($text, new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')));
        if (count($dates) >= 2) { $patch['start_date'] = $dates[0]; $patch['end_date'] = $dates[1]; }
        return ['slots_patch' => $patch, 'clear_slots' => [], 'kind' => 'booking'];
    }
    if (($state['slots']['intent'] ?? null) === 'Hotel Room' && receptionist_natural_room_type_menu_requested($text)) {
        return ['request_room_type' => true];
    }
    $roomTypeCorrection = preg_match('/\b(?:actually|instead|change|switch|another|rather|choose|try|prefer|gusto|palitan)\b/i', $text) === 1;
    $roomTypeStep = $pending === 'room_type_code'
        || ($roomTypeCorrection && ($state['slots']['intent'] ?? null) === 'Hotel Room')
        || (($state['slots']['intent'] ?? null) === 'Hotel Room' && $pending === 'preference');
    if ($roomTypeStep && receptionist_natural_room_type_menu_requested($text) && receptionist_natural_parse_room_type_choice($text) === null) return ['clarify_room_type' => true];
    if ($roomTypeStep && ($roomType = receptionist_natural_local_room_type_answer($text)) !== null) {
        $patch = ['room_type_code' => $roomType];
        $dates = receptionist_knowledge_booking_dates($text, new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')));
        if (count($dates) >= 2) { $patch['start_date'] = $dates[0]; $patch['end_date'] = $dates[1]; }
        elseif (count($dates) === 1) {
            if (preg_match('/\b(?:checkout|check[ -]?out|departure)\b/i', $text)) $patch['end_date'] = $dates[0];
            else $patch['start_date'] = $dates[0];
        }
        return ['slots_patch' => $patch, 'clear_slots' => [], 'kind' => 'booking'];
    }
    if ($pending === 'intent' && ($value = receptionist_natural_category($text)) !== null && str_word_count($text) <= 4) return ['slots_patch' => ['intent' => $value], 'clear_slots' => [], 'kind' => 'booking'];
    if (in_array($pending, ['start_date', 'end_date'], true)) {
        $simpleDate = preg_match('/\A(?:on\s+)?(?:today|tomorrow|next\s+\w+|\d{4}-\d{2}-\d{2}|(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\s+\d{1,2}(?:,?\s+\d{4})?)[.!]?\z/i', $text) === 1;
        $date = $simpleDate ? receptionist_knowledge_booking_date($text) : null;
        if ($date !== null) return ['slots_patch' => [$pending => $date], 'clear_slots' => [], 'kind' => 'booking'];
        if (preg_match('/\A(?:on\s+)?(?:yesterday|last\s+(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)|kahapon)[.!]?\z/i', $text)) return ['invalid_date' => true];
    }
    return null;
}

function receptionist_natural_local_action_patch(?string $actionId): ?array
{
    $intent = match ($actionId) {
        'category_event_hall' => 'Event Hall',
        'category_hotel_room' => 'Hotel Room',
        'category_resort_villa' => 'Resort Villa',
        default => null,
    };
    return $intent === null ? null : ['slots_patch' => ['intent' => $intent], 'clear_slots' => [], 'kind' => 'booking'];
}

/** Resolve only a unique published venue plus hotel room-group identity. */
function receptionist_natural_named_availability_target(array $records, string $message, array $slots): array
{
    $category = receptionist_natural_category($message) ?? ($slots['intent'] ?? null);
    if (!in_array($category, ['Hotel Room', 'Event Hall', 'Resort Villa'], true)) return ['target' => null, 'ambiguous' => false];
    $lower = mb_strtolower($message, 'UTF-8');
    $pronoun = preg_match('/\b(?:this|that|same|selected|it|this room|that room|dito|iyan|iyon|ito)\b/i', $message) === 1;
    $matches = [];
    foreach ($records as $record) {
        if (($record['kind'] ?? null) !== 'venue' || ($record['category'] ?? null) !== $category) continue;
        $venueId = (int)($record['venue_id'] ?? 0);
        $groupId = $category === 'Hotel Room' ? (int)($record['room_group_id'] ?? 0) : 0;
        if ($venueId < 1 || ($category === 'Hotel Room' && $groupId < 1)) continue;
        $name = mb_strtolower(trim((string)($record['name'] ?? '')), 'UTF-8');
        $roomType = mb_strtolower(trim((string)($record['room_type'] ?? '')), 'UTF-8');
        $activeMatch = $pronoun && (int)($slots['active_venue_id'] ?? 0) === $venueId
            && ($category !== 'Hotel Room' || (int)($slots['active_room_group_id'] ?? 0) === $groupId);
        $nameMatch = $name !== '' && mb_strlen($name, 'UTF-8') >= 3 && str_contains($lower, $name);
        $typeMatch = $roomType !== '' && mb_strlen($roomType, 'UTF-8') >= 3 && str_contains($lower, $roomType);
        if (!$activeMatch && !$nameMatch && !$typeMatch) continue;
        // Prefer a building + exact room type pair over either partial label.
        // A model-selected source ID cannot break a tie the guest left open.
        $score = $activeMatch && !$nameMatch && !$typeMatch ? 4 : ($nameMatch && $typeMatch ? 3 : 2);
        $matches[$venueId . ':' . $groupId] = ['score' => $score, 'record' => $record];
    }
    if (!$matches) return ['target' => null, 'ambiguous' => false];
    $bestScore = max(array_column($matches, 'score'));
    $best = array_values(array_filter($matches, static fn(array $match): bool => $match['score'] === $bestScore));
    if (count($best) === 1) return ['target' => $best[0]['record'], 'ambiguous' => false];
    return ['target' => null, 'ambiguous' => true];
}

/** Trusted receptionist UI sync; values still pass the normal slot validator. */
function receptionist_natural_guided_context_patch(array $request, array $state): ?array
{
    if (($request['action_id'] ?? null) !== 'guided_search_update' || !is_array($request['guided_context'] ?? null)) return null;
    $source = $request['guided_context'];
    $mapping = ['intent' => 'intent', 'occasion' => 'occasion', 'purpose' => 'purpose', 'groupSizeExact' => 'group_size',
        'group_size' => 'group_size', 'preference' => 'preference', 'roomTypeCode' => 'room_type_code', 'room_type_code' => 'room_type_code',
        'startDate' => 'start_date', 'endDate' => 'end_date',
        'activeVenueId' => 'active_venue_id', 'activeRoomGroupId' => 'active_room_group_id'];
    $patch = [];
    $clear = [];
    foreach ($mapping as $clientKey => $serverKey) {
        if (!array_key_exists($clientKey, $source)) continue;
        $value = $source[$clientKey];
        if ($value === null || $value === '') {
            if (array_key_exists($serverKey, $state['slots'])) $clear[] = $serverKey;
            continue;
        }
        $patch[$serverKey] = $value;
    }
    // A category change is a new search. Do not let old dependent criteria or
    // selected venue identities survive even if a stale browser sent them.
    if (isset($patch['intent']) && isset($state['slots']['intent']) && $patch['intent'] !== $state['slots']['intent']) {
        $clear = array_values(array_unique([...$clear, 'occasion', 'purpose', 'group_size', 'preference', 'room_type_code', 'start_date', 'end_date', 'active_venue_id', 'active_room_group_id']));
    }
    return ['slots_patch' => $patch, 'clear_slots' => $clear, 'kind' => 'booking', 'guided_sync' => true,
        'request_room_type' => ($source['roomTypeRequested'] ?? false) === true];
}

/** Describe only validated UI selections so guided steps can be restored as chat history. */
function receptionist_natural_guided_history_label(array $before, array $after): ?string
{
    $labels = [];
    $intentNames = ['Hotel Room' => 'hotel room', 'Event Hall' => 'event hall', 'Resort Villa' => 'resort villa'];
    if (($before['intent'] ?? null) !== ($after['intent'] ?? null) && isset($intentNames[$after['intent'] ?? ''])) {
        $labels[] = $intentNames[$after['intent']];
    }
    foreach (['group_size' => 'guest count'] as $field => $label) {
        if (($before[$field] ?? null) !== ($after[$field] ?? null) && isset($after[$field])) $labels[] = (int)$after[$field] . ' guests';
    }
    if (($before['room_type_code'] ?? null) !== ($after['room_type_code'] ?? null) && isset($after['room_type_code'])) {
        $labels[] = $after['room_type_code'] === 'any' ? 'any room type' : (hotel_room_type_label((string)$after['room_type_code']) ?? 'room type');
    }
    if (($before['preference'] ?? null) !== ($after['preference'] ?? null) && isset($after['preference'])) {
        $labels[] = match ($after['preference']) { 'best_fit' => 'best fit', 'save' => 'lowest price', 'comfort' => 'comfort', default => '' };
    }
    $intent = $after['intent'] ?? $before['intent'] ?? 'Hotel Room';
    $dateLabels = match ($intent) {
        'Event Hall' => ['start_date' => 'event date', 'end_date' => 'event end date'],
        'Resort Villa' => ['start_date' => 'visit date', 'end_date' => 'departure date'],
        default => ['start_date' => 'check-in', 'end_date' => 'check-out'],
    };
    foreach ($dateLabels as $field => $label) {
        if (($before[$field] ?? null) === ($after[$field] ?? null) || !is_string($after[$field] ?? null)) continue;
        if ($field === 'end_date' && ($after['start_date'] ?? null) === $after[$field]
            && in_array($intent, ['Event Hall', 'Resort Villa'], true)) continue;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $after[$field], new DateTimeZone('Asia/Manila'));
        if ($date instanceof DateTimeImmutable && $date->format('Y-m-d') === $after[$field]) $labels[] = $label . ' ' . $date->format('F j, Y');
    }
    $labels = array_values(array_filter($labels, static fn(string $label): bool => $label !== ''));
    return $labels ? 'Selected ' . implode(', ', $labels) . '.' : null;
}

function receptionist_natural_guest_range(int $count): ?string
{
    foreach (hotel_allowed_guest_ranges() as $key => $range) if ($count >= $range['min'] && $count <= $range['max']) return $key;
    return null;
}

function receptionist_natural_reference(array $state, string $message): ?array
{
    $results = is_array($state['recommendation_snapshot']['results'] ?? null) ? $state['recommendation_snapshot']['results'] : [];
    if (!$results || receptionist_knowledge_intent($message)['kind'] === 'availability') return null;
    $lower = mb_strtolower($message, 'UTF-8');
    $matches = [];
    foreach ($results as $result) {
        $title = mb_strtolower((string)($result['title'] ?? ''), 'UTF-8');
        $building = mb_strtolower((string)($result['building_name'] ?? ''), 'UTF-8');
        $type = mb_strtolower((string)($result['room_type'] ?? ''), 'UTF-8');
        if (($title !== '' && str_contains($lower, $title)) || ($building !== '' && str_contains($lower, $building)) || ($type !== '' && str_contains($lower, $type))) $matches[] = $result;
    }
    if (preg_match('/\b(?:cheaper|cheapest|lowest|less expensive|budget)\b/i', $message)) {
        $candidates = count($matches) >= 2 ? $matches : $results;
        usort($candidates, static fn(array $a, array $b): int => (float)($a['rate_value'] ?? PHP_FLOAT_MAX) <=> (float)($b['rate_value'] ?? PHP_FLOAT_MAX));
        $matches = $candidates ? [$candidates[0]] : [];
    } elseif (!$matches && preg_match('/\b(?:why|details|tell me more|what about|its|it|that one|this one|those|them)\b/i', $message)) {
        $focused = $state['focused_room'] ?? null;
        if (is_array($focused)) foreach ($results as $result) {
            if ((int)($result['venue_id'] ?? 0) === (int)$focused['venue_id'] && (int)($result['room_group_id'] ?? 0) === (int)$focused['room_group_id']) $matches[] = $result;
        }
        if (!$matches && count($results) === 1) $matches = [$results[0]];
    }
    return count($matches) === 1 ? $matches[0] : null;
}

function receptionist_natural_expected_reference(array $state, string $message): ?array
{
    $results = is_array($state['recommendation_snapshot']['results'] ?? null) ? $state['recommendation_snapshot']['results'] : [];
    if (!$results) return null;
    $lower = mb_strtolower($message, 'UTF-8');
    if (preg_match('/\b(?:first|1st|una)\b/i', $message)) return $results[0] ?? null;
    if (preg_match('/\b(?:second|2nd|pangalawa)\b/i', $message)) return $results[1] ?? null;
    if (preg_match('/\b(?:third|3rd|pangatlo)\b/i', $message)) return $results[2] ?? null;

    if (preg_match('/\b(?:cheaper|cheapest|lowest|less expensive|pinakamura)\b/i', $message)) {
        $excluded = [];
        foreach ($results as $result) {
            foreach (['title', 'building_name', 'room_type'] as $field) {
                $label = mb_strtolower(trim((string)($result[$field] ?? '')), 'UTF-8');
                if ($label !== '' && str_contains($lower, $label)) { $excluded[] = $result; break; }
            }
        }
        if (count($excluded) === 1 && (preg_match('/\b(?:than|compared with|cheaper than|kaysa kay|kumpara kay)\b/i', $message))) {
            $excludedId = (int)($excluded[0]['venue_id'] ?? 0) . ':' . (int)($excluded[0]['room_group_id'] ?? 0);
            $results = array_values(array_filter($results, static fn(array $item): bool => ((int)($item['venue_id'] ?? 0) . ':' . (int)($item['room_group_id'] ?? 0)) !== $excludedId));
        }
        $costs = [];
        foreach ($results as $result) {
            $value = $result['estimated_total'] ?? $result['estimated_nightly_amount'] ?? $result['rate_value'] ?? null;
            if (is_numeric($value) && (float)$value >= 0) $costs[] = ['result' => $result, 'cost' => (float)$value];
        }
        if (!$costs) return null;
        usort($costs, static fn(array $a, array $b): int => $a['cost'] <=> $b['cost']);
        if (isset($costs[1]) && abs($costs[0]['cost'] - $costs[1]['cost']) < 0.005) return null;
        return $costs[0]['result'];
    }

    $named = [];
    foreach ($results as $result) {
        foreach (['title', 'building_name', 'room_type'] as $field) {
            $label = mb_strtolower(trim((string)($result[$field] ?? '')), 'UTF-8');
            if ($label !== '' && str_contains($lower, $label)) { $named[] = $result; break; }
        }
    }
    if ($named) return count($named) === 1 ? $named[0] : null;

    $focused = $state['focused_room'] ?? null;
    $focusVenue = is_array($focused) ? (int)($focused['venue_id'] ?? 0) : (int)($state['slots']['active_venue_id'] ?? 0);
    $focusGroup = is_array($focused) ? (int)($focused['room_group_id'] ?? 0) : (int)($state['slots']['active_room_group_id'] ?? 0);
    if ($focusVenue > 0) foreach ($results as $result) {
        if ((int)($result['venue_id'] ?? 0) === $focusVenue && (int)($result['room_group_id'] ?? 0) === $focusGroup) return $result;
    }
    return count($results) === 1 ? $results[0] : null;
}

function receptionist_natural_reference_cue(string $message): bool
{
    return preg_match('/\b(?:why|reason|recommend|best fit|details?|tell me more|what about|cheaper|cheapest|lowest|less expensive|pinakamura|first|1st|second|2nd|third|3rd|una|pangalawa|pangatlo|that one|this one|those|them|it|its)\b/i', $message) === 1;
}

function receptionist_natural_snapshot_reply(array $result, string $message, string $language, array $snapshot = []): string
{
    $title = (string)($result['title'] ?? 'This room');
    if (preg_match('/\b(?:cheaper|less expensive|pinakamura)\b/i', $message) && str_contains($message, '?')) {
        $results = is_array($snapshot['results'] ?? null) ? $snapshot['results'] : [];
        $others = array_values(array_filter($results, static fn(array $item): bool => (int)($item['venue_id'] ?? 0) !== (int)($result['venue_id'] ?? 0)
            || (int)($item['room_group_id'] ?? 0) !== (int)($result['room_group_id'] ?? 0)));
        $isStayPrice = ($snapshot['pricing_basis'] ?? null) === 'full_stay';
        $targetCost = $isStayPrice ? ($result['estimated_total'] ?? null)
            : ($result['estimated_nightly_amount'] ?? $result['rate_value'] ?? null);
        if (is_numeric($targetCost) && $others) {
            $peer = null;
            foreach ($others as $candidate) {
                $cost = $isStayPrice ? ($candidate['estimated_total'] ?? null)
                    : ($candidate['estimated_nightly_amount'] ?? $candidate['rate_value'] ?? null);
                if (!is_numeric($cost)) continue;
                if ($peer === null || (float)$cost < (float)$peer['cost']) $peer = ['item' => $candidate, 'cost' => (float)$cost];
            }
            if ($peer !== null) {
                $basis = $isStayPrice ? 'full stay' : 'per night';
                $targetAmount = '₱' . number_format((float)$targetCost, 2);
                $peerAmount = '₱' . number_format((float)$peer['cost'], 2);
                $peerName = (string)($peer['item']['title'] ?? 'the other option');
                $lower = (float)$targetCost < $peer['cost'];
                return match ($language) {
                    'fil' => $lower ? "Mas mura ang {$title} sa {$targetAmount} para sa {$basis}, kumpara sa {$peerName} na {$peerAmount}."
                        : "Hindi mas mura ang {$title} sa {$targetAmount} para sa {$basis}; {$peerName} ang mas mababa sa {$peerAmount}.",
                    'taglish' => $lower ? "Mas mura ang {$title} at {$targetAmount} for the {$basis}, compared with {$peerName} at {$peerAmount}."
                        : "Hindi mas mura ang {$title} at {$targetAmount} for the {$basis}; {$peerName} is lower at {$peerAmount}.",
                    default => $lower ? "{$title} is cheaper at {$targetAmount} for the {$basis}, compared with {$peerName} at {$peerAmount}."
                        : "{$title} is not cheaper at {$targetAmount} for the {$basis}; {$peerName} is lower at {$peerAmount}.",
                };
            }
        }
    }
    if (preg_match('/\b(?:available|availability|free on|open on)\b/i', $message)) {
        $status = (string)($result['status'] ?? 'Availability not checked');
        return match ($language) {
            'fil' => $title . ' ay ' . ($status === 'Available' ? 'available' : 'hindi available') . ' sa mga petsang iyon.',
            'taglish' => $title . ' is ' . ($status === 'Available' ? 'available' : 'unavailable') . ' for those dates.',
            default => $title . ' is ' . ($status === 'Available' ? 'available' : 'unavailable') . ' for those dates.',
        };
    }
    if (preg_match('/\b(?:why|best fit|recommend)\b/i', $message)) {
        $reason = is_array($result['reasons'][0] ?? null) ? (string)($result['reasons'][0]['value'] ?? '') : '';
        if ($reason === '') return receptionist_natural_local_reply('unknown', $language);
        return match ($language) {
            'fil' => $title . ' ang iminungkahi dahil ' . mb_strtolower($reason, 'UTF-8') . '.',
            'taglish' => $title . ' came up dahil ' . mb_strtolower($reason, 'UTF-8') . '.',
            default => $title . ' came up because ' . mb_strtolower($reason, 'UTF-8') . '.',
        };
    }
    $capacityValue = is_numeric($result['capacity_value'] ?? null) ? (int)$result['capacity_value'] : null;
    $bedsValue = is_numeric($result['beds_value'] ?? null) ? (int)$result['beds_value'] : null;
    $capacityText = (string)($result['capacity'] ?? '');
    $bedsText = (string)($result['beds'] ?? '');
    $rateText = (string)($result['rate'] ?? '');
    return match ($language) {
        'fil' => $capacityValue !== null && $bedsValue !== null
            ? "{$title}: akma sa hanggang {$capacityValue} bisita, may {$bedsValue} kama, at rate na {$rateText}."
            : "Para sa detalye ng {$title}: {$capacityText}, {$bedsText}, at {$rateText}.",
        'taglish' => $capacityValue !== null && $bedsValue !== null
            ? "{$title}: fits up to {$capacityValue} guests, may {$bedsValue} beds, at rate na {$rateText}."
            : "For {$title}, details na nasa listing: {$capacityText}, {$bedsText}, at {$rateText}.",
        default => "{$title}: {$capacityText}, {$bedsText}, {$rateText}.",
    };
}

function receptionist_natural_selected_room_outcome_reply(array $outcome, string $language): string
{
    $title = receptionist_ai_safe_catalog_text($outcome['title'] ?? '') ?: 'The selected room';
    $capacity = filter_var($outcome['capacity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $groupSize = filter_var($outcome['group_size'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $doesNotFit = $capacity !== false && $groupSize !== false && $groupSize > $capacity;
    $available = ($outcome['available'] ?? false) === true;
    $options = max(0, (int)($outcome['options_count'] ?? 0));
    $optionsChecked = ($outcome['options_checked'] ?? false) === true;

    if (!$available && $doesNotFit) {
        $lead = match ($language) {
            'fil' => "Hindi available ang {$title} sa mga petsang iyon. Hanggang {$capacity} bisita ang published capacity nito, para sa grupong {$groupSize}.",
            'taglish' => "Hindi available ang {$title} for those dates. Its published capacity is {$capacity} guests for your group of {$groupSize}.",
            default => "{$title} is not available on those dates. Its published capacity is {$capacity} guests for your group of {$groupSize}.",
        };
    } elseif (!$available) {
        $lead = match ($language) {
            'fil' => "Hindi available ang {$title} sa mga petsang iyon.",
            'taglish' => "Hindi available ang {$title} for those dates.",
            default => "{$title} is not available on those dates.",
        };
    } elseif ($doesNotFit) {
        $lead = match ($language) {
            'fil' => "Available ang {$title} sa mga petsang iyon, pero hanggang {$capacity} bisita lang ang published capacity nito; {$groupSize} kayo.",
            'taglish' => "Available ang {$title} for those dates, pero {$capacity} guests lang ang published capacity; {$groupSize} kayo.",
            default => "{$title} is available on those dates, but its published capacity is {$capacity} guests and your group has {$groupSize}.",
        };
    } else {
        $lead = match ($language) {
            'fil' => "Available ang {$title} at pasok ito sa bilang ng bisita mo sa mga petsang iyon.",
            'taglish' => "Available ang {$title} and it fits your group for those dates.",
            default => "{$title} is available and fits your group for those dates.",
        };
    }

    if ($optionsChecked && $options > 0) {
        $followup = match ($language) {
            'fil' => "May {$options} checked na room option sa ibaba.",
            'taglish' => "May {$options} checked room options sa ibaba.",
            default => "I found {$options} checked room options below.",
        };
    } elseif ($optionsChecked) {
        $followup = match ($language) {
            'fil' => 'Wala akong nakitang ibang checked na room option. Maaari mong baguhin ang petsa o room type.',
            'taglish' => 'Wala akong nakitang ibang checked room option. Maaari mong baguhin ang dates o room type.',
            default => 'I did not find another checked room option. You can change the dates or room type.',
        };
    } elseif ($groupSize === false) {
        $followup = match ($language) {
            'fil' => 'Ibigay ang bilang ng bisita kung gusto mong maghanap ako ng alternatives.',
            'taglish' => 'Ibigay ang guest count para makahanap ako ng alternatives.',
            default => 'Tell me the guest count and I can look for alternatives.',
        };
    } else {
        $followup = match ($language) {
            'fil' => 'Hindi ko ma-load ngayon ang alternatives; maaari mong baguhin ang petsa o subukan ulit.',
            'taglish' => 'Hindi ko ma-load ngayon ang alternatives; puwede mong baguhin ang dates o subukan ulit.',
            default => 'I could not load alternatives right now. You can change the dates or try again.',
        };
    }
    return $lead . ' ' . $followup;
}

/** Build a selected-room answer only from a fresh exact result for this stay. */
function receptionist_natural_selected_room_outcome_from_snapshot(
    array $snapshot,
    int $venueId,
    int $groupId,
    string $checkIn,
    string $checkOut,
    ?int $groupSize
): ?array {
    if ($venueId < 1 || $groupId < 1 || !receptionist_natural_snapshot_has_fresh_availability($snapshot)
        || ($snapshot['check_in'] ?? null) !== $checkIn || ($snapshot['check_out'] ?? null) !== $checkOut) return null;

    foreach ($snapshot['results'] ?? [] as $result) {
        if (!is_array($result) || (int)($result['venue_id'] ?? 0) !== $venueId
            || (int)($result['room_group_id'] ?? 0) !== $groupId) continue;
        if (empty($result['availability_checked']) && empty($snapshot['availability_checked'])) return null;
        $capacity = filter_var($result['capacity_value'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($groupSize !== null && $capacity === false) return null;
        $status = (string)($result['status'] ?? '');
        if (!in_array($status, ['Available', 'Unavailable for these dates'], true)) return null;
        return [
            'title' => (string)($result['title'] ?? 'The selected room'),
            'available' => $status === 'Available',
            'capacity' => $capacity === false ? null : $capacity,
            'group_size' => $groupSize,
        ];
    }
    return null;
}

function receptionist_natural_validate_proposal(array $payload, array $state, array $catalog, string $message, bool $trustedStructuredPatch = false): array
{
    $kind = $payload['kind'] ?? null;
    if (!in_array($kind, ['booking', 'question', 'availability', 'reference', 'social', 'ambiguous'], true)) throw new InvalidArgumentException('Invalid natural receptionist kind.');
    $patch = $payload['slots_patch'] ?? null;
    $allowed = ['intent', 'occasion', 'purpose', 'group_size', 'preference', 'room_type_code', 'start_date', 'end_date', 'active_venue_id', 'active_room_group_id'];
    if (!is_array($patch) || array_diff(array_keys($patch), $allowed)) throw new InvalidArgumentException('Invalid natural receptionist slot patch.');
    $evidenced = [];
    if ($trustedStructuredPatch) {
        // Explicit guided controls already carry typed values. They still pass
        // through the normal catalog/date/count slot validator below; gating
        // them on the synthetic action text would silently erase dates.
        $evidenced = $patch;
    } else foreach ($patch as $key => $value) {
        if ($value === null || $value === '') continue;
        if ($key === 'intent' && receptionist_natural_category_evidence($message) === $value) $evidenced[$key] = $value;
        elseif ($key === 'group_size' && is_int($value) && receptionist_natural_group_count_evidence($message, $state, $value)) $evidenced[$key] = $value;
        elseif ($key === 'preference') {
            $parsed = receptionist_natural_parse_preference($message);
            if ($parsed === $value && receptionist_natural_preference_evidence($message, $state['pending_question'] ?? null) !== null) $evidenced[$key] = $value;
        } elseif ($key === 'room_type_code') {
            $parsed = receptionist_natural_parse_room_type_choice($message);
            if ($parsed !== null && $parsed === $value) $evidenced[$key] = $value;
        } elseif ($key === 'occasion' && preg_match('/\b(?:wedding|birthday|celebration|corporate|meeting|reunion|kasal|kaarawan)\b/i', $message)) $evidenced[$key] = $value;
        elseif ($key === 'purpose' && preg_match('/\b(?:family|private|relax|relaxation|pamilya|pribado)\b/i', $message)) $evidenced[$key] = $value;
        elseif (in_array($key, ['start_date', 'end_date'], true) && receptionist_natural_date_evidence($message, $state, $key, $value)) $evidenced[$key] = $value;
    }
    $intent = $evidenced['intent'] ?? null;
    if (is_string($intent) && isset($state['slots']['intent']) && $intent !== $state['slots']['intent']) {
        $switchPatch = ['intent' => $intent];
        foreach (['occasion', 'purpose', 'group_size', 'preference', 'room_type_code', 'start_date', 'end_date'] as $key) if (isset($evidenced[$key])) $switchPatch[$key] = $evidenced[$key];
        $evidenced = $switchPatch;
    }
    // Model-requested clears are only honored for an explicit, user-evidenced category switch.
    $clear = [];
    if ($trustedStructuredPatch) {
        foreach (is_array($payload['clear_slots'] ?? null) ? $payload['clear_slots'] : [] as $key) if (in_array($key, $allowed, true)) $clear[] = $key;
    } elseif (isset($evidenced['intent']) && $evidenced['intent'] !== ($state['slots']['intent'] ?? null)) {
        foreach (is_array($payload['clear_slots'] ?? null) ? $payload['clear_slots'] : [] as $key) if (in_array($key, $allowed, true)) $clear[] = $key;
    }
    $targetVenue = filter_var($payload['target_venue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $snapshotCategory = $state['recommendation_snapshot']['intent'] ?? null;
    $rawTargetGroup = $payload['target_room_group_id'] ?? null;
    $targetGroup = $snapshotCategory === 'Hotel Room'
        ? filter_var($rawTargetGroup, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : (in_array($rawTargetGroup, [null, 0, '0'], true) ? 0 : false);
    $reference = null;
    if ($targetVenue !== false && $targetGroup !== false && is_string($snapshotCategory) && in_array($kind, ['reference', 'question'], true)) {
        foreach ($state['recommendation_snapshot']['results'] ?? [] as $item) {
            if ((int)($item['venue_id'] ?? 0) === (int)$targetVenue && (int)($item['room_group_id'] ?? 0) === (int)$targetGroup
                && receptionist_ai_catalog_venue($catalog, $targetVenue, $snapshotCategory, $snapshotCategory === 'Hotel Room' ? $targetGroup : null) !== null) { $reference = $item; break; }
        }
    }
    $expectedReference = receptionist_natural_expected_reference($state, $message);
    if ($snapshotCategory === 'Hotel Room' && preg_match('/\b(?:cheaper|cheapest|lowest|less expensive|pinakamura|first|1st|una|second|2nd|pangalawa|third|3rd|pangatlo)\b/i', $message)) {
        if ($reference === null || $expectedReference === null
            || (int)$reference['venue_id'] !== (int)$expectedReference['venue_id']
            || (int)$reference['room_group_id'] !== (int)$expectedReference['room_group_id']) $reference = null;
    }
    return ['kind' => $kind, 'slots_patch' => $evidenced, 'clear_slots' => array_values(array_unique($clear)),
        'reply_language' => in_array($payload['language'] ?? null, ['en', 'fil', 'taglish'], true) ? $payload['language'] : null,
        'knowledge_id' => is_string($payload['knowledge_id'] ?? null) ? $payload['knowledge_id'] : null,
        'knowledge_property' => is_string($payload['knowledge_property'] ?? null) ? $payload['knowledge_property'] : null,
        'target_venue_id' => $targetVenue === false ? null : (int)$targetVenue,
        'target_room_group_id' => $targetGroup === false ? null : (int)$targetGroup,
        'reference' => $reference,
        'missing_field' => in_array($payload['missing_field'] ?? null, ['intent', 'group_size', 'room_type_code', 'preference', 'occasion', 'purpose', 'start_date', 'end_date'], true) ? $payload['missing_field'] : null,
        'social_reply' => is_string($payload['reply'] ?? null) ? $payload['reply'] : '',
        'claims' => is_array($payload['claims'] ?? null) ? array_slice($payload['claims'], 0, 12) : [],
        'reply_segments' => is_array($payload['reply_segments'] ?? null) ? $payload['reply_segments'] : []];
}

/** Return the only server value a claim may cite for this exact source and field. */
function receptionist_natural_claim_value(string $sourceId, string $field, array $records, array $state, array $proposal): ?string
{
    if ($sourceId === 'recommendation-summary') {
        $snapshot = $state['recommendation_snapshot'] ?? null;
        if (!is_array($snapshot)) return null;
        $value = match ($field) {
            'result_count' => isset($snapshot['total_matches']) ? (string)(int)$snapshot['total_matches'] : null,
            'guest_count' => isset($snapshot['group_size']) ? (string)(int)$snapshot['group_size']
                : (isset($snapshot['guest_count']) ? (string)(int)$snapshot['guest_count']
                    : (isset($state['slots']['group_size']) ? (string)(int)$state['slots']['group_size'] : null)),
            'check_in' => is_string($snapshot['check_in'] ?? null) ? $snapshot['check_in'] : null,
            'check_out' => is_string($snapshot['check_out'] ?? null) ? $snapshot['check_out'] : null,
            'nights' => (static function () use ($snapshot): ?string {
                $checkIn = is_string($snapshot['check_in'] ?? null) ? strtotime($snapshot['check_in']) : false;
                $checkOut = is_string($snapshot['check_out'] ?? null) ? strtotime($snapshot['check_out']) : false;
                if ($checkIn === false || $checkOut === false || $checkOut <= $checkIn) return null;
                return (string)(int)(($checkOut - $checkIn) / 86400);
            })(),
            default => null,
        };
        return is_string($value) && $value !== '' ? $value : null;
    }

    if (str_starts_with($sourceId, 'recommendation-')) {
        foreach ($state['recommendation_snapshot']['results'] ?? [] as $item) {
            $itemId = 'recommendation-' . (int)($item['venue_id'] ?? 0) . '-' . (int)($item['room_group_id'] ?? 0);
            if ($sourceId !== $itemId) continue;
            // The source ID itself is the evidence identity. Proposal target
            // IDs describe focus and are validated separately; a comparison
            // reply may cite more than one exact result in the same turn.
            $value = match ($field) {
                'name' => (string)($item['title'] ?? ''),
                'room_type' => (string)($item['room_type'] ?? ''),
                'capacity' => (string)($item['capacity'] ?? ''),
                'beds' => (string)($item['beds'] ?? ''),
                'rate' => (string)($item['rate'] ?? ''),
                'estimated_total' => isset($item['estimated_total']) ? '₱' . number_format((float)$item['estimated_total'], 2) . ' full stay' : null,
                'reason' => (string)($item['reasons'][0]['value'] ?? ''),
                'availability' => (string)($item['status'] ?? ''),
                'description' => (string)($item['description'] ?? ''),
                'amenities' => implode(', ', is_array($item['amenities'] ?? null) ? $item['amenities'] : []),
                default => null,
            };
            return is_string($value) && trim($value) !== '' ? $value : null;
        }
        return null;
    }

    foreach ($records as $record) {
        if (($record['id'] ?? null) !== $sourceId) continue;
        $value = match ($field) {
            'name' => (string)($record['name'] ?? ''),
            'room_type' => (string)($record['room_type'] ?? ''),
            'base_rate' => isset($record['base_rate']) ? '₱' . number_format((float)$record['base_rate'], 2) . ' ' . (string)($record['rate_unit'] ?? '') : null,
            'overnight_rate' => isset($record['overnight_rate']) ? '₱' . number_format((float)$record['overnight_rate'], 2) . ' per night' : null,
            'capacity_base' => isset($record['capacity_base']) ? 'base capacity ' . (int)$record['capacity_base'] . ' guests' : null,
            'capacity_max' => isset($record['capacity_max']) ? 'up to ' . (int)$record['capacity_max'] . ' guests' : null,
            'capacity_styles' => is_array($record['capacity_styles'] ?? null) ? implode(', ', array_map(static fn($label, $number): string => $label . ': ' . (int)$number, array_keys($record['capacity_styles']), array_values($record['capacity_styles']))) : null,
            'bed_count_min', 'bed_count_max' => isset($record[$field]) ? (int)$record[$field] . ' beds' : null,
            'amenities' => is_array($record['amenities'] ?? null) ? implode(', ', $record['amenities']) : null,
            'inclusions' => is_array($record['inclusions'] ?? null) ? implode(', ', $record['inclusions']) : null,
            'description' => (string)($record['description'] ?? ''),
            'question' => (string)($record['question'] ?? ''),
            'answer' => (string)($record['answer'] ?? ''),
            'text' => (string)($record['text'] ?? ''),
            default => null,
        };
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
    return null;
}

/** Exact, preformatted claim values shown to the model and reused by validation. */
function receptionist_natural_claimable_evidence(array $records, array $state): array
{
    $evidence = [];
    $recordFields = [
        'name' => ['description'], 'room_type' => ['description'], 'base_rate' => ['price'],
        'overnight_rate' => ['price', 'overnight_price'], 'capacity_base' => ['capacity'],
        'capacity_max' => ['capacity'], 'capacity_styles' => ['capacity'],
        'bed_count_min' => ['capacity'], 'bed_count_max' => ['capacity'],
        'amenities' => ['amenities'], 'inclusions' => ['amenities'], 'description' => ['description'],
        'question' => ['faq_answer', 'policy'], 'answer' => ['faq_answer', 'policy', 'contact', 'location', 'event_options'],
        'text' => ['faq_answer', 'policy', 'contact', 'location', 'event_options'],
    ];
    foreach ($records as $record) {
        $sourceId = is_string($record['id'] ?? null) ? $record['id'] : '';
        if ($sourceId === '') continue;
        foreach ($recordFields as $field => $properties) foreach ($properties as $property) {
            $value = receptionist_natural_claim_value($sourceId, $field, $records, $state, [
                'knowledge_id' => $sourceId, 'knowledge_property' => $property,
            ]);
            if ($value !== null) $evidence[$sourceId][$field] = $value;
        }
    }
    foreach ($state['recommendation_snapshot']['results'] ?? [] as $item) {
        if (!is_array($item)) continue;
        $sourceId = 'recommendation-' . (int)($item['venue_id'] ?? 0) . '-' . (int)($item['room_group_id'] ?? 0);
        $proposal = ['target_venue_id' => (int)($item['venue_id'] ?? 0), 'target_room_group_id' => (int)($item['room_group_id'] ?? 0)];
        foreach (['name', 'room_type', 'capacity', 'beds', 'rate', 'estimated_total', 'reason', 'availability', 'description', 'amenities'] as $field) {
            $value = receptionist_natural_claim_value($sourceId, $field, $records, $state, $proposal);
            if ($value !== null) $evidence[$sourceId][$field] = $value;
        }
    }
    if (is_array($state['recommendation_snapshot'] ?? null)) {
        foreach (['result_count', 'guest_count', 'check_in', 'check_out', 'nights'] as $field) {
            $value = receptionist_natural_claim_value('recommendation-summary', $field, $records, $state, []);
            if ($value !== null) $evidence['recommendation-summary'][$field] = $value;
        }
    }
    return $evidence;
}

function receptionist_natural_strip_followup_question(string $reply, ?string $pendingField = null): string
{
    $sentences = preg_split('/(?<=[.!?])\s+/u', trim($reply), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $kept = [];
    foreach ($sentences as $sentence) {
        if (str_contains($sentence, '?')) break;
        $kept[] = $sentence;
    }
    $fieldPatterns = [
        'intent' => '/\b(?:venue|hotel|villa|event hall)\b/iu',
        'group_size' => '/\b(?:guest|guests|people|party size|group size|bisita)\b/iu',
        'preference' => '/\b(?:priority|preference|lowest price|best fit|comfort)\b/iu',
        'occasion' => '/\b(?:occasion|celebration|event)\b/iu',
        'purpose' => '/\b(?:purpose|stay|visit)\b/iu',
        'start_date' => '/\b(?:check[ -]?in|arrival|date)\b/iu',
        'end_date' => '/\b(?:check[ -]?out|departure|date)\b/iu',
    ];
    $lastIndex = count($kept) - 1;
    if ($lastIndex > 0 && isset($fieldPatterns[$pendingField ?? ''])
        && preg_match('/\b(?:let me know|tell me|share|send|provide|please (?:tell|share|provide)|when you are ready)\b/iu', $kept[$lastIndex]) === 1
        && preg_match($fieldPatterns[$pendingField], $kept[$lastIndex]) === 1) {
        array_pop($kept);
    }
    return trim(implode(' ', $kept));
}

function receptionist_natural_reply_matches_language(string $reply, string $language): bool
{
    if ($language === 'en') return true;
    $lower = mb_strtolower($reply, 'UTF-8');
    $markers = ['ang', 'ng', 'sa', 'para', 'ito', 'iyan', 'ninyo', 'natin', 'ako', 'ko', 'mo', 'niya', 'ay', 'may', 'wala', 'hindi', 'mga', 'po', 'naka', 'kasya', 'bagay', 'mas', 'dahil', 'pero', 'kung', 'kaya', 'gusto', 'presyo', 'kwarto', 'bisita', 'lang'];
    foreach ($markers as $marker) if (preg_match('/\b' . preg_quote($marker, '/') . '\b/u', $lower) === 1) return true;
    return false;
}

function receptionist_natural_proposal_observe(array $dependencies, string $phase, array $payload, array $proposal): void
{
    if (!is_callable($dependencies['proposal_trace'] ?? null)) return;
    ($dependencies['proposal_trace'])($phase, [
        'kind' => $proposal['kind'] ?? null,
        'slots_patch' => $proposal['slots_patch'] ?? [],
        'clear_slots' => $proposal['clear_slots'] ?? [],
        'knowledge_id' => $proposal['knowledge_id'] ?? null,
        'knowledge_property' => $proposal['knowledge_property'] ?? null,
        'target_venue_id' => $proposal['target_venue_id'] ?? null,
        'target_room_group_id' => $proposal['target_room_group_id'] ?? null,
        'missing_field' => $proposal['missing_field'] ?? null,
        'reply' => $payload['reply'] ?? '',
        'claims' => $payload['claims'] ?? [],
    ]);
}

function receptionist_natural_numeric_value(string $value): ?string
{
    $value = str_replace(',', '', trim($value));
    if (!is_numeric($value)) return null;
    $number = (float)$value;
    return floor($number) === $number ? (string)(int)$number : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
}

/** Accept natural prose only when every stated fact is backed by exact claims. */
function receptionist_natural_grounded_reply(string $reply, array $claims, array $records, array $state, array $proposal, string $message, ?string &$rejectionReason = null, bool $allowAvailabilityClaims = false): ?string
{
    $rejectionReason = null;
    $reject = static function (string $reason) use (&$rejectionReason): ?string { $rejectionReason = $reason; return null; };
    $clean = receptionist_faq_text($reply, 600);
    if ($clean === null || preg_match('/(?:https?:\/\/|www\.|<[^>]*>)/iu', $clean) === 1 || substr_count($clean, '?') > 1) return $reject('reply_shape');
    $verified = [];
    foreach (array_slice($claims, 0, 12) as $claim) {
        if (!is_array($claim) || !is_string($claim['source_id'] ?? null) || !is_string($claim['field'] ?? null) || !is_string($claim['value'] ?? null)) return $reject('claim_shape');
        $expected = receptionist_natural_claim_value($claim['source_id'], $claim['field'], $records, $state, $proposal);
        if ($expected === null || trim($claim['value']) !== trim($expected)) return $reject('claim_value');
        if ($claim['field'] === 'availability' && !$allowAvailabilityClaims) return $reject('availability_not_checked');
        $verified[] = ['source_id' => $claim['source_id'], 'field' => $claim['field'], 'value' => $expected];
    }

    if (!$verified && receptionist_knowledge_is_fact_request($message, $state['slots'])) return $reject('missing_factual_claims');

    $replyLower = mb_strtolower($clean, 'UTF-8');
    $assertsAvailability = preg_match('/\b(?:is|are|was|were|remains?|shows?)\s+(?:not\s+)?available\b|\bavailable\s+(?:for|on|from)\b|\b(?:fully\s+)?booked\b|\bwalang bakante\b|\bmay bakante\b/iu', $clean) === 1;
    if ($assertsAvailability && (!$allowAvailabilityClaims
        || !array_filter($verified, static fn(array $claim): bool => $claim['field'] === 'availability'))) return $reject('availability_not_checked');
    // Check each distinct mentioned label against a selected exact source.
    // Repeated room types in unrelated catalog rows must not make an exact
    // target's display name impossible to use.
    $labels = [];
    $recordById = [];
    foreach ($records as $record) if (is_string($record['id'] ?? null)) $recordById[$record['id']] = $record;
    $mentionedCatalogIds = [];
    foreach ($records as $record) foreach (['name', 'room_type'] as $field) {
        $label = trim((string)($record[$field] ?? ''));
        if ($label !== '' && mb_strlen($label, 'UTF-8') >= 4 && str_contains($replyLower, mb_strtolower($label, 'UTF-8'))) $labels[mb_strtolower($label, 'UTF-8')] = $label;
    }
    foreach ($records as $record) {
        if (($record['kind'] ?? null) !== 'venue') continue;
        $building = trim((string)($record['name'] ?? ''));
        $roomType = trim((string)($record['room_type'] ?? ''));
        if ($building === '' || $roomType === '') continue;
        $composite = $building . ' ' . $roomType;
        if (str_contains($replyLower, mb_strtolower($composite, 'UTF-8'))) {
            $mentionedCatalogIds[(string)($record['id'] ?? '')] = true;
            $labels[mb_strtolower($composite, 'UTF-8')] = $composite;
        }
    }
    $mentionedRecommendationIds = [];
    foreach ($state['recommendation_snapshot']['results'] ?? [] as $item) foreach (['title', 'room_type'] as $field) {
        $label = trim((string)($item[$field] ?? ''));
        if ($label !== '' && mb_strlen($label, 'UTF-8') >= 4 && str_contains($replyLower, mb_strtolower($label, 'UTF-8'))) {
            $labels[mb_strtolower($label, 'UTF-8')] = $label;
            if ($field === 'title') $mentionedRecommendationIds['recommendation-' . (int)($item['venue_id'] ?? 0) . '-' . (int)($item['room_group_id'] ?? 0)] = true;
        }
    }
    foreach ($labels as $label) {
        $labelLower = mb_strtolower($label, 'UTF-8');
        $tied = false;
        foreach ($verified as $claim) {
            if (!str_starts_with($claim['source_id'], 'recommendation-')) {
                foreach ($records as $record) if (($record['id'] ?? null) === $claim['source_id']) {
                    $sourceLabels = [(string)($record['name'] ?? ''), (string)($record['room_type'] ?? '')];
                    if (($record['kind'] ?? null) === 'venue' && trim((string)($record['name'] ?? '')) !== ''
                        && trim((string)($record['room_type'] ?? '')) !== '') {
                        $sourceLabels[] = trim((string)$record['name']) . ' ' . trim((string)$record['room_type']);
                    }
                    foreach ($sourceLabels as $sourceLabel) if ($sourceLabel !== ''
                        && mb_strtolower($sourceLabel, 'UTF-8') === $labelLower) $tied = true;
                }
                continue;
            }
            foreach ($state['recommendation_snapshot']['results'] ?? [] as $item) {
                $expectedId = 'recommendation-' . (int)($item['venue_id'] ?? 0) . '-' . (int)($item['room_group_id'] ?? 0);
                if ($claim['source_id'] !== $expectedId) continue;
                $labelsForSource = array_map(static fn(string $field): string => trim((string)($item[$field] ?? '')), ['title', 'building_name', 'room_type']);
                foreach ($records as $record) if (($record['kind'] ?? null) === 'venue'
                    && (int)($record['venue_id'] ?? 0) === (int)($item['venue_id'] ?? 0)
                    && (int)($record['room_group_id'] ?? 0) === (int)($item['room_group_id'] ?? 0)
                    ) $labelsForSource = [...$labelsForSource, trim((string)($record['name'] ?? '')), trim((string)($record['room_type'] ?? ''))];
                foreach ($labelsForSource as $sourceLabel) {
                    if ($sourceLabel !== '' && mb_strtolower($sourceLabel, 'UTF-8') === $labelLower) $tied = true;
                }
            }
        }
        if (!$tied) return $reject('entity_identity');
    }
    // When the reply names an exact room, facts from a different room cannot
    // ride along merely because both rooms share a generic type such as
    // "Standard Room". Multi-room comparisons remain valid when each exact
    // title is named and each source is cited independently.
    foreach ($verified as $claim) {
        if (in_array($claim['field'], ['result_count', 'guest_count', 'check_in', 'check_out', 'nights'], true)) continue;
        if (str_starts_with($claim['source_id'], 'recommendation-') && $mentionedRecommendationIds
            && !isset($mentionedRecommendationIds[$claim['source_id']])) return $reject('claim_room_mismatch');
        if (!str_starts_with($claim['source_id'], 'recommendation-') && $mentionedCatalogIds
            && isset($recordById[$claim['source_id']]) && ($recordById[$claim['source_id']]['kind'] ?? null) === 'venue'
            && !isset($mentionedCatalogIds[$claim['source_id']])) return $reject('claim_room_mismatch');
    }

    // A numeric value needs an exact evidence value; units must match the
    // cited field so capacity cannot be recast as beds or vice versa.
    $numberWords = ['one' => '1', 'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'ten' => '10',
        'isa' => '1', 'dalawa' => '2', 'tatlo' => '3', 'apat' => '4', 'lima' => '5', 'anim' => '6', 'pito' => '7', 'walo' => '8', 'siyam' => '9', 'sampu' => '10'];
    $dateMatches = [];
    if (preg_match_all('/\b\d{4}-\d{2}-\d{2}\b/', $clean, $dateMatches)) {
        $allowedDates = array_filter([$state['slots']['start_date'] ?? null, $state['slots']['end_date'] ?? null,
            $state['recommendation_snapshot']['check_in'] ?? null, $state['recommendation_snapshot']['check_out'] ?? null]);
        foreach ($dateMatches[0] as $date) if (!in_array($date, $allowedDates, true)) return $reject('unsupported_date');
    }
    $allowedDates = array_values(array_unique(array_filter([$state['slots']['start_date'] ?? null, $state['slots']['end_date'] ?? null,
        $state['recommendation_snapshot']['check_in'] ?? null, $state['recommendation_snapshot']['check_out'] ?? null])));
    $spokenDates = receptionist_knowledge_booking_dates($clean);
    $compactRangePattern = '/\b(january|jan|february|feb|march|mar|april|apr|may|june|jun|july|jul|august|aug|september|sep|sept|october|oct|november|nov|december|dec)\.?\s+(\d{1,2})(?:st|nd|rd|th)?\s+(?:to|through|until|[-–])\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?\b/iu';
    if (preg_match_all($compactRangePattern, $clean, $compactRanges, PREG_SET_ORDER)) {
        $todayManila = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
        foreach ($compactRanges as $range) {
            $firstDate = receptionist_knowledge_parse_month_date($range[1] . ' ' . $range[2] . (empty($range[4]) ? '' : ' ' . $range[4]), $todayManila);
            $secondDate = receptionist_knowledge_parse_month_date($range[1] . ' ' . $range[3] . (empty($range[4]) ? '' : ' ' . $range[4]), $todayManila);
            if ($firstDate === null || $secondDate === null || !in_array($firstDate, $allowedDates, true) || !in_array($secondDate, $allowedDates, true)) {
                return $reject('unsupported_date');
            }
            $expectedCheckIn = $state['recommendation_snapshot']['check_in'] ?? $state['slots']['start_date'] ?? null;
            $expectedCheckOut = $state['recommendation_snapshot']['check_out'] ?? $state['slots']['end_date'] ?? null;
            if (is_string($expectedCheckIn) && is_string($expectedCheckOut)
                && ($firstDate !== $expectedCheckIn || $secondDate !== $expectedCheckOut)) return $reject('unsupported_date');
            $spokenDates[] = $firstDate;
            $spokenDates[] = $secondDate;
        }
    }
    $spokenDates = array_values(array_unique($spokenDates));
    foreach ($spokenDates as $date) if (!in_array($date, $allowedDates, true)) return $reject('unsupported_date');
    $withoutDates = preg_replace('/\b\d{4}-\d{2}-\d{2}\b/', ' ', $clean) ?? $clean;
    $withoutDates = preg_replace('/\b(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\.?\s+\d{1,2}(?:st|nd|rd|th)?(?:\s*(?:to|through|until|[-–])\s*\d{1,2}(?:st|nd|rd|th)?)?(?:,?\s+\d{4})?\b/iu', ' ', $withoutDates) ?? $withoutDates;
    if (preg_match_all('/[₱$]\s*([\d,]+(?:\.\d{1,2})?)/u', $withoutDates, $moneyMatches, PREG_OFFSET_CAPTURE)) {
        $moneyValues = [];
        foreach ($verified as $claim) if (in_array($claim['field'], ['base_rate', 'overnight_rate', 'rate', 'estimated_total'], true)
            && preg_match_all('/[₱$]?\s*([\d,]+(?:\.\d{1,2})?)/u', $claim['value'], $valueMatches)) {
            foreach ($valueMatches[1] as $value) $moneyValues[] = receptionist_natural_numeric_value($value);
        }
        $replyAmounts = array_map(static fn(array $match): ?string => receptionist_natural_numeric_value($match[0]), $moneyMatches[1]);
        $verifiedReplyPrice = (bool)array_intersect($replyAmounts, $moneyValues);
        preg_match_all('/[₱$]\s*([\d,]+(?:\.\d{1,2})?)/u', $message, $userMoneyMatches);
        $userAmounts = array_map('receptionist_natural_numeric_value', $userMoneyMatches[1] ?? []);
        foreach ($moneyMatches[1] as [$amount, $offset]) {
            $normalizedAmount = receptionist_natural_numeric_value($amount);
            if (in_array($normalizedAmount, $moneyValues, true)) continue;
            $beforeAmount = substr($withoutDates, max(0, $offset - 56), min(56, $offset));
            $isNegatedQuote = preg_match('/\b(?:not|doesn.t|isn.t|do not|does not|is not)\b[^.;!?]{0,48}$/iu', $beforeAmount) === 1
                && in_array($normalizedAmount, $userAmounts, true) && $verifiedReplyPrice;
            if (!$isNegatedQuote) return $reject('unsupported_price');
        }
        $withoutDates = preg_replace('/[₱$]\s*[\d,]+(?:\.\d{1,2})?/u', ' ', $withoutDates) ?? $withoutDates;
    }
    if (preg_match('/\b(?:cheaper|less expensive|more expensive|mas mura|mas mahal)\b/i', $clean) === 1
        && preg_match('/\b(?:cheaper|less expensive|more expensive|mas mura|mas mahal)\b/i', $message) === 1) {
        $targetVenueId = (int)($proposal['target_venue_id'] ?? 0);
        $targetGroupId = (int)($proposal['target_room_group_id'] ?? 0);
        $target = null;
        foreach ($state['recommendation_snapshot']['results'] ?? [] as $item) if ((int)($item['venue_id'] ?? 0) === $targetVenueId
            && (int)($item['room_group_id'] ?? 0) === $targetGroupId) { $target = $item; break; }
        $snapshot = $state['recommendation_snapshot'] ?? [];
        $stayPrice = ($snapshot['pricing_basis'] ?? null) === 'full_stay';
        $targetCost = is_array($target) ? ($stayPrice ? ($target['estimated_total'] ?? null) : ($target['estimated_nightly_amount'] ?? $target['rate_value'] ?? null)) : null;
        $otherCosts = [];
        foreach ($snapshot['results'] ?? [] as $item) {
            if ((int)($item['venue_id'] ?? 0) === $targetVenueId && (int)($item['room_group_id'] ?? 0) === $targetGroupId) continue;
            $cost = $stayPrice ? ($item['estimated_total'] ?? null) : ($item['estimated_nightly_amount'] ?? $item['rate_value'] ?? null);
            if (is_numeric($cost)) $otherCosts[] = (float)$cost;
        }
        if (!is_numeric($targetCost) || !$otherCosts) return $reject('unverifiable_comparison');
        $isCheaper = (float)$targetCost < min($otherCosts);
        $assertsCheaper = preg_match('/\b(?:cheaper|less expensive|mas mura)\b/i', $clean) === 1
            && preg_match('/\b(?:not|isn.t|is not)\s+(?:the\s+)?(?:cheaper|less expensive)|\b(?:not cheaper|hindi mas mura|di mas mura)\b/i', $clean) !== 1;
        $assertsMoreExpensive = preg_match('/\b(?:more expensive|mas mahal)\b/i', $clean) === 1;
        if (($assertsCheaper && !$isCheaper) || ($assertsMoreExpensive && $isCheaper)) return $reject('incorrect_comparison');
    }
    if (preg_match_all('/\b(\d{1,4}|one|two|three|four|five|six|seven|eight|nine|ten|isa|dalawa|tatlo|apat|lima|anim|pito|walo|siyam|sampu)\b/iu', $withoutDates, $numberMatches, PREG_OFFSET_CAPTURE)) {
        foreach ($numberMatches[0] as [$rawNumber, $offset]) {
            $normalized = $numberWords[strtolower($rawNumber)] ?? receptionist_natural_numeric_value($rawNumber);
            if ($normalized === null) return $reject('unsupported_number');
            // PREG_OFFSET_CAPTURE returns byte offsets. Prefer the unit after
            // the number so a preceding room name cannot misclassify "3 guests"
            // as a count of rooms.
            // Bind a number only to the noun immediately following it. A
            // later word such as "beds" must not recast "four guests".
            $afterNumber = substr($withoutDates, $offset + strlen($rawNumber), 20);
            $beforeNumber = substr($withoutDates, max(0, $offset - 20), min(20, $offset));
            $unit = null;
            if (preg_match('/^\s*(?:(?:maximum|max)\s*)?(?:beds?|kama|kamao)\b/i', $afterNumber)) $unit = 'beds';
            elseif (preg_match('/^\s*(?:(?:maximum|max)\s*)?(?:guests?|people|persons?|pax|bisita|tao|katao)\b/i', $afterNumber)) $unit = 'guests';
            elseif (preg_match('/^\s*(?:rooms?|options?|matches|choices?)\b/i', $afterNumber)) $unit = 'results';
            elseif (preg_match('/^\s*(?:nights?|days?)\b/i', $afterNumber)) $unit = 'duration';
            elseif (preg_match('/\b(?:beds?|kama|kamao)\s*(?:of|:)?\s*$/i', $beforeNumber)) $unit = 'beds';
            elseif (preg_match('/\b(?:guests?|people|persons?|pax|bisita|tao|katao)\s*(?:of|:)?\s*$/i', $beforeNumber)) $unit = 'guests';
            elseif (preg_match('/\b(?:rooms?|options?|matches|choices?)\s*(?:of|:)?\s*$/i', $beforeNumber)) $unit = 'results';
            elseif (preg_match('/\b(?:nights?|days?)\s*(?:of|:)?\s*$/i', $beforeNumber)) $unit = 'duration';
            $supported = false;
            foreach ($verified as $claim) {
                $claimNumbers = [];
                if (preg_match_all('/\d{1,4}(?:,\d{3})*(?:\.\d+)?/', $claim['value'], $claimMatches)) foreach ($claimMatches[0] as $n) $claimNumbers[] = receptionist_natural_numeric_value($n);
                if (in_array($normalized, $claimNumbers, true)) {
                    if ($unit === 'beds' && !in_array($claim['field'], ['beds', 'bed_count_min', 'bed_count_max'], true)) continue;
                    if ($unit === 'guests' && !in_array($claim['field'], ['capacity', 'capacity_base', 'capacity_max', 'capacity_styles', 'reason'], true)) continue;
                    if ($unit === 'results' && $claim['field'] !== 'result_count') continue;
                    if ($unit === 'duration' && $claim['field'] !== 'nights') continue;
                    $supported = true;
                }
            }
            if ($unit === 'results' && (string)($state['recommendation_snapshot']['total_matches'] ?? '') === $normalized) $supported = true;
            if ($unit === 'guests' && (string)($state['slots']['group_size'] ?? '') === $normalized) $supported = true;
            if (!$supported) return $reject($unit === 'beds' ? 'unsupported_bed_count' : ($unit === 'guests' ? 'unsupported_capacity' : 'unsupported_number'));
        }
    }

    $amenityTerms = ['jacuzzi', 'hot tub', 'breakfast', 'swimming pool', 'pool', 'wi-fi', 'wifi', 'parking', 'air conditioning', 'kitchen', 'balcony', 'cancellation', 'refund', 'spa', 'airport pickup', 'airport transfer', 'shuttle service'];
    foreach ($amenityTerms as $term) {
        if (!str_contains($replyLower, $term)) continue;
        $quotedTerm = preg_quote($term, '/');
        $denialPattern = '/\b(?:do\s+not|don[\'’]t|does\s+not|doesn[\'’]t|not|no|without|unavailable|not\s+(?:provided|offered|included))\b.{0,70}\b' . $quotedTerm . '\b/iu';
        $postfixDenialPattern = '/\b' . $quotedTerm . '\b.{0,70}\b(?:(?:is|are)\s+)?(?:not\s+(?:available|provided|offered|included)|unavailable|isn[\'’]t\s+(?:available|provided|offered|included))\b/iu';
        $replyDeniesFeature = preg_match($denialPattern, $clean) === 1 || preg_match($postfixDenialPattern, $clean) === 1;
        $assertsFeature = !$replyDeniesFeature
            && preg_match('/\b(?:has|have|includes?|offers?|features?|provides?|comes with|with|free|complimentary)\b.{0,70}\b' . $quotedTerm . '\b/iu', $clean) === 1;
        if (!$assertsFeature && !$replyDeniesFeature) continue;
        $supported = false;
        $explicitlyFree = false;
        $replyClaimsFree = preg_match('/\b(?:free|complimentary|no charge|at no (?:extra|additional) cost)\b.{0,50}\b' . $quotedTerm . '\b/iu', $clean) === 1;
        foreach ($verified as $claim) {
            $claimValue = mb_strtolower($claim['value'], 'UTF-8');
            if (preg_match('/\b' . $quotedTerm . '\b/iu', $claimValue) !== 1
                || !(in_array($claim['field'], ['amenities', 'inclusions'], true)
                    || (in_array($proposal['knowledge_property'] ?? null, ['policy', 'faq_answer'], true) && in_array($claim['field'], ['answer', 'text'], true)))) continue;
            $claimDeniesFeature = preg_match($denialPattern, $claimValue) === 1 || preg_match($postfixDenialPattern, $claimValue) === 1;
            if ($replyDeniesFeature ? $claimDeniesFeature : !$claimDeniesFeature) {
                $supported = true;
                if (preg_match('/\b(?:free|complimentary|no charge|at no (?:extra|additional) cost)\b.{0,50}\b' . $quotedTerm . '\b/iu', $claimValue) === 1) $explicitlyFree = true;
            }
        }
        if ($replyClaimsFree && !$explicitlyFree) $supported = false;
        if (!$supported) return $reject('unsupported_feature');
    }

    if (preg_match('/\b(?:why|reason|recommended|best fit)\b/i', $message)
        && is_array($state['recommendation_snapshot'] ?? null)
        && !array_filter($verified, static fn(array $claim): bool => $claim['field'] === 'reason')) return $reject('missing_recommendation_reason');
    if (preg_match('/\b(?:why|reason|best fit)\b/i', $message) && is_array($state['recommendation_snapshot'] ?? null)) {
        $explains = preg_match('/\b(?:because|since|as|reason|dahil|kasi|kaya|ayon sa)\b/i', $clean) === 1;
        $repeatsReason = false;
        foreach ($verified as $claim) if ($claim['field'] === 'reason'
            && str_contains($replyLower, mb_strtolower($claim['value'], 'UTF-8'))) $repeatsReason = true;
        if (!$explains && !$repeatsReason) return $reject('reason_not_explained');
    }
    if (receptionist_knowledge_intent($message)['kind'] === 'availability' && is_array($state['recommendation_snapshot'] ?? null)
        && !array_filter($verified, static fn(array $claim): bool => $claim['field'] === 'availability')) return $reject('missing_availability');
    return trim($clean);
}

function receptionist_natural_category_evidence(string $message): ?string
{
    $exact = receptionist_natural_category($message);
    if ($exact !== null) return $exact;
    $tokens = preg_split('/[^\p{L}\p{N}]+/u', strtolower($message), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($tokens as $token) {
        foreach (['hotel' => 'Hotel Room', 'room' => 'Hotel Room', 'kwarto' => 'Hotel Room', 'silid' => 'Hotel Room', 'tulugan' => 'Hotel Room',
            'villa' => 'Resort Villa', 'bilya' => 'Resort Villa', 'event' => 'Event Hall', 'venue' => 'Event Hall', 'hall' => 'Event Hall', 'bulwagan' => 'Event Hall'] as $needle => $category) {
            if (strlen($token) >= 4 && abs(strlen($token) - strlen($needle)) <= 1 && levenshtein($token, $needle) <= 1) return $category;
            // The common "hotle" transposition should preserve the hotel
            // intent through a short answer such as "2" on the next turn.
            if ($needle === 'hotel' && $token === 'hotle') return 'Hotel Room';
        }
    }
    return null;
}

function receptionist_natural_group_count_values(string $message, array $state): array
{
    $words = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'eleven' => 11, 'twelve' => 12, 'dalawa' => 2, 'tatlo' => 3, 'apat' => 4, 'lima' => 5, 'anim' => 6, 'pito' => 7, 'walo' => 8, 'siyam' => 9, 'sampu' => 10];
    $number = '(?:\\d{1,4}|' . implode('|', array_keys($words)) . ')';
    $toInt = static function (string $value) use ($words): ?int {
        $value = strtolower(trim($value));
        if (ctype_digit($value)) return (int)$value;
        return $words[$value] ?? null;
    };
    $values = [];
    $pending = ($state['pending_question'] ?? null) === 'group_size';
    if ($pending && preg_match('/\\A(?:actually\\s+)?(?:we are |there are |for )?(' . $number . ')(?:\\s+(?:people|persons|guests|guest|pax|bisita))?[.!]?\\z/iu', trim($message), $match)) {
        $value = $toInt($match[1]);
        if ($value !== null && $value >= 1 && $value <= 10000) $values[] = $value;
    }
    $contextPattern = '/\\b(?:party|group)\\s+of\\s+(' . $number . ')\\b|\\b(' . $number . ')\\s+(?:guests?|people|persons|pax|travelers?|bisita|tao|katao)\\b|\\b(?:we are|we\\x27re|there are|kami|tayo)\\s+(' . $number . ')\\b/iu';
    if (preg_match_all($contextPattern, $message, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) foreach (array_slice($match, 1) as $candidate) if ($candidate !== '') {
            $value = $toInt($candidate);
            if ($value !== null && $value >= 1 && $value <= 10000) $values[] = $value;
        }
    }
    $taglishCountPattern = '/\\b(' . $number . ')\\s+(?:kami|tayo)\\b/iu';
    if (preg_match_all($taglishCountPattern, $message, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $value = $toInt($match[1]);
            if ($value !== null && $value >= 1 && $value <= 10000) $values[] = $value;
        }
    }
    if (isset($state['slots']['group_size']) && preg_match('/\\A(?:actually|instead|make it|correction)\\s+(' . $number . ')[.!]?\\z/iu', trim($message), $match)) {
        $value = $toInt($match[1]);
        if ($value !== null && $value >= 1 && $value <= 10000) $values[] = $value;
    }
    return array_values(array_unique($values));
}

function receptionist_natural_group_count_evidence(string $message, array $state, ?int $expected = null): bool
{
    $values = receptionist_natural_group_count_values($message, $state);
    return $expected === null ? $values !== [] : in_array($expected, $values, true);
}

/** Apply one clearly stated party-size correction without depending on a model parse. */
function receptionist_natural_local_group_count_patch(string $message, array $state): ?array
{
    if (!in_array($state['slots']['intent'] ?? null, ['Hotel Room', 'Event Hall', 'Resort Villa'], true)
        || !isset($state['slots']['group_size'])) return null;
    $explicitCategory = receptionist_natural_category_evidence($message);
    if ($explicitCategory !== null && $explicitCategory !== $state['slots']['intent']) return null;
    $values = receptionist_natural_group_count_values($message, $state);
    if (count($values) !== 1 || $values[0] === (int)$state['slots']['group_size']) return null;

    if (receptionist_natural_preference_evidence($message, $state['pending_question'] ?? null) !== null
        || receptionist_natural_parse_room_type_choice($message) !== null
        || receptionist_knowledge_booking_dates($message) !== []) return null;
    $correctionCue = preg_match('/\\b(?:what if|paano kung|instead|actually|same dates?|same stay|change (?:it )?to|make it|we are|we\\x27re|there are|kami|tayo|party of|group of)\\b/i', $message) === 1;
    if (!$correctionCue) return null;
    $capacityQuestion = preg_match('/\\b(?:capacity|fit|accommodat|how many|maximum|max|kasya|ilang)\\b/i', $message) === 1;
    if ($capacityQuestion && preg_match('/\\b(?:what if|paano kung|instead|actually|change (?:it )?to|make it|we are|we\\x27re|there are|kami|tayo|party of|group of)\\b/i', $message) !== 1) return null;

    return ['slots_patch' => ['group_size' => $values[0]], 'clear_slots' => [], 'kind' => 'booking'];
}

function receptionist_natural_date_evidence(string $message, array $state, string $field, string $value): bool
{
    if (!in_array($field, ['start_date', 'end_date'], true)) return false;
    $dates = receptionist_knowledge_booking_dates($message);
    if (!$dates) return false;
    $checkOut = preg_match('/\b(?:check[ -]?out|checkout|departure|until|through|hanggang)\b/i', $message) === 1;
    $checkIn = preg_match('/\b(?:check[ -]?in|checkin|arrival|from|simula)\b/i', $message) === 1;
    if ($field === 'start_date') {
        if ($checkOut && !$checkIn && count($dates) === 1) return false;
        return $dates[0] === $value;
    }
    if (count($dates) >= 2) return $dates[count($dates) - 1] === $value;
    return count($dates) === 1 && (($state['pending_question'] ?? null) === 'end_date' || ($checkOut && !$checkIn)) && $dates[0] === $value;
}

/** Apply only a clearly stated hotel availability search using the trusted slot parsers. */
function receptionist_natural_local_availability_patch(string $message, array $state): ?array
{
    if (receptionist_knowledge_property_from_message($message) !== 'availability'
        || preg_match('/\b(?:price|prices|rates?|cost|how\s+much|capacity|fit|amenit\w*|pool|wifi|parking|breakfast|spa|airport|shuttle|address|location|directions?|policy|policies|cancel\w*|refund|payment|pets?|children|kids|rules|catering|outside)\b/i', $message) === 1) {
        return null;
    }

    $monthName = '(?:january|jan|february|feb|march|mar|april|apr|may|june|jun|july|jul|august|aug|september|sep|sept|october|oct|november|nov|december|dec)';
    $relativeDateCue = '/\b(?:today|tomorrow|bukas|yesterday|kahapon|(?:this|next)\s+(?:week|weekend|month|year|' . $monthName . '|sun(?:day)?|mon(?:day)?|t(?:ue|ues|uesday)|wed(?:nesday)?|thu(?:r|rs|sday|rsday)?|fri(?:day)?|sat(?:urday)?)|(?:this\s+)?(?:sun(?:day)?|mon(?:day)?|t(?:ue|ues|uesday)|wed(?:nesday)?|thu(?:r|rs|sday|rsday)?|fri(?:day)?|sat(?:urday)?)|in\s+\d{1,3}\s+(?:days?|weeks?|months?))\b/i';
    if (preg_match($relativeDateCue, $message) === 1) return null;
    $explicitDatePattern = '/\b(?:\d{4}-\d{2}-\d{2}|\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}|' . $monthName . '\s+\d{1,2}(?:st|nd|rd|th)?(?:,?\s+\d{4})?|\d{1,2}\s+' . $monthName . '(?:\s+\d{4})?)\b/i';
    $explicitDateCount = preg_match_all($explicitDatePattern, $message);
    $explicitDateRangeCount = preg_match_all('/\b' . $monthName . '\s+\d{1,2}\s*[-–]\s*\d{1,2}(?:,?\s+\d{4})?\b/i', $message);
    $dates = receptionist_knowledge_booking_dates($message, new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')));
    $twoExplicitDates = $explicitDateRangeCount === 1
        ? $explicitDateCount === 1
        : $explicitDateRangeCount === 0 && $explicitDateCount === 2;
    if (!$twoExplicitDates || count($dates) !== 2 || $dates[0] >= $dates[1]
        || receptionist_natural_preference_evidence($message, $state['pending_question'] ?? null) !== null
        || receptionist_natural_parse_room_type_choice($message) !== null
        || receptionist_natural_room_type_clarification_requested($message)) {
        return null;
    }

    $category = receptionist_natural_category_evidence($message);
    $mentionsOtherCategory = preg_match('/\b(?:villa|bilya|event|hall|venue|bulwagan)\b/i', $message) === 1;
    if ($category !== null && ($category !== 'Hotel Room' || $mentionsOtherCategory)) return null;

    $slots = is_array($state['slots'] ?? null) ? $state['slots'] : [];
    $focused = is_array($state['focused_room'] ?? null) ? $state['focused_room'] : [];
    $focusedVenueId = filter_var($focused['venue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $focusedGroupId = filter_var($focused['room_group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $hasValidatedHotelFocus = ($slots['intent'] ?? null) === 'Hotel Room'
        && $focusedVenueId !== false && $focusedGroupId !== false
        && (int)($slots['active_venue_id'] ?? 0) === (int)$focusedVenueId
        && (int)($slots['active_room_group_id'] ?? 0) === (int)$focusedGroupId;
    if ($category !== 'Hotel Room' && !$hasValidatedHotelFocus) return null;

    $counts = receptionist_natural_group_count_values($message, $state);
    if (count($counts) > 1) return null;
    $intentChanges = isset($slots['intent']) && $slots['intent'] !== 'Hotel Room';
    $groupSize = $counts[0] ?? ($intentChanges ? null : ($slots['group_size'] ?? null));
    if ($groupSize === null || (int)$groupSize < 1 || (int)$groupSize > 10000) return null;
    if ($category === 'Hotel Room' && !isset($slots['group_size']) && $counts === []) return null;

    $slotsPatch = ['start_date' => $dates[0], 'end_date' => $dates[1]];
    if ($category === 'Hotel Room') $slotsPatch['intent'] = 'Hotel Room';
    if ($counts) $slotsPatch['group_size'] = $counts[0];

    return ['slots_patch' => $slotsPatch, 'clear_slots' => [], 'kind' => 'availability'];
}

function receptionist_natural_safe_social_reply(string $reply, string $language): string
{
    $clean = receptionist_faq_text($reply, 240);
    return $clean !== null && receptionist_ai_social_reply_is_safe($clean) ? $clean : receptionist_ai_safe_social_fallback($language);
}

function receptionist_natural_nonfactual_reply(string $reply): ?string
{
    $clean = receptionist_faq_text($reply, 180);
    if ($clean === null || str_contains($clean, '?') || preg_match('/(?:https?:\/\/|www\.|₱|\b\d|\b(?:available|availability|capacity|accommodat|sleeps|bed|beds|rate|rates|price|prices|cost|costs|include|includes|amenit|pool|breakfast|cancel|cancellation|policy|policies|check[ -]?in|checkout)\b)/iu', $clean) === 1) return null;
    return $clean;
}

function receptionist_natural_render_segments(array $segments, array $records, array $state, array $proposal, string $language): ?string
{
    $textAllowed = receptionist_natural_connectors();
    $allowedFields = ['name', 'room_type', 'base_rate', 'overnight_rate', 'capacity_base', 'capacity_max', 'capacity_styles', 'bed_count_min', 'bed_count_max', 'amenities', 'inclusions', 'description', 'question', 'answer', 'text', 'capacity', 'beds', 'rate', 'reason', 'availability'];
    $rendered = '';
    $factCount = 0;
    foreach (array_slice($segments, 0, 12) as $segment) {
        if (!is_array($segment)) return null;
        if (($segment['kind'] ?? null) === 'text') {
            $text = is_string($segment['text'] ?? null) ? $segment['text'] : '';
            if (!in_array($text, $textAllowed, true)) return null;
            $rendered .= $text;
            continue;
        }
        if (($segment['kind'] ?? null) !== 'fact' || !in_array($segment['field'] ?? null, $allowedFields, true) || !is_string($segment['source_id'] ?? null)) return null;
        $sourceId = $segment['source_id'];
        $field = $segment['field'];
        $value = null;
        if (str_starts_with($sourceId, 'recommendation-')) {
            foreach ($state['recommendation_snapshot']['results'] ?? [] as $item) {
                $expectedId = 'recommendation-' . (int)($item['venue_id'] ?? 0) . '-' . (int)($item['room_group_id'] ?? 0);
                if ($sourceId !== $expectedId) continue;
                $targetVenue = filter_var($proposal['target_venue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $targetGroupRaw = $proposal['target_room_group_id'] ?? null;
                $targetGroup = $state['recommendation_snapshot']['intent'] === 'Hotel Room'
                    ? filter_var($targetGroupRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                    : (in_array($targetGroupRaw, [null, 0, '0'], true) ? 0 : false);
                if ($targetVenue !== (int)$item['venue_id'] || $targetGroup !== (int)$item['room_group_id']) return null;
                $value = match ($field) {
                    'name' => (string)($item['title'] ?? ''),
                    'capacity' => (string)($item['capacity'] ?? ''),
                    'beds' => (string)($item['beds'] ?? ''),
                    'rate' => (string)($item['rate'] ?? ''),
                    'reason' => (string)($item['reasons'][0]['value'] ?? ''),
                    'availability' => (string)($item['status'] ?? ''),
                    'description' => (string)($item['description'] ?? ''),
                    'amenities' => implode(', ', is_array($item['amenities'] ?? null) ? $item['amenities'] : []),
                    default => null,
                };
                break;
            }
        } else {
            if (($proposal['knowledge_id'] ?? null) !== $sourceId) return null;
            $selectedProperty = $proposal['knowledge_property'] ?? null;
            $allowedForProperty = match ($selectedProperty) {
                'price' => ['base_rate', 'overnight_rate'],
                'overnight_price' => ['overnight_rate'],
                'capacity' => ['capacity_base', 'capacity_max', 'capacity_styles'],
                'amenities' => ['amenities', 'inclusions'],
                'description' => ['description', 'name', 'room_type'],
                'faq_answer', 'policy', 'contact', 'location', 'event_options' => ['answer', 'text', 'name', 'question'],
                default => [],
            };
            if (!in_array($field, $allowedForProperty, true)) return null;
            foreach ($records as $record) {
                if (($record['id'] ?? null) !== $sourceId) continue;
                $value = match ($field) {
                    'name' => (string)($record['name'] ?? ''),
                    'room_type' => (string)($record['room_type'] ?? ''),
                    'base_rate' => isset($record['base_rate']) ? '₱' . number_format((float)$record['base_rate'], 2) . ' ' . (string)($record['rate_unit'] ?? '') : null,
                    'overnight_rate' => isset($record['overnight_rate']) ? '₱' . number_format((float)$record['overnight_rate'], 2) . ' per night' : null,
                    'capacity_base' => isset($record['capacity_base']) ? 'base capacity ' . (int)$record['capacity_base'] . ' guests' : null,
                    'capacity_max' => isset($record['capacity_max']) ? 'up to ' . (int)$record['capacity_max'] . ' guests' : null,
                    'capacity_styles' => is_array($record['capacity_styles'] ?? null) ? implode(', ', array_map(static fn($label, $number): string => $label . ': ' . (int)$number, array_keys($record['capacity_styles']), array_values($record['capacity_styles']))) : null,
                    'bed_count_min', 'bed_count_max' => isset($record[$field]) ? (int)$record[$field] . ' beds' : null,
                    'amenities' => is_array($record['amenities'] ?? null) ? implode(', ', $record['amenities']) : null,
                    'inclusions' => is_array($record['inclusions'] ?? null) ? implode(', ', $record['inclusions']) : null,
                    'description' => (string)($record['description'] ?? ''),
                    'question' => (string)($record['question'] ?? ''),
                    'answer' => (string)($record['answer'] ?? ''),
                    'text' => (string)($record['text'] ?? ''),
                    default => null,
                };
                break;
            }
        }
        if (!is_string($value) || trim($value) === '') return null;
        $rendered .= $value;
        $factCount++;
    }
    $rendered = trim($rendered);
    return $factCount > 0 && $rendered !== '' && mb_strlen($rendered, 'UTF-8') <= 1400 ? $rendered : null;
}

function receptionist_natural_log(string $requestId, array $result, int $promptBytes, int $latencyMs): void
{
    $diagnostic = is_array($result['diagnostic'] ?? null) ? $result['diagnostic'] : [];
    $safe = ['request_id' => $requestId, 'provider' => receptionist_ai_sanitize_provider_error_code(receptionist_ai_env('AI_PROVIDER', 'openrouter')) ?? 'unknown',
        'model' => receptionist_ai_sanitize_provider_error_code(receptionist_ai_env('AI_MODEL')) ?? 'unknown',
        'fallback_class' => !empty($result['success']) ? null : (string)($result['error_class'] ?? 'provider_unavailable'),
        'latency_ms' => $latencyMs, 'attempt_count' => $diagnostic['attempt_count'] ?? null, 'prompt_bytes' => $promptBytes,
        'response_bytes' => $diagnostic['response_bytes'] ?? null, 'http_status' => $diagnostic['http_status'] ?? null];
    error_log('receptionist_natural ' . json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function receptionist_natural_reply_log(string $requestId, string $replyType, string $groundingStatus, int $providerCallCount, ?string $fallbackClass = null, ?string $groundingReason = null): void
{
    $safe = ['request_id' => $requestId, 'reply_type' => $replyType, 'grounding_status' => $groundingStatus,
        'provider_call_count' => max(0, min(2, $providerCallCount)), 'fallback_class' => $fallbackClass,
        'grounding_reason' => in_array($groundingReason, ['reply_shape', 'claim_shape', 'claim_value', 'entity_identity', 'claim_room_mismatch', 'availability_not_checked', 'unsupported_date', 'unsupported_price', 'unsupported_bed_count', 'unsupported_capacity', 'unsupported_number', 'unsupported_feature', 'missing_factual_claims', 'missing_recommendation_reason', 'reason_not_explained', 'missing_availability', 'unverifiable_comparison', 'incorrect_comparison', 'language_mismatch'], true) ? $groundingReason : null];
    error_log('receptionist_natural_reply ' . json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function receptionist_natural_emit_reply_log(array $dependencies, string $requestId, string $replyType, string $groundingStatus, int $providerCallCount, ?string $fallbackClass = null, ?string $groundingReason = null): void
{
    $metadata = ['request_id' => $requestId, 'reply_type' => $replyType, 'grounding_status' => $groundingStatus,
        'provider_call_count' => max(0, min(2, $providerCallCount)), 'fallback_class' => $fallbackClass,
        'grounding_reason' => $groundingReason];
    if (is_callable($dependencies['reply_log'] ?? null)) {
        try { ($dependencies['reply_log'])($metadata); } catch (Throwable $error) { /* Logging must not interrupt a turn. */ }
        return;
    }
    receptionist_natural_reply_log($requestId, $replyType, $groundingStatus, $providerCallCount, $fallbackClass, $groundingReason);
}

function receptionist_natural_finish(array $state, string $message, string $reply, string $presentation, array $extra, ?string $errorCode, string $requestId, bool $incrementTurn = true, bool $recordTurn = true): array
{
    $state['revision'] = (int)$state['revision'] + 1;
    if ($incrementTurn) $state['turn_count']++;
    if ($recordTurn) $state['history'] = receptionist_natural_append_history($state['history'], $message, $reply);
    $_SESSION['receptionist_natural_state'] = $state;
    $response = ['success' => true, 'mode' => 'natural', 'reply' => $reply, 'presentation' => $presentation,
        'revision' => $state['revision'], 'natural_state' => receptionist_natural_public_state($state), 'request_id' => $requestId];
    if ($errorCode !== null) {
        $metadata = receptionist_ai_fallback_metadata($errorCode);
        $response['fallback_code'] = $metadata['code'];
        $response['retryable'] = $metadata['retryable'];
    }
    return [array_merge($response, $extra), 200];
}

/** Process a single typed message through the stateful hybrid path. */
function receptionist_natural_handle_turn(mysqli $conn, array $request, string $message, string $language, array $catalog, string $requestId, array $dependencies = []): array
{
    $clock = is_callable($dependencies['clock'] ?? null) ? $dependencies['clock'] : static fn(): float => microtime(true);
    $rawState = is_array($_SESSION['receptionist_natural_state'] ?? null) ? $_SESSION['receptionist_natural_state'] : [];
    $state = is_callable($dependencies['state_loader'] ?? null)
        ? ($dependencies['state_loader'])($rawState, $conn, $catalog)
        : receptionist_natural_state($rawState, $conn, $catalog);
    $expected = filter_var($request['expected_revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($expected === false || $expected !== $state['revision']) {
        return [['success' => false, 'code' => 'stale_state', 'mode' => 'natural', 'reply' => receptionist_natural_local_reply('stale', $language),
            'revision' => $state['revision'], 'natural_state' => receptionist_natural_public_state($state), 'request_id' => $requestId], 409];
    }
    $rateAllowed = static function (string $bucket, int $max, int $seconds) use ($conn, $dependencies): bool {
        if (is_callable($dependencies['rate_limit'] ?? null)) return (bool)($dependencies['rate_limit'])($bucket, $max, $seconds);
        try { return check_rate_limit($conn, $bucket, $max, $seconds); } catch (Throwable $error) { return false; }
    };
    $faqs = is_callable($dependencies['faq_loader'] ?? null) ? ($dependencies['faq_loader'])($conn) : receptionist_faq_load($conn);
    $records = is_array($dependencies['records'] ?? null) ? $dependencies['records']
        : (is_callable($dependencies['record_loader'] ?? null) ? ($dependencies['record_loader'])($conn, $faqs) : receptionist_public_knowledge_records($conn, $faqs));
    if ($state['revision'] === 0 && $state['slots'] === [] && is_array($request['context'] ?? null)) {
        try { $state['slots'] = is_callable($dependencies['validate_slots'] ?? null)
            ? ($dependencies['validate_slots'])($request['context'], [], $catalog, [])
            : receptionist_ai_validate_slots($conn, $request['context'], [], $catalog); } catch (Throwable $error) { $state['slots'] = []; }
        if (($state['slots']['intent'] ?? null) === 'Hotel Room' && !isset($state['slots']['preference'])) $state['slots']['preference'] = 'best_fit';
    }
    $state = receptionist_natural_restore_selected_context($state, $request, $catalog);

    if (preg_match('/\A(?:start over|reset|restart|new conversation|simulan ulit)\b/i', trim($message))) {
        $fresh = receptionist_natural_empty_state();
        $fresh['revision'] = $state['revision'];
        $reply = match ($language) { 'fil' => 'Sige, magsimula tayo ulit. Ano ang maitutulong ko?', 'taglish' => 'Sige, start tayo ulit. What can I help you with?', default => 'Let’s start fresh. What can I help you with?' };
        return receptionist_natural_finish($fresh, $message, $reply, 'keep', ['slots' => [], 'validated_slots' => [], 'missing_slots' => ['intent'], 'reset_context' => true], null, $requestId);
    }

    $actionId = is_string($request['action_id'] ?? null) ? $request['action_id'] : null;
    $localPatch = receptionist_natural_guided_context_patch($request, $state)
        ?? receptionist_natural_local_action_patch($actionId)
        ?? receptionist_natural_local_availability_patch($message, $state)
        ?? receptionist_natural_local_pending_patch($message, $state)
        ?? receptionist_natural_local_group_count_patch($message, $state);
    $requestRoomType = ($localPatch['request_room_type'] ?? false) === true;
    if (($localPatch['clarify_room_type'] ?? false) === true
        || (($localPatch['request_room_type'] ?? false) === true && ($localPatch['guided_sync'] ?? false) !== true)) {
        $state['pending_question'] = 'room_type_code';
        $labels = array_map(static fn(string $code): string => hotel_room_type_label($code) ?? '', array_keys(hotel_fixed_room_types()));
        $labels[] = 'Any room type';
        $reply = match ($language) {
            'fil' => 'Aling published room type ang gusto mong i-filter, kung may partikular kang gusto? Maaari ring anumang room type ang hanapin.',
            'taglish' => 'May specific published room type ka bang gustong i-filter? Puwede ring any room type.',
            default => 'Which published room type would you like to filter for? You can also keep any room type.',
        };
        return receptionist_natural_finish($state, $message, $reply, 'keep', ['slots' => $state['slots'], 'validated_slots' => $state['slots'],
            'missing_slots' => ['room_type_code'], 'quick_replies' => $labels], null, $requestId);
    }
    if (($localPatch['invalid_date'] ?? false) === true) {
        $key = (string)($state['pending_question'] ?? 'start_date');
        return receptionist_natural_finish($state, $message, receptionist_natural_local_reply('invalid_date', $language, $state['slots']['intent'] ?? null), 'show_dates',
            ['slots' => $state['slots'], 'validated_slots' => $state['slots'], 'missing_slots' => [$key]], null, $requestId);
    }

    $availabilityQuestion = (receptionist_knowledge_intent($message)['kind'] ?? null) === 'availability';
    $availabilityRequested = $availabilityQuestion;

    $providerError = null;
    $providerCallCount = 0;
    $initialGroundedReplyAccepted = false;
    $groundingRejected = false;
    $groundingReason = null;
    $provider = null;
    $providerDeadline = 0.0;
    $providerLimits = null;
    if ($localPatch === null) {
        if ($state['provider_turn_count'] >= 25) {
            return receptionist_natural_finish($state, $message, receptionist_ai_guided_message($language, 'visit_limit'), 'keep',
                ['slots' => $state['slots'], 'validated_slots' => $state['slots']], 'visit_limit', $requestId);
        }
        $provider = is_callable($dependencies['provider_factory'] ?? null) ? ($dependencies['provider_factory'])() : receptionist_ai_provider();
        if (!$provider) $providerError = 'provider_unavailable';
        elseif (!$rateAllowed('receptionist_chat_provider', 30, 10)) $providerError = 'busy';
        else {
            $knowledgeCandidates = receptionist_knowledge_model_candidates($records, $message, $state['slots'], 8, $state['focused_faq_id']);
            $messages = [
                ['role' => 'system', 'content' => receptionist_natural_system_prompt($state, $knowledgeCandidates, $language)],
                ['role' => 'user', 'content' => $message],
            ];
            $promptBytes = strlen((string)json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $started = (float)$clock();
            try {
                $limits = is_array($dependencies['provider_limits'] ?? null) ? $dependencies['provider_limits'] : receptionist_ai_limits();
                $providerLimits = $limits;
                $providerDeadline = $started + 12.0;
                $timeout = min(10, max(1, (int)$limits['timeout']));
                $naturalOutputTokens = min(1600, max(1400, (int)$limits['tokens']));
                $schema = receptionist_natural_provider_schema($knowledgeCandidates, $state);
                // Only model-bound turns consume the AI visit allowance. Local
                // pending answers, reset, stale refreshes, and rate-limit
                // responses leave this counter unchanged.
                $state['provider_turn_count']++;
                $providerCallCount++;
                if (method_exists($provider, 'completeWithSchema')) $result = $provider->completeWithSchema($messages, $naturalOutputTokens, $timeout, $schema, 'sevilla_receptionist_natural_response', 1);
                else $result = $provider->complete($messages, $naturalOutputTokens, $timeout);
            } catch (Throwable $error) {
                $result = ['success' => false, 'error_class' => 'provider_unavailable'];
            }
            receptionist_natural_log($requestId, $result, $promptBytes, (int)round(((float)$clock() - $started) * 1000));
            if (empty($result['success']) || !is_array($result['payload'] ?? null)) $providerError = (string)($result['error_class'] ?? 'provider_unavailable');
            else {
                try {
                    $proposal = receptionist_natural_validate_proposal($result['payload'], $state, $catalog, $message);
                    receptionist_natural_proposal_observe($dependencies, 'interpretation', $result['payload'], $proposal);
                }
                catch (Throwable $error) { $providerError = 'provider_schema'; }
            }
        }
        if ($providerError !== null) {
            $failureMetadata = receptionist_ai_fallback_metadata($providerError);
            if (($failureMetadata['retryable'] ?? false) === true) {
                // Do not turn an unavailable model call into an unrelated
                // booking follow-up based on stale session context. The
                // canonical outage reply is also recognizable after history
                // restoration, so the client can offer a manual retry.
                $reply = receptionist_natural_local_reply('outage', $language);
            } else {
                $fallback = is_callable($dependencies['fallback'] ?? null)
                    ? ($dependencies['fallback'])($records, $message, $language, $state['slots'], $state['history'], $state['focused_faq_id'])
                    : receptionist_knowledge_local_fallback($records, $message, $language, $state['slots'], $state['history'], $state['focused_faq_id']);
                $reply = is_array($fallback) ? (string)($fallback['reply'] ?? '') : receptionist_natural_local_reply('outage', $language);
            }
            receptionist_natural_emit_reply_log($dependencies, $requestId, 'local_fallback', 'unavailable', $providerCallCount, $providerError);
            return receptionist_natural_finish($state, $message, $reply, 'keep',
                ['slots' => $state['slots'], 'validated_slots' => $state['slots']], $providerError, $requestId);
        }
    } else {
        $proposal = receptionist_natural_validate_proposal($localPatch + ['kind' => 'booking'], $state, $catalog, $message,
            ($localPatch['guided_sync'] ?? false) === true);
    }

    $kind = (string)($proposal['kind'] ?? 'booking');
    $previous = $state['slots'];
    $previousSelectedRoom = null;
    $focusedRoom = is_array($state['focused_room'] ?? null) ? $state['focused_room'] : null;
    $focusedVenueId = filter_var($focusedRoom['venue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $focusedGroupId = filter_var($focusedRoom['room_group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (($previous['intent'] ?? null) === 'Hotel Room'
        && $focusedVenueId !== false && $focusedGroupId !== false
        && (int)($previous['active_venue_id'] ?? 0) === (int)$focusedVenueId
        && (int)($previous['active_room_group_id'] ?? 0) === (int)$focusedGroupId
        && receptionist_ai_catalog_venue($catalog, (int)$focusedVenueId, 'Hotel Room', (int)$focusedGroupId) !== null) {
        $previousSelectedRoom = ['venue_id' => (int)$focusedVenueId, 'room_group_id' => (int)$focusedGroupId];
    }
    $previousPending = $state['pending_question'] ?? null;
    $rawPatch = is_array($proposal['slots_patch'] ?? null) ? $proposal['slots_patch'] : [];
    $clear = is_array($proposal['clear_slots'] ?? null) ? $proposal['clear_slots'] : [];
    if (isset($rawPatch['intent']) && isset($previous['intent']) && $rawPatch['intent'] !== $previous['intent']) {
        $clear = array_values(array_unique([...$clear, 'occasion', 'purpose', 'group_size', 'preference', 'room_type_code', 'start_date', 'end_date', 'active_venue_id', 'active_room_group_id']));
    }
    $validationBase = $previous;
    foreach ($clear as $key) unset($validationBase[$key]);
    $invalidDatePatch = isset($rawPatch['start_date']) ? 'start_date' : (isset($rawPatch['end_date']) ? 'end_date' : null);
    try { $state['slots'] = is_callable($dependencies['validate_slots'] ?? null)
        ? ($dependencies['validate_slots'])($rawPatch, $validationBase, $catalog, $clear)
        : receptionist_ai_validate_slots($conn, $rawPatch, $validationBase, $catalog, $clear); }
    catch (Throwable $error) {
        $state['slots'] = $previous;
        $rawPatch = [];
        $clear = [];
        if ($invalidDatePatch !== null) $state['pending_question'] = $invalidDatePatch;
    }
    if (($state['slots']['intent'] ?? null) === 'Hotel Room' && !isset($state['slots']['preference'])) $state['slots']['preference'] = 'best_fit';
    if (($state['slots']['intent'] ?? null) === 'Hotel Room' && !isset($state['slots']['room_type_code']) && !$requestRoomType) {
        $state['slots']['room_type_code'] = 'any';
    }
    $priorityChoiceExplicit = ($state['slots']['intent'] ?? null) === 'Hotel Room'
        && receptionist_natural_preference_evidence($message, is_string($previousPending) ? $previousPending : null) !== null;
    $groupCountChanged = isset($state['slots']['group_size'])
        && (int)($previous['group_size'] ?? 0) !== (int)$state['slots']['group_size'];
    $priorityPromptDue = ($state['slots']['intent'] ?? null) === 'Hotel Room' && $groupCountChanged
        && !isset($state['slots']['start_date']) && !isset($state['slots']['end_date'])
        && !$priorityChoiceExplicit && !$requestRoomType && $invalidDatePatch === null;
    $availabilityRequested = $availabilityRequested || $kind === 'availability'
        || ($proposal['knowledge_property'] ?? null) === 'availability';
    $freshAvailabilityChecked = false;
    $selectedRoomOutcome = null;
    $namedAvailability = $availabilityRequested ? receptionist_natural_named_availability_target($records, $message, $state['slots']) : ['target' => null, 'ambiguous' => false];
    if (is_array($namedAvailability['target'] ?? null) && !isset($state['slots']['intent'])) {
        $state['slots']['intent'] = (string)$namedAvailability['target']['category'];
        if ($state['slots']['intent'] === 'Hotel Room') $state['slots']['preference'] = 'best_fit';
    }
    $criteriaChanged = receptionist_natural_criteria_signature($previous) !== receptionist_natural_criteria_signature($state['slots']);
    $selectionChanged = ($previous['intent'] ?? null) !== ($state['slots']['intent'] ?? null)
        || ($previous['room_type_code'] ?? null) !== ($state['slots']['room_type_code'] ?? null);
    if ($criteriaChanged) {
        $state['recommendation_snapshot'] = null;
        if ($selectionChanged) {
            $state['focused_room'] = null;
            unset($state['slots']['active_venue_id'], $state['slots']['active_room_group_id']);
        }
        $proposal['reference'] = null;
    }

    // A model may correctly understand a comparison or follow-up but label
    // it as a booking turn and omit the exact target ids. Recover only when
    // the existing, unchanged snapshot resolves the reference unambiguously.
    $kind = (string)($proposal['kind'] ?? 'booking');
    if (!$criteriaChanged && empty($proposal['slots_patch']) && receptionist_natural_reference_cue($message)
        && in_array($kind, ['booking', 'question', 'reference'], true)) {
        $resolvedReference = $proposal['reference'] ?? receptionist_natural_expected_reference($state, $message);
        if (is_array($resolvedReference)) {
            $proposal['reference'] = $resolvedReference;
            if ($kind === 'booking') $kind = 'reference';
            $proposal['kind'] = $kind;
        }
    }

    $answer = null;
    $knowledgeCandidates ??= receptionist_knowledge_model_candidates($records, $message, $state['slots'], 8, $state['focused_faq_id']);
    $recommendationWhy = is_array($proposal['reference'] ?? null)
        && is_array($state['recommendation_snapshot'] ?? null)
        && preg_match('/\b(?:why|reason|best fit|recommend)\b/i', $message) === 1
        && !in_array($proposal['knowledge_property'] ?? null, ['faq_answer', 'policy'], true);
    if (!$recommendationWhy && ($kind === 'question' || receptionist_knowledge_is_fact_request($message, $state['slots']))
        && is_string($proposal['knowledge_id'] ?? null) && is_string($proposal['knowledge_property'] ?? null)) {
        $selectionProperty = $proposal['knowledge_property'];
        // FAQ policy answers use their published FAQ record even when the
        // classifier calls the topic "policy". Keep actual policy documents
        // on the policy path; only translate when the selected record is an FAQ.
        foreach ($records as $candidateRecord) {
            if (($candidateRecord['id'] ?? null) === $proposal['knowledge_id'] && ($candidateRecord['kind'] ?? null) === 'faq') {
                $selectionProperty = 'faq_answer';
                break;
            }
        }
        $answer = receptionist_knowledge_compose_selection($records, $knowledgeCandidates, $proposal['knowledge_id'], $selectionProperty, $language, $state['slots'], $message);
    }
    $firstGroundedReply = receptionist_natural_grounded_reply((string)($proposal['social_reply'] ?? ''), $proposal['claims'] ?? [], $records, $state, $proposal, $message, $groundingReason);
    if ($firstGroundedReply !== null && !receptionist_natural_reply_matches_language($firstGroundedReply, $language)) {
        $firstGroundedReply = null;
        $groundingReason = 'language_mismatch';
    }
    if ($firstGroundedReply !== null) $groundingReason = null;
    $reply = $firstGroundedReply ?? '';
    $initialGroundedReplyAccepted = $firstGroundedReply !== null;
    if (trim((string)($proposal['social_reply'] ?? '')) !== '' && $firstGroundedReply === null) $groundingRejected = true;
    $quickReplies = [];
    $showSupportContactCta = false;
    if (is_array($proposal['reference'] ?? null)) {
        $item = $proposal['reference'];
        $state['slots']['active_venue_id'] = (int)$item['venue_id'];
        if (($item['category'] ?? null) === 'Hotel Room' && (int)($item['room_group_id'] ?? 0) > 0) {
            $state['focused_room'] = ['venue_id' => (int)$item['venue_id'], 'room_group_id' => (int)$item['room_group_id']];
            $state['slots']['active_room_group_id'] = (int)$item['room_group_id'];
        } else unset($state['slots']['active_room_group_id']);
    }
    if (is_array($answer)) {
        if (is_string($answer['faq_id'] ?? null)) $state['focused_faq_id'] = $answer['faq_id'];
        if ($reply === '') $reply = (string)($answer['reply'] ?? '');
    } elseif (is_array($proposal['reference'] ?? null)) {
        $item = $proposal['reference'];
        $state['slots']['active_venue_id'] = (int)$item['venue_id'];
        if (($item['category'] ?? null) === 'Hotel Room' && (int)($item['room_group_id'] ?? 0) > 0) {
            $state['focused_room'] = ['venue_id' => (int)$item['venue_id'], 'room_group_id' => (int)$item['room_group_id']];
            $state['slots']['active_room_group_id'] = (int)$item['room_group_id'];
        } else unset($state['slots']['active_room_group_id']);
        if ($reply === '') $reply = receptionist_natural_snapshot_reply($item, $message, $language, $state['recommendation_snapshot'] ?? []);
    } elseif ($kind === 'social' || receptionist_knowledge_is_social_input($message)) {
        if ($reply === '' && !receptionist_knowledge_is_fact_request($message, $state['slots'])) {
            $reply = receptionist_natural_safe_social_reply((string)($proposal['social_reply'] ?? ''), $language);
        }
    } elseif ($kind === 'ambiguous') {
        if ($reply === '') $reply = match ($language) { 'fil' => 'Alin dito ang ibig mong sabihin?', 'taglish' => 'Alin dito ang ibig mong sabihin?', default => 'Which option did you mean?' };
    } elseif ($kind === 'reference') {
        if ($reply === '') $reply = match ($language) { 'fil' => 'Aling option ang gusto mong tingnan?', 'taglish' => 'Aling option ang gusto mong tingnan?', default => 'Which option would you like me to look at?' };
        $quickReplies = array_values(array_slice(array_unique(array_map(static fn(array $item): string => (string)($item['title'] ?? ''), $state['recommendation_snapshot']['results'] ?? [])), 0, 3));
    } elseif ($kind === 'question' && ($proposal['knowledge_property'] ?? null) === 'unknown') {
        if ($reply === '' && !receptionist_knowledge_is_fact_request($message, $state['slots'])) {
            $reply = receptionist_natural_local_reply('unknown', $language);
        }
    }

    if ($reply === '' && receptionist_knowledge_is_fact_request($message, $state['slots'])) {
        $unsupportedFallback = receptionist_knowledge_local_fallback(
            $records, $message, $language, $state['slots'], $state['history'], $state['focused_faq_id']
        );
        if (is_array($unsupportedFallback)) {
            $reply = (string)($unsupportedFallback['reply'] ?? receptionist_natural_local_reply('unknown', $language));
            $showSupportContactCta = ($unsupportedFallback['show_support_contact_cta'] ?? false) === true;
            if (is_string($unsupportedFallback['faq_id'] ?? null)) $state['focused_faq_id'] = $unsupportedFallback['faq_id'];
            if (!$quickReplies && is_array($unsupportedFallback['quick_replies'] ?? null)) $quickReplies = $unsupportedFallback['quick_replies'];
        } else {
            $reply = receptionist_natural_local_reply('unknown', $language);
        }
    }

    $intent = $state['slots']['intent'] ?? null;
    $pending = null;
    $missing = [];
    $presentation = 'keep';
    if ($invalidDatePatch !== null && $rawPatch === []) {
        $pending = $invalidDatePatch;
        $missing[] = $pending;
        $presentation = 'show_dates';
        $state['pending_question'] = $pending;
        $reply = receptionist_natural_local_reply('invalid_date', $language);
    }
    if ($availabilityRequested) {
        if (!empty($namedAvailability['ambiguous'])) {
            $reply = $intent === 'Hotel Room'
                ? match ($language) { 'fil' => 'Aling eksaktong room type ang gusto mong ipa-check?', 'taglish' => 'Aling exact room type ang iche-check ko?', default => 'Which exact room type should I check availability for?' }
                : match ($language) { 'fil' => 'Aling venue ang gusto mong ipa-check?', 'taglish' => 'Aling venue ang iche-check ko?', default => 'Which venue should I check availability for?' };
            $presentation = 'keep';
        } elseif (is_array($namedAvailability['target'] ?? null)) {
            if ($intent === 'Hotel Room' && (!isset($state['slots']['start_date']) || !isset($state['slots']['end_date']))) {
                $pending = !isset($state['slots']['start_date']) ? 'start_date' : 'end_date';
                $missing[] = $pending;
                $presentation = 'show_dates';
            } elseif ($intent !== 'Hotel Room' && !isset($state['slots']['start_date'])) {
                $pending = 'start_date'; $missing[] = $pending; $presentation = 'show_dates';
            } else $presentation = 'show_results';
        } elseif ($intent === 'Hotel Room' && (!isset($state['slots']['group_size']) || !isset($state['slots']['start_date']) || !isset($state['slots']['end_date']))) {
            $pending = !isset($state['slots']['group_size']) ? 'group_size' : (!isset($state['slots']['start_date']) ? 'start_date' : 'end_date');
            $missing[] = $pending;
            $presentation = $pending === 'group_size' ? 'keep' : 'show_dates';
        } elseif (in_array($intent, ['Event Hall', 'Resort Villa'], true) && !isset($state['slots']['group_size']) && !isset($state['slots']['start_date'])) {
            // For an untargeted date question, ask for the date first; party
            // size is needed only if the guest wants fit-ranked options.
            $pending = 'start_date'; $missing[] = $pending; $presentation = 'show_dates';
        } elseif (in_array($intent, ['Event Hall', 'Resort Villa'], true) && !isset($state['slots']['group_size'])) {
            $pending = 'group_size'; $missing[] = $pending;
        } elseif (in_array($intent, ['Event Hall', 'Resort Villa'], true) && !isset($state['slots']['start_date'])) {
            $pending = 'start_date'; $missing[] = $pending; $presentation = 'show_dates';
        } elseif ($intent !== null) $presentation = 'show_results';
    } elseif ($kind === 'booking' || $criteriaChanged) {
        if ($intent === null) { $pending = 'intent'; $missing[] = $pending; }
        elseif (!isset($state['slots']['group_size'])) { $pending = 'group_size'; $missing[] = $pending; }
        elseif ($intent === 'Hotel Room' && $requestRoomType && !isset($state['slots']['room_type_code'])) { $pending = 'room_type_code'; $missing[] = $pending; }
        elseif ($intent === 'Hotel Room' && $priorityPromptDue) { $pending = 'preference'; $missing[] = $pending; }
        elseif ($intent === 'Hotel Room' && !isset($state['slots']['start_date'])) { $pending = 'start_date'; $missing[] = $pending; $presentation = 'show_dates'; }
        elseif ($intent === 'Hotel Room' && isset($state['slots']['start_date']) && !isset($state['slots']['end_date'])) { $pending = 'end_date'; $missing[] = $pending; $presentation = 'show_dates'; }
        elseif (in_array($intent, ['Event Hall', 'Resort Villa'], true) && !isset($state['slots']['start_date'])) { $pending = 'start_date'; $missing[] = $pending; $presentation = 'show_dates'; }
        else $presentation = 'show_results';
    }
    if (is_array($answer) && $presentation === 'keep' && !$criteriaChanged) $presentation = 'keep';
    if ($kind === 'reference' && isset($state['slots']['active_venue_id'])) $presentation = 'show_venue';
    $pendingAnswered = $previousPending;
    if (is_string($previousPending) && isset($state['slots'][$previousPending]) && array_key_exists($previousPending, $rawPatch)) $pendingAnswered = null;
    $state['pending_question'] = $pending ?? (($kind === 'question' || $kind === 'reference' || $kind === 'availability' || $kind === 'social') ? $pendingAnswered : null);
    if ($pending === 'room_type_code') {
        $reply = receptionist_natural_local_reply('room_type_code', $language, $intent);
        $quickReplies = array_map(static fn(string $code): string => hotel_room_type_label($code) ?? '', array_keys(hotel_fixed_room_types()));
        $quickReplies[] = 'Any room type';
    } elseif ($pending === 'preference') {
        $reply = receptionist_natural_local_reply('preference', $language, $intent);
        $quickReplies = ['Best fit', 'Lowest price', 'Comfort', 'No preference'];
    }

    if ($presentation === 'show_results' && is_array($namedAvailability['target'] ?? null)) {
        $target = $namedAvailability['target'];
        $targetVenueId = (int)($target['venue_id'] ?? 0);
        $targetGroupId = (int)($target['room_group_id'] ?? 0);
        $state['slots']['active_venue_id'] = $targetVenueId;
        if ($intent === 'Hotel Room') {
            $state['slots']['active_room_group_id'] = $targetGroupId;
            $state['focused_room'] = ['venue_id' => $targetVenueId, 'room_group_id' => $targetGroupId];
        } else {
            unset($state['slots']['active_room_group_id']);
        }
        try {
            if ($availabilityRequested) $state['recommendation_snapshot'] = null;
            if ($intent === 'Hotel Room') {
                $snapshot = is_callable($dependencies['hotel_exact_search'] ?? null)
                    ? ($dependencies['hotel_exact_search'])($conn, $targetVenueId, $targetGroupId, (string)$state['slots']['start_date'], (string)$state['slots']['end_date'], session_id())
                    : hotel_recommendation_exact_availability($conn, $targetVenueId, $targetGroupId,
                        (string)$state['slots']['start_date'], (string)$state['slots']['end_date'], session_id());
            } else {
                $snapshot = is_callable($dependencies['venue_exact_search'] ?? null)
                    ? ($dependencies['venue_exact_search'])($conn, $intent, $targetVenueId, (string)$state['slots']['start_date'], (string)($state['slots']['end_date'] ?? $state['slots']['start_date']), $records, session_id())
                    : venue_recommendation_exact_availability($conn, $intent, $targetVenueId,
                        (string)$state['slots']['start_date'], (string)($state['slots']['end_date'] ?? $state['slots']['start_date']), $records, session_id());
            }
            $snapshot['criteria_signature'] = receptionist_natural_criteria_signature($state['slots']);
            $state['recommendation_snapshot'] = $snapshot;
            $freshAvailabilityChecked = receptionist_natural_snapshot_has_fresh_availability($snapshot)
                && ($snapshot['check_in'] ?? null) === ($state['slots']['start_date'] ?? null)
                && ($snapshot['check_out'] ?? null) === ($state['slots']['end_date'] ?? $state['slots']['start_date'] ?? null)
                && hash_equals((string)$snapshot['criteria_signature'], receptionist_natural_criteria_signature($state['slots']));
            if ($intent === 'Hotel Room' && isset($state['slots']['group_size'])) {
                $selectedRoomOutcome = receptionist_natural_selected_room_outcome_from_snapshot(
                    $snapshot, $targetVenueId, $targetGroupId, (string)$state['slots']['start_date'],
                    (string)$state['slots']['end_date'], (int)$state['slots']['group_size']);
                if ($selectedRoomOutcome !== null) {
                    $capacity = $selectedRoomOutcome['capacity'];
                    $fits = is_int($capacity) && (int)$state['slots']['group_size'] <= $capacity;
                    if (!$selectedRoomOutcome['available'] || !$fits) {
                        try {
                            $range = receptionist_natural_guest_range((int)$state['slots']['group_size']);
                            if ($range !== null) {
                                $searchRequest = [
                                    'guest_range' => $range, 'group_size' => (int)$state['slots']['group_size'],
                                    'priority' => $state['slots']['preference'] ?? 'best_fit',
                                    'room_type_code' => $state['slots']['room_type_code'] ?? 'any',
                                    'check_in' => $state['slots']['start_date'], 'check_out' => $state['slots']['end_date'],
                                ];
                                $alternatives = is_callable($dependencies['hotel_search'] ?? null)
                                    ? ($dependencies['hotel_search'])($conn, $searchRequest, session_id())
                                    : hotel_recommendation_search($conn, $searchRequest, session_id());
                                $alternatives['intent'] = 'Hotel Room';
                                $alternatives['criteria_signature'] = receptionist_natural_criteria_signature($state['slots']);
                                $alternativesFresh = receptionist_natural_snapshot_has_fresh_availability($alternatives)
                                    && ($alternatives['check_in'] ?? null) === $state['slots']['start_date']
                                    && ($alternatives['check_out'] ?? null) === $state['slots']['end_date'];
                                if ($alternativesFresh) {
                                    $state['recommendation_snapshot'] = $alternatives;
                                    $selectedRoomOutcome['options_checked'] = true;
                                    $selectedRoomOutcome['options_count'] = count($alternatives['results'] ?? []);
                                }
                            }
                        } catch (Throwable $error) {
                            // Keep the exact selected-room result if alternatives cannot be loaded.
                        }
                    } else {
                        $selectedRoomOutcome['options_checked'] = true;
                        $selectedRoomOutcome['options_count'] = count($snapshot['results'] ?? []);
                    }
                }
            }
        } catch (Throwable $error) {
            $providerError = 'recommendation_unavailable';
            if ($availabilityRequested) { $state['recommendation_snapshot'] = null; $presentation = 'keep'; }
        }
    } elseif ($presentation === 'show_results' && isset($state['slots']['group_size'])) {
        if ($intent === 'Hotel Room' && (!$availabilityQuestion || isset($state['slots']['start_date'], $state['slots']['end_date']))) {
            $range = receptionist_natural_guest_range((int)$state['slots']['group_size']);
            if ($range !== null) try {
                if ($availabilityRequested) $state['recommendation_snapshot'] = null;
                $searchRequest = [
                    'guest_range' => $range, 'group_size' => (int)$state['slots']['group_size'],
                    'priority' => $state['slots']['preference'] ?? 'best_fit',
                    'room_type_code' => $state['slots']['room_type_code'] ?? 'any',
                    'check_in' => $state['slots']['start_date'] ?? null, 'check_out' => $state['slots']['end_date'] ?? null,
                ];
                $snapshot = is_callable($dependencies['hotel_search'] ?? null)
                    ? ($dependencies['hotel_search'])($conn, $searchRequest, session_id())
                    : hotel_recommendation_search($conn, $searchRequest, session_id());
                $snapshot['intent'] = 'Hotel Room';
                $snapshot['criteria_signature'] = receptionist_natural_criteria_signature($state['slots']);
                $state['recommendation_snapshot'] = $snapshot;
                $freshAvailabilityChecked = receptionist_natural_snapshot_has_fresh_availability($snapshot)
                    && ($snapshot['check_in'] ?? null) === ($state['slots']['start_date'] ?? null)
                    && ($snapshot['check_out'] ?? null) === ($state['slots']['end_date'] ?? null)
                    && hash_equals((string)$snapshot['criteria_signature'], receptionist_natural_criteria_signature($state['slots']));
                $selectedStillActive = $previousSelectedRoom !== null
                    && (int)($state['slots']['active_venue_id'] ?? 0) === $previousSelectedRoom['venue_id']
                    && (int)($state['slots']['active_room_group_id'] ?? 0) === $previousSelectedRoom['room_group_id'];
                $stayOrPartyChanged = ($previous['group_size'] ?? null) !== ($state['slots']['group_size'] ?? null)
                    || ($previous['start_date'] ?? null) !== ($state['slots']['start_date'] ?? null)
                    || ($previous['end_date'] ?? null) !== ($state['slots']['end_date'] ?? null);
                if ($criteriaChanged && !$selectionChanged && $selectedStillActive && $stayOrPartyChanged
                    && isset($state['slots']['start_date'], $state['slots']['end_date'])) {
                    try {
                        $exactSelected = is_callable($dependencies['hotel_exact_search'] ?? null)
                            ? ($dependencies['hotel_exact_search'])($conn, $previousSelectedRoom['venue_id'],
                                $previousSelectedRoom['room_group_id'], (string)$state['slots']['start_date'],
                                (string)$state['slots']['end_date'], session_id())
                            : hotel_recommendation_exact_availability($conn, $previousSelectedRoom['venue_id'],
                                $previousSelectedRoom['room_group_id'], (string)$state['slots']['start_date'],
                                (string)$state['slots']['end_date'], session_id());
                        $selectedRoomOutcome = receptionist_natural_selected_room_outcome_from_snapshot(
                            $exactSelected, $previousSelectedRoom['venue_id'], $previousSelectedRoom['room_group_id'],
                            (string)$state['slots']['start_date'], (string)$state['slots']['end_date'],
                            (int)($state['slots']['group_size'] ?? 0));
                        if ($selectedRoomOutcome !== null) {
                            $selectedRoomOutcome['options_checked'] = $freshAvailabilityChecked;
                            $selectedRoomOutcome['options_count'] = $freshAvailabilityChecked
                                ? count($snapshot['results'] ?? []) : 0;
                        }
                    } catch (Throwable $error) {
                        // Keep the category search result but do not make a selected-room claim.
                    }
                }
            } catch (Throwable $error) {
                $providerError = 'recommendation_unavailable';
                if ($availabilityRequested) { $state['recommendation_snapshot'] = null; $presentation = 'keep'; }
            }
        } elseif (in_array($intent, ['Event Hall', 'Resort Villa'], true)
            && (!$availabilityQuestion || isset($state['slots']['start_date']))) {
            try {
                if ($availabilityRequested) $state['recommendation_snapshot'] = null;
                $searchArgs = [$conn, $intent, (int)$state['slots']['group_size'], $state['slots']['start_date'] ?? null,
                    $state['slots']['end_date'] ?? ($state['slots']['start_date'] ?? null), $records, session_id()];
                $snapshot = is_callable($dependencies['venue_search'] ?? null)
                    ? ($dependencies['venue_search'])(...$searchArgs)
                    : venue_recommendation_search(...$searchArgs);
                $snapshot['criteria_signature'] = receptionist_natural_criteria_signature($state['slots']);
                $state['recommendation_snapshot'] = $snapshot;
                $freshAvailabilityChecked = receptionist_natural_snapshot_has_fresh_availability($snapshot)
                    && ($snapshot['check_in'] ?? null) === ($state['slots']['start_date'] ?? null)
                    && ($snapshot['check_out'] ?? null) === ($state['slots']['end_date'] ?? $state['slots']['start_date'] ?? null)
                    && hash_equals((string)$snapshot['criteria_signature'], receptionist_natural_criteria_signature($state['slots']));
            } catch (Throwable $error) {
                $providerError = 'recommendation_unavailable';
                if ($availabilityRequested) { $state['recommendation_snapshot'] = null; $presentation = 'keep'; }
            }
        }
    }
    // The first model pass ran before current result data existed. Treat any
    // valid wording there as a pre-search acknowledgment; the reply after a
    // search must come from the grounded result pass or a local result summary.
    if ($presentation === 'show_results' && is_array($state['recommendation_snapshot'])) {
        $reply = '';
        $initialGroundedReplyAccepted = false;
    }
    $wordingReplyAccepted = false;
    if ($presentation === 'show_results' && is_array($state['recommendation_snapshot'])
        && (int)($state['recommendation_snapshot']['total_matches'] ?? 0) > 0
        && empty($state['recommendation_snapshot']['preferred_type_unavailable'])
        && is_object($provider) && method_exists($provider, 'completeWithSchema')
        && $providerDeadline > (float)$clock() && $providerLimits !== null) {
        $remainingSeconds = (int)floor($providerDeadline - (float)$clock());
        if ($remainingSeconds >= 1) {
            $wordingCandidates = receptionist_knowledge_model_candidates($records, $message, $state['slots'], 8, $state['focused_faq_id']);
            $wordingMessages = [
                ['role' => 'system', 'content' => receptionist_natural_system_prompt($state, $wordingCandidates, $language)
                    . "\nThis is a wording pass for the original user message. The validated interpretation is {$kind}. Do not change slots, clear state, start a new search, or ask the booking follow-up; the app will ask one necessary follow-up after your reply. Use the current checked snapshot and cite factual claims with exact source, field, and value."],
                ['role' => 'user', 'content' => $message],
            ];
            $wordingBytes = strlen((string)json_encode($wordingMessages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $wordingStarted = (float)$clock();
            try {
                $wordingSchema = receptionist_natural_provider_schema($wordingCandidates, $state);
                $providerCallCount++;
                $naturalOutputTokens = min(1600, max(1400, (int)$providerLimits['tokens']));
                $wordingResult = $provider->completeWithSchema($wordingMessages, $naturalOutputTokens, min(6, $remainingSeconds),
                    $wordingSchema, 'sevilla_receptionist_natural_response', 1);
            } catch (Throwable $error) {
                $wordingResult = ['success' => false, 'error_class' => 'provider_unavailable'];
            }
            receptionist_natural_log($requestId, $wordingResult, $wordingBytes, (int)round(((float)$clock() - $wordingStarted) * 1000));
            if (!empty($wordingResult['success']) && is_array($wordingResult['payload'] ?? null)) {
                try {
                    $wordingProposal = receptionist_natural_validate_proposal($wordingResult['payload'], $state, $catalog, $message);
                    receptionist_natural_proposal_observe($dependencies, 'wording', $wordingResult['payload'], $wordingProposal);
                    $wordingTargetMatches = !is_array($proposal['reference'] ?? null)
                        || ((int)($wordingProposal['target_venue_id'] ?? 0) === (int)$proposal['reference']['venue_id']
                            && (int)($wordingProposal['target_room_group_id'] ?? 0) === (int)$proposal['reference']['room_group_id']);
                    if ($wordingTargetMatches) {
                        foreach (['knowledge_id', 'knowledge_property', 'target_venue_id', 'target_room_group_id', 'claims', 'social_reply'] as $key) {
                            $proposal[$key] = $wordingProposal[$key] ?? null;
                        }
                        $wordingAnswer = null;
                        if (($kind === 'question' || receptionist_knowledge_is_fact_request($message, $state['slots']))
                            && is_string($proposal['knowledge_id']) && is_string($proposal['knowledge_property'])) {
                            $selectionProperty = $proposal['knowledge_property'];
                            foreach ($records as $candidateRecord) {
                                if (($candidateRecord['id'] ?? null) === $proposal['knowledge_id'] && ($candidateRecord['kind'] ?? null) === 'faq') {
                                    $selectionProperty = 'faq_answer';
                                    break;
                                }
                            }
                            $wordingAnswer = receptionist_knowledge_compose_selection($records, $wordingCandidates, $proposal['knowledge_id'],
                                $selectionProperty, $language, $state['slots'], $message);
                        }
                        $wordingGroundingReason = null;
                        $wordedReply = receptionist_natural_grounded_reply((string)($proposal['social_reply'] ?? ''), $proposal['claims'] ?? [],
                            $records, $state, $proposal, $message, $wordingGroundingReason, $freshAvailabilityChecked);
                        if ($wordedReply !== null && !receptionist_natural_reply_matches_language($wordedReply, $language)) {
                            $wordedReply = null;
                            $wordingGroundingReason = 'language_mismatch';
                        }
                        if ($wordedReply !== null) {
                            $reply = $wordedReply;
                            $groundingReason = null;
                            $wordingReplyAccepted = true;
                            if (is_array($wordingAnswer)) $answer = $wordingAnswer;
                        } elseif (trim((string)($wordingProposal['social_reply'] ?? '')) !== '') {
                            $groundingRejected = true;
                            $groundingReason ??= $wordingGroundingReason;
                        }
                    }
                } catch (Throwable $error) {
                    // Keep the first validated interpretation and its safe local rendering.
                }
            } elseif (is_array($wordingResult['payload'] ?? null)) {
                $groundingRejected = true;
            }
        }
    }
    if ($presentation === 'show_results' && is_array($state['recommendation_snapshot'])
        && !empty($state['recommendation_snapshot']['preferred_type_unavailable'])) {
        $typeLabel = (string)($state['recommendation_snapshot']['requested_room_type'] ?? 'selected room type');
        $reply = match ($language) {
            'fil' => "Walang {$typeLabel} na available at tugma sa bilang ng bisita at petsang ito. Ang mga ipinapakita ay available na alternatibong room type para sa parehong stay.",
            'taglish' => "Walang available na {$typeLabel} na fit sa guests at dates na ito. Ang results sa ibaba ay available na alternative room types for the same stay.",
            default => "No {$typeLabel} room matched your guest count and dates. The rooms shown are available alternatives for the same stay.",
        };
    } elseif ($presentation === 'show_results' && is_array($state['recommendation_snapshot'])
        && (int)($state['recommendation_snapshot']['total_matches'] ?? 0) < 1
        && empty($state['recommendation_snapshot']['exact_target'])) {
        $nearby = is_array($state['recommendation_snapshot']['nearby_dates'] ?? null) ? $state['recommendation_snapshot']['nearby_dates'] : [];
        $startLabel = (string)($state['recommendation_snapshot']['check_in'] ?? '');
        $endLabel = (string)($state['recommendation_snapshot']['check_out'] ?? '');
        $reply = $nearby
            ? match ($language) {
                'fil' => "Walang available na hotel room para sa stay na {$startLabel} hanggang {$endLabel}. Nag-check din ako ng susunod na 7 check-in dates; may aktuwal na room/date alternatives sa ibaba.",
                'taglish' => "Walang hotel room na available for {$startLabel} to {$endLabel}. I checked the next 7 check-in dates and found room/date options that were available when checked below.",
                default => "No hotel rooms are available from {$startLabel} to {$endLabel}. I checked the next 7 check-in dates and found room/date options that were available when searched below.",
            }
            : match ($language) {
                'fil' => "Walang available na hotel room para sa bilang ng bisita at petsang ito, at wala akong nakitang checked alternative sa susunod na 7 check-in dates. Maaari mong baguhin ang petsa o room type.",
                'taglish' => "Walang hotel room na available for these guests and dates, and I found no checked alternative in the next 7 check-in dates. You can change the dates or room type.",
                default => "No hotel rooms are available for these guests and dates, and I found no checked alternative within the next 7 check-in dates. You can change the dates or room type.",
            };
    } elseif ($presentation === 'show_results' && is_array($state['recommendation_snapshot']) && !$wordingReplyAccepted
        && ($kind === 'availability' || $availabilityQuestion)) {
        $targetResults = $state['recommendation_snapshot']['results'] ?? [];
        if (!empty($namedAvailability['target']) && is_array($targetResults[0] ?? null)) {
            $reply = receptionist_natural_snapshot_reply($targetResults[0], $message, $language, $state['recommendation_snapshot']);
        } elseif ((int)($state['recommendation_snapshot']['total_matches'] ?? 0) < 1) {
            $reply = match ($language) {
                'fil' => 'Walang tumugmang available na option sa mga petsang iyon. Maaari nating baguhin ang petsa o detalye.',
                'taglish' => 'Walang matching option for those dates. We can adjust the dates or details.',
                default => 'I couldn’t find a matching option for those dates. I can adjust the dates or details.',
            };
        } else {
            $reply = match ($language) {
                'fil' => 'May nakita akong matching options para sa mga petsang iyon.',
                'taglish' => 'May matching options para sa dates na iyon.',
                default => 'I found matching options for those dates.',
            };
        }
    }
    if ($reply === '') {
        if ($presentation === 'show_dates') $reply = receptionist_natural_local_reply((string)$pending, $language, $intent);
        elseif ($pending !== null) $reply = receptionist_natural_local_reply($pending, $language, $intent);
        elseif ($presentation === 'show_results') $reply = match ($language) {
            'fil' => 'Sige, eto ang mga option para sa detalye mo.',
            'taglish' => 'Sige, here are options based on your details.',
            default => 'Here are options based on those details.',
        };
        else $reply = receptionist_ai_guided_message($language);
    }
    if (!$wordingReplyAccepted && !$initialGroundedReplyAccepted && is_array($answer) && !empty($answer['faq_id'])
        && preg_match('/\b(?:price|rate|cost|how much|₱|php)\b/iu', $message) === 1) {
        $referencedRoom = receptionist_natural_reference($state, $message);
        $listedRate = is_array($referencedRoom) ? trim((string)($referencedRoom['rate'] ?? '')) : '';
        if ($listedRate !== '') {
            $title = (string)($referencedRoom['title'] ?? 'the room');
            $rateSentence = match ($language) {
                'fil' => "Para sa {$title}, ang nakalistang rate ay {$listedRate}.",
                'taglish' => "For {$title}, ang listed rate ay {$listedRate}.",
                default => "For {$title}, the listed rate is {$listedRate}.",
            };
            $reply = rtrim($reply) . ' ' . $rateSentence;
        }
    }
    $followup = $pending ?? $pendingAnswered;
    if ($providerError === 'recommendation_unavailable') $reply = receptionist_natural_local_reply('search_error', $language);
    if ($followup !== null) {
        $reply = receptionist_natural_strip_followup_question($reply, $followup);
        $question = receptionist_natural_local_reply($followup, $language, $intent);
        $reply = trim($reply) === '' ? $question : rtrim($reply) . ' ' . $question;
    }
    if (is_array($selectedRoomOutcome)) {
        $reply = receptionist_natural_selected_room_outcome_reply($selectedRoomOutcome, $language);
        $wordingReplyAccepted = false;
        $initialGroundedReplyAccepted = false;
    }
    $replyType = ($wordingReplyAccepted || $initialGroundedReplyAccepted) ? 'natural'
        : ($groundingRejected ? 'grounded_fallback' : (is_array($answer) ? 'server_answer' : 'local'));
    $groundingStatus = ($wordingReplyAccepted || $initialGroundedReplyAccepted) ? 'accepted'
        : ($groundingRejected ? 'rejected' : 'not_applicable');
    receptionist_natural_emit_reply_log($dependencies, $requestId, $replyType, $groundingStatus, $providerCallCount,
        $groundingRejected && $groundingStatus === 'rejected' ? 'grounding_rejected' : $providerError, $groundingReason);
    $extra = ['slots' => $state['slots'], 'validated_slots' => $state['slots'], 'missing_slots' => $missing, 'clear_slots' => $clear];
    if (is_array($answer)) $extra['answer'] = $answer;
    if (is_array($state['recommendation_snapshot'])) $extra['recommendation_snapshot'] = $state['recommendation_snapshot'];
    if ($quickReplies) $extra['quick_replies'] = $quickReplies;
    if ($showSupportContactCta) $extra['show_support_contact_cta'] = true;
    if (($presentation === 'show_venue') && isset($state['slots']['active_venue_id'])) $extra['action'] = 'venue';
    $guidedSync = $actionId === 'guided_search_update';
    if ($guidedSync) {
        $guidedUserMessage = receptionist_natural_guided_history_label($previous, $state['slots']);
        if ($guidedUserMessage !== null) $extra['guided_user_message'] = $guidedUserMessage;
        return receptionist_natural_finish($state, $guidedUserMessage ?? $message, $reply, $presentation, $extra, $providerError, $requestId, false, $guidedUserMessage !== null);
    }
    return receptionist_natural_finish($state, $message, $reply, $presentation, $extra, $providerError, $requestId);
}
