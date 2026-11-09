<?php

/**
 * Return the canonical database-local completion predicate for a booking.
 *
 * A booking is complete once it is explicitly marked Completed or a confirmed
 * booking's final date has passed. Historical cancellations and pending
 * inquiries retain their stored terminal/workflow status. CURDATE() keeps the
 * decision in the database's local date context, rather than relying on a
 * PHP/browser timezone.
 */
function booking_completion_sql(string $alias = 'b'): string
{
    if (!preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $alias)) {
        throw new InvalidArgumentException('Invalid booking table alias.');
    }

    return "({$alias}.booking_status = 'Completed' OR ({$alias}.booking_status = 'Confirmed' AND {$alias}.end_date < CURDATE()))";
}

/** Return whether a row already carries the canonical completed display state. */
function booking_is_completed(array $booking): bool
{
    return (string)($booking['display_booking_status'] ?? $booking['booking_status'] ?? '') === 'Completed'
        || (int)($booking['is_completed'] ?? 0) === 1;
}

/** Check the optional marker column before building queries that reference it. */
function booking_checkout_unavailable_marker_available(mysqli $conn): bool
{
    try {
        $result = $conn->query("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'bookings' AND column_name = 'checkout_unavailable_at' LIMIT 1");
        return $result instanceof mysqli_result && $result->num_rows > 0;
    } catch (Throwable $error) {
        return false;
    }
}
