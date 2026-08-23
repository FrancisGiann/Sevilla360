<?php
require_once __DIR__ . '/../../includes/session_init.php';
header('Content-Type: application/json');
require '../../config/db_connect.php';

function walkin_extension_response(bool $success, string $message, array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    walkin_extension_response(false, 'POST is required.', [], 405);
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['staff', 'admin'], true)) {
    walkin_extension_response(false, 'Your staff session has expired. Please sign in again.', [], 401);
}

$client_csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $client_csrf_token)) {
    walkin_extension_response(false, 'CSRF validation failed. Unauthorized request.', [], 403);
}

$session_id = session_id();
$addon_ids = array_values(array_filter(
    array_map('intval', (array)($_SESSION['walkin_addon_lock_ids'] ?? [])),
    static fn(int $id): bool => $id > 0
));
$addon_id_lookup = array_fill_keys($addon_ids, true);
$primary_venue_id = (int)($_SESSION['locked_venue_id'] ?? 0);
$transaction_started = false;

try {
    if (!$conn->begin_transaction()) throw new RuntimeException('Temporary holds could not be extended.');
    $transaction_started = true;

    // Lock the qualifying rows first. The expiry predicate is deliberately
    // repeated on the UPDATE so an expired hold can never be resurrected.
    $stmt_active = $conn->prepare("SELECT id, venue_id, expires_at FROM booking_locks WHERE session_id = ? AND source = 'walkin' AND expires_at > NOW() FOR UPDATE");
    if (!$stmt_active) throw new RuntimeException('Temporary holds could not be extended.');
    $stmt_active->bind_param('s', $session_id);
    if (!$stmt_active->execute()) throw new RuntimeException('Temporary holds could not be extended.');
    $active_result = $stmt_active->get_result();
    $active_rows = $active_result->fetch_all(MYSQLI_ASSOC);
    $stmt_active->close();

    if (!$active_rows) {
        $conn->rollback();
        $transaction_started = false;
        walkin_extension_response(false, 'No active walk-in holds remain. Please reselect and hold the dates or rooms again.', [], 409);
    }

    $stmt_extend = $conn->prepare("UPDATE booking_locks SET expires_at = DATE_ADD(expires_at, INTERVAL 30 MINUTE) WHERE session_id = ? AND source = 'walkin' AND expires_at > NOW()");
    if (!$stmt_extend) throw new RuntimeException('Temporary holds could not be extended.');
    $stmt_extend->bind_param('s', $session_id);
    if (!$stmt_extend->execute() || $stmt_extend->affected_rows !== count($active_rows)) {
        throw new RuntimeException('Temporary holds could not be extended. Please try again.');
    }
    $stmt_extend->close();

    $stmt_updated = $conn->prepare("SELECT id, venue_id, expires_at FROM booking_locks WHERE session_id = ? AND source = 'walkin' AND expires_at > NOW()");
    if (!$stmt_updated) throw new RuntimeException('Temporary holds could not be verified.');
    $stmt_updated->bind_param('s', $session_id);
    if (!$stmt_updated->execute()) throw new RuntimeException('Temporary holds could not be verified.');
    $updated_rows = $stmt_updated->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_updated->close();

    $primary_expiry = null;
    $addon_expiry = null;
    foreach ($updated_rows as $row) {
        $expiry = strtotime((string)$row['expires_at']);
        if ($expiry === false) throw new RuntimeException('Temporary holds could not be verified.');
        $is_addon = isset($addon_id_lookup[(int)$row['id']]);
        if (!$is_addon && $primary_venue_id > 0 && (int)$row['venue_id'] !== $primary_venue_id) {
            $is_addon = true;
        }
        if (!$is_addon && $primary_venue_id === 0 && $addon_ids) {
            $is_addon = true;
        }
        if ($is_addon) {
            $addon_expiry = $addon_expiry === null ? $expiry : min($addon_expiry, $expiry);
        } else {
            $primary_expiry = $primary_expiry === null ? $expiry : min($primary_expiry, $expiry);
        }
    }

    if (!$conn->commit()) throw new RuntimeException('Temporary holds could not be extended.');
    $transaction_started = false;
    $nearest_expiry = min(array_filter([$primary_expiry, $addon_expiry], static fn($expiry): bool => $expiry !== null));
    walkin_extension_response(true, 'Temporary walk-in holds extended by 30 minutes.', [
        'extended_count' => count($active_rows),
        'primary_expires_at' => $primary_expiry,
        'addon_expires_at' => $addon_expiry,
        'nearest_expires_at' => $nearest_expiry
    ]);
} catch (Throwable $error) {
    if ($transaction_started) $conn->rollback();
    walkin_extension_response(false, 'Temporary holds could not be extended. Please try again.', [], 409);
}
