<?php
declare(strict_types=1);

/**
 * Bounded, public-only knowledge used by the virtual receptionist.
 *
 * This service deliberately selects a small allowlist of venue, pricing,
 * policy, contact, and FAQ fields. It never reads bookings, customers,
 * payment submissions, staff records, or internal financial notes.
 */

const RECEPTIONIST_KNOWLEDGE_CATEGORIES = ['Event Hall', 'Hotel Room', 'Resort Villa'];
const RECEPTIONIST_KNOWLEDGE_MAX_RECORDS = 100;

function receptionist_knowledge_clean_text($value, int $maximum): ?string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) return null;
    $value = trim((string)preg_replace('/\s+/', ' ', $value));
    if (preg_match('/(?:https?:\/\/|www\.)/i', $value) === 1) return null;
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    return $value !== '' && $length <= $maximum ? $value : null;
}

function receptionist_knowledge_positive_number($value): ?float
{
    if (!is_numeric($value)) return null;
    $number = (float)$value;
    return is_finite($number) && $number > 0 && $number < 100000000 ? $number : null;
}

function receptionist_knowledge_positive_int($value): ?int
{
    $number = receptionist_knowledge_positive_number($value);
    return $number !== null && floor($number) === $number ? (int)$number : null;
}

function receptionist_knowledge_money(float $amount): string
{
    return '₱' . number_format($amount, abs($amount - round($amount)) < 0.001 ? 0 : 2);
}

function receptionist_knowledge_amenities($value): array
{
    $values = is_array($value) ? $value : preg_split('/[,;\r\n]+/', (string)$value, -1, PREG_SPLIT_NO_EMPTY);
    $result = [];
    $seen = [];
    foreach ($values ?: [] as $item) {
        $clean = receptionist_knowledge_clean_text($item, 120);
        if ($clean === null) continue;
        $key = strtolower($clean);
        if (isset($seen[$key]) || count($result) >= 10) continue;
        $seen[$key] = true;
        $result[] = $clean;
    }
    return $result;
}

function receptionist_knowledge_public_policy($value): ?string
{
    if (!is_string($value)) return null;
    $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
    $safe = [];
    foreach ($lines as $line) {
        $clean = receptionist_knowledge_clean_text($line, 600);
        if ($clean === null) continue;
        if (preg_match('/processing fee|fee percentage|admin[- ]initiated|force cancellation|snapshotted|configurable fee|internal|staff[- ]only|customer id|account number/i', $clean) === 1) continue;
        $safe[] = $clean;
    }
    $text = receptionist_knowledge_clean_text(implode(' ', $safe), 2400);
    return $text;
}

function receptionist_knowledge_fetch_venue_rows(mysqli $conn): array
{
    $stmt = $conn->prepare("SELECT v.id, v.category, v.name, v.description, v.amenities,
            eh.base_rate AS event_rate, eh.base_capacity AS event_base_capacity, eh.max_capacity AS event_max_capacity,
            eh.capacity_theater, eh.capacity_classroom, eh.capacity_banquet,
            hr.room_type, hr.room_group_id, hr.bed_count, hr.base_capacity AS room_base_capacity,
            hr.max_capacity AS room_max_capacity, hr.nightly_rate,
            vi.day_rate, vi.overnight_rate, vi.base_capacity AS villa_base_capacity,
            vi.max_capacity AS villa_max_capacity, vi.has_private_pool,
            vi.day_stay_inclusions, vi.overnight_stay_inclusions
        FROM venues v
        LEFT JOIN event_halls eh ON eh.venue_id = v.id
        LEFT JOIN hotel_rooms hr ON hr.venue_id = v.id
        LEFT JOIN villas vi ON vi.venue_id = v.id
        WHERE v.status = 'Available' AND v.category IN ('Event Hall', 'Hotel Room', 'Resort Villa')
        ORDER BY v.category, v.name, hr.room_type, hr.room_group_id");
    if (!$stmt || !$stmt->execute()) {
        if ($stmt) $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

function receptionist_knowledge_fetch_settings(mysqli $conn): array
{
    // Keep this allowlist public. Do not broaden it to payment, account, or
    // operational/admin settings without an explicit public-source decision.
    $keys = [
        'event_type_wedding', 'event_type_birthday',
        'catering_silver', 'catering_gold', 'catering_platinum', 'av_setup',
        'biz_name', 'biz_email', 'biz_phone', 'biz_address',
        'biz_policies', 'support_terms', 'support_contact_description',
    ];
    $quoted = implode(', ', array_map(static fn(string $key): string => "'" . $key . "'", $keys));
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ({$quoted})");
    if (!$stmt || !$stmt->execute()) {
        if ($stmt) $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $settings = [];
    while ($row = $result->fetch_assoc()) {
        $key = (string)($row['setting_key'] ?? '');
        if (in_array($key, $keys, true)) $settings[$key] = (string)($row['setting_value'] ?? '');
    }
    $stmt->close();
    return $settings;
}

function receptionist_knowledge_build_records(array $venueRows, array $settings, array $faqs): array
{
    $groups = [];
    foreach ($venueRows as $row) {
        if (!is_array($row)) continue;
        $category = (string)($row['category'] ?? '');
        if (!in_array($category, RECEPTIONIST_KNOWLEDGE_CATEGORIES, true)) continue;
        $venueId = receptionist_knowledge_positive_int($row['id'] ?? null);
        if ($venueId === null) continue;
        $roomType = $category === 'Hotel Room' ? (receptionist_knowledge_clean_text($row['room_type'] ?? '', 80) ?? '') : '';
        $groupId = $category === 'Hotel Room' ? receptionist_knowledge_positive_int($row['room_group_id'] ?? null) : null;
        $key = implode('|', [$category, $venueId, $roomType, $groupId ?? '']);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'id' => 'venue-' . strtolower(str_replace(' ', '-', $category)) . '-' . $venueId . ($groupId !== null ? '-group-' . $groupId : ($roomType !== '' ? '-' . substr(hash('sha256', $roomType), 0, 8) : '')),
                'kind' => 'venue',
                'venue_id' => $venueId,
                'category' => $category,
                'name' => receptionist_knowledge_clean_text($row['name'] ?? '', 150) ?? 'Unnamed venue',
                'room_type' => $roomType,
                'room_group_id' => $groupId,
                'description' => receptionist_knowledge_clean_text($row['description'] ?? '', 400),
                'amenities' => receptionist_knowledge_amenities($row['amenities'] ?? ''),
                'base_rate' => null,
                'rate_unit' => null,
                'overnight_rate' => null,
                'capacity_base' => null,
                'capacity_max' => null,
                'capacity_styles' => [],
                'bed_count_min' => null,
                'bed_count_max' => null,
                'inclusions' => [],
            ];
        }
        $record =& $groups[$key];
        $record['amenities'] = receptionist_knowledge_amenities(array_merge($record['amenities'], receptionist_knowledge_amenities($row['amenities'] ?? '')));
        if ($record['description'] === null) $record['description'] = receptionist_knowledge_clean_text($row['description'] ?? '', 400);
        if ($category === 'Event Hall') {
            $rate = receptionist_knowledge_positive_number($row['event_rate'] ?? null);
            if ($rate !== null) $record['base_rate'] = $record['base_rate'] === null ? $rate : min($record['base_rate'], $rate);
            $record['rate_unit'] = 'per day';
            $max = receptionist_knowledge_positive_int($row['event_max_capacity'] ?? null);
            if ($max !== null) $record['capacity_max'] = $record['capacity_max'] === null ? $max : max($record['capacity_max'], $max);
            $base = receptionist_knowledge_positive_int($row['event_base_capacity'] ?? null);
            if ($base !== null) $record['capacity_base'] = $record['capacity_base'] === null ? $base : min($record['capacity_base'], $base);
            foreach (['Theater' => 'capacity_theater', 'Classroom' => 'capacity_classroom', 'Banquet' => 'capacity_banquet'] as $label => $field) {
                $capacity = receptionist_knowledge_positive_int($row[$field] ?? null);
                if ($capacity !== null) $record['capacity_styles'][$label] = $capacity;
            }
        } elseif ($category === 'Hotel Room') {
            $rate = receptionist_knowledge_positive_number($row['nightly_rate'] ?? null);
            if ($rate !== null) $record['base_rate'] = $record['base_rate'] === null ? $rate : min($record['base_rate'], $rate);
            $record['rate_unit'] = 'per night';
            $base = receptionist_knowledge_positive_int($row['room_base_capacity'] ?? null);
            $max = receptionist_knowledge_positive_int($row['room_max_capacity'] ?? null);
            $beds = receptionist_knowledge_positive_int($row['bed_count'] ?? null);
            if ($base !== null) $record['capacity_base'] = $record['capacity_base'] === null ? $base : min($record['capacity_base'], $base);
            if ($max !== null) $record['capacity_max'] = $record['capacity_max'] === null ? $max : max($record['capacity_max'], $max);
            if ($beds !== null) {
                $record['bed_count_min'] = $record['bed_count_min'] === null ? $beds : min($record['bed_count_min'], $beds);
                $record['bed_count_max'] = $record['bed_count_max'] === null ? $beds : max($record['bed_count_max'], $beds);
            }
        } else {
            $rate = receptionist_knowledge_positive_number($row['day_rate'] ?? null);
            if ($rate !== null) $record['base_rate'] = $record['base_rate'] === null ? $rate : min($record['base_rate'], $rate);
            $record['rate_unit'] = 'per day';
            $overnight = receptionist_knowledge_positive_number($row['overnight_rate'] ?? null);
            if ($overnight !== null) $record['overnight_rate'] = $record['overnight_rate'] === null ? $overnight : min($record['overnight_rate'], $overnight);
            $base = receptionist_knowledge_positive_int($row['villa_base_capacity'] ?? null);
            $max = receptionist_knowledge_positive_int($row['villa_max_capacity'] ?? null);
            if ($base !== null) $record['capacity_base'] = $record['capacity_base'] === null ? $base : min($record['capacity_base'], $base);
            if ($max !== null) $record['capacity_max'] = $record['capacity_max'] === null ? $max : max($record['capacity_max'], $max);
            if ((int)($row['has_private_pool'] ?? 0) === 1) $record['amenities'] = receptionist_knowledge_amenities(array_merge($record['amenities'], ['Private pool']));
            $record['inclusions'] = receptionist_knowledge_amenities(array_merge(
                $record['inclusions'],
                receptionist_knowledge_amenities($row['day_stay_inclusions'] ?? ''),
                receptionist_knowledge_amenities($row['overnight_stay_inclusions'] ?? '')
            ));
        }
        unset($record);
    }

    $records = [];
    foreach ($groups as $record) {
        if ($record['base_rate'] === null) unset($record['base_rate']);
        if ($record['rate_unit'] === null) unset($record['rate_unit']);
        if ($record['overnight_rate'] === null) unset($record['overnight_rate']);
        if ($record['capacity_base'] === null) unset($record['capacity_base']);
        if ($record['capacity_max'] === null) unset($record['capacity_max']);
        if ($record['bed_count_min'] === null) unset($record['bed_count_min']);
        if ($record['bed_count_max'] === null) unset($record['bed_count_max']);
        if ($record['description'] === null) unset($record['description']);
        if ($record['room_type'] === '') unset($record['room_type']);
        if ($record['room_group_id'] === null) unset($record['room_group_id']);
        if (!$record['capacity_styles']) unset($record['capacity_styles']);
        if (!$record['amenities']) unset($record['amenities']);
        if (!$record['inclusions']) unset($record['inclusions']);
        $records[] = $record;
    }

    $dedupedVenues = [];
    foreach ($records as $record) {
        $dedupeKey = implode('|', [
            (string)($record['name'] ?? ''),
            (string)($record['room_type'] ?? ''),
            (string)($record['category'] ?? ''),
            isset($record['base_rate']) ? (string)(float)$record['base_rate'] : '',
        ]);
        if (!isset($dedupedVenues[$dedupeKey])) {
            $record['unit_count'] = 1;
            $dedupedVenues[$dedupeKey] = $record;
            continue;
        }

        $rep =& $dedupedVenues[$dedupeKey];
        $rep['unit_count']++;

        if (isset($record['capacity_base'])) {
            $rep['capacity_base'] = isset($rep['capacity_base'])
                ? min((int)$rep['capacity_base'], (int)$record['capacity_base'])
                : (int)$record['capacity_base'];
        }
        if (isset($record['capacity_max'])) {
            $rep['capacity_max'] = isset($rep['capacity_max'])
                ? max((int)$rep['capacity_max'], (int)$record['capacity_max'])
                : (int)$record['capacity_max'];
        }
        if (isset($record['bed_count_min'])) {
            $rep['bed_count_min'] = isset($rep['bed_count_min'])
                ? min((int)$rep['bed_count_min'], (int)$record['bed_count_min'])
                : (int)$record['bed_count_min'];
        }
        if (isset($record['bed_count_max'])) {
            $rep['bed_count_max'] = isset($rep['bed_count_max'])
                ? max((int)$rep['bed_count_max'], (int)$record['bed_count_max'])
                : (int)$record['bed_count_max'];
        }
        if (isset($record['overnight_rate'])) {
            $rep['overnight_rate'] = isset($rep['overnight_rate'])
                ? min((float)$rep['overnight_rate'], (float)$record['overnight_rate'])
                : (float)$record['overnight_rate'];
        }
        if (!isset($rep['description']) && isset($record['description'])) {
            $rep['description'] = $record['description'];
        }

        $mergedAmenities = receptionist_knowledge_amenities(array_merge($rep['amenities'] ?? [], $record['amenities'] ?? []));
        if ($mergedAmenities) {
            $rep['amenities'] = $mergedAmenities;
        } else {
            unset($rep['amenities']);
        }

        $mergedInclusions = receptionist_knowledge_amenities(array_merge($rep['inclusions'] ?? [], $record['inclusions'] ?? []));
        if ($mergedInclusions) {
            $rep['inclusions'] = $mergedInclusions;
        } else {
            unset($rep['inclusions']);
        }

        if (!empty($record['capacity_styles'])) {
            $rep['capacity_styles'] = $rep['capacity_styles'] ?? [];
            foreach ($record['capacity_styles'] as $style => $count) {
                $rep['capacity_styles'][$style] = max((int)($rep['capacity_styles'][$style] ?? 0), (int)$count);
            }
        }
        unset($rep);
    }
    $records = array_values($dedupedVenues);

    $eventModifiers = [];
    foreach ([
        'event_type_wedding' => ['label' => 'Wedding event fee', 'unit' => 'per event'],
        'event_type_birthday' => ['label' => 'Birthday event fee', 'unit' => 'per event'],
        'catering_silver' => ['label' => 'Silver catering', 'unit' => 'per person'],
        'catering_gold' => ['label' => 'Gold catering', 'unit' => 'per person'],
        'catering_platinum' => ['label' => 'Platinum catering', 'unit' => 'per person'],
        'av_setup' => ['label' => 'A/V setup', 'unit' => 'per event'],
    ] as $key => $meta) {
        $amount = receptionist_knowledge_positive_number($settings[$key] ?? null);
        if ($amount !== null) $eventModifiers[] = ['label' => $meta['label'], 'amount' => $amount, 'unit' => $meta['unit']];
    }
    if ($eventModifiers) {
        $records[] = [
            'id' => 'event-pricing-options',
            'kind' => 'event_pricing',
            'modifiers' => $eventModifiers,
            'qualifier' => 'Event type, catering, and A/V options affect the preliminary estimate; staff confirms the final quotation.',
        ];
    }

    $contact = [];
    foreach (['biz_name' => 'name', 'biz_email' => 'email', 'biz_phone' => 'phone', 'biz_address' => 'address'] as $source => $target) {
        $value = receptionist_knowledge_clean_text($settings[$source] ?? '', 240);
        if ($value !== null) $contact[$target] = $value;
    }
    if ($contact) $records[] = ['id' => 'public-contact', 'kind' => 'contact', 'contact' => $contact];
    foreach (['biz_policies' => 'public-policies', 'support_terms' => 'public-terms', 'support_contact_description' => 'public-contact-guidance'] as $source => $id) {
        $text = receptionist_knowledge_public_policy($settings[$source] ?? '');
        if ($text !== null) {
            $records[] = ['id' => $id, 'kind' => 'policy', 'text' => $text];
        }
    }
    foreach (array_slice($faqs, 0, 50) as $faq) {
        if (!is_array($faq) || !is_string($faq['id'] ?? null) || !is_string($faq['question'] ?? null) || !is_string($faq['answer'] ?? null)) continue;
        $records[] = [
            'id' => $faq['id'], 'kind' => 'faq', 'category' => (string)($faq['category'] ?? 'General'),
            'question' => receptionist_knowledge_clean_text($faq['question'], 240) ?? '',
            'answer' => receptionist_knowledge_clean_text($faq['answer'], 3000) ?? '',
            'phrases' => array_values(array_filter(array_map(static fn($item): ?string => receptionist_knowledge_clean_text($item, 160), is_array($faq['phrases'] ?? null) ? $faq['phrases'] : []))),
        ];
    }
    return array_slice($records, 0, RECEPTIONIST_KNOWLEDGE_MAX_RECORDS);
}

function receptionist_public_knowledge_records(mysqli $conn, array $faqs): array
{
    return receptionist_knowledge_build_records(receptionist_knowledge_fetch_venue_rows($conn), receptionist_knowledge_fetch_settings($conn), $faqs);
}

function receptionist_knowledge_lower(string $value): string
{
    return strtolower(str_replace(['–', '—'], '-', $value));
}

function receptionist_knowledge_tokens(string $message): array
{
    $tokens = preg_split('/[^\p{L}\p{N}]+/u', receptionist_knowledge_lower($message), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $stopWords = ['the', 'and', 'how', 'does', 'what', 'are', 'can', 'for', 'is', 'my', 'with', 'about', 'please', 'may', 'you', 'your', 'this', 'that', 'all', 'ang', 'mga', 'ano', 'paano', 'sa', 'ng', 'para', 'ba', 'may', 'ito', 'ako', 'kami', 'ninyo'];
    return array_values(array_filter($tokens, static fn(string $token): bool => strlen($token) >= 3 && !in_array($token, $stopWords, true)));
}

function receptionist_knowledge_record_text(array $record): string
{
    return receptionist_knowledge_lower(implode(' ', array_map(static fn($value): string => is_array($value) ? implode(' ', $value) : (string)$value, [
        $record['name'] ?? '', $record['category'] ?? '', $record['room_type'] ?? '', $record['description'] ?? '',
        $record['amenities'] ?? [], $record['inclusions'] ?? [], $record['question'] ?? '', $record['answer'] ?? '', $record['phrases'] ?? [],
        $record['text'] ?? '',
    ])));
}

function receptionist_knowledge_record_score(array $record, array $tokens): int
{
    $haystack = receptionist_knowledge_record_text($record);
    $score = 0;
    foreach ($tokens as $token) if (str_contains($haystack, $token)) $score++;
    return $score;
}

function receptionist_knowledge_find_records(array $records, string $kind, array $tokens, ?string $category = null, ?int $venueId = null, ?int $roomGroupId = null, int $limit = 6): array
{
    $matches = [];
    foreach ($records as $record) {
        if (($record['kind'] ?? null) !== $kind) continue;
        if ($category !== null && ($record['category'] ?? null) !== $category) continue;
        if ($venueId !== null && (int)($record['venue_id'] ?? 0) !== $venueId) continue;
        if ($roomGroupId !== null && (int)($record['room_group_id'] ?? 0) !== $roomGroupId) continue;
        $score = receptionist_knowledge_record_score($record, $tokens);
        if ($tokens && $score === 0) continue;
        $matches[] = ['score' => $score, 'record' => $record];
    }
    usort($matches, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
    return array_map(static fn(array $entry): array => $entry['record'], array_slice($matches, 0, max(1, min($limit, 8))));
}

function receptionist_knowledge_faq_matches(array $records, array $tokens, int $limit = 3): array
{
    $matches = [];
    foreach ($records as $record) {
        if (!in_array($record['kind'] ?? null, ['faq', 'policy'], true)) continue;
        $score = receptionist_knowledge_record_score($record, $tokens);
        if ($score > 0) $matches[] = ['score' => $score, 'record' => $record];
    }
    usort($matches, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
    return array_map(static fn(array $entry): array => $entry['record'], array_slice($matches, 0, max(1, min($limit, 3))));
}

function receptionist_knowledge_excerpt(string $value, int $maximum = 420): string
{
    $value = trim($value);
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length <= $maximum) return $value;
    $excerpt = function_exists('mb_substr') ? mb_substr($value, 0, max(1, $maximum - 1), 'UTF-8') : substr($value, 0, max(1, $maximum - 1));
    return rtrim($excerpt) . '…';
}

function receptionist_knowledge_intent(string $message): array
{
    $lower = receptionist_knowledge_lower($message);
    $informationalBooking = preg_match('/\b(how do i|how can i|how to|what is the (?:booking|reservation) process|booking process|steps? to (?:book|reserve)|where can i (?:book|reserve)|paano (?:mag[- ]?book|mag[- ]?reserve|ang proseso)|ano ang proseso)\b/i', $lower) === 1;
    $bookingVerbPattern = '(?:book|reserve|mag[- ]?book|magpa[- ]?book|mag[- ]?reserve|magpa[- ]?reserve|magpareserba|booking|i[- ]?book|ipa[- ]?book|walk[ -]?in)';
    $directBooking = !$informationalBooking && (
        preg_match('/\b(?:i|we|ako|kami|gusto|want|need|help me|pwede|maaari)\b[^.!?\n]{0,48}\b' . $bookingVerbPattern . '\b/i', $lower) === 1
        || preg_match('/\b' . $bookingVerbPattern . '\s+(?:an?\s+)?(?:hotel|room|rooms|villa|event|venue)\b/i', $lower) === 1
        || preg_match('/\b' . $bookingVerbPattern . '\s+(?:for|para\s+sa)?\s*\d+/i', $lower) === 1
        || preg_match('/\b(?:mag[- ]?book|magpa[- ]?reserve|mag[- ]?reserve|magpa[- ]?book|i[- ]?book|ipa[- ]?book|walk[ -]?in)\b/i', $lower) === 1
    );
    if ($directBooking) {
        $category = receptionist_knowledge_category_hint($message);
        return ['kind' => 'booking', 'category' => $category];
    }
    if ($informationalBooking) {
        return ['kind' => 'booking_process', 'category' => receptionist_knowledge_category_hint($message)];
    }
    $policy = preg_match('/\b(booking|reservation|payment|pay|cancel|cancellation|refund|resched|policy|policies|rules|proof|receipt|status|hold|check[- ]?in|check[- ]?out|contact|address|location|where|hours|pwede\s+ba|bawal\s+ba|patakaran|tuntunin|pano|paano|walk.?in|pasok|saan|nasaan|pano\s+pumunta|paano\s+pumunta|direksyon|lokasyon|san\s+kayo)\b/i', $lower) === 1;
    return ['kind' => $policy ? 'policy' : 'other', 'category' => receptionist_knowledge_category_hint($message)];
}

function receptionist_knowledge_select(array $records, string $message, int $limit = 8): array
{
    $tokens = receptionist_knowledge_tokens($message);
    $matches = [];
    foreach ($records as $record) {
        if (!is_array($record)) continue;
        $score = receptionist_knowledge_record_score($record, $tokens);
        if ($score > 0) $matches[] = ['score' => $score, 'record' => $record];
    }
    usort($matches, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
    return array_map(static fn(array $entry): array => $entry['record'], array_slice($matches, 0, max(1, min($limit, 8))));
}

function receptionist_knowledge_category_hint(string $message): ?string
{
    $lower = receptionist_knowledge_lower($message);
    if (preg_match('/\b(event|events|event hall|wedding|birthday|corporate|party|venue)\b/i', $lower)) return 'Event Hall';
    if (preg_match('/\b(hotel|room|rooms|stay|overnight|nightly|check[- ]in)\b/i', $lower)) return 'Hotel Room';
    if (preg_match('/\b(villa|pool|overnight stay)\b/i', $lower)) return 'Resort Villa';
    return null;
}

function receptionist_knowledge_reply(array $records, string $message, string $language = 'en', array $baseSlots = []): ?array
{
    $language = in_array($language, ['en', 'fil', 'taglish'], true) ? $language : 'en';
    $tokens = receptionist_knowledge_tokens($message);
    if (!$tokens) return null;
    $lower = receptionist_knowledge_lower($message);
    $route = receptionist_knowledge_intent($message);
    $category = $route['category'] ?? (receptionist_knowledge_category_hint($message) ?? (($baseSlots['intent'] ?? null) ?: null));
    $prefix = [
        'en' => ['price' => 'Here are our starting rates:', 'capacity' => 'Here\'s the capacity info:', 'amenities' => 'Here\'s what\'s included:', 'faq' => 'Here\'s what I found:', 'contact' => 'Here\'s how to reach us:', 'booking' => 'I can help you start a booking!'],
        'fil' => ['price' => 'Narito ang aming mga starting rates:', 'capacity' => 'Narito ang capacity info:', 'amenities' => 'Narito ang mga kasama:', 'faq' => 'Narito ang nakita ko:', 'contact' => 'Narito kung paano kami maabot:', 'booking' => 'Matutulungan kitang magsimula ng booking!'],
        'taglish' => ['price' => 'Here are our starting rates:', 'capacity' => 'Here\'s the capacity info:', 'amenities' => 'Here\'s what\'s included:', 'faq' => 'Here\'s what I found:', 'contact' => 'Here\'s how to reach us:', 'booking' => 'I can help you start a booking!'],
    ][$language];
    if (($route['kind'] ?? null) === 'booking' && empty($baseSlots['intent'])) {
        $bookingCategory = $category;
        $slots = $bookingCategory !== null ? ['intent' => $bookingCategory] : [];
        $missing = $bookingCategory === 'Hotel Room'
            ? ['group_size', 'start_date', 'end_date', 'preference']
            : ['group_size', 'start_date'];

        $extractedGroupSize = null;
        if (preg_match('/\b(?:for|para sa|kami)\s+(\d{1,3})\b/i', $message, $gm)) {
            $extractedGroupSize = (int)$gm[1];
        } elseif (preg_match('/\b(\d{1,3})\s*(?:pax|persons?|guests?|people|tao|bisita)\b/i', $message, $gm)) {
            $extractedGroupSize = (int)$gm[1];
        } elseif (preg_match('/\b(?:solo|mag-?isa|alone)\b/i', $message)) {
            $extractedGroupSize = 1;
        } elseif (preg_match('/\b(?:kami dalawa|dalawa kami|couple|pair)\b/i', $message)) {
            $extractedGroupSize = 2;
        }

        if ($extractedGroupSize !== null && $extractedGroupSize > 0) {
            $slots['group_size'] = $extractedGroupSize;
            $missing = array_values(array_filter($missing, static fn(string $s): bool => $s !== 'group_size'));
            if ($bookingCategory === 'Hotel Room') {
                $detail = ($language === 'fil')
                    ? "Nakuha ko — {$extractedGroupSize} bisita! Ano ang iyong check-in at check-out dates?"
                    : "Got it — {$extractedGroupSize} guest(s)! What are your check-in and check-out dates?";
            } elseif ($bookingCategory === 'Resort Villa') {
                $detail = ($language === 'fil')
                    ? "Nakuha ko — {$extractedGroupSize} bisita! Anong petsa ang gusto mong i-book para sa villa?"
                    : "Got it — {$extractedGroupSize} guest(s)! What date would you like for the villa booking?";
            } else {
                $detail = ($language === 'fil')
                    ? "Nakuha ko — {$extractedGroupSize} bisita! Anong venue type at anong petsa ang gusto mong i-book?"
                    : "Got it — {$extractedGroupSize} guest(s)! Which venue type and what date would you like to book?";
            }
        } else {
            $detail = $bookingCategory === 'Hotel Room'
                ? ($language === 'fil' ? 'Ilang bisita, check-in date, at check-out date ang kailangan ko; maaari mong idagdag ang room preference pagkatapos.' : 'How many guests are there, and what are your check-in and check-out dates? You can add a room preference next.')
                : ($bookingCategory === 'Resort Villa'
                    ? ($language === 'fil' ? 'Ilang bisita at anong petsa ang gusto mong i-book para sa villa?' : 'How many guests and what date would you like for the villa booking?')
                    : ($language === 'fil' ? 'Anong venue type, ilang bisita, at anong petsa ang gusto mong i-book?' : 'Which venue type, how many guests, and what date would you like to book?'));
        }

        $quickReplies = $bookingCategory === 'Hotel Room'
            ? ['Guest count', 'Check-in date', 'Check-out date', 'Support FAQs']
            : ['Guest count', 'Booking date', 'Event details', 'Support FAQs'];
        if ($extractedGroupSize !== null && $extractedGroupSize > 0) {
            $quickReplies = array_values(array_filter($quickReplies, static fn(string $q): bool => $q !== 'Guest count'));
        }

        return ['mode' => 'knowledge', 'action' => 'ask', 'reply' => $prefix['booking'] . ' ' . $detail, 'faq_id' => null, 'slots' => $slots, 'missing_slots' => $missing, 'quick_replies' => $quickReplies];
    }
    if (($route['kind'] ?? null) === 'booking_process') {
        $bookingCategory = $category;
        $processCopy = [
            'en' => [
                'Hotel Room' => 'For a hotel stay, enter your guest count and check-in/check-out dates, review matching rooms, select one, then continue to the booking page to review and submit.',
                'Event Hall' => 'For an event, choose the event type or occasion, guest count, and date, review a matching hall, then submit an inquiry. Staff finalizes the quotation; no payment is taken when the inquiry is submitted.',
                'Resort Villa' => 'For a villa stay, enter your guest count, date, and stay preference, review a matching villa, then continue to the booking page to review and submit.',
                'generic' => 'Choose Event, Hotel, or Villa, enter the requested details, review the matching option, then continue to the booking page or submit an inquiry.',
            ],
            'fil' => [
                'Hotel Room' => 'Para sa hotel stay, ilagay ang guest count at check-in/check-out dates, i-review ang matching rooms, pumili ng room, at magpatuloy sa booking page para i-review at isumite.',
                'Event Hall' => 'Para sa event, piliin ang event type o occasion, guest count, at date, i-review ang matching hall, at magsumite ng inquiry. Staff ang magfa-finalize ng quotation; walang payment sa inquiry submission.',
                'Resort Villa' => 'Para sa villa stay, ilagay ang guest count, date, at stay preference, i-review ang matching villa, at magpatuloy sa booking page para i-review at isumite.',
                'generic' => 'Pumili ng Event, Hotel, o Villa, ilagay ang requested details, i-review ang match, at magpatuloy sa booking page o magsumite ng inquiry.',
            ],
            'taglish' => [
                'Hotel Room' => 'For a hotel stay, enter your guest count and check-in/check-out dates, review matching rooms, select one, then continue to the booking page to review and submit.',
                'Event Hall' => 'For an event, choose the event type or occasion, guest count, and date, review a matching hall, then submit an inquiry. Staff finalizes the quotation; no payment is taken when the inquiry is submitted.',
                'Resort Villa' => 'For a villa stay, enter your guest count, date, and stay preference, review a matching villa, then continue to the booking page to review and submit.',
                'generic' => 'Choose Event, Hotel, or Villa, enter the requested details, review the matching option, then continue to the booking page or submit an inquiry.',
            ],
        ][$language];
        $reply = $processCopy[$bookingCategory] ?? $processCopy['generic'];
        $slots = $bookingCategory !== null ? ['intent' => $bookingCategory] : [];
        $quickReplies = match ($bookingCategory) {
            'Hotel Room' => ['Guest count', 'Check-in date', 'Check-out date', 'Support FAQs'],
            'Event Hall' => ['Event', 'Guest count', 'Booking date', 'Support FAQs'],
            'Resort Villa' => ['Villa', 'Guest count', 'Booking date', 'Support FAQs'],
            default => ['Event', 'Hotel', 'Villa', 'Support FAQs'],
        };
        return ['mode' => 'knowledge', 'action' => 'ask', 'reply' => $prefix['booking'] . ' ' . $reply, 'faq_id' => null, 'slots' => $slots, 'missing_slots' => [], 'quick_replies' => $quickReplies];
    }
    $venueId = receptionist_knowledge_positive_int($baseSlots['active_venue_id'] ?? null);
    $roomGroupId = receptionist_knowledge_positive_int($baseSlots['active_room_group_id'] ?? null);
    $venues = receptionist_knowledge_find_records($records, 'venue', $tokens, $category, $venueId, $roomGroupId, 6);
    if (!$venues && $venueId !== null) $venues = receptionist_knowledge_find_records($records, 'venue', $tokens, $category, $venueId, null, 6);
    $mentionedVenues = array_values(array_filter($records, static function (array $record) use ($lower, $category, $venueId, $roomGroupId): bool {
        if (($record['kind'] ?? null) !== 'venue') return false;
        if ($category !== null && ($record['category'] ?? null) !== $category) return false;
        if ($venueId !== null && (int)($record['venue_id'] ?? 0) !== $venueId) return false;
        if ($roomGroupId !== null && (int)($record['room_group_id'] ?? 0) !== $roomGroupId) return false;
        $name = receptionist_knowledge_lower((string)($record['name'] ?? ''));
        $roomType = receptionist_knowledge_lower((string)($record['room_type'] ?? ''));
        return ($name !== '' && str_contains($lower, $name)) || ($roomType !== '' && str_contains($lower, $roomType));
    }));
    if ($mentionedVenues) $venues = $mentionedVenues;
    $priceIntent = preg_match('/\b(prices?|rates?|cost|how\s+much|magkano|presyo|bayad|fee|rent|per\s+day|per\s+night|singil|bili|halaga|bayarin|mahal|mura|pinakamura)\b/i', $lower) === 1;
    $capacityIntent = preg_match('/\b(capacity|fit|guests?|pax|ilang|kasya|maximum|how\s+many|ilang\s+tao|pwedeng\s+tao|ilan\s+kaya|ilan|pwede(?!\s+(?:po\s+)?ba\b))\b/i', $lower) === 1;
    $amenityIntent = preg_match('/\b(amenit|included|inclusion|facilit|what.*(?:include|have)|ano.*(?:kasama|meron)|wifi|pool|parking|bed|meron\s+ba|may\s+ba|available\s+ba|meron\s+bang|may\s+bang)\b/i', $lower) === 1;
    $policyIntent = ($route['kind'] ?? null) === 'policy' || preg_match('/\b(payment|pay|cancel|cancellation|refund|resched|policy|policies|rules|proof|receipt|status|hold|check[- ]?in|check[- ]?out|contact|address|location|where|hours|pwede\s+ba|bawal\s+ba|patakaran|tuntunin|pano|paano|walk.?in|pasok|saan|nasaan|pano\s+pumunta|paano\s+pumunta|direksyon|lokasyon|san\s+kayo)\b/i', $lower) === 1;

    if ($priceIntent) {
        $genericRateRequest = preg_match('/\b(starting rates?|rate card|price list|all rates|cheapest|pinakamura|mura)\b/i', $lower) === 1
            || preg_match('/\b(venues?|resort)\b/i', $lower) === 1
            || preg_match('/^(?:(?:what\s+(?:are|is)\s+(?:the\s+|your\s+)?)|(?:ano\s+(?:ang|yung)\s+))?(?:prices?|rates?|magkano|how\s+much|presyo|pinakamura|mura)(?:\s+(?:ang\s+)?(?:rates?|presyo|bayad|is\s+it|does\s+it\s+cost|are\s+they|po|ba|din|naman|please))?[?.!]*$/i', trim($lower)) === 1;
        if ($category === null && empty($venues) && $genericRateRequest) {
            $categoryMinRates = [];
            $categoryUnits = [];
            foreach ($records as $record) {
                if (($record['kind'] ?? null) !== 'venue') continue;
                $cat = $record['category'] ?? null;
                if (!$cat || !isset($record['base_rate'])) continue;
                $rate = (float)$record['base_rate'];
                if (!isset($categoryMinRates[$cat]) || $rate < $categoryMinRates[$cat]) {
                    $categoryMinRates[$cat] = $rate;
                    $categoryUnits[$cat] = ($record['rate_unit'] ?? '') === 'per day' ? '/day' : (($record['rate_unit'] ?? '') === 'per night' ? '/night' : ' ' . ($record['rate_unit'] ?? ''));
                }
            }
            $labels = [
                'Event Hall' => 'Event Halls',
                'Hotel Room' => 'Hotel Rooms',
                'Resort Villa' => 'Resort Villa',
            ];
            $lines = [];
            foreach ($labels as $cat => $catLabel) {
                if (isset($categoryMinRates[$cat])) {
                    $unit = $categoryUnits[$cat] ?? '/day';
                    $lines[] = $catLabel . ': from ' . receptionist_knowledge_money($categoryMinRates[$cat]) . $unit;
                }
            }
            if ($lines) {
                $reply = "Here are the starting rates by category:\n" . implode("\n", $lines) . "\nWhich category would you like to know more about?";
                return [
                    'action' => 'ask',
                    'reply' => $reply,
                    'faq_id' => null,
                    'quick_replies' => ['Event Halls', 'Hotel Rooms', 'Resort Villa'],
                ];
            }
        }
        if (!$venues && $category !== null) $venues = receptionist_knowledge_find_records($records, 'venue', [], $category, null, null, 6);
        $priced = array_values(array_filter($venues, static fn(array $record): bool => isset($record['base_rate'])));
        if ($priced) {
            $lines = [];
            foreach ($priced as $record) {
                $label = trim((string)($record['name'] ?? '') . (isset($record['room_type']) ? ' — ' . $record['room_type'] : ''));
                $unit = ($record['rate_unit'] ?? '') === 'per day' ? '/day' : (($record['rate_unit'] ?? '') === 'per night' ? '/night' : ' ' . ($record['rate_unit'] ?? ''));
                $lines[] = $label . ': ' . receptionist_knowledge_money((float)$record['base_rate']) . $unit;
                if (isset($record['overnight_rate'])) $lines[] = $label . ' overnight: ' . receptionist_knowledge_money((float)$record['overnight_rate']) . '/night';
            }
            if ($category === 'Event Hall' || preg_match('/\b(event|wedding|birthday|corporate|party|catering|a\/v|audio|setup)\b/i', $lower)) {
                $eventPricing = array_values(array_filter($records, static fn(array $record): bool => ($record['kind'] ?? null) === 'event_pricing'));
                foreach ($eventPricing[0]['modifiers'] ?? [] as $modifier) $lines[] = ($modifier['label'] ?? 'Option') . ': ' . receptionist_knowledge_money((float)$modifier['amount']) . ' ' . ($modifier['unit'] ?? '');
                $lines[] = $eventPricing[0]['qualifier'] ?? 'Event options may affect the preliminary estimate; staff confirms the final quotation.';
            }
            $lines = array_values(array_unique($lines));
            return ['action' => 'ask', 'reply' => $prefix['price'] . "\n" . implode("\n", array_slice($lines, 0, 12)), 'faq_id' => null, 'quick_replies' => ['Book now', 'What\'s included?', 'Contact us']];
        }
    }

    if ($capacityIntent) {
        if (!$venues) $venues = receptionist_knowledge_find_records($records, 'venue', [], $category, null, null, 6);
        $capacity = array_values(array_filter($venues, static fn(array $record): bool => isset($record['capacity_max']) || isset($record['capacity_styles'])));
        if ($capacity) {
            $lines = [];
            foreach ($capacity as $record) {
                $label = trim((string)($record['name'] ?? '') . (isset($record['room_type']) ? ' — ' . $record['room_type'] : ''));
                $line = $label . ': ' . (isset($record['capacity_max']) ? 'up to ' . number_format((int)$record['capacity_max']) . ' guests' : 'capacity is listed by setup');
                if (!empty($record['capacity_styles'])) {
                    $styles = [];
                    foreach ($record['capacity_styles'] as $style => $count) $styles[] = $style . ' ' . number_format((int)$count);
                    $line .= ' (' . implode(', ', $styles) . ')';
                }
                if (isset($record['bed_count_min'])) $line .= '; ' . number_format((int)$record['bed_count_min']) . (isset($record['bed_count_max']) && $record['bed_count_max'] !== $record['bed_count_min'] ? '–' . number_format((int)$record['bed_count_max']) : '') . ' beds';
                $lines[] = $line;
            }
            return ['action' => 'ask', 'reply' => $prefix['capacity'] . "\n" . implode("\n", array_slice($lines, 0, 6)), 'faq_id' => null, 'quick_replies' => ['See rates', 'Book now', 'What\'s included?']];
        }
    }

    $specificAmenityDefs = [
        'parking' => [
            'pattern' => '/\b(parking|paradahan|park)\b/i',
            'keywords' => ['parking', 'paradahan', 'park'],
            'name' => 'Free parking',
            'type' => 'available',
        ],
        'pool' => [
            'pattern' => '/\b(pool|swim|palanguyan|swimming)\b/i',
            'keywords' => ['pool', 'swim', 'palanguyan', 'swimming'],
            'name' => 'swimming pool',
            'type' => 'pool',
        ],
        'wifi' => [
            'pattern' => '/\b(wifi|wi[- ]?fi|internet|\bnet\b)\b/i',
            'keywords' => ['wifi', 'wi-fi', 'internet'],
            'name' => 'Free wifi',
            'type' => 'available',
        ],
        'gym' => [
            'pattern' => '/\b(gym|fitness|exercise)\b/i',
            'keywords' => ['gym', 'fitness', 'exercise'],
            'name' => 'Gym and fitness facilities',
            'type' => 'available',
        ],
        'breakfast' => [
            'pattern' => '/\b(breakfast|almusal|morning\s+meal)\b/i',
            'keywords' => ['breakfast', 'almusal'],
            'name' => 'Breakfast',
            'type' => 'included',
        ],
        'aircon' => [
            'pattern' => '/\b(aircon|a\/c|air\s*condition(?:ing|er)?)\b/i',
            'keywords' => ['aircon', 'air condition', 'aircondition', 'a/c'],
            'name' => 'Air conditioning',
            'type' => 'available',
        ],
        'tv' => [
            'pattern' => '/\b(tv|television|telebisyon)\b/i',
            'keywords' => ['tv', 'television', 'telebisyon', 'smart tv'],
            'name' => 'TV',
            'type' => 'available',
        ],
        'kitchen' => [
            'pattern' => '/\b(kitchen|kusina|cook(?:ing)?)\b/i',
            'keywords' => ['kitchen', 'kusina', 'cook'],
            'name' => 'Kitchen facilities',
            'type' => 'available',
        ],
    ];

    $matchedAmenityDef = null;
    foreach ($specificAmenityDefs as $amenityDef) {
        if (preg_match($amenityDef['pattern'], $lower) === 1) {
            $matchedAmenityDef = $amenityDef;
            break;
        }
    }

    if ($matchedAmenityDef !== null) {
        $allVenues = array_values(array_filter($records, static fn(array $r): bool => ($r['kind'] ?? null) === 'venue'));
        $matchingVenues = [];
        foreach ($allVenues as $v) {
            $items = array_merge($v['amenities'] ?? [], $v['inclusions'] ?? []);
            if (!empty($v['has_private_pool'])) {
                $items[] = 'private pool';
            }
            $hasAmenity = false;
            foreach ($items as $item) {
                $itemStr = strtolower((string)$item);
                foreach ($matchedAmenityDef['keywords'] as $kw) {
                    $kwLower = strtolower($kw);
                    if ($kwLower === 'wifi' || $kwLower === 'wi-fi') {
                        if (str_contains(str_replace(['-', ' '], '', $itemStr), 'wifi') || str_contains($itemStr, 'internet')) {
                            $hasAmenity = true;
                            break 2;
                        }
                    } elseif ($kwLower === 'tv') {
                        if (preg_match('/\b(?:smart\s+)?tv\b/i', $itemStr) === 1 || str_contains($itemStr, 'television')) {
                            $hasAmenity = true;
                            break 2;
                        }
                    } elseif ($kwLower === 'park' || $kwLower === 'parking') {
                        if (str_contains($itemStr, 'park')) {
                            $hasAmenity = true;
                            break 2;
                        }
                    } else {
                        if (str_contains($itemStr, $kwLower)) {
                            $hasAmenity = true;
                            break 2;
                        }
                    }
                }
            }
            if ($hasAmenity) {
                $matchingVenues[] = $v;
            }
        }

        if (empty($matchingVenues)) {
            return [
                'action' => 'ask',
                'reply' => "I'm not sure about that specific amenity. Let me connect you with our team for the most accurate answer.",
                'faq_id' => null,
                'quick_replies' => ['See rates', 'Book now', 'More questions'],
            ];
        }

        $labels = [];
        $hasHotel = false;
        foreach ($matchingVenues as $mv) {
            $cat = $mv['category'] ?? '';
            if ($cat === 'Hotel Room') {
                $hasHotel = true;
            } elseif ($cat === 'Resort Villa') {
                $vName = (string)($mv['name'] ?? 'Villa');
                $labels[] = strcasecmp($vName, 'villa') === 0 ? 'the Villa' : $vName;
            } else {
                $labels[] = (string)($mv['name'] ?? 'Event Hall');
            }
        }
        $labels = array_values(array_unique($labels));
        sort($labels, SORT_NATURAL | SORT_FLAG_CASE);
        if ($hasHotel) {
            $labels[] = 'our hotel rooms';
        }

        if (count($labels) === 1) {
            $venueList = $labels[0];
        } elseif (count($labels) === 2) {
            $venueList = $labels[0] . ' and ' . $labels[1];
        } else {
            $venueList = implode(', ', array_slice($labels, 0, -1)) . ', and ' . end($labels);
        }

        if (count($matchingVenues) === count($allVenues) && count($allVenues) > 0) {
            if ($matchedAmenityDef['type'] === 'pool') {
                $reply = "Yes! We have a swimming pool. Pool access is included with all our venues.";
            } elseif ($matchedAmenityDef['type'] === 'included') {
                $reply = "Yes! {$matchedAmenityDef['name']} is included with all our venues.";
            } else {
                $reply = "Yes! {$matchedAmenityDef['name']} is available at all our venues.";
            }
        } else {
            if ($matchedAmenityDef['type'] === 'pool') {
                $reply = "Yes! We have a swimming pool. Pool access is included with {$venueList}.";
            } elseif ($matchedAmenityDef['type'] === 'included') {
                $reply = "Yes! {$matchedAmenityDef['name']} is included with {$venueList}.";
            } else {
                $reply = "Yes! {$matchedAmenityDef['name']} is available at {$venueList}.";
            }
        }

        return [
            'action' => 'ask',
            'reply' => $reply,
            'faq_id' => null,
            'quick_replies' => ['See rates', 'Book now', 'More questions'],
        ];
    }

    if ($amenityIntent) {
        if (!$venues) $venues = receptionist_knowledge_find_records($records, 'venue', [], $category, null, null, 6);
        $amenityRecords = array_values(array_filter($venues, static fn(array $record): bool => !empty($record['amenities']) || !empty($record['inclusions']) || !empty($record['description'])));
        if ($amenityRecords) {
            $lines = [];
            foreach ($amenityRecords as $record) {
                $label = trim((string)($record['name'] ?? '') . (isset($record['room_type']) ? ' — ' . $record['room_type'] : ''));
                $facts = array_merge($record['amenities'] ?? [], $record['inclusions'] ?? []);
                $line = $label . ': ' . ($facts ? implode(', ', array_slice($facts, 0, 8)) : 'No amenity list is currently published');
                if (!empty($record['description'])) $line .= '. ' . $record['description'];
                $lines[] = $line;
            }
            return ['action' => 'ask', 'reply' => $prefix['amenities'] . "\n" . implode("\n", array_slice($lines, 0, 6)), 'faq_id' => null, 'quick_replies' => ['See rates', 'Book now', 'Contact us']];
        }
    }

    if ($policyIntent) {
        $faqMatches = receptionist_knowledge_faq_matches($records, $tokens, 6);
        $faqMatches = array_values(array_filter($faqMatches, static fn(array $record): bool => ($record['kind'] ?? null) === 'faq')) ?: array_slice($faqMatches, 0, 1);
        if ($faqMatches) {
            $lines = [];
            $boundedFaqs = array_slice($faqMatches, 0, 2);
            $faqId = count($boundedFaqs) === 1 && ($boundedFaqs[0]['kind'] ?? null) === 'faq' ? $boundedFaqs[0]['id'] : null;
            foreach ($boundedFaqs as $record) {
                if (($record['kind'] ?? null) === 'faq') $lines[] = (string)$record['question'] . ': ' . receptionist_knowledge_excerpt((string)$record['answer']);
                elseif (($record['kind'] ?? null) === 'policy') $lines[] = receptionist_knowledge_excerpt((string)$record['text']);
            }
            if ($lines) return ['action' => $faqId !== null ? 'faq' : 'ask', 'reply' => $prefix['faq'] . "\n" . implode("\n\n", array_slice($lines, 0, 3)), 'faq_id' => $faqId, 'quick_replies' => ['More FAQs', 'Book now', 'Contact us']];
        }
        if (preg_match('/\b(contact|address|location|where|phone|email|saan|nasaan|pano\s+pumunta|paano\s+pumunta|direksyon|lokasyon|san\s+kayo)\b/i', $lower)) {
            $contact = array_values(array_filter($records, static fn(array $record): bool => ($record['kind'] ?? null) === 'contact'))[0] ?? null;
            if ($contact) {
                $parts = [];
                foreach (['name' => 'Name', 'address' => 'Address', 'phone' => 'Phone', 'email' => 'Email'] as $key => $label) if (!empty($contact['contact'][$key])) $parts[] = $label . ': ' . $contact['contact'][$key];
                if ($parts) return ['action' => 'ask', 'reply' => $prefix['contact'] . "\n" . implode("\n", $parts), 'faq_id' => null, 'quick_replies' => ['Booking', 'Payment', 'Support FAQs']];
            }
        }
    }
    return null;
}
