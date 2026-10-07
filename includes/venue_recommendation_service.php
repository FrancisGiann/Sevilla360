<?php
declare(strict_types=1);

/** Shared single-unit venue overlap rules used by recommendations and date checks. */
function venue_recommendation_is_available(mysqli $conn, int $venueId, string $category, string $startDate, string $endDate, ?string $sessionId = null): bool
{
    if (!in_array($category, ['Event Hall', 'Resort Villa'], true) || $venueId < 1 || $endDate < $startDate) return false;
    $bookingStatuses = $category === 'Event Hall' ? "('Confirmed','Completed')" : "('Pending','Confirmed','Completed')";
    $seminarKind = $category === 'Event Hall' ? 'hall' : 'room';
    $lockClause = $category === 'Resort Villa' ? " AND NOT EXISTS (
        SELECT 1 FROM booking_locks bl WHERE bl.venue_id = v.id AND bl.expires_at > NOW()
          AND bl.session_id <> ? AND bl.start_date <= ? AND bl.end_date >= ?
    )" : '';
    $sql = "SELECT v.id FROM venues v
        WHERE v.id = ? AND v.category = ? AND v.status = 'Available'
          AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.venue_id = v.id
            AND b.booking_status IN {$bookingStatuses} AND COALESCE(b.source, '') <> 'Maintenance'
            AND b.start_date <= ? AND b.end_date >= ?)
          AND NOT EXISTS (SELECT 1 FROM maintenance m WHERE m.venue_id = v.id AND m.is_blocking = 1
            AND (m.status = 'Scheduled' OR m.status IS NULL) AND m.start_date <= ? AND m.end_date >= ?)
          AND NOT EXISTS (SELECT 1 FROM seminar_reservations sr JOIN seminars s ON s.id = sr.seminar_id
            WHERE sr.venue_id = v.id AND s.status IN ('draft','finalized') AND sr.resource_kind = ?
              AND sr.start_date <= ? AND sr.end_date >= ?){$lockClause} LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare venue availability lookup.');
    $sessionId ??= session_id();
    $values = [$venueId, $category, $endDate, $startDate, $endDate, $startDate, $seminarKind, $endDate, $startDate];
    $types = 'issssssss';
    if ($category === 'Resort Villa') { $values[] = $sessionId; $values[] = $endDate; $values[] = $startDate; $types .= 'sss'; }
    $params = [$types];
    foreach ($values as $index => $value) $params[] = &$values[$index];
    call_user_func_array([$stmt, 'bind_param'], $params);
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to check venue availability.'); }
    $available = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $available;
}

/** Shared, database-backed Event Hall and Resort Villa result snapshot. */
function venue_recommendation_search(mysqli $conn, string $category, int $guestCount, ?string $startDate, ?string $endDate, array $knowledgeRecords, ?string $sessionId = null): array
{
    if (!in_array($category, ['Event Hall', 'Resort Villa'], true)) throw new InvalidArgumentException('Choose an event hall or resort villa.');
    if ($guestCount < 1 || $guestCount > 10000) throw new InvalidArgumentException('Choose a valid exact guest count.');
    if (($startDate === null) !== ($endDate === null)) throw new InvalidArgumentException('Provide both venue dates or neither.');
    $availabilityChecked = $startDate !== null;
    if ($availabilityChecked) {
        foreach ([$startDate, $endDate] as $date) {
            if (!is_string($date) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $date)) throw new InvalidArgumentException('Choose valid venue dates.');
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Choose valid venue dates.');
        }
        if ((string)$endDate < (string)$startDate) throw new InvalidArgumentException('The end date must be on or after the start date.');
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
        if (new DateTimeImmutable((string)$startDate) < $today) throw new InvalidArgumentException('Venue dates cannot start in the past.');
        if ($category === 'Event Hall' && $endDate !== $startDate) throw new InvalidArgumentException('Event halls use one event date.');
    }

    $candidates = [];
    foreach ($knowledgeRecords as $record) {
        if (($record['kind'] ?? null) !== 'venue' || ($record['category'] ?? null) !== $category) continue;
        $venueId = filter_var($record['venue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $capacity = filter_var($record['capacity_max'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($venueId === false || $capacity === false || $capacity < $guestCount) continue;
        $candidates[] = [$record, (int)$venueId, (int)$capacity];
    }

    $availability = [];
    if ($availabilityChecked && $candidates) {
        foreach ($candidates as [, $venueId]) {
            $availability[$venueId] = venue_recommendation_is_available($conn, $venueId, $category, (string)$startDate, (string)$endDate, $sessionId);
        }
        $candidates = array_values(array_filter($candidates, static fn(array $candidate): bool => $availability[$candidate[1]] ?? false));
    }

    usort($candidates, static function (array $left, array $right) use ($guestCount): int {
        $distance = abs($left[2] - $guestCount) <=> abs($right[2] - $guestCount);
        if ($distance !== 0) return $distance;
        $name = strcasecmp((string)($left[0]['name'] ?? ''), (string)($right[0]['name'] ?? ''));
        return $name !== 0 ? $name : ($left[1] <=> $right[1]);
    });

    $totalMatches = count($candidates);
    $results = [];
    foreach (array_slice($candidates, 0, 3) as [$record, $venueId, $capacity]) {
        $overnightStay = $category === 'Resort Villa' && $availabilityChecked && (string)$endDate > (string)$startDate;
        $rateValue = $overnightStay && isset($record['overnight_rate']) && is_numeric($record['overnight_rate'])
            ? (float)$record['overnight_rate']
            : (isset($record['base_rate']) && is_numeric($record['base_rate']) ? (float)$record['base_rate'] : null);
        $rateUnit = $overnightStay && isset($record['overnight_rate']) && is_numeric($record['overnight_rate']) ? 'per night' : (string)($record['rate_unit'] ?? 'per day');
        $results[] = [
            'id' => 'venue-' . $venueId, 'venue_id' => $venueId, 'room_group_id' => 0, 'category' => $category,
            'title' => (string)($record['name'] ?? 'Venue'), 'building_name' => (string)($record['name'] ?? 'Venue'),
            'status' => $availabilityChecked ? 'Available' : 'Availability not checked',
            'description' => (string)($record['description'] ?? ''),
            'amenities' => array_values(array_filter(is_array($record['amenities'] ?? null) ? $record['amenities'] : [], 'is_string')),
            'capacity' => 'up to ' . $capacity . ' guests', 'capacity_value' => $capacity,
            'rate' => $rateValue === null ? 'Not listed' : '₱' . number_format($rateValue, 2) . ' ' . $rateUnit,
            'rate_value' => $rateValue, 'rate_unit' => $rateUnit,
            'overnight_rate_unlisted' => $overnightStay && (!isset($record['overnight_rate']) || !is_numeric($record['overnight_rate'])),
            'availability_checked' => $availabilityChecked,
            'reasons' => [['label' => 'Capacity fit', 'value' => $capacity === $guestCount
                ? 'fits your ' . $guestCount . '-guest group exactly'
                : 'accommodates ' . $guestCount . ' guests with room for ' . ($capacity - $guestCount) . ' more']],
        ];
    }

    return [
        'success' => true, 'intent' => $category,
        'state' => $totalMatches > 0 ? 'matched' : 'no_match',
        'guest_count' => $guestCount, 'check_in' => $startDate, 'check_out' => $endDate,
        'availability_checked' => $availabilityChecked, 'checked_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        'total_matches' => $totalMatches, 'results' => $results,
    ];
}

/** Exact Event Hall or Resort Villa date lookup that retains an unavailable named target. */
function venue_recommendation_exact_availability(mysqli $conn, string $category, int $venueId, string $startDate, string $endDate, array $knowledgeRecords, ?string $sessionId = null): array
{
    if (!in_array($category, ['Event Hall', 'Resort Villa'], true) || $venueId < 1) throw new InvalidArgumentException('Choose an exact event hall or resort villa.');
    foreach ([$startDate, $endDate] as $date) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Choose valid venue dates.');
    }
    if ($endDate < $startDate) throw new InvalidArgumentException('The end date must be on or after the start date.');
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    if (new DateTimeImmutable($startDate) < $today) throw new InvalidArgumentException('Venue dates cannot start in the past.');
    if ($category === 'Event Hall' && $endDate !== $startDate) throw new InvalidArgumentException('Event halls use one event date.');

    $record = null;
    foreach ($knowledgeRecords as $candidate) {
        if (($candidate['kind'] ?? null) === 'venue' && ($candidate['category'] ?? null) === $category
            && (int)($candidate['venue_id'] ?? 0) === $venueId) { $record = $candidate; break; }
    }
    if (!is_array($record)) throw new InvalidArgumentException('That venue is no longer in the public catalog.');
    $available = venue_recommendation_is_available($conn, $venueId, $category, $startDate, $endDate, $sessionId);
    $overnightStay = $category === 'Resort Villa' && $endDate > $startDate;
    $rateValue = $overnightStay && is_numeric($record['overnight_rate'] ?? null)
        ? (float)$record['overnight_rate'] : (is_numeric($record['base_rate'] ?? null) ? (float)$record['base_rate'] : null);
    $rateUnit = $overnightStay && is_numeric($record['overnight_rate'] ?? null) ? 'per night' : (string)($record['rate_unit'] ?? 'per day');
    $result = [
        'id' => 'venue-' . $venueId, 'venue_id' => $venueId, 'room_group_id' => 0, 'category' => $category,
        'title' => (string)($record['name'] ?? 'Venue'), 'building_name' => (string)($record['name'] ?? 'Venue'),
        'status' => $available ? 'Available' : 'Unavailable for these dates', 'availability_checked' => true,
        'description' => (string)($record['description'] ?? ''),
        'amenities' => array_values(array_filter(is_array($record['amenities'] ?? null) ? $record['amenities'] : [], 'is_string')),
        'capacity' => isset($record['capacity_max']) ? 'up to ' . (int)$record['capacity_max'] . ' guests' : null,
        'capacity_value' => isset($record['capacity_max']) ? (int)$record['capacity_max'] : null,
        'rate' => $rateValue === null ? 'Not listed' : '₱' . number_format($rateValue, 2) . ' ' . $rateUnit,
        'rate_value' => $rateValue, 'rate_unit' => $rateUnit,
        'overnight_rate_unlisted' => $overnightStay && !is_numeric($record['overnight_rate'] ?? null),
        'reasons' => [],
    ];
    return [
        'success' => true, 'intent' => $category, 'state' => $available ? 'matched' : 'unavailable',
        'exact_target' => true, 'target_found' => true, 'guest_count' => null,
        'check_in' => $startDate, 'check_out' => $endDate, 'availability_checked' => true,
        'checked_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        'total_matches' => $available ? 1 : 0, 'results' => [$result],
    ];
}
