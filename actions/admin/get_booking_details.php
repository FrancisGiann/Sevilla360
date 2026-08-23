<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['staff', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$booking_id = (int)$_GET['id'];

try {
    // 1. Fetch Booking + Phone Number!
    $stmt = $conn->prepare("
        SELECT
            b.*, c.first_name, c.last_name, c.email, COALESCE(b.contact_phone, c.phone) AS phone,
            v.name as venue_name, v.category as venue_category,
            hr.room_type, hr.room_number,
            EXISTS (SELECT 1 FROM reschedule_requests rr_done WHERE rr_done.booking_id = b.id AND rr_done.status = 'Approved') AS has_rescheduled
        FROM bookings b
        JOIN customers c ON b.customer_id = c.id
        JOIN venues v ON b.venue_id = v.id
        LEFT JOIN hotel_rooms hr ON v.id = hr.venue_id
        WHERE b.id = ?
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();

    if (!$booking) throw new Exception("Booking not found");

    if ($booking['venue_category'] === 'Hotel Room') {
        $r_type = $booking['room_type'] ?? 'Hotel Room';
        $r_num = $booking['room_number'] ? " - Room " . $booking['room_number'] : "";
        $booking['venue_name'] = $booking['venue_name'] . " - " . $r_type . $r_num;
    }

    // 2. Fetch Specifics
    $specifics = null;
    $event_style_capacities = null;
    if ($booking['venue_category'] === 'Event Hall') {
        $st = $conn->prepare("SELECT event_style, event_type, custom_notes, admin_notes FROM booking_event_details WHERE booking_id = ?");
        $st->bind_param("i", $booking_id);
        $st->execute();
        $specifics = $st->get_result()->fetch_assoc();

        $st_capacity = $conn->prepare("SELECT capacity_theater, capacity_classroom, capacity_banquet FROM event_halls WHERE venue_id = ? LIMIT 1");
        $st_capacity->bind_param('i', $booking['venue_id']);
        $st_capacity->execute();
        $capacity_row = $st_capacity->get_result()->fetch_assoc();
        $event_style_capacities = [
            'theater' => (int)($capacity_row['capacity_theater'] ?? 0),
            'classroom' => (int)($capacity_row['capacity_classroom'] ?? 0),
            'banquet' => (int)($capacity_row['capacity_banquet'] ?? 0)
        ];
    } elseif ($booking['venue_category'] === 'Resort Villa') {
        $st = $conn->prepare("SELECT stay_type FROM booking_villa_details WHERE booking_id = ?");
        $st->bind_param("i", $booking_id);
        $st->execute();
        $specifics = $st->get_result()->fetch_assoc();
    }

    // Fetch Initial Addons
    $st_add = $conn->prepare("SELECT a.name, ba.quantity, ba.total_price FROM booking_addons ba JOIN addons a ON ba.addon_id = a.id WHERE ba.booking_id = ?");
    $st_add->bind_param("i", $booking_id);
    $st_add->execute();
    $addons = $st_add->get_result()->fetch_all(MYSQLI_ASSOC);

    // Fetch Custom Line Items (If finalized)
    $st_li = $conn->prepare("SELECT item_name, amount FROM booking_line_items WHERE booking_id = ?");
    $st_li->bind_param("i", $booking_id);
    $st_li->execute();
    $line_items = $st_li->get_result()->fetch_all(MYSQLI_ASSOC);

    // Fetch Room Allocations (Hotel Add-ons)
    $st_ra = $conn->prepare("
        SELECT v.name as building_name, h.room_type, h.room_number, br.start_date, br.end_date, br.nights, br.line_total
        FROM booking_rooms br
        JOIN venues v ON br.venue_id = v.id
        JOIN hotel_rooms h ON v.id = h.venue_id
        WHERE br.booking_id = ?
    ");
    $st_ra->bind_param("i", $booking_id);
    $st_ra->execute();
    $room_allocations = $st_ra->get_result()->fetch_all(MYSQLI_ASSOC);

    // Fetch Transaction Reference (latest)
    $st_tx = $conn->prepare("SELECT transaction_id FROM payments WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
    $st_tx->bind_param("i", $booking_id);
    $st_tx->execute();
    $tx_res = $st_tx->get_result()->fetch_assoc();
    $transaction_id = $tx_res ? $tx_res['transaction_id'] : null;

    $st_checkout = $conn->prepare("SELECT id, provider_session_id, checkout_url, amount, currency, status, provider_status, expires_at FROM booking_checkout_sessions WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
    if (!$st_checkout) throw new Exception('Unable to load checkout reconciliation details.');
    $st_checkout->bind_param('i', $booking_id);
    if (!$st_checkout->execute()) throw new Exception('Unable to load checkout reconciliation details.');
    $checkout_session = $st_checkout->get_result()->fetch_assoc();

    // Fetch Cancellation Data
    $st_cx = $conn->prepare("SELECT refund_amount, refund_transaction_id, fee_deducted, fee_percent, status, admin_reply FROM cancellations WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
    $st_cx->bind_param("i", $booking_id);
    $st_cx->execute();
    $cx_res = $st_cx->get_result()->fetch_assoc();

    echo json_encode(['success' => true, 'data' => [
        'booking' => $booking,
        'specifics' => $specifics,
        'event_style_capacities' => $event_style_capacities,
        'addons' => $addons,
        'line_items' => $line_items,
        'room_allocations' => $room_allocations,
        'transaction_id' => $transaction_id,
        'checkout_session' => $checkout_session,
        'cancellation' => $cx_res
    ]]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
