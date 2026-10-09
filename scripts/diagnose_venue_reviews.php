<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/env.php';

$failures = 0;
$report = static function (bool $passed, string $check, string $details = '') use (&$failures): void {
    if (!$passed) $failures++;
    echo ($passed ? 'PASS' : 'FAIL') . '|' . $check . ($details !== '' ? '|' . $details : '') . PHP_EOL;
};

$report(true, 'php_runtime', 'version=' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
if (!extension_loaded('mysqli')) {
    $report(false, 'mysqli_extension', 'extension_unavailable');
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);
$host = (string)($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost');
$username = (string)($_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root');
$password = (string)($_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '');
$database = (string)($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'sevilla360');

try {
    $conn = @new mysqli($host, $username, $password, $database);
} catch (Throwable $error) {
    $sqlState = method_exists($error, 'getSqlState') ? (string)$error->getSqlState() : '00000';
    if (preg_match('/\A[A-Z0-9]{5}\z/', $sqlState) !== 1) $sqlState = '00000';
    $report(false, 'database_connection', 'exception=' . get_class($error) . '|sqlstate=' . $sqlState . '|code=' . (int)$error->getCode());
    exit(1);
}

if ($conn->connect_errno !== 0) {
    $state = preg_match('/\A[A-Z0-9]{5}\z/', (string)$conn->sqlstate) === 1 ? $conn->sqlstate : '00000';
    $report(false, 'database_connection', 'sqlstate=' . $state . '|code=' . (int)$conn->connect_errno);
    exit(1);
}
$report(true, 'database_connection');

$clientInfo = (string)mysqli_get_client_info();
$report(true, 'mysqli_result_runtime', 'php=' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION
    . '|mysqlnd=' . (stripos($clientInfo, 'mysqlnd') !== false ? 'yes' : 'no'));

$requiredColumns = [
    'venues' => ['id', 'name', 'category', 'status'],
    'hotel_rooms' => ['venue_id', 'room_type', 'room_group_id'],
    'hotel_room_groups' => ['id', 'room_type_code'],
    'hotel_room_types' => ['type_code', 'active'],
    'venue_reviews' => ['id', 'booking_id', 'customer_id', 'venue_id', 'rating', 'review_text', 'moderation_status', 'created_at'],
    'bookings' => ['id', 'booking_status', 'payment_status'],
    'customers' => ['id', 'first_name', 'last_name'],
];

foreach ($requiredColumns as $table => $columns) {
    $result = $conn->query("SHOW COLUMNS FROM `{$table}`");
    if (!$result) {
        $state = preg_match('/\A[A-Z0-9]{5}\z/', (string)$conn->sqlstate) === 1 ? $conn->sqlstate : '00000';
        $report(false, 'schema_table', 'table=' . $table . '|sqlstate=' . $state . '|code=' . (int)$conn->errno);
        continue;
    }
    $present = [];
    while ($row = $result->fetch_assoc()) $present[(string)$row['Field']] = true;
    $result->free();
    $missing = array_values(array_filter($columns, static fn(string $column): bool => !isset($present[$column])));
    $report(!$missing, 'schema_columns', 'table=' . $table . ($missing ? '|missing=' . implode(',', $missing) : ''));
}

$queryChecks = [
    'venue_resolution_hotel_group_key' => "EXPLAIN SELECT DISTINCT h.venue_id
        FROM hotel_rooms h
        INNER JOIN hotel_room_groups g ON g.id = h.room_group_id
        INNER JOIN hotel_room_types t ON t.type_code = g.room_type_code AND t.active = 1
        INNER JOIN venues v ON v.id = h.venue_id
        WHERE h.room_group_id = 0 AND v.category = 'Hotel Room' AND v.status = 'Available'
        LIMIT 1001",
    'venue_resolution_hotel_normalized_hash' => "EXPLAIN SELECT DISTINCT h.venue_id
        FROM hotel_rooms h
        INNER JOIN hotel_room_groups g ON g.id = h.room_group_id
        INNER JOIN hotel_room_types t ON t.type_code = g.room_type_code AND t.active = 1
        INNER JOIN venues v ON v.id = h.venue_id
        WHERE v.category = 'Hotel Room' AND v.status = 'Available'
          AND MD5(CONCAT(v.name, ' - ', COALESCE(NULLIF(g.legacy_room_type, ''), t.display_name))) = '00000000000000000000000000000000'
        LIMIT 1001",
    'venue_resolution_hotel_legacy_hash' => "EXPLAIN SELECT DISTINCT h.venue_id
        FROM hotel_rooms h INNER JOIN venues v ON v.id = h.venue_id
        WHERE v.category = 'Hotel Room' AND v.status = 'Available'
          AND MD5(CONCAT(v.name, ' - ', h.room_type)) = '00000000000000000000000000000000'
        LIMIT 1001",
    'review_aggregate' => "EXPLAIN SELECT COALESCE(AVG(vr.rating), 0) AS rating_average, COUNT(*) AS rating_count
        FROM venue_reviews vr INNER JOIN bookings b ON b.id = vr.booking_id
        WHERE vr.moderation_status = 'Approved' AND b.booking_status <> 'Cancelled'
          AND COALESCE(b.payment_status, '') <> 'Refunded' AND vr.venue_id IN (0)",
    'review_list' => "EXPLAIN SELECT vr.rating, vr.review_text, vr.created_at, c.first_name, c.last_name
        FROM venue_reviews vr INNER JOIN customers c ON c.id = vr.customer_id
        INNER JOIN bookings b ON b.id = vr.booking_id
        WHERE vr.moderation_status = 'Approved' AND b.booking_status <> 'Cancelled'
          AND COALESCE(b.payment_status, '') <> 'Refunded' AND vr.venue_id IN (0)
        ORDER BY vr.created_at DESC, vr.id DESC LIMIT 3",
];

foreach ($queryChecks as $stage => $sql) {
    $result = $conn->query($sql);
    if (!$result) {
        $state = preg_match('/\A[A-Z0-9]{5}\z/', (string)$conn->sqlstate) === 1 ? $conn->sqlstate : '00000';
        $report(false, 'query_stage', 'stage=' . $stage . '|sqlstate=' . $state . '|code=' . (int)$conn->errno);
        continue;
    }
    $result->free();
    $report(true, 'query_stage', 'stage=' . $stage);
}

$conn->close();
echo ($failures === 0 ? 'PASS' : 'FAIL') . '|summary|failures=' . $failures . PHP_EOL;
exit($failures === 0 ? 0 : 1);
