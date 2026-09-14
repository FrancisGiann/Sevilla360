<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Use POST to load audit details.']);
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
if (!is_string($rawBody) || strlen($rawBody) > 4096) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Request is too large.']);
    exit;
}
try {
    $request = json_decode($rawBody, true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON request.']);
    exit;
}
$auditId = is_array($request) ? filter_var($request['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
if ($auditId === false) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid audit ID is required.']);
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/audit_log_details.php';

try {
    $statement = $conn->prepare("SELECT a.id, a.user_id, a.created_at, a.action, a.module, a.ip_address, a.event_type, a.entity_type, a.entity_id, a.details_json, COALESCE(NULLIF(s.full_name, ''), NULLIF(CONCAT_WS(' ', NULLIF(c.first_name, ''), NULLIF(c.last_name, '')), ''), NULLIF(u.email, ''), 'System') AS actor_name, COALESCE(u.role, 'system') AS actor_role FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id LEFT JOIN staff s ON u.id = s.user_id LEFT JOIN customers c ON u.id = c.user_id WHERE a.id = ? LIMIT 1");
    if (!$statement) throw new RuntimeException('Unable to prepare audit detail lookup.');
    $statement->bind_param('i', $auditId);
    if (!$statement->execute()) throw new RuntimeException('Unable to load audit entry.');
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!$row) {
        $conn->close();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Audit entry not found.']);
        exit;
    }

    $eventType = $row['event_type'] === null ? null : (string)$row['event_type'];
    $entityType = $row['entity_type'] === null ? null : (string)$row['entity_type'];
    $entityId = filter_var($row['entity_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $detail = [
        'id' => (int)$row['id'],
        'created_at' => (string)$row['created_at'],
        'actor_name' => (string)$row['actor_name'],
        'actor_role' => (string)$row['actor_role'],
        'action' => (string)$row['action'],
        'module' => (string)$row['module'],
        'ip_address' => $row['ip_address'] === null ? null : (string)$row['ip_address'],
        'event_type' => $eventType,
        'entity_type' => $entityType,
        'entity_id' => $entityId === false ? null : $entityId,
        'details' => audit_log_safe_details($row['details_json'] === null ? null : (string)$row['details_json'], $eventType),
    ];
    $conn->close();
    echo json_encode(['success' => true, 'data' => $detail], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $exception) {
    error_log('Audit log detail retrieval failed: ' . get_class($exception));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Audit details could not be loaded.']);
}
