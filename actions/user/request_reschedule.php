<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

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
if ($booking['booking_status'] !== 'Confirmed') {
    echo json_encode(['success' => false, 'message' => 'Only confirmed bookings can be rescheduled.']);
    exit;
}

// Force strict duration rule for ALL bookings to prevent unauthorized extension/shortening
$orig_start = new DateTime($booking['start_date']);
$orig_end = new DateTime($booking['end_date']);
$nights = $orig_start->diff($orig_end)->days;

// Recalculate end date from the requested start date to prevent duration tampering
$req_start = DateTime::createFromFormat('!Y-m-d', $new_start);
if (!$req_start || $req_start->format('Y-m-d') !== $new_start || $req_start < new DateTime('today')) {
    echo json_encode(['success' => false, 'message' => 'Please select a valid future reschedule date.']);
    exit;
}
if ($nights > 0) {
    $req_start->modify("+$nights days");
}
$new_end = $req_start->format('Y-m-d');

try {
    // Check if a request is already pending
    $chk_pending = $conn->prepare("SELECT id FROM reschedule_requests WHERE booking_id = ? AND status = 'Pending'");
    $chk_pending->bind_param("i", $booking_id);
    $chk_pending->execute();
    if ($chk_pending->get_result()->num_rows > 0) {
        throw new Exception("You already have a pending reschedule request for this booking.");
    }

    $stmt_insert = $conn->prepare("INSERT INTO reschedule_requests (booking_id, new_start_date, new_end_date, reason) VALUES (?, ?, ?, ?)");
    $stmt_insert->bind_param("isss", $booking_id, $new_start, $new_end, $reason);
    $stmt_insert->execute();

    echo json_encode(['success' => true, 'message' => 'Reschedule request submitted successfully! Staff will review it shortly.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
