<?php
/**
 * Session-only recovery nudge state for customer credential failures.
 *
 * This deliberately stores no identifier from the submitted credentials.
 */
const CUSTOMER_LOGIN_RECOVERY_SESSION_KEY = 'customer_login_recovery';
const CUSTOMER_LOGIN_RECOVERY_WINDOW_SECONDS = 900;
const CUSTOMER_LOGIN_RECOVERY_THRESHOLD = 3;
const CUSTOMER_LOGIN_RECOVERY_MAX_FAILURES = 5;

/** Record one generic customer-login credential failure in the current session. */
function customer_login_recovery_record_failure(?int $now = null): void
{
    $now = $now ?? time();
    if ($now < 1) $now = time();

    $state = $_SESSION[CUSTOMER_LOGIN_RECOVERY_SESSION_KEY] ?? null;
    $count = 0;
    $last_failure_at = 0;
    if (is_array($state)) {
        $count = is_numeric($state['count'] ?? null) ? (int)$state['count'] : 0;
        $last_failure_at = is_numeric($state['last_failure_at'] ?? null) ? (int)$state['last_failure_at'] : 0;
    }

    if ($last_failure_at < 1 || $now < $last_failure_at || ($now - $last_failure_at) >= CUSTOMER_LOGIN_RECOVERY_WINDOW_SECONDS) {
        $count = 0;
    }

    $_SESSION[CUSTOMER_LOGIN_RECOVERY_SESSION_KEY] = [
        'count' => min(CUSTOMER_LOGIN_RECOVERY_MAX_FAILURES, max(0, $count) + 1),
        'last_failure_at' => $now,
    ];
}

/** Return whether the customer has earned the inline recovery nudge. */
function customer_login_recovery_nudge_eligible(?int $now = null): bool
{
    $now = $now ?? time();
    if ($now < 1) $now = time();

    $state = $_SESSION[CUSTOMER_LOGIN_RECOVERY_SESSION_KEY] ?? null;
    if (!is_array($state)) return false;

    $count = is_numeric($state['count'] ?? null) ? (int)$state['count'] : 0;
    $last_failure_at = is_numeric($state['last_failure_at'] ?? null) ? (int)$state['last_failure_at'] : 0;
    if ($last_failure_at < 1 || $now < $last_failure_at || ($now - $last_failure_at) >= CUSTOMER_LOGIN_RECOVERY_WINDOW_SECONDS) {
        unset($_SESSION[CUSTOMER_LOGIN_RECOVERY_SESSION_KEY]);
        return false;
    }

    return $count >= CUSTOMER_LOGIN_RECOVERY_THRESHOLD;
}

/** Clear recovery state after a successful customer login. */
function customer_login_recovery_clear(): void
{
    unset($_SESSION[CUSTOMER_LOGIN_RECOVERY_SESSION_KEY]);
}
