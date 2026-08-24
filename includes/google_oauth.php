<?php
/** Configuration-gated Google OpenID Connect helpers. */
function google_oauth_env(string $key): string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return is_string($value) ? trim($value) : '';
}

function google_oauth_config(): ?array
{
    $client_id = google_oauth_env('GOOGLE_CLIENT_ID');
    $client_secret = google_oauth_env('GOOGLE_CLIENT_SECRET');
    $redirect_uri = google_oauth_env('GOOGLE_REDIRECT_URI');
    if ($client_id === '' || $client_secret === '' || $redirect_uri === '') return null;

    $parts = parse_url($redirect_uri);
    if (!$parts || empty($parts['scheme']) || empty($parts['host']) || !empty($parts['query']) || !empty($parts['fragment'])) return null;
    $is_local = in_array(strtolower((string)$parts['host']), ['localhost', '127.0.0.1', '::1'], true);
    if (strtolower((string)$parts['scheme']) !== 'https' && !$is_local) return null;
    return ['client_id' => $client_id, 'client_secret' => $client_secret, 'redirect_uri' => $redirect_uri];
}

function google_oauth_is_configured(): bool
{
    return google_oauth_config() !== null && google_oauth_library_available();
}

function google_oauth_library_available(): bool
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) return false;
    require_once $autoload;
    return class_exists('Google\\Client');
}

function google_oauth_client(array $config): object
{
    if (!google_oauth_library_available()) throw new RuntimeException('Google OAuth dependency is not installed.');
    $client = new Google\Client();
    $client->setClientId($config['client_id']);
    $client->setClientSecret($config['client_secret']);
    $client->setRedirectUri($config['redirect_uri']);
    $client->setScopes(['openid', 'email', 'profile']);
    $client->setAccessType('online');
    $client->setPrompt('select_account');
    return $client;
}

function google_oauth_claims_valid(array $claims, array $config, string $nonce, ?int $now = null): bool
{
    $now ??= time();
    $issuer = (string)($claims['iss'] ?? '');
    $audience_claim = $claims['aud'] ?? null;
    $audience_valid = is_string($audience_claim)
        ? hash_equals((string)$config['client_id'], $audience_claim)
        : is_array($audience_claim)
            && in_array((string)$config['client_id'], array_map('strval', $audience_claim), true)
            && isset($claims['azp'])
            && hash_equals((string)$config['client_id'], (string)$claims['azp']);
    $email = (string)($claims['email'] ?? '');
    $verified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)
        && $audience_valid
        && isset($claims['exp']) && is_numeric($claims['exp']) && (int)$claims['exp'] > $now
        && isset($claims['iat']) && is_numeric($claims['iat']) && (int)$claims['iat'] <= $now + 60
        && isset($claims['sub']) && preg_match('/^[A-Za-z0-9._-]{8,255}$/', (string)$claims['sub']) === 1
        && $verified === true
        && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && $nonce !== '' && hash_equals($nonce, (string)($claims['nonce'] ?? ''))
        && (!isset($claims['azp']) || hash_equals((string)$config['client_id'], (string)$claims['azp']));
}
