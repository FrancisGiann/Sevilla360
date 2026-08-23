<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';

// Auth Guard (Only superadmins can access audit logs)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// CSRF PROTECTION GUARD
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$page = isset($data['page']) ? intval($data['page']) : 1;
$limit = isset($data['limit']) ? intval($data['limit']) : 50;
$offset = ($page - 1) * $limit;

$searchTerms = isset($data['search']) ? array_filter(explode(' ', strtolower(trim($data['search'])))) : [];
$dateFilter = isset($data['date']) ? trim($data['date']) : '';

// Base Query
$where_clauses = ["1=1"];
$params = [];
$types = "";

// Smart Text Search
if (!empty($searchTerms)) {
    foreach ($searchTerms as $term) {
        $where_clauses[] = "(LOWER(a.action) LIKE ? OR LOWER(a.module) LIKE ? OR LOWER(COALESCE(s.full_name, u.email, 'System')) LIKE ?)";
        $searchPattern = '%' . $term . '%';
        $params[] = $searchPattern;
        $params[] = $searchPattern;
        $params[] = $searchPattern;
        $types .= "sss";
    }
}

// Date Filter
if (!empty($dateFilter)) {
    $where_clauses[] = "DATE(a.created_at) = ?";
    $params[] = $dateFilter;
    $types .= "s";
}

$where_sql = implode(' AND ', $where_clauses);

// Count Total Query
$count_query = "
    SELECT COUNT(a.id) as total 
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.id
    LEFT JOIN staff s ON u.id = s.user_id
    WHERE $where_sql
";
$stmt_count = $conn->prepare($count_query);
if (!empty($params)) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$total_rows = $stmt_count->get_result()->fetch_assoc()['total'];
$stmt_count->close();

$total_pages = ceil($total_rows / $limit);
if ($total_pages == 0) $total_pages = 1;

// Fetch Data Query
$data_query = "
    SELECT 
        a.created_at, 
        a.action, 
        a.module, 
        a.ip_address, 
        COALESCE(s.full_name, u.email, 'System') as staff_name
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.id
    LEFT JOIN staff s ON u.id = s.user_id
    WHERE $where_sql
    ORDER BY a.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt_data = $conn->prepare($data_query);
$params[] = $limit;
$params[] = $offset;
$types .= "ii";
$stmt_data->bind_param($types, ...$params);
$stmt_data->execute();
$result = $stmt_data->get_result();

$logs = [];
while ($row = $result->fetch_assoc()) {
    $logs[] = $row;
}
$stmt_data->close();

echo json_encode([
    'success' => true,
    'data' => $logs,
    'pagination' => [
        'current_page' => $page,
        'total_pages' => $total_pages,
        'total_rows' => $total_rows,
        'limit' => $limit
    ]
]);
