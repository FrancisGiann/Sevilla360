<?php
// includes/rate_limit.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

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
    // Get client IP safely
    $ip_address = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
    
    // First, clean up old records for this action and IP to reset the window
    $cleanup_stmt = $conn->prepare("DELETE FROM rate_limits WHERE action_name = ? AND ip_address = ? AND last_attempt < NOW() - INTERVAL ? MINUTE");
    $cleanup_stmt->bind_param("ssi", $action_name, $ip_address, $time_window_minutes);
    $cleanup_stmt->execute();
    
    // Check current attempts
    $check_stmt = $conn->prepare("SELECT attempts FROM rate_limits WHERE action_name = ? AND ip_address = ?");
    $check_stmt->bind_param("ss", $action_name, $ip_address);
    $check_stmt->execute();
    $res = $check_stmt->get_result();
    
    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $current_attempts = $row['attempts'];
        
        if ($current_attempts >= $max_attempts) {
            // Rate limit exceeded
            return false;
        }
        
        // Increment attempts
        $update_stmt = $conn->prepare("UPDATE rate_limits SET attempts = attempts + 1, last_attempt = NOW() WHERE action_name = ? AND ip_address = ?");
        $update_stmt->bind_param("ss", $action_name, $ip_address);
        $update_stmt->execute();
        
    } else {
        // First attempt in this time window
        $insert_stmt = $conn->prepare("INSERT INTO rate_limits (ip_address, action_name, attempts) VALUES (?, ?, 1)");
        $insert_stmt->bind_param("ss", $ip_address, $action_name);
        $insert_stmt->execute();
    }
    
    return true;
}

/**
 * Clears the rate limit (e.g., upon successful login)
 */
function clear_rate_limit($conn, $action_name) {
    $ip_address = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
    $stmt = $conn->prepare("DELETE FROM rate_limits WHERE action_name = ? AND ip_address = ?");
    $stmt->bind_param("ss", $action_name, $ip_address);
    $stmt->execute();
}
?>
