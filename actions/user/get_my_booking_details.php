<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once '../../config/db_connect.php';
require_once '../../includes/booking_lifecycle.php';
require_once '../../includes/customer_booking_status.php';
require_once '../../includes/manual_payment.php';
require_once '../../includes/refund_helper.php';
require_once '../../includes/booking_rules.php';
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
            EXISTS (SELECT 1 FROM reschedule_requests rr_done WHERE rr_done.booking_id = b.id AND rr_done.status = 'Approved') AS has_rescheduled,
            EXISTS (SELECT 1 FROM cancellations cx_pending WHERE cx_pending.booking_id = b.id AND cx_pending.status = 'Pending') AS cancel_pending,
            EXISTS (SELECT 1 FROM reschedule_requests rr_pending WHERE rr_pending.booking_id = b.id AND rr_pending.status = 'Pending') AS resched_pending,
            EXISTS (SELECT 1 FROM manual_payment_submissions mps_pending WHERE mps_pending.booking_id = b.id AND mps_pending.customer_user_id = c.user_id AND mps_pending.status = 'pending') AS manual_payment_pending,
            latest_mps.transaction_reference AS manual_submission_reference,
            latest_mps.status AS manual_submission_status,
            latest_mps.submitted_at AS manual_submission_submitted_at,
            latest_mps.rejection_reason AS manual_rejection_reason
        FROM bookings b
        JOIN customers c ON b.customer_id = c.id
        JOIN venues v ON b.venue_id = v.id
        LEFT JOIN hotel_rooms hr ON v.id = hr.venue_id
        LEFT JOIN manual_payment_submissions latest_mps ON latest_mps.id = (
            SELECT mps_latest.id
            FROM manual_payment_submissions mps_latest
            WHERE mps_latest.booking_id = b.id AND mps_latest.customer_user_id = c.user_id
            ORDER BY mps_latest.submitted_at DESC, mps_latest.id DESC
            LIMIT 1
        )
        WHERE b.id = ? AND c.user_id = ?
    ");
    $stmt->bind_param("ii", $booking_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) throw new Exception("Booking not found or access denied.");
    $booking = $result->fetch_assoc();

    [$booking['customer_status_label'], $booking['customer_status_class']] = customer_dashboard_status($booking);

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
        $stmt_vi = $conn->prepare("SELECT bvd.stay_type, vi.overnight_stay_inclusions FROM booking_villa_details bvd JOIN bookings b ON b.id = bvd.booking_id JOIN villas vi ON vi.venue_id = b.venue_id WHERE bvd.booking_id = ?");
        $stmt_vi->bind_param("i", $booking_id);
        $stmt_vi->execute();
        $specifics = $stmt_vi->get_result()->fetch_assoc();
        if ($specifics) {
            $specifics['nights'] = villa_stay_nights((string)$specifics['stay_type'], new DateTimeImmutable($booking['start_date']), new DateTimeImmutable($booking['end_date']));
            $specifics['breakfast_entitlement'] = villa_breakfast_entitlement($specifics['overnight_stay_inclusions'] ?? null);
            $specifics['breakfast_schedule'] = villa_breakfast_schedule((string)$specifics['stay_type'], new DateTimeImmutable($booking['start_date']), new DateTimeImmutable($booking['end_date']));
            $specifics['summary'] = villa_booking_detail_summary((string)$specifics['stay_type'], (string)$booking['start_date'], (string)$booking['end_date'], $specifics['overnight_stay_inclusions'] ?? null);
            unset($specifics['overnight_stay_inclusions']);
        }
        $response['data']['specifics'] = $specifics;
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

    // 4.5. Return the latest request on any booking state, exposing only a
    // masked identifier to the owning customer.
    $stmt_cx = $conn->prepare("SELECT reason, admin_reply, status, refund_amount, fee_deducted, fee_percent, refund_destination_method, refund_destination_account_name, refund_destination_account_identifier, refund_destination_bank_name FROM cancellations WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
    $stmt_cx->bind_param("i", $booking_id);
    $stmt_cx->execute();
    $cancellation = $stmt_cx->get_result()->fetch_assoc();
    if ($cancellation && in_array($cancellation['status'], ['Pending', 'Rejected', 'Processed'], true)) {
        $cancellation['refund_destination'] = refund_destination_for_customer($cancellation);
        unset($cancellation['refund_destination_method'], $cancellation['refund_destination_account_name'], $cancellation['refund_destination_account_identifier'], $cancellation['refund_destination_bank_name']);
        $response['data']['cancellation'] = $cancellation;
    } else {
        $response['data']['cancellation'] = null;
    }

    // 5. Get All Payment Records & Transaction IDs
    $stmt_pay = $conn->prepare("SELECT p.transaction_id, p.payment_method, p.amount, p.payment_date, mps.transaction_reference AS manual_reference FROM payments p LEFT JOIN manual_payment_submissions mps ON mps.payment_id = p.id WHERE p.booking_id = ? AND p.status = 'Success' ORDER BY p.payment_date ASC, p.id ASC");
    $stmt_pay->bind_param("i", $booking_id);
    $stmt_pay->execute();
    $payments_res = manual_payment_format_history($stmt_pay->get_result()->fetch_all(MYSQLI_ASSOC));
    $response['data']['payments'] = $payments_res;

    $tx_ids = array_filter(array_column($payments_res, 'transaction_reference'), static fn($value) => is_string($value) && $value !== '');
    $response['data']['transaction_id'] = !empty($tx_ids) ? implode(', ', $tx_ids) : 'N/A';

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
