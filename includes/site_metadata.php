<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/env.php';

/** Return the validated public application base URL, including an optional install path. */
function site_metadata_app_base_url(?string $configuredUrl = null): ?string
{
    $configuredUrl = $configuredUrl ?? trim((string)($_ENV['APP_BASE_URL'] ?? getenv('APP_BASE_URL') ?: ''));
    if ($configuredUrl === '' || strlen($configuredUrl) > 512) return null;

    $parts = parse_url($configuredUrl);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return null;
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return null;

    $scheme = strtolower((string)$parts['scheme']);
    $host = strtolower((string)$parts['host']);
    $unwrappedHost = trim($host, '[]');
    $isLocalHost = in_array($unwrappedHost, ['localhost', '127.0.0.1', '::1'], true);
    if ($scheme !== 'https' && !($scheme === 'http' && $isLocalHost)) return null;

    $validIp = filter_var($unwrappedHost, FILTER_VALIDATE_IP) !== false;
    $validDomain = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    if (!$validIp && !$validDomain) return null;

    $port = '';
    if (isset($parts['port'])) {
        $portValue = (int)$parts['port'];
        if ($portValue < 1 || $portValue > 65535) return null;
        $port = ':' . $portValue;
    }

    $path = (string)($parts['path'] ?? '');
    if ($path !== '' && preg_match('~\A/(?:[A-Za-z0-9._\~-]+/)*[A-Za-z0-9._\~-]*\z~', $path) !== 1) return null;
    foreach (explode('/', trim($path, '/')) as $segment) {
        if ($segment === '.' || $segment === '..') return null;
    }

    return $scheme . '://' . $host . $port . rtrim($path, '/');
}

/** Build an absolute URL from a safe app-relative path; never consult request host headers. */
function site_metadata_absolute_url(string $path, ?string $configuredBaseUrl = null): ?string
{
    $base = site_metadata_app_base_url($configuredBaseUrl);
    if ($base === null) return null;

    $path = ltrim($path, '/');
    if ($path !== '' && preg_match('~\A(?:[A-Za-z0-9._\~-]+/)*[A-Za-z0-9._\~-]+/?\z~', $path) !== 1) return null;
    foreach (explode('/', trim($path, '/')) as $segment) {
        if ($segment === '.' || $segment === '..') return null;
    }

    return $base . '/' . $path;
}

/** Version a known local CSS or JavaScript file without probing arbitrary paths. */
function site_metadata_asset_url(string $path): string
{
    if (preg_match('~\Aassets/(css|js)/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.(css|js)\z~', $path, $matches) !== 1) return '';
    if (($matches[1] === 'css' && $matches[2] !== 'css') || ($matches[1] === 'js' && $matches[2] !== 'js')) return '';

    $root = realpath(__DIR__ . '/..');
    if ($root === false) return '';
    $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    $resolved = realpath($candidate);
    if ($resolved === false || $resolved !== $candidate || !is_file($resolved) || is_link($candidate)) return '';

    $modifiedAt = @filemtime($resolved);
    return $path . ($modifiedAt !== false ? '?v=' . (int)$modifiedAt : '');
}
