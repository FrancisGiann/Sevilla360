<?php
/**
 * Allowlisted post-auth destination contract for an interrupted public booking.
 * The browser draft contains only non-sensitive selections; this session value
 * controls the destination and can never contain a caller-supplied URL.
 */
const BOOKING_AUTH_DESTINATION = 'booking_resume';
const BOOKING_AUTH_RESUME_MARKER = 'booking_resume_marker';

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
        // Only a successful customer authentication may arm the one-time
        // booking resume. The browser query string is never trusted for this.
        $_SESSION[BOOKING_AUTH_RESUME_MARKER] = true;
        return 'booking.php?resume=1';
    }
    return null;
}

function booking_auth_consume_resume_marker(): bool
{
    $ready = ($_SESSION[BOOKING_AUTH_RESUME_MARKER] ?? false) === true;
    unset($_SESSION[BOOKING_AUTH_RESUME_MARKER]);
    return $ready;
}

function booking_auth_clear_destination(): void
{
    unset($_SESSION['post_auth_destination']);
    unset($_SESSION[BOOKING_AUTH_RESUME_MARKER]);
}
?>
