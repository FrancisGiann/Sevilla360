<?php
session_start();
header('Content-Type: application/json');
require_once '../../config/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$booking_id = (int)$_GET['id'];

try {
    // 1. Fetch Booking + Phone Number!
    $stmt = $conn->prepare("
        SELECT b.*, c.first_name, c.last_name, c.email, c.phone, v.name as venue_name, v.category as venue_category 
        FROM bookings b 
        JOIN customers c ON b.customer_id = c.id 
        JOIN venues v ON b.venue_id = v.id 
        WHERE b.id = ?
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();

    if (!$booking) throw new Exception("Booking not found");

    // 2. Fetch Specifics
    $specifics = null;
    if ($booking['venue_category'] === 'Event Hall') {
        $st = $conn->prepare("SELECT event_style, event_type, custom_notes FROM booking_event_details WHERE booking_id = ?");
        $st->bind_param("i", $booking_id);
        $st->execute();
        $specifics = $st->get_result()->fetch_assoc();
    } elseif ($booking['venue_category'] === 'Resort Villa') {
        $st = $conn->prepare("SELECT stay_type FROM booking_villa_details WHERE booking_id = ?");
        $st->bind_param("i", $booking_id);
        $st->execute();
        $specifics = $st->get_result()->fetch_assoc();
    }

    $st_add = $conn->prepare("SELECT a.name, ba.quantity, ba.total_price FROM booking_addons ba JOIN addons a ON ba.addon_id = a.id WHERE ba.booking_id = ?");
    $st_add->bind_param("i", $booking_id);
    $st_add->execute();
    $addons = $st_add->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['success' => true, 'data' => ['booking' => $booking, 'specifics' => $specifics, 'addons' => $addons]]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>