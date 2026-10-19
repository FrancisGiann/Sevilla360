<?php
require_once __DIR__ . '/booking_lifecycle.php';

/** Resolve the customer-facing booking state used across dashboard surfaces. */
function customer_dashboard_status(array $booking): array
{
    $bookingStatus = (string)($booking['display_booking_status'] ?? $booking['booking_status'] ?? '');
    $venueType = (string)($booking['venue_type'] ?? $booking['venue_category'] ?? '');

    if (booking_is_completed($booking)) return ['Completed', 'badge-completed'];
    if (!empty($booking['cancel_pending']) || ($booking['cancel_status'] ?? '') === 'Pending') return ['Pending refund', 'badge-cancelled'];
    if (!empty($booking['resched_pending']) || ($booking['resched_status'] ?? '') === 'Pending') return ['Reschedule requested', 'badge-reschedule'];
    if ($bookingStatus === 'Cancelled') return ['Cancelled', 'badge-cancelled'];
    if (!empty($booking['manual_payment_pending'])) return ['Awaiting payment verification', 'badge-pending'];
    if (($booking['manual_submission_status'] ?? '') === 'rejected') return ['Proof rejected', 'badge-cancelled'];
    if ($bookingStatus === 'Pending' && $venueType === 'Event Hall') return ['Inquiry sent', 'badge-pending'];

    $paymentStatus = (string)($booking['payment_status'] ?? '');
    if ($paymentStatus === 'Paid') return ['Fully paid', 'badge-paid'];
    if ($paymentStatus === 'Partial') return ['Partially paid', 'badge-partial'];
    if ($paymentStatus === 'Refunded') return ['Refunded', 'badge-cancelled'];

    if (
        $paymentStatus === 'Unpaid'
        && (float)($booking['amount_paid'] ?? 0) <= 0
        && ($booking['source'] ?? '') === 'Online'
        && in_array($bookingStatus, ['Pending', 'Confirmed'], true)
        && !empty($booking['payment_due_at'])
    ) {
        $deadline = strtotime((string)$booking['payment_due_at']);
        if ($deadline !== false && $deadline <= time()) return ['Payment window expired', 'badge-cancelled'];
    }

    return ['Payment due', 'badge-pending'];
}
