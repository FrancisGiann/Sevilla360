<?php

function seminar_strict_date(mixed $value, string $label): string
{
    if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value)) {
        throw new InvalidArgumentException("Choose a valid {$label} date.");
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException("Choose a valid {$label} date.");
    return $value;
}

function seminar_validate_dates(mixed $hallStart, mixed $hallEnd, mixed $checkIn, mixed $checkOut): array
{
    $hallStart = seminar_strict_date($hallStart, 'hall start');
    $hallEnd = seminar_strict_date($hallEnd, 'hall end');
    $checkIn = seminar_strict_date($checkIn, 'hotel check-in');
    $checkOut = seminar_strict_date($checkOut, 'hotel checkout');
    if ($hallEnd < $hallStart) throw new InvalidArgumentException('Hall end date must be on or after the start date.');
    if ($checkOut <= $checkIn) throw new InvalidArgumentException('Hotel checkout must be after check-in.');
    if (new DateTimeImmutable($hallStart) < new DateTimeImmutable('today') || new DateTimeImmutable($checkIn) < new DateTimeImmutable('today')) {
        throw new InvalidArgumentException('Seminar dates cannot start in the past.');
    }
    return [$hallStart, $hallEnd, $checkIn, $checkOut];
}

/**
 * Caller must lock the target venue row before relying on this conflict check.
 * Pass $lockRows=false only when the caller holds the complete venue mutex set
 * and uses READ COMMITTED; this avoids a venue→seminar-row lock inversion.
 */
function seminar_has_resource_conflict(mysqli $conn, int $venueId, string $startDate, string $endDate, ?int $excludeSeminarId = null, bool $lockRows = true): bool
{
    $sql = "SELECT sr.id FROM seminar_reservations sr JOIN seminars s ON s.id = sr.seminar_id
        WHERE sr.venue_id = ? AND s.status IN ('draft','finalized')
          AND ((sr.resource_kind = 'hall' AND sr.start_date <= ? AND sr.end_date >= ?)
            OR (sr.resource_kind = 'room' AND sr.start_date < ? AND sr.end_date > ?))";
    $types = 'issss';
    $values = [$venueId, $endDate, $startDate, $endDate, $startDate];
    if ($excludeSeminarId !== null) {
        $sql .= ' AND s.id <> ?';
        $types .= 'i';
        $values[] = $excludeSeminarId;
    }
    $sql .= ' LIMIT 1' . ($lockRows ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to validate seminar availability.');
    $params = [$types];
    foreach ($values as $index => $value) $params[] = &$values[$index];
    call_user_func_array([$stmt, 'bind_param'], $params);
    if (!$stmt->execute()) throw new RuntimeException('Unable to validate seminar availability.');
    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $found;
}

function seminar_has_maintenance_conflict(mysqli $conn, int $venueId, string $startDate, string $endDate): bool
{
    $stmt = $conn->prepare("SELECT sr.id FROM seminar_reservations sr JOIN seminars s ON s.id=sr.seminar_id
        WHERE sr.venue_id=? AND s.status IN ('draft','finalized')
          AND ((sr.resource_kind='hall' AND sr.start_date <= ? AND sr.end_date >= ?)
            OR (sr.resource_kind='room' AND sr.start_date < ? AND sr.end_date > ?))
        LIMIT 1 FOR UPDATE");
    if (!$stmt) throw new RuntimeException('Unable to validate seminar maintenance conflicts.');
    $stmt->bind_param('issss', $venueId, $endDate, $startDate, $endDate, $startDate);
    if (!$stmt->execute()) throw new RuntimeException('Unable to validate seminar maintenance conflicts.');
    $found = $stmt->get_result()->num_rows > 0; $stmt->close();
    return $found;
}

/** Build the available physical hotel room list for an exclusive stay interval. */
function seminar_available_room_units(mysqli $conn, string $checkIn, string $checkOut, ?int $excludeSeminarId = null): array
{
    if ($checkOut <= $checkIn) throw new InvalidArgumentException('Hotel checkout must be after check-in.');
    $groupsReady = false;
    $schema = $conn->query("SELECT COUNT(DISTINCT table_name) AS found FROM information_schema.columns
        WHERE table_schema=DATABASE() AND ((table_name='hotel_room_groups' AND column_name IN ('id','building_name','display_name','sort_order'))
            OR (table_name='hotel_rooms' AND column_name IN ('venue_id','room_type','room_number','max_capacity','room_group_id')))");
    $groupsReady = $schema && (int)($schema->fetch_assoc()['found'] ?? 0) === 2;
    $building = $groupsReady ? 'COALESCE(g.building_name,v.name)' : 'v.name';
    $roomType = $groupsReady ? 'COALESCE(g.display_name,h.room_type)' : 'h.room_type';
    $sortOrder = $groupsReady ? 'g.sort_order,' : '';
    $groupJoin = $groupsReady ? 'LEFT JOIN hotel_room_groups g ON g.id=h.room_group_id' : '';
    $conflicts = [];
    $checks = [
        ["SELECT b.venue_id,'Existing booking' AS reason FROM bookings b WHERE b.booking_status IN ('Pending','Confirmed','Completed') AND COALESCE(b.source,'')<>'Maintenance' AND b.start_date < ? AND b.end_date > ?", 'ss'],
        ["SELECT br.venue_id,'Room add-on booking' AS reason FROM booking_rooms br JOIN bookings b ON b.id=br.booking_id JOIN venues parent_v ON parent_v.id=b.venue_id WHERE b.booking_status IN ('Pending','Confirmed','Completed') AND NOT (b.booking_status='Pending' AND parent_v.category='Event Hall') AND COALESCE(b.source,'')<>'Maintenance' AND br.start_date < ? AND br.end_date > ?", 'ss'],
        ["SELECT m.venue_id,'Maintenance' AS reason FROM maintenance m WHERE m.is_blocking=1 AND (m.status='Scheduled' OR m.status IS NULL) AND m.start_date < ? AND m.end_date > ?", 'ss'],
        ["SELECT bl.venue_id,'Temporary hold' AS reason FROM booking_locks bl WHERE bl.expires_at>NOW() AND bl.start_date < ? AND bl.end_date > ?", 'ss'],
    ];
    foreach ($checks as [$conflictSql, $types]) {
        $stmt = $conn->prepare($conflictSql);
        if (!$stmt) throw new RuntimeException('Unable to check seminar hotel room availability.');
        $stmt->bind_param($types, $checkOut, $checkIn);
        if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to check seminar hotel room availability.'); }
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $conflicts[(int)$row['venue_id']] ??= $row['reason'];
        $stmt->close();
    }
    $seminarSql = "SELECT sr.venue_id,'Another seminar' AS reason FROM seminar_reservations sr JOIN seminars sem ON sem.id=sr.seminar_id
        WHERE sr.resource_kind='room' AND sem.status IN ('draft','finalized') AND sr.start_date < ? AND sr.end_date > ?";
    $seminarTypes = 'ss';
    $seminarValues = [$checkOut, $checkIn];
    if ($excludeSeminarId !== null) { $seminarSql .= ' AND sem.id<>?'; $seminarTypes .= 'i'; $seminarValues[] = $excludeSeminarId; }
    $stmt = $conn->prepare($seminarSql);
    if (!$stmt) throw new RuntimeException('Unable to check seminar hotel room availability.');
    $params = [$seminarTypes]; foreach ($seminarValues as $index => $value) $params[] = &$seminarValues[$index];
    call_user_func_array([$stmt, 'bind_param'], $params);
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to check seminar hotel room availability.'); }
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $conflicts[(int)$row['venue_id']] ??= $row['reason'];
    $stmt->close();
    $sql = "SELECT v.id AS venue_id,v.name,h.room_number,h.room_type,h.floor_label,h.max_capacity,{$building} AS building_name,{$roomType} AS room_label
        FROM venues v INNER JOIN hotel_rooms h ON h.venue_id=v.id {$groupJoin}
        WHERE v.category='Hotel Room' AND v.status='Available'
        ORDER BY {$building},{$sortOrder}h.room_type,h.room_number,v.id";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to check seminar hotel room availability.');
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to check seminar hotel room availability.'); }
    $rooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rooms as &$room) {
        $reason = $conflicts[(int)$room['venue_id']] ?? null;
        $room['available'] = $reason === null ? 1 : 0;
        $room['unavailable_reason'] = $reason;
    }
    unset($room);
    return $rooms;
}

/** Return all usable halls with date-aware conflict state for the reservation picker. */
function seminar_available_halls(mysqli $conn, string $startDate, string $endDate, ?int $excludeSeminarId = null): array
{
    if ($endDate < $startDate) throw new InvalidArgumentException('Hall end date must be on or after the start date.');
    $conflicts = [];
    $checks = [
        ["SELECT b.venue_id,'Existing booking' AS reason FROM bookings b WHERE b.booking_status IN ('Confirmed','Completed') AND COALESCE(b.source,'')<>'Maintenance' AND b.start_date <= ? AND b.end_date >= ?", 'ss'],
        ["SELECT m.venue_id,'Maintenance' AS reason FROM maintenance m WHERE m.is_blocking=1 AND (m.status='Scheduled' OR m.status IS NULL) AND m.start_date <= ? AND m.end_date >= ?", 'ss'],
        ["SELECT bl.venue_id,'Temporary hold' AS reason FROM booking_locks bl WHERE bl.expires_at>NOW() AND bl.start_date <= ? AND bl.end_date >= ?", 'ss'],
    ];
    foreach ($checks as [$sql, $types]) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException('Unable to check Event Hall availability.');
        $stmt->bind_param($types, $endDate, $startDate);
        if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to check Event Hall availability.'); }
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $conflicts[(int)$row['venue_id']] ??= $row['reason'];
        $stmt->close();
    }
    $sql = "SELECT sr.venue_id,'Another seminar' AS reason FROM seminar_reservations sr JOIN seminars sem ON sem.id=sr.seminar_id
        WHERE sr.resource_kind='hall' AND sem.status IN ('draft','finalized') AND sr.start_date <= ? AND sr.end_date >= ?";
    $types = 'ss'; $values = [$endDate, $startDate];
    if ($excludeSeminarId !== null) { $sql .= ' AND sem.id<>?'; $types .= 'i'; $values[] = $excludeSeminarId; }
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to check Event Hall availability.');
    $params = [$types]; foreach ($values as $index => $value) $params[] = &$values[$index];
    call_user_func_array([$stmt, 'bind_param'], $params);
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to check Event Hall availability.'); }
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $conflicts[(int)$row['venue_id']] ??= $row['reason'];
    $stmt->close();
    $stmt = $conn->prepare("SELECT v.id,v.name,COALESCE(GREATEST(e.capacity_theater,e.capacity_classroom,e.capacity_banquet),0) AS capacity FROM venues v JOIN event_halls e ON e.venue_id=v.id WHERE v.category='Event Hall' AND v.status='Available' ORDER BY v.name");
    if (!$stmt || !$stmt->execute()) throw new RuntimeException('Unable to load Event Halls.');
    $halls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    foreach ($halls as &$hall) { $reason = $conflicts[(int)$hall['id']] ?? null; $hall['available'] = $reason === null ? 1 : 0; $hall['unavailable_reason'] = $reason; }
    unset($hall);
    return $halls;
}

function seminar_normalize_room_ids(mixed $value, int $maxRooms = 500): array
{
    if (!is_array($value) || count($value) < 1 || count($value) > $maxRooms) throw new InvalidArgumentException("Select between 1 and {$maxRooms} hotel room units.");
    $ids = [];
    foreach ($value as $raw) {
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new InvalidArgumentException('Choose valid hotel room units.');
        $ids[] = (int)$id;
    }
    if (count(array_unique($ids)) !== count($ids)) throw new InvalidArgumentException('A hotel room can only be selected once.');
    sort($ids, SORT_NUMERIC);
    return $ids;
}

/**
 * Caller must hold the room's venues row lock before rechecking a hotel add-on.
 * Pass $lockConflictRows=false only with the complete venue mutex set held in a
 * READ COMMITTED transaction; other callers retain locking reads by default.
 */
function seminar_assert_hotel_room_available(mysqli $conn, int $venueId, string $startDate, string $endDate, int $excludeBookingId, bool $lockConflictRows = true): void
{
    if ($endDate <= $startDate) throw new InvalidArgumentException('Hotel room checkout must be after check-in.');
    if (seminar_has_resource_conflict($conn, $venueId, $startDate, $endDate, null, $lockConflictRows)) {
        throw new InvalidArgumentException('A hotel add-on room is reserved for a seminar.');
    }
    $lockingClause = $lockConflictRows ? ' FOR UPDATE' : '';
    $checks = [
        ["SELECT id FROM bookings WHERE venue_id=? AND id<>? AND booking_status IN ('Pending','Confirmed','Completed')
            AND COALESCE(source,'')<>'Maintenance' AND start_date < ? AND end_date > ? LIMIT 1{$lockingClause}", 'iiss', 'The hotel add-on room is booked for those dates.'],
        ["SELECT br.id FROM booking_rooms br JOIN bookings b ON b.id=br.booking_id JOIN venues parent_v ON parent_v.id=b.venue_id
            WHERE br.venue_id=? AND br.booking_id<>? AND b.booking_status IN ('Pending','Confirmed','Completed')
            AND NOT (b.booking_status='Pending' AND parent_v.category='Event Hall') AND COALESCE(b.source,'')<>'Maintenance'
            AND br.start_date < ? AND br.end_date > ? LIMIT 1{$lockingClause}", 'iiss', 'The hotel add-on room is attached to another booking.'],
        ["SELECT id FROM maintenance WHERE venue_id=? AND is_blocking=1 AND (status='Scheduled' OR status IS NULL)
            AND start_date < ? AND end_date > ? LIMIT 1{$lockingClause}", 'iss', 'The hotel add-on room is under maintenance.'],
        ["SELECT id FROM booking_locks WHERE venue_id=? AND expires_at>NOW() AND start_date < ? AND end_date > ? LIMIT 1{$lockingClause}", 'iss', 'The hotel add-on room is temporarily held by another booking.'],
    ];
    foreach ($checks as [$sql, $types, $message]) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException('Unable to validate hotel add-on availability.');
        if ($types === 'iiss') $stmt->bind_param($types, $venueId, $excludeBookingId, $endDate, $startDate);
        else $stmt->bind_param($types, $venueId, $endDate, $startDate);
        if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to validate hotel add-on availability.'); }
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($found) throw new InvalidArgumentException($message);
    }
}

function seminar_text(mixed $value, int $maxLength, bool $required = true): string
{
    if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) throw new InvalidArgumentException('Text fields must use UTF-8 encoding.');
    $value = is_string($value) ? trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '') : '';
    if ($required && $value === '') throw new InvalidArgumentException('Required attendee fields cannot be blank.');
    if (mb_strlen($value, 'UTF-8') > $maxLength) throw new InvalidArgumentException('A value exceeds the allowed length.');
    return $value;
}

/** Validate a manually entered seminar contract price for DECIMAL(12,2). */
function seminar_validate_agreed_price(mixed $value): ?string
{
    if ($value === null) return null;
    if (!is_string($value) && !is_int($value)) {
        throw new InvalidArgumentException('Enter a non-negative price with up to 10 whole digits and 2 decimal places.');
    }
    $price = trim((string)$value);
    if ($price === '') return null;
    if (!preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', $price)) {
        throw new InvalidArgumentException('Enter a non-negative price with up to 10 whole digits and 2 decimal places.');
    }
    [$whole, $fraction] = array_pad(explode('.', $price, 2), 2, '');
    $whole = ltrim($whole, '0');
    $whole = $whole === '' ? '0' : $whole;
    if (strlen($whole) > 10) throw new InvalidArgumentException('Enter a non-negative price with up to 10 whole digits and 2 decimal places.');
    $fraction = str_pad($fraction, 2, '0');
    return $whole . '.' . $fraction;
}

function seminar_normalize_gender(mixed $gender): string
{
    $value = strtolower(trim((string)$gender));
    if (in_array($value, ['f', 'female', 'woman', 'women'], true)) return 'female';
    if (in_array($value, ['m', 'male', 'man', 'men'], true)) return 'male';
    return 'unknown';
}

/** Assigns only known genders and never exceeds physical room capacity. */
function seminar_allocate_attendees(array $attendees, array $rooms): array
{
    $assignments = [];
    $roomState = [];
    foreach ($rooms as $room) {
        $id = (int)$room['venue_id'];
        $roomState[$id] = ['capacity' => max(0, (int)$room['max_capacity']), 'people' => [], 'gender' => null, 'pool_gender' => null, 'locations' => []];
    }
    ksort($roomState, SORT_NUMERIC);
    $genders = ['female', 'male'];
    $genderCounts = ['female' => 0, 'male' => 0];
    foreach ($attendees as $attendee) {
        $gender = seminar_normalize_gender($attendee['gender'] ?? 'unknown');
        if (isset($genderCounts[$gender])) $genderCounts[$gender]++;
    }
    $knownAttendees = array_sum($genderCounts);
    $roomCapacities = [];
    $inventoryGenderCapacity = 0;
    $largestGenderRoom = 0;
    foreach ($roomState as $roomId => $state) {
        if ($state['capacity'] < 1) continue;
        $effectiveCapacity = min($state['capacity'], $knownAttendees);
        $roomCapacities[$roomId] = $effectiveCapacity;
        $inventoryGenderCapacity += $effectiveCapacity;
        $largestGenderRoom = max($largestGenderRoom, $effectiveCapacity);
    }

    $femaleRoomIds = [];
    if ($genderCounts['female'] > 0 && $genderCounts['male'] > 0) {
        // Find a deterministic subset of rooms for women while leaving enough
        // capacity in the remaining rooms for men. The same partition rule is
        // used by the room picker, so a successful suggestion is allocatable.
        $femaleCount = $genderCounts['female'];
        $maleCount = $genderCounts['male'];
        $subsetLimit = min($inventoryGenderCapacity, $femaleCount + $largestGenderRoom - 1);
        $reachable = array_fill(0, $subsetLimit + 1, false);
        $reachable[0] = true;
        $decisionRows = [];
        $capacityRows = [];
        foreach ($roomCapacities as $roomId => $capacity) {
            $decisions = str_repeat("\0", $subsetLimit + 1);
            if ($capacity <= $subsetLimit) {
                for ($beds = $subsetLimit; $beds >= $capacity; $beds--) {
                    if ($reachable[$beds] || !$reachable[$beds - $capacity]) continue;
                    $reachable[$beds] = true;
                    $decisions[$beds] = "\1";
                }
            }
            $capacityRows[] = [$roomId, $capacity];
            $decisionRows[] = $decisions;
        }

        $chosenFemaleCapacity = null;
        for ($beds = $femaleCount; $beds <= $subsetLimit; $beds++) {
            if ($reachable[$beds] && $inventoryGenderCapacity - $beds >= $maleCount) {
                $chosenFemaleCapacity = $beds;
                break;
            }
        }
        if ($chosenFemaleCapacity === null) {
            // If inventory cannot fit both groups, still divide room capacity
            // to maximize assigned attendees instead of letting the first
            // gender consume every room.
            $bestAssigned = -1;
            $bestImbalance = PHP_INT_MAX;
            $bestDistance = PHP_INT_MAX;
            $idealFemaleCapacity = (int)round($inventoryGenderCapacity * $femaleCount / max(1, $knownAttendees));
            for ($beds = 0; $beds <= $subsetLimit; $beds++) {
                if (!$reachable[$beds]) continue;
                $femaleAssigned = min($femaleCount, $beds);
                $maleAssigned = min($maleCount, max(0, $inventoryGenderCapacity - $beds));
                $assigned = $femaleAssigned + $maleAssigned;
                $imbalance = abs(($femaleCount - $femaleAssigned) - ($maleCount - $maleAssigned));
                $distance = abs($beds - $idealFemaleCapacity);
                if ($assigned > $bestAssigned
                    || ($assigned === $bestAssigned && $imbalance < $bestImbalance)
                    || ($assigned === $bestAssigned && $imbalance === $bestImbalance && $distance < $bestDistance)) {
                    $chosenFemaleCapacity = $beds;
                    $bestAssigned = $assigned;
                    $bestImbalance = $imbalance;
                    $bestDistance = $distance;
                }
            }
        }

        $beds = $chosenFemaleCapacity ?? 0;
        for ($index = count($capacityRows) - 1; $index >= 0 && $beds > 0; $index--) {
            if ($decisionRows[$index][$beds] !== "\1") continue;
            $femaleRoomIds[] = $capacityRows[$index][0];
            $beds -= $capacityRows[$index][1];
        }
        $femaleRoomSet = array_fill_keys($femaleRoomIds, true);
        foreach ($roomState as $roomId => &$state) {
            if ($state['capacity'] < 1) continue;
            $state['pool_gender'] = isset($femaleRoomSet[$roomId]) ? 'female' : 'male';
        }
        unset($state);
    } elseif ($genderCounts['female'] > 0 || $genderCounts['male'] > 0) {
        $onlyGender = $genderCounts['female'] > 0 ? 'female' : 'male';
        foreach ($roomState as &$state) if ($state['capacity'] > 0) $state['pool_gender'] = $onlyGender;
        unset($state);
    }
    $normalizeLocation = static function (mixed $value): string {
        $raw = is_scalar($value) ? (string)$value : '';
        $collapsed = preg_replace('/\s+/u', ' ', trim($raw));
        if ($collapsed === null) $collapsed = preg_replace('/\s+/', ' ', trim($raw)) ?? trim($raw);
        $label = $collapsed !== '' ? $collapsed : 'Unknown location';
        return function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
    };
    $chooseRoom = static function (callable $eligible, int $required = 1) use (&$roomState): ?int {
        $chosenId = null;
        $bestRemainder = PHP_INT_MAX;
        foreach ($roomState as $roomId => $state) {
            $free = $state['capacity'] - count($state['people']);
            if ($free < $required || !$eligible($roomId, $state)) continue;
            $remainder = $free - $required;
            if ($remainder < $bestRemainder || ($remainder === $bestRemainder && ($chosenId === null || $roomId < $chosenId))) {
                $chosenId = $roomId;
                $bestRemainder = $remainder;
            }
        }
        return $chosenId;
    };
    $chooseLargestRoom = static function (callable $eligible) use (&$roomState): ?int {
        $chosenId = null;
        $largestFree = 0;
        foreach ($roomState as $roomId => $state) {
            $free = $state['capacity'] - count($state['people']);
            if ($free < 1 || !$eligible($roomId, $state)) continue;
            if ($free > $largestFree || ($free === $largestFree && ($chosenId === null || $roomId < $chosenId))) {
                $chosenId = $roomId;
                $largestFree = $free;
            }
        }
        return $chosenId;
    };
    $addPeople = static function (int $roomId, array $people, string $gender, string $locationKey) use (&$roomState, &$assignments): void {
        $roomState[$roomId]['gender'] = $gender;
        $roomState[$roomId]['locations'][$locationKey] = true;
        foreach ($people as $person) {
            $personId = (int)$person['id'];
            $roomState[$roomId]['people'][] = $personId;
            $assignments[$personId] = $roomId;
        }
    };
    foreach ($genders as $gender) {
        $groups = [];
        foreach ($attendees as $attendee) {
            if (seminar_normalize_gender($attendee['gender'] ?? 'unknown') !== $gender) continue;
            $locationKey = $normalizeLocation($attendee['location'] ?? '');
            $groups[$locationKey][] = $attendee;
        }
        uksort($groups, static function ($a, $b) use ($groups) {
            return count($groups[$b]) <=> count($groups[$a]) ?: strcmp($a, $b);
        });
        foreach ($groups as $locationKey => $people) {
            $remaining = count($people);
            $sameLocation = static fn(int $roomId, array $state): bool => $state['pool_gender'] === $gender && $state['gender'] === $gender && isset($state['locations'][$locationKey]);
            $empty = static fn(int $roomId, array $state): bool => $state['pool_gender'] === $gender && count($state['people']) === 0 && $state['capacity'] > 0;
            $sameGenderOccupied = static fn(int $roomId, array $state): bool => $state['pool_gender'] === $gender && $state['gender'] === $gender && count($state['people']) > 0;

            // Keep a location cohort together when a compatible room can take
            // it, while using space in occupied same-gender rooms first.
            $roomId = $chooseRoom($sameLocation, $remaining);
            if ($roomId === null) $roomId = $chooseRoom($sameGenderOccupied, $remaining);
            if ($roomId === null) $roomId = $chooseRoom($empty, $remaining);
            if ($roomId !== null) {
                $addPeople($roomId, $people, $gender, $locationKey);
                continue;
            }

            // Split a cohort only when no single compatible room can contain its remainder.
            $offset = 0;
            while ($offset < count($people)) {
                $remaining = count($people) - $offset;
                $roomId = $chooseRoom($sameLocation, $remaining);
                if ($roomId === null) $roomId = $chooseRoom($sameGenderOccupied, $remaining);
                if ($roomId === null) $roomId = $chooseRoom($empty, $remaining);
                if ($roomId !== null) {
                    $chunk = array_slice($people, $offset);
                    $addPeople($roomId, $chunk, $gender, $locationKey);
                    break;
                }

                // Fill an existing same-location room before opening another room.
                $roomId = $chooseRoom($sameLocation, 1);
                if ($roomId === null) $roomId = $chooseRoom($sameGenderOccupied, 1);
                if ($roomId === null) $roomId = $chooseLargestRoom($empty);
                if ($roomId === null) break;
                $free = $roomState[$roomId]['capacity'] - count($roomState[$roomId]['people']);
                $take = min($free, $remaining);

                // Leave two attendees for another room instead of creating a singleton when feasible.
                if ($remaining - $take === 1 && $take > 1) {
                    $anotherRoomCanPair = false;
                    foreach ($roomState as $otherRoomId => $otherState) {
                        if ($otherRoomId === $roomId) continue;
                        $otherFree = $otherState['capacity'] - count($otherState['people']);
                        if ($otherFree >= 2 && $otherState['pool_gender'] === $gender) {
                            $anotherRoomCanPair = true;
                            break;
                        }
                    }
                    if ($anotherRoomCanPair) $take--;
                }
                $chunk = array_slice($people, $offset, $take);
                $addPeople($roomId, $chunk, $gender, $locationKey);
                $offset += $take;
            }

            // Cohorts are kept together when possible, then any members left
            // over use spare occupied beds before another room is considered.
            foreach ($people as $person) {
                $personId = (int)$person['id'];
                if (isset($assignments[$personId])) continue;
                $roomId = $chooseRoom($sameLocation, 1);
                if ($roomId === null) $roomId = $chooseRoom($sameGenderOccupied, 1);
                if ($roomId !== null) $addPeople($roomId, [$person], $gender, $locationKey);
            }
        }
    }
    // A single occupant can move from a fuller same-gender room when that
    // keeps both rooms multi-occupant and within their separate capacities.
    foreach (array_keys($roomState) as $soloRoomId) {
        if (count($roomState[$soloRoomId]['people']) !== 1) continue;
        $gender = $roomState[$soloRoomId]['gender'];
        foreach (array_keys($roomState) as $donorRoomId) {
            if ($donorRoomId === $soloRoomId || $roomState[$donorRoomId]['gender'] !== $gender) continue;
            if (count($roomState[$donorRoomId]['people']) < 3 || count($roomState[$soloRoomId]['people']) >= $roomState[$soloRoomId]['capacity']) continue;
            $movedAttendeeId = array_pop($roomState[$donorRoomId]['people']);
            $roomState[$soloRoomId]['people'][] = $movedAttendeeId;
            $assignments[$movedAttendeeId] = $soloRoomId;
            break;
        }
    }
    $solo = [];
    foreach ($roomState as $roomId => $state) if (count($state['people']) === 1) $solo[$state['people'][0]] = true;
    return ['assignments' => $assignments, 'solo' => $solo, 'unassigned' => array_values(array_map(static fn($a) => (int)$a['id'], array_filter($attendees, static fn($a) => seminar_normalize_gender($a['gender'] ?? 'unknown') === 'unknown' || !isset($assignments[(int)$a['id']]))))];
}

/** Finalization invariants are calculated from current assignments, never cached attendee flags. */
function seminar_check_final_assignments(array $attendees, array $roomCapacities, array $allowMixedRoomIds): array
{
    if (!$attendees) throw new InvalidArgumentException('Add attendees before finalizing this seminar.');
    $counts = []; $genders = [];
    foreach ($attendees as $attendee) {
        $roomId = (int)($attendee['assigned_venue_id'] ?? 0);
        if ($roomId < 1) throw new InvalidArgumentException('Assign every attendee to a hotel room before finalizing.');
        if (!array_key_exists($roomId, $roomCapacities)) throw new InvalidArgumentException('An attendee is assigned to a room not reserved by this seminar.');
        $counts[$roomId] = ($counts[$roomId] ?? 0) + 1;
        $gender = $attendee['gender'] ?? 'unknown';
        if (in_array($gender, ['male', 'female'], true)) $genders[$roomId][$gender] = true;
        if ($counts[$roomId] > (int)$roomCapacities[$roomId]) throw new InvalidArgumentException('A room assignment exceeds the room capacity.');
    }
    $allowed = array_fill_keys(array_map('intval', $allowMixedRoomIds), true);
    foreach ($genders as $roomId => $roomGenders) {
        if (count($roomGenders) > 1 && !isset($allowed[(int)$roomId])) throw new InvalidArgumentException('A mixed-gender room must be explicitly approved.');
    }
    return ['counts' => $counts, 'genders' => $genders];
}

function seminar_column_index(mixed $value, int $columnCount): ?int
{
    if (!is_scalar($value) || trim((string)$value) === '') return null;
    $value = trim((string)$value);
    if (ctype_digit($value)) $index = (int)$value;
    else {
        $index = 0;
        foreach (str_split(strtoupper($value)) as $char) {
            if ($char < 'A' || $char > 'Z') return null;
            $index = ($index * 26) + ord($char) - 64;
        }
        $index--;
    }
    return $index >= 0 && $index < $columnCount ? $index : null;
}

function seminar_csv_sheets(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false || strlen($contents) > 5 * 1024 * 1024 || !mb_check_encoding($contents, 'UTF-8')) throw new InvalidArgumentException('CSV must be UTF-8 and no larger than 5 MB.');
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $contents);
    rewind($handle);
    $rows = [];
    while (($row = fgetcsv($handle, 65536, ',', '"', '\\')) !== false) {
        if (count($row) > 30) throw new InvalidArgumentException('CSV may contain at most 30 columns.');
        $rows[] = array_map(static fn($v) => trim((string)$v), $row);
        if (count($rows) > 1001) throw new InvalidArgumentException('Import may contain at most 1,000 attendee rows.');
    }
    fclose($handle);
    if (!$rows) throw new InvalidArgumentException('The uploaded CSV is empty.');
    $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0] ?? '');
    return [['name' => 'CSV', 'rows' => $rows]];
}

function seminar_xlsx_xml(string $xml, callable $consume): void
{
    $reader = new XMLReader();
    if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING)) throw new InvalidArgumentException('The XLSX workbook contains invalid XML.');
    try { while ($reader->read()) $consume($reader); } finally { $reader->close(); }
}

function seminar_xlsx_column(string $reference): int
{
    preg_match('/\A([A-Z]+)/i', $reference, $match);
    $index = 0;
    foreach (str_split(strtoupper($match[1] ?? 'A')) as $char) $index = $index * 26 + ord($char) - 64;
    return max(0, $index - 1);
}

function seminar_xlsx_sheets(string $path): array
{
    if (!class_exists('ZipArchive') || !class_exists('XMLReader')) throw new RuntimeException('XLSX import requires the PHP ZipArchive and XMLReader extensions.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new InvalidArgumentException('Unable to open XLSX workbook.');
    try {
        if ($zip->numFiles > 40) throw new InvalidArgumentException('The XLSX workbook contains too many files.');
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $total += (int)($stat['size'] ?? 0);
            if ($total > 25 * 1024 * 1024) throw new InvalidArgumentException('The uncompressed XLSX workbook exceeds 25 MB.');
        }
        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            if (strlen($sharedXml) > 8 * 1024 * 1024) throw new InvalidArgumentException('XLSX shared strings exceed the import limit.');
            $active = false; $value = '';
            seminar_xlsx_xml($sharedXml, static function (XMLReader $reader) use (&$active, &$value, &$shared) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') { $active = true; $value = ''; }
                elseif ($active && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') $value .= $reader->readString();
                elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si') { $shared[] = $value; $active = false; }
            });
        }
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if ($relsXml === false || $workbookXml === false || strlen($workbookXml) > 1024 * 1024 || strlen($relsXml) > 1024 * 1024) throw new InvalidArgumentException('XLSX workbook metadata is missing or too large.');
        $targets = [];
        seminar_xlsx_xml($relsXml, static function (XMLReader $reader) use (&$targets) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'Relationship') {
                $id = $reader->getAttribute('Id'); $target = $reader->getAttribute('Target');
                $mode = $reader->getAttribute('TargetMode');
                if (is_string($id) && is_string($target) && $mode !== 'External' && !str_contains($target, '..')) {
                    $targets[$id] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . ltrim($target, '/');
                }
            }
        });
        $definitions = [];
        seminar_xlsx_xml($workbookXml, static function (XMLReader $reader) use (&$definitions, $targets) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'sheet') {
                $name = $reader->getAttribute('name'); $rid = $reader->getAttribute('r:id');
                if (is_string($name) && is_string($rid) && isset($targets[$rid])) $definitions[] = ['name' => $name, 'path' => $targets[$rid]];
            }
        });
        if (count($definitions) > 20) throw new InvalidArgumentException('XLSX may contain at most 20 worksheets.');
        $sheets = [];
        foreach ($definitions as $definition) {
            $sheetXml = $zip->getFromName($definition['path']);
            if ($sheetXml === false || strlen($sheetXml) > 15 * 1024 * 1024) throw new InvalidArgumentException('An XLSX worksheet is missing or too large.');
            $rows = []; $row = []; $cell = null; $cellType = ''; $cellValue = ''; $cellText = false;
            seminar_xlsx_xml($sheetXml, static function (XMLReader $reader) use (&$rows, &$row, &$cell, &$cellType, &$cellValue, &$cellText, $shared) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') $row = [];
                elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c') {
                    $cell = seminar_xlsx_column((string)$reader->getAttribute('r'));
                    if ($cell > 29) throw new InvalidArgumentException('XLSX may contain at most 30 columns.');
                    $cellType = (string)$reader->getAttribute('t'); $cellValue = ''; $cellText = false;
                } elseif ($cell !== null && $reader->nodeType === XMLReader::ELEMENT && ($reader->localName === 'v' || $reader->localName === 't')) {
                    if ($reader->localName === 't') $cellText = true;
                    $cellValue = $reader->readString();
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'c' && $cell !== null) {
                    if ($cellType === 's' && ctype_digit($cellValue)) $value = $shared[(int)$cellValue] ?? '';
                    else $value = $cellValue;
                    $row[$cell] = trim((string)$value); $cell = null;
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'row') {
                    if ($row) {
                        if (count($row) > 30) throw new InvalidArgumentException('XLSX may contain at most 30 columns.');
                        $max = max(array_keys($row)); $normalized = array_fill(0, $max + 1, '');
                        foreach ($row as $index => $value) $normalized[$index] = $value;
                        $rows[] = $normalized;
                        if (count($rows) > 1001) throw new InvalidArgumentException('Import may contain at most 1,000 attendee rows.');
                    }
                }
            });
            $sheets[] = ['name' => $definition['name'], 'rows' => $rows];
        }
        if (!$sheets) throw new InvalidArgumentException('The XLSX workbook has no readable worksheets.');
        return $sheets;
    } finally { $zip->close(); }
}

function seminar_parse_upload(string $path, string $extension): array
{
    if ($extension === 'csv') return seminar_csv_sheets($path);
    if ($extension === 'xlsx') return seminar_xlsx_sheets($path);
    throw new InvalidArgumentException('Choose a UTF-8 CSV or XLSX file.');
}

/** Map raw worksheet rows to validated attendee values and collect row-level errors. */
function seminar_validate_import_rows(array $rows, array $mapping): array
{
    $required = ['name', 'gender', 'location'];
    $columnCount = max(array_map('count', $rows) ?: [0]);
    $indexes = [];
    foreach (array_merge($required, ['contact']) as $field) {
        if ($field === 'contact' && (($mapping[$field] ?? '') === '')) { $indexes[$field] = null; continue; }
        $index = seminar_column_index($mapping[$field] ?? null, $columnCount);
        if ($index === null && in_array($field, $required, true)) throw new InvalidArgumentException('Map name, gender, and location to worksheet columns.');
        $indexes[$field] = $index;
    }
    if (count(array_unique(array_filter(array_values(array_intersect_key($indexes, array_flip($required))), static fn($v) => $v !== null))) !== count($required)) throw new InvalidArgumentException('Name, gender, and location must use different columns.');
    $mapped = array_values(array_filter($indexes, static fn($v) => $v !== null));
    if (count(array_unique($mapped)) !== count($mapped)) throw new InvalidArgumentException('Map each attendee field to a different worksheet column.');
    $attendees = []; $errors = [];
    foreach (array_slice($rows, 1, 1000, true) as $rowIndex => $row) {
        $name = trim((string)($row[$indexes['name']] ?? ''));
        $gender = trim((string)($row[$indexes['gender']] ?? ''));
        $location = trim((string)($row[$indexes['location']] ?? ''));
        $contact = $indexes['contact'] === null ? '' : trim((string)($row[$indexes['contact']] ?? ''));
        if ($name === '' && $gender === '' && $location === '' && $contact === '') continue;
        $issues = [];
        foreach (['Name' => $name, 'Gender' => $gender, 'Location' => $location] as $label => $value) if ($value === '') $issues[] = "$label is required";
        if (mb_strlen($name, 'UTF-8') > 180 || mb_strlen($location, 'UTF-8') > 180 || mb_strlen($contact, 'UTF-8') > 100) $issues[] = 'A value exceeds the allowed length';
        if ($issues) { $errors[] = ['row' => $rowIndex + 1, 'issues' => $issues]; continue; }
        $attendees[] = ['full_name' => $name, 'gender' => seminar_normalize_gender($gender), 'location' => $location, 'contact' => $contact];
    }
    return ['attendees' => $attendees, 'errors' => $errors];
}

function seminar_import_summary(array $attendees): array
{
    $genderCounts = ['female' => 0, 'male' => 0, 'unknown' => 0];
    $locationCounts = [];
    foreach ($attendees as $attendee) {
        $gender = in_array(($attendee['gender'] ?? ''), ['female','male'], true) ? $attendee['gender'] : 'unknown';
        $genderCounts[$gender]++;
        $location = trim((string)($attendee['location'] ?? '')) ?: 'Unknown location';
        $locationCounts[$location] = ($locationCounts[$location] ?? 0) + 1;
    }
    uksort($locationCounts, static fn($a, $b) => $locationCounts[$b] <=> $locationCounts[$a] ?: strcasecmp($a, $b));
    $locations = [];
    foreach ($locationCounts as $location => $count) $locations[] = ['name' => $location, 'count' => $count];
    return ['gender_counts' => $genderCounts, 'location_counts' => $locations];
}

function seminar_floor_display_label(mixed $value): string
{
    $label = trim((string)$value);
    if ($label === '') return '';
    if (preg_match('/\\bfloor\\b/i', $label)) return $label;
    return preg_match('/\\A\\d+(?:st|nd|rd|th)?\\z/iD', $label) ? 'Floor ' . $label : $label;
}

function seminar_pdf_text_sort_key(mixed $value): string
{
    $text = is_scalar($value) ? (string)$value : '';
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function seminar_pdf_location_sort_key(mixed $value): string
{
    $text = is_scalar($value) ? (string)$value : '';
    $collapsed = preg_replace('/[\\s\\p{Z}\\x{FEFF}]+/u', ' ', $text);
    if ($collapsed !== null) $collapsed = trim($collapsed);
    if ($collapsed === null) $collapsed = preg_replace('/\\s+/', ' ', trim($text)) ?? trim($text);
    return seminar_pdf_text_sort_key($collapsed);
}

function seminar_sort_room_sheet_attendees(array $attendees): array
{
    $sorted = array_values($attendees);
    usort($sorted, static function (array $left, array $right): int {
        $locationOrder = strcmp(seminar_pdf_location_sort_key($left['location'] ?? ''), seminar_pdf_location_sort_key($right['location'] ?? ''));
        if ($locationOrder !== 0) return $locationOrder;
        $leftName = is_scalar($left['full_name'] ?? null) ? (string)$left['full_name'] : '';
        $rightName = is_scalar($right['full_name'] ?? null) ? (string)$right['full_name'] : '';
        $nameOrder = strcmp(seminar_pdf_text_sort_key($leftName), seminar_pdf_text_sort_key($rightName));
        if ($nameOrder !== 0) return $nameOrder;
        $nameCaseOrder = strcmp($leftName, $rightName);
        if ($nameCaseOrder !== 0) return $nameCaseOrder;
        return (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0);
    });
    return $sorted;
}

function seminar_sort_alphabetical_attendees(array $attendees): array
{
    $sorted = array_values($attendees);
    usort($sorted, static function (array $left, array $right): int {
        $leftName = is_scalar($left['full_name'] ?? null) ? (string)$left['full_name'] : '';
        $rightName = is_scalar($right['full_name'] ?? null) ? (string)$right['full_name'] : '';
        $nameOrder = strcmp(seminar_pdf_text_sort_key($leftName), seminar_pdf_text_sort_key($rightName));
        if ($nameOrder !== 0) return $nameOrder;
        $nameCaseOrder = strcmp($leftName, $rightName);
        if ($nameCaseOrder !== 0) return $nameCaseOrder;
        return (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0);
    });
    return $sorted;
}

function seminar_render_pdf_html(array $seminar, array $rooms, array $attendees, string $type): string
{
    if (!in_array($type, ['rooms', 'list'], true)) throw new InvalidArgumentException('Invalid seminar PDF type.');
    $e = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $hallDate = $seminar['hall_start_date'] === $seminar['hall_end_date'] ? $seminar['hall_start_date'] : $seminar['hall_start_date'] . ' to ' . $seminar['hall_end_date'];
    $hotelDate = $seminar['hotel_check_in'] . ' to ' . $seminar['hotel_check_out'];
    $logoPath = realpath(__DIR__ . '/../assets/img/Logo.png');
    $logo = $logoPath !== false && is_file($logoPath) ? 'file://' . $logoPath : '';
    $roomLayouts = [];
    $roomCss = '';
    if ($type === 'rooms') {
        $occupiedRoomIds = [];
        foreach ($attendees as $person) if (!empty($person['assigned_venue_id'])) $occupiedRoomIds[(int)$person['assigned_venue_id']] = true;
        $rooms = array_values(array_filter($rooms, static fn($room) => isset($occupiedRoomIds[(int)$room['venue_id']])));
        foreach ($rooms as $roomIndex => $room) {
            $peopleCount = count(array_filter($attendees, static fn($person) => (int)$person['assigned_venue_id'] === (int)$room['venue_id']));
            $rowCount = max(12, $peopleCount);
            // Reserve roughly 180mm for the roster, leaving room for readable seminar and hotel details.
            // Tighter line height is applied only when a room has a larger roster.
            $rowBudget = min(10.5, max(3.0, 180 / $rowCount));
            $cellPadding = min(1.05, max(0.25, ($rowBudget - 3.0) / 2));
            $cellHeight = max(2.5, $rowBudget - (2 * $cellPadding));
            $fontSize = max(5.3, min(8.5, $rowBudget * 0.82));
            $headerFont = max(6.2, min(7.8, $fontSize));
            $roomLayouts[$roomIndex] = ['row_count' => $rowCount, 'cell_height' => $cellHeight];
            $selector = '.room-sheet-' . $roomIndex;
            $roomCss .= $selector . '{page-break-inside:avoid}'
                . $selector . ' .brand{margin-bottom:2.5mm;padding-bottom:1.8mm}'
                . $selector . ' .brand img{height:12mm}'
                . $selector . ' h1{font-size:16pt;margin-bottom:1.5mm}'
                . $selector . ' h2{font-size:12pt;margin-bottom:1mm}'
                . $selector . ' .sub{font-size:7.5pt;margin-bottom:2.5mm}'
                . $selector . ' .meta{margin-bottom:2.5mm}'
                . $selector . ' .meta td{padding:1.2mm}'
                . $selector . ' table.roster th,' . $selector . ' table.roster td{font-size:' . number_format($fontSize, 2, '.', '') . 'pt;line-height:1.05;padding:' . number_format($cellPadding, 2, '.', '') . 'mm 1.6mm;height:' . number_format($cellHeight, 2, '.', '') . 'mm}'
                . $selector . ' table.roster th{font-size:' . number_format($headerFont, 2, '.', '') . 'pt}'
                . $selector . ' table.roster td.signature{height:' . number_format($cellHeight, 2, '.', '') . 'mm}'
                . $selector . ' footer{margin-top:2mm}';
        }
    }
    $style = '<style>@page{size:A4 portrait;margin:10mm}body{font-family:DejaVu Sans,sans-serif;color:#27231f;font-size:9pt}.brand{align-items:center;border-bottom:1px solid #c8b89f;display:flex;margin-bottom:4mm;padding-bottom:3mm}.brand img{height:14mm;margin-right:4mm;width:auto}.brand span{color:#6b5433;font-size:8pt;letter-spacing:1pt}h1{font-size:18pt;margin:0 0 3mm;color:#513c24}h2{font-size:13pt;margin:0 0 2mm}.sub{color:#64594d;font-size:8pt;margin-bottom:4mm}.meta{width:100%;border-collapse:collapse;margin:0 0 4mm}.meta td{border:1px solid #b9ae9e;padding:2mm}.meta strong{color:#594932}table.roster{width:100%;border-collapse:collapse}table.roster th,table.roster td{border:1px solid #9c907f;padding:1.8mm;text-align:left;height:8mm}table.roster th{background:#f1eadf;font-size:8pt}.signature{height:10mm}footer{margin-top:4mm;text-align:right;color:#8b8175;font-size:7pt}.page-break{page-break-after:always}' . $roomCss . '</style>';
    $html = '<!doctype html><html><head><meta charset="utf-8">' . $style . '</head><body>';
    if ($type === 'rooms') {
        foreach ($rooms as $roomIndex => $room) {
            $people = seminar_sort_room_sheet_attendees(array_values(array_filter($attendees, static fn($a) => (int)$a['assigned_venue_id'] === (int)$room['venue_id'])));
            $roomName = !empty($room['room_number']) ? 'Room ' . $room['room_number'] : $room['name'];
            $floorLabel = seminar_floor_display_label($room['floor_label'] ?? '');
            $floorText = $floorLabel !== '' ? $e($floorLabel) . ' · ' : '';
            $html .= '<section class="room-sheet room-sheet-' . $roomIndex . '">' . ($logo ? '<div class="brand"><img src="' . $e($logo) . '" alt="Sevilla360"><span>SEVILLA360 · SEMINAR OPERATIONS</span></div>' : '') . '<h1>' . $e($room['name']) . ' · ' . $e($roomName) . '</h1><h2>' . $e($seminar['name']) . '</h2><div class="sub">ROOM REGISTRATION SHEET · ' . $floorText . 'Capacity ' . (int)$room['max_capacity'] . '</div>';
            $html .= '<table class="meta"><tr><td><strong>Event Hall</strong><br>' . $e($seminar['hall_name']) . '</td><td><strong>Hall dates</strong><br>' . $e($hallDate) . '</td></tr><tr><td><strong>Hotel check-in</strong><br>' . $e($seminar['hotel_check_in']) . '</td><td><strong>Hotel checkout</strong><br>' . $e($seminar['hotel_check_out']) . '</td></tr></table>';
            $html .= '<table class="roster"><thead><tr><th style="width:33%">Attendee name</th><th style="width:24%">Location</th><th style="width:19%">Contact No.</th><th style="width:24%">Signature</th></tr></thead><tbody>';
            for ($i = 0, $lineCount = $roomLayouts[$roomIndex]['row_count']; $i < $lineCount; $i++) {
                $person = $people[$i] ?? null;
                $html .= '<tr><td>' . $e($person['full_name'] ?? '') . '</td><td>' . $e($person['location'] ?? '') . '</td><td class="signature"></td><td class="signature"></td></tr>';
            }
            $html .= '</tbody></table><footer>Sevilla360 · ' . $e($seminar['name']) . ' · ' . ($roomIndex + 1) . ' / ' . count($rooms) . '</footer></section>';
            if ($roomIndex < count($rooms) - 1) $html .= '<div class="page-break"></div>';
        }
    } else {
        $roomById = [];
        foreach ($rooms as $room) {
            $floorLabel = seminar_floor_display_label($room['floor_label'] ?? '');
            $roomById[(int)$room['venue_id']] = $room['name'] . (!empty($room['room_number']) ? ' · Room ' . $room['room_number'] : '') . ($floorLabel !== '' ? ' · ' . $floorLabel : '');
        }
        $html .= ($logo ? '<div class="brand"><img src="' . $e($logo) . '" alt="Sevilla360"><span>SEVILLA360 · SEMINAR OPERATIONS</span></div>' : '');
        $html .= '<h1>Seminar Attendee Room List</h1><h2>' . $e($seminar['name']) . '</h2><div class="sub">' . $e($seminar['hall_name']) . ' · Hall ' . $e($hallDate) . ' · Hotel ' . $e($hotelDate) . '</div>';
        $html .= '<table class="roster"><thead><tr><th style="width:8%">No.</th><th style="width:35%">Attendee name</th><th style="width:25%">Location</th><th>Hotel room</th></tr></thead><tbody>';
        foreach (seminar_sort_alphabetical_attendees($attendees) as $index => $person) $html .= '<tr><td>' . ($index + 1) . '</td><td>' . $e($person['full_name']) . '</td><td>' . $e($person['location']) . '</td><td>' . $e($roomById[(int)$person['assigned_venue_id']] ?? 'Unassigned') . '</td></tr>';
        $html .= '</tbody></table>';
    }
    return $html . '</body></html>';
}

/** Return a single reserved room only when the finalized roster assigns at least one attendee to it. */
function seminar_occupied_pdf_room(array $rooms, array $attendees, int $roomId): ?array
{
    if ($roomId < 1) return null;
    $reservedRoom = null;
    foreach ($rooms as $room) if ((int)($room['venue_id'] ?? 0) === $roomId) { $reservedRoom = $room; break; }
    if ($reservedRoom === null) return null;
    foreach ($attendees as $attendee) if ((int)($attendee['assigned_venue_id'] ?? 0) === $roomId) return $reservedRoom;
    return null;
}

function seminar_write_audit(mysqli $conn, int $userId, string $action): void
{
    $module = 'Seminars';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare('INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, ?, ?, ?)');
    if ($stmt) { $stmt->bind_param('isss', $userId, $module, $action, $ip); $stmt->execute(); $stmt->close(); }
}
