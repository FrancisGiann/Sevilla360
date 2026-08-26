<?php
/** Central session policy. Include this file before any session_start call. */
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/request_context.php';

const SESSION_POLICY_CUSTOMER_IDLE = 1800;
const SESSION_POLICY_STAFF_IDLE = 900;
const SESSION_POLICY_ABSOLUTE = 28800;

/**
 * Return the idle timeout for an authenticated role, or null for an invalid role.
 */
function session_policy_idle_timeout(string $role): ?int
{
    return match ($role) {
        'customer' => SESSION_POLICY_CUSTOMER_IDLE,
        'staff', 'admin' => SESSION_POLICY_STAFF_IDLE,
        default => null,
    };
}

/**
 * Background reads and token refreshes must not count as user activity. The
 * header is an explicit client contract, while the route list protects the
 * policy when an older or third-party client forgets to send that header.
 */
function session_policy_is_passive_request(): bool
{
    $header = strtolower(trim((string)($_SERVER['HTTP_X_SEVILLA_BACKGROUND'] ?? '')));
    if (in_array($header, ['1', 'true', 'yes'], true)) return true;

    $request_path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ($_SERVER['SCRIPT_NAME'] ?? ''));
    $request_path = '/' . ltrim($request_path, '/');
    $passive_routes = [
        '/actions/user/get_notifications.php',
        '/actions/admin/get_dashboard_stats.php',
        '/actions/realtime/token.php',
    ];
    foreach ($passive_routes as $route) {
        if ($request_path === $route || str_ends_with($request_path, $route)) return true;
    }
    return false;
}

function session_policy_expire_cookie(): void
{
    $params = session_get_cookie_params();
    $options = [
        'expires' => time() - 42000,
        'path' => (string)($params['path'] ?? '/'),
        'secure' => (bool)($params['secure'] ?? false),
        'httponly' => (bool)($params['httponly'] ?? true),
    ];
    if (!empty($params['domain'])) $options['domain'] = (string)$params['domain'];
    if (!empty($params['samesite'])) $options['samesite'] = (string)$params['samesite'];
    setcookie(session_name(), '', $options);
}

/**
 * Destroy the active server-side session and invalidate its browser cookie.
 * This deliberately never calls session_regenerate_id() without an active
 * session; callers that need an anonymous session use session_policy_reset().
 */
function session_policy_destroy(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_unset();
        session_destroy();
    }
    session_policy_expire_cookie();
}

/**
 * Replace an authenticated session with a fresh anonymous session so an
 * expiry/account alert and a new CSRF token can safely be displayed.
 */
function session_policy_reset(?array $auth_alert = null): void
{
    $cookie_name = session_name();
    session_policy_destroy();
    // PHP may otherwise re-read the request's stale cookie when starting the
    // replacement session, even after the browser-expiry Set-Cookie header.
    unset($_COOKIE[$cookie_name]);
    session_id('');
    session_start();
    if ($auth_alert !== null) $_SESSION['auth_alert'] = $auth_alert;
}

function session_policy_expire(string $message, string $title = 'Session Expired', string $type = 'warning'): void
{
    session_policy_reset([
        'title' => $title,
        'message' => $message,
        'type' => $type,
    ]);
}

/**
 * Initialize timestamps for a newly authenticated session. `last_activity`
 * remains mirrored for compatibility with older code, while the policy uses
 * the explicit names below as its authoritative values.
 */
function session_policy_mark_authenticated(): void
{
    $now = time();
    $_SESSION['auth_started_at'] = $now;
    $_SESSION['last_user_activity'] = $now;
    $_SESSION['last_activity'] = $now;
}

/**
 * Enforce absolute and role-specific idle limits before application code runs.
 */
function session_policy_enforce(): void
{
    if (($_SESSION['logged_in'] ?? false) !== true) return;

    $role = (string)($_SESSION['role'] ?? '');
    $idle_timeout = session_policy_idle_timeout($role);
    if ($idle_timeout === null) {
        session_policy_expire('Your session is no longer valid. Please sign in again.');
        return;
    }

    $now = time();
    // Older authenticated sessions did not have an absolute timestamp. Start
    // the non-sliding lifetime at migration time rather than logging them out
    // immediately because the new field is absent.
    if (!isset($_SESSION['auth_started_at']) || !is_numeric($_SESSION['auth_started_at'])) {
        $_SESSION['auth_started_at'] = $now;
    }
    $auth_started_at = (int)$_SESSION['auth_started_at'];
    if ($auth_started_at < 1 || $auth_started_at > $now) {
        $_SESSION['auth_started_at'] = $now;
        $auth_started_at = $now;
    }

    if (!isset($_SESSION['last_user_activity']) || !is_numeric($_SESSION['last_user_activity'])) {
        $legacy_activity = (int)($_SESSION['last_activity'] ?? 0);
        $_SESSION['last_user_activity'] = ($legacy_activity > 0 && $legacy_activity <= $now)
            ? $legacy_activity
            : $now;
    }
    $last_activity = (int)$_SESSION['last_user_activity'];
    if ($last_activity < 1 || $last_activity > $now) {
        $_SESSION['last_user_activity'] = $now;
        $last_activity = $now;
    }

    if (($now - $auth_started_at) >= SESSION_POLICY_ABSOLUTE) {
        session_policy_expire('Your session reached its maximum lifetime. Please sign in again.');
        return;
    }
    if (($now - $last_activity) >= $idle_timeout) {
        $minutes = (int)($idle_timeout / 60);
        session_policy_expire("You were automatically logged out after {$minutes} minutes of inactivity.");
        return;
    }

    if (!session_policy_is_passive_request()) {
        $_SESSION['last_user_activity'] = $now;
        $_SESSION['last_activity'] = $now;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    $directHttps = isset($_SERVER['HTTPS']) && in_array(strtolower((string)$_SERVER['HTTPS']), ['on', '1', 'https'], true);
    $trustedProxyHttps = false;
    if (!$directHttps && request_peer_is_trusted(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')))) {
        $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''), 2)[0]));
        $trustedProxyHttps = $forwardedProto === 'https';
    }
    $https = $directHttps || $trustedProxyHttps;
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}
session_policy_enforce();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
