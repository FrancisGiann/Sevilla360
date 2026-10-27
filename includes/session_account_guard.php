<?php

/**
 * Revalidate an authenticated session against the account's current database
 * role and active status. Returns `active`, `invalid`, or `unavailable`.
 *
 * This helper intentionally receives the route's existing mysqli connection:
 * callers do not create a second connection, and anonymous requests can be
 * rejected before querying account state.
 */
function session_account_revalidation_state(mysqli $conn, array $allowedRoles = []): string
{
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $sessionRole = $_SESSION['role'] ?? null;
    if (($_SESSION['logged_in'] ?? false) !== true
        || $userId === false
        || !is_string($sessionRole)
        || !in_array($sessionRole, ['customer', 'staff', 'admin'], true)
        || ($allowedRoles !== [] && !in_array($sessionRole, $allowedRoles, true))) {
        return 'invalid';
    }

    try {
        $statement = $conn->prepare(
            'SELECT u.role, u.auth_version, u.status AS user_status, s.status AS staff_status
             FROM users u
             LEFT JOIN staff s ON s.user_id = u.id
             WHERE u.id = ?
             LIMIT 1'
        );
        if (!$statement) return 'unavailable';
        $statement->bind_param('i', $userId);
        if (!$statement->execute()) {
            $statement->close();
            return 'unavailable';
        }
        $account = $statement->get_result()->fetch_assoc();
        $statement->close();
    } catch (Throwable $error) {
        return 'unavailable';
    }

    if (!is_array($account)
        || !is_string($account['role'] ?? null)
        || !hash_equals($sessionRole, $account['role'])
        || !in_array($account['role'], ['customer', 'staff', 'admin'], true)
        || !isset($_SESSION['auth_version'])
        || (int)$_SESSION['auth_version'] !== (int)$account['auth_version']) {
        return 'invalid';
    }

    $status = in_array($account['role'], ['admin', 'staff'], true)
        ? ($account['staff_status'] ?? '')
        : ($account['user_status'] ?? '');
    return is_string($status) && strcasecmp($status, 'active') === 0
        ? 'active'
        : 'invalid';
}

/**
 * Revalidate an authenticated API request and let the route retain its own
 * response body contract. The callback receives `invalid` or `unavailable`.
 */
function session_account_require_active(
    mysqli $conn,
    array $allowedRoles,
    callable $respond,
    int $invalidStatus = 401,
    int $unavailableStatus = 503
): bool
{
    $state = session_account_revalidation_state($conn, $allowedRoles);
    if ($state === 'active') return true;

    if ($state === 'invalid') {
        session_policy_expire(
            'Your account is inactive or no longer authorized. Please sign in again.',
            'Account unavailable',
            'error'
        );
        http_response_code($invalidStatus);
    } else {
        http_response_code($unavailableStatus);
    }

    $respond($state);
    return false;
}

/** Convenience wrapper for JSON APIs that need route-specific response keys. */
function session_account_require_active_json(
    mysqli $conn,
    array $allowedRoles,
    array $invalidResponse,
    array $unavailableResponse,
    int $invalidStatus = 401,
    int $unavailableStatus = 503
): bool {
    return session_account_require_active(
        $conn,
        $allowedRoles,
        static function (string $state) use ($invalidResponse, $unavailableResponse): void {
            $payload = $state === 'invalid' ? $invalidResponse : $unavailableResponse;
            echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        },
        $invalidStatus,
        $unavailableStatus
    );
}
