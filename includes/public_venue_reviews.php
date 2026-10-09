<?php
declare(strict_types=1);

final class VenueReviewsQueryFailure extends RuntimeException
{
    public function __construct(
        public readonly string $stage,
        public readonly string $causeClass,
        public readonly string $sqlState,
        public readonly int $errorCode,
        ?Throwable $previous = null
    ) {
        parent::__construct($stage . ' failed', 0, $previous);
    }
}

/** Read prepared SELECT results with mysqlnd when available and bind_result otherwise. */
function venue_reviews_fetch_rows(object $stmt): array
{
    if (method_exists($stmt, 'get_result')) {
        $result = $stmt->get_result();
        if (!$result || !method_exists($result, 'fetch_assoc')) {
            throw new RuntimeException('Query result unavailable.');
        }
        $rows = [];
        while (($row = $result->fetch_assoc()) !== null) {
            if (is_array($row)) $rows[] = $row;
        }
        if (method_exists($result, 'free')) $result->free();
        return $rows;
    }

    if (!method_exists($stmt, 'result_metadata') || !method_exists($stmt, 'bind_result') || !method_exists($stmt, 'fetch')) {
        throw new RuntimeException('Query result API unavailable.');
    }
    $metadata = $stmt->result_metadata();
    if (!$metadata || !method_exists($metadata, 'fetch_fields')) throw new RuntimeException('Query metadata unavailable.');
    $fields = $metadata->fetch_fields();
    if (method_exists($metadata, 'free')) $metadata->free();
    if (!$fields) return [];

    $row = [];
    $references = [];
    foreach ($fields as $field) {
        $row[$field->name] = null;
        $references[] =& $row[$field->name];
    }
    if ($stmt->bind_result(...$references) !== true) throw new RuntimeException('Query result binding failed.');

    $rows = [];
    while ($stmt->fetch()) {
        $copy = [];
        foreach ($row as $name => $value) $copy[$name] = $value;
        $rows[] = $copy;
    }
    return $rows;
}

/** Execute one fixed prepared query and convert database failures to stage-tagged metadata. */
function venue_reviews_query_rows(object $conn, string $stage, string $sql, string $types = '', array $values = []): array
{
    $stmt = null;
    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new RuntimeException('Query preparation failed.');

        if ($types !== '') {
            if (strlen($types) !== count($values)) throw new RuntimeException('Query parameter count mismatch.');
            $references = [];
            foreach ($values as $index => &$value) $references[$index] =& $value;
            unset($value);
            if ($stmt->bind_param($types, ...array_values($references)) !== true) {
                throw new RuntimeException('Query parameter binding failed.');
            }
        }

        if ($stmt->execute() !== true) throw new RuntimeException('Query execution failed.');
        return venue_reviews_fetch_rows($stmt);
    } catch (Throwable $cause) {
        $sqlState = '';
        if (method_exists($cause, 'getSqlState')) $sqlState = (string)$cause->getSqlState();
        if ($sqlState === '' && isset($stmt) && is_object($stmt) && isset($stmt->sqlstate)) $sqlState = (string)$stmt->sqlstate;
        if ($sqlState === '' && isset($conn->sqlstate)) $sqlState = (string)$conn->sqlstate;
        if (preg_match('/\A[A-Z0-9]{5}\z/', $sqlState) !== 1) $sqlState = '00000';

        $errorCode = (int)$cause->getCode();
        if ($errorCode === 0 && isset($stmt) && is_object($stmt) && isset($stmt->errno)) $errorCode = (int)$stmt->errno;
        if ($errorCode === 0 && isset($conn->errno)) $errorCode = (int)$conn->errno;

        throw new VenueReviewsQueryFailure($stage, get_class($cause), $sqlState, $errorCode, $cause);
    } finally {
        if (isset($stmt) && is_object($stmt) && method_exists($stmt, 'close')) {
            try { $stmt->close(); } catch (Throwable) {}
        }
    }
}

function venue_reviews_failure_response(string $stage, ?Throwable $cause = null): array
{
    $failure = $cause instanceof VenueReviewsQueryFailure
        ? $cause
        : new VenueReviewsQueryFailure(
            $stage,
            $cause ? get_class($cause) : 'schema_unavailable',
            '00000',
            $cause ? (int)$cause->getCode() : 0,
            $cause
        );
    return ['status' => 503, 'body' => ['success' => false, 'message' => 'Reviews are temporarily unavailable.'], 'failure' => $failure];
}

function venue_reviews_hotel_group_schema_ready(object $conn, ?callable $schemaCheck = null): bool
{
    if ($schemaCheck !== null) return (bool)$schemaCheck($conn);
    return function_exists('hotel_group_schema_ready') && hotel_group_schema_ready($conn);
}

/** Build the public response without exposing query failures or returning fake empty results. */
function venue_reviews_public_response(object $conn, mixed $venueKey, ?callable $logFailure = null, ?callable $hotelGroupSchemaReady = null): array
{
    if (!is_string($venueKey) || !preg_match('/\A(?:event|villa)-[1-9][0-9]*\z|\Ahotel-[a-f0-9]{32}\z|\Ahotel-group-[1-9][0-9]*\z/', $venueKey)) {
        return ['status' => 422, 'body' => ['success' => false, 'message' => 'A valid venue key is required.']];
    }

    $stage = 'venue_resolution';
    $maxResolvedVenueIds = 1000;
    try {
        $venueIds = [];
        $keyType = str_starts_with($venueKey, 'hotel-group-') ? 'hotel_group' : (str_starts_with($venueKey, 'hotel-') ? 'hotel' : (str_starts_with($venueKey, 'event-') ? 'event' : 'villa'));
        if ($keyType === 'hotel_group') {
            if (!venue_reviews_hotel_group_schema_ready($conn, $hotelGroupSchemaReady)) {
                $response = venue_reviews_failure_response('venue_resolution_schema');
                if ($logFailure) $logFailure($response['failure']);
                unset($response['failure']);
                return $response;
            }
            $groupId = (int)substr($venueKey, strlen('hotel-group-'));
            $rows = venue_reviews_query_rows($conn, $stage, "SELECT DISTINCT h.venue_id FROM hotel_rooms h
                INNER JOIN hotel_room_groups g ON g.id = h.room_group_id
                INNER JOIN hotel_room_types t ON t.type_code = g.room_type_code AND t.active = 1
                INNER JOIN venues v ON v.id = h.venue_id
                WHERE h.room_group_id = ? AND v.category = 'Hotel Room' AND v.status = 'Available'
                LIMIT 1001", 'i', [$groupId]);
            $venueIds = array_map(static fn(array $row): int => (int)$row['venue_id'], $rows);
        } elseif ($keyType === 'hotel') {
            $digest = substr($venueKey, 6);
            if (venue_reviews_hotel_group_schema_ready($conn, $hotelGroupSchemaReady)) {
                $rows = venue_reviews_query_rows($conn, $stage, "SELECT DISTINCT h.venue_id
                    FROM hotel_rooms h
                    INNER JOIN hotel_room_groups g ON g.id = h.room_group_id
                    INNER JOIN hotel_room_types t ON t.type_code = g.room_type_code AND t.active = 1
                    INNER JOIN venues v ON v.id = h.venue_id
                    WHERE v.category = 'Hotel Room' AND v.status = 'Available'
                      AND MD5(CONCAT(v.name, ' - ', COALESCE(NULLIF(g.legacy_room_type, ''), t.display_name))) = ?
                    LIMIT 1001", 's', [$digest]);
                $venueIds = array_map(static fn(array $row): int => (int)$row['venue_id'], $rows);
            }
            // Keep old keys working for ungrouped records and legacy aliases not
            // represented by the normalized room-type catalog.
            if (!$venueIds) {
                $rows = venue_reviews_query_rows($conn, $stage, "SELECT DISTINCT h.venue_id
                    FROM hotel_rooms h INNER JOIN venues v ON v.id = h.venue_id
                    WHERE v.category = 'Hotel Room' AND v.status = 'Available'
                      AND MD5(CONCAT(v.name, ' - ', h.room_type)) = ?
                    LIMIT 1001", 's', [$digest]);
                $venueIds = array_map(static fn(array $row): int => (int)$row['venue_id'], $rows);
            }
        } else {
            $id = (int)substr($venueKey, strpos($venueKey, '-') + 1);
            $category = $keyType === 'event' ? 'Event Hall' : 'Resort Villa';
            $rows = venue_reviews_query_rows($conn, $stage, "SELECT id FROM venues WHERE id = ? AND category = ? AND status = 'Available' LIMIT 1", 'is', [$id, $category]);
            if (isset($rows[0]['id'])) $venueIds[] = (int)$rows[0]['id'];
        }
        if (count($venueIds) > $maxResolvedVenueIds) {
            $stage = 'venue_resolution_limit';
            throw new RuntimeException('Venue review resolution limit exceeded.');
        }
        if (!$venueIds) return ['status' => 404, 'body' => ['success' => false, 'message' => 'Venue not found.']];

        $placeholders = implode(',', array_fill(0, count($venueIds), '?'));
        $types = str_repeat('i', count($venueIds));
        $stage = 'review_aggregate';
        $aggregateRows = venue_reviews_query_rows($conn, $stage, "SELECT COALESCE(AVG(vr.rating), 0) AS rating_average, COUNT(*) AS rating_count
            FROM venue_reviews vr INNER JOIN bookings b ON b.id = vr.booking_id
            WHERE vr.moderation_status = 'Approved' AND b.booking_status <> 'Cancelled'
              AND COALESCE(b.payment_status, '') <> 'Refunded' AND vr.venue_id IN ($placeholders)", $types, $venueIds);
        $aggregate = $aggregateRows[0] ?? ['rating_average' => 0, 'rating_count' => 0];

        $stage = 'review_list';
        $rows = venue_reviews_query_rows($conn, $stage, "SELECT vr.rating, vr.review_text, vr.created_at, c.first_name, c.last_name
            FROM venue_reviews vr INNER JOIN customers c ON c.id = vr.customer_id
            INNER JOIN bookings b ON b.id = vr.booking_id
            WHERE vr.moderation_status = 'Approved' AND b.booking_status <> 'Cancelled'
              AND COALESCE(b.payment_status, '') <> 'Refunded' AND vr.venue_id IN ($placeholders)
            ORDER BY vr.created_at DESC, vr.id DESC LIMIT 3", $types, $venueIds);

        $reviews = [];
        foreach ($rows as $row) {
            $lastName = trim((string)($row['last_name'] ?? ''));
            $initial = function_exists('mb_substr') ? mb_substr($lastName, 0, 1) : substr($lastName, 0, 1);
            $reviews[] = [
                'rating' => (int)$row['rating'],
                'review_text' => trim(strip_tags((string)($row['review_text'] ?? ''))),
                'reviewer' => trim((string)($row['first_name'] ?? '')) . ($lastName !== '' ? ' ' . $initial . '.' : ''),
                'created_at' => (string)$row['created_at'],
            ];
        }

        return ['status' => 200, 'body' => [
            'success' => true,
            'venue_key' => $venueKey,
            'rating_average' => round((float)($aggregate['rating_average'] ?? 0), 1),
            'rating_count' => (int)($aggregate['rating_count'] ?? 0),
            'reviews' => $reviews,
        ]];
    } catch (Throwable $cause) {
        $response = venue_reviews_failure_response($stage, $cause);
        if ($logFailure) $logFailure($response['failure']);
        unset($response['failure']);
        return $response;
    }
}
