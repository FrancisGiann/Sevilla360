<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['staff', 'admin'], true)) {
    http_response_code(403);
    exit('Unauthorized access.');
}

$searchTerm = trim((string)($_GET['search'] ?? ''));
$venueFilter = (string)($_GET['venue'] ?? 'All');
$statusFilter = (string)($_GET['status'] ?? 'all');
$search = '%' . $searchTerm . '%';
$where = [
    "(b.reference_no LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT_WS(' ', c.first_name, c.last_name) LIKE ? OR v.name LIKE ?)",
    "b.reference_no NOT LIKE 'MAINT-%'",
    "b.source <> 'Maintenance'",
    "c.last_name <> 'MAINTENANCE'"
];
$params = [$search, $search, $search, $search, $search];
$types = 'sssss';

if (in_array($venueFilter, ['Event Hall', 'Hotel Room', 'Resort Villa'], true)) {
    $where[] = 'v.category = ?';
    $params[] = $venueFilter;
    $types .= 's';
}
switch ($statusFilter) {
    case 'action_req':
        $where[] = "b.booking_status <> 'Cancelled' AND (EXISTS (SELECT 1 FROM cancellations cx WHERE cx.booking_id = b.id AND cx.status = 'Pending') OR EXISTS (SELECT 1 FROM reschedule_requests rr WHERE rr.booking_id = b.id AND rr.status = 'Pending') OR b.booking_status = 'Pending' OR (b.booking_status = 'Confirmed' AND b.payment_status = 'Unpaid'))";
        break;
    case 'partial':
        $where[] = "b.booking_status = 'Confirmed' AND b.payment_status IN ('Partial', 'Unpaid')";
        break;
    case 'pending':
        $where[] = "b.booking_status = 'Pending'";
        break;
    case 'confirmed':
        $where[] = "b.booking_status = 'Confirmed'";
        break;
    case 'cancelled':
        $where[] = "b.booking_status = 'Cancelled'";
        break;
}

$sql = "SELECT b.reference_no, CONCAT_WS(' ', c.first_name, c.last_name) AS customer_name,
               v.name AS venue_name, v.category AS venue_category, b.start_date, b.end_date,
               b.guests_count, b.total_amount, b.amount_paid, b.booking_status, b.payment_status
        FROM bookings b
        INNER JOIN customers c ON c.id = b.customer_id
        INNER JOIN venues v ON v.id = b.venue_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY b.id DESC";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit('Unable to prepare export.');
}
$bind = [$types];
foreach ($params as $key => $value) $bind[] = &$params[$key];
call_user_func_array([$stmt, 'bind_param'], $bind);
if (!$stmt->execute()) {
    http_response_code(500);
    exit('Unable to generate export.');
}

function csv_safe_value($value): string
{
    $text = (string)$value;
    // Spreadsheet formula parsing can still be triggered when a dangerous
    // character is preceded by spaces, tabs, newlines, or Unicode control /
    // separator characters. Preserve the original value, but prefix an
    // apostrophe before any such value so it is imported as text.
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
    if ($trimmed !== null && $trimmed !== '' && strpos('=+-@', $trimmed[0]) !== false) {
        $text = "'" . $text;
    }
    return $text;
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="sevilla360-bookings-' . date('Y-m-d') . '.csv"');
$output = fopen('php://output', 'wb');
fputcsv($output, ['Reference', 'Customer', 'Venue', 'Category', 'Start date', 'End date', 'Guests', 'Total amount', 'Amount paid', 'Booking status', 'Payment status']);
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    fputcsv($output, array_map('csv_safe_value', [
        $row['reference_no'], $row['customer_name'], $row['venue_name'], $row['venue_category'],
        $row['start_date'], $row['end_date'], $row['guests_count'], $row['total_amount'],
        $row['amount_paid'], $row['booking_status'], $row['payment_status']
    ]));
}
fclose($output);
