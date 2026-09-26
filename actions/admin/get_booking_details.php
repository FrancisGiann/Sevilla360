<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/booking_lifecycle.php';
require_once __DIR__ . '/../../includes/booking_rules.php';
require_once __DIR__ . '/../../includes/manual_payment.php';
$booking_completion_sql = booking_completion_sql('b');

if (!isset($_SESSION['user_id']) || !in_array(($_SESSION['role'] ?? ''), ['staff', 'admin'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

$booking_id = (int)$_GET['id'];

try {
    // 1. Fetch Booking + Phone Number!
    $stmt = $conn->prepare("
        SELECT
            b.*, CASE WHEN $booking_completion_sql THEN 'Completed' ELSE b.booking_status END AS display_booking_status,
            c.first_name, c.last_name, c.email, COALESCE(b.contact_phone, c.phone) AS phone,
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
        $st = $conn->prepare("SELECT bvd.stay_type, vi.overnight_stay_inclusions FROM booking_villa_details bvd JOIN bookings b ON b.id = bvd.booking_id JOIN villas vi ON vi.venue_id = b.venue_id WHERE bvd.booking_id = ?");
        $st->bind_param("i", $booking_id);
        $st->execute();
        $specifics = $st->get_result()->fetch_assoc();
        if ($specifics) {
            $specifics['nights'] = villa_stay_nights((string)$specifics['stay_type'], new DateTimeImmutable($booking['start_date']), new DateTimeImmutable($booking['end_date']));
            $specifics['breakfast_entitlement'] = villa_breakfast_entitlement($specifics['overnight_stay_inclusions'] ?? null);
            $specifics['breakfast_schedule'] = villa_breakfast_schedule((string)$specifics['stay_type'], new DateTimeImmutable($booking['start_date']), new DateTimeImmutable($booking['end_date']));
            $specifics['summary'] = villa_booking_detail_summary((string)$specifics['stay_type'], (string)$booking['start_date'], (string)$booking['end_date'], $specifics['overnight_stay_inclusions'] ?? null);
            unset($specifics['overnight_stay_inclusions']);
        }
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

    // Keep each successful payment distinct and substitute the submitted reference
    // for the internal manual-payment idempotency key.
    $st_tx = $conn->prepare("SELECT p.transaction_id, p.payment_method, p.amount, p.payment_date, mps.transaction_reference AS manual_reference FROM payments p LEFT JOIN manual_payment_submissions mps ON mps.payment_id = p.id WHERE p.booking_id = ? AND p.status = 'Success' ORDER BY p.payment_date ASC, p.id ASC");
    $st_tx->bind_param("i", $booking_id);
    $st_tx->execute();
    $payments = manual_payment_format_history($st_tx->get_result()->fetch_all(MYSQLI_ASSOC));
    $displayReferences = array_values(array_filter(array_column($payments, 'transaction_reference'), static fn($value) => is_string($value) && $value !== ''));
    $transaction_id = $displayReferences ? end($displayReferences) : null;

    // Proof history is metadata-only. Image bytes remain behind payment_proof.php.
    $st_proofs = $conn->prepare("SELECT id, status, payment_method, transaction_reference, expected_amount, submitted_at, reviewed_at, rejection_reason, NOW() AS retention_checked_at FROM manual_payment_submissions WHERE booking_id = ? ORDER BY submitted_at ASC, id ASC");
    $st_proofs->bind_param('i', $booking_id);
    if (!$st_proofs->execute()) throw new RuntimeException('Unable to load payment proof history.');
    $proofHistory = array_map(static function (array $proof): array {
        $proof['id'] = (int)$proof['id'];
        $checkedAt = new DateTimeImmutable((string)$proof['retention_checked_at']);
        $proof['proof_available'] = !manual_payment_proof_retention_expired(
            (string)$proof['status'],
            isset($proof['reviewed_at']) ? (string)$proof['reviewed_at'] : null,
            $checkedAt
        );
        unset($proof['retention_checked_at']);
        return $proof;
    }, $st_proofs->get_result()->fetch_all(MYSQLI_ASSOC));

    // Fetch Cancellation Data
    $st_cx = $conn->prepare("SELECT reason, refund_amount, refund_transaction_id, fee_deducted, fee_percent, status, admin_reply, refund_destination_method, refund_destination_account_name, refund_destination_account_identifier, refund_destination_bank_name FROM cancellations WHERE booking_id = ? ORDER BY id DESC LIMIT 1");
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
        'payments' => $payments,
        'proof_history' => $proofHistory,
        'cancellation' => $cx_res
    ]]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
