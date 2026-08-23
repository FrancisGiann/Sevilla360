<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/request_context.php';

// Security check
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['staff', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// ==========================================
// CSRF PROTECTION GUARD (JSON)
// ==========================================
$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF validation failed. Unauthorized request.']);
    exit;
}

$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

if (!isset($data['id'])) {
    echo json_encode(['success' => false, 'message' => 'Maintenance ID missing.']);
    exit;
}

$maint_id = intval($data['id']);

try {
    $conn->begin_transaction();

    // 1. Get the details of the maintenance so we can find the matching dummy booking
    $stmt_get = $conn->prepare("
        SELECT m.venue_id, m.start_date, m.end_date, m.is_blocking, v.name as venue_name 
        FROM maintenance m
        LEFT JOIN venues v ON m.venue_id = v.id
        WHERE m.id = ?
    ");
    $stmt_get->bind_param("i", $maint_id);
    $stmt_get->execute();
    $res = $stmt_get->get_result();

    if ($res->num_rows === 0) throw new Exception("Maintenance record not found.");
    $maint = $res->fetch_assoc();
    $v_name = $maint['venue_name'] ?? 'Unknown Venue';

    // 2. If it was blocking, delete the associated dummy booking lock
    if ($maint['is_blocking']) {
        $stmt_del_book = $conn->prepare("DELETE FROM bookings WHERE source = 'Maintenance' AND venue_id = ? AND start_date = ? AND end_date = ?");
        $stmt_del_book->bind_param("iss", $maint['venue_id'], $maint['start_date'], $maint['end_date']);
        $stmt_del_book->execute();
    }

    // 3. Delete the maintenance record itself
    $stmt_del_maint = $conn->prepare("DELETE FROM maintenance WHERE id = ?");
    $stmt_del_maint->bind_param("i", $maint_id);
    $stmt_del_maint->execute();

    // 4. Audit Log
    if (isset($_SESSION['user_id'])) {
        $log_user = $_SESSION['user_id'];
        $log_module = 'Maintenance';
        $log_action = "Cancelled maintenance for $v_name ({$maint['start_date']} to {$maint['end_date']})"; 
        $log_ip = request_client_ip();

        $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, ?, ?, ?)");
        $audit_stmt->bind_param("isss", $log_user, $log_module, $log_action, $log_ip);
        $audit_stmt->execute();
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Maintenance cancelled successfully.']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
