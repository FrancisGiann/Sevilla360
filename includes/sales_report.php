<?php
/** Shared sales report rules used by the admin report and dashboard metric. */

function sales_report_timezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Manila');
}

function sales_report_default_filters(?DateTimeImmutable $now = null): array
{
    $today = ($now ?? new DateTimeImmutable('now', sales_report_timezone()))
        ->setTimezone(sales_report_timezone())
        ->format('Y-m-d');
    return [
        'from' => substr($today, 0, 7) . '-01',
        'to' => $today,
        'method' => '',
        'venue_id' => 0,
    ];
}

function sales_report_parse_date($value): ?string
{
    if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value)) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, sales_report_timezone());
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) return null;
    return $value;
}

/** Invalid or reversed date input safely falls back to the Manila month-to-date range. */
function sales_report_normalize_filters(array $input, ?DateTimeImmutable $now = null): array
{
    $defaults = sales_report_default_filters($now);
    $hasDateInput = array_key_exists('from', $input) || array_key_exists('to', $input);
    $from = $hasDateInput ? sales_report_parse_date($input['from'] ?? null) : $defaults['from'];
    $to = $hasDateInput ? sales_report_parse_date($input['to'] ?? null) : $defaults['to'];
    $error = '';
    if ($from === null || $to === null || $from > $to) {
        $from = $defaults['from'];
        $to = $defaults['to'];
        $error = 'Enter a valid date range. Showing this month to date.';
    }

    $methodInput = $input['method'] ?? '';
    $method = is_string($methodInput) ? trim($methodInput) : '';
    if (strlen($method) > 120 || preg_match('/[\x00-\x1F\x7F]/', $method) || strcasecmp($method, 'unknown') === 0) $method = '';
    // `room_id` remains accepted for older report links. It now resolves to a
    // venue group, so individual hotel room numbers are never a report filter.
    $venueInput = $input['venue_id'] ?? ($input['room_id'] ?? 0);
    $venueFilter = filter_var($venueInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $venueId = $venueFilter === false ? 0 : (int)$venueFilter;

    return ['from' => $from, 'to' => $to, 'method' => $method, 'venue_id' => $venueId, 'error' => $error];
}

function sales_report_money_to_cents($amount): int
{
    if (!is_numeric($amount) || !is_finite((float)$amount)) return 0;
    return (int)round((float)$amount * 100, 0, PHP_ROUND_HALF_UP);
}

/** Exact floor and remainder for a*b/divisor without overflowing a PHP integer. */
function sales_report_mul_divmod(int $a, int $b, int $divisor): array
{
    if ($a < 0 || $b < 0 || $divisor <= 0) return [0, 0];
    $bits = [];
    do {
        $bits[] = $a % 2;
        $a = intdiv($a, 2);
    } while ($a > 0);

    $quotient = 0;
    $remainder = 0;
    foreach (array_reverse($bits) as $bit) {
        $quotient *= 2;
        $doubledRemainder = $remainder * 2;
        if ($doubledRemainder >= $divisor) {
            $quotient++;
            $doubledRemainder -= $divisor;
        }
        $remainder = $doubledRemainder;
        if ($bit === 1) {
            $remainder += $b;
            if ($remainder >= $divisor) {
                $quotient++;
                $remainder -= $divisor;
            }
        }
    }
    return [$quotient, $remainder];
}

/**
 * Divide integer cents among weighted venue shares. Largest remainders ensure
 * every payment/refund reconciles exactly, including very small transactions.
 * Each target needs a unique `key` and a positive integer `weight`.
 */
function sales_report_allocate_cents(int $amountCents, array $targets): array
{
    $amountCents = max(0, $amountCents);
    $targets = array_values(array_filter($targets, static fn($target): bool =>
        is_array($target) && isset($target['key']) && isset($target['weight']) && (int)$target['weight'] > 0
    ));
    $weightTotal = array_sum(array_map(static fn($target): int => (int)$target['weight'], $targets));
    if ($amountCents === 0 || $weightTotal <= 0 || !$targets) return [];

    $allocations = [];
    $remainders = [];
    $allocated = 0;
    foreach ($targets as $target) {
        $key = (string)$target['key'];
        [$share, $remainder] = sales_report_mul_divmod($amountCents, (int)$target['weight'], $weightTotal);
        $allocations[$key] = $share;
        $allocated += $share;
        $remainders[] = ['key' => $key, 'remainder' => $remainder];
    }
    usort($remainders, static function (array $left, array $right): int {
        $byRemainder = $right['remainder'] <=> $left['remainder'];
        return $byRemainder !== 0 ? $byRemainder : strcmp($left['key'], $right['key']);
    });
    $remaining = $amountCents - $allocated;
    for ($i = 0; $i < $remaining; $i++) {
        $key = $remainders[$i % count($remainders)]['key'];
        $allocations[$key]++;
    }
    return $allocations;
}

function sales_report_activity_exclusions(string $bookingAlias = 'b', string $customerAlias = 'c'): string
{
    // Aliases are internal constants at call sites and never user input.
    return "COALESCE({$bookingAlias}.source, '') <> 'Maintenance' AND {$bookingAlias}.reference_no NOT LIKE 'MAINT-%' AND COALESCE({$customerAlias}.last_name, '') <> 'MAINTENANCE'";
}

function sales_report_usable_method_sql(string $methodExpression): string
{
    // Method expressions are static query-template values, never user input.
    return "NULLIF(TRIM(COALESCE({$methodExpression}, '')), '') IS NOT NULL AND LOWER(TRIM(COALESCE({$methodExpression}, ''))) <> 'unknown'";
}

function sales_report_venue_label_sql(string $venueAlias): string
{
    // The venue name is the building for hotel inventory and the venue name
    // itself for halls and villas. Aliases are static query-template values.
    return "CASE {$venueAlias}.category
        WHEN 'Hotel Room' THEN CONCAT('Hotel · ', {$venueAlias}.name)
        WHEN 'Event Hall' THEN CONCAT('Event Hall · ', {$venueAlias}.name)
        WHEN 'Resort Villa' THEN CONCAT('Resort Villa · ', {$venueAlias}.name)
        ELSE {$venueAlias}.name END";
}

function sales_report_hotel_building_group_sql(): string
{
    return "(SELECT MIN(building_venue.id) AS group_id, building_venue.name
        FROM venues building_venue
        INNER JOIN hotel_rooms building_room ON building_room.venue_id = building_venue.id
        WHERE building_venue.category = 'Hotel Room'
        GROUP BY building_venue.name)";
}

function sales_report_is_active_admin(mysqli $conn, int $userId): bool
{
    if ($userId < 1) return false;
    $statement = $conn->prepare('SELECT u.role, s.status AS staff_status FROM users u LEFT JOIN staff s ON s.user_id = u.id WHERE u.id = ? LIMIT 1');
    if (!$statement) throw new RuntimeException('Unable to verify admin access.');
    $statement->bind_param('i', $userId);
    if (!$statement->execute()) {
        $statement->close();
        throw new RuntimeException('Unable to verify admin access.');
    }
    $account = $statement->get_result()->fetch_assoc();
    $statement->close();
    return $account !== null
        && ($account['role'] ?? '') === 'admin'
        && strcasecmp((string)($account['staff_status'] ?? ''), 'active') === 0;
}

function sales_report_fetch_activities(mysqli $conn, array $filters, int $offset = 0, int $limit = 1000): array
{
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', $filters['from'], sales_report_timezone());
    $to = DateTimeImmutable::createFromFormat('!Y-m-d', $filters['to'], sales_report_timezone())->modify('+1 day');
    if (!$from || !$to) throw new InvalidArgumentException('Invalid sales report date range.');
    $fromBoundary = $from->format('Y-m-d 00:00:00');
    $toBoundary = $to->format('Y-m-d 00:00:00');
    $excluded = sales_report_activity_exclusions();
    $receivedMethodUsable = sales_report_usable_method_sql('p.payment_method');
    $refundMethodUsable = sales_report_usable_method_sql('cx.refund_destination_method');
    $primaryVenueLabel = sales_report_venue_label_sql('v');
    $hotelBuildingGroup = sales_report_hotel_building_group_sql();
    $sql = "
        SELECT activity_type, activity_id, occurred_at, amount, method_label, reference_code,
               booking_id, booking_reference, booking_total, primary_filter_id, primary_venue,
               primary_category, customer_name
        FROM (
            SELECT 'received' AS activity_type, p.id AS activity_id, p.payment_date AS occurred_at,
                   p.amount, TRIM(COALESCE(p.payment_method, '')) AS method_label,
                   CASE
                       WHEN UPPER(LEFT(TRIM(COALESCE(p.transaction_id, '')), 7)) = 'MANUAL-'
                           THEN COALESCE(NULLIF(TRIM(mps.transaction_reference), ''), '')
                       ELSE COALESCE(NULLIF(TRIM(mps.transaction_reference), ''), NULLIF(TRIM(p.transaction_id), ''), '')
                   END AS reference_code,
                   b.id AS booking_id, b.reference_no AS booking_reference, b.total_amount AS booking_total,
                   COALESCE(hotel_building.group_id, v.id) AS primary_filter_id,
                   {$primaryVenueLabel} AS primary_venue, v.category AS primary_category,
                   TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) AS customer_name
            FROM payments p
            INNER JOIN bookings b ON b.id = p.booking_id
            INNER JOIN customers c ON c.id = b.customer_id
            INNER JOIN venues v ON v.id = b.venue_id
            LEFT JOIN {$hotelBuildingGroup} AS hotel_building ON hotel_building.name = v.name AND v.category = 'Hotel Room'
            LEFT JOIN manual_payment_submissions mps ON mps.payment_id = p.id
            WHERE p.status = 'Success' AND p.amount > 0
              AND p.payment_date >= ? AND p.payment_date < ? AND {$excluded}
              AND {$receivedMethodUsable}

            UNION ALL

            SELECT 'refunded' AS activity_type, h.id AS activity_id, h.created_at AS occurred_at,
                   h.refund_amount AS amount,
                   TRIM(COALESCE(cx.refund_destination_method, '')) AS method_label,
                   COALESCE(NULLIF(TRIM(cx.refund_transaction_id), ''), '') AS reference_code,
                   b.id AS booking_id, b.reference_no AS booking_reference, b.total_amount AS booking_total,
                   COALESCE(hotel_building.group_id, v.id) AS primary_filter_id,
                   {$primaryVenueLabel} AS primary_venue, v.category AS primary_category,
                   TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) AS customer_name
            FROM cancellation_history h
            INNER JOIN bookings b ON b.id = h.booking_id
            INNER JOIN customers c ON c.id = b.customer_id
            INNER JOIN venues v ON v.id = b.venue_id
            LEFT JOIN {$hotelBuildingGroup} AS hotel_building ON hotel_building.name = v.name AND v.category = 'Hotel Room'
            LEFT JOIN cancellations cx ON cx.id = h.cancellation_id AND cx.booking_id = h.booking_id
            WHERE h.action = 'processed' AND h.refund_amount > 0
              AND h.created_at >= ? AND h.created_at < ? AND {$excluded}
              AND {$refundMethodUsable}
        ) AS activity
        ORDER BY occurred_at DESC, activity_type ASC, activity_id DESC
        LIMIT ? OFFSET ?
    ";
    $statement = $conn->prepare($sql);
    if (!$statement) throw new RuntimeException('Unable to prepare sales report query.');
    $offset = max(0, $offset);
    $limit = max(1, min(5000, $limit));
    $statement->bind_param('ssssii', $fromBoundary, $toBoundary, $fromBoundary, $toBoundary, $limit, $offset);
    if (!$statement->execute()) {
        $statement->close();
        throw new RuntimeException('Unable to load sales report data.');
    }
    $result = $statement->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $statement->close();
    return $rows;
}

/** Load current assigned room shares; reports label this attribution as an estimate. */
function sales_report_fetch_room_lines(mysqli $conn, array $bookingIds): array
{
    $bookingIds = array_values(array_unique(array_filter(array_map('intval', $bookingIds), static fn(int $id): bool => $id > 0)));
    $roomLines = [];
    foreach (array_chunk($bookingIds, 400) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $types = str_repeat('i', count($chunk));
        $roomLabel = sales_report_venue_label_sql('v');
        $hotelBuildingGroup = sales_report_hotel_building_group_sql();
        $sql = "SELECT br.booking_id, br.venue_id, br.line_total,
                       COALESCE(hotel_building.group_id, v.id) AS filter_id,
                       {$roomLabel} AS venue_name, v.category AS venue_category
                FROM booking_rooms br INNER JOIN venues v ON v.id = br.venue_id
                LEFT JOIN {$hotelBuildingGroup} AS hotel_building ON hotel_building.name = v.name AND v.category = 'Hotel Room'
                WHERE br.booking_id IN ({$placeholders}) ORDER BY br.booking_id, br.venue_id, br.id";
        $statement = $conn->prepare($sql);
        if (!$statement) throw new RuntimeException('Unable to prepare room allocation query.');
        $binding = [$types];
        foreach ($chunk as $index => $_value) $binding[] = &$chunk[$index];
        call_user_func_array([$statement, 'bind_param'], $binding);
        if (!$statement->execute()) {
            $statement->close();
            throw new RuntimeException('Unable to load room allocation data.');
        }
        $result = $statement->get_result();
        while ($result && ($row = $result->fetch_assoc())) $roomLines[(int)$row['booking_id']][] = $row;
        $statement->close();
    }
    return $roomLines;
}

function sales_report_allocation_targets(array $activity, array $roomLines): array
{
    $bookingId = (int)$activity['booking_id'];
    $primaryId = (int)$activity['primary_filter_id'];
    $totalCents = max(0, sales_report_money_to_cents($activity['booking_total'] ?? 0));
    $primaryCategory = (string)$activity['primary_category'];
    $primaryKey = $primaryCategory === 'Hotel Room' ? 'building:' . $primaryId : 'venue:' . $primaryId;

    if ($primaryCategory !== 'Event Hall') {
        return [[
            'key' => $primaryKey,
            'label' => (string)$activity['primary_venue'],
            'filter_id' => $primaryId,
            'weight' => max(1, $totalCents),
        ]];
    }

    $targets = [];
    $addonTotal = 0;
    foreach ($roomLines[$bookingId] ?? [] as $line) {
        $weight = max(0, sales_report_money_to_cents($line['line_total'] ?? 0));
        if ($weight <= 0) continue;
        $addonTotal += $weight;
        $category = (string)$line['venue_category'];
        $filterId = (int)$line['filter_id'];
        $isHotel = $category === 'Hotel Room';
        $key = $isHotel ? 'building:' . $filterId : 'venue:' . (int)$line['venue_id'];
        if (!isset($targets[$key])) {
            $targets[$key] = [
                'key' => $key,
                'label' => (string)$line['venue_name'],
                'filter_id' => $filterId,
                'weight' => 0,
            ];
        }
        $targets[$key]['weight'] += $weight;
    }

    $primaryRemainder = max(0, $totalCents - $addonTotal);
    if ($primaryRemainder > 0) {
        $targets[$primaryKey] ??= [
            'key' => $primaryKey,
            'label' => (string)$activity['primary_venue'],
            'filter_id' => $primaryId,
            'weight' => 0,
        ];
        $targets[$primaryKey]['weight'] += $primaryRemainder;
    }
    if (!$targets && $primaryId > 0) {
        $targets[$primaryKey] = [
            'key' => $primaryKey,
            'label' => (string)$activity['primary_venue'],
            'filter_id' => $primaryId,
            'weight' => max(1, $totalCents),
        ];
    }
    ksort($targets, SORT_STRING);
    return array_values($targets);
}

function sales_report_build(mysqli $conn, array $filters, int $page = 1, int $pageSize = 50, ?callable $onTransaction = null): array
{
    $page = max(1, $page);
    $pageSize = max(1, min(250, $pageSize));
    $venueTotalsByKey = [];
    $availableMethods = [];
    $received = 0;
    $refunded = 0;
    $methodTotals = [];
    $transactionRows = [];
    $totalRows = 0;

    $batchSize = 1000;
    for ($offset = 0; ; $offset += $batchSize) {
        $activities = sales_report_fetch_activities($conn, $filters, $offset, $batchSize);
        if (!$activities) break;
        $roomLines = sales_report_fetch_room_lines($conn, array_column($activities, 'booking_id'));
        foreach ($activities as $activity) {
            $method = trim((string)($activity['method_label'] ?? ''));
            if ($method === '' || strcasecmp($method, 'unknown') === 0) continue;
            $availableMethods[$method] = $method;
            if ($filters['method'] !== '' && strcasecmp($method, $filters['method']) !== 0) continue;
            $amountCents = max(0, sales_report_money_to_cents($activity['amount'] ?? 0));
            if ($amountCents <= 0) continue;
            $targets = sales_report_allocation_targets($activity, $roomLines);
            $shares = sales_report_allocate_cents($amountCents, $targets);
            $matchingShare = 0;
            $matchingLabel = '';
            foreach ($targets as $target) {
                $share = (int)($shares[$target['key']] ?? 0);
                if ($share <= 0) continue;
                $filterId = (int)$target['filter_id'];
                if ($filters['venue_id'] > 0 && $filterId !== $filters['venue_id']) continue;
                $matchingShare += $share;
                $matchingLabel = $target['label'];
                if (!isset($methodTotals[$method])) $methodTotals[$method] = ['method' => $method, 'received_cents' => 0, 'refunded_cents' => 0];
                if ($activity['activity_type'] === 'received') $methodTotals[$method]['received_cents'] += $share;
                else $methodTotals[$method]['refunded_cents'] += $share;

                $key = (string)$target['key'];
                if (!isset($venueTotalsByKey[$key])) $venueTotalsByKey[$key] = ['label' => $target['label'], 'venue_id' => $filterId, 'received_cents' => 0, 'refunded_cents' => 0];
                if ($activity['activity_type'] === 'received') $venueTotalsByKey[$key]['received_cents'] += $share;
                else $venueTotalsByKey[$key]['refunded_cents'] += $share;
            }
            if ($filters['venue_id'] > 0 && $matchingShare <= 0) continue;

            if ($activity['activity_type'] === 'received') $received += $matchingShare;
            else $refunded += $matchingShare;
            $totalRows++;
            if ($onTransaction !== null || ($totalRows > ($page - 1) * $pageSize && $totalRows <= $page * $pageSize)) {
                $transaction = [
                    'type' => (string)$activity['activity_type'],
                    'date' => (string)$activity['occurred_at'],
                    'method' => $method,
                    'reference' => (string)($activity['reference_code'] ?? ''),
                    'booking_reference' => (string)($activity['booking_reference'] ?? ''),
                    'customer_name' => trim((string)($activity['customer_name'] ?? '')) ?: 'Guest',
                    'venue' => $filters['venue_id'] > 0 ? $matchingLabel : (string)$activity['primary_venue'],
                    'amount_cents' => $matchingShare,
                ];
                if ($onTransaction !== null) $onTransaction($transaction);
                else $transactionRows[] = $transaction;
            }
        }
        if (count($activities) < $batchSize) break;
    }
    ksort($availableMethods, SORT_NATURAL | SORT_FLAG_CASE);
    $availableMethods = array_values($availableMethods);

    foreach ($methodTotals as &$methodTotal) $methodTotal['net_cents'] = $methodTotal['received_cents'] - $methodTotal['refunded_cents'];
    unset($methodTotal);
    uasort($methodTotals, static fn(array $a, array $b): int => strcmp($a['method'], $b['method']));

    $venueTotals = [];
    foreach ($venueTotalsByKey as $value) {
        $value['net_cents'] = $value['received_cents'] - $value['refunded_cents'];
        $venueTotals[] = $value;
    }
    usort($venueTotals, static function (array $a, array $b): int {
        return strcasecmp($a['label'], $b['label']);
    });

    return [
        'received_cents' => $received,
        'refunded_cents' => $refunded,
        'net_cents' => $received - $refunded,
        'method_totals' => array_values($methodTotals),
        'venue_totals' => $venueTotals,
        'available_methods' => $availableMethods,
        'transactions' => $transactionRows,
        'total_rows' => $totalRows,
        'page' => $page,
        'page_size' => $pageSize,
        'page_count' => max(1, (int)ceil($totalRows / max(1, $pageSize))),
    ];
}

function sales_report_current_month_received_total(mysqli $conn, ?DateTimeImmutable $now = null): int
{
    $today = ($now ?? new DateTimeImmutable('now', sales_report_timezone()))->setTimezone(sales_report_timezone());
    $from = $today->modify('first day of this month')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    $until = $today->modify('+1 day')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    $excluded = sales_report_activity_exclusions();
    $methodUsable = sales_report_usable_method_sql('p.payment_method');
    $statement = $conn->prepare("SELECT COALESCE(SUM(p.amount), 0) AS total
        FROM payments p INNER JOIN bookings b ON b.id = p.booking_id
        INNER JOIN customers c ON c.id = b.customer_id
        WHERE p.status = 'Success' AND p.amount > 0 AND p.payment_date >= ? AND p.payment_date < ? AND {$excluded}
          AND {$methodUsable}");
    if (!$statement) throw new RuntimeException('Unable to prepare monthly sales query.');
    $statement->bind_param('ss', $from, $until);
    if (!$statement->execute()) {
        $statement->close();
        throw new RuntimeException('Unable to load monthly sales data.');
    }
    $total = $statement->get_result()->fetch_assoc()['total'] ?? 0;
    $statement->close();
    return sales_report_money_to_cents($total);
}

function sales_report_venue_options(mysqli $conn): array
{
    $result = $conn->query("SELECT MIN(v.id) AS id, v.name, 'Hotel Room' AS category
        FROM venues v INNER JOIN hotel_rooms hr ON hr.venue_id = v.id
        WHERE v.category = 'Hotel Room'
        GROUP BY v.name
        UNION ALL
        SELECT v.id, v.name, v.category
        FROM venues v
        WHERE v.category IN ('Event Hall', 'Resort Villa')
        ORDER BY category, name, id");
    if (!$result) throw new RuntimeException('Unable to load venue filters.');
    $venues = [];
    while ($row = $result->fetch_assoc()) {
        $label = sales_report_venue_label((string)$row['category'], (string)$row['name']);
        $venues[] = ['id' => (int)$row['id'], 'label' => $label, 'category' => (string)$row['category']];
    }
    return $venues;
}

function sales_report_venue_label(string $category, string $name): string
{
    $prefix = match ($category) {
        'Hotel Room' => 'Hotel',
        'Event Hall' => 'Event Hall',
        'Resort Villa' => 'Resort Villa',
        default => '',
    };
    $name = trim($name);
    if ($name === '') $name = 'Unnamed venue';
    return $prefix === '' ? $name : $prefix . ' · ' . $name;
}

/** Resolve a legacy physical hotel-room ID to its building group ID. */
function sales_report_resolve_venue_filter(mysqli $conn, int $requestedId, array $options): ?int
{
    if ($requestedId < 1) return 0;
    $availableIds = array_map('intval', array_column($options, 'id'));
    if (in_array($requestedId, $availableIds, true)) return $requestedId;

    $hotelBuildingGroup = sales_report_hotel_building_group_sql();
    $statement = $conn->prepare("SELECT CASE WHEN v.category = 'Hotel Room'
            THEN COALESCE(hotel_building.group_id, v.id) ELSE v.id END AS resolved_id
        FROM venues v
        LEFT JOIN {$hotelBuildingGroup} AS hotel_building ON hotel_building.name = v.name AND v.category = 'Hotel Room'
        WHERE v.id = ? LIMIT 1");
    if (!$statement) throw new RuntimeException('Unable to validate venue filter.');
    $statement->bind_param('i', $requestedId);
    if (!$statement->execute()) {
        $statement->close();
        throw new RuntimeException('Unable to validate venue filter.');
    }
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    $resolvedId = isset($row['resolved_id']) ? (int)$row['resolved_id'] : 0;
    return in_array($resolvedId, $availableIds, true) ? $resolvedId : null;
}

function sales_report_csv_cell($value): string
{
    $text = (string)$value;
    // Spreadsheet formula parsing can be triggered after Unicode control or
    // separator characters as well as ordinary spaces and line breaks.
    $trimmed = preg_replace('/\A[\s\p{Cc}\p{Cf}\p{Z}]+/u', '', $text);
    if ($trimmed === null) {
        $offset = 0;
        $length = strlen($text);
        while ($offset < $length) {
            $byte = ord($text[$offset]);
            if ($byte > 32 && $byte !== 127) break;
            $offset++;
        }
        $trimmed = substr($text, $offset);
    }
    if ($trimmed !== null && $trimmed !== '' && strpos('=+-@', $trimmed[0]) !== false) $text = "'" . $text;
    return $text;
}

function sales_report_csv_line($stream, array $values, array $numericIndexes = []): void
{
    $safeValues = [];
    foreach (array_values($values) as $index => $value) {
        if (in_array($index, $numericIndexes, true) && is_numeric($value) && is_finite((float)$value)) {
            $safeValues[] = (string)$value;
        } else {
            $safeValues[] = sales_report_csv_cell($value);
        }
    }
    fputcsv($stream, $safeValues);
}
?>
