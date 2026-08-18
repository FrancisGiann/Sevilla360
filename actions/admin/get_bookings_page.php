<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';

// Auth Guard
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'staff' && $_SESSION['role'] !== 'admin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// CSRF PROTECTION GUARD (JSON)
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$page = isset($data['page']) ? intval($data['page']) : 1;
$limit = isset($data['limit']) ? intval($data['limit']) : 10;
$offset = ($page - 1) * $limit;

$search = isset($data['search']) ? '%' . $data['search'] . '%' : '%';
$venueFilter = isset($data['venue']) ? $data['venue'] : 'All';
$statusFilter = isset($data['status']) ? $data['status'] : 'all';

// Base Query
$where_clauses = ["(b.reference_no LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR v.name LIKE ?)"];
$params = [$search, $search, $search, $search];
$types = "ssss";

if ($venueFilter !== 'All') {
    $where_clauses[] = "v.category = ?";
    $params[] = $venueFilter;
    $types .= "s";
}

// Status Filtering Logic
if ($statusFilter === 'action_req') {
    $where_clauses[] = "b.booking_status != 'Cancelled' AND (cx.status = 'Pending' OR rr.status = 'Pending' OR b.booking_status = 'Pending' OR (b.booking_status = 'Confirmed' AND b.payment_status = 'Unpaid'))";
} elseif ($statusFilter === 'partial') {
    $where_clauses[] = "(b.booking_status = 'Confirmed' AND b.payment_status IN ('Partial', 'Unpaid'))";
} elseif ($statusFilter === 'confirmed') {
    $where_clauses[] = "b.booking_status = 'Confirmed'";
} elseif ($statusFilter === 'cancelled') {
    $where_clauses[] = "b.booking_status = 'Cancelled'";
} elseif ($statusFilter === 'pending') {
    $where_clauses[] = "b.booking_status = 'Pending'";
}

$where_sql = implode(' AND ', $where_clauses);

try {
    // 1. Get Total Row Count (for pagination math)
    $count_sql = "
        SELECT COUNT(b.id) as total 
        FROM bookings b
        JOIN customers c ON b.customer_id = c.id
        JOIN venues v ON b.venue_id = v.id
        LEFT JOIN cancellations cx ON b.id = cx.booking_id AND cx.status = 'Pending'
        LEFT JOIN reschedule_requests rr ON b.id = rr.booking_id AND rr.status = 'Pending'
        WHERE $where_sql
    ";
    
    $stmt_count = $conn->prepare($count_sql);
    if (!empty($params)) $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_rows = $stmt_count->get_result()->fetch_assoc()['total'];
    $total_pages = ceil($total_rows / $limit);

    // 2. Fetch the actual paginated data
    $data_sql = "
        SELECT 
            b.id, b.reference_no, b.venue_id, b.start_date, b.end_date, b.total_amount, b.amount_paid, b.booking_status, b.payment_status,
            c.first_name, c.last_name, 
            v.name AS venue_name, v.category AS venue_category,
            hr.room_type AS hotel_room_type,
            cx.status AS cancel_status, cx.reason AS cancel_reason,
            rr.status AS resched_status, rr.new_start_date, rr.new_end_date, rr.reason AS resched_reason
        FROM bookings b
        JOIN customers c ON b.customer_id = c.id
        JOIN venues v ON b.venue_id = v.id
        LEFT JOIN cancellations cx ON b.id = cx.booking_id AND cx.status = 'Pending'
        LEFT JOIN hotel_rooms hr ON v.id = hr.venue_id
        LEFT JOIN reschedule_requests rr ON b.id = rr.booking_id AND rr.status = 'Pending'
        WHERE $where_sql
        GROUP BY b.id
        ORDER BY 
            CASE 
                WHEN cx.status = 'Pending' THEN 1 
                WHEN rr.status = 'Pending' THEN 1 
                ELSE 0 
            END DESC, 
            b.id DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt_data = $conn->prepare($data_sql);
    
    // Append limit and offset to params
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt_data->bind_param($types, ...$params);
    $stmt_data->execute();
    $result = $stmt_data->get_result();
    
    $bookings = [];
    while($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }

    echo json_encode([
        'success' => true, 
        'data' => $bookings,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $total_pages,
            'total_rows' => $total_rows,
            'limit' => $limit
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>