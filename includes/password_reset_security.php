<?php
/** Shared password-reset token and callback URL validation. */

const PASSWORD_RESET_GENERIC_MESSAGE = 'If your email is in our system, you will receive a password reset link shortly.';

function password_reset_base_url(): ?string
{
    $configured = trim((string)($_ENV['APP_BASE_URL'] ?? getenv('APP_BASE_URL') ?: ''));
    if ($configured === '' || strlen($configured) > 512) return null;

    $parts = parse_url($configured);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return null;
    $scheme = strtolower((string)$parts['scheme']);
    $host = strtolower((string)$parts['host']);
    $isLocalHost = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    // HTTP is intentionally limited to local development. Production URLs
    // must use TLS; a path is allowed for deployments below the web root.
    if ($scheme !== 'https' && !($scheme === 'http' && $isLocalHost)) return null;
    if (!empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])) return null;

    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $path = rtrim((string)($parts['path'] ?? ''), '/');
    return $scheme . '://' . $host . $port . $path;
}

function password_reset_origin(string $origin): string
{
    return in_array($origin, ['customer', 'admin'], true) ? $origin : 'customer';
}

function password_reset_link(string $token, string $origin): ?string
{
    $base = password_reset_base_url();
    if ($base === null || !preg_match('/\A[a-f0-9]{64}\z/', $token)) return null;
    return $base . '/reset_password.php?token=' . rawurlencode($token) . '&origin=' . rawurlencode(password_reset_origin($origin));
}
