<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

// 1. SECURITY: Must be a logged-in customer
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Booking ID missing']);
    exit;
}

$booking_id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];
$response = ['success' => true, 'data' => []];

try {
    // 2. SECURITY: Fetch the booking, ensuring it belongs to THIS user_id!
    $stmt = $conn->prepare("
        SELECT 
            b.*, 
            c.first_name, c.last_name, c.email, c.phone, 
            v.name AS venue_name, v.category AS venue_category
        FROM bookings b
        JOIN customers c ON b.customer_id = c.id
        JOIN venues v ON b.venue_id = v.id
        WHERE b.id = ? AND c.user_id = ?
    ");
    $stmt->bind_param("ii", $booking_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) throw new Exception("Booking not found or access denied.");
    $booking = $result->fetch_assoc();
    $response['data']['booking'] = $booking;

    // 3. Get Specific Details
    if ($booking['venue_category'] === 'Event Hall') {
        $stmt_ev = $conn->prepare("SELECT event_style, event_type, custom_notes FROM booking_event_details WHERE booking_id = ?");
        $stmt_ev->bind_param("i", $booking_id);
        $stmt_ev->execute();
        $response['data']['specifics'] = $stmt_ev->get_result()->fetch_assoc();
    } elseif ($booking['venue_category'] === 'Resort Villa') {
        $stmt_vi = $conn->prepare("SELECT stay_type FROM booking_villa_details WHERE booking_id = ?");
        $stmt_vi->bind_param("i", $booking_id);
        $stmt_vi->execute();
        $response['data']['specifics'] = $stmt_vi->get_result()->fetch_assoc();
    }

    // 4. Get Add-ons
    $stmt_add = $conn->prepare("
        SELECT a.name, ba.quantity, ba.total_price 
        FROM booking_addons ba 
        JOIN addons a ON ba.addon_id = a.id 
        WHERE ba.booking_id = ?
    ");
    $stmt_add->bind_param("i", $booking_id);
    $stmt_add->execute();
    $addons_result = $stmt_add->get_result();
    $response['data']['addons'] = [];
    while($row = $addons_result->fetch_assoc()) {
        $response['data']['addons'][] = $row;
    }

    // 5. Get Transaction ID (if exists)
    $stmt_pay = $conn->prepare("SELECT transaction_id FROM payments WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
    $stmt_pay->bind_param("i", $booking_id);
    $stmt_pay->execute();
    $pay_res = $stmt_pay->get_result();
    $response['data']['transaction_id'] = ($pay_res->num_rows > 0) ? $pay_res->fetch_assoc()['transaction_id'] : 'N/A';

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>