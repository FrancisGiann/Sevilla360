<?php
require_once __DIR__ . '/seminars.php';

/**
 * Returns the overlap predicate used for customer inventory.
 * Event halls and Resort Villas occupy every calendar date in their inclusive
 * range. Hotel stays use checkout-exclusive intervals so a checkout date can
 * be a following guest's check-in boundary. Villas persist Day stays with the
 * same start/end date, so their inclusive rule is also what blocks that
 * service date.
 */
function booking_overlap_sql(string $category, string $start_column = 'start_date', string $end_column = 'end_date'): string
{
    return in_array($category, ['Event Hall', 'Resort Villa'], true)
        ? "($start_column <= ? AND $end_column >= ?)"
        : "($start_column < ? AND $end_column > ?)";
}

/** Validate the persisted date shape for a Resort Villa stay. */
function validate_villa_stay_dates(string $category, string $stay_type, DateTimeInterface $start, DateTimeInterface $end): void
{
    if ($category !== 'Resort Villa') return;
    if (!in_array($stay_type, ['Day Time Stay', 'Overnight'], true)) {
        throw new InvalidArgumentException('Please select a valid Villa stay type.');
    }
    $days = $start->diff($end);
    if ($days->invert || ($stay_type === 'Day Time Stay' && $days->days !== 0)) {
        throw new InvalidArgumentException('Day Time Stay must use one calendar date.');
    }
    if ($stay_type === 'Overnight' && $days->days < 1) {
        throw new InvalidArgumentException('Overnight stays require checkout after check-in.');
    }
}

/** Return the number of nights in a validated Villa stay. Day stays count as one service day. */
function villa_stay_nights(string $stay_type, DateTimeInterface $start, DateTimeInterface $end): int
{
    return $stay_type === 'Day Time Stay' ? 1 : max(0, (int)$start->diff($end)->days);
}

/** Return the configured breakfast inclusion, if the Villa lists one. */
function villa_breakfast_entitlement(?string $overnight_inclusions): ?string
{
    $items = preg_split('/[;,\n]+/', (string)$overnight_inclusions) ?: [];
    foreach ($items as $item) {
        $item = trim($item);
        if ($item !== '' && preg_match('/\bbreakfast\b/i', $item)) return $item;
    }
    return null;
}

/** Return a bounded description of the included breakfast mornings. */
function villa_breakfast_schedule(string $stay_type, DateTimeInterface $start, DateTimeInterface $end): array
{
    $difference = $start->diff($end);
    if ($stay_type !== 'Overnight' || $difference->invert || $difference->days < 1) {
        return ['count' => 0, 'first_morning' => null, 'last_morning' => null];
    }
    return [
        'count' => (int)$difference->days,
        'first_morning' => DateTimeImmutable::createFromInterface($start)->modify('+1 day')->format('Y-m-d'),
        'last_morning' => DateTimeImmutable::createFromInterface($end)->format('Y-m-d'),
    ];
}

/** Build a display summary from current booking dates and configured Villa inclusions without adding a booking column. */
function villa_booking_detail_summary(string $stay_type, string $start_date, string $end_date, ?string $overnight_inclusions): string
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $start_date);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $end_date);
    if (!$start || !$end || $start->format('Y-m-d') !== $start_date || $end->format('Y-m-d') !== $end_date) return $stay_type;
    if ($stay_type !== 'Overnight') return 'Day Time Stay';
    $nights = villa_stay_nights($stay_type, $start, $end);
    $summary = 'Overnight · ' . $nights . ' night' . ($nights === 1 ? '' : 's');
    $breakfast = villa_breakfast_entitlement($overnight_inclusions);
    $schedule = villa_breakfast_schedule($stay_type, $start, $end);
    if ($breakfast !== null && $schedule['count'] > 0) {
        $first = (new DateTimeImmutable($schedule['first_morning']))->format('D, M j');
        $last = (new DateTimeImmutable($schedule['last_morning']))->format('D, M j');
        $morningRange = $first === $last ? $first : $first . ' through ' . $last;
        $summary .= ' · ' . $breakfast . ', ' . $schedule['count'] . ' morning' . ($schedule['count'] === 1 ? '' : 's') . ': ' . $morningRange . ' (including checkout)';
    }
    return $summary;
}

/** Maintenance blocks are physical calendar dates, so always inclusive. */
function maintenance_overlap_sql(string $start_column = 'start_date', string $end_column = 'end_date'): string
{
    return "($start_column <= ? AND $end_column >= ?)";
}

function normalize_event_style(?string $style): ?string
{
    $value = strtolower(trim((string)$style));
    $value = preg_replace('/[^a-z]/', '', $value);
    if ($value === '') return null;
    if (str_starts_with($value, 'theater')) return 'theater';
    if (str_starts_with($value, 'classroom')) return 'classroom';
    if (str_starts_with($value, 'banquet')) return 'banquet';
    return null;
}

/** Return the database-backed capacity for an allowed seating style. */
function get_event_style_capacity(mysqli $conn, int $venue_id, ?string $style): ?int
{
    $style_key = normalize_event_style($style);
    $columns = [
        'theater' => 'capacity_theater',
        'classroom' => 'capacity_classroom',
        'banquet' => 'capacity_banquet'
    ];
    if (!$style_key || !isset($columns[$style_key])) return null;

    $stmt = $conn->prepare("SELECT {$columns[$style_key]} AS style_capacity FROM event_halls WHERE venue_id = ?");
    if (!$stmt) throw new RuntimeException('Unable to validate event seating capacity.');
    $stmt->bind_param('i', $venue_id);
    if (!$stmt->execute()) throw new RuntimeException('Unable to validate event seating capacity.');
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return null;
    return max(0, (int)$row['style_capacity']);
}

/**
 * Reallocate Event Hall hotel add-ons at confirmation time.
 *
 * Pending Event Hall inquiries may carry provisional booking_rooms rows. They
 * are deliberately ignored by general hotel availability checks; this helper
 * turns those requested building/type/date rows into concrete, authoritative
 * room allocations while the caller's transaction is active.
 */
function booking_rules_bind_params(mysqli_stmt $statement, string $types, array $values): bool
{
    $params = [$types];
    foreach ($values as $index => $value) $params[] = &$values[$index];
    return call_user_func_array([$statement, 'bind_param'], $params);
}

/** Begin an admin booking mutation with a fresh read view after row-lock waits. */
function booking_begin_mutation_transaction(mysqli $conn): void
{
    if (!$conn->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')) {
        throw new RuntimeException('Unable to prepare a consistent booking update.');
    }
    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Unable to begin the booking update.');
    }
}

/** Lock a complete resource set in the numeric venue-id order used by seminars. */
function booking_lock_venues_in_order(mysqli $conn, array $venueIds): array
{
    $venueIds = array_values(array_unique(array_filter(array_map('intval', $venueIds), static fn($id) => $id > 0)));
    sort($venueIds, SORT_NUMERIC);
    foreach ($venueIds as $venueId) {
        $stmt = $conn->prepare('SELECT id FROM venues WHERE id=? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('Unable to lock the reserved venue.');
        $stmt->bind_param('i', $venueId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Venue not found.');
        }
        $result = $stmt->get_result();
        $found = $result && $result->num_rows > 0;
        if ($result) $result->free();
        $stmt->close();
        if (!$found) throw new RuntimeException('Venue not found.');
    }
    return $venueIds;
}

/** Load and normalize the provisional hotel add-ons while the parent booking is locked. */
function event_hall_addon_reallocation_plan(mysqli $conn, int $booking_id): array
{
    $groups_schema_ready = false;
    try {
        $schema_check = $conn->query("SELECT COUNT(DISTINCT table_name) AS found_tables FROM information_schema.columns
            WHERE table_schema = DATABASE() AND ((table_name = 'hotel_room_groups' AND column_name IN ('id','room_type_code'))
                OR (table_name = 'booking_rooms' AND column_name = 'room_group_id'))");
        $groups_schema_ready = $schema_check && (int)($schema_check->fetch_assoc()['found_tables'] ?? 0) === 2;
    } catch (Throwable $error) {
        $groups_schema_ready = false;
    }
    $requested_select = $groups_schema_ready
        ? 'COALESCE(br.room_group_id, h.room_group_id) AS room_group_id, COALESCE(g.building_name, v.name) AS building_name, COALESCE(t.display_name, g.legacy_room_type, h.room_type) AS room_type'
        : 'NULL AS room_group_id, v.name AS building_name, h.room_type';
    $requested_joins = $groups_schema_ready
        ? 'LEFT JOIN hotel_rooms h ON h.venue_id = br.venue_id LEFT JOIN venues v ON v.id = br.venue_id LEFT JOIN hotel_room_groups g ON g.id = COALESCE(br.room_group_id, h.room_group_id) LEFT JOIN hotel_room_types t ON t.type_code = g.room_type_code'
        : 'LEFT JOIN venues v ON v.id = br.venue_id LEFT JOIN hotel_rooms h ON h.venue_id = br.venue_id';
    $requested_order = $groups_schema_ready ? 'COALESCE(g.building_name, v.name), COALESCE(g.id, h.room_group_id)' : 'v.name, h.room_type';
    $stmt_requested = $conn->prepare("\n        SELECT br.start_date, br.end_date, {$requested_select}\n        FROM booking_rooms br\n        {$requested_joins}\n        WHERE br.booking_id = ?\n        ORDER BY {$requested_order}, br.start_date, br.end_date, br.id\n    ");
    if (!$stmt_requested) throw new RuntimeException('Unable to load the requested hotel add-ons.');
    $stmt_requested->bind_param('i', $booking_id);
    if (!$stmt_requested->execute()) throw new RuntimeException('Unable to load the requested hotel add-ons.');

    $groups = [];
    $requested_result = $stmt_requested->get_result();
    while ($row = $requested_result->fetch_assoc()) {
        $building = trim((string)($row['building_name'] ?? ''));
        $room_type = trim((string)($row['room_type'] ?? ''));
        $room_group_id = isset($row['room_group_id']) && (int)$row['room_group_id'] > 0 ? (int)$row['room_group_id'] : null;
        $start_date = (string)($row['start_date'] ?? '');
        $end_date = (string)($row['end_date'] ?? '');
        $start_dt = DateTime::createFromFormat('!Y-m-d', $start_date);
        $end_dt = DateTime::createFromFormat('!Y-m-d', $end_date);
        if ($building === '' || $room_type === '' || !$start_dt || !$end_dt
            || $start_dt->format('Y-m-d') !== $start_date
            || $end_dt->format('Y-m-d') !== $end_date
            || $end_dt <= $start_dt) {
            throw new RuntimeException('The Event Hall hotel add-on request is invalid.');
        }

        $key = ($room_group_id !== null ? 'group:' . $room_group_id : $building . "\0" . $room_type) . "\0" . $start_date . "\0" . $end_date;
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'building_name' => $building,
                'room_type' => $room_type,
                'room_group_id' => $room_group_id,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'nights' => $start_dt->diff($end_dt)->days,
                'quantity' => 0
            ];
        }
        $groups[$key]['quantity']++;
    }

    return ['groups_schema_ready' => $groups_schema_ready, 'groups' => $groups];
}

/** Return the complete available hotel candidate set for room groups, without taking venue locks. */
function booking_hotel_candidate_venue_ids(mysqli $conn, array $groups): array
{
    if (!$groups) return [];

    $conditions = [];
    $types = '';
    $values = [];
    foreach ($groups as $group) {
        if (isset($group['room_group_id']) && (int)$group['room_group_id'] > 0) {
            $conditions[] = 'h.room_group_id = ?';
            $types .= 'i';
            $values[] = (int)$group['room_group_id'];
        } else {
            $conditions[] = '(v.name = ? AND h.room_type = ?)';
            $types .= 'ss';
            $values[] = (string)$group['building_name'];
            $values[] = (string)$group['room_type'];
        }
    }

    $sql = "SELECT v.id FROM venues v
        INNER JOIN hotel_rooms h ON h.venue_id = v.id
        WHERE v.status = 'Available' AND (" . implode(' OR ', $conditions) . ')
        ORDER BY v.id';
    $stmt = $conn->prepare($sql);
    if (!$stmt || !booking_rules_bind_params($stmt, $types, $values)) {
        throw new RuntimeException('Unable to prepare hotel add-on inventory locks.');
    }
    if (!$stmt->execute()) throw new RuntimeException('Unable to load hotel add-on inventory locks.');
    $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
    $stmt->close();
    sort($ids, SORT_NUMERIC);
    return array_values(array_unique($ids));
}

/** Return every available room candidate referenced by this invoice plan. */
function event_hall_addon_candidate_venue_ids(mysqli $conn, array $plan): array
{
    $groups = is_array($plan['groups'] ?? null) ? array_values($plan['groups']) : [];
    return booking_hotel_candidate_venue_ids($conn, $groups);
}

/** Collect current add-on rows and their available legacy-label candidate pool for rescheduling. */
function booking_reschedule_addon_plan(mysqli $conn, int $bookingId): array
{
    $stmt = $conn->prepare("SELECT br.id, br.venue_id, v.name AS building_name, h.room_type
        FROM booking_rooms br
        INNER JOIN venues v ON v.id = br.venue_id
        INNER JOIN hotel_rooms h ON h.venue_id = br.venue_id
        WHERE br.booking_id = ?
        ORDER BY v.name, h.room_type, br.id");
    if (!$stmt) throw new RuntimeException('Unable to load room add-ons for rescheduling.');
    $stmt->bind_param('i', $bookingId);
    if (!$stmt->execute()) throw new RuntimeException('Unable to load room add-ons for rescheduling.');
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $groups = [];
    $existingVenueIds = [];
    foreach ($rows as $row) {
        $building = (string)$row['building_name'];
        $roomType = (string)$row['room_type'];
        $key = $building . "\0" . $roomType;
        $groups[$key] = [
            'building_name' => $building,
            'room_type' => $roomType,
            'room_group_id' => null,
        ];
        $existingVenueIds[] = (int)$row['venue_id'];
    }

    return [
        'rows' => $rows,
        'existing_venue_ids' => array_values(array_unique($existingVenueIds)),
        'candidate_venue_ids' => booking_hotel_candidate_venue_ids($conn, array_values($groups)),
    ];
}

function reallocate_event_hall_addons(mysqli $conn, int $booking_id, array $plan, array $lockedCandidateIds): float
{
    $groups_schema_ready = !empty($plan['groups_schema_ready']);
    $groups = is_array($plan['groups'] ?? null) ? $plan['groups'] : [];
    if (!$groups) return 0.0;

    // The caller locks the hall and this full candidate union in one numeric
    // venue-id order. Only allocate from that prelocked set; a newly inserted
    // room is ignored until a later retry rather than locked out of order.
    $lockedCandidateMap = array_fill_keys(array_map('intval', $lockedCandidateIds), true);
    if (!$lockedCandidateMap) throw new RuntimeException('No hotel add-on inventory was locked. Please retry the invoice.');
    ksort($groups, SORT_STRING);

    // Candidate venue rows are already locked as a complete numeric union by
    // the caller. This query only reads that bounded set and takes no new locks.
    $candidate_filter = $groups_schema_ready ? 'h.room_group_id = ?' : 'v.name = ? AND h.room_type = ?';
    $candidate_group_select = $groups_schema_ready ? 'h.room_group_id' : 'NULL AS room_group_id';
    $stmt_candidates = $conn->prepare("\n        SELECT v.id, h.nightly_rate, {$candidate_group_select}\n        FROM venues v\n        INNER JOIN hotel_rooms h ON h.venue_id = v.id\n        WHERE {$candidate_filter} AND v.status = 'Available'\n        ORDER BY v.id\n    ");
    $stmt_direct = $conn->prepare("\n        SELECT id FROM bookings\n        WHERE venue_id = ? AND booking_status IN ('Pending', 'Confirmed', 'Completed')\n          AND COALESCE(source, '') <> 'Maintenance'\n          AND start_date < ? AND end_date > ?\n        LIMIT 1\n    ");
    $stmt_addon = $conn->prepare("\n        SELECT br.id\n        FROM booking_rooms br\n        INNER JOIN bookings b ON b.id = br.booking_id\n        INNER JOIN venues parent_v ON parent_v.id = b.venue_id\n        WHERE br.venue_id = ? AND b.id <> ?\n          AND b.booking_status IN ('Pending', 'Confirmed', 'Completed')\n          AND NOT (b.booking_status = 'Pending' AND parent_v.category = 'Event Hall')\n          AND COALESCE(b.source, '') <> 'Maintenance'\n          AND br.start_date < ? AND br.end_date > ?\n        LIMIT 1\n    ");
    $stmt_maintenance = $conn->prepare("\n        SELECT id FROM maintenance\n        WHERE venue_id = ? AND is_blocking = 1 AND (status = 'Scheduled' OR status IS NULL)\n          AND start_date <= ? AND end_date >= ?\n        LIMIT 1\n    ");
    $stmt_lock = $conn->prepare("\n        SELECT id FROM booking_locks\n        WHERE venue_id = ? AND expires_at > NOW()\n          AND start_date < ? AND end_date > ?\n        LIMIT 1\n    ");
    if (!$stmt_candidates || !$stmt_direct || !$stmt_addon || !$stmt_maintenance || !$stmt_lock) {
        throw new RuntimeException('Unable to prepare hotel add-on allocation checks.');
    }

    $allocations = [];
    $room_subtotal = 0.0;
    foreach ($groups as $group) {
        $building = $group['building_name'];
        $room_type = $group['room_type'];
        $start_date = $group['start_date'];
        $end_date = $group['end_date'];
        $quantity = $group['quantity'];
        $allocation_group_key = ($group['room_group_id'] !== null
            ? 'group:' . $group['room_group_id']
            : $building . "\0" . $room_type) . "\0" . $start_date . "\0" . $end_date;

        if ($group['room_group_id'] !== null) $stmt_candidates->bind_param('i', $group['room_group_id']);
        else $stmt_candidates->bind_param('ss', $building, $room_type);
        if (!$stmt_candidates->execute()) throw new RuntimeException('Hotel add-on inventory could not be checked.');
        $candidate_result = $stmt_candidates->get_result();

        while ($room = $candidate_result->fetch_assoc()) {
            if (count($allocations[$allocation_group_key] ?? []) >= $quantity) break;
            $venue_id = (int)$room['id'];
            if (!isset($lockedCandidateMap[$venue_id])) continue;

            if (seminar_has_resource_conflict($conn, $venue_id, $start_date, $end_date, null, false)) continue;

            $stmt_direct->bind_param('iss', $venue_id, $end_date, $start_date);
            if (!$stmt_direct->execute()) throw new RuntimeException('Hotel direct-booking availability could not be checked.');
            if ($stmt_direct->get_result()->num_rows > 0) continue;

            $stmt_addon->bind_param('iiss', $venue_id, $booking_id, $end_date, $start_date);
            if (!$stmt_addon->execute()) throw new RuntimeException('Hotel add-on availability could not be checked.');
            if ($stmt_addon->get_result()->num_rows > 0) continue;

            $stmt_maintenance->bind_param('iss', $venue_id, $end_date, $start_date);
            if (!$stmt_maintenance->execute()) throw new RuntimeException('Hotel maintenance availability could not be checked.');
            if ($stmt_maintenance->get_result()->num_rows > 0) continue;

            $stmt_lock->bind_param('iss', $venue_id, $end_date, $start_date);
            if (!$stmt_lock->execute()) throw new RuntimeException('Hotel room holds could not be checked.');
            if ($stmt_lock->get_result()->num_rows > 0) continue;

            $allocations[$allocation_group_key][] = [
                'venue_id' => $venue_id,
                'room_group_id' => isset($room['room_group_id']) && (int)$room['room_group_id'] > 0 ? (int)$room['room_group_id'] : null,
                'nightly_rate' => (float)$room['nightly_rate'],
                'start_date' => $start_date,
                'end_date' => $end_date,
                'nights' => (int)$group['nights']
            ];
            $room_subtotal += (float)$room['nightly_rate'] * (int)$group['nights'];
        }

        if (count($allocations[$allocation_group_key] ?? []) !== $quantity) {
            throw new RuntimeException("Not enough inventory is available for {$building} - {$room_type} to finalize this Event Hall inquiry.");
        }
    }

    $stmt_delete = $conn->prepare('DELETE FROM booking_rooms WHERE booking_id = ?');
    $stmt_insert = $groups_schema_ready
        ? $conn->prepare('INSERT INTO booking_rooms (booking_id, venue_id, room_group_id, nightly_rate, start_date, end_date, nights, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        : $conn->prepare('INSERT INTO booking_rooms (booking_id, venue_id, nightly_rate, start_date, end_date, nights, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt_delete || !$stmt_insert) throw new RuntimeException('Unable to save the finalized hotel add-ons.');
    $stmt_delete->bind_param('i', $booking_id);
    if (!$stmt_delete->execute()) throw new RuntimeException('Unable to replace the provisional hotel add-ons.');

    foreach ($allocations as $group_allocations) {
        foreach ($group_allocations as $allocation) {
            $venue_id = $allocation['venue_id'];
            $nightly_rate = $allocation['nightly_rate'];
            $start_date = $allocation['start_date'];
            $end_date = $allocation['end_date'];
            $nights = $allocation['nights'];
            $line_total = $nightly_rate * $nights;
            $room_group_id = $allocation['room_group_id'];
            if ($groups_schema_ready) {
                $stmt_insert->bind_param('iiidssid', $booking_id, $venue_id, $room_group_id, $nightly_rate, $start_date, $end_date, $nights, $line_total);
            } else {
                $stmt_insert->bind_param('iidssid', $booking_id, $venue_id, $nightly_rate, $start_date, $end_date, $nights, $line_total);
            }
            if (!$stmt_insert->execute()) throw new RuntimeException('Unable to save a finalized hotel add-on.');
        }
    }

    return round($room_subtotal, 2);
}
