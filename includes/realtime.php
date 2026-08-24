<?php
/**
 * Small, deployment-neutral bridge between PHP mutations and the realtime
 * gateway. The database outbox is the source of truth; Redis/WebSockets are
 * only delivery infrastructure and may be unavailable while PHP continues
 * to serve the existing polling clients.
 */

function realtime_env(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return is_string($value) ? trim($value) : $default;
}

function realtime_is_configured(): bool
{
    $ws_url = realtime_env('REALTIME_WS_URL');
    $ws_parts = parse_url($ws_url);
    return realtime_env('REALTIME_ENABLED', '0') === '1'
        && strlen(realtime_env('REALTIME_SIGNING_KEY')) >= 32
        && filter_var($ws_url, FILTER_VALIDATE_URL) !== false
        && is_array($ws_parts) && strtolower((string)($ws_parts['scheme'] ?? '')) === 'wss'
        && realtime_env('REALTIME_ALLOWED_ORIGINS') !== '';
}

function realtime_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function realtime_base64url_decode(string $value): string|false
{
    $padding = strlen($value) % 4;
    if ($padding) $value .= str_repeat('=', 4 - $padding);
    return base64_decode(strtr($value, '-_', '+/'), true);
}

/** Issue a short-lived gateway connection token. */
function realtime_issue_token(int $user_id, string $role, ?int $ttl = null): string
{
    if ($user_id < 1 || !in_array($role, ['customer', 'staff', 'admin'], true)) {
        throw new InvalidArgumentException('Invalid realtime principal.');
    }
    $key = realtime_env('REALTIME_SIGNING_KEY');
    if (strlen($key) < 32) throw new RuntimeException('Realtime signing key is not configured.');
    $now = time();
    $ttl = $ttl ?? (int)realtime_env('REALTIME_TOKEN_TTL', '60');
    $ttl = max(30, min(300, $ttl));
    $header = realtime_base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $payload = realtime_base64url_encode(json_encode([
        'sub' => (string)$user_id,
        'role' => $role,
        'iat' => $now,
        'exp' => $now + $ttl,
        'jti' => bin2hex(random_bytes(16)),
    ], JSON_THROW_ON_ERROR));
    $signing_input = $header . '.' . $payload;
    $signature = realtime_base64url_encode(hash_hmac('sha256', $signing_input, $key, true));
    return $signing_input . '.' . $signature;
}

/**
 * Insert one event in the outbox. Call this while the business transaction
 * is open to retain atomicity; callers outside a transaction get a short
 * transaction owned by this function.
 */
function realtime_enqueue_event(mysqli $conn, string $channel, string $event_type, array $payload): bool
{
    if (!preg_match('/^(admin|customer:[1-9][0-9]*)$/', $channel)) {
        throw new InvalidArgumentException('Invalid realtime channel.');
    }
    if ($event_type === '' || strlen($event_type) > 80) throw new InvalidArgumentException('Invalid realtime event type.');

    $owns_transaction = false;
    $in_tx_result = $conn->query('SELECT @@in_transaction AS in_transaction');
    $in_transaction = $in_tx_result && (int)($in_tx_result->fetch_assoc()['in_transaction'] ?? 0) === 1;
    if (!$in_transaction) {
        if (!$conn->begin_transaction()) throw new RuntimeException('Unable to start realtime outbox transaction.');
        $owns_transaction = true;
    }

    try {
        $event_id = bin2hex(random_bytes(24));
        $payload_json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $stmt = $conn->prepare(
            'INSERT INTO notification_outbox (event_id, channel, event_type, payload_json) VALUES (?, ?, ?, ?)'
        );
        if (!$stmt) throw new RuntimeException('Unable to prepare realtime outbox event.');
        $stmt->bind_param('ssss', $event_id, $channel, $event_type, $payload_json);
        if (!$stmt->execute()) throw new RuntimeException('Unable to queue realtime event.');
        $stmt->close();
        if ($owns_transaction && !$conn->commit()) throw new RuntimeException('Unable to commit realtime outbox event.');
        return true;
    } catch (Throwable $error) {
        $sql_errno = (int)$conn->errno;
        if ($owns_transaction) $conn->rollback();
        // A not-yet-migrated deployment must keep the existing polling flow
        // operational. Other SQL failures are surfaced to the enclosing
        // business transaction so they cannot be silently lost.
        if ($sql_errno === 1146) {
            error_log('Realtime outbox unavailable; polling fallback remains active.');
            return false;
        }
        throw $error;
    }
}

function realtime_client_config(): array
{
    if (!realtime_is_configured()) return ['enabled' => false];
    return [
        'enabled' => true,
        'tokenUrl' => 'actions/realtime/token.php',
    ];
}
