<?php
/**
 * Fetch actionable notifications for the admin dashboard and homepage bell.
 *
 * @param mysqli   $conn  Active database connection.
 * @param int|null $limit Maximum rows to return, or null for all rows.
 * @return array<int, array<string, mixed>>
 */
function get_admin_action_notifications(mysqli $conn, ?int $limit = null): array
{
    if ($limit !== null) {
        $limit = max(1, min(100, $limit));
    }

    $limit_clause = $limit === null ? '' : " LIMIT {$limit}";
    $result = $conn->query(
        "SELECT b.id, b.reference_no, b.booking_status, b.start_date,
                b.source, v.name AS venue_name, v.category AS venue_category,
                CASE WHEN EXISTS (
                    SELECT 1 FROM cancellations cx
                    WHERE cx.booking_id = b.id AND cx.status = 'Pending'
                ) THEN 'Pending' END AS cancel_status,
                CASE WHEN b.booking_status != 'Cancelled' AND EXISTS (
                    SELECT 1 FROM reschedule_requests rr
                    WHERE rr.booking_id = b.id AND rr.status = 'Pending'
                ) THEN 'Pending' END AS resched_status
         FROM bookings b
         JOIN venues v ON b.venue_id = v.id
         JOIN customers c ON b.customer_id = c.id
         WHERE b.source <> 'Maintenance'
           AND b.reference_no NOT LIKE 'MAINT-%'
           AND c.last_name <> 'MAINTENANCE'
           AND (
                EXISTS (
                    SELECT 1 FROM cancellations cx
                    WHERE cx.booking_id = b.id AND cx.status = 'Pending'
                )
            OR (b.booking_status != 'Cancelled' AND EXISTS (
                    SELECT 1 FROM reschedule_requests rr
                    WHERE rr.booking_id = b.id AND rr.status = 'Pending'
                ))
            OR (b.source = 'Online' AND b.booking_status = 'Pending'
                AND v.category IN ('Event Hall', 'Hotel Room', 'Resort Villa'))
           )
         ORDER BY b.id DESC{$limit_clause}"
    );

    if (!$result) {
        throw new RuntimeException('Unable to fetch admin action notifications: ' . $conn->error);
    }

    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
    return $rows;
}
