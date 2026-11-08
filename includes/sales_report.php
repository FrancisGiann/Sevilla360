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

/** Detect optional PayMongo accounting columns/tables without silently dropping provider data. */
function sales_report_data_capabilities(mysqli $conn): array
{
    $result = $conn->query("SELECT TABLE_NAME, COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND (
            (TABLE_NAME = 'payments' AND COLUMN_NAME IN ('fee_cents','net_amount_cents','fee_verified_at')) OR
            (TABLE_NAME = 'cancellations' AND COLUMN_NAME IN ('refund_provider','refund_snapshot_id')) OR
            (TABLE_NAME = 'pm_refund_snapshots' AND COLUMN_NAME IN ('id','booking_id')) OR
            (TABLE_NAME = 'pm_refund_allocations' AND COLUMN_NAME IN ('id','snapshot_id','payment_id','refund_cents')) OR
            (TABLE_NAME = 'pm_refund_attempts' AND COLUMN_NAME IN ('id','allocation_id','correlation_note','provider_refund_id','status','provider_updated_at','reconciled_at','updated_at'))
        )");
    if (!$result) throw new RuntimeException('Unable to inspect sales report accounting data.');
    $found = [];
    while ($row = $result->fetch_assoc()) $found[strtolower($row['TABLE_NAME'])][strtolower($row['COLUMN_NAME'])] = true;
    $has = static fn(string $table, array $columns): bool => count(array_filter($columns, static fn(string $column): bool => !empty($found[$table][$column]))) === count($columns);
    return [
        'payment_fee_columns' => $has('payments', ['fee_cents', 'fee_verified_at']),
        'payment_net_column' => $has('payments', ['net_amount_cents']),
        'paymongo_refund_ledger' => $has('pm_refund_snapshots', ['id', 'booking_id'])
            && $has('pm_refund_allocations', ['id', 'snapshot_id', 'payment_id', 'refund_cents'])
            && $has('pm_refund_attempts', ['id', 'allocation_id', 'correlation_note', 'provider_refund_id', 'status', 'provider_updated_at', 'reconciled_at', 'updated_at']),
        'paymongo_refund_history' => $has('cancellations', ['refund_provider', 'refund_snapshot_id']),
    ];
}

/** Log only support-safe diagnostics; never include SQL text, bind values, or raw exception messages. */
function sales_report_log_failure(Throwable $error, string $operation): string
{
    try {
        $reference = bin2hex(random_bytes(8));
    } catch (Throwable $randomError) {
        $reference = substr(hash('sha256', uniqid('', true)), 0, 16);
    }
    $operation = in_array($operation, ['page', 'export', 'access'], true) ? $operation : 'report';
    $exceptionClass = get_class($error);
    if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_\\\\]{0,95}\z/D', $exceptionClass)) $exceptionClass = 'Throwable';
    $code = is_int($error->getCode()) || ctype_digit((string)$error->getCode()) ? (int)$error->getCode() : 0;
    $sqlState = $error instanceof mysqli_sql_exception ? (string)$error->getSqlState() : '';
    if (!preg_match('/\A[A-Z0-9]{5}\z/D', $sqlState)) $sqlState = 'unknown';
    $safeMessage = 'Sensitive exception details omitted.';
    if ($error instanceof mysqli_sql_exception) {
        $rawMessage = trim($error->getMessage());
        if (preg_match('/\AIllegal mix of collations \([A-Za-z0-9_]+,[A-Z]+\) and \([A-Za-z0-9_]+,[A-Z]+\) for operation [=<>]+\z/D', $rawMessage)) {
            $safeMessage = $rawMessage;
        }
    }
    error_log(sprintf(
        'sales_report_failure support_ref=%s operation=%s exception_class=%s error_code=%d sqlstate=%s message="%s"',
        $reference,
        $operation,
        $exceptionClass,
        $code,
        $sqlState,
        $safeMessage
    ));
    return $reference;
}

/** Detect processed PayMongo cancellation history that cannot be reconciled to successful provider refunds. */
function sales_report_has_unmatched_paymongo_refund_history(mysqli $conn, array $filters, array $capabilities): bool
{
    if (empty($capabilities['paymongo_refund_history'])) return false;
    $method = trim((string)($filters['method'] ?? ''));
    if ($method !== '' && !in_array(strtolower($method), ['gcash', 'maya', 'card', 'paymongo test'], true)) return false;
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', $filters['from'], sales_report_timezone());
    $to = DateTimeImmutable::createFromFormat('!Y-m-d', $filters['to'], sales_report_timezone())->modify('+1 day');
    if (!$from || !$to) throw new InvalidArgumentException('Invalid sales report date range.');
    $fromBoundary = $from->format('Y-m-d 00:00:00');
    $toBoundary = $to->format('Y-m-d 00:00:00');
    $ledgerCheck = !empty($capabilities['paymongo_refund_ledger'])
        ? "NOT EXISTS (
                SELECT 1 FROM pm_refund_allocations check_al
                LEFT JOIN pm_refund_attempts check_at ON check_at.allocation_id = check_al.id
                    AND check_at.status = 'succeeded' AND NULLIF(TRIM(check_at.provider_refund_id), '') IS NOT NULL
                WHERE BINARY check_al.snapshot_id = BINARY check_rs.id AND check_at.id IS NULL
            ) AND EXISTS (SELECT 1 FROM pm_refund_allocations any_al WHERE BINARY any_al.snapshot_id = BINARY check_rs.id)"
        : '0 = 1';
    $snapshotJoin = !empty($capabilities['paymongo_refund_ledger'])
        ? 'LEFT JOIN pm_refund_snapshots check_rs ON BINARY check_rs.id = BINARY cx.refund_snapshot_id'
        : 'LEFT JOIN (SELECT CAST(NULL AS CHAR(32)) AS id) check_rs ON 1 = 0';
    $methodScope = '';
    if ($method !== '' && !empty($capabilities['paymongo_refund_ledger'])) {
        $methodScope = "AND (check_rs.id IS NULL OR EXISTS (
            SELECT 1 FROM pm_refund_allocations method_al
            INNER JOIN payments method_p ON BINARY method_p.transaction_id = BINARY method_al.payment_id
            WHERE method_al.snapshot_id = check_rs.id AND LOWER(TRIM(method_p.payment_method)) = LOWER(?)
        ))";
    }
    $hotelBuildingGroup = sales_report_hotel_building_group_sql();
    $venueScope = '';
    if ((int)($filters['venue_id'] ?? 0) > 0) $venueScope = 'AND COALESCE(hotel_building.group_id, v.id) = ?';
    $sql = "SELECT 1
        FROM cancellation_history h
        INNER JOIN cancellations cx ON cx.id = h.cancellation_id
        INNER JOIN bookings b ON b.id = h.booking_id
        INNER JOIN venues v ON v.id = b.venue_id
        LEFT JOIN {$hotelBuildingGroup} AS hotel_building ON hotel_building.name = v.name AND v.category = 'Hotel Room'
        {$snapshotJoin}
        WHERE h.action = 'processed' AND h.refund_amount > 0
          AND h.created_at >= ? AND h.created_at < ?
          AND cx.refund_provider = 'paymongo'
          AND (check_rs.id IS NULL OR NOT ({$ledgerCheck}))
          {$methodScope}
          {$venueScope}
        LIMIT 1";
    $statement = $conn->prepare($sql);
    if (!$statement) throw new RuntimeException('Unable to check PayMongo refund completeness.');
    $params = [$fromBoundary, $toBoundary];
    $types = 'ss';
    if ($methodScope !== '') { $params[] = $method; $types .= 's'; }
    if ($venueScope !== '') { $params[] = (int)$filters['venue_id']; $types .= 'i'; }
    $bindings = [$types];
    foreach ($params as $index => $_value) $bindings[] = &$params[$index];
    call_user_func_array([$statement, 'bind_param'], $bindings);
    if (!$statement->execute()) {
        $statement->close();
        throw new RuntimeException('Unable to check PayMongo refund completeness.');
    }
    $unmatched = $statement->get_result()->fetch_row() !== null;
    $statement->close();
    return $unmatched;
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

function sales_report_fetch_activities(mysqli $conn, array $filters, int $offset = 0, int $limit = 1000, ?array $capabilities = null): array
{
    $capabilities ??= sales_report_data_capabilities($conn);
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', $filters['from'], sales_report_timezone());
    $to = DateTimeImmutable::createFromFormat('!Y-m-d', $filters['to'], sales_report_timezone())->modify('+1 day');
    if (!$from || !$to) throw new InvalidArgumentException('Invalid sales report date range.');
    $fromBoundary = $from->format('Y-m-d 00:00:00');
    $toBoundary = $to->format('Y-m-d 00:00:00');
    $excluded = sales_report_activity_exclusions();
    $receivedMethodUsable = sales_report_usable_method_sql('p.payment_method');
    $seminarMethodUsable = sales_report_usable_method_sql('sp.payment_method');
    $refundMethodUsable = sales_report_usable_method_sql('cx.refund_destination_method');
    $excludePayMongoHistory = !empty($capabilities['paymongo_refund_history'])
        ? "AND COALESCE(cx.refund_provider, '') <> 'paymongo'"
        : '';
    $primaryVenueLabel = sales_report_venue_label_sql('v');
    $hotelBuildingGroup = sales_report_hotel_building_group_sql();
    $reportText = static fn(string $expression): string => "CONVERT(({$expression}) USING utf8mb4) COLLATE utf8mb4_unicode_ci";
    $primaryVenueText = $reportText($primaryVenueLabel);
    $primaryCategoryText = $reportText('v.category');
    $customerNameText = $reportText("TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')))");
    $feeCents = !empty($capabilities['payment_fee_columns'])
        ? "CASE WHEN LEFT(p.transaction_id, 4) = 'pay_' AND p.fee_verified_at IS NOT NULL AND p.fee_cents IS NOT NULL THEN p.fee_cents WHEN LEFT(p.transaction_id, 4) <> 'pay_' THEN 0 ELSE NULL END"
        : "CASE WHEN LEFT(p.transaction_id, 4) <> 'pay_' THEN 0 ELSE NULL END";
    $providerNetCents = !empty($capabilities['payment_net_column']) && !empty($capabilities['payment_fee_columns'])
        ? "CASE WHEN LEFT(p.transaction_id, 4) = 'pay_' AND p.fee_verified_at IS NOT NULL AND p.net_amount_cents IS NOT NULL THEN p.net_amount_cents ELSE NULL END"
        : 'NULL';
    $branches = [];
    $dateBindings = [$fromBoundary, $toBoundary, $fromBoundary, $toBoundary];
    $branches[] = "
            SELECT 'booking' AS record_source, 'received' AS activity_type, p.id AS activity_id, p.payment_date AS occurred_at,
                   p.amount, {$reportText("TRIM(COALESCE(p.payment_method, ''))")} AS method_label,
                   {$reportText("CASE
                       WHEN UPPER(LEFT(TRIM(COALESCE(p.transaction_id, '')), 7)) = 'MANUAL-'
                           THEN COALESCE(NULLIF(TRIM(mps.transaction_reference), ''), '')
                       ELSE COALESCE(NULLIF(TRIM(mps.transaction_reference), ''), NULLIF(TRIM(p.transaction_id), ''), '')
                   END")} AS reference_code,
                   b.id AS booking_id, {$reportText('b.reference_no')} AS booking_reference, b.total_amount AS booking_total,
                   COALESCE(hotel_building.group_id, v.id) AS primary_filter_id,
                   {$primaryVenueText} AS primary_venue, {$primaryCategoryText} AS primary_category,
                   {$customerNameText} AS customer_name,
                   CASE WHEN LEFT(p.transaction_id, 4) = 'pay_' THEN 1 ELSE 0 END AS is_paymongo,
                   {$feeCents} AS provider_fee_cents, {$providerNetCents} AS provider_net_cents
            FROM payments p
            INNER JOIN bookings b ON b.id = p.booking_id
            INNER JOIN customers c ON c.id = b.customer_id
            INNER JOIN venues v ON v.id = b.venue_id
            LEFT JOIN {$hotelBuildingGroup} AS hotel_building ON hotel_building.name = v.name AND v.category = 'Hotel Room'
            LEFT JOIN manual_payment_submissions mps ON mps.payment_id = p.id
            WHERE p.status = 'Success' AND p.amount > 0
              AND p.payment_date >= ? AND p.payment_date < ? AND {$excluded}
              AND {$receivedMethodUsable}
        ";

    $branches[] = "
            SELECT 'booking' AS record_source, 'refunded' AS activity_type, h.id AS activity_id, h.created_at AS occurred_at,
                   h.refund_amount AS amount,
                   {$reportText("TRIM(COALESCE(cx.refund_destination_method, ''))")} AS method_label,
                   {$reportText("COALESCE(NULLIF(TRIM(cx.refund_transaction_id), ''), '')")} AS reference_code,
                   b.id AS booking_id, {$reportText('b.reference_no')} AS booking_reference, b.total_amount AS booking_total,
                   COALESCE(hotel_building.group_id, v.id) AS primary_filter_id,
                   {$primaryVenueText} AS primary_venue, {$primaryCategoryText} AS primary_category,
                   {$customerNameText} AS customer_name, 0 AS is_paymongo, 0 AS provider_fee_cents, NULL AS provider_net_cents
            FROM cancellation_history h
            INNER JOIN bookings b ON b.id = h.booking_id
            INNER JOIN customers c ON c.id = b.customer_id
            INNER JOIN venues v ON v.id = b.venue_id
            LEFT JOIN {$hotelBuildingGroup} AS hotel_building ON hotel_building.name = v.name AND v.category = 'Hotel Room'
            LEFT JOIN cancellations cx ON cx.id = h.cancellation_id AND cx.booking_id = h.booking_id
            WHERE h.action = 'processed' AND h.refund_amount > 0
              AND h.created_at >= ? AND h.created_at < ? AND {$excluded}
              {$excludePayMongoHistory}
              AND {$refundMethodUsable}
        ";

    if (!empty($capabilities['paymongo_refund_ledger'])) {
        $refundOccurredAt = 'COALESCE(FROM_UNIXTIME(NULLIF(at.provider_updated_at, 0)), at.reconciled_at, at.updated_at)';
        $branches[] = "
            SELECT 'booking' AS record_source, 'refunded' AS activity_type, CONCAT('PMREFUND:', at.id) AS activity_id,
                   {$refundOccurredAt} AS occurred_at, al.refund_cents / 100.0 AS amount,
                   {$reportText("TRIM(COALESCE(p.payment_method, ''))")} AS method_label,
                   {$reportText("COALESCE(NULLIF(TRIM(at.provider_refund_id), ''), at.correlation_note)")} AS reference_code,
                   b.id AS booking_id, {$reportText('b.reference_no')} AS booking_reference, b.total_amount AS booking_total,
                   COALESCE(hotel_building.group_id, v.id) AS primary_filter_id,
                   {$primaryVenueText} AS primary_venue, {$primaryCategoryText} AS primary_category,
                   {$customerNameText} AS customer_name, 0 AS is_paymongo, 0 AS provider_fee_cents, NULL AS provider_net_cents
            FROM pm_refund_attempts at
            INNER JOIN pm_refund_allocations al ON al.id = at.allocation_id
            INNER JOIN pm_refund_snapshots rs ON rs.id = al.snapshot_id
            INNER JOIN payments p ON BINARY p.transaction_id = BINARY al.payment_id
            INNER JOIN bookings b ON b.id = rs.booking_id
            INNER JOIN customers c ON c.id = b.customer_id
            INNER JOIN venues v ON v.id = b.venue_id
            LEFT JOIN {$hotelBuildingGroup} AS hotel_building ON hotel_building.name = v.name AND v.category = 'Hotel Room'
            WHERE at.status = 'succeeded' AND NULLIF(TRIM(at.provider_refund_id), '') IS NOT NULL AND al.refund_cents > 0
              AND NOT EXISTS (
                  SELECT 1 FROM pm_refund_attempts duplicate_attempt
                  WHERE duplicate_attempt.status = 'succeeded'
                    AND BINARY duplicate_attempt.provider_refund_id = BINARY at.provider_refund_id
                    AND duplicate_attempt.id < at.id
              )
              AND p.status = 'Success' AND LEFT(p.transaction_id, 4) = 'pay_'
              AND {$refundOccurredAt} >= ? AND {$refundOccurredAt} < ? AND {$excluded}
              AND {$receivedMethodUsable}
        ";
        $dateBindings[] = $fromBoundary;
        $dateBindings[] = $toBoundary;
    }

    $branches[] = "
            SELECT 'seminar' AS record_source, 'received' AS activity_type, sp.id AS activity_id, sp.created_at AS occurred_at,
                   sp.amount, {$reportText("TRIM(COALESCE(sp.payment_method, ''))")} AS method_label,
                   {$reportText("COALESCE(NULLIF(TRIM(sp.transaction_reference), ''), '')")} AS reference_code,
                   NULL AS booking_id, {$reportText("CONCAT('Seminar: ', s.name)")} AS booking_reference, s.agreed_price AS booking_total,
                   s.hall_venue_id AS primary_filter_id,
                   {$reportText("CONCAT('Seminar · Event Hall · ', v.name)")} AS primary_venue,
                   {$reportText("'Seminar'")} AS primary_category, {$reportText("'Staff-entered seminar'")} AS customer_name,
                   0 AS is_paymongo, 0 AS provider_fee_cents, NULL AS provider_net_cents
            FROM seminar_payments sp
            INNER JOIN seminars s ON s.id=sp.seminar_id
            INNER JOIN venues v ON v.id=s.hall_venue_id
            WHERE sp.status='posted' AND sp.amount > 0
              AND sp.created_at >= ? AND sp.created_at < ?
              AND {$seminarMethodUsable}
        ";
    $dateBindings[] = $fromBoundary;
    $dateBindings[] = $toBoundary;
    $sql = "
        SELECT record_source, activity_type, activity_id, occurred_at, amount, method_label, reference_code,
               booking_id, booking_reference, booking_total, primary_filter_id, primary_venue,
               primary_category, customer_name, is_paymongo, provider_fee_cents, provider_net_cents
        FROM (" . implode("\nUNION ALL\n", $branches) . ") AS activity
        ORDER BY occurred_at DESC, activity_type ASC, record_source ASC, activity_id DESC
        LIMIT ? OFFSET ?
    ";
    $statement = $conn->prepare($sql);
    if (!$statement) throw new RuntimeException('Unable to prepare sales report query.');
    $offset = max(0, $offset);
    $limit = max(1, min(5000, $limit));
    $params = array_merge($dateBindings, [$limit, $offset]);
    $bindings = [str_repeat('s', count($dateBindings)) . 'ii'];
    foreach ($params as $index => $_value) $bindings[] = &$params[$index];
    call_user_func_array([$statement, 'bind_param'], $bindings);
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
    $capabilities = sales_report_data_capabilities($conn);
    $unmatchedPayMongoHistory = sales_report_has_unmatched_paymongo_refund_history($conn, $filters, $capabilities);
    $venueTotalsByKey = [];
    $availableMethods = [];
    $received = 0;
    $refunded = 0;
    $providerFees = 0;
    $knownNet = 0;
    $unknownFeeRows = 0;
    $unknownNetRows = 0;
    $paymongoReceivedRows = 0;
    $methodTotals = [];
    $transactionRows = [];
    $totalRows = 0;

    $batchSize = 1000;
    for ($offset = 0; ; $offset += $batchSize) {
        $activities = sales_report_fetch_activities($conn, $filters, $offset, $batchSize, $capabilities);
        if (!$activities) break;
        $roomLines = sales_report_fetch_room_lines($conn, array_column($activities, 'booking_id'));
        foreach ($activities as $activity) {
            $method = trim((string)($activity['method_label'] ?? ''));
            if ($method === '' || strcasecmp($method, 'unknown') === 0) continue;
            $availableMethods[$method] = $method;
            if ($filters['method'] !== '' && strcasecmp($method, $filters['method']) !== 0) continue;
            $amountCents = max(0, sales_report_money_to_cents($activity['amount'] ?? 0));
            if ($amountCents <= 0) continue;
            $isReceived = $activity['activity_type'] === 'received';
            $isPayMongo = $isReceived && (int)($activity['is_paymongo'] ?? 0) === 1;
            $feeCents = 0;
            $providerNetCents = $amountCents;
            $feeKnown = true;
            if ($isPayMongo) {
                $candidateFee = $activity['provider_fee_cents'] ?? null;
                $candidateNet = $activity['provider_net_cents'] ?? null;
                $feeKnown = $candidateFee !== null && is_numeric($candidateFee)
                    && (int)$candidateFee >= 0 && (int)$candidateFee <= $amountCents
                    && ($candidateNet === null || (is_numeric($candidateNet)
                        && (int)$candidateNet >= 0 && (int)$candidateFee + (int)$candidateNet === $amountCents));
                if ($feeKnown) {
                    $feeCents = (int)$candidateFee;
                    $providerNetCents = $amountCents - $feeCents;
                } else {
                    $providerNetCents = 0;
                }
            }
            $targets = sales_report_allocation_targets($activity, $roomLines);
            $shares = sales_report_allocate_cents($amountCents, $targets);
            $shareTargets = [];
            foreach ($targets as $target) {
                $grossShare = (int)($shares[$target['key']] ?? 0);
                if ($grossShare > 0) $shareTargets[] = ['key' => $target['key'], 'weight' => $grossShare];
            }
            $feeShares = $feeKnown ? sales_report_allocate_cents($feeCents, $shareTargets) : [];
            $netShares = $feeKnown ? sales_report_allocate_cents($providerNetCents, $shareTargets) : [];
            $matchingShare = 0;
            $matchingLabel = '';
            $matchingFee = 0;
            $matchingNet = 0;
            $matchingHasUnknown = false;
            foreach ($targets as $target) {
                $share = (int)($shares[$target['key']] ?? 0);
                if ($share <= 0) continue;
                $filterId = (int)$target['filter_id'];
                if ($filters['venue_id'] > 0 && $filterId !== $filters['venue_id']) continue;
                $matchingShare += $share;
                $matchingLabel = $target['label'];
                $targetFee = $isReceived ? (int)($feeShares[$target['key']] ?? 0) : 0;
                $targetNet = $isReceived ? (int)($netShares[$target['key']] ?? 0) : -$share;
                $matchingFee += $targetFee;
                $matchingNet += $targetNet;
                if (!$feeKnown) $matchingHasUnknown = true;
                if (!isset($methodTotals[$method])) $methodTotals[$method] = [
                    'method' => $method, 'received_cents' => 0, 'refunded_cents' => 0,
                    'provider_fee_cents' => 0, 'net_cents' => 0, 'unknown_fee_rows' => 0,
                    'unknown_net_rows' => 0, 'paymongo_received_rows' => 0,
                ];
                if ($isReceived) $methodTotals[$method]['received_cents'] += $share;
                else $methodTotals[$method]['refunded_cents'] += $share;
                if ($isReceived) {
                    if ($feeKnown) {
                        $methodTotals[$method]['provider_fee_cents'] += $targetFee;
                        $methodTotals[$method]['net_cents'] += $targetNet;
                    } else {
                        $methodTotals[$method]['unknown_fee_rows']++;
                        $methodTotals[$method]['unknown_net_rows']++;
                    }
                    if ($isPayMongo) $methodTotals[$method]['paymongo_received_rows']++;
                } else {
                    $methodTotals[$method]['net_cents'] -= $share;
                }

                $key = (string)$target['key'];
                if (!isset($venueTotalsByKey[$key])) $venueTotalsByKey[$key] = [
                    'label' => $target['label'], 'venue_id' => $filterId, 'received_cents' => 0,
                    'refunded_cents' => 0, 'provider_fee_cents' => 0, 'net_cents' => 0,
                    'unknown_fee_rows' => 0, 'unknown_net_rows' => 0, 'paymongo_received_rows' => 0,
                ];
                if ($isReceived) $venueTotalsByKey[$key]['received_cents'] += $share;
                else $venueTotalsByKey[$key]['refunded_cents'] += $share;
                if ($isReceived) {
                    if ($feeKnown) {
                        $venueTotalsByKey[$key]['provider_fee_cents'] += $targetFee;
                        $venueTotalsByKey[$key]['net_cents'] += $targetNet;
                    } else {
                        $venueTotalsByKey[$key]['unknown_fee_rows']++;
                        $venueTotalsByKey[$key]['unknown_net_rows']++;
                    }
                    if ($isPayMongo) $venueTotalsByKey[$key]['paymongo_received_rows']++;
                } else {
                    $venueTotalsByKey[$key]['net_cents'] -= $share;
                }
            }
            if ($filters['venue_id'] > 0 && $matchingShare <= 0) continue;

            if ($isReceived) {
                $received += $matchingShare;
                $providerFees += $matchingFee;
                if ($matchingHasUnknown) {
                    $unknownFeeRows++;
                    $unknownNetRows++;
                } else {
                    $knownNet += $matchingNet;
                }
                if ($isPayMongo) $paymongoReceivedRows++;
            } else {
                $refunded += $matchingShare;
                $knownNet -= $matchingShare;
            }
            $totalRows++;
            if ($onTransaction !== null || ($totalRows > ($page - 1) * $pageSize && $totalRows <= $page * $pageSize)) {
                $transaction = [
                    'type' => (string)$activity['activity_type'],
                    'source' => (string)$activity['record_source'],
                    'date' => (string)$activity['occurred_at'],
                    'method' => $method,
                    'reference' => (string)($activity['reference_code'] ?? ''),
                    'booking_reference' => (string)($activity['booking_reference'] ?? ''),
                    'customer_name' => trim((string)($activity['customer_name'] ?? '')) ?: 'Guest',
                    'venue' => $filters['venue_id'] > 0 ? $matchingLabel : (string)$activity['primary_venue'],
                    'amount_cents' => $matchingShare,
                    'gross_received_cents' => $isReceived ? $matchingShare : 0,
                    'customer_refund_cents' => $isReceived ? 0 : $matchingShare,
                    'provider_fee_cents' => $isReceived && !$matchingHasUnknown ? $matchingFee : ($isReceived ? null : 0),
                    'net_after_fees_refunds_cents' => $isReceived && $matchingHasUnknown ? null : $matchingNet,
                    'fee_status' => !$isReceived ? 'not_applicable' : ($matchingHasUnknown ? 'unknown' : ($isPayMongo ? 'provider_verified' : 'no_provider_fee')),
                ];
                if ($onTransaction !== null) $onTransaction($transaction);
                else $transactionRows[] = $transaction;
            }
        }
        if (count($activities) < $batchSize) break;
    }
    ksort($availableMethods, SORT_NATURAL | SORT_FLAG_CASE);
    $availableMethods = array_values($availableMethods);
    $refundDataComplete = !$unmatchedPayMongoHistory
        && (!empty($capabilities['paymongo_refund_ledger']) || $paymongoReceivedRows === 0);

    foreach ($methodTotals as &$methodTotal) {
        $methodRefundDataComplete = !$unmatchedPayMongoHistory
            && (!empty($capabilities['paymongo_refund_ledger']) || $methodTotal['paymongo_received_rows'] === 0);
        if ($methodTotal['unknown_net_rows'] > 0 || !$methodRefundDataComplete) $methodTotal['net_cents'] = null;
    }
    unset($methodTotal);
    uasort($methodTotals, static fn(array $a, array $b): int => strcmp($a['method'], $b['method']));

    $venueTotals = [];
    foreach ($venueTotalsByKey as $value) {
        $venueRefundDataComplete = !$unmatchedPayMongoHistory
            && (!empty($capabilities['paymongo_refund_ledger']) || $value['paymongo_received_rows'] === 0);
        if ($value['unknown_net_rows'] > 0 || !$venueRefundDataComplete) $value['net_cents'] = null;
        $venueTotals[] = $value;
    }
    usort($venueTotals, static function (array $a, array $b): int {
        return strcasecmp($a['label'], $b['label']);
    });

    $netIsKnown = $unknownNetRows === 0 && $refundDataComplete;
    return [
        'received_cents' => $received,
        'refunded_cents' => $refunded,
        'provider_fee_cents' => $providerFees,
        'fee_unknown_rows' => $unknownFeeRows,
        'net_cents' => $netIsKnown ? $knownNet : null,
        'net_unknown_rows' => $unknownNetRows + ((!$refundDataComplete && $unknownNetRows === 0) ? 1 : 0),
        'paymongo_refund_data_complete' => $refundDataComplete,
        'paymongo_fee_data_complete' => $capabilities['payment_fee_columns'] || $paymongoReceivedRows === 0,
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
    $seminarMethodUsable = sales_report_usable_method_sql('sp.payment_method');
    $statement = $conn->prepare("SELECT COALESCE(SUM(receipts.amount), 0) AS total FROM (
        SELECT p.amount, CONVERT(p.payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_method FROM payments p
        INNER JOIN bookings b ON b.id = p.booking_id INNER JOIN customers c ON c.id = b.customer_id
        WHERE p.status='Success' AND p.amount > 0 AND p.payment_date >= ? AND p.payment_date < ? AND {$excluded}
          AND {$methodUsable}
        UNION ALL
        SELECT sp.amount, CONVERT(sp.payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci AS payment_method FROM seminar_payments sp
        WHERE sp.status='posted' AND sp.amount > 0 AND sp.created_at >= ? AND sp.created_at < ?
          AND {$seminarMethodUsable}
    ) AS receipts");
    if (!$statement) throw new RuntimeException('Unable to prepare monthly sales query.');
    $statement->bind_param('ssss', $from, $until, $from, $until);
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
