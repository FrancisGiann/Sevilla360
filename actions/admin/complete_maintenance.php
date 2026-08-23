<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/request_context.php';

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

$data = json_decode(file_get_contents('php://input'), true);
$maint_id = intval($data['id'] ?? 0);

if (!$maint_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid maintenance ID.']);
    exit;
}

try {
    $conn->begin_transaction();

    // 1. Get maintenance details
    $stmt_get = $conn->prepare("
        SELECT m.venue_id, m.start_date, m.end_date, m.is_blocking, m.status, v.name as venue_name
        FROM maintenance m
        LEFT JOIN venues v ON m.venue_id = v.id
        WHERE m.id = ?
    ");
    $stmt_get->bind_param("i", $maint_id);
    $stmt_get->execute();
    $maint = $stmt_get->get_result()->fetch_assoc();

    if (!$maint) {
        throw new Exception("Maintenance record not found.");
    }
    if ($maint['status'] !== 'Scheduled') {
        throw new Exception("Maintenance is already completed or cancelled.");
    }
    $v_name = $maint['venue_name'] ?? 'Unknown Venue';

    // 2. Mark the record completed without changing its historical date range.
    $stmt_maint = $conn->prepare("
        UPDATE maintenance 
        SET status = 'Completed', 
            completed_at = NOW()
        WHERE id = ?
    ");
    $stmt_maint->bind_param("i", $maint_id);
    $stmt_maint->execute();

    // 3. Remove a legacy fake booking lock, if one still exists.
    if ($maint['is_blocking']) {
        $stmt_book = $conn->prepare("DELETE FROM bookings WHERE source = 'Maintenance' AND venue_id = ? AND start_date = ? AND end_date = ?");
        $stmt_book->bind_param("iss", $maint['venue_id'], $maint['start_date'], $maint['end_date']);
        $stmt_book->execute();
    }

    // 4. Audit Log
    if (isset($_SESSION['user_id'])) {
        $log_user = $_SESSION['user_id'];
        $log_module = 'Maintenance';
        $log_action = "Marked maintenance as Completed for $v_name"; 
        $log_ip = request_client_ip();

        $audit_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, ?, ?, ?)");
        $audit_stmt->bind_param("isss", $log_user, $log_module, $log_action, $log_ip);
        $audit_stmt->execute();
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Maintenance marked as completed.']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
