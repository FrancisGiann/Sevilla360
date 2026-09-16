<?php
require_once __DIR__ . '/booking_lifecycle.php';

/** Fetch unresolved admin actions as normalized, account-aware items. */
function get_admin_action_notifications(mysqli $conn, ?int $limit = null, ?int $current_user_id = null): array
{
    $limit = $limit === null ? null : max(1, min(100, $limit));
    $current_user_id = $current_user_id ?? (int)($_SESSION['user_id'] ?? 0);
    $completion = booking_completion_sql('b');
    $sql = "
        SELECT * FROM (
            SELECT CONCAT('booking:', b.id, ':new') AS notification_key, 'new_booking' AS kind,
                CASE WHEN v.category = 'Event Hall' THEN 'New Event Inquiry' ELSE 'New Booking Request' END AS title,
                CONCAT(CASE WHEN v.category = 'Event Hall' THEN 'Event Hall inquiry' ELSE 'Booking request' END, ' for ', v.name, ' (#', b.reference_no, ')') AS message,
                b.id AS booking_id, b.reference_no, NULL AS submission_id, b.created_at AS action_timestamp,
                CONCAT('admin_dashboard.php?page=bookings&booking_id=', b.id) AS target_url
            FROM bookings b JOIN venues v ON v.id = b.venue_id JOIN customers c ON c.id = b.customer_id
            WHERE b.source = 'Online' AND b.booking_status = 'Pending' AND NOT {$completion}
              AND b.source <> 'Maintenance' AND b.reference_no NOT LIKE 'MAINT-%' AND c.last_name <> 'MAINTENANCE'
            UNION ALL
            SELECT CONCAT('cancellation:', cx.id), 'cancellation_request', 'Refund Requested',
                CONCAT('Refund request for ', v.name, ' (#', b.reference_no, ')'), b.id, b.reference_no, NULL, cx.created_at,
                CONCAT('admin_dashboard.php?page=bookings&booking_id=', b.id, '&filter=action_req')
            FROM cancellations cx JOIN bookings b ON b.id = cx.booking_id JOIN venues v ON v.id = b.venue_id JOIN customers c ON c.id = b.customer_id
            WHERE cx.status = 'Pending' AND b.booking_status <> 'Cancelled' AND NOT {$completion}
              AND b.source <> 'Maintenance' AND b.reference_no NOT LIKE 'MAINT-%' AND c.last_name <> 'MAINTENANCE'
            UNION ALL
            SELECT CONCAT('reschedule:', rr.id), 'reschedule_request', 'Reschedule Requested',
                CONCAT('Reschedule request for ', v.name, ' (#', b.reference_no, ')'), b.id, b.reference_no, NULL, rr.created_at,
                CONCAT('admin_dashboard.php?page=bookings&booking_id=', b.id, '&filter=action_req')
            FROM reschedule_requests rr JOIN bookings b ON b.id = rr.booking_id JOIN venues v ON v.id = b.venue_id JOIN customers c ON c.id = b.customer_id
            WHERE rr.status = 'Pending' AND b.booking_status <> 'Cancelled' AND NOT {$completion}
              AND b.source <> 'Maintenance' AND b.reference_no NOT LIKE 'MAINT-%' AND c.last_name <> 'MAINTENANCE'
            UNION ALL
            SELECT CONCAT('payment-proof:', m.booking_id, ':', m.id, ':', m.proof_sha256, ':', UNIX_TIMESTAMP(m.submitted_at)), 'payment_proof', 'Payment Proof Pending',
                CONCAT('Payment proof awaiting review for ', v.name, ' (#', b.reference_no, ')'), b.id, b.reference_no, m.id, m.submitted_at,
                CONCAT('admin_dashboard.php?page=bookings&booking_id=', b.id, '&open_payment_proof=', m.id)
            FROM manual_payment_submissions m JOIN bookings b ON b.id = m.booking_id JOIN venues v ON v.id = b.venue_id JOIN customers c ON c.id = b.customer_id
            WHERE m.status = 'pending' AND b.booking_status <> 'Cancelled' AND NOT {$completion}
              AND b.source <> 'Maintenance' AND b.reference_no NOT LIKE 'MAINT-%' AND c.last_name <> 'MAINTENANCE'
        ) actions ORDER BY action_timestamp DESC, booking_id DESC";
    if ($limit !== null) $sql .= " LIMIT {$limit}";
    $result = $conn->query($sql);
    if (!$result) throw new RuntimeException('Unable to fetch admin action notifications: ' . $conn->error);
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
    if (!$rows || $current_user_id < 1) {
        return array_map(static fn(array $row): array => admin_notification_normalize_item($row, false), $rows);
    }

    $placeholders = implode(',', array_fill(0, count($rows), '?'));
    $stmt = $conn->prepare("SELECT notification_key FROM admin_notification_reads WHERE user_id = ? AND notification_key IN ({$placeholders})");
    if (!$stmt) throw new RuntimeException('Unable to load admin notification read state.');
    $types = 'i' . str_repeat('s', count($rows));
    $values = [$current_user_id];
    foreach ($rows as $row) $values[] = (string)$row['notification_key'];
    $bind = [$types];
    foreach ($values as $index => $value) $bind[] = &$values[$index];
    call_user_func_array([$stmt, 'bind_param'], $bind);
    if (!$stmt->execute()) throw new RuntimeException('Unable to load admin notification read state.');
    $read = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $read[(string)$row['notification_key']] = true;
    $stmt->close();
    return array_map(static fn(array $row): array => admin_notification_normalize_item($row, isset($read[(string)$row['notification_key']])), $rows);
}

function admin_notification_normalize_item(array $row, bool $is_read): array
{
    $key = (string)$row['notification_key'];
    return [
        'key' => $key, 'kind' => (string)$row['kind'], 'title' => (string)$row['title'], 'message' => (string)$row['message'],
        'booking_id' => (int)$row['booking_id'], 'reference_no' => (string)$row['reference_no'],
        'submission_id' => $row['submission_id'] === null ? null : (int)$row['submission_id'],
        'target_url' => (string)$row['target_url'], 'timestamp' => (string)$row['action_timestamp'], 'is_read' => $is_read,
    ];
}

/** Only currently unresolved server-generated keys may be persisted. */
function admin_notification_key_is_active(mysqli $conn, string $key): bool
{
    if (!preg_match('/\A(?:booking:[1-9][0-9]*:new|cancellation:[1-9][0-9]*|reschedule:[1-9][0-9]*|payment-proof:[1-9][0-9]*:[1-9][0-9]*:[a-f0-9]{64}:[1-9][0-9]*)\z/', $key)) return false;
    foreach (get_admin_action_notifications($conn, null, 0) as $item) {
        if (hash_equals((string)$item['key'], $key)) return true;
    }
    return false;
}
