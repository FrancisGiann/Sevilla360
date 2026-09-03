<?php
/**
 * Allowlisted post-auth destination contract for an interrupted public booking.
 * The browser draft contains only non-sensitive selections; this session value
 * controls the destination and can never contain a caller-supplied URL.
 */
const BOOKING_AUTH_DESTINATION = 'booking_resume';

function booking_auth_capture_request(): void
{
    $destination = $_GET['destination'] ?? null;
    if (is_string($destination) && hash_equals(BOOKING_AUTH_DESTINATION, $destination)) {
        $_SESSION['post_auth_destination'] = BOOKING_AUTH_DESTINATION;
    }
}

function booking_auth_resume_requested(): bool
{
    return ($_SESSION['post_auth_destination'] ?? null) === BOOKING_AUTH_DESTINATION;
}

function booking_auth_consume_destination(string $role): ?string
{
    $destination = $_SESSION['post_auth_destination'] ?? null;
    unset($_SESSION['post_auth_destination']);
    if ($role === 'customer' && $destination === BOOKING_AUTH_DESTINATION) {
        return 'booking.php?resume=1';
    }
    return null;
}

function booking_auth_clear_destination(): void
{
    unset($_SESSION['post_auth_destination']);
}
?>
