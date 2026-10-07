<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/booking_lifecycle.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['staff', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}
$clientToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$clientToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
    exit;
}

$decoded = json_decode(file_get_contents('php://input') ?: '', true);
$data = is_array($decoded) ? $decoded : [];
$pageValue = filter_var($data['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$limitValue = filter_var($data['limit'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = $pageValue === false ? 1 : min(1000000, (int)$pageValue);
$limit = $limitValue === false ? 10 : min(100, (int)$limitValue);
$offset = ($page - 1) * $limit;
$searchTerm = is_string($data['search'] ?? null) ? trim($data['search']) : '';
$search = '%' . $searchTerm . '%';
$venueFilter = $data['venue'] ?? 'All';
if (!is_string($venueFilter) || !in_array($venueFilter, ['All', 'Event Hall', 'Hotel Room', 'Resort Villa'], true)) $venueFilter = 'All';
$statusFilter = $data['status'] ?? 'all';
if (!is_string($statusFilter) || !in_array($statusFilter, ['all', 'action_req', 'awaiting_verification', 'partial', 'confirmed', 'completed', 'cancelled', 'pending'], true)) $statusFilter = 'all';
$bookingCompletionSql = booking_completion_sql('b');
$bookingWhere = [
    "(b.reference_no LIKE ? OR CAST(b.id AS CHAR) LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT_WS(' ', c.first_name, c.last_name) LIKE ? OR v.name LIKE ?)",
    "b.reference_no NOT LIKE 'MAINT-%'",
    "COALESCE(b.source, '') <> 'Maintenance'",
    "COALESCE(c.last_name, '') <> 'MAINTENANCE'",
];
$bookingParams = [$search, $search, $search, $search, $search, $search];
$bookingTypes = 'ssssss';
$seminarWhere = [
    "(CONCAT('SEM-', s.id) LIKE ? OR CAST(s.id AS CHAR) LIKE ? OR s.name LIKE ? OR v.name LIKE ?)",
];
$seminarParams = [$search, $search, $search, $search];
$seminarTypes = 'ssss';

if (array_key_exists('booking_id', $data)) {
    $bookingId = filter_var($data['booking_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($bookingId === false) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid booking ID is required.']);
        exit;
    }
    $bookingWhere[] = 'b.id = ?';
    $bookingParams[] = (int)$bookingId;
    $bookingTypes .= 'i';
    $seminarWhere[] = '1=0';
}

if ($venueFilter !== 'All') {
    $bookingWhere[] = 'v.category = ?';
    $bookingParams[] = $venueFilter;
    $bookingTypes .= 's';
    if ($venueFilter === 'Event Hall') $seminarWhere[] = "v.category = 'Event Hall'";
    else $seminarWhere[] = '1=0';
}

$paidSeminarSql = "COALESCE((SELECT SUM(sp.amount) FROM seminar_payments sp WHERE sp.seminar_id=s.id AND sp.status='posted'),0)";
$historyText = static fn(string $expression): string => "CONVERT(({$expression}) USING utf8mb4) COLLATE utf8mb4_unicode_ci";
switch ($statusFilter) {
    case 'action_req':
        $bookingWhere[] = "b.booking_status <> 'Cancelled' AND NOT $bookingCompletionSql AND (EXISTS (SELECT 1 FROM cancellations cx WHERE cx.booking_id=b.id AND cx.status='Pending') OR EXISTS (SELECT 1 FROM reschedule_requests rr WHERE rr.booking_id=b.id AND rr.status='Pending') OR b.booking_status='Pending' OR (b.booking_status='Confirmed' AND b.payment_status='Unpaid') OR EXISTS (SELECT 1 FROM manual_payment_submissions mps_action WHERE mps_action.booking_id=b.id AND mps_action.status='pending'))";
        $seminarWhere[] = "s.status <> 'cancelled' AND (s.agreed_price IS NULL OR $paidSeminarSql < s.agreed_price)";
        break;
    case 'awaiting_verification':
        $bookingWhere[] = "EXISTS (SELECT 1 FROM manual_payment_submissions mps_filter WHERE mps_filter.booking_id=b.id AND mps_filter.status='pending')";
        $seminarWhere[] = '1=0';
        break;
    case 'partial':
        $bookingWhere[] = "b.booking_status='Confirmed' AND NOT $bookingCompletionSql AND b.payment_status IN ('Partial','Unpaid')";
        $seminarWhere[] = "s.status <> 'cancelled' AND s.agreed_price IS NOT NULL AND $paidSeminarSql > 0 AND $paidSeminarSql < s.agreed_price";
        break;
    case 'confirmed':
        $bookingWhere[] = "b.booking_status='Confirmed' AND NOT $bookingCompletionSql";
        $seminarWhere[] = "s.status='finalized'";
        break;
    case 'completed':
        $bookingWhere[] = $bookingCompletionSql;
        $seminarWhere[] = '1=0';
        break;
    case 'cancelled':
        $bookingWhere[] = "b.booking_status='Cancelled' AND NOT $bookingCompletionSql";
        $seminarWhere[] = "s.status='cancelled'";
        break;
    case 'pending':
        $bookingWhere[] = "b.booking_status='Pending' AND NOT $bookingCompletionSql";
        $seminarWhere[] = "s.status='draft'";
        break;
}
$bookingWhereSql = implode(' AND ', $bookingWhere);
$seminarWhereSql = implode(' AND ', $seminarWhere);

$bind = static function (mysqli_stmt $statement, string $types, array &$params): void {
    $arguments = [$types];
    foreach ($params as $index => $_value) $arguments[] = &$params[$index];
    call_user_func_array([$statement, 'bind_param'], $arguments);
};

try {
    $countSql = "SELECT
        (SELECT COUNT(DISTINCT b.id) FROM bookings b JOIN customers c ON c.id=b.customer_id JOIN venues v ON v.id=b.venue_id WHERE {$bookingWhereSql})
        +
        (SELECT COUNT(DISTINCT s.id) FROM seminars s JOIN venues v ON v.id=s.hall_venue_id WHERE {$seminarWhereSql}) AS total";
    $countParams = array_merge($bookingParams, $seminarParams);
    $countTypes = $bookingTypes . $seminarTypes;
    $countStatement = $conn->prepare($countSql);
    if (!$countStatement) throw new RuntimeException('Unable to prepare history count.');
    $bind($countStatement, $countTypes, $countParams);
    if (!$countStatement->execute()) { $countStatement->close(); throw new RuntimeException('Unable to count booking and seminar history.'); }
    $totalRows = (int)($countStatement->get_result()->fetch_assoc()['total'] ?? 0);
    $countStatement->close();
    $totalPages = max(1, (int)ceil($totalRows / $limit));
    if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $limit; }

    $dataSql = "SELECT * FROM (
        SELECT {$historyText("'booking'")} AS record_type, b.id, {$historyText('b.reference_no')} AS reference_no, b.venue_id, b.start_date, b.end_date,
            b.total_amount, b.amount_paid, {$historyText('b.booking_status')} AS booking_status,
            {$historyText("CASE WHEN {$bookingCompletionSql} THEN 'Completed' ELSE b.booking_status END")} AS display_booking_status,
            {$historyText('b.payment_status')} AS payment_status, {$historyText("COALESCE(c.first_name, '')")} AS first_name,
            {$historyText("COALESCE(c.last_name, '')")} AS last_name, {$historyText('v.name')} AS venue_name,
            {$historyText('v.category')} AS venue_category, {$historyText('hr.room_type')} AS hotel_room_type,
            {$historyText('cx.status')} AS cancel_status, {$historyText('cx.reason')} AS cancel_reason,
            cx.fee_percent AS cancel_fee_percent, cx.fee_deducted AS cancel_fee, cx.refund_amount AS cancel_refund,
            {$historyText('rr.status')} AS resched_status, rr.new_start_date, rr.new_end_date, {$historyText('rr.reason')} AS resched_reason,
            EXISTS (SELECT 1 FROM reschedule_requests rr_done WHERE rr_done.booking_id=b.id AND rr_done.status='Approved') AS has_rescheduled,
            (SELECT mps.id FROM manual_payment_submissions mps WHERE mps.booking_id=b.id AND mps.status='pending' ORDER BY mps.id DESC LIMIT 1) AS pending_payment_submission_id,
            {$historyText("(SELECT mps.payment_method FROM manual_payment_submissions mps WHERE mps.booking_id=b.id AND mps.status='pending' ORDER BY mps.id DESC LIMIT 1)")} AS pending_payment_method,
            (SELECT mps.expected_amount FROM manual_payment_submissions mps WHERE mps.booking_id=b.id AND mps.status='pending' ORDER BY mps.id DESC LIMIT 1) AS pending_payment_expected_amount,
            {$historyText("(SELECT mps.transaction_reference FROM manual_payment_submissions mps WHERE mps.booking_id=b.id AND mps.status='pending' ORDER BY mps.id DESC LIMIT 1)")} AS pending_payment_reference,
            {$historyText("''")} AS seminar_name, 0 AS attendee_count,
            CASE WHEN EXISTS (SELECT 1 FROM manual_payment_submissions mps_order WHERE mps_order.booking_id=b.id AND mps_order.status='pending') THEN 2 WHEN cx.status='Pending' OR rr.status='Pending' THEN 1 ELSE 0 END AS record_priority
        FROM bookings b
        JOIN customers c ON c.id=b.customer_id
        JOIN venues v ON v.id=b.venue_id
        LEFT JOIN cancellations cx ON cx.booking_id=b.id AND cx.status='Pending'
        LEFT JOIN hotel_rooms hr ON hr.venue_id=v.id
        LEFT JOIN reschedule_requests rr ON rr.booking_id=b.id AND rr.status='Pending'
        WHERE {$bookingWhereSql}
        GROUP BY b.id

        UNION ALL

        SELECT {$historyText("'seminar'")} AS record_type, s.id, {$historyText("CONCAT('SEM-',s.id)")} AS reference_no, s.hall_venue_id AS venue_id,
            s.hall_start_date AS start_date, s.hall_end_date AS end_date, s.agreed_price AS total_amount,
            {$paidSeminarSql} AS amount_paid, {$historyText('s.status')} AS booking_status,
            {$historyText("CASE s.status WHEN 'draft' THEN 'Draft' WHEN 'finalized' THEN 'Finalized' ELSE 'Cancelled' END")} AS display_booking_status,
            {$historyText("CASE WHEN s.agreed_price IS NULL THEN 'Unpriced' WHEN {$paidSeminarSql} >= s.agreed_price THEN 'Paid' WHEN {$paidSeminarSql} > 0 THEN 'Partial' ELSE 'Unpaid' END")} AS payment_status,
            {$historyText("''")} AS first_name, {$historyText("''")} AS last_name, {$historyText('v.name')} AS venue_name,
            {$historyText('v.category')} AS venue_category, {$historyText('NULL')} AS hotel_room_type,
            {$historyText('NULL')} AS cancel_status, {$historyText('NULL')} AS cancel_reason, NULL AS cancel_fee_percent, NULL AS cancel_fee, NULL AS cancel_refund,
            {$historyText('NULL')} AS resched_status, NULL AS new_start_date, NULL AS new_end_date, {$historyText('NULL')} AS resched_reason,
            0 AS has_rescheduled, NULL AS pending_payment_submission_id, NULL AS pending_payment_method,
            NULL AS pending_payment_expected_amount, NULL AS pending_payment_reference,
            {$historyText('s.name')} AS seminar_name, (SELECT COUNT(*) FROM seminar_attendees sa WHERE sa.seminar_id=s.id) AS attendee_count,
            CASE WHEN s.status <> 'cancelled' AND (s.agreed_price IS NULL OR {$paidSeminarSql} < s.agreed_price) THEN 1 ELSE 0 END AS record_priority
        FROM seminars s JOIN venues v ON v.id=s.hall_venue_id
        WHERE {$seminarWhereSql}
    ) AS history
    ORDER BY record_priority DESC, start_date DESC, record_type ASC, id DESC
    LIMIT ? OFFSET ?";
    $queryParams = array_merge($bookingParams, $seminarParams, [$limit, $offset]);
    $queryTypes = $bookingTypes . $seminarTypes . 'ii';
    $statement = $conn->prepare($dataSql);
    if (!$statement) throw new RuntimeException('Unable to prepare history page.');
    $bind($statement, $queryTypes, $queryParams);
    if (!$statement->execute()) { $statement->close(); throw new RuntimeException('Unable to load booking and seminar history.'); }
    $result = $statement->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $statement->close();

    echo json_encode([
        'success' => true,
        'data' => $rows,
        'pagination' => ['current_page' => $page, 'total_pages' => $totalPages, 'total_rows' => $totalRows, 'limit' => $limit],
    ]);
} catch (Throwable $error) {
    error_log('Booking and seminar history query failed: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load booking and seminar history.']);
}
?>
