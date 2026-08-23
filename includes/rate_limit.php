<?php
// includes/rate_limit.php
require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/request_context.php';

/**
 * Checks if the current IP has exceeded the rate limit for a specific action.
 * Logs the attempt in the database.
 * 
 * @param mysqli $conn The database connection
 * @param string $action_name The identifier for the action (e.g., 'login_attempt', 'password_reset')
 * @param int $max_attempts Maximum allowed attempts within the time window
 * @param int $time_window_minutes The rolling window duration in minutes
 * @return bool True if allowed, False if blocked
 */
function check_rate_limit($conn, $action_name, $max_attempts, $time_window_minutes) {
    $max_attempts = (int)$max_attempts;
    $time_window_minutes = (int)$time_window_minutes;
    if ($max_attempts < 1 || $time_window_minutes < 1) return false;

    // Get client IP safely
    $ip_address = request_client_ip();

    // rate_limits has UNIQUE(ip_address, action_name). This single atomic
    // upsert closes the check-then-increment race without starting, committing,
    // or rolling back a transaction owned by the caller. attempts is capped at
    // max+1 so repeated blocked requests cannot overflow the column; the
    // sentinel value also keeps blocked requests from extending the window.
    // The ordinal is assigned while the unique row lock is held. It is kept
    // in a connection-local user variable so a later read cannot observe a
    // different request's incremented value.
    if (!$conn->query('SET @rate_limit_attempt = 1')) return false;
    $stmt = $conn->prepare("\n        INSERT INTO rate_limits (ip_address, action_name, attempts, first_attempt, last_attempt)\n        VALUES (?, ?, 1, NOW(), NOW())\n        ON DUPLICATE KEY UPDATE\n            attempts = IF(\n                last_attempt < DATE_SUB(NOW(), INTERVAL ? MINUTE),\n                (@rate_limit_attempt := 1),\n                (@rate_limit_attempt := LEAST(attempts + 1, ?))\n            ),\n            first_attempt = IF(@rate_limit_attempt = 1, NOW(), first_attempt),\n            last_attempt = IF(@rate_limit_attempt > ?, last_attempt, NOW())\n    ");
    if (!$stmt) return false;
    $attempt_sentinel = $max_attempts + 1;
    $stmt->bind_param('ssiii', $ip_address, $action_name, $time_window_minutes, $attempt_sentinel, $max_attempts);
    if (!$stmt->execute()) return false;

    $ordinal_result = $conn->query('SELECT @rate_limit_attempt AS attempts');
    if (!$ordinal_result) return false;
    $ordinal = $ordinal_result->fetch_assoc();
    return $ordinal !== null && (int)$ordinal['attempts'] <= $max_attempts;
}

/**
 * Clears the rate limit (e.g., upon successful login)
 */
function clear_rate_limit($conn, $action_name) {
    $ip_address = request_client_ip();
    $stmt = $conn->prepare("DELETE FROM rate_limits WHERE action_name = ? AND ip_address = ?");
    $stmt->bind_param("ss", $action_name, $ip_address);
    $stmt->execute();
}
?>
