<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/booking_lifecycle.php';
$booking_completion_sql = booking_completion_sql('b');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['staff', 'admin'], true)) {
    http_response_code(403);
    exit('Unauthorized access.');
}

$searchTerm = trim((string)($_GET['search'] ?? ''));
$venueFilter = (string)($_GET['venue'] ?? 'All');
$statusFilter = (string)($_GET['status'] ?? 'all');
$search = '%' . $searchTerm . '%';
$where = [
    "(b.reference_no LIKE ? OR CAST(b.id AS CHAR) LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT_WS(' ', c.first_name, c.last_name) LIKE ? OR v.name LIKE ?)",
    "b.reference_no NOT LIKE 'MAINT-%'",
    "b.source <> 'Maintenance'",
    "c.last_name <> 'MAINTENANCE'"
];
$params = [$search, $search, $search, $search, $search, $search];
$types = 'ssssss';
$seminarWhere = ["(CONCAT('SEM-',s.id) LIKE ? OR CAST(s.id AS CHAR) LIKE ? OR s.name LIKE ? OR v.name LIKE ?)"];
$seminarParams = [$search, $search, $search, $search];
$seminarTypes = 'ssss';

if (in_array($venueFilter, ['Event Hall', 'Hotel Room', 'Resort Villa'], true)) {
    $where[] = 'v.category = ?';
    $params[] = $venueFilter;
    $types .= 's';
    if ($venueFilter === 'Event Hall') $seminarWhere[] = "v.category='Event Hall'";
    else $seminarWhere[] = '1=0';
}
$paidSeminarSql = "COALESCE((SELECT SUM(sp.amount) FROM seminar_payments sp WHERE sp.seminar_id=s.id AND sp.status='posted'),0)";
switch ($statusFilter) {
    case 'action_req':
        $where[] = "b.booking_status <> 'Cancelled' AND NOT $booking_completion_sql AND (EXISTS (SELECT 1 FROM cancellations cx WHERE cx.booking_id = b.id AND cx.status = 'Pending') OR EXISTS (SELECT 1 FROM reschedule_requests rr WHERE rr.booking_id = b.id AND rr.status = 'Pending') OR b.booking_status = 'Pending' OR (b.booking_status = 'Confirmed' AND b.payment_status = 'Unpaid') OR EXISTS (SELECT 1 FROM manual_payment_submissions mps WHERE mps.booking_id=b.id AND mps.status='pending'))";
        $seminarWhere[] = "s.status <> 'cancelled' AND (s.agreed_price IS NULL OR $paidSeminarSql < s.agreed_price)";
        break;
    case 'awaiting_verification':
        $where[] = "EXISTS (SELECT 1 FROM manual_payment_submissions mps WHERE mps.booking_id=b.id AND mps.status='pending')";
        $seminarWhere[] = '1=0';
        break;
    case 'partial':
        $where[] = "b.booking_status = 'Confirmed' AND NOT $booking_completion_sql AND b.payment_status IN ('Partial', 'Unpaid')";
        $seminarWhere[] = "s.status <> 'cancelled' AND s.agreed_price IS NOT NULL AND $paidSeminarSql > 0 AND $paidSeminarSql < s.agreed_price";
        break;
    case 'pending':
        $where[] = "b.booking_status = 'Pending' AND NOT $booking_completion_sql";
        $seminarWhere[] = "s.status='draft'";
        break;
    case 'confirmed':
        $where[] = "b.booking_status = 'Confirmed' AND NOT $booking_completion_sql";
        $seminarWhere[] = "s.status='finalized'";
        break;
    case 'completed':
        $where[] = $booking_completion_sql;
        $seminarWhere[] = '1=0';
        break;
    case 'cancelled':
        $where[] = "b.booking_status = 'Cancelled' AND NOT $booking_completion_sql";
        $seminarWhere[] = "s.status='cancelled'";
        break;
}

$sql = "SELECT * FROM (
        SELECT 'Booking' AS record_type, b.reference_no, CONCAT_WS(' ', c.first_name, c.last_name) AS customer_name,
               v.name AS venue_name, v.category AS venue_category, b.start_date, b.end_date,
               b.guests_count, b.total_amount, b.amount_paid,
               CASE WHEN $booking_completion_sql THEN 'Completed' ELSE b.booking_status END AS booking_status,
               b.payment_status, b.id AS record_id
        FROM bookings b
        INNER JOIN customers c ON c.id = b.customer_id
        INNER JOIN venues v ON v.id = b.venue_id
        WHERE " . implode(' AND ', $where) . "
        UNION ALL
        SELECT 'Seminar' AS record_type, CONCAT('SEM-',s.id) AS reference_no, CONCAT('Staff managed seminar · ',s.name) AS customer_name,
               CONCAT('Event Hall · ',v.name) AS venue_name, 'Seminar' AS venue_category,
               s.hall_start_date AS start_date, s.hall_end_date AS end_date,
               (SELECT COUNT(*) FROM seminar_attendees a WHERE a.seminar_id=s.id) AS guests_count,
               s.agreed_price AS total_amount, $paidSeminarSql AS amount_paid,
               CASE s.status WHEN 'draft' THEN 'Draft' WHEN 'finalized' THEN 'Finalized' ELSE 'Cancelled' END AS booking_status,
               CASE WHEN s.agreed_price IS NULL THEN 'Unpriced' WHEN $paidSeminarSql >= s.agreed_price THEN 'Paid' WHEN $paidSeminarSql > 0 THEN 'Partial' ELSE 'Unpaid' END AS payment_status,
               s.id AS record_id
        FROM seminars s JOIN venues v ON v.id=s.hall_venue_id
        WHERE " . implode(' AND ', $seminarWhere) . "
    ) AS history ORDER BY start_date DESC, record_type ASC, record_id DESC";
$allParams = array_merge($params, $seminarParams);
$allTypes = $types . $seminarTypes;
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit('Unable to prepare export.');
}
$bind = [$allTypes];
foreach ($allParams as $key => $value) $bind[] = &$allParams[$key];
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
fputcsv($output, ['Record type', 'Reference', 'Customer / seminar', 'Venue', 'Category', 'Start date', 'End date', 'Guests', 'Agreed / total amount', 'Amount paid', 'Booking status', 'Payment status']);
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    fputcsv($output, array_map('csv_safe_value', [
        $row['record_type'], $row['reference_no'], $row['customer_name'], $row['venue_name'], $row['venue_category'],
        $row['start_date'], $row['end_date'], $row['guests_count'], $row['total_amount'],
        $row['amount_paid'], $row['booking_status'], $row['payment_status']
    ]));
}
fclose($output);
