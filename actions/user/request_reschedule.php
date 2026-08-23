<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once '../../config/db_connect.php';
require_once '../../includes/request_context.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
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

if (!isset($data['booking_id']) || !isset($data['new_start_date']) || !isset($data['reason'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required data.']);
    exit;
}

$booking_id = intval($data['booking_id']);
$new_start = $data['new_start_date'];
$new_end = isset($data['new_end_date']) ? $data['new_end_date'] : $new_start;
$reason = trim($data['reason']);

// Security: Verify this booking belongs to the logged-in user and fetch details
$stmt_check = $conn->prepare("
    SELECT b.id, b.start_date, b.end_date, b.booking_status, v.category
    FROM bookings b 
    JOIN customers c ON b.customer_id = c.id 
    JOIN venues v ON b.venue_id = v.id
    WHERE b.id = ? AND c.user_id = ?
");
$stmt_check->bind_param("ii", $booking_id, $_SESSION['user_id']);
$stmt_check->execute();
$res = $stmt_check->get_result();

if ($res->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Booking not found or access denied.']);
    exit;
}

$booking = $res->fetch_assoc();

try {
    // Lock the owned booking before checking/inserting. Two concurrent tabs
    // therefore produce one pending request deterministically.
    if (!$conn->begin_transaction()) throw new Exception('Could not start the request transaction.');
    $stmt_lock = $conn->prepare("SELECT b.id FROM bookings b JOIN customers c ON b.customer_id = c.id WHERE b.id = ? AND c.user_id = ? FOR UPDATE");
    if (!$stmt_lock) throw new Exception('Could not lock the booking.');
    $stmt_lock->bind_param('ii', $booking_id, $_SESSION['user_id']);
    if (!$stmt_lock->execute() || $stmt_lock->get_result()->num_rows === 0) throw new Exception('Booking not found or access denied.');
    // Re-fetch mutable booking state after acquiring the row lock so date,
    // duration, and confirmation checks cannot use a stale snapshot.
    $stmt_refresh = $conn->prepare("SELECT b.id, b.start_date, b.end_date, b.booking_status, v.category FROM bookings b JOIN customers c ON b.customer_id = c.id JOIN venues v ON b.venue_id = v.id WHERE b.id = ? AND c.user_id = ? FOR UPDATE");
    if (!$stmt_refresh) throw new Exception('Could not refresh the booking.');
    $stmt_refresh->bind_param('ii', $booking_id, $_SESSION['user_id']);
    if (!$stmt_refresh->execute()) throw new Exception('Could not refresh the booking.');
    $booking = $stmt_refresh->get_result()->fetch_assoc();
    if (!$booking || $booking['booking_status'] !== 'Confirmed') throw new Exception('Only confirmed bookings can be rescheduled.');
    $orig_start = new DateTime($booking['start_date']);
    $orig_end = new DateTime($booking['end_date']);
    $nights = $orig_start->diff($orig_end)->days;
    $req_start = DateTime::createFromFormat('!Y-m-d', $new_start);
    if (!$req_start || $req_start->format('Y-m-d') !== $new_start || $req_start < new DateTime('today')) throw new Exception('Please select a valid future reschedule date.');
    if ($nights > 0) $req_start->modify("+$nights days");
    $new_end = $req_start->format('Y-m-d');
    // Check if a request is already pending
    $chk_pending = $conn->prepare("SELECT id FROM reschedule_requests WHERE booking_id = ? AND status = 'Pending'");
    $chk_pending->bind_param("i", $booking_id);
    $chk_pending->execute();
    if ($chk_pending->get_result()->num_rows > 0) {
        throw new Exception("You already have a pending reschedule request for this booking.");
    }

    $stmt_insert = $conn->prepare("INSERT INTO reschedule_requests (booking_id, new_start_date, new_end_date, reason) VALUES (?, ?, ?, ?)");
    $stmt_insert->bind_param("isss", $booking_id, $new_start, $new_end, $reason);
    if (!$stmt_insert->execute()) throw new Exception('Could not submit the reschedule request.');

    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Customer Reschedule', ?, ?)");
    $audit_action = "Requested reschedule for booking #{$booking_id} to {$new_start}";
    $audit_ip = request_client_ip();
    if (!$audit) throw new Exception('Could not prepare the request audit entry.');
    $audit->bind_param('iss', $_SESSION['user_id'], $audit_action, $audit_ip);
    if (!$audit->execute()) throw new Exception('Could not record the request audit entry.');

    if (!$conn->commit()) throw new Exception('Could not commit the reschedule request.');
    echo json_encode(['success' => true, 'message' => 'Reschedule request submitted successfully! Staff will review it shortly.']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
