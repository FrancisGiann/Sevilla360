<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Use POST to load audit logs.']);
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}
$clientCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionCsrf = $_SESSION['csrf_token'] ?? '';
if (!is_string($clientCsrf) || !is_string($sessionCsrf) || $sessionCsrf === '' || !hash_equals($sessionCsrf, $clientCsrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
    exit;
}

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || strlen($rawBody) > 16384) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Request is too large.']);
    exit;
}
try {
    $request = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON request.']);
    exit;
}
if (!is_array($request)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

const MAX_AUDIT_PAGE_SIZE = 100;
$requestedPage = filter_var($request['page'] ?? 1, FILTER_VALIDATE_INT);
$requestedLimit = filter_var($request['limit'] ?? 50, FILTER_VALIDATE_INT);
$page = $requestedPage === false ? 1 : max(1, min(1000000, $requestedPage));
$limit = $requestedLimit === false ? 50 : max(1, min(MAX_AUDIT_PAGE_SIZE, $requestedLimit));
$searchInput = $request['search'] ?? '';
$dateInput = $request['date'] ?? '';
if (!is_string($searchInput) || !is_string($dateInput)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid search filters.']);
    exit;
}
$searchInput = trim(substr($searchInput, 0, 200));
$dateFilter = trim($dateInput);
if ($dateFilter !== '' && (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $dateFilter)
    || !checkdate((int)substr($dateFilter, 5, 2), (int)substr($dateFilter, 8, 2), (int)substr($dateFilter, 0, 4)))) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter a valid audit date.']);
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';

$whereClauses = ['1=1'];
$params = [];
$types = '';
$searchTerms = preg_split('/\s+/', strtolower($searchInput), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$searchTerms = array_slice($searchTerms, 0, 8);
foreach ($searchTerms as $term) {
    $whereClauses[] = "(LOWER(a.action) LIKE ? OR LOWER(a.module) LIKE ? OR LOWER(COALESCE(NULLIF(s.full_name, ''), NULLIF(CONCAT_WS(' ', NULLIF(c.first_name, ''), NULLIF(c.last_name, '')), ''), u.email, 'System')) LIKE ?)";
    $pattern = '%' . $term . '%';
    array_push($params, $pattern, $pattern, $pattern);
    $types .= 'sss';
}
if ($dateFilter !== '') {
    $whereClauses[] = 'DATE(a.created_at) = ?';
    $params[] = $dateFilter;
    $types .= 's';
}
$whereSql = implode(' AND ', $whereClauses);

try {
    $countQuery = "SELECT COUNT(a.id) AS total FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id LEFT JOIN staff s ON u.id = s.user_id LEFT JOIN customers c ON u.id = c.user_id WHERE {$whereSql}";
    $countStmt = $conn->prepare($countQuery);
    if (!$countStmt) throw new RuntimeException('Unable to prepare audit count.');
    if ($params !== []) $countStmt->bind_param($types, ...$params);
    if (!$countStmt->execute()) throw new RuntimeException('Unable to count audit entries.');
    $totalRows = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    $totalPages = max(1, (int)ceil($totalRows / $limit));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $limit;
    $dataQuery = "
        SELECT a.id, a.created_at, a.action, a.module, a.ip_address,
               a.event_type, a.entity_type, a.entity_id,
               (a.details_json IS NOT NULL) AS has_details,
               COALESCE(NULLIF(s.full_name, ''), NULLIF(CONCAT_WS(' ', NULLIF(c.first_name, ''), NULLIF(c.last_name, '')), ''), NULLIF(u.email, ''), 'System') AS staff_name,
               COALESCE(u.role, 'system') AS actor_role
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN staff s ON u.id = s.user_id
        LEFT JOIN customers c ON u.id = c.user_id
        WHERE {$whereSql}
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT ? OFFSET ?
    ";
    $dataStmt = $conn->prepare($dataQuery);
    if (!$dataStmt) throw new RuntimeException('Unable to prepare audit entries.');
    $dataParams = $params;
    $dataParams[] = $limit;
    $dataParams[] = $offset;
    $dataTypes = $types . 'ii';
    $dataStmt->bind_param($dataTypes, ...$dataParams);
    if (!$dataStmt->execute()) throw new RuntimeException('Unable to load audit entries.');

    $logs = [];
    $result = $dataStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $logs[] = [
            'id' => (int)$row['id'],
            'created_at' => (string)$row['created_at'],
            'action' => (string)$row['action'],
            'module' => (string)$row['module'],
            'ip_address' => $row['ip_address'] === null ? null : (string)$row['ip_address'],
            'staff_name' => (string)$row['staff_name'],
            'actor_role' => (string)$row['actor_role'],
            'event_type' => $row['event_type'] === null ? null : (string)$row['event_type'],
            'entity_type' => $row['entity_type'] === null ? null : (string)$row['entity_type'],
            'entity_id' => $row['entity_id'] === null ? null : (int)$row['entity_id'],
            'has_details' => (bool)$row['has_details'],
        ];
    }
    $dataStmt->close();
    $conn->close();

    echo json_encode([
        'success' => true,
        'data' => $logs,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_rows' => $totalRows,
            'limit' => $limit,
        ],
    ], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $exception) {
    error_log('Audit log list retrieval failed: ' . get_class($exception));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Audit logs could not be loaded.']);
}
