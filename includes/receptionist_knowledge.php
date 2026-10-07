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
        // A name/rate is presentation data and is not a stable identity. Keep
        // distinct venues and commercial room groups even when they share a
        // display label or starting rate.
        $dedupeKey = implode('|', [
            (string)($record['category'] ?? ''),
            (string)($record['venue_id'] ?? ''),
            (string)($record['room_group_id'] ?? ''),
            (string)($record['id'] ?? ''),
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
    return receptionist_knowledge_apply_quotas($records, RECEPTIONIST_KNOWLEDGE_MAX_RECORDS);
}

function receptionist_knowledge_apply_quotas(array $records, int $maximum = RECEPTIONIST_KNOWLEDGE_MAX_RECORDS): array
{
    $maximum = max(1, min(RECEPTIONIST_KNOWLEDGE_MAX_RECORDS, $maximum));
    $buckets = ['venue' => [], 'event_pricing' => [], 'contact' => [], 'policy' => [], 'faq' => []];
    foreach ($records as $record) {
        if (!is_array($record)) continue;
        $kind = (string)($record['kind'] ?? '');
        if (isset($buckets[$kind])) $buckets[$kind][] = $record;
    }
    // Venue records are bounded, while at least one contact/policy/FAQ item
    // survives a large venue catalog. The final pass remains deterministic.
    $selected = [];
    $take = static function (array $items, int $count) use (&$selected): void {
        foreach (array_slice($items, 0, max(0, $count)) as $item) $selected[] = $item;
    };
    $venueQuota = min(70, $maximum);
    $venueOverflow = count($buckets['venue']) > $venueQuota;
    // Reserve one bounded metadata record for the complete authoritative
    // venue identity index when the presentation knowledge quota overflows.
    // It is used only for direct deterministic selection, never sent to the
    // model as factual knowledge.
    $selectionMaximum = max(0, $maximum - ($venueOverflow ? 1 : 0));
    $venueByCategory = ['Event Hall' => [], 'Hotel Room' => [], 'Resort Villa' => []];
    foreach ($buckets['venue'] as $venue) {
        $category = (string)($venue['category'] ?? '');
        if (isset($venueByCategory[$category])) $venueByCategory[$category][] = $venue;
    }
    foreach (['Event Hall' => 40, 'Hotel Room' => 25, 'Resort Villa' => 25] as $category => $quota) {
        if (count($selected) >= $selectionMaximum) break;
        $take($venueByCategory[$category], min($quota, $selectionMaximum - count($selected)));
    }
    foreach (['event_pricing' => 4, 'contact' => 1, 'policy' => 4, 'faq' => $maximum] as $kind => $quota) {
        if (count($selected) >= $selectionMaximum) break;
        $take($buckets[$kind], min($quota, $selectionMaximum - count($selected)));
    }
    // Fill remaining slots in category/kind order, preserving each identity.
    if (count($selected) < $selectionMaximum) {
        $seen = [];
        foreach ($selected as $item) $seen[(string)($item['id'] ?? '')] = true;
        foreach ($records as $item) {
            if (count($selected) >= $selectionMaximum) break;
            $id = (string)($item['id'] ?? '');
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $selected[] = $item;
        }
    }
    if ($venueOverflow && $maximum > 0) {
        $index = [];
        foreach ($buckets['venue'] as $venue) {
            $index[] = [
                'id' => (string)($venue['id'] ?? ''),
                'kind' => 'venue',
                'venue_id' => (int)($venue['venue_id'] ?? 0),
                'category' => (string)($venue['category'] ?? ''),
                'name' => receptionist_knowledge_clean_text($venue['name'] ?? '', 150) ?? '',
                'room_type' => receptionist_knowledge_clean_text($venue['room_type'] ?? '', 80) ?? '',
                'room_group_id' => receptionist_knowledge_positive_int($venue['room_group_id'] ?? null),
            ];
        }
        $selected[] = ['id' => '__venue_index', 'kind' => 'venue_index', 'items' => $index];
    }
    return $selected;
}

function receptionist_public_knowledge_records(mysqli $conn, array $faqs): array
{
    return receptionist_knowledge_build_records(receptionist_knowledge_fetch_venue_rows($conn), receptionist_knowledge_fetch_settings($conn), $faqs);
}

function receptionist_knowledge_lower(string $value): string
{
    return strtolower(str_replace(['–', '—'], '-', $value));
}

function receptionist_knowledge_normalize_message(string $message): string
{
    $message = receptionist_knowledge_lower($message);
    // Conservative typo/alias normalization for high-signal receptionist
    // terms. This only affects retrieval/extraction, never stored content.
    $replacements = [
        '/\bwnt(?:ed)?\b/' => 'want', '/\bresrve\b/' => 'reserve', '/\bbookng\b/' => 'booking',
        '/\bavail(?:ble|abilty)\b/' => 'available', '/\bprce\b/' => 'price', '/\bguest[s]?\b/' => 'guests',
        '/\brooms?\b/' => 'room', '/\bhotels?\b/' => 'hotel', '/\bvilas?\b/' => 'villa',
    ];
    foreach ($replacements as $pattern => $replacement) $message = preg_replace($pattern, $replacement, $message) ?? $message;
    return $message;
}

function receptionist_knowledge_tokens(string $message): array
{
    $tokens = preg_split('/[^\p{L}\p{N}]+/u', receptionist_knowledge_normalize_message($message), -1, PREG_SPLIT_NO_EMPTY) ?: [];
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
    $haystackTokens = preg_split('/[^\p{L}\p{N}]+/u', $haystack, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $category = receptionist_knowledge_normalize_message((string)($record['category'] ?? ''));
    foreach ($tokens as $token) {
        if (strlen($token) < 3) continue;
        if (in_array($token, $haystackTokens, true)) {
            // Exact question/alias terms carry more weight than incidental
            // answer text. Category matches prevent cross-kind leakage.
            $score += 1;
            if (str_contains(receptionist_knowledge_normalize_message((string)($record['name'] ?? '') . ' ' . (string)($record['question'] ?? '') . ' ' . implode(' ', $record['phrases'] ?? [])), $token)) $score++;
            if ($category !== '' && in_array($token, preg_split('/\s+/', $category, -1, PREG_SPLIT_NO_EMPTY), true)) $score++;
            continue;
        }
        // Small bounded fuzzy match catches common typos without making a
        // weakly related record look relevant.
        if (strlen($token) >= 4 && strlen($token) <= 24) {
            foreach ($haystackTokens as $candidate) {
                if (strlen($candidate) < 4 || abs(strlen($candidate) - strlen($token)) > 2) continue;
                if (levenshtein($token, $candidate) <= (strlen($token) >= 7 ? 2 : 1)) { $score++; break; }
            }
        }
    }
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
    usort($matches, static function (array $left, array $right): int {
        $score = $right['score'] <=> $left['score'];
        return $score !== 0 ? $score : strcmp((string)($left['record']['id'] ?? ''), (string)($right['record']['id'] ?? ''));
    });
    return array_map(static fn(array $entry): array => $entry['record'], array_slice($matches, 0, max(1, min($limit, 8))));
}

function receptionist_knowledge_faq_matches(array $records, array $tokens, int $limit = 3): array
{
    $matches = [];
    foreach ($records as $record) {
        if (!in_array($record['kind'] ?? null, ['faq', 'policy'], true)) continue;
        // The published FAQ question and answer are the canonical retrieval
        // source. Staff phrases remain useful aliases, but never have to be
        // maintained for an FAQ to be discoverable.
        $withoutAliases = $record;
        $withoutAliases['phrases'] = [];
        $score = receptionist_knowledge_record_score($withoutAliases, $tokens);
        if ($score === 0 && !empty($record['phrases'])) {
            $aliasesOnly = $record;
            $aliasesOnly['question'] = '';
            $aliasesOnly['answer'] = '';
            $aliasScore = receptionist_knowledge_record_score($aliasesOnly, $tokens);
            $score = (int)floor($aliasScore / 2);
        }
        if ($score > 0) $matches[] = ['score' => $score, 'record' => $record];
    }
    usort($matches, static function (array $left, array $right): int {
        $score = $right['score'] <=> $left['score'];
        return $score !== 0 ? $score : strcmp((string)($left['record']['id'] ?? ''), (string)($right['record']['id'] ?? ''));
    });
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

function receptionist_knowledge_is_support_faq_request(string $message): bool
{
    return preg_match('/\b(?:faqs?|frequently\s+asked\s+questions|mga\s+madalas\s+na\s+tanong)\b/i', $message) === 1;
}

function receptionist_knowledge_intent(string $message): array
{
    $lower = receptionist_knowledge_normalize_message($message);
    if (receptionist_knowledge_is_support_faq_request($message)) return ['kind' => 'support_faq', 'category' => null];
    $informationalBooking = preg_match('/\b(how do i|how can i|how to|what is the (?:booking|reservation) process|booking process|steps? to (?:book|reserve)|where can i (?:book|reserve)|paano (?:mag[- ]?book|mag[- ]?reserve|ang proseso)|ano ang proseso|booking steps?)\b/i', $lower) === 1;
    $bookingVerbPattern = '(?:book|wanna|reserve|reservation|mag[- ]?book|magpa[- ]?book|mag[- ]?reserve|magpa[- ]?reserve|magpareserba|booking|i[- ]?book|ipa[- ]?book|walk[ -]?in)';
    $directBooking = !$informationalBooking && (
        preg_match('/\b(?:i|we|ako|kami|gusto|want|need|help me|pwede|maaari)\b[^.!?\n]{0,48}\b' . $bookingVerbPattern . '\b/i', $lower) === 1
        || preg_match('/\b' . $bookingVerbPattern . '\s+(?:an?\s+)?(?:hotel|room|rooms|villa|event|venue)\b/i', $lower) === 1
        || preg_match('/\b' . $bookingVerbPattern . '\s+(?:for|para\s+sa)?\s*\d+/i', $lower) === 1
        || preg_match('/\b(?:want|need|looking\s+for|gusto)\b[^.!?\n]{0,24}\b(?:hotel|room|villa|event|venue)\b/i', $lower) === 1
        || preg_match('/\b(?:make|start)\s+(?:an?\s+)?(?:booking|reservation)\b/i', $lower) === 1
        || preg_match('/\A\s*(?:book|reserve|booking|reservation)\s*[.!?]*\z/i', $lower) === 1
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

function receptionist_knowledge_number_word(string $value): ?int
{
    $ones = ['zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50, 'dalawa' => 2, 'tatlo' => 3, 'apat' => 4, 'lima' => 5, 'anim' => 6, 'pito' => 7, 'walo' => 8, 'siyam' => 9, 'sampu' => 10];
    $value = strtolower(trim($value));
    if (isset($ones[$value])) return $ones[$value];
    if (preg_match('/\A(twenty|thirty|forty|fifty)[ -](one|two|three|four|five|six|seven|eight|nine)\z/', $value, $parts)) return ($ones[$parts[1]] ?? 0) + ($ones[$parts[2]] ?? 0);
    return null;
}

function receptionist_knowledge_booking_group_size(string $message): ?int
{
    $message = receptionist_knowledge_normalize_message($message);
    $number = '(\d{1,4}|zero|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty(?:[- ](?:one|two|three|four|five|six|seven|eight|nine))?|thirty(?:[- ](?:one|two|three|four|five|six|seven|eight|nine))?|forty(?:[- ](?:one|two|three|four|five|six|seven|eight|nine))?|fifty(?:[- ](?:one|two|three|four|five|six|seven|eight|nine))?|dalawa|tatlo|apat|lima|anim|pito|walo|siyam|sampu)';
    $patterns = [
        '/\b(?:party|group|pax|for|para sa|kami)\s*(?:of|na)?\s*' . $number . '\b/i',
        '/\b' . $number . '\s*(?:pax|persons?|guests?|people|tao|bisita|adults?|children?)\b/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message, $match)) {
            $value = ctype_digit((string)$match[1]) ? (int)$match[1] : receptionist_knowledge_number_word((string)$match[1]);
            if ($value !== null && $value > 0 && $value <= 10000) return $value;
        }
    }
    if (preg_match('/\b(?:solo|mag-?isa|alone)\b/i', $message)) return 1;
    if (preg_match('/\b(?:kami dalawa|dalawa kami|couple|pair)\b/i', $message)) return 2;
    return null;
}

/** Identify the latest unanswered booking prompt from validated state + chat history. */
function receptionist_knowledge_pending_booking_step(array $slots, array $history): ?string
{
    $intent = $slots['intent'] ?? null;
    $hasIntent = in_array($intent, RECEPTIONIST_KNOWLEDGE_CATEGORIES, true);

    for ($index = count($history) - 1; $index >= 0; $index--) {
        $turn = $history[$index] ?? null;
        if (!is_array($turn) || ($turn['role'] ?? null) !== 'assistant' || !is_string($turn['content'] ?? null)) continue;
        $prompt = strtolower($turn['content']);
        if (!$hasIntent && preg_match('/\b(?:which venue would you like to book|event hall, hotel room|event, hotel, or villa|venue type)\b/i', $prompt)) return 'intent';
        if ($intent === 'Event Hall' && preg_match('/\b(?:what kind of event|what type of event|event occasion|occasion)\b/i', $prompt)) return 'occasion';
        if ($intent === 'Resort Villa' && preg_match('/\b(?:purpose of your stay|purpose of (?:the )?stay|what.*purpose)\b/i', $prompt)) return 'purpose';
        if ($hasIntent && preg_match('/\b(?:how many guests?|guest count|number of guests|ilang bisita|bilang ng bisita)\b/i', $prompt)) return 'group_size';
        if ($intent === 'Hotel Room' && preg_match('/\b(?:what matters most.*room search|room preference|best fit,? lowest price|pinakamababang presyo.*comfort)\b/i', $prompt)) return 'preference';
        if ($intent === 'Hotel Room' && !empty($slots['start_date']) && preg_match('/\b(?:check[ -]?out date|date of checkout)\b/i', $prompt)) return 'end_date';
        if ($hasIntent && preg_match('/\b(?:check[ -]?in date|event date|event or stay date|what date.*(?:villa|booking|stay)|booking date)\b/i', $prompt)) return 'start_date';

        $previousUser = null;
        for ($userIndex = $index - 1; $userIndex >= 0; $userIndex--) {
            if (($history[$userIndex]['role'] ?? null) === 'user' && is_string($history[$userIndex]['content'] ?? null)) {
                $previousUser = $history[$userIndex]['content'];
                break;
            }
        }
        if (is_string($previousUser) && (receptionist_knowledge_is_social_input($previousUser)
            || receptionist_knowledge_property_from_message($previousUser) !== null
            || receptionist_knowledge_is_fact_request($previousUser, $slots))) continue;
        return null;
    }
    return null;
}

function receptionist_knowledge_pending_preference(string $message): ?string
{
    $text = receptionist_knowledge_normalize_message($message);
    if (preg_match('/\b(?:best\s*fit|most suitable|better fit|fits? (?:us|our group)|works? for us|match(?:es)? our group|recommend(?:ed|ation)?)\b/i', $text)) return 'best_fit';
    if (preg_match('/\b(?:cheap(?:est)?|low(?:est)?(?: price| cost)?|budget|affordab\w*|less expensive|save(?: money)?|mura|pinakamura)\b/i', $text)) return 'save';
    if (preg_match('/\b(?:comfort(?:able)?|luxur\w*|upscale|premium|higher[- ]end|deluxe|more comfortable)\b/i', $text)) return 'comfort';
    return null;
}

function receptionist_knowledge_pending_booking_clarification(string $step, string $language, array $slots): array
{
    $fil = $language === 'fil';
    $copy = [
        'intent' => [$fil ? 'Anong venue ang gusto mong i-book — event hall, hotel room, o resort villa?' : 'Which venue would you like to book — an event hall, hotel room, or resort villa?', ['Event', 'Hotel', 'Villa']],
        'occasion' => [$fil ? 'Anong uri ng event ito, halimbawa wedding, birthday, corporate event, o iba pa?' : 'What kind of event is it — a wedding, birthday, corporate event, or something else?', ['Wedding', 'Birthday', 'Corporate', 'Other']],
        'purpose' => [$fil ? 'Ano ang layunin ng villa stay — family, private, o relaxation?' : 'What is the purpose of your villa stay — family, private, or relaxation?', ['Family', 'Private', 'Relaxation']],
        'group_size' => [$fil ? 'Ilang bisita ang kasama? Pakisagot gamit ang buong bilang, halimbawa 2.' : 'How many guests are included? Please reply with a whole number, such as 2.', []],
        'preference' => [$fil ? 'Ano ang mas mahalaga sa room search mo — best fit, pinakamababang presyo, o comfort?' : 'What matters most for your room search — best fit, lowest price, or comfort?', ['Best fit', 'Lowest price', 'Comfort']],
        'start_date' => [($slots['intent'] ?? null) === 'Hotel Room'
            ? ($fil ? 'Ano ang check-in date?' : 'What is the check-in date?')
            : (($slots['intent'] ?? null) === 'Resort Villa'
                ? ($fil ? 'Anong petsa ang gusto mo para sa villa stay?' : 'What date would you like for the villa stay?')
                : ($fil ? 'Ano ang petsa ng event?' : 'What is the event date?')), []],
        'end_date' => [$fil ? 'Ano ang check-out date? Dapat mas huli ito sa check-in.' : 'What is the check-out date? It must be after check-in.', []],
    ];
    [$reply, $quickReplies] = $copy[$step] ?? $copy['group_size'];
    return ['mode' => 'knowledge', 'booking_continuation' => true, 'action' => 'ask', 'reply' => $reply,
        'faq_id' => null, 'slots' => $slots, 'missing_slots' => [$step], 'quick_replies' => $quickReplies,
        'quick_actions' => ['start_over']];
}

/** Recognize a bare count only as an answer to the active guest-count prompt. */
function receptionist_knowledge_pending_bare_guest_count(string $message, array $baseSlots, array $history): ?array
{
    $pendingStep = receptionist_knowledge_pending_booking_step($baseSlots, $history);
    if (!in_array($pendingStep, ['group_size', 'preference'], true)) return null;
    $number = '(?:-?\d+(?:\.\d+)?|zero|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty(?:[- ](?:one|two|three|four|five|six|seven|eight|nine))?|thirty(?:[- ](?:one|two|three|four|five|six|seven|eight|nine))?|forty(?:[- ](?:one|two|three|four|five|six|seven|eight|nine))?|fifty(?:[- ](?:one|two|three|four|five|six|seven|eight|nine))?|dalawa|tatlo|apat|lima|anim|pito|walo|siyam|sampu)';
    $text = strtolower(trim(receptionist_knowledge_normalize_message($message)));
    $text = trim((string)preg_replace('/[.!?,;:]+$/u', '', $text));
    $correctionPrefix = '(?:(?:actually|really|it is|we are|about)\s+)';
    $labeledGuest = '\s+(?:guests?|people|persons?|pax|tao|bisita)';
    $pattern = $pendingStep === 'group_size'
        ? '/\A(?:' . $correctionPrefix . ')?(' . $number . ')(?:' . $labeledGuest . ')?\z/u'
        : '/\A' . $correctionPrefix . '(' . $number . ')(?:' . $labeledGuest . ')?\z/u';
    if (!preg_match($pattern, $text, $match)) return null;
    $raw = $match[1];
    $count = preg_match('/\A-?\d+(?:\.\d+)?\z/', $raw) === 1
        ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]])
        : receptionist_knowledge_number_word($raw);
    return ['valid' => is_int($count) && $count > 0 && $count <= 10000, 'count' => is_int($count) && $count > 0 && $count <= 10000 ? $count : null];
}

function receptionist_knowledge_parse_month_date(string $value, DateTimeImmutable $today): ?string
{
    $months = ['january' => 1, 'jan' => 1, 'february' => 2, 'feb' => 2, 'march' => 3, 'mar' => 3, 'april' => 4, 'apr' => 4, 'may' => 5, 'june' => 6, 'jun' => 6, 'july' => 7, 'jul' => 7, 'august' => 8, 'aug' => 8, 'september' => 9, 'sep' => 9, 'sept' => 9, 'october' => 10, 'oct' => 10, 'november' => 11, 'nov' => 11, 'december' => 12, 'dec' => 12];
    $value = strtolower(trim($value));
    if (!preg_match('/\A([a-z]+)\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?\z/i', $value, $match)
        && !preg_match('/\A(\d{1,2})\s+([a-z]+)(?:\s+(\d{4}))?\z/i', $value, $match)) return null;
    $month = isset($months[$match[1]]) ? $months[$match[1]] : ($months[$match[2]] ?? null);
    $day = isset($months[$match[1]]) ? (int)$match[2] : (int)$match[1];
    $year = (int)($match[3] ?? 0);
    if ($month === null || $day < 1 || $day > 31) return null;
    if ($year === 0) {
        $year = (int)$today->format('Y');
        $candidate = DateTimeImmutable::createFromFormat('!Y-n-j', "{$year}-{$month}-{$day}");
        if (!$candidate || $candidate < $today) $year++;
    }
    $candidate = DateTimeImmutable::createFromFormat('!Y-n-j', "{$year}-{$month}-{$day}");
    return $candidate && $candidate->format('Y-n-j') === "{$year}-{$month}-{$day}" && $candidate->format('Y-m-d') >= $today->format('Y-m-d') ? $candidate->format('Y-m-d') : null;
}

function receptionist_knowledge_parse_numeric_date(string $value, DateTimeImmutable $today): ?string
{
    if (!preg_match('/\A(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})\z/', trim($value), $match)) return null;
    $first = (int)$match[1]; $second = (int)$match[2]; $year = (int)$match[3];
    if ($year < 100) $year += 2000;
    // Only parse an unambiguous numeric date: month/day when day > 12 or
    // day/month when month > 12. Otherwise ask the visitor to clarify.
    if ($first <= 12 && $second > 12) { $month = $first; $day = $second; }
    elseif ($first > 12 && $second <= 12) { $month = $second; $day = $first; }
    else return null;
    $candidate = DateTimeImmutable::createFromFormat('!Y-n-j', "{$year}-{$month}-{$day}");
    return $candidate && $candidate->format('Y-n-j') === "{$year}-{$month}-{$day}" && $candidate->format('Y-m-d') >= $today->format('Y-m-d') ? $candidate->format('Y-m-d') : null;
}

function receptionist_knowledge_booking_dates(string $message, ?DateTimeImmutable $today = null): array
{
    $today ??= new DateTimeImmutable('today');
    $lower = receptionist_knowledge_normalize_message($message);
    $dates = [];
    if (preg_match_all('/\b\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}\b/', $lower, $numericMatches)) foreach ($numericMatches[0] as $raw) {
        $parsed = receptionist_knowledge_parse_numeric_date($raw, $today);
        if ($parsed !== null && !in_array($parsed, $dates, true)) $dates[] = $parsed;
    }
    if (preg_match_all('/\b(january|jan|february|feb|march|mar|april|apr|may|june|jun|july|jul|august|aug|september|sep|sept|october|oct|november|nov|december|dec)\s+(\d{1,2})\s*[-–]\s*(\d{1,2})(?:,?\s+(\d{4}))?\b/i', $lower, $rangeMatches, PREG_SET_ORDER)) foreach ($rangeMatches as $range) {
        $yearSuffix = isset($range[4]) && $range[4] !== '' ? ' ' . $range[4] : '';
        foreach ([(string)$range[2], (string)$range[3]] as $day) {
            $parsed = receptionist_knowledge_parse_month_date($range[1] . ' ' . $day . $yearSuffix, $today);
            if ($parsed !== null && !in_array($parsed, $dates, true)) $dates[] = $parsed;
        }
    }
    foreach (['/(\d{4}-\d{2}-\d{2})/', '/\b((?:january|jan|february|feb|march|mar|april|apr|may|june|jun|july|jul|august|aug|september|sep|sept|october|oct|november|nov|december|dec)\s+\d{1,2}(?:st|nd|rd|th)?(?:,?\s+\d{4})?)\b/i', '/\b(\d{1,2}\s+(?:january|jan|february|feb|march|mar|april|apr|may|june|jun|july|jul|august|aug|september|sep|sept|october|oct|november|nov|december|dec)(?:\s+\d{4})?)\b/i'] as $pattern) {
        if (preg_match_all($pattern, $lower, $matches)) foreach ($matches[1] as $raw) {
            $parsed = preg_match('/\A\d{4}-/', $raw) ? receptionist_ai_canonical_date($raw, $today) : receptionist_knowledge_parse_month_date($raw, $today);
            if ($parsed !== null && !in_array($parsed, $dates, true)) $dates[] = $parsed;
        }
    }
    if (preg_match('/\b(?:tomorrow|bukas)\b/i', $lower)) $dates[] = $today->modify('+1 day')->format('Y-m-d');
    $days = ['sun' => 0, 'sunday' => 0, 'mon' => 1, 'monday' => 1, 'tue' => 2, 'tues' => 2, 'tuesday' => 2, 'wed' => 3, 'wednesday' => 3, 'thu' => 4, 'thur' => 4, 'thurs' => 4, 'thursday' => 4, 'fri' => 5, 'friday' => 5, 'sat' => 6, 'saturday' => 6];
    if (preg_match('/\b(next|this)\s+weekend\b/i', $lower, $match)) {
        $current = (int)$today->format('w');
        $offset = (6 - $current + 7) % 7 + (strtolower($match[1]) === 'next' ? 7 : 0);
        $dates[] = $today->modify('+' . $offset . ' days')->format('Y-m-d');
    } elseif (preg_match('/\bnext\s+week\s+(sun(?:day)?|mon(?:day)?|t(?:ue|ues|uesday)|wed(?:nesday)?|thu(?:r|rs|sday|rsday)?|fri(?:day)?|sat(?:urday)?)\b/i', $lower, $match)) {
        $target = $days[strtolower($match[1])] ?? null;
        if ($target !== null) {
            $monday = $today->modify('monday next week');
            $dates[] = $monday->modify('+' . ($target - 1) . ' days')->format('Y-m-d');
        }
    } elseif (preg_match('/\b(?:this\s+)?(sun(?:day)?|mon(?:day)?|t(?:ue|ues|uesday)|wed(?:nesday)?|thu(?:r|rs|sday|rsday)?|fri(?:day)?|sat(?:urday)?)\b/i', $lower, $match)) {
        $target = $days[strtolower($match[1])] ?? null;
        if ($target !== null) {
            $current = (int)$today->format('w');
            $dates[] = $today->modify('+' . (($target - $current + 7) % 7) . ' days')->format('Y-m-d');
        }
    }
    return array_values(array_unique(array_slice($dates, 0, 2)));
}

function receptionist_knowledge_booking_date(string $message, ?DateTimeImmutable $today = null): ?string
{
    return receptionist_knowledge_booking_dates($message, $today)[0] ?? null;
}

function receptionist_knowledge_normalized_phrase(string $value): string
{
    return trim((string)preg_replace('/[^a-z0-9]+/i', ' ', receptionist_knowledge_lower($value)));
}

function receptionist_knowledge_booking_venue(array $records, string $message, ?string $category): ?array
{
    $messageText = ' ' . receptionist_knowledge_normalized_phrase($message) . ' ';
    if ($messageText === '  ') return null;
    $messageTokens = preg_split('/\s+/', trim($messageText), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $venueRecords = [];
    foreach ($records as $record) {
        if (($record['kind'] ?? null) === 'venue') $venueRecords[] = $record;
        if (($record['kind'] ?? null) === 'venue_index' && is_array($record['items'] ?? null)) {
            foreach ($record['items'] as $indexedVenue) if (is_array($indexedVenue)) $venueRecords[] = $indexedVenue;
        }
    }
    $bestMatches = [];
    $bestScore = 0;
    foreach ($venueRecords as $record) {
        if (($record['kind'] ?? null) !== 'venue' || ($category !== null && ($record['category'] ?? null) !== $category)) continue;
        $name = receptionist_knowledge_normalized_phrase((string)($record['name'] ?? ''));
        $roomType = receptionist_knowledge_normalized_phrase((string)($record['room_type'] ?? ''));
        if (in_array($name, ['event', 'event hall', 'venue', 'hall', 'hotel', 'room', 'villa'], true)) continue;
        $identity = (string)($record['venue_id'] ?? '') . '|' . (string)($record['room_group_id'] ?? '');
        $compositeName = trim($name . ' ' . $roomType);
        if ($compositeName !== '' && $roomType !== '' && str_contains($messageText, ' ' . $compositeName . ' ')) {
            if ($bestScore !== PHP_INT_MAX) $bestMatches = [];
            $bestMatches[$identity] = $record;
            $bestScore = PHP_INT_MAX;
            continue;
        }
        if ($bestScore !== PHP_INT_MAX && $name !== '' && str_contains($messageText, ' ' . $name . ' ')) {
            if ($category !== 'Hotel Room') return $record;
            // A hotel name can map to several sellable room groups. Never
            // silently choose the first group when the visitor has not named it.
            $bestMatches[$identity] = $record;
            $bestScore = max($bestScore, 1);
            continue;
        }
        if ($bestScore === PHP_INT_MAX) continue;
        $aliases = array_values(array_unique(array_filter([$name, preg_replace('/\b(?:hall|room|villa|hotel)\b/', '', $name) ?: '', $roomType])));
        foreach ($aliases as $alias) {
            $alias = trim($alias);
            if ($alias === '' || strlen($alias) < 4) continue;
            $aliasTokens = preg_split('/\s+/', $alias, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $matched = count($aliasTokens) > 1
                ? str_contains($messageText, ' ' . $alias . ' ')
                : in_array($alias, $messageTokens, true);
            if ($matched && count($aliasTokens) >= $bestScore) {
                if (count($aliasTokens) > $bestScore) $bestMatches = [];
                $bestMatches[$identity] = $record;
                $bestScore = count($aliasTokens);
            }
            if (!$matched && count($aliasTokens) === 1 && strlen($alias) >= 5) {
                foreach ($messageTokens as $token) {
                    if (abs(strlen($token) - strlen($alias)) <= 1 && levenshtein($token, $alias) <= 1) {
                        if ($bestScore <= 1) $bestMatches[$identity] = $record;
                        $bestScore = max($bestScore, 1);
                        break;
                    }
                }
            }
        }
    }
    return count($bestMatches) === 1 ? reset($bestMatches) : null;
}

function receptionist_knowledge_booking_continuation(array $records, string $message, string $language, array $baseSlots, array $route, ?string $category, ?int $pendingGuestCount = null, ?string $pendingStep = null): ?array
{
    $lower = receptionist_knowledge_normalize_message($message);
    $groupSize = receptionist_knowledge_booking_group_size($message) ?? $pendingGuestCount;
    $bookingDates = receptionist_knowledge_booking_dates($message);
    $startDate = $bookingDates[0] ?? null;
    $pendingPreference = receptionist_knowledge_pending_preference($message);
    $venue = receptionist_knowledge_booking_venue($records, $message, $category ?? ($baseSlots['intent'] ?? null));
    if (($route['kind'] ?? null) === 'booking' && $venue === null && receptionist_knowledge_is_generic_booking_start($message)) {
        return [
            'mode' => 'knowledge',
            'booking_continuation' => true,
            'reset_context' => true,
            'action' => 'ask',
            'reply' => $language === 'fil'
                ? 'Anong venue ang gusto mong i-book — event hall, hotel room, o resort villa?'
                : 'Which venue would you like to book — an event hall, hotel room, or resort villa?',
            'faq_id' => null,
            'slots' => [],
            'missing_slots' => ['intent'],
            'quick_replies' => ['Event', 'Hotel', 'Villa', 'Support FAQs'],
        ];
    }
    $normalizedMessage = receptionist_knowledge_normalized_phrase($message);
    $venueName = receptionist_knowledge_normalized_phrase((string)($venue['name'] ?? ''));
    $venueRoomIdentity = receptionist_knowledge_normalized_phrase(trim((string)($venue['name'] ?? '') . ' ' . (string)($venue['room_type'] ?? '')));
    $venueBookingSignal = $venue !== null && ($normalizedMessage === $venueName
        || (($venue['category'] ?? null) === 'Hotel Room' && $normalizedMessage === $venueRoomIdentity)
        || preg_match('/\b(?:want|book|reserve|choose|select|view|show|looking|gusto|mag-?book|i\s+want)\b/i', $lower) === 1);
    $shortLabel = preg_match('/^\s*(?:(?:a|an|the)\s+)?(?:event(?:\s+hall)?|venue|hall|hotel(?:\s+room)?|room|villa|wedding|birthday|corporate)\s*[.!?]*\s*$/i', $message) === 1;
    $bookingSignal = ($route['kind'] ?? null) === 'booking'
        || $shortLabel
        || $groupSize !== null
        || $startDate !== null
        || $venueBookingSignal
        || preg_match('/(?:\b(?:hotel|room)\b[^.!?\n]{0,24}\b(?:budget|cheap|cheapest|mura|save)\b|\b(?:budget|cheap|cheapest|mura|save)\b[^.!?\n]{0,24}\b(?:hotel|room)\b)/i', $lower) === 1
        || preg_match('/(?:\bvilla\b[^.!?\n]{0,24}\b(?:family|private|relax)\b|\b(?:family|private|relax)\b[^.!?\n]{0,24}\bvilla\b)/i', $lower) === 1
        || (($baseSlots['intent'] ?? null) === 'Event Hall' && preg_match('/\bother\b/i', $lower) === 1)
        || (($baseSlots['intent'] ?? null) === 'Hotel Room' && $pendingPreference !== null)
        || ($pendingStep === 'preference' && $pendingPreference !== null)
        || (($baseSlots['intent'] ?? null) === 'Resort Villa' && preg_match('/\b(?:relax|relaxation|rest|family|kids|children|private|privacy)\b/i', $lower) === 1)
        || preg_match('/\b(?:i|we|want|need|gusto|looking)\b[^.!?\n]{0,32}\b(?:wedding|birthday|corporate|celebration|kasal|marriage)\b/i', $lower) === 1
        || (($baseSlots['intent'] ?? null) !== null && preg_match('/\b(?:wedding|birthday|corporate|celebration|kasal|marriage)\b/i', $lower) === 1);
    if (!$bookingSignal) return null;

    $intent = $baseSlots['intent'] ?? null;
    $intentChanged = $category !== null && $intent !== null && $category !== $intent;
    if ($category !== null && (($route['kind'] ?? null) === 'booking' || $intent === null || $shortLabel || $venue !== null)) $intent = $category;
    if ($intent === null && $venueBookingSignal) $intent = is_string($venue['category'] ?? null) ? $venue['category'] : null;
    $preferenceCorrection = ($baseSlots['intent'] ?? null) === 'Hotel Room' && $pendingPreference !== null;
    $patch = [];
    if ($intent !== null) $patch['intent'] = $intent;
    if ($intent === 'Event Hall') {
        if (preg_match('/\b(?:wedding|kasal|marriage)\b/i', $lower)) $patch['occasion'] = 'wedding';
        elseif (preg_match('/\b(?:birthday|party|celebration|celebrate)\b/i', $lower)) $patch['occasion'] = 'celebration';
        elseif (preg_match('/\b(?:corporate|company|seminar|conference)\b/i', $lower)) $patch['occasion'] = 'corporate';
        elseif (preg_match('/\bother\b/i', $lower)) $patch['occasion'] = 'other';
    } elseif ($intent === 'Hotel Room') {
        $preference = $pendingPreference ?? (preg_match('/\b(?:lowest|low|save|cheapest|budget|mura)\b/i', $lower) ? 'save'
            : (preg_match('/\b(?:best\s*fit|fit|match)\b/i', $lower) ? 'best_fit'
                : (preg_match('/\b(?:comfort|higher|premium|deluxe)\b/i', $lower) ? 'comfort' : null)));
        if ($preference !== null) $patch['preference'] = $preference;
    } elseif ($intent === 'Resort Villa') {
        if (preg_match('/\b(?:relax|relaxation|rest)\b/i', $lower)) $patch['purpose'] = 'relaxation';
        elseif (preg_match('/\b(?:family|kids|children)\b/i', $lower)) $patch['purpose'] = 'family';
        elseif (preg_match('/\b(?:private|privacy)\b/i', $lower)) $patch['purpose'] = 'private';
    }
    if ($groupSize !== null && $groupSize > 0) {
        $categoryCapacity = 0;
        foreach ($records as $record) {
            if (($record['kind'] ?? null) === 'venue' && ($record['category'] ?? null) === $intent) {
                $categoryCapacity = max($categoryCapacity, (int)($record['capacity_max'] ?? 0));
            }
        }
        if ($categoryCapacity > 0 && $groupSize > $categoryCapacity) {
            return [
                'mode' => 'knowledge', 'booking_continuation' => true, 'action' => 'ask',
                'reply' => $language === 'fil'
                    ? "Lampas ito sa pinakamataas na naka-publish na capacity na {$categoryCapacity} bisita. Pakilagay ang mas maliit na bilang."
                    : "That is above the largest published capacity of {$categoryCapacity} guests. Please enter a smaller guest count.",
                'faq_id' => null, 'slots' => $baseSlots, 'missing_slots' => ['group_size'],
                'quick_replies' => [], 'quick_actions' => ['start_over'],
            ];
        }
        $patch['group_size'] = $groupSize;
    }
    if (count($bookingDates) > 1) {
        if ($bookingDates[1] > $bookingDates[0]) {
            $patch['start_date'] = $bookingDates[0];
            $patch['end_date'] = $bookingDates[1];
        } else {
            return receptionist_knowledge_pending_booking_clarification('end_date', $language, $baseSlots);
        }
    } elseif ($startDate !== null) {
        $dateBase = $intentChanged ? [] : $baseSlots;
        $explicitCheckOut = preg_match('/\b(?:check[ -]?out|checkout|departure)\b/i', $lower) === 1;
        $explicitCheckIn = preg_match('/\b(?:check[ -]?in|checkin|arrival)\b/i', $lower) === 1;
        if ($intent === 'Hotel Room' && ($pendingStep === 'end_date' || $explicitCheckOut)) {
            if (!empty($dateBase['start_date']) && $startDate <= $dateBase['start_date']) {
                return receptionist_knowledge_pending_booking_clarification('end_date', $language, $baseSlots);
            }
            $patch['end_date'] = $startDate;
        } elseif ($intent === 'Hotel Room' && !$explicitCheckIn && !empty($dateBase['start_date']) && empty($dateBase['end_date'])) {
            // Guests often answer the requested dates in separate messages.
            // Once check-in is known, an unlabeled next date is check-out.
            $patch['end_date'] = $startDate;
        } else {
            $patch['start_date'] = $startDate;
            if ($intent === 'Hotel Room' && !empty($dateBase['end_date']) && $dateBase['end_date'] <= $startDate) {
                $patch['end_date'] = null;
            }
        }
    }
    if ($venueBookingSignal) {
        $patch['active_venue_id'] = (int)($venue['venue_id'] ?? 0);
        if (($venue['room_group_id'] ?? null) !== null) $patch['active_room_group_id'] = (int)$venue['room_group_id'];
    }
    // A category change starts a fresh flow, but values extracted from this
    // message remain in the patch (guest count, date, venue, and preference).
    // This prevents an old event occasion/date/venue from leaking into a new
    // hotel or villa request.
    $mergeBase = $intentChanged ? [] : $baseSlots;
    $clearSlots = array_key_exists('end_date', $patch) && $patch['end_date'] === null ? ['end_date'] : [];
    $slots = receptionist_ai_merge_slots($mergeBase, $patch, $clearSlots);
    $responseSlots = $slots;
    foreach ($clearSlots as $key) $responseSlots[$key] = null;
    if (($slots['intent'] ?? null) === null) {
        return ['mode' => 'knowledge', 'booking_continuation' => true, 'action' => 'ask', 'reply' => $language === 'fil' ? 'Anong venue ang gusto mong i-book — event hall, hotel room, o resort villa?' : 'Which venue would you like to book — an event hall, hotel room, or resort villa?', 'faq_id' => null, 'slots' => $responseSlots, 'clear_slots' => $clearSlots, 'missing_slots' => ['intent'], 'quick_replies' => ['Event', 'Hotel', 'Villa', 'Support FAQs']];
    }
    if ($venueBookingSignal) {
        $name = (string)($venue['name'] ?? 'That venue');
        return ['mode' => 'knowledge', 'booking_continuation' => true, 'action' => 'venue', 'reply' => $language === 'fil' ? "Pinili mo ang {$name}. Bubuksan ko ang venue details para ma-review mo." : "{$name} selected. I’ll open the venue details for you to review.", 'faq_id' => null, 'slots' => $responseSlots, 'clear_slots' => $clearSlots, 'missing_slots' => [], 'quick_replies' => [], 'quick_actions' => ['venue_details', 'venue_change', 'start_over']];
    }
    $missing = [];
    if (($slots['intent'] ?? null) === 'Event Hall' && !array_key_exists('occasion', $slots)) $missing[] = 'occasion';
    if (($slots['intent'] ?? null) === 'Resort Villa' && !array_key_exists('purpose', $slots)) $missing[] = 'purpose';
    if (!array_key_exists('group_size', $slots)) $missing[] = 'group_size';
    if (($slots['intent'] ?? null) === 'Hotel Room' && !array_key_exists('preference', $slots)) $missing[] = 'preference';
    if (!array_key_exists('start_date', $slots)) $missing[] = 'start_date';
    if (($slots['intent'] ?? null) === 'Hotel Room' && !array_key_exists('end_date', $slots)) $missing[] = 'end_date';
    if (!array_key_exists('active_venue_id', $slots)) $missing[] = 'active_venue_id';
    $next = $missing[0] ?? null;
    $labels = ['occasion' => 'event occasion (for example, wedding)', 'purpose' => 'the purpose of your stay', 'group_size' => 'guest count', 'preference' => 'room preference', 'start_date' => ($slots['intent'] ?? null) === 'Hotel Room' ? 'check-in date' : 'event date', 'end_date' => 'check-out date', 'active_venue_id' => 'the venue you want to view'];
    $nextLabel = $labels[$next] ?? 'your venue details';
    if ($language === 'fil') {
        $reply = match ($next) {
            'occasion' => 'Anong uri ng event ito (halimbawa, wedding)? Kakailanganin ko rin ang bilang ng bisita at petsa ng event.',
            'purpose' => 'Ano ang layunin ng iyong stay? Kakailanganin ko rin ang bilang ng bisita at petsa.',
            'group_size' => ($slots['intent'] ?? null) === 'Hotel Room' ? 'Ilang bisita? Kakailanganin ko rin ang check-in at check-out dates.' : 'Ilang bisita? Kakailanganin ko rin ang petsa ng event.',
            'preference' => 'Ano ang mas mahalaga sa room search mo — best fit, pinakamababang presyo, o comfort?',
            'start_date' => 'Ano ang petsa ng event?',
            default => 'Kumpleto na ang pangunahing detalye. Pumili ng venue o sabihin ang pangalan nito.',
        };
    } else {
        $reply = match ($next) {
            'occasion' => 'What kind of event is this (for example, a wedding)? I’ll also need the guest count and event date.',
            'purpose' => 'What is the purpose of your stay? I’ll also need the guest count and date.',
            'group_size' => ($slots['intent'] ?? null) === 'Hotel Room' ? 'How many guests? I’ll then need the check-in and check-out dates.' : 'How many guests? I’ll then need the event date.',
            'preference' => 'What matters most for your room search — best fit, lowest price, or comfort?',
            'start_date' => ($slots['intent'] ?? null) === 'Hotel Room' ? 'What is the check-in date?' : 'What is the event date?',
            'end_date' => 'What is the check-out date?',
            default => 'I have the main details. Choose a venue, or tell me its name to view it.',
        };
    }
    if ($next === 'active_venue_id') {
        $venueLabel = ($slots['intent'] ?? null) === 'Hotel Room' ? 'hotel room' : (($slots['intent'] ?? null) === 'Resort Villa' ? 'resort villa' : 'event hall');
        $reply = $language === 'fil' ? "Kumpleto na ang pangunahing details. Pumili ng {$venueLabel} o sabihin ang pangalan nito para makita." : "I have the main details. Choose a {$venueLabel}, or tell me its name to view it.";
    }
    if ($preferenceCorrection) {
        $preferenceLabel = $slots['preference'] ?? $pendingPreference;
        $acknowledgment = match ($preferenceLabel) {
            'save' => $language === 'fil'
                ? 'Sige — uunahin ko ang pinakamababang nakalistang presyo sa room search mo. '
                : 'Got it — I’ll prioritize the lowest listed price for your room search. ',
            'best_fit' => $language === 'fil'
                ? 'Sige — uunahin ko ang pinakabagay sa grupo mo sa room search. '
                : 'Got it — I’ll prioritize the best fit for your group. ',
            default => $language === 'fil'
                ? 'Sige — uunahin ko ang comfort at mas mataas na room category sa room search mo. '
                : 'Got it — I’ll prioritize comfort and a higher room category. ',
        };
        $reply = $acknowledgment . $reply;
    }
    return ['mode' => 'knowledge', 'booking_continuation' => true, 'action' => 'ask', 'reply' => $reply, 'faq_id' => null, 'slots' => $responseSlots, 'clear_slots' => $clearSlots, 'missing_slots' => $missing, 'quick_replies' => $next === 'active_venue_id' ? [] : [$nextLabel], 'quick_actions' => $next === 'active_venue_id' ? ['venue_list', 'start_over'] : ['start_over']];
}

function receptionist_knowledge_select(array $records, string $message, int $limit = 8): array
{
    $tokens = receptionist_knowledge_tokens($message);
    $category = receptionist_knowledge_category_hint($message);
    $matches = [];
    foreach ($records as $record) {
        if (!is_array($record)) continue;
        $score = receptionist_knowledge_record_score($record, $tokens);
        if ($category !== null && ($record['category'] ?? null) === $category) $score += 2;
        if ($score > 0) $matches[] = ['score' => $score, 'record' => $record];
    }
    usort($matches, static function (array $left, array $right): int {
        $score = $right['score'] <=> $left['score'];
        return $score !== 0 ? $score : strcmp((string)($left['record']['id'] ?? ''), (string)($right['record']['id'] ?? ''));
    });
    return array_map(static fn(array $entry): array => $entry['record'], array_slice($matches, 0, max(1, min($limit, 8))));
}

function receptionist_knowledge_model_candidate_properties(array $record): array
{
    $kind = (string)($record['kind'] ?? '');
    if ($kind === 'venue') {
        $properties = [];
        if (isset($record['base_rate'])) $properties[] = 'price';
        if (isset($record['overnight_rate'])) $properties[] = 'overnight_price';
        if (isset($record['capacity_max']) || isset($record['capacity_styles']) || isset($record['bed_count_min'])) $properties[] = 'capacity';
        if (!empty($record['amenities']) || !empty($record['inclusions'])) $properties[] = 'amenities';
        if (!empty($record['description'])) $properties[] = 'description';
        $properties[] = 'location';
        return $properties;
    }
    return match ($kind) {
        'faq' => ['faq_answer'],
        'policy' => ['policy'],
        'contact' => ['contact', 'location'],
        'event_pricing' => ['event_options'],
        default => [],
    };
}

function receptionist_knowledge_faq_specific_topic_matches(array $faq, string $message): bool
{
    $rules = [
        '/\b(?:pets?|dogs?|cats?)\b/i' => '/\b(?:pets?|dogs?|cats?)\b/i',
        '/\b(?:children|child|kids?)\b/i' => '/\b(?:children|child|kids?)\b/i',
        '/\b(?:outside\s+catering|catering)\b/i' => '/\b(?:outside\s+catering|catering)\b/i',
    ];
    $faqText = (string)($faq['question'] ?? '') . ' ' . implode(' ', is_array($faq['phrases'] ?? null) ? $faq['phrases'] : []);
    foreach ($rules as $messagePattern => $faqPattern) {
        if (preg_match($messagePattern, $message) === 1 && preg_match($faqPattern, $faqText) !== 1) return false;
    }
    return true;
}

function receptionist_knowledge_has_specific_policy_faq(array $records, string $message): bool
{
    foreach ($records as $record) {
        if (($record['kind'] ?? null) !== 'faq' || !receptionist_knowledge_faq_specific_topic_matches($record, $message)) continue;
        return true;
    }
    return false;
}

function receptionist_knowledge_explicit_venue_matches(array $records, string $message, ?string $category = null): array
{
    $messageText = ' ' . receptionist_knowledge_normalized_phrase($message) . ' ';
    if ($messageText === '  ') return [];
    $matches = [];
    foreach ($records as $record) {
        if (($record['kind'] ?? null) !== 'venue' || ($category !== null && ($record['category'] ?? null) !== $category)) continue;
        $name = receptionist_knowledge_normalized_phrase((string)($record['name'] ?? ''));
        $coreName = trim((string)preg_replace('/\b(?:event hall|function hall|hall|hotel room|hotel|room|villa)\b/', '', $name));
        $roomType = receptionist_knowledge_normalized_phrase((string)($record['room_type'] ?? ''));
        $fullNameMatch = $name !== '' && str_contains($messageText, ' ' . $name . ' ');
        $coreNameMatch = strlen($coreName) >= 4 && str_contains($messageText, ' ' . $coreName . ' ');
        $roomTypeMatch = $roomType !== '' && str_contains($messageText, ' ' . $roomType . ' ');
        $hotelMatch = ($record['category'] ?? null) === 'Hotel Room'
            ? (($fullNameMatch || $coreNameMatch) && ($roomTypeMatch || empty($record['room_group_id'])))
            : ($fullNameMatch || $coreNameMatch);
        if (!$hotelMatch) continue;
        $key = implode('|', [(string)($record['category'] ?? ''), (int)($record['venue_id'] ?? 0), (int)($record['room_group_id'] ?? 0)]);
        $matches[$key] = $record;
    }
    return array_values($matches);
}

/** Return all current room groups for explicitly named hotel buildings. */
function receptionist_knowledge_hotel_parent_matches(array $records, string $message): array
{
    $messageText = ' ' . receptionist_knowledge_normalized_phrase($message) . ' ';
    if ($messageText === '  ') return [];
    $matches = [];
    $longestMatch = 0;
    foreach ($records as $record) {
        if (($record['kind'] ?? null) !== 'venue' || ($record['category'] ?? null) !== 'Hotel Room') continue;
        $name = receptionist_knowledge_normalized_phrase((string)($record['name'] ?? ''));
        $coreName = trim((string)preg_replace('/\b(?:hotel room|hotel|room|villa|event hall|hall)\b/', '', $name));
        $matchedLength = 0;
        if ($name !== '' && str_contains($messageText, ' ' . $name . ' ')) $matchedLength = strlen($name);
        if (strlen($coreName) >= 4 && str_contains($messageText, ' ' . $coreName . ' ')) $matchedLength = max($matchedLength, strlen($coreName));
        if ($matchedLength === 0) continue;
        if ($matchedLength > $longestMatch) { $matches = []; $longestMatch = $matchedLength; }
        if ($matchedLength === $longestMatch) $matches[(string)($record['id'] ?? '')] = $record;
    }
    return array_values($matches);
}

function receptionist_knowledge_hotel_room_type_matches(string $message, array $record): bool
{
    $roomType = receptionist_knowledge_normalized_phrase((string)($record['room_type'] ?? ''));
    if ($roomType === '') return false;
    $messageText = ' ' . receptionist_knowledge_normalized_phrase($message) . ' ';
    if (str_contains($messageText, ' ' . $roomType . ' ')) return true;
    $specificRoomType = trim((string)preg_replace('/\broom\b/', '', $roomType));
    return strlen($specificRoomType) >= 4 && str_contains($messageText, ' ' . $specificRoomType . ' ');
}

/**
 * Select a small model-facing candidate set. Exact active venue and hotel
 * room-group context is inserted first; lexical retrieval and a bounded FAQ
 * set follow so paraphrases still have usable choices.
 */
function receptionist_knowledge_model_candidates(array $records, string $message, array $context = [], int $limit = 8, ?string $focusedFaqId = null): array
{
    $limit = max(1, min(8, $limit));
    $selected = [];
    $add = static function ($record) use (&$selected, $limit): void {
        if (!is_array($record) || !is_string($record['id'] ?? null) || count($selected) >= $limit) return;
        $properties = receptionist_knowledge_model_candidate_properties($record);
        if (!$properties || isset($selected[$record['id']])) return;
        $selected[$record['id']] = $record;
    };

    $intent = in_array($context['intent'] ?? null, RECEPTIONIST_KNOWLEDGE_CATEGORIES, true) ? $context['intent'] : null;
    $activeVenueId = receptionist_knowledge_positive_int($context['active_venue_id'] ?? null);
    $activeRoomGroupId = receptionist_knowledge_positive_int($context['active_room_group_id'] ?? null);
    if ($activeVenueId === null && $focusedFaqId !== null && receptionist_knowledge_is_contextual_followup($message)
        && receptionist_knowledge_property_from_message($message) === null) {
        foreach ($records as $record) {
            if (($record['kind'] ?? null) === 'faq' && ($record['id'] ?? null) === $focusedFaqId) {
                $add($record);
                return array_values($selected);
            }
        }
    }
    if ($activeVenueId !== null) {
        $active = array_values(array_filter($records, static function (array $record) use ($activeVenueId, $activeRoomGroupId, $intent): bool {
            if (($record['kind'] ?? null) !== 'venue' || (int)($record['venue_id'] ?? 0) !== $activeVenueId) return false;
            if ($intent !== null && ($record['category'] ?? null) !== $intent) return false;
            if ($activeRoomGroupId !== null) return (int)($record['room_group_id'] ?? 0) === $activeRoomGroupId;
            // Never let a selected hotel building fan out to an arbitrary
            // commercial room group when the exact group was not validated.
            return empty($record['room_group_id']);
        }));
        foreach ($active as $record) $add($record);
    }


    $lower = receptionist_knowledge_normalize_message($message);
    $explicitVenues = receptionist_knowledge_explicit_venue_matches($records, $message);
    $hotelNamesWithoutRoomType = [];
    foreach ($records as $record) {
        if (($record['kind'] ?? null) !== 'venue' || ($record['category'] ?? null) !== 'Hotel Room') continue;
        $name = receptionist_knowledge_normalized_phrase((string)($record['name'] ?? ''));
        $coreName = trim((string)preg_replace('/\b(?:hotel room|hotel|room)\b/', '', $name));
        $roomType = receptionist_knowledge_normalized_phrase((string)($record['room_type'] ?? ''));
        $nameMentioned = ($name !== '' && str_contains(' ' . receptionist_knowledge_normalized_phrase($message) . ' ', ' ' . $name . ' '))
            || (strlen($coreName) >= 4 && str_contains(' ' . receptionist_knowledge_normalized_phrase($message) . ' ', ' ' . $coreName . ' '));
        $roomMentioned = $roomType !== '' && str_contains(' ' . receptionist_knowledge_normalized_phrase($message) . ' ', ' ' . $roomType . ' ');
        if ($nameMentioned && !$roomMentioned) $hotelNamesWithoutRoomType[(int)($record['venue_id'] ?? 0)][] = $record;
    }
    $ambiguousHotelVenueIds = [];
    foreach ($hotelNamesWithoutRoomType as $venueId => $rows) if (count($rows) > 1) $ambiguousHotelVenueIds[(int)$venueId] = true;
    if ($explicitVenues) {
        $explicitGroups = [];
        foreach ($explicitVenues as $record) {
            $roomType = receptionist_knowledge_normalize_message((string)($record['room_type'] ?? ''));
            if ($roomType !== '' && preg_match('/\b' . preg_quote($roomType, '/') . '\b/u', $lower)) $explicitGroups[] = $record;
        }
        $venueChoices = $explicitGroups ?: $explicitVenues;
        $hotelIdentityCount = count(array_filter($venueChoices, static fn(array $record): bool => ($record['category'] ?? null) === 'Hotel Room'));
        $hasRoomIdentity = $activeRoomGroupId !== null || (bool)$explicitGroups;
        foreach ($venueChoices as $record) {
            if (($record['category'] ?? null) === 'Hotel Room' && $hotelIdentityCount > 1 && !$hasRoomIdentity) continue;
            if ($activeVenueId !== null && (int)($record['venue_id'] ?? 0) !== $activeVenueId) continue;
            $add($record);
        }
    }

    if (preg_match('/\b(?:catering|a\/?v|audio|wedding|birthday|event\s+fee|setup\s+fee)\b/i', $lower)) {
        foreach ($records as $record) if (($record['kind'] ?? null) === 'event_pricing') { $add($record); break; }
    }
    if (preg_match('/\b(?:where|address|location|directions?|contact|phone|email|saan|nasaan|lokasyon)\b/i', $lower)) {
        foreach ($records as $record) if (($record['kind'] ?? null) === 'contact') { $add($record); break; }
    }

    $explicitHotelGroups = [];
    foreach ($explicitGroups ?? [] as $record) if (($record['category'] ?? null) === 'Hotel Room') {
        $explicitHotelGroups[(int)($record['venue_id'] ?? 0)] = (int)($record['room_group_id'] ?? 0);
    }
    foreach (receptionist_knowledge_select($records, $message, 6) as $record) {
        if (($record['kind'] ?? null) === 'venue') {
            $candidateVenueId = (int)($record['venue_id'] ?? 0);
            if ($activeVenueId !== null && $candidateVenueId !== $activeVenueId) continue;
            if ($activeRoomGroupId !== null && (int)($record['room_group_id'] ?? 0) !== $activeRoomGroupId) continue;
            if (($record['category'] ?? null) === 'Hotel Room') {
                if ($activeVenueId !== null && $activeRoomGroupId === null && !empty($record['room_group_id'])) continue;
                if (isset($explicitHotelGroups[$candidateVenueId]) && (int)($record['room_group_id'] ?? 0) !== $explicitHotelGroups[$candidateVenueId]) continue;
                if (isset($ambiguousHotelVenueIds[$candidateVenueId])) continue;
            }
        }
        $add($record);
    }
    $tokens = receptionist_knowledge_tokens($message);
    foreach (receptionist_knowledge_faq_matches($records, $tokens, 4) as $record) $add($record);

    // Keep a small FAQ set available to the model for semantic paraphrases
    // that share no obvious lexical tokens with the published question.
    $faqPriority = [];
    if (preg_match('/\b(?:payment|pay|paid|proof|receipt|screenshot|bayad|bayaran)\b/i', $lower)) $faqPriority[] = 'faq-payment-proof';
    if (preg_match('/\b(?:cancel|cancellation|refund|refunds|resched)\b/i', $lower)) $faqPriority[] = 'faq-refund-policy';
    if (preg_match('/\b(?:hotel|room|overnight|nightly)\b/i', $lower)) $faqPriority[] = 'faq-hotel-nightly';
    if (preg_match('/\b(?:event|wedding|inquiry)\b/i', $lower)) $faqPriority[] = 'faq-event-inquiry';
    if (preg_match('/\b(?:date|dates|hold|reserve)\b/i', $lower)) $faqPriority[] = 'faq-booking-window';
    $faqFallbackLimit = $activeVenueId !== null ? 3 : 4;
    foreach ($faqPriority as $faqId) {
        foreach ($records as $record) if (($record['kind'] ?? null) === 'faq' && ($record['id'] ?? null) === $faqId) { $add($record); break; }
    }
    foreach ($records as $record) {
        if (count($selected) >= $limit) break;
        if (($record['kind'] ?? null) !== 'faq') continue;
        if (count(array_filter($selected, static fn(array $item): bool => ($item['kind'] ?? null) === 'faq')) >= $faqFallbackLimit) break;
        $add($record);
    }

    if (!$selected && preg_match('/\b(?:faq|faqs|support|policy|policies|rules|terms|question|tanong)\b/i', $lower)) {
        foreach ($records as $record) if (($record['kind'] ?? null) === 'faq') $add($record);
    }

    return array_values(array_slice($selected, 0, $limit, true));
}

function receptionist_knowledge_candidate_projection(array $records): array
{
    $projected = [];
    foreach (array_slice($records, 0, 8) as $record) {
        if (!is_array($record) || !is_string($record['id'] ?? null)) continue;
        $item = [
            'id' => $record['id'],
            'kind' => (string)($record['kind'] ?? ''),
            'properties' => receptionist_knowledge_model_candidate_properties($record),
        ];
        foreach (['category', 'name', 'room_type', 'venue_id', 'room_group_id', 'question', 'phrases'] as $key) {
            if (array_key_exists($key, $record)) $item[$key] = $record[$key];
        }
        if (($record['kind'] ?? null) === 'venue') {
            foreach (['base_rate', 'rate_unit', 'overnight_rate', 'capacity_base', 'capacity_max', 'capacity_styles', 'bed_count_min', 'bed_count_max', 'amenities', 'inclusions', 'description'] as $key) {
                if (array_key_exists($key, $record)) $item[$key] = $record[$key];
            }
        } elseif (($record['kind'] ?? null) === 'faq') {
            $item['answer_excerpt'] = receptionist_knowledge_excerpt((string)($record['answer'] ?? ''), 360);
        } elseif (($record['kind'] ?? null) === 'policy') {
            $item['text_excerpt'] = receptionist_knowledge_excerpt((string)($record['text'] ?? ''), 360);
        } elseif (($record['kind'] ?? null) === 'contact') {
            $item['available_fields'] = array_values(array_intersect(['name', 'address', 'phone', 'email'], array_keys($record['contact'] ?? [])));
        } elseif (($record['kind'] ?? null) === 'event_pricing') {
            $item['modifiers'] = array_slice($record['modifiers'] ?? [], 0, 6);
        }
        $projected[] = $item;
    }
    return $projected;
}

function receptionist_knowledge_is_social_input(string $message): bool
{
    $text = trim(receptionist_knowledge_normalize_message($message));
    $text = trim((string)preg_replace('/[.!?,;:]+\s*$/u', '', $text));
    if ($text === '') return false;
    return preg_match('/\A(?:hi|hello|hey|good\s+(?:morning|afternoon|evening)|kumusta|kamusta)(?:\s+there|\s+po)?\z/iu', $text) === 1
        || preg_match('/\A(?:thanks?|thank\s+you|many\s+thanks|salamat|maraming\s+salamat)(?:\s+(?:so\s+much|po))?\z/iu', $text) === 1
        || preg_match('/\A(?:who\s+are\s+you|what\s+are\s+you|are\s+you\s+(?:a\s+)?(?:bot|virtual\s+receptionist)|sino\s+ka|ano\s+ka)\z/iu', $text) === 1
        || preg_match('/\A(?:how\s+are\s+you|how\s+is\s+it\s+going|how\s+is\s+everything|what\s+is\s+up|kumusta\s+ka|kamusta\s+ka)\z/iu', $text) === 1;
}

function receptionist_knowledge_is_capability_request(string $message): bool
{
    $text = trim(receptionist_knowledge_normalize_message($message));
    $text = trim((string)preg_replace('/[.!?,;:]+\s*$/u', '', $text));
    return preg_match('/\A(?:what\s+(?:can|do)\s+you\s+(?:help|assist)(?:\s+me)?(?:\s+with)?|how\s+can\s+you\s+(?:help|assist)(?:\s+me)?|what\s+do\s+you\s+do|what\s+are\s+you\s+able\s+to\s+do|ano\s+ang\s+(?:maitutulong|tulong)\s+mo(?:\s+sa\s+akin)?|paano\s+mo\s+ako\s+matutulungan)\z/iu', $text) === 1;
}

function receptionist_knowledge_social_reply(string $message, string $language): string
{
    $text = trim(receptionist_knowledge_normalize_message($message));
    $text = trim((string)preg_replace('/[.!?,;:]+\s*$/u', '', $text));
    $thanks = preg_match('/\A(?:thanks?|thank\s+you|many\s+thanks|salamat|maraming\s+salamat)(?:\s+(?:so\s+much|po))?\z/iu', $text) === 1;
    $identity = preg_match('/\A(?:who\s+are\s+you|what\s+are\s+you|are\s+you\s+(?:a\s+)?(?:bot|virtual\s+receptionist)|sino\s+ka|ano\s+ka)\z/iu', $text) === 1;
    $status = preg_match('/\A(?:how\s+are\s+you|how\s+is\s+it\s+going|how\s+is\s+everything|what\s+is\s+up|kumusta\s+ka|kamusta\s+ka)\z/iu', $text) === 1;
    if ($language === 'fil') {
        if ($thanks) return 'Walang anuman! Matutulungan kita sa venue details, FAQs, at booking guidance.';
        if ($identity) return 'Ako ang virtual receptionist ng M.I. Sevilla Resort & Events Place. Matutulungan kita sa venue details, stays, FAQs, at booking guidance.';
        if ($status) return 'Handa akong tumulong sa venue details, stays, at mga tanong tungkol sa resort.';
        return 'Kumusta! Ako ang virtual receptionist ng M.I. Sevilla Resort & Events Place. Paano kita matutulungan?';
    }
    if ($language === 'taglish') {
        if ($thanks) return 'You’re welcome! I can help with venue details, resort FAQs, and booking guidance.';
        if ($identity) return 'I’m the virtual receptionist for M.I. Sevilla Resort & Events Place. I can help with venue details, stays, resort FAQs, and booking guidance.';
        if ($status) return 'I can help with venue details, stays, and resort questions. What would you like to know?';
        return 'Hello! Ako ang virtual receptionist ng M.I. Sevilla Resort & Events Place. How can I help you?';
    }
    if ($thanks) return 'You’re welcome! I can help with venue details, resort FAQs, and booking guidance.';
    if ($identity) return 'I’m the virtual receptionist for M.I. Sevilla Resort & Events Place. I can help with venue details, stays, resort FAQs, and booking guidance.';
    if ($status) return 'I can help with venue details, stays, and resort questions. What would you like to know?';
    return 'Hello! I’m the virtual receptionist for M.I. Sevilla Resort & Events Place. How can I help you today?';
}

function receptionist_knowledge_is_question(string $message): bool
{
    $lower = receptionist_knowledge_normalize_message($message);
    return str_contains($message, '?') || preg_match('/\A\s*(?:what|which|when|where|who|why|how|is|are|am|do|does|did|can|could|will|would|may|any|ano|alin|kailan|saan|sino|bakit|paano|ilan|gaano|may|pwede|puwede|meron|meron\s+bang)\b/i', $lower) === 1;
}

function receptionist_knowledge_is_active_venue_property_question(string $message, array $context): bool
{
    if (receptionist_knowledge_positive_int($context['active_venue_id'] ?? null) === null || !receptionist_knowledge_is_question($message)) return false;
    if (receptionist_knowledge_is_social_input($message)) return false;
    // Keep explicitly policy/booking FAQs available in a venue conversation.
    if (preg_match('/\b(?:payment|pay|paid|proof|receipt|cancel\w*|refund|policy|policies|rules|terms|check[- ]?in|check[- ]?out|booking\s+process|booking\s+status|reservation\s+status|dates?\s+held|hold\s+dates?)\b/i', $message) === 1) return false;
    return true;
}

function receptionist_knowledge_is_room_details_request(string $message, array $context = []): bool
{
    $lower = receptionist_knowledge_normalize_message($message);
    if (preg_match('/\b(?:details?|description)\b/i', $lower) !== 1) return false;
    if (($context['intent'] ?? null) === 'Hotel Room') return true;
    if (in_array($context['intent'] ?? null, RECEPTIONIST_KNOWLEDGE_CATEGORIES, true)) return false;
    return preg_match('/\b(?:hotel|room|it|its|this|that|those|their)\b/i', $lower) === 1;
}

function receptionist_knowledge_is_comparison_request(string $message): bool
{
    $lower = receptionist_knowledge_normalize_message($message);
    return preg_match('/\b(?:why\b.{0,100}\b(?:better|best|recommended|recommendation|fit)|what\s+makes\b.{0,100}\b(?:better|best|recommended)|compare\b|difference\s+between\b)\b/i', $lower) === 1;
}

function receptionist_knowledge_pending_room_comparison_message(array $history): ?string
{
    for ($index = count($history) - 1; $index >= 0; $index--) {
        if (($history[$index]['role'] ?? null) !== 'assistant') continue;
        for ($prior = $index - 1; $prior >= 0; $prior--) {
            if (($history[$prior]['role'] ?? null) !== 'user' || !is_string($history[$prior]['content'] ?? null)) continue;
            return receptionist_knowledge_is_comparison_request($history[$prior]['content']) ? $history[$prior]['content'] : null;
        }
        return null;
    }
    return null;
}

function receptionist_knowledge_is_short_why(string $message): bool
{
    return preg_match('/\A\s*(?:why|how\s+come|bakit)\s*[?.!]*\s*\z/i', $message) === 1;
}

function receptionist_knowledge_pending_room_followup(array $history): ?string
{
    $lastAssistantIndex = null;
    for ($index = count($history) - 1; $index >= 0; $index--) {
        if (($history[$index]['role'] ?? null) === 'assistant') { $lastAssistantIndex = $index; break; }
    }
    if ($lastAssistantIndex === null || !is_string($history[$lastAssistantIndex]['content'] ?? null)) return null;
    $clarification = receptionist_knowledge_normalize_message($history[$lastAssistantIndex]['content']);
    if (preg_match('/\b(?:which exact room type|aling eksaktong room type)\b/i', $clarification) !== 1) return null;
    for ($index = $lastAssistantIndex - 1; $index >= 0; $index--) {
        if (($history[$index]['role'] ?? null) !== 'user' || !is_string($history[$index]['content'] ?? null)) continue;
        if (receptionist_knowledge_is_comparison_request($history[$index]['content'])) return 'comparison';
        if (receptionist_knowledge_is_room_details_request($history[$index]['content'])) return 'details';
        return null;
    }
    return null;
}

function receptionist_knowledge_pending_room_parent_message(array $history): ?string
{
    $lastAssistantIndex = null;
    for ($index = count($history) - 1; $index >= 0; $index--) {
        if (($history[$index]['role'] ?? null) === 'assistant') { $lastAssistantIndex = $index; break; }
    }
    if ($lastAssistantIndex === null || !is_string($history[$lastAssistantIndex]['content'] ?? null)) return null;
    $clarification = receptionist_knowledge_normalize_message($history[$lastAssistantIndex]['content']);
    if (preg_match('/\b(?:which exact room type|aling eksaktong room type)\b/i', $clarification) !== 1) return null;
    for ($index = $lastAssistantIndex - 1; $index >= 0; $index--) {
        if (($history[$index]['role'] ?? null) === 'user' && is_string($history[$index]['content'] ?? null)) return $history[$index]['content'];
    }
    return null;
}

function receptionist_knowledge_is_fact_request(string $message, array $context = []): bool
{
    if (receptionist_knowledge_is_social_input($message)) return false;
    $lower = receptionist_knowledge_normalize_message($message);
    $question = receptionist_knowledge_is_question($message);
    $factSignal = preg_match('/\b(?:price|prices|rate|rates|cost|much|capacity|fit|guests?|pax|amenit\w*|include\w*|pool|wifi|parking|aircon|breakfast|address|location|where|located|directions?|availability|available|vacant|check[- ]?in|check[- ]?out|policy|policies|cancel\w*|refund|payment|pay|pets?|children|kids|rules|allowed|permitted|walk.?in|magkano|presyo|bayad|ilan|kasya|amenidad|kasama|saan|nasaan|bakante|patakaran|aso|pusa|catering|outside)\b/i', $lower) === 1;
    $activeVenue = receptionist_knowledge_positive_int($context['active_venue_id'] ?? null) !== null;
    $resortSubject = preg_match('/\b(?:resort|venue|hall|hotel|room|villa|stay|booking|reservation|event|your|our|we|you|reception|receptionist|sevilla)\b/i', $lower) === 1;
    $specificSubject = preg_match('/\b(?:outside|catering|pets?|children|kids|parking|pool|wifi|aircon|check[- ]?in|walk.?in|villa|hotel|room|hall|venue|event|resort)\b/i', $lower) === 1;
    $yesNoFactQuestion = $question && preg_match('/\b(?:do\s+you|does\s+it|does\s+this|does\s+that|does\s+the|does\s+your|is\s+there|are\s+there|can\s+i|can\s+we)\b/i', $lower) === 1;
    $roomDetails = receptionist_knowledge_is_room_details_request($message, $context);
    $comparison = receptionist_knowledge_is_comparison_request($message);
    return $roomDetails
        || ($comparison && ($question || $activeVenue || $resortSubject || $specificSubject))
        || ($factSignal && ($question || $resortSubject || $activeVenue || $specificSubject))
        || ($question && ($activeVenue || $resortSubject || $yesNoFactQuestion));
}

function receptionist_knowledge_property_from_message(string $message): ?string
{
    $lower = receptionist_knowledge_normalize_message($message);
    if (preg_match('/\b(?:available|availability|vacancy|vacant|bakante|may\s+slot|may\s+bakante)\b/i', $lower)) return 'availability';
    if (preg_match('/\b(?:price|prices|rates?|cost|how\s+much|magkano|presyo|bayad|fee|rent|per\s+day|per\s+night|singil|halaga|bayarin|mahal|mura|pinakamura)\b/i', $lower)) return 'price';
    if (preg_match('/\b(?:capacity|fit|guests?|pax|ilang|kasya|maximum|how\s+many|ilang\s+tao|pwedeng\s+tao|ilan\s+kaya|ilan)\b/i', $lower)) return 'capacity';
    if (preg_match('/\b(?:address|location|located|directions?|saan|nasaan|paano\s+pumunta|direksyon|lokasyon|where\s+(?:is|are|can\s+i\s+find))\b/i', $lower)) return 'location';
    if (preg_match('/\b(?:amenit\w*|included|inclusion|facilit\w*|what.*(?:include|have)|ano.*(?:kasama|meron)|wifi|pool|parking|bed|breakfast|aircon|tv|kitchen|meron\s+ba|may\s+ba|available\s+ba)\b/i', $lower)) return 'amenities';
    if (preg_match('/\b(?:tell\s+me\s+more|more\s+details?|describe|what\s+is\s+(?:it|this|that)|ano\s+ito)\b/i', $lower)) return 'description';
    if (preg_match('/\b(?:cancel\w*|refund|payment|pay|policy|policies|rules|terms|check[- ]?in|check[- ]?out|walk.?in|children|kids|pets?)\b/i', $lower)) return 'faq_answer';
    return null;
}

function receptionist_knowledge_is_contextual_followup(string $message): bool
{
    $lower = receptionist_knowledge_normalize_message($message);
    return preg_match('/\b(?:what\s+about|how\s+about|is\s+it|are\s+they|does\s+it|do\s+they|can\s+it|what\s+if)\b|\b(?:it|its|this|that|there|those|them|same)\b/i', $lower) === 1;
}

function receptionist_knowledge_unconfirmed_reply(string $message, string $language = 'en', array $context = []): ?array
{
    if (!receptionist_knowledge_is_fact_request($message, $context)) return null;
    $language = in_array($language, ['en', 'fil', 'taglish'], true) ? $language : 'en';
    $reply = match ($language) {
        'fil' => 'Wala akong kumpirmadong sagot mula sa kasalukuyang naka-publish na venue at FAQ information. Makipag-ugnayan sa reception para makuha ang tamang detalye.',
        'taglish' => 'I can’t confirm that from the published venue and FAQ information. Please contact reception para makuha ang tamang detalye.',
        default => 'I can’t confirm that from the published venue and FAQ information. Please contact reception for the accurate details.',
    };
    return ['mode' => 'knowledge', 'action' => 'ask', 'reply' => $reply, 'faq_id' => null, 'slots' => $context, 'missing_slots' => [], 'quick_replies' => ['Support FAQs'], 'show_support_contact_cta' => true];
}

function receptionist_knowledge_local_fallback(array $records, string $message, string $language = 'en', array $context = [], array $history = [], ?string $focusedFaqId = null): ?array
{
    return receptionist_knowledge_reply($records, $message, $language, $context, $history, $focusedFaqId)
        ?? receptionist_knowledge_unconfirmed_reply($message, $language, $context);
}

/** Compose a factual answer only from a currently published, allowed record. */
function receptionist_knowledge_compose_selection(array $records, array $candidates, $id, $property, string $language = 'en', array $context = [], string $message = ''): ?array
{
    if (!is_string($id) || $id === '' || !is_string($property)) return null;
    $allowed = ['price', 'overnight_price', 'capacity', 'amenities', 'description', 'faq_answer', 'policy', 'contact', 'location', 'event_options', 'availability', 'unknown'];
    if (!in_array($property, $allowed, true)) return null;
    $candidateIds = array_fill_keys(array_values(array_filter(array_map(static fn(array $row): ?string => is_string($row['id'] ?? null) ? $row['id'] : null, $candidates))), true);
    if (!isset($candidateIds[$id])) return null;
    $record = null;
    foreach ($records as $item) if (is_array($item) && ($item['id'] ?? null) === $id) { $record = $item; break; }
    if ($record === null) return null;
    $candidateRecord = null;
    foreach ($candidates as $item) if (is_array($item) && ($item['id'] ?? null) === $id) { $candidateRecord = $item; break; }
    if ($candidateRecord === null || (!in_array($property, receptionist_knowledge_model_candidate_properties($candidateRecord), true) && !in_array($property, ['availability', 'unknown'], true))) return null;
    if (($record['kind'] ?? null) === 'venue') {
        $activeId = receptionist_knowledge_positive_int($context['active_venue_id'] ?? null);
        $activeGroup = receptionist_knowledge_positive_int($context['active_room_group_id'] ?? null);
        if ($activeId !== null && (int)($record['venue_id'] ?? 0) !== $activeId) return null;
        if ($activeGroup !== null && (int)($record['room_group_id'] ?? 0) !== $activeGroup) return null;
        if (($record['category'] ?? null) === 'Hotel Room' && $activeId !== null && $activeGroup === null && !empty($record['room_group_id'])) return null;
    }
    $language = in_array($language, ['en', 'fil', 'taglish'], true) ? $language : 'en';
    if (($record['kind'] ?? null) === 'faq' && $property === 'faq_answer' && $message !== '') {
        if (!receptionist_knowledge_faq_specific_topic_matches($record, $message)) return null;
        if (receptionist_knowledge_is_active_venue_property_question($message, $context)) return null;
    }
    $answerContext = $context;
    if (($record['kind'] ?? null) === 'venue' && receptionist_knowledge_positive_int($context['active_venue_id'] ?? null) === null) {
        $normalizedMessage = receptionist_knowledge_normalize_message($message);
        $name = receptionist_knowledge_normalize_message((string)($record['name'] ?? ''));
        $firstNamePart = strtok($name, ' ') ?: '';
        $roomType = receptionist_knowledge_normalize_message((string)($record['room_type'] ?? ''));
        $venueMentioned = ($name !== '' && preg_match('/\b' . preg_quote($name, '/') . '\b/u', $normalizedMessage) === 1)
            || (strlen($firstNamePart) >= 4 && preg_match('/\b' . preg_quote($firstNamePart, '/') . '\b/u', $normalizedMessage) === 1);
        $roomGroupMentioned = $roomType !== '' && preg_match('/\b' . preg_quote($roomType, '/') . '\b/u', $normalizedMessage) === 1;
        $sameVenueRows = array_values(array_filter($records, static fn(array $item): bool => ($item['kind'] ?? null) === 'venue' && (int)($item['venue_id'] ?? 0) === (int)($record['venue_id'] ?? 0)));
        if ($venueMentioned && (($record['category'] ?? null) !== 'Hotel Room' || $roomGroupMentioned || count($sameVenueRows) === 1)) {
            $answerContext['intent'] = $record['category'];
            $answerContext['active_venue_id'] = (int)$record['venue_id'];
            if (!empty($record['room_group_id']) && ($roomGroupMentioned || count($sameVenueRows) === 1)) {
                $answerContext['active_room_group_id'] = (int)$record['room_group_id'];
            }
        }
    }
    $label = trim((string)($record['name'] ?? '') . (isset($record['room_type']) ? ' — ' . $record['room_type'] : ''));
    $reply = null;
    $contactCta = false;
    if (($record['kind'] ?? null) === 'faq' && $property === 'faq_answer') {
        $reply = receptionist_faq_text($record['answer'] ?? '', 3000);
    } elseif (($record['kind'] ?? null) === 'policy' && $property === 'policy') {
        $reply = receptionist_knowledge_excerpt((string)($record['text'] ?? ''), 1500);
    } elseif (($record['kind'] ?? null) === 'event_pricing' && $property === 'event_options') {
        $lines = [];
        foreach (array_slice($record['modifiers'] ?? [], 0, 6) as $modifier) {
            if (!is_array($modifier) || !is_numeric($modifier['amount'] ?? null)) continue;
            $lines[] = (string)($modifier['label'] ?? 'Option') . ': ' . receptionist_knowledge_money((float)$modifier['amount']) . ' ' . (string)($modifier['unit'] ?? '');
        }
        if ($lines) $reply = implode("\n", $lines) . "\n" . (string)($record['qualifier'] ?? 'Staff confirms the final quotation.');
    } elseif (($record['kind'] ?? null) === 'contact' && in_array($property, ['contact', 'location'], true)) {
        $contact = is_array($record['contact'] ?? null) ? $record['contact'] : [];
        $fields = $property === 'location' ? ['address' => 'Address'] : ['name' => 'Name', 'address' => 'Address', 'phone' => 'Phone', 'email' => 'Email'];
        $lines = [];
        foreach ($fields as $key => $fieldLabel) if (is_string($contact[$key] ?? null) && trim($contact[$key]) !== '') $lines[] = $fieldLabel . ': ' . trim($contact[$key]);
        if ($lines) $reply = ($language === 'fil' ? 'Narito kung paano kami maabot:' : 'Here’s how to reach us:') . "\n" . implode("\n", $lines);
    } elseif (($record['kind'] ?? null) === 'venue') {
        if ($property === 'location') {
            foreach ($records as $item) if (($item['kind'] ?? null) === 'contact' && !empty($item['contact']['address'])) {
                $reply = ($language === 'fil' ? 'Matatagpuan ang resort sa ' : 'The resort is located at ') . $item['contact']['address'] . ($language === 'fil' ? '. ' : '. ') . ($language === 'fil' ? 'Walang hiwalay na venue address na naka-publish.' : 'A separate venue address is not published.');
                break;
            }
        } elseif ($property === 'price' && isset($record['base_rate'])) {
            $unit = (string)($record['rate_unit'] ?? '');
            $reply = ($language === 'fil' ? 'Starting rate para sa ' : 'Starting rate for ') . $label . ': ' . receptionist_knowledge_money((float)$record['base_rate']) . ($unit === 'per night' ? '/night' : ($unit === 'per day' ? '/day' : '')) . '. ' . ($record['category'] === 'Event Hall' ? ($language === 'fil' ? 'Maaaring mag-iba ang final quote; kukumpirmahin ito ng staff.' : 'The final event quote may vary and is confirmed by staff.') : '');
            if (isset($record['overnight_rate'])) $reply .= ($language === 'fil' ? "\nOvernight rate: " : "\nOvernight rate: ") . receptionist_knowledge_money((float)$record['overnight_rate']) . '/night.';
        } elseif ($property === 'overnight_price' && isset($record['overnight_rate'])) {
            $reply = 'Overnight starting rate for ' . $label . ': ' . receptionist_knowledge_money((float)$record['overnight_rate']) . '/night.';
        } elseif ($property === 'capacity' && (isset($record['capacity_max']) || !empty($record['capacity_styles']) || isset($record['bed_count_min']))) {
            $parts = [];
            if (isset($record['capacity_max'])) $parts[] = 'up to ' . number_format((int)$record['capacity_max']) . ' guests';
            foreach (($record['capacity_styles'] ?? []) as $style => $count) if (is_numeric($count)) $parts[] = $style . ' setup: ' . number_format((int)$count);
            if (isset($record['bed_count_min'])) $parts[] = number_format((int)$record['bed_count_min']) . (isset($record['bed_count_max']) && $record['bed_count_max'] !== $record['bed_count_min'] ? '–' . number_format((int)$record['bed_count_max']) : '') . ' beds';
            if ($parts) $reply = $label . ' capacity: ' . implode('; ', $parts) . '.';
        } elseif ($property === 'amenities' && (!empty($record['amenities']) || !empty($record['inclusions']))) {
            $items = array_values(array_unique(array_merge($record['amenities'] ?? [], $record['inclusions'] ?? [])));
            $featureAliases = [
                'pool' => ['pool', 'swim'], 'parking' => ['parking', 'park', 'car park'], 'wifi' => ['wifi', 'wi-fi', 'internet'],
                'breakfast' => ['breakfast', 'almusal'], 'air conditioning' => ['aircon', 'air condition'],
                'tv' => ['tv', 'television'], 'kitchen' => ['kitchen', 'kusina'],
            ];
            $requestedFeature = null;
            foreach ($featureAliases as $feature => $aliases) foreach ($aliases as $alias) {
                if (preg_match('/\b' . preg_quote($alias, '/') . '\b/i', receptionist_knowledge_normalize_message($message))) { $requestedFeature = $feature; break 2; }
            }
            if ($requestedFeature === null && preg_match('/\b(?:have|has|offer(?:s)?|include(?:s)?|got)\s+(?:a|an|the\s+)?([\p{L}][\p{L}\p{N}-]*(?:\s+[\p{L}][\p{L}\p{N}-]*){0,2})/iu', $message, $featureMatch) === 1) {
                $requestedFeature = receptionist_knowledge_normalize_message(trim($featureMatch[1]));
            }
            if ($requestedFeature === null && preg_match('/\b(?:is|are)\s+there\s+(?:a|an|the\s+)?([\p{L}][\p{L}\p{N}-]*(?:\s+[\p{L}][\p{L}\p{N}-]*){0,2})/iu', $message, $featureMatch) === 1) {
                $requestedFeature = receptionist_knowledge_normalize_message(trim($featureMatch[1]));
            }
            if ($requestedFeature !== null) {
                $requestedAliases = $featureAliases[$requestedFeature] ?? [$requestedFeature];
                foreach ($items as $item) if (preg_match('/' . implode('|', array_map(static fn(string $alias): string => preg_quote($alias, '/'), $requestedAliases)) . '/i', (string)$item)) {
                    $reply = $requestedFeature . ' is listed for ' . $label . '.';
                    break;
                }
                if ($reply === null) {
                    $reply = 'I don’t see ' . $requestedFeature . ' listed for ' . $label . ', so I can’t confirm whether it is offered. Please contact reception.';
                    $contactCta = true;
                }
            } elseif ($items) {
                $reply = $label . ' published amenities and inclusions: ' . implode(', ', array_slice($items, 0, 12)) . '.';
            }
        } elseif ($property === 'description' && !empty($record['description'])) {
            $reply = receptionist_knowledge_excerpt((string)$record['description'], 800);
        }
    }
    if (!is_string($reply) || trim($reply) === '') {
        $reply = match ($language) {
            'fil' => 'Wala akong kumpirmadong detalye tungkol dito sa kasalukuyang naka-publish na impormasyon. Makipag-ugnayan sa reception para matiyak ang tamang sagot.',
            'taglish' => 'Wala akong confirmed published detail tungkol diyan. Please contact reception para makuha ang tamang sagot.',
            default => 'That detail is not confirmed in the published venue information. Please contact reception for the accurate answer.',
        };
        $contactCta = true;
    }
    return ['mode' => 'knowledge', 'action' => 'ask', 'reply' => $reply, 'faq_id' => ($record['kind'] ?? null) === 'faq' ? $record['id'] : null, 'slots' => $answerContext, 'missing_slots' => [], 'quick_replies' => [], 'show_support_contact_cta' => $contactCta];
}

function receptionist_knowledge_category_hint(string $message): ?string
{
    $lower = receptionist_knowledge_normalize_message($message);
    if (preg_match('/\b(villa|overnight stay|staycation)\b/i', $lower)) return 'Resort Villa';
    if (preg_match('/\b(event|events|event hall|function hall|reception|hall|wedding|birthday|corporate|party|venue)\b/i', $lower)) return 'Event Hall';
    if (preg_match('/\b(hotel|room|rooms|accommodation|stay|overnight|nightly|check[- ]?in)\b/i', $lower)) return 'Hotel Room';
    return null;
}

function receptionist_knowledge_explicit_category_switch(string $message): ?string
{
    $lower = receptionist_knowledge_normalize_message($message);
    $matches = [];
    if (preg_match('/\b(?:villa|staycation)\b/i', $lower)) $matches[] = 'Resort Villa';
    if (preg_match('/\b(?:hotel|rooms?|accommodations?|overnight stay)\b/i', $lower)) $matches[] = 'Hotel Room';
    if (preg_match('/\b(?:event halls?|function halls?|event spaces?|weddings?|birthdays?|corporate events?|receptions?|parties|party venue)\b/i', $lower)) $matches[] = 'Event Hall';
    $matches = array_values(array_unique($matches));
    return count($matches) === 1 ? $matches[0] : null;
}

/**
 * Detect a booking opener that carries no category or usable booking detail.
 * A generic opener must replace the prior flow rather than inherit its slots.
 */
function receptionist_knowledge_is_generic_booking_start(string $message): bool
{
    $lower = receptionist_knowledge_normalize_message($message);
    if (receptionist_knowledge_category_hint($message) !== null) return false;
    if (receptionist_knowledge_booking_group_size($message) !== null || receptionist_knowledge_booking_dates($message) !== []) return false;
    if (preg_match('/\b(?:wedding|kasal|marriage|birthday|party|celebration|corporate|seminar|conference|family|private|relax(?:ation)?|budget|cheap|cheapest|mura|save|comfort|premium|deluxe)\b/i', $lower) === 1) return false;
    return preg_match('/\b(?:book|booking|reserve|reservation|mag[- ]?book|magpa[- ]?book|mag[- ]?reserve|magpa[- ]?reserve|magpareserba|i[- ]?book|ipa[- ]?book)\b/i', $lower) === 1;
}

function receptionist_knowledge_is_memory_request(string $message): bool
{
    $lower = receptionist_knowledge_normalize_message($message);
    return preg_match('/\bwhat did i (?:say|ask|tell you)\b|\bwhat was i (?:looking for|asking)\b|\bano (?:ang )?(?:sinabi|sinagot) ko\b|\bano yung sinabi ko\b/i', $lower) === 1;
}

function receptionist_knowledge_memory_reply(string $message, string $language, array $baseSlots, array $history): ?array
{
    if (!receptionist_knowledge_is_memory_request($message)) return null;
    if (function_exists('receptionist_ai_public_history')) $history = receptionist_ai_public_history($history);
    $userMessages = [];
    foreach ($history as $turn) {
        if (($turn['role'] ?? null) !== 'user' || !is_string($turn['content'] ?? null)) continue;
        if (receptionist_knowledge_is_memory_request($turn['content'])) continue;
        $userMessages[] = $turn['content'];
    }
    $previous = $userMessages ? end($userMessages) : null;
    if (!is_string($previous)) {
        $reply = match ($language) {
            'fil' => 'Wala pa akong naunang mensahe sa chat na ito. Ano ang gusto mong itanong?',
            'taglish' => 'Wala pa akong earlier message sa chat na ito. Ano ang gusto mong itanong?',
            default => 'I don’t have an earlier message in this chat yet. What would you like to ask?'
        };
    } else {
        $reply = match ($language) {
            'fil' => 'Ang huli mong sinabi ay: “' . $previous . '”.',
            'taglish' => 'Your last message was: “' . $previous . '”.',
            default => 'Your last message was: “' . $previous . '”.'
        };
    }
    return ['mode' => 'knowledge', 'action' => 'ask', 'reply' => $reply, 'faq_id' => null, 'slots' => $baseSlots, 'missing_slots' => [], 'quick_replies' => ['Continue', 'Support FAQs']];
}

function receptionist_knowledge_walk_in_reply(string $message, string $language, array $baseSlots): ?array
{
    $lower = receptionist_knowledge_normalize_message($message);
    if (preg_match('/\bwalk[ -]?ins?\b/i', $lower) !== 1) return null;

    $reply = match ($language) {
        'fil' => 'Hindi ko makukumpirma rito kung tumatanggap ng walk-in. Makipag-ugnayan muna sa reception bago pumunta para tiyakin ang availability.',
        'taglish' => 'I can’t confirm walk-in availability here. Please contact reception muna before visiting to check.',
        default => 'I can’t confirm walk-in availability here. Please contact reception before visiting to check.',
    };
    return [
        'mode' => 'knowledge',
        'action' => 'ask',
        'reply' => $reply,
        'faq_id' => null,
        'slots' => $baseSlots,
        'missing_slots' => [],
        'quick_replies' => ['Support FAQs'],
        'show_support_contact_cta' => true,
    ];
}

function receptionist_knowledge_availability_reply(array $records, string $message, string $language, array $baseSlots, ?string $category): ?array
{
    $lower = receptionist_knowledge_normalize_message($message);
    $availabilityIntent = preg_match('/\b(?:available|availability|vacancy|vacant|bakante|may\s+slot|may\s+bakante)\b/i', $lower) === 1;
    $amenityIntent = preg_match('/\b(?:amenit|included|inclusion|facilit|what.*(?:include|have)|ano.*(?:kasama|meron)|wifi|pool|parking|bed|meron\s+ba|may\s+ba|available\s+ba|meron\s+bang|may\s+bang)\b/i', $lower) === 1;
    if (!$availabilityIntent || $amenityIntent) return null;

    $language = in_array($language, ['en', 'fil', 'taglish'], true) ? $language : 'en';
    $currentIntent = $baseSlots['intent'] ?? null;
    $intent = in_array($category, RECEPTIONIST_KNOWLEDGE_CATEGORIES, true) ? $category : $currentIntent;
    $messageDates = receptionist_knowledge_booking_dates($message);
    $patch = $intent !== null ? ['intent' => $intent] : [];
    $clearKeys = [];
    if ($currentIntent !== null && $intent !== null && $currentIntent !== $intent) {
        $baseSlots = [];
        $clearKeys = ['occasion', 'purpose', 'preference', 'group_size', 'start_date', 'end_date', 'active_venue_id', 'active_room_group_id'];
    }
    if (count($messageDates) > 1) {
        $patch['start_date'] = $messageDates[0];
        if ($messageDates[1] > $messageDates[0]) $patch['end_date'] = $messageDates[1];
    } elseif ($messageDates) {
        $date = $messageDates[0];
        $explicitCheckOut = preg_match('/\b(?:check[ -]?out|checkout|departure)\b/i', $lower) === 1;
        $explicitCheckIn = preg_match('/\b(?:check[ -]?in|checkin|arrival)\b/i', $lower) === 1;
        if ($intent === 'Hotel Room' && $explicitCheckOut) {
            $patch['end_date'] = $date;
        } elseif ($intent === 'Hotel Room' && !$explicitCheckIn && !empty($baseSlots['start_date']) && empty($baseSlots['end_date'])) {
            $patch['end_date'] = $date;
        } else {
            $patch['start_date'] = $date;
            if ($intent === 'Hotel Room' && !empty($baseSlots['end_date']) && $baseSlots['end_date'] <= $date) {
                $patch['end_date'] = null;
                $clearKeys[] = 'end_date';
            }
        }
    }
    $explicitVenues = receptionist_knowledge_explicit_venue_matches($records, $message, $intent);
    if (count($explicitVenues) === 1) {
        $explicitVenue = $explicitVenues[0];
        $patch['intent'] = $explicitVenue['category'];
        $patch['active_venue_id'] = (int)($explicitVenue['venue_id'] ?? 0);
        if (!empty($explicitVenue['room_group_id'])) $patch['active_room_group_id'] = (int)$explicitVenue['room_group_id'];
    }
    $slots = receptionist_ai_merge_slots($baseSlots, $patch, $clearKeys);
    $clearSlots = array_key_exists('end_date', $patch) && $patch['end_date'] === null ? ['end_date'] : [];
    $responseSlots = $slots;
    foreach ($clearSlots as $key) $responseSlots[$key] = null;
    $missing = [];
    if (!in_array($slots['intent'] ?? null, RECEPTIONIST_KNOWLEDGE_CATEGORIES, true)) {
        $missing = ['intent'];
    } else {
        if (($slots['intent'] ?? null) === 'Hotel Room') {
            if (!isset($slots['group_size'])) $missing[] = 'group_size';
            if (!isset($slots['preference'])) $missing[] = 'preference';
        }
        if (empty($slots['start_date'])) $missing[] = 'start_date';
        if (($slots['intent'] ?? null) === 'Hotel Room' && empty($slots['end_date'])) $missing[] = 'end_date';
    }

    if ($missing) {
        $reply = match ($missing[0]) {
            'intent' => $language === 'fil' ? 'Event hall, hotel room, o resort villa ba ang gusto mong i-check?' : 'Would you like to check an event hall, hotel room, or resort villa?',
            'group_size' => $language === 'fil' ? 'Ilang bisita ang kasama sa hotel stay?' : 'How many guests are included in the hotel stay?',
            'preference' => $language === 'fil' ? 'Ano ang mas mahalaga sa room search mo — best fit, pinakamababang presyo, o comfort?' : 'What matters most for your room search — best fit, lowest price, or comfort?',
            'start_date' => ($slots['intent'] ?? null) === 'Hotel Room'
                ? ($language === 'fil' ? 'Ano ang check-in date?' : 'What is the check-in date?')
                : ($language === 'fil' ? 'Ano ang petsa ng event o stay?' : 'What is the event or stay date?'),
            default => $language === 'fil' ? 'Ano ang check-out date? Kailangan itong mas huli sa check-in date.' : 'What is the check-out date? It must be after check-in.',
        };
        return ['mode' => 'knowledge', 'booking_continuation' => true, 'action' => 'ask', 'reply' => $reply, 'faq_id' => null, 'slots' => $responseSlots, 'clear_slots' => $clearSlots, 'missing_slots' => $missing, 'quick_replies' => [], 'quick_actions' => ['start_over']];
    }

    $reply = match ($language) {
        'fil' => 'Iche-check ko ang kasalukuyang availability para sa mga date na ito. Wala pang nare-reserve o na-ho-hold sa hakbang na ito.',
        'taglish' => 'I’ll check the current options for these dates. Wala pang room or date na nare-reserve o na-ho-hold.',
        default => 'I’ll check the current options for these dates. This does not reserve or hold a room or date.',
    };
    return ['mode' => 'knowledge', 'booking_continuation' => true, 'action' => 'availability', 'reply' => $reply, 'faq_id' => null, 'slots' => $responseSlots, 'clear_slots' => $clearSlots, 'missing_slots' => [], 'quick_replies' => [], 'quick_actions' => ['start_over']];
}

function receptionist_knowledge_reply(array $records, string $message, string $language = 'en', array $baseSlots = [], array $history = [], ?string $focusedFaqId = null): ?array
{
    $language = in_array($language, ['en', 'fil', 'taglish'], true) ? $language : 'en';
    if (receptionist_knowledge_is_capability_request($message)) {
        $reply = match ($language) {
            'fil' => 'Matutulungan kitang mag-explore ng event halls, hotel rooms, at villas; magbahagi ng naka-publish na rates at amenities; sumagot ng resort policy questions; at gumabay sa pag-check ng dates o booking inquiry.',
            'taglish' => 'I can help you explore event halls, hotel rooms, and villas; share published rates and amenities; answer resort policy questions; and guide you through a date availability check or booking inquiry.',
            default => 'I can help you explore event halls, hotel rooms, and villas; share published rates and amenities; answer resort policy questions; and guide you through a date availability check or booking inquiry.',
        };
        return ['mode' => 'knowledge', 'action' => 'ask', 'reply' => $reply, 'faq_id' => null, 'slots' => $baseSlots, 'missing_slots' => [], 'quick_replies' => []];
    }
    if (receptionist_knowledge_is_social_input($message)) {
        return ['mode' => 'knowledge', 'action' => 'social', 'reply' => receptionist_knowledge_social_reply($message, $language), 'faq_id' => null, 'slots' => $baseSlots, 'missing_slots' => [], 'quick_replies' => []];
    }
    $memoryReply = receptionist_knowledge_memory_reply($message, $language, $baseSlots, $history);
    if ($memoryReply !== null) return $memoryReply;
    if (receptionist_knowledge_positive_int($baseSlots['active_venue_id'] ?? null) === null
        && $focusedFaqId !== null && receptionist_knowledge_is_contextual_followup($message)
        && receptionist_knowledge_property_from_message($message) === null) {
        foreach ($records as $record) {
            if (($record['kind'] ?? null) !== 'faq' || ($record['id'] ?? null) !== $focusedFaqId) continue;
            $focusedAnswer = receptionist_knowledge_compose_selection($records, [$record], $focusedFaqId, 'faq_answer', $language, $baseSlots, $message);
            if ($focusedAnswer !== null) return $focusedAnswer;
            break;
        }
    }
    $pendingStep = receptionist_knowledge_pending_booking_step($baseSlots, $history);
    $currentCategorySwitch = receptionist_knowledge_explicit_category_switch($message);
    if (($currentCategorySwitch !== null && $currentCategorySwitch !== ($baseSlots['intent'] ?? null))
        || receptionist_knowledge_is_generic_booking_start($message)) $pendingStep = null;
    $pendingBareGuestCount = receptionist_knowledge_pending_bare_guest_count($message, $baseSlots, $history);
    if ($pendingBareGuestCount !== null && !$pendingBareGuestCount['valid']) {
        $clarification = receptionist_knowledge_pending_booking_clarification('group_size', $language, $baseSlots);
        $clarification['reply'] = $language === 'fil'
            ? 'Kailangan ko ng buong bilang mula 1 pataas para sa guest count. Ilang bisita ang kasama?'
            : 'Please enter a whole guest count from 1 to 10,000. How many guests are included?';
        return $clarification;
    }
    $tokens = receptionist_knowledge_tokens($message);
    if (!$tokens && $pendingBareGuestCount === null) {
        if ($pendingStep !== null && !receptionist_knowledge_is_fact_request($message, $baseSlots)) {
            return receptionist_knowledge_pending_booking_clarification($pendingStep, $language, $baseSlots);
        }
        return null;
    }
    $lower = receptionist_knowledge_normalize_message($message);
    $route = receptionist_knowledge_intent($message);
    $routeCategory = in_array($route['kind'] ?? null, ['booking', 'booking_process'], true) ? ($route['category'] ?? null) : null;
    $explicitCategory = receptionist_knowledge_explicit_category_switch($message);
    $category = $routeCategory ?? $explicitCategory ?? (($baseSlots['intent'] ?? null) ?: receptionist_knowledge_category_hint($message));
    $comparisonTargetMessage = receptionist_knowledge_is_comparison_request($message) ? $message : null;
    if ($comparisonTargetMessage === null && receptionist_knowledge_is_short_why($message)) {
        $comparisonTargetMessage = receptionist_knowledge_pending_room_comparison_message($history);
    }
    $roomComparisonIntent = $comparisonTargetMessage !== null;
    $hotelComparisonIntent = $roomComparisonIntent && (($baseSlots['intent'] ?? null) === 'Hotel Room'
        || receptionist_knowledge_hotel_parent_matches($records, $comparisonTargetMessage) !== []);
    $availabilityAnswer = receptionist_knowledge_availability_reply($records, $message, $language, $baseSlots, $category);
    if ($availabilityAnswer !== null) return $availabilityAnswer;
    $prefix = [
        'en' => ['price' => 'Here are our starting rates:', 'capacity' => 'Here\'s the capacity info:', 'amenities' => 'Here\'s what\'s included:', 'faq' => 'Here\'s what I found:', 'contact' => 'Here\'s how to reach us:', 'booking' => 'I can help you start a booking!'],
        'fil' => ['price' => 'Narito ang aming mga starting rates:', 'capacity' => 'Narito ang capacity info:', 'amenities' => 'Narito ang mga kasama:', 'faq' => 'Narito ang nakita ko:', 'contact' => 'Narito kung paano kami maabot:', 'booking' => 'Matutulungan kitang magsimula ng booking!'],
        'taglish' => ['price' => 'Here are our starting rates:', 'capacity' => 'Here\'s the capacity info:', 'amenities' => 'Here\'s what\'s included:', 'faq' => 'Here\'s what I found:', 'contact' => 'Here\'s how to reach us:', 'booking' => 'I can help you start a booking!'],
    ][$language];
    if (($route['kind'] ?? null) === 'support_faq') {
        $quickReplies = [];
        foreach ($records as $record) {
            if (($record['kind'] ?? null) !== 'faq' || !is_string($record['question'] ?? null) || $record['question'] === '') continue;
            $quickReplies[] = $record['question'];
            if (count($quickReplies) >= 4) break;
        }
        return [
            'mode' => 'knowledge',
            'action' => 'ask',
            'reply' => match ($language) {
                'fil' => 'Saklaw ng Support & FAQs ang booking, payments, cancellations, venue at stay details, at mga patakaran ng resort. Buksan ang buong Support & FAQs page para sa kumpletong listahan, o pumili ng tanong sa ibaba.',
                default => 'Support & FAQs covers booking, payments, cancellations, venue and stay details, and resort policies. Open the full Support & FAQs page for the complete list, or choose a question below.',
            },
            'faq_id' => null,
            'slots' => [],
            'missing_slots' => [],
            'quick_replies' => $quickReplies,
            'show_support_faq_cta' => true,
        ];
    }
    $walkInReply = receptionist_knowledge_walk_in_reply($message, $language, $baseSlots);
    if ($walkInReply !== null) return $walkInReply;
    $bookingContinuation = $hotelComparisonIntent ? null : receptionist_knowledge_booking_continuation($records, $message, $language, $baseSlots, $route, $category, $pendingBareGuestCount['count'] ?? null, $pendingStep);
    if ($bookingContinuation !== null) return $bookingContinuation;
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
    $roomDetailsIntent = receptionist_knowledge_is_room_details_request($message, $baseSlots);
    $roomComparisonIntent = $roomComparisonIntent || receptionist_knowledge_is_comparison_request($message);
    $pendingRoomFollowup = receptionist_knowledge_pending_room_followup($history);
    if ($pendingRoomFollowup === 'details') $roomDetailsIntent = true;
    if ($pendingRoomFollowup === 'comparison') $roomComparisonIntent = true;
    $activeVenueId = receptionist_knowledge_positive_int($baseSlots['active_venue_id'] ?? null);
    $activeRoomGroupId = receptionist_knowledge_positive_int($baseSlots['active_room_group_id'] ?? null);
    $sessionVenueId = $activeVenueId;
    $roomReferenceMessage = $comparisonTargetMessage ?? $message;
    $namedParentRows = receptionist_knowledge_hotel_parent_matches($records, $roomReferenceMessage);
    $namedExactRows = receptionist_knowledge_explicit_venue_matches($records, $roomReferenceMessage, 'Hotel Room');
    $pendingParentMessage = receptionist_knowledge_pending_room_parent_message($history);
    if (!$namedParentRows && $pendingParentMessage !== null) {
        $namedParentRows = receptionist_knowledge_hotel_parent_matches($records, $pendingParentMessage);
    }
    $activeHotelRows = [];
    if (($baseSlots['intent'] ?? null) === 'Hotel Room' && $activeVenueId !== null && $activeRoomGroupId === null) {
        foreach ($records as $record) {
            if (($record['kind'] ?? null) === 'venue' && ($record['category'] ?? null) === 'Hotel Room'
                && (int)($record['venue_id'] ?? 0) === $activeVenueId) $activeHotelRows[] = $record;
        }
    }
    $roomSelectionRows = $namedParentRows ?: $activeHotelRows;
    $typedActiveRows = array_values(array_filter($roomSelectionRows, static fn(array $record): bool => receptionist_knowledge_hotel_room_type_matches($roomReferenceMessage, $record)));
    $messagePhrase = receptionist_knowledge_normalized_phrase($roomReferenceMessage);
    $isRoomTypeSelection = count($typedActiveRows) === 1 && strlen($messagePhrase) <= 32;
    if ($isRoomTypeSelection) {
        $selectedType = receptionist_knowledge_normalized_phrase((string)($typedActiveRows[0]['room_type'] ?? ''));
        $selectedType = trim((string)preg_replace('/\broom\b/', '', $selectedType));
        $isRoomTypeSelection = in_array($messagePhrase, [$selectedType, receptionist_knowledge_normalized_phrase((string)($typedActiveRows[0]['room_type'] ?? ''))], true);
    }
    $knownIntent = $baseSlots['intent'] ?? null;
    $hasHotelReference = (bool)$namedParentRows || (bool)$namedExactRows
        || $knownIntent === 'Hotel Room'
        || ($knownIntent === null && preg_match('/\b(?:hotel|room)\b/i', $lower) === 1);
    $unscopedPronounDetails = $roomDetailsIntent && $activeVenueId === null && !$namedParentRows
        && !in_array($baseSlots['intent'] ?? null, RECEPTIONIST_KNOWLEDGE_CATEGORIES, true);
    if ((($roomDetailsIntent || $roomComparisonIntent || $isRoomTypeSelection) && $hasHotelReference) || $unscopedPronounDetails) {
        $namedVenueIds = array_values(array_unique(array_map(static fn(array $record): int => (int)($record['venue_id'] ?? 0), $namedParentRows)));
        if (count($namedVenueIds) === 1 && $namedVenueIds[0] > 0 && $namedVenueIds[0] !== $activeVenueId) {
            $activeVenueId = $namedVenueIds[0];
            $activeRoomGroupId = null;
        }
        $candidateRows = [];
        if (count($namedExactRows) > 1) {
            $candidateRows = $namedExactRows;
        } elseif ($namedParentRows) {
            $candidateRows = $namedParentRows;
        } elseif ($activeVenueId !== null) {
            foreach ($records as $record) {
                if (($record['kind'] ?? null) !== 'venue' || ($record['category'] ?? null) !== 'Hotel Room'
                    || (int)($record['venue_id'] ?? 0) !== $activeVenueId) continue;
                $candidateRows[] = $record;
            }
        }
        if ($namedExactRows) {
            $namedIds = array_fill_keys(array_map(static fn(array $record): string => (string)($record['id'] ?? ''), $namedExactRows), true);
            $candidateRows = array_values(array_filter($candidateRows, static fn(array $record): bool => isset($namedIds[(string)($record['id'] ?? '')])));
        } elseif ($activeRoomGroupId !== null) {
            $candidateRows = array_values(array_filter($candidateRows, static fn(array $record): bool => (int)($record['room_group_id'] ?? 0) === $activeRoomGroupId));
        } else {
            $typedRows = array_values(array_filter($candidateRows, static fn(array $record): bool => receptionist_knowledge_hotel_room_type_matches($roomReferenceMessage, $record)));
            if ($typedRows) $candidateRows = $typedRows;
        }
        $requestedGuests = receptionist_knowledge_positive_int($baseSlots['group_size'] ?? null);
        if ($roomComparisonIntent && $requestedGuests !== null && count($candidateRows) > 1) {
            $capacityFits = [];
            $unknownCapacity = false;
            foreach ($candidateRows as $candidate) {
                $candidateCapacity = receptionist_knowledge_positive_int($candidate['capacity_max'] ?? null);
                if ($candidateCapacity === null) $unknownCapacity = true;
                elseif ($candidateCapacity >= $requestedGuests) $capacityFits[] = $candidate;
            }
            if (count($capacityFits) === 1 && !$unknownCapacity) $candidateRows = $capacityFits;
        }

        $followupSlots = $baseSlots;
        $targetVenueIds = array_values(array_unique(array_map(static fn(array $record): int => (int)($record['venue_id'] ?? 0), $candidateRows)));
        if (count($candidateRows) === 1 && count($targetVenueIds) === 1 && $targetVenueIds[0] > 0) {
            if ($sessionVenueId !== $targetVenueIds[0]) unset($followupSlots['active_room_group_id']);
            $followupSlots['intent'] = 'Hotel Room';
            $followupSlots['active_venue_id'] = $targetVenueIds[0];
            $followupSlots['active_room_group_id'] = (int)$candidateRows[0]['room_group_id'];
        }

        if (count($candidateRows) === 1) {
            $room = $candidateRows[0];
            $followupSlots['intent'] = 'Hotel Room';
            $followupSlots['active_venue_id'] = (int)$room['venue_id'];
            $followupSlots['active_room_group_id'] = (int)$room['room_group_id'];
            $name = trim((string)($room['name'] ?? 'Room') . ' — ' . (string)($room['room_type'] ?? ''));
            if ($roomDetailsIntent || (!$roomComparisonIntent && $isRoomTypeSelection)) {
                return [
                    'mode' => 'knowledge', 'booking_continuation' => true, 'action' => 'venue',
                    'reply' => $language === 'fil' ? "Narito ang detalye ng {$name}." : "Here are the details for {$name}.",
                    'faq_id' => null, 'slots' => $followupSlots, 'missing_slots' => [],
                    'quick_replies' => [], 'quick_actions' => ['venue_details', 'venue_change', 'start_over'],
                ];
            }

            $capacityMax = receptionist_knowledge_positive_int($room['capacity_max'] ?? null);
            $requestedGuests = receptionist_knowledge_positive_int($followupSlots['group_size'] ?? null);
            $capacity = $capacityMax !== null
                ? 'Its published capacity is up to ' . number_format($capacityMax) . ' guests'
                : 'Its guest capacity is not listed';
            if ($capacityMax !== null && $requestedGuests !== null) {
                $capacity .= $capacityMax >= $requestedGuests
                    ? ', which covers your group of ' . number_format($requestedGuests)
                    : ', below your group of ' . number_format($requestedGuests);
            }
            $beds = isset($room['bed_count_min']) ? ' and ' . number_format((int)$room['bed_count_min']) . ' bed' . ((int)$room['bed_count_min'] === 1 ? '' : 's') : '';
            $reason = $name . ': ' . $capacity . $beds . '. ';
            if (($followupSlots['preference'] ?? null) === 'best_fit') {
                $rateBasis = !empty($followupSlots['start_date']) && !empty($followupSlots['end_date'])
                    ? 'estimated stay total' : 'estimated nightly amount';
                $reason .= 'The best-fit order favors capacity fit, then listed bed count and ' . $rateBasis . '. ';
            }
            if (!empty($followupSlots['start_date']) && !empty($followupSlots['end_date'])) {
                $reason .= 'I kept your dates ' . $followupSlots['start_date'] . ' to ' . $followupSlots['end_date'] . ' and have not rechecked availability.';
            } else {
                $reason .= 'This uses published room facts and does not confirm current availability.';
            }
            return ['mode' => 'knowledge', 'action' => 'ask', 'reply' => $reason, 'faq_id' => null, 'slots' => $followupSlots, 'missing_slots' => [], 'quick_replies' => [], 'show_support_contact_cta' => false];
        }

        $roomLabels = [];
        foreach ($candidateRows as $record) {
            $roomType = trim((string)($record['room_type'] ?? ''));
            if ($roomType !== '' && !in_array($roomType, $roomLabels, true)) $roomLabels[] = $roomType;
        }
        $reply = $candidateRows
            ? ($language === 'fil' ? 'Aling eksaktong room type ang tinutukoy mo?' : 'Which exact room type do you mean?')
            : ($language === 'fil' ? 'Aling venue o hotel room ang gusto mong pag-usapan? Para sa hotel stay, ilagay din ang room type.' : 'Which venue or hotel room do you mean? For a hotel stay, include the room type too.');
        return ['mode' => 'knowledge', 'action' => 'ask', 'reply' => $reply, 'faq_id' => null, 'slots' => $followupSlots, 'missing_slots' => ['active_room_group_id'], 'quick_replies' => array_slice($roomLabels, 0, 4), 'show_support_contact_cta' => false];
    }

    $venueId = receptionist_knowledge_positive_int($baseSlots['active_venue_id'] ?? null);
    $roomGroupId = receptionist_knowledge_positive_int($baseSlots['active_room_group_id'] ?? null);
    $selectedContextVenue = $venueId !== null
        ? receptionist_knowledge_find_records($records, 'venue', [], $category, $venueId, $roomGroupId, 2)
        : [];
    $explicitDetailsIntent = preg_match('/\b(?:see\s+(?:the\s+)?details?|show\s+(?:the\s+)?details?|more\s+details?|tell\s+me\s+more|view\s+(?:the\s+)?details?|open\s+(?:it|the\s+venue))\b/i', $lower) === 1;
    $cardRevealIntent = preg_match('/^\s*where(?:\s+is\s+(?:it|that|this|the\s+venue|the\s+selected\s+venue))?\s*[?.!]*\s*$/i', $lower) === 1;
    $locationQuestion = preg_match('/\b(?:address|location|located|directions?|saan|nasaan|paano\s+pumunta|direksyon|lokasyon)\b/i', $lower) === 1;
    if (!$locationQuestion && preg_match('/\bwhere\s+(?:is|are|can\s+i\s+find)\b/i', $lower) === 1 && count($selectedContextVenue) === 1) {
        $selectedName = receptionist_knowledge_lower((string)($selectedContextVenue[0]['name'] ?? ''));
        $selectedRoomType = receptionist_knowledge_lower((string)($selectedContextVenue[0]['room_type'] ?? ''));
        $locationQuestion = ($selectedName !== '' && preg_match('/\b' . preg_quote($selectedName, '/') . '\b/i', $lower) === 1)
            || ($selectedRoomType !== '' && preg_match('/\b' . preg_quote($selectedRoomType, '/') . '\b/i', $lower) === 1);
    }
    $detailIntent = $explicitDetailsIntent || ($cardRevealIntent && !$locationQuestion);
    if ($detailIntent && $venueId !== null) {
        $selected = $selectedContextVenue;
        if (count($selected) === 1 && ($category !== 'Hotel Room' || $roomGroupId !== null)) {
            $name = (string)($selected[0]['name'] ?? 'The selected venue');
            $roomType = trim((string)($selected[0]['room_type'] ?? ''));
            if ($roomType !== '') $name .= ' — ' . $roomType;
            return [
                'mode' => 'knowledge',
                'booking_continuation' => true,
                'action' => 'venue',
                'reply' => $language === 'fil' ? "Narito ang detalye ng napili mong {$name}." : "Here are the details for {$name}.",
                'faq_id' => null,
                'slots' => $baseSlots,
                'missing_slots' => [],
                'quick_replies' => [],
                'quick_actions' => ['venue_details', 'venue_change', 'start_over'],
            ];
        }
    }
    if ($locationQuestion && count($selectedContextVenue) === 1) {
        $contact = array_values(array_filter($records, static fn(array $record): bool => ($record['kind'] ?? null) === 'contact'))[0] ?? null;
        $address = is_array($contact['contact'] ?? null) ? trim((string)($contact['contact']['address'] ?? '')) : '';
        if ($address !== '') {
            return [
                'action' => 'ask',
                'reply' => $prefix['contact'] . "\nAddress: " . $address,
                'faq_id' => null,
                'slots' => $baseSlots,
                'missing_slots' => [],
                'quick_replies' => [],
            ];
        }
    }
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

    // A selected venue is the subject of short follow-ups such as “what
    // about its price?”, “may pool ba?”, or “where?”. Resolve those against
    // that exact public record before category-wide matches can take over.
    $contextProperty = receptionist_knowledge_property_from_message($message);
    if (count($selectedContextVenue) === 1 && $contextProperty !== null) {
        $selectedAnswer = receptionist_knowledge_compose_selection(
            $records,
            $selectedContextVenue,
            $selectedContextVenue[0]['id'] ?? null,
            $contextProperty,
            $language,
            $baseSlots,
            $message
        );
        if ($selectedAnswer !== null) return $selectedAnswer;
    }
    if ($contextProperty !== null) {
        $explicitMatches = receptionist_knowledge_explicit_venue_matches($records, $message, $category);
        $explicitVenue = count($explicitMatches) === 1 ? $explicitMatches[0] : null;
        if ($explicitVenue !== null) {
            $explicitContext = $baseSlots;
            if ((int)($explicitContext['active_venue_id'] ?? 0) !== (int)($explicitVenue['venue_id'] ?? 0)) {
                unset($explicitContext['active_venue_id'], $explicitContext['active_room_group_id']);
            }
            $explicitContext['intent'] = $explicitVenue['category'];
            $explicitAnswer = receptionist_knowledge_compose_selection($records, [$explicitVenue], $explicitVenue['id'] ?? null, $contextProperty, $language, $explicitContext, $message);
            if ($explicitAnswer !== null) return $explicitAnswer;
        }
    }
    if ($venueId !== null && $category === 'Hotel Room' && $roomGroupId === null && in_array($contextProperty, ['price', 'overnight_price', 'capacity', 'amenities'], true)) {
        $roomOptions = receptionist_knowledge_find_records($records, 'venue', [], 'Hotel Room', $venueId, null, 6);
        $roomLabels = [];
        foreach ($roomOptions as $roomOption) {
            $roomType = trim((string)($roomOption['room_type'] ?? ''));
            if ($roomType !== '') $roomLabels[] = $roomType;
        }
        $reply = $language === 'fil'
            ? 'Aling eksaktong room type ang tinutukoy mo? Kailangan ang room-group na ito para maibigay ang tamang detalye.'
            : 'Which exact room type do you mean? I need that room selection to give you the correct details.';
        return ['action' => 'ask', 'reply' => $reply, 'faq_id' => null, 'slots' => $baseSlots, 'missing_slots' => ['active_room_group_id'], 'quick_replies' => array_slice($roomLabels, 0, 4), 'show_support_contact_cta' => false];
    }

    if (preg_match('/\b(?:pets?|dogs?|cats?|children|child|kids?|outside\s+catering|catering)\b/i', $lower) === 1
        && !receptionist_knowledge_has_specific_policy_faq($records, $message)) {
        return receptionist_knowledge_unconfirmed_reply($message, $language, $baseSlots);
    }

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
    if ($pendingStep !== null && !receptionist_knowledge_is_fact_request($message, $baseSlots)
        && count(receptionist_knowledge_tokens($message)) <= 8) {
        return receptionist_knowledge_pending_booking_clarification($pendingStep, $language, $baseSlots);
    }
    return null;
}
