<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/public_venue_reviews.php';
require_once dirname(__DIR__) . '/includes/hotel_rooms.php';

final class FakeVenueReviewsSqlException extends RuntimeException
{
    public function getSqlState(): string { return '42S02'; }
}

final class FakeVenueReviewsResult
{
    public function __construct(private array $rows) {}

    public function fetch_assoc(): ?array
    {
        return array_shift($this->rows) ?? null;
    }

    public function free(): void {}
}

final class FakeVenueReviewsStatement
{
    private array $parameters = [];
    private array $rows = [];

    public function __construct(private FakeVenueReviewsDatabase $database, private string $sql) {}

    public function bind_param(string $types, &...$parameters): bool
    {
        if (strlen($types) !== count($parameters)) return false;
        foreach ($parameters as $index => &$parameter) $this->parameters[$index] =& $parameter;
        unset($parameter);
        return true;
    }

    public function execute(): bool
    {
        $this->rows = $this->database->execute($this->sql, array_map(static fn(&$value) => $value, $this->parameters));
        return true;
    }

    public function get_result(): FakeVenueReviewsResult
    {
        return new FakeVenueReviewsResult($this->rows);
    }

    public function close(): void {}
}

final class FakeVenueReviewsMetadata
{
    public function __construct(private array $fields) {}
    public function fetch_fields(): array { return array_map(static fn(string $name): object => (object)['name' => $name], $this->fields); }
    public function free(): void {}
}

final class FakeVenueReviewsStatementWithoutMysqlnd
{
    private array $references = [];
    private int $rowIndex = 0;

    public function __construct(private array $rows) {}
    public function result_metadata(): FakeVenueReviewsMetadata
    {
        return new FakeVenueReviewsMetadata(array_keys($this->rows[0] ?? []));
    }
    public function bind_result(&...$references): bool
    {
        foreach ($references as $index => &$reference) $this->references[$index] =& $reference;
        unset($reference);
        return true;
    }
    public function fetch(): bool
    {
        if (!isset($this->rows[$this->rowIndex])) return false;
        foreach ($this->references as $index => &$reference) {
            $reference = $this->rows[$this->rowIndex][array_keys($this->rows[$this->rowIndex])[$index]];
        }
        unset($reference);
        $this->rowIndex++;
        return true;
    }
}

final class FakeVenueReviewsDatabase
{
    public int $prepareCount = 0;
    public string $sqlstate = '00000';
    public int $errno = 0;
    public ?string $failOn = null;
    public array $venues = [];
    public array $hotelRooms = [];
    public array $reviews = [];
    public string $lastGroupResolutionSql = '';
    public string $lastNormalizedLegacySql = '';
    public array $groupRoomLabels = [];

    public function prepare(string $sql): FakeVenueReviewsStatement
    {
        $this->prepareCount++;
        if ($this->failOn === 'review_list' && str_contains($sql, 'SELECT vr.rating, vr.review_text')) {
            $this->sqlstate = '42S02';
            $this->errno = 1146;
            throw new FakeVenueReviewsSqlException('simulated missing review table');
        }
        return new FakeVenueReviewsStatement($this, $sql);
    }

    public function execute(string $sql, array $parameters): array
    {
        if (str_contains($sql, 'COALESCE(NULLIF(g.legacy_room_type')) {
            $this->lastNormalizedLegacySql = $sql;
            $digest = (string)($parameters[0] ?? '');
            $venueIds = [];
            foreach ($this->hotelRooms as $room) {
                $groupId = (int)($room['room_group_id'] ?? 0);
                $venue = $this->venues[$room['venue_id']] ?? null;
                $label = $this->groupRoomLabels[$groupId] ?? null;
                if ($groupId < 1 || !$venue || $venue['category'] !== 'Hotel Room' || $venue['status'] !== 'Available' || !is_string($label)) continue;
                if (md5($venue['name'] . ' - ' . $label) === $digest) $venueIds[(int)$room['venue_id']] = true;
            }
            return array_map(static fn(int $id): array => ['venue_id' => $id], array_keys($venueIds));
        }

        if (str_contains($sql, 'WHERE h.room_group_id = ?')) {
            $this->lastGroupResolutionSql = $sql;
            $groupId = (int)($parameters[0] ?? 0);
            $venueIds = [];
            foreach ($this->hotelRooms as $room) {
                if ((int)($room['room_group_id'] ?? 0) !== $groupId) continue;
                $venue = $this->venues[$room['venue_id']] ?? null;
                if (!$venue || $venue['category'] !== 'Hotel Room' || $venue['status'] !== 'Available') continue;
                $venueIds[] = (int)$room['venue_id'];
            }
            if (str_contains($sql, 'SELECT DISTINCT')) $venueIds = array_values(array_unique($venueIds));
            return array_map(static fn(int $id): array => ['venue_id' => $id], array_slice($venueIds, 0, 1001));
        }

        if (str_contains($sql, 'MD5(CONCAT(v.name, \' - \', h.room_type)) = ?')) {
            $digest = (string)($parameters[0] ?? '');
            $venueIds = [];
            foreach ($this->hotelRooms as $room) {
                $venue = $this->venues[$room['venue_id']] ?? null;
                if (!$venue || $venue['category'] !== 'Hotel Room' || $venue['status'] !== 'Available') continue;
                if (md5($venue['name'] . ' - ' . $room['room_type']) === $digest) $venueIds[(int)$room['venue_id']] = true;
            }
            return array_map(static fn(int $id): array => ['venue_id' => $id], array_keys($venueIds));
        }

        if (str_contains($sql, 'AVG(vr.rating)')) {
            $this->assertReviewEligibilityPredicates($sql);
            $reviews = $this->eligibleReviews($sql, $parameters);
            $average = $reviews ? array_sum(array_column($reviews, 'rating')) / count($reviews) : 0;
            return [['rating_average' => $average, 'rating_count' => count($reviews)]];
        }

        if (str_contains($sql, 'SELECT vr.rating, vr.review_text')) {
            $this->assertReviewEligibilityPredicates($sql);
            $reviews = $this->eligibleReviews($sql, $parameters);
            usort($reviews, static fn(array $a, array $b): int => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);
            return array_map(static fn(array $review): array => [
                'rating' => $review['rating'],
                'review_text' => $review['review_text'],
                'created_at' => $review['created_at'],
                'first_name' => $review['first_name'],
                'last_name' => $review['last_name'],
            ], array_slice($reviews, 0, 3));
        }

        throw new RuntimeException('Unexpected SQL in isolated service test.');
    }

    private function assertReviewEligibilityPredicates(string $sql): void
    {
        if (!str_contains($sql, "vr.moderation_status = 'Approved'")
            || !str_contains($sql, "b.booking_status <> 'Cancelled'")
            || !str_contains($sql, "COALESCE(b.payment_status, '') <> 'Refunded'")) {
            throw new RuntimeException('Review eligibility predicate was removed.');
        }
    }

    private function eligibleReviews(string $sql, array $parameters): array
    {
        $venueIds = array_map('intval', $parameters);
        return array_values(array_filter($this->reviews, static fn(array $review): bool =>
            in_array((int)$review['venue_id'], $venueIds, true)
            && $review['moderation_status'] === 'Approved'
            && $review['booking_status'] !== 'Cancelled'
            && $review['payment_status'] !== 'Refunded'
        ));
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(hotel_public_review_key(5, 'Kristel', 'Standard Room') === 'hotel-group-5', 'modern showroom hotels must use the production-supported room-group review key');
$assert(hotel_public_review_key('5', 'Kristel', 'Standard Room') === 'hotel-group-5', 'string room-group ids from mysqli results should use the group review key');
$assert(hotel_public_review_key(null, 'Sevilla Hotel', 'Standard') === 'hotel-' . md5('Sevilla Hotel - Standard'), 'records without group metadata must preserve their legacy review key');
$assert(hotel_public_review_key(0, 'Sevilla Hotel', 'Standard') === 'hotel-' . md5('Sevilla Hotel - Standard'), 'invalid group ids must not produce a group review key');

$fallbackRows = venue_reviews_fetch_rows(new FakeVenueReviewsStatementWithoutMysqlnd([
    ['rating' => 5, 'review_text' => 'first'],
    ['rating' => 4, 'review_text' => 'second'],
]));
$assert($fallbackRows === [['rating' => 5, 'review_text' => 'first'], ['rating' => 4, 'review_text' => 'second']], 'result binding fallback should fetch rows without mysqlnd');

$database = new FakeVenueReviewsDatabase();
for ($id = 11; $id <= 14; $id++) {
    $database->venues[$id] = ['id' => $id, 'name' => 'Sevilla Hotel', 'category' => 'Hotel Room', 'status' => 'Available'];
    $database->hotelRooms[] = ['venue_id' => $id, 'room_type' => 'Standard', 'room_group_id' => 5];
}
$database->groupRoomLabels[5] = 'Standard Room';
$database->hotelRooms[] = ['venue_id' => 11, 'room_type' => 'Standard', 'room_group_id' => 5];
$database->venues[15] = ['id' => 15, 'name' => 'Unavailable Hotel', 'category' => 'Hotel Room', 'status' => 'Maintenance'];
$database->hotelRooms[] = ['venue_id' => 15, 'room_type' => 'Standard', 'room_group_id' => 5];
$database->reviews = [
    ['id' => 1, 'venue_id' => 11, 'rating' => 5, 'review_text' => '<b>Great</b> stay', 'created_at' => '2026-01-05', 'first_name' => 'Ana', 'last_name' => 'Santos', 'moderation_status' => 'Approved', 'booking_status' => 'Completed', 'payment_status' => 'Paid'],
    ['id' => 2, 'venue_id' => 12, 'rating' => 4, 'review_text' => 'Lovely', 'created_at' => '2026-01-04', 'first_name' => 'Ben', 'last_name' => 'Reyes', 'moderation_status' => 'Approved', 'booking_status' => 'Completed', 'payment_status' => 'Paid'],
    ['id' => 3, 'venue_id' => 13, 'rating' => 3, 'review_text' => 'Good', 'created_at' => '2026-01-03', 'first_name' => 'Cara', 'last_name' => 'Cruz', 'moderation_status' => 'Approved', 'booking_status' => 'Confirmed', 'payment_status' => 'Partial'],
    ['id' => 4, 'venue_id' => 14, 'rating' => 1, 'review_text' => 'Okay', 'created_at' => '2026-01-02', 'first_name' => 'Don', 'last_name' => '', 'moderation_status' => 'Approved', 'booking_status' => 'Completed', 'payment_status' => 'Paid'],
    ['id' => 5, 'venue_id' => 11, 'rating' => 5, 'review_text' => 'Cancelled', 'created_at' => '2026-01-08', 'first_name' => 'Eva', 'last_name' => 'Lim', 'moderation_status' => 'Approved', 'booking_status' => 'Cancelled', 'payment_status' => 'Paid'],
    ['id' => 6, 'venue_id' => 11, 'rating' => 5, 'review_text' => 'Refunded', 'created_at' => '2026-01-07', 'first_name' => 'Fay', 'last_name' => 'Uy', 'moderation_status' => 'Approved', 'booking_status' => 'Completed', 'payment_status' => 'Refunded'],
    ['id' => 7, 'venue_id' => 11, 'rating' => 5, 'review_text' => 'Pending', 'created_at' => '2026-01-06', 'first_name' => 'Gil', 'last_name' => 'Tan', 'moderation_status' => 'Pending', 'booking_status' => 'Completed', 'payment_status' => 'Paid'],
];

$key = 'hotel-' . md5('Sevilla Hotel - Standard Room');
$response = venue_reviews_public_response($database, $key, null, static fn(object $conn): bool => true);
$body = $response['body'];
$assert($response['status'] === 200 && $body['success'] === true, 'valid hotel key should return reviews');
$assert($body['rating_count'] === 4 && $body['rating_average'] === 3.3, 'aggregate should include only approved, non-cancelled, non-refunded reviews');
$assert(count($body['reviews']) === 3 && $body['reviews'][0]['reviewer'] === 'Ana S.' && $body['reviews'][0]['review_text'] === 'Great stay', 'latest three reviews preserve the public name mask and strip markup');
$assert(!in_array('Cancelled', array_column($body['reviews'], 'review_text'), true) && !in_array('Refunded', array_column($body['reviews'], 'review_text'), true), 'cancelled and refunded reviews must stay excluded');
$assert(str_contains($database->lastNormalizedLegacySql, 'hotel_room_types t') && !str_contains($database->lastNormalizedLegacySql, 'h.room_type'), 'modern legacy hash resolution should use the normalized catalog instead of the physical room_type column');

$invalid = venue_reviews_public_response($database, 'hotel-not-a-digest');
$assert($invalid['status'] === 422 && $database->prepareCount === 3, 'invalid key must be rejected before any database query');

$groupResponse = venue_reviews_public_response($database, 'hotel-group-5', null, static fn(object $conn): bool => true);
$assert($groupResponse['status'] === 200 && $groupResponse['body']['rating_count'] === 4 && $groupResponse['body']['rating_average'] === 3.3, 'group key resolves distinct available venue ids and preserves review eligibility');
$assert(str_contains($database->lastGroupResolutionSql, 'SELECT DISTINCT h.venue_id') && str_contains($database->lastGroupResolutionSql, 'LIMIT 1001'), 'group resolution must deduplicate venues and fetch only one row beyond the ceiling');

$groupSchemaLog = null;
$groupSchemaUnavailable = venue_reviews_public_response(new FakeVenueReviewsDatabase(), 'hotel-group-5', static function (VenueReviewsQueryFailure $error) use (&$groupSchemaLog): void { $groupSchemaLog = $error; }, static fn(object $conn): bool => false);
$assert($groupSchemaUnavailable['status'] === 503 && $groupSchemaLog instanceof VenueReviewsQueryFailure && $groupSchemaLog->stage === 'venue_resolution_schema', 'unavailable group schema should report a generic service error with stage metadata');

$legacyDatabase = new FakeVenueReviewsDatabase();
$legacyDatabase->venues = $database->venues;
$legacyDatabase->reviews = $database->reviews;
for ($id = 11; $id <= 14; $id++) $legacyDatabase->hotelRooms[] = ['venue_id' => $id, 'room_type' => 'Standard'];
$legacyFallback = venue_reviews_public_response($legacyDatabase, 'hotel-' . md5('Sevilla Hotel - Standard'), null, static fn(object $conn): bool => true);
$assert($legacyFallback['status'] === 200 && $legacyFallback['body']['rating_count'] === 4, 'old room-label keys for ungrouped records should retain their prepared-query fallback');

$overflowDatabase = new FakeVenueReviewsDatabase();
for ($id = 1; $id <= 1001; $id++) {
    $overflowDatabase->venues[$id] = ['id' => $id, 'name' => 'Hotel ' . $id, 'category' => 'Hotel Room', 'status' => 'Available'];
    $overflowDatabase->hotelRooms[] = ['venue_id' => $id, 'room_type' => 'Standard', 'room_group_id' => 9];
}
$overflowLog = null;
$overflow = venue_reviews_public_response($overflowDatabase, 'hotel-group-9', static function (VenueReviewsQueryFailure $error) use (&$overflowLog): void { $overflowLog = $error; }, static fn(object $conn): bool => true);
$assert($overflow['status'] === 503 && $overflowLog instanceof VenueReviewsQueryFailure && $overflowLog->stage === 'venue_resolution_limit', 'more than 1000 resolved venues should fail safely instead of truncating ratings or building an unbounded IN clause');
$assert($overflowDatabase->prepareCount === 1, 'overflow must be detected before aggregate and review queries');

$emptyDatabase = new FakeVenueReviewsDatabase();
$emptyDatabase->venues = $database->venues;
$emptyDatabase->hotelRooms = $database->hotelRooms;
$emptyDatabase->groupRoomLabels = $database->groupRoomLabels;
$empty = venue_reviews_public_response($emptyDatabase, $key, null, static fn(object $conn): bool => true);
$assert($empty['status'] === 200 && $empty['body']['rating_average'] === 0.0 && $empty['body']['rating_count'] === 0 && $empty['body']['reviews'] === [], 'empty eligible review set should return a successful empty response');

$failureDatabase = new FakeVenueReviewsDatabase();
$failureDatabase->venues = $database->venues;
$failureDatabase->hotelRooms = $database->hotelRooms;
$failureDatabase->groupRoomLabels = $database->groupRoomLabels;
$failureDatabase->reviews = $database->reviews;
$failureDatabase->failOn = 'review_list';
$loggedFailure = null;
$failure = venue_reviews_public_response($failureDatabase, $key, static function (VenueReviewsQueryFailure $error) use (&$loggedFailure): void { $loggedFailure = $error; }, static fn(object $conn): bool => true);
$assert($failure['status'] === 503 && $failure['body'] === ['success' => false, 'message' => 'Reviews are temporarily unavailable.'], 'query failure must not be reported as an empty success or leak database details');
$assert($loggedFailure instanceof VenueReviewsQueryFailure && $loggedFailure->stage === 'review_list' && $loggedFailure->sqlState === '42S02' && $loggedFailure->errorCode === 1146, 'server diagnostics should identify the failing stage and database error metadata');

echo "Public venue review service checks passed.\n";
