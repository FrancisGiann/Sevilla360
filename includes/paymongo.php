<?php
require_once __DIR__ . '/../config/env.php';

function paymongo_checkout_key(int $booking_id, float $amount, float $amount_paid): string {
    return $booking_id . ':' . number_format($amount, 2, '.', '') . ':' . number_format($amount_paid, 2, '.', '');
}

/** Reserve one provider attempt without holding a DB lock over curl. */
function paymongo_reserve_checkout(mysqli $conn, int $booking_id, string $key, float $amount, string $currency = 'PHP'): array {
    if (!$conn->begin_transaction()) throw new RuntimeException('Unable to start checkout reservation transaction.');
    try {
        $token = bin2hex(random_bytes(32));
        $stmt = $conn->prepare("INSERT INTO booking_checkout_sessions (booking_id, checkout_key, amount, currency, status, attempt_token, expires_at) VALUES (?, ?, ?, ?, 'creating', ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE)) ON DUPLICATE KEY UPDATE checkout_key = checkout_key");
        if (!$stmt) throw new RuntimeException('Unable to reserve checkout session.');
        $stmt->bind_param('isdss', $booking_id, $key, $amount, $currency, $token);
        if (!$stmt->execute()) throw new RuntimeException('Unable to reserve checkout session.');
        $stmt = $conn->prepare("SELECT id, provider_session_id, checkout_url, amount, currency, status, provider_status, attempt_token, expires_at, updated_at FROM booking_checkout_sessions WHERE checkout_key = ? FOR UPDATE");
        if (!$stmt) throw new RuntimeException('Unable to load checkout session.');
        $stmt->bind_param('s', $key);
        if (!$stmt->execute()) throw new RuntimeException('Unable to load checkout session.');
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) throw new RuntimeException('Unable to load checkout session.');
        $fresh_creating = $row['status'] === 'creating' && strtotime((string)$row['updated_at']) > time() - 120;
        // The local lease only guards a stuck provider request. A PayMongo URL
        // may remain payable beyond it, so reuse a created session until a
        // provider-confirmed terminal state is recorded.
        if ($row['status'] === 'created' && !empty($row['checkout_url'])) {
            if (!$conn->commit()) throw new RuntimeException('Unable to commit checkout reservation.');
            return ['action' => 'reuse', 'row' => $row];
        }
        if ($fresh_creating && !hash_equals((string)($row['attempt_token'] ?? ''), $token)) {
            if (!$conn->commit()) throw new RuntimeException('Unable to commit checkout reservation.');
            return ['action' => 'in_progress', 'row' => $row];
        }
        if (!hash_equals((string)($row['attempt_token'] ?? ''), $token)) {
            $stmt = $conn->prepare("UPDATE booking_checkout_sessions SET status = 'creating', attempt_token = ?, provider_session_id = NULL, checkout_url = NULL, provider_status = NULL, metadata_json = NULL, expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = ?");
            if (!$stmt) throw new RuntimeException('Unable to claim checkout session.');
            $id = (int)$row['id']; $stmt->bind_param('si', $token, $id);
            if (!$stmt->execute()) throw new RuntimeException('Unable to claim checkout session.');
            $row['id'] = $id; $row['attempt_token'] = $token;
        }
        if (!$conn->commit()) throw new RuntimeException('Unable to commit checkout reservation.');
        return ['action' => 'create', 'row' => $row, 'token' => $token];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function paymongo_checkout_request(array $payload, string $secret, string $idempotency_key = ''): array {
    if ($secret === '') throw new RuntimeException('Online payment is temporarily unavailable.');
    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
    if ($ch === false) throw new RuntimeException('Online payment is temporarily unavailable.');
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ' . base64_encode($secret . ':')];
    if ($idempotency_key !== '') $headers[] = 'Idempotency-Key: ' . substr(hash('sha256', $idempotency_key), 0, 64);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_HTTPHEADER => $headers]);
    $response = curl_exec($ch); $error = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($response === false || $error !== '' || $code < 200 || $code >= 300) throw new RuntimeException('Payment provider rejected the checkout request. Please retry from your dashboard.');
    $decoded = json_decode($response, true);
    if (!is_array($decoded) || isset($decoded['errors'])) throw new RuntimeException('Payment provider returned an invalid checkout response.');
    return $decoded;
}

function paymongo_create_or_reuse_checkout(mysqli $conn, int $booking_id, float $amount, float $amount_paid, array $payload): array {
    $key = paymongo_checkout_key($booking_id, $amount, $amount_paid);
    $reservation = paymongo_reserve_checkout($conn, $booking_id, $key, $amount, 'PHP');
    if ($reservation['action'] === 'reuse') return ['checkout_url' => $reservation['row']['checkout_url'], 'provider_session_id' => $reservation['row']['provider_session_id'], 'reused' => true];
    if ($reservation['action'] === 'in_progress') throw new RuntimeException('A checkout session is already being prepared. Please retry in a few seconds.');
    $row = $reservation['row']; $token = $reservation['token'];
    try {
        $response = paymongo_checkout_request($payload, trim((string)($_ENV['PAYMONGO_SECRET_KEY'] ?? '')), $key);
        $data = $response['data'] ?? [];
        $attrs = $data['attributes'] ?? [];
        $url = (string)($attrs['checkout_url'] ?? ''); $provider_id = (string)($data['id'] ?? '');
        if ($url === '' || $provider_id === '') throw new RuntimeException('Payment provider returned no checkout URL.');
        $meta = json_encode(['reference_number' => $attrs['reference_number'] ?? null, 'line_items' => $attrs['line_items'] ?? [], 'provider_status' => $attrs['status'] ?? null]);
        $stmt = $conn->prepare("UPDATE booking_checkout_sessions SET provider_session_id = ?, checkout_url = ?, status = 'created', provider_status = ?, metadata_json = ? WHERE id = ? AND attempt_token = ?");
        if (!$stmt) throw new RuntimeException('Unable to save checkout session.');
        $provider_status = (string)($attrs['status'] ?? 'active'); $id = (int)$row['id'];
        $stmt->bind_param('ssssis', $provider_id, $url, $provider_status, $meta, $id, $token);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) throw new RuntimeException('Checkout claim was lost; please retry from your dashboard.');
        return ['checkout_url' => $url, 'provider_session_id' => $provider_id, 'reused' => false];
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $metadata = json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $stmt = $conn->prepare("UPDATE booking_checkout_sessions SET status = 'failed', provider_status = 'failed', metadata_json = ? WHERE id = ? AND attempt_token = ?");
        if (!$stmt) throw new RuntimeException('Unable to record checkout failure.');
        $stmt->bind_param('sis', $metadata, $row['id'], $token);
        if (!$stmt->execute()) throw new RuntimeException('Unable to record checkout failure.');
        throw $e;
    }
}

function paymongo_fetch_checkout_session(string $provider_id): array {
    if (!preg_match('/^[A-Za-z0-9_-]{3,120}$/', $provider_id)) throw new RuntimeException('Invalid provider checkout session ID.');
    $secret = trim((string)($_ENV['PAYMONGO_SECRET_KEY'] ?? ''));
    if ($secret === '') throw new RuntimeException('Online payment is temporarily unavailable.');
    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions/' . rawurlencode($provider_id));
    if ($ch === false) throw new RuntimeException('Unable to contact payment provider.');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Basic ' . base64_encode($secret . ':')]]);
    $response = curl_exec($ch); $error = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($response === false || $error !== '' || $code < 200 || $code >= 300) throw new RuntimeException('Payment provider status lookup failed.');
    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !isset($decoded['data'])) throw new RuntimeException('Payment provider returned an invalid status response.');
    return $decoded['data'];
}
?>
