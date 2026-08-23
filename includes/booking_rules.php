<?php

/**
 * Returns the overlap predicate used for customer inventory.
 * Event halls occupy every calendar date in their inclusive range; overnight
 * hotel/villa stays use checkout-exclusive intervals.
 */
function booking_overlap_sql(string $category, string $start_column = 'start_date', string $end_column = 'end_date'): string
{
    return $category === 'Event Hall'
        ? "($start_column <= ? AND $end_column >= ?)"
        : "($start_column < ? AND $end_column > ?)";
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
function reallocate_event_hall_addons(mysqli $conn, int $booking_id): float
{
    $stmt_requested = $conn->prepare("\n        SELECT br.start_date, br.end_date, v.name AS building_name, h.room_type\n        FROM booking_rooms br\n        LEFT JOIN venues v ON v.id = br.venue_id\n        LEFT JOIN hotel_rooms h ON h.venue_id = br.venue_id\n        WHERE br.booking_id = ?\n        ORDER BY v.name, h.room_type, br.start_date, br.end_date, br.id\n    ");
    if (!$stmt_requested) throw new RuntimeException('Unable to load the requested hotel add-ons.');
    $stmt_requested->bind_param('i', $booking_id);
    if (!$stmt_requested->execute()) throw new RuntimeException('Unable to load the requested hotel add-ons.');

    $groups = [];
    $requested_result = $stmt_requested->get_result();
    while ($row = $requested_result->fetch_assoc()) {
        $building = trim((string)($row['building_name'] ?? ''));
        $room_type = trim((string)($row['room_type'] ?? ''));
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

        $key = $building . "\0" . $room_type . "\0" . $start_date . "\0" . $end_date;
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'building_name' => $building,
                'room_type' => $room_type,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'nights' => $start_dt->diff($end_dt)->days,
                'quantity' => 0
            ];
        }
        $groups[$key]['quantity']++;
    }

    if (!$groups) return 0.0;
    ksort($groups, SORT_STRING);

    // Lock candidate venue rows in deterministic group/id order before
    // checking occupancy. The caller already locks the Event Hall venue row.
    $stmt_candidates = $conn->prepare("\n        SELECT v.id, h.nightly_rate\n        FROM venues v\n        INNER JOIN hotel_rooms h ON h.venue_id = v.id\n        WHERE v.name = ? AND h.room_type = ? AND v.status = 'Available'\n        ORDER BY v.id\n        FOR UPDATE\n    ");
    $stmt_direct = $conn->prepare("\n        SELECT id FROM bookings\n        WHERE venue_id = ? AND booking_status IN ('Pending', 'Confirmed', 'Completed')\n          AND source <> 'Maintenance'\n          AND start_date < ? AND end_date > ?\n        LIMIT 1\n    ");
    $stmt_addon = $conn->prepare("\n        SELECT br.id\n        FROM booking_rooms br\n        INNER JOIN bookings b ON b.id = br.booking_id\n        INNER JOIN venues parent_v ON parent_v.id = b.venue_id\n        WHERE br.venue_id = ? AND b.id <> ?\n          AND b.booking_status IN ('Pending', 'Confirmed', 'Completed')\n          AND NOT (b.booking_status = 'Pending' AND parent_v.category = 'Event Hall')\n          AND b.source <> 'Maintenance'\n          AND br.start_date < ? AND br.end_date > ?\n        LIMIT 1\n    ");
    $stmt_maintenance = $conn->prepare("\n        SELECT id FROM maintenance\n        WHERE venue_id = ? AND is_blocking = 1 AND status = 'Scheduled'\n          AND start_date <= ? AND end_date >= ?\n        LIMIT 1\n    ");
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

        $stmt_candidates->bind_param('ss', $building, $room_type);
        if (!$stmt_candidates->execute()) throw new RuntimeException('Hotel add-on inventory could not be checked.');
        $candidate_result = $stmt_candidates->get_result();

        while ($room = $candidate_result->fetch_assoc()) {
            if (count($allocations[$group['building_name'] . "\0" . $room_type . "\0" . $start_date . "\0" . $end_date] ?? []) >= $quantity) break;
            $venue_id = (int)$room['id'];

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

            $group_key = $group['building_name'] . "\0" . $room_type . "\0" . $start_date . "\0" . $end_date;
            $allocations[$group_key][] = [
                'venue_id' => $venue_id,
                'nightly_rate' => (float)$room['nightly_rate'],
                'start_date' => $start_date,
                'end_date' => $end_date,
                'nights' => (int)$group['nights']
            ];
            $room_subtotal += (float)$room['nightly_rate'] * (int)$group['nights'];
        }

        $group_key = $group['building_name'] . "\0" . $room_type . "\0" . $start_date . "\0" . $end_date;
        if (count($allocations[$group_key] ?? []) !== $quantity) {
            throw new RuntimeException("Not enough inventory is available for {$building} - {$room_type} to finalize this Event Hall inquiry.");
        }
    }

    $stmt_delete = $conn->prepare('DELETE FROM booking_rooms WHERE booking_id = ?');
    $stmt_insert = $conn->prepare('INSERT INTO booking_rooms (booking_id, venue_id, nightly_rate, start_date, end_date, nights, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)');
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
            $stmt_insert->bind_param('iidssid', $booking_id, $venue_id, $nightly_rate, $start_date, $end_date, $nights, $line_total);
            if (!$stmt_insert->execute()) throw new RuntimeException('Unable to save a finalized hotel add-on.');
        }
    }

    return round($room_subtotal, 2);
}
