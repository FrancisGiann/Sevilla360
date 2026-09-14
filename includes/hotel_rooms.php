<?php
declare(strict_types=1);

require_once __DIR__ . '/booking_rules.php';

/** Fixed public room taxonomy. Codes and comfort ranks are persisted by migration 023. */
function hotel_fixed_room_types(): array
{
    return [
        'standard_room' => ['label' => 'Standard Room', 'comfort_rank' => 1],
        'dormitory_room' => ['label' => 'Dormitory Room', 'comfort_rank' => 2],
        'family_room_superior' => ['label' => 'Family Room / Superior', 'comfort_rank' => 3],
        'deluxe' => ['label' => 'Deluxe', 'comfort_rank' => 4],
        'vip_suite' => ['label' => 'VIP Suite', 'comfort_rank' => 5],
    ];
}

function hotel_room_type_label(string $code): ?string
{
    $types = hotel_fixed_room_types();
    return $types[$code]['label'] ?? null;
}

function hotel_validate_room_type_code(mixed $code): string
{
    if (!is_string($code) || hotel_room_type_label($code) === null) {
        throw new InvalidArgumentException('Please select a valid hotel room type.');
    }
    return $code;
}

function hotel_allowed_guest_ranges(): array
{
    return [
        '1-2' => ['min' => 1, 'max' => 2],
        '3-4' => ['min' => 3, 'max' => 4],
        '5-6' => ['min' => 5, 'max' => 6],
        '7-8' => ['min' => 7, 'max' => 8],
        '9-12' => ['min' => 9, 'max' => 12],
        '13-16' => ['min' => 13, 'max' => 16],
    ];
}

function hotel_parse_guest_range(mixed $range): ?array
{
    if (!is_string($range)) return null;
    return hotel_allowed_guest_ranges()[$range] ?? null;
}

function hotel_allowed_recommendation_priorities(): array
{
    return ['save', 'best_fit', 'comfort'];
}

function hotel_parse_recommendation_priority(mixed $priority): ?string
{
    return is_string($priority) && in_array($priority, hotel_allowed_recommendation_priorities(), true)
        ? $priority
        : null;
}

function hotel_parse_strict_date(mixed $raw): ?DateTimeImmutable
{
    if (!is_string($raw) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $raw)) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $raw ? $date : null;
}

function hotel_validate_recommendation_dates(mixed $checkIn, mixed $checkOut, ?DateTimeImmutable $today = null): array
{
    $start = hotel_parse_strict_date($checkIn);
    $end = hotel_parse_strict_date($checkOut);
    $today = $today ?? new DateTimeImmutable('today');
    if (!$start || !$end || $start < $today || $end <= $start) {
        throw new InvalidArgumentException('Choose a valid check-in and checkout date.');
    }
    return [$start, $end, (int)$start->diff($end)->days];
}

/** Optional hotel dates are an all-or-nothing pair; undated pricing uses one night. */
function hotel_validate_optional_recommendation_dates(mixed $checkIn, mixed $checkOut, ?DateTimeImmutable $today = null): array
{
    $hasCheckIn = $checkIn !== null && $checkIn !== '';
    $hasCheckOut = $checkOut !== null && $checkOut !== '';
    if (!$hasCheckIn && !$hasCheckOut) return [null, null, 1, false];
    if (!$hasCheckIn || !$hasCheckOut) {
        throw new InvalidArgumentException('Choose both check-in and checkout dates, or choose dates later.');
    }
    [$start, $end, $nights] = hotel_validate_recommendation_dates($checkIn, $checkOut, $today);
    return [$start, $end, $nights, true];
}

function hotel_normalize_group_time(mixed $value): string
{
    $value = is_string($value) ? trim($value) : '';
    if (preg_match('/\A([01]\d|2[0-3]):([0-5]\d)\z/D', $value, $match)) return $match[1] . ':' . $match[2] . ':00';
    if (preg_match('/\A([01]\d|2[0-3]):([0-5]\d):([0-5]\d)\z/D', $value, $match)) return $match[1] . ':' . $match[2] . ':' . $match[3];
    throw new InvalidArgumentException('Hotel check-in and checkout times must be valid.');
}

function hotel_normalize_media_slot_key(mixed $value): ?string
{
    if ($value === null) return null;
    if (!is_string($value)) throw new InvalidArgumentException('Media slot key is invalid.');
    $value = trim($value);
    if ($value === '') return null;
    if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D', $value)) {
        throw new InvalidArgumentException('Media slot key must use only letters, numbers, underscores, or hyphens.');
    }
    return $value;
}

/** Mirrors the SHA-256 commercial_key expression used by migration 023. */
function hotel_commercial_variant_key(array $variant): string
{
    $typeCode = (string)($variant['room_type_code'] ?? '');
    $legacyType = $typeCode === '' ? trim((string)($variant['legacy_room_type'] ?? $variant['room_type'] ?? '')) : '';
    $parts = [
        (string)($variant['building_name'] ?? ''),
        $typeCode,
        $legacyType,
        (string)(int)($variant['base_capacity'] ?? 0),
        (string)(int)($variant['max_capacity'] ?? 0),
        (string)(int)($variant['bed_count'] ?? 0),
        number_format((float)($variant['nightly_rate'] ?? 0), 2, '.', ''),
        number_format((float)($variant['extra_pax_rate'] ?? 0), 2, '.', ''),
        hotel_normalize_group_time((string)($variant['check_in_time'] ?? '14:00:00')),
        hotel_normalize_group_time((string)($variant['check_out_time'] ?? '12:00:00')),
    ];
    return hash('sha256', implode("\x1f", $parts));
}

function hotel_group_schema_ready(mysqli $conn): bool
{
    static $cache = [];
    $key = spl_object_id($conn);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $result = $conn->query("SELECT table_name, column_name
            FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name IN ('hotel_room_types', 'hotel_room_groups', 'hotel_rooms', 'booking_rooms', 'bookings', 'maintenance', 'booking_locks')");
        $found = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) $found[$row['table_name']][$row['column_name']] = true;
        }
        $required = [
            'hotel_room_types' => ['type_code', 'display_name', 'comfort_rank', 'active', 'sort_order'],
            'hotel_room_groups' => ['id', 'commercial_key', 'building_name', 'display_name', 'description', 'amenities', 'room_type_code', 'legacy_room_type', 'base_capacity', 'max_capacity', 'bed_count', 'nightly_rate', 'extra_pax_rate', 'check_in_time', 'check_out_time', 'occupancy_mode', 'bathroom_mode', 'floor_area_sqm', 'recommendation_ready', 'media_slot_key', 'sort_order'],
            'hotel_rooms' => ['venue_id', 'room_type', 'room_type_code', 'room_group_id', 'room_number', 'bed_count', 'base_capacity', 'max_capacity', 'nightly_rate', 'extra_pax_rate', 'check_in_time', 'check_out_time'],
            'booking_rooms' => ['booking_id', 'venue_id', 'room_group_id', 'start_date', 'end_date'],
            'bookings' => ['id', 'venue_id', 'booking_status', 'source', 'start_date', 'end_date'],
            'maintenance' => ['venue_id', 'is_blocking', 'status', 'start_date', 'end_date'],
            'booking_locks' => ['venue_id', 'session_id', 'expires_at', 'start_date', 'end_date'],
        ];
        $cache[$key] = true;
        foreach ($required as $table => $columns) {
            foreach ($columns as $column) {
                if (!isset($found[$table][$column])) {
                    $cache[$key] = false;
                    break 2;
                }
            }
        }
    } catch (Throwable $error) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

/** Validate a canonical type against the active database catalogue once migration 023 is available. */
function hotel_validate_active_room_type_code(mysqli $conn, mixed $code): string
{
    $typeCode = hotel_validate_room_type_code($code);
    if (!hotel_group_schema_ready($conn)) return $typeCode;

    $stmt = $conn->prepare('SELECT active FROM hotel_room_types WHERE type_code = ? LIMIT 1');
    if (!$stmt) throw new RuntimeException('Unable to validate hotel room type.');
    $stmt->bind_param('s', $typeCode);
    if (!$stmt->execute()) throw new RuntimeException('Unable to validate hotel room type.');
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || (int)$row['active'] !== 1) {
        throw new InvalidArgumentException('Please select an active hotel room type.');
    }
    return $typeCode;
}

function hotel_room_group_upsert(
    mysqli $conn,
    array $variant,
    ?string $mediaSlotKey = null,
    ?string $description = null,
    ?string $amenities = null,
    ?string $displayName = null
): int
{
    if (!hotel_group_schema_ready($conn)) throw new RuntimeException('Hotel room group schema is not available.');
    $building = trim((string)($variant['building_name'] ?? ''));
    if ($building === '' || mb_strlen($building) > 150) throw new InvalidArgumentException('Hotel building name is invalid.');
    $typeCode = hotel_validate_active_room_type_code($conn, $variant['room_type_code'] ?? null);
    $displayName = trim((string)($displayName ?? ($building . ' — ' . hotel_room_type_label($typeCode))));
    $description = trim((string)$description);
    $amenities = trim((string)$amenities);
    $mediaSlotKey = hotel_normalize_media_slot_key($mediaSlotKey);
    if (mb_strlen($displayName) > 250 || mb_strlen($description) > 5000 || mb_strlen($amenities) > 10000) {
        throw new InvalidArgumentException('Hotel room display details are too long.');
    }
    $baseCapacity = (int)($variant['base_capacity'] ?? 0);
    $maxCapacity = (int)($variant['max_capacity'] ?? 0);
    $bedCount = (int)($variant['bed_count'] ?? 0);
    $nightlyRate = (float)($variant['nightly_rate'] ?? 0);
    $extraPaxRate = (float)($variant['extra_pax_rate'] ?? 0);
    $checkIn = hotel_normalize_group_time((string)($variant['check_in_time'] ?? ''));
    $checkOut = hotel_normalize_group_time((string)($variant['check_out_time'] ?? ''));
    if ($baseCapacity < 1 || $maxCapacity < $baseCapacity || $bedCount < 1 || $bedCount > $maxCapacity
        || !is_finite($nightlyRate) || $nightlyRate < 0 || !is_finite($extraPaxRate) || $extraPaxRate < 0) {
        throw new InvalidArgumentException('Hotel capacity and rate details are invalid.');
    }
    $group = [
        'building_name' => $building,
        'room_type_code' => $typeCode,
        'base_capacity' => $baseCapacity,
        'max_capacity' => $maxCapacity,
        'bed_count' => $bedCount,
        'nightly_rate' => $nightlyRate,
        'extra_pax_rate' => $extraPaxRate,
        'check_in_time' => $checkIn,
        'check_out_time' => $checkOut,
    ];
    $commercialKey = hotel_commercial_variant_key($group);
    // Commercial readiness depends only on validated group facts. Optional
    // legacy metadata columns remain untouched for non-destructive rollout.
    $recommendationReady = 1;
    $stmt = $conn->prepare("INSERT INTO hotel_room_groups
            (commercial_key, building_name, display_name, room_type_code, legacy_room_type, base_capacity, max_capacity, bed_count,
             nightly_rate, extra_pax_rate, check_in_time, check_out_time,
             media_slot_key, description, amenities, recommendation_ready)
        VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), display_name = VALUES(display_name),
            media_slot_key = VALUES(media_slot_key), description = VALUES(description), amenities = VALUES(amenities),
            recommendation_ready = VALUES(recommendation_ready), updated_at = CURRENT_TIMESTAMP");
    if (!$stmt) throw new RuntimeException('Unable to prepare hotel room group.');
    $stmt->bind_param('ssssiiiddsssssi', $commercialKey, $building, $displayName, $typeCode, $baseCapacity, $maxCapacity, $bedCount,
        $nightlyRate, $extraPaxRate, $checkIn, $checkOut, $mediaSlotKey, $description, $amenities, $recommendationReady);
    if (!$stmt->execute()) throw new RuntimeException('Unable to save hotel room group.');
    $id = (int)$conn->insert_id;
    $stmt->close();
    if ($id < 1) throw new RuntimeException('Unable to resolve hotel room group.');
    return $id;
}

function hotel_bind_values(mysqli_stmt $statement, string $types, array $values): void
{
    $params = [$types];
    foreach ($values as $index => $value) $params[] = &$values[$index];
    if (!call_user_func_array([$statement, 'bind_param'], $params)) throw new RuntimeException('Unable to bind hotel availability query.');
}

/** Return available physical units grouped by room_group_id, with no unit identifiers in the public response. */
function hotel_available_group_units(mysqli $conn, string $checkIn, string $checkOut, string $sessionId, ?array $groupIds = null, bool $forUpdate = false): array
{
    $ids = $groupIds === null ? [] : array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn(int $id): bool => $id > 0)));
    if ($groupIds !== null && !$ids) return [];
    $filter = $ids ? ' AND g.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' : '';
    $sql = "SELECT g.id AS room_group_id, v.id AS venue_id, h.nightly_rate, h.base_capacity, h.max_capacity
        FROM hotel_room_groups g
        INNER JOIN hotel_room_types t ON t.type_code = g.room_type_code AND t.active = 1
        INNER JOIN hotel_rooms h ON h.room_group_id = g.id
        INNER JOIN venues v ON v.id = h.venue_id AND v.category = 'Hotel Room'
        WHERE v.status = 'Available'{$filter}
          AND NOT EXISTS (
            SELECT 1 FROM bookings b
            WHERE b.venue_id = v.id AND b.booking_status IN ('Pending', 'Confirmed', 'Completed')
              AND COALESCE(b.source, '') <> 'Maintenance'
              AND " . booking_overlap_sql('Hotel Room', 'b.start_date', 'b.end_date') . "
          )
          AND NOT EXISTS (
            SELECT 1 FROM booking_rooms br
            INNER JOIN bookings b2 ON b2.id = br.booking_id
            INNER JOIN venues parent_v ON parent_v.id = b2.venue_id
            WHERE br.venue_id = v.id AND b2.booking_status IN ('Pending', 'Confirmed', 'Completed')
              AND NOT (b2.booking_status = 'Pending' AND parent_v.category = 'Event Hall')
              AND COALESCE(b2.source, '') <> 'Maintenance'
              AND " . booking_overlap_sql('Hotel Room', 'br.start_date', 'br.end_date') . "
          )
          AND NOT EXISTS (
            SELECT 1 FROM maintenance m
            WHERE m.venue_id = v.id AND m.is_blocking = 1 AND (m.status = 'Scheduled' OR m.status IS NULL)
              AND " . maintenance_overlap_sql('m.start_date', 'm.end_date') . "
          )
          AND NOT EXISTS (
            SELECT 1 FROM booking_locks bl
            WHERE bl.venue_id = v.id AND bl.session_id <> ? AND bl.expires_at > NOW()
              AND " . booking_overlap_sql('Hotel Room', 'bl.start_date', 'bl.end_date') . "
          )
        ORDER BY g.sort_order ASC, g.id ASC, v.id ASC" . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare hotel availability query.');
    $values = [...$ids, $checkOut, $checkIn, $checkOut, $checkIn, $checkOut, $checkIn, $sessionId, $checkOut, $checkIn];
    $types = str_repeat('i', count($ids)) . str_repeat('s', 9);
    hotel_bind_values($stmt, $types, $values);
    if (!$stmt->execute()) throw new RuntimeException('Unable to check hotel availability.');
    $result = $stmt->get_result();
    $units = [];
    while ($row = $result->fetch_assoc()) {
        $groupId = (int)$row['room_group_id'];
        $units[$groupId][] = [
            'venue_id' => (int)$row['venue_id'],
            'nightly_rate' => (float)$row['nightly_rate'],
            'base_capacity' => (int)$row['base_capacity'],
            'max_capacity' => (int)$row['max_capacity'],
        ];
    }
    $stmt->close();
    return $units;
}

function hotel_estimated_total(array $group, int $nights, int $guestCount): float
{
    $baseCapacity = (int)($group['base_capacity'] ?? 0);
    $maxCapacity = (int)($group['max_capacity'] ?? 0);
    if ($nights < 1 || $guestCount < 1 || $guestCount > $maxCapacity || $baseCapacity < 1) return INF;
    $nightlyRate = (float)($group['nightly_rate'] ?? 0);
    $extraRate = (float)($group['extra_pax_rate'] ?? 0);
    $extraGuests = max(0, $guestCount - $baseCapacity);
    return round(($nightlyRate + ($extraGuests * $extraRate)) * $nights, 2);
}

function hotel_estimated_nightly_amount(array $group, int $guestCount): float
{
    return hotel_estimated_total($group, 1, $guestCount);
}

/** Validate the commercial facts, inventory, and dates shared by all priorities. */
function hotel_group_common_recommendation_issue(array $group, int $guestCount, bool $availabilityChecked = true): ?string
{
    $typeCode = (string)($group['room_type_code'] ?? '');
    if (!isset(hotel_fixed_room_types()[$typeCode])) return 'canonical_type_missing';

    $maxCapacity = $group['max_capacity'] ?? null;
    $baseCapacity = $group['base_capacity'] ?? null;
    $bedCount = $group['bed_count'] ?? null;
    if (!is_numeric($baseCapacity) || !is_numeric($maxCapacity) || !is_numeric($bedCount)
        || (int)$baseCapacity < 1 || (int)$maxCapacity < $guestCount || (int)$maxCapacity < (int)$baseCapacity || (int)$bedCount < 1) {
        return 'commercial_fields_missing';
    }

    $building = trim((string)($group['building_name'] ?? ''));
    $displayName = trim((string)($group['display_name'] ?? ''));
    $checkIn = trim((string)($group['check_in_time'] ?? ''));
    $checkOut = trim((string)($group['check_out_time'] ?? ''));
    $validTime = static fn(string $time): bool => preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?\z/D', $time) === 1;
    if ($building === '' || $displayName === '' || !$validTime($checkIn) || !$validTime($checkOut)) {
        return 'commercial_fields_missing';
    }

    foreach (['nightly_rate', 'extra_pax_rate'] as $rateKey) {
        $rate = $group[$rateKey] ?? null;
        if (!is_numeric($rate) || !is_finite((float)$rate) || (float)$rate < 0) return 'pricing_metadata_missing';
    }

    $unitCount = (int)($availabilityChecked ? ($group['available_unit_count'] ?? 0) : ($group['active_unit_count'] ?? 0));
    if ($unitCount < 1) return $availabilityChecked ? 'unavailable_for_stay' : 'no_active_inventory';
    return null;
}

function hotel_recommendation_issue_message(string $issue): ?string
{
    return match ($issue) {
        'pricing_metadata_missing' => 'A price estimate is unavailable because rate details are incomplete. Contact reception to confirm pricing.',
        'commercial_fields_missing' => 'Some room capacity, bed, or schedule details are incomplete. Contact reception for assistance.',
        default => null,
    };
}

/** Deterministic, media-independent ranking used by the receptionist contract tests. */
function hotel_rank_recommendation_groups(array $groups, string $priority, int $nights, int $guestCount, int $limit = 3, bool $availabilityChecked = true): array
{
    if (!in_array($priority, hotel_allowed_recommendation_priorities(), true) || $nights < 1 || $guestCount < 1) return [];
    $types = hotel_fixed_room_types();
    $ranked = [];
    foreach ($groups as $group) {
        if (!is_array($group)) continue;
        $typeCode = (string)($group['room_type_code'] ?? '');
        $rank = $types[$typeCode]['comfort_rank'] ?? null;
        $capacity = (int)($group['max_capacity'] ?? 0);
        $beds = (int)($group['bed_count'] ?? 0);
        $availability = (int)($group['available_unit_count'] ?? 0);
        $activeUnitCount = (int)($group['active_unit_count'] ?? 0);
        $total = hotel_estimated_total($group, $nights, $guestCount);
        if (hotel_group_common_recommendation_issue($group, $guestCount, $availabilityChecked) !== null
            || $capacity < $guestCount || $total === INF || !$rank) continue;
        $group['_rank'] = $rank;
        $group['_total'] = $total;
        $group['_unit_count_for_tie'] = $availabilityChecked ? $availability : $activeUnitCount;
        $group['_surplus'] = $capacity - $guestCount;
        $group['_bed_count'] = $beds;
        $ranked[] = $group;
    }
    $compare = static function (array $a, array $b) use ($priority): int {
        $result = 0;
        if ($priority === 'save') $result = $a['_total'] <=> $b['_total'];
        elseif ($priority === 'best_fit') {
            $result = $a['_surplus'] <=> $b['_surplus'];
            if ($result === 0) $result = $b['_bed_count'] <=> $a['_bed_count'];
            if ($result === 0) $result = $a['_total'] <=> $b['_total'];
            if ($result === 0) $result = (int)$b['_unit_count_for_tie'] <=> (int)$a['_unit_count_for_tie'];
            if ($result === 0) $result = (int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0);
            if ($result === 0) $result = (int)$a['id'] <=> (int)$b['id'];
            return $result;
        } elseif ($priority === 'comfort') $result = $b['_rank'] <=> $a['_rank'];
        if ($result !== 0) return $result;
        return ($a['_surplus'] <=> $b['_surplus'])
            ?: ($a['_total'] <=> $b['_total'])
            ?: ((int)$b['_unit_count_for_tie'] <=> (int)$a['_unit_count_for_tie'])
            ?: ((int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0))
            ?: ((int)$a['id'] <=> (int)$b['id']);
    };
    usort($ranked, $compare);
    return array_slice($ranked, 0, max(0, min(count($ranked), $limit)));
}

function hotel_recommendation_reasons(array $group, string $priority, int $nights, int $guestCount, bool $availabilityChecked = true): array
{
    $types = hotel_fixed_room_types();
    $typeLabel = $types[(string)($group['room_type_code'] ?? '')]['label'] ?? 'Hotel room';
    $total = $availabilityChecked ? hotel_estimated_total($group, $nights, $guestCount) : hotel_estimated_nightly_amount($group, $guestCount);
    $surplus = (int)$group['max_capacity'] - $guestCount;
    $estimatedValue = $availabilityChecked
        ? '₱' . number_format($total, 2) . " for {$nights} night" . ($nights === 1 ? '' : 's')
        : '₱' . number_format($total, 2) . " /night for up to {$guestCount} guests, including extra-pax charges";
    $priorityReason = match ($priority) {
        'save' => $availabilityChecked
            ? ['code' => 'estimated_total', 'label' => 'Estimated full-stay total', 'value' => $estimatedValue]
            : ['code' => 'estimated_nightly_amount', 'label' => 'Estimated nightly amount', 'value' => $estimatedValue],
        'best_fit' => ['code' => 'capacity_fit', 'label' => 'Capacity fit', 'value' => 'Fits up to ' . (int)$group['max_capacity'] . ' guests' . ($surplus > 0 ? " · {$surplus} places above your range maximum" : ' · exact fit for your range maximum')],
        'comfort' => ['code' => 'comfort', 'label' => 'Canonical room type', 'value' => $typeLabel],
    };
    $capacityReason = ['code' => 'capacity_fit', 'label' => 'Capacity fit', 'value' => 'Fits up to ' . (int)$group['max_capacity'] . ' guests' . ($surplus > 0 ? " · {$surplus} places above your range maximum" : ' · exact fit for your range maximum')];
    $bedReason = ['code' => 'bed_count', 'label' => 'Bed count', 'value' => (int)$group['bed_count'] . ' bed' . ((int)$group['bed_count'] === 1 ? '' : 's') . ' listed'];
    $availabilityReason = ['code' => 'availability', 'label' => 'Availability', 'value' => $availabilityChecked ? 'Available for your stay' : 'Dates needed to check availability'];
    if ($priority === 'best_fit') return [$capacityReason, $bedReason, ['code' => 'estimated_price', 'label' => $availabilityChecked ? 'Estimated full-stay total' : 'Estimated nightly amount', 'value' => $estimatedValue], $availabilityReason];
    return [$priorityReason, $capacityReason, $bedReason, $availabilityReason];
}

function hotel_recommendation_state(int $resultCount): string
{
    return $resultCount < 1 ? 'no_match' : ($resultCount < 3 ? 'partial' : 'matched');
}
