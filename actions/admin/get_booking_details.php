<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

// Auth Guard
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superadmin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Booking ID missing']);
    exit;
}

$booking_id = intval($_GET['id']);
$response = ['success' => true, 'data' => []];

try {
    // 1. Get Main Booking & Customer & Venue
    $stmt = $conn->prepare("
        SELECT 
            b.*, 
            c.first_name, c.last_name, c.email, c.phone, 
            v.name AS venue_name, v.category AS venue_category
        FROM bookings b
        JOIN customers c ON b.customer_id = c.id
        JOIN venues v ON b.venue_id = v.id
        WHERE b.id = ?
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) throw new Exception("Booking not found");
    $booking = $result->fetch_assoc();
    $response['data']['booking'] = $booking;

    // 2. Get Specific Details (Event Hall OR Villa)
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

    // 3. Get Add-ons
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

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>