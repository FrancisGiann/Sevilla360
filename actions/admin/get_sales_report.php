<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';
header('Content-Type: application/json; charset=UTF-8');

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrator access is required.']);
    exit;
}
$tz = new DateTimeZone('Asia/Manila');
$today = new DateTimeImmutable('today', $tz);
$startInput = $_GET['start_date'] ?? $today->modify('first day of this month')->format('Y-m-d');
$endInput = $_GET['end_date'] ?? $today->format('Y-m-d');
$validDate = static function ($value): ?DateTimeImmutable {
    if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value)) return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) return null;
    return $date;
};
$start = $validDate($startInput);
$end = $validDate($endInput);
if (!$start || !$end || $start > $end) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Dates must use YYYY-MM-DD and start_date must be on or before end_date.']);
    exit;
}
$daysRequested = $start->diff($end)->days + 1;
if ($daysRequested > 366) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'The report range cannot exceed 366 days.']);
    exit;
}

$endExclusive = $end->modify('+1 day')->format('Y-m-d');
$stmt = $conn->prepare("SELECT DATE(p.payment_date) AS payment_day, COUNT(*) AS payment_count, COALESCE(SUM(p.amount), 0) AS total
    FROM payments p INNER JOIN bookings b ON b.id = p.booking_id
    WHERE p.status = 'Success' AND b.booking_status <> 'Cancelled'
      AND p.payment_date >= ? AND p.payment_date < ?
    GROUP BY DATE(p.payment_date) ORDER BY payment_day");
$startValue = $start->format('Y-m-d');
$stmt->bind_param('ss', $startValue, $endExclusive);
$stmt->execute();
$result = $stmt->get_result();
$byDay = [];
$total = 0.0;
$count = 0;
while ($row = $result->fetch_assoc()) {
    $amount = (float)$row['total'];
    $number = (int)$row['payment_count'];
    $byDay[$row['payment_day']] = ['date' => $row['payment_day'], 'payment_count' => $number, 'total' => $amount];
    $total += $amount;
    $count += $number;
}
$stmt->close();
$days = [];
for ($i = 0; $i < $daysRequested; $i++) {
    $date = $start->modify("+{$i} days")->format('Y-m-d');
    $days[] = $byDay[$date] ?? ['date' => $date, 'payment_count' => 0, 'total' => 0.0];
}
echo json_encode([
    'success' => true,
    'range' => ['start_date' => $startValue, 'end_date' => $end->format('Y-m-d'), 'days' => $daysRequested],
    'totals' => ['total' => round($total, 2), 'payment_count' => $count, 'average' => $count > 0 ? round($total / $count, 2) : 0.0],
    'days' => $days,
], JSON_UNESCAPED_SLASHES);
