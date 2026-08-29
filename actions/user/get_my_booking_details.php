<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once '../../config/db_connect.php';
require_once '../../includes/booking_lifecycle.php';
$booking_completion_sql = booking_completion_sql('b');

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
            b.*, CASE WHEN $booking_completion_sql THEN 'Completed' ELSE b.booking_status END AS display_booking_status,
            c.first_name, c.last_name, c.email, COALESCE(b.contact_phone, c.phone) AS phone,
            v.name AS venue_name, v.category AS venue_category,
            hr.room_type, hr.room_number,
            EXISTS (SELECT 1 FROM reschedule_requests rr_done WHERE rr_done.booking_id = b.id AND rr_done.status = 'Approved') AS has_rescheduled
        FROM bookings b
        JOIN customers c ON b.customer_id = c.id
        JOIN venues v ON b.venue_id = v.id
        LEFT JOIN hotel_rooms hr ON v.id = hr.venue_id
        WHERE b.id = ? AND c.user_id = ?
    ");
    $stmt->bind_param("ii", $booking_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) throw new Exception("Booking not found or access denied.");
    $booking = $result->fetch_assoc();

    // Format the venue_name for Hotel Rooms
    if ($booking['venue_category'] === 'Hotel Room') {
        $r_type = $booking['room_type'] ?? 'Hotel Room';
        $r_num = $booking['room_number'] ? " - Room " . $booking['room_number'] : "";
        $booking['venue_name'] = $booking['venue_name'] . " - " . $r_type . $r_num;
    }

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
    $response['data']['addons'] = $stmt_add->get_result()->fetch_all(MYSQLI_ASSOC);

    // 4b. Get Room Add-ons (booking_rooms)
    $stmt_br = $conn->prepare("
        SELECT
            v.name AS building_name,
            hr.room_type,
            hr.room_number,
            br.start_date,
            br.end_date,
            br.nights,
            br.line_total
        FROM booking_rooms br
        JOIN venues v ON br.venue_id = v.id
        JOIN hotel_rooms hr ON v.id = hr.venue_id
        WHERE br.booking_id = ?
    ");
    $stmt_br->bind_param("i", $booking_id);
    $stmt_br->execute();
    $response['data']['rooms'] = $stmt_br->get_result()->fetch_all(MYSQLI_ASSOC);

    // 4.2. Get Custom Line Items
    $stmt_li = $conn->prepare("SELECT item_name, amount FROM booking_line_items WHERE booking_id = ?");
    $stmt_li->bind_param("i", $booking_id);
    $stmt_li->execute();
    $response['data']['line_items'] = $stmt_li->get_result()->fetch_all(MYSQLI_ASSOC);

    // 4.5. Get Cancellation Reason (if this booking was cancelled)
    if ($booking['booking_status'] === 'Cancelled') {
        $stmt_cx = $conn->prepare("
            SELECT reason, admin_reply, status
            FROM cancellations
            WHERE booking_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt_cx->bind_param("i", $booking_id);
        $stmt_cx->execute();
        $cx_res = $stmt_cx->get_result();
        $response['data']['cancellation'] = ($cx_res->num_rows > 0) ? $cx_res->fetch_assoc() : null;
    } else {
        $response['data']['cancellation'] = null;
    }

    // 5. Get All Payment Records & Transaction IDs
    $stmt_pay = $conn->prepare("SELECT transaction_id, payment_method, amount, payment_date FROM payments WHERE booking_id = ? AND status = 'Success' ORDER BY payment_date ASC");
    $stmt_pay->bind_param("i", $booking_id);
    $stmt_pay->execute();
    $payments_res = $stmt_pay->get_result()->fetch_all(MYSQLI_ASSOC);
    $response['data']['payments'] = $payments_res;

    $tx_ids = array_filter(array_column($payments_res, 'transaction_id'));
    $response['data']['transaction_id'] = !empty($tx_ids) ? implode(', ', $tx_ids) : 'N/A';

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
