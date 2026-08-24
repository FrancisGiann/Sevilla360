<?php
require_once __DIR__ . '/realtime.php';

function create_user_notification(mysqli $conn, $user_id, $title, $message): bool
{
    $user_id = (int)$user_id;
    $title = trim((string)$title);
    $message = trim((string)$message);
    if ($user_id < 1 || $title === '' || $message === '') return false;

    $owns_transaction = false;
    $tx_probe = $conn->query('SELECT @@in_transaction AS in_transaction');
    $in_transaction = $tx_probe && (int)($tx_probe->fetch_assoc()['in_transaction'] ?? 0) === 1;
    if (!$in_transaction) {
        if (!$conn->begin_transaction()) return false;
        $owns_transaction = true;
    }

    try {
        $stmt = $conn->prepare('INSERT INTO user_notifications (user_id, title, message) VALUES (?, ?, ?)');
        if (!$stmt) throw new RuntimeException('Unable to prepare notification.');
        $stmt->bind_param('iss', $user_id, $title, $message);
        if (!$stmt->execute()) throw new RuntimeException('Unable to save notification.');
        $notification_id = (int)$conn->insert_id;
        $stmt->close();

        // This insert runs in the caller's transaction when one is active,
        // making the notification and its realtime event atomic. A missing
        // optional outbox migration never disables the polling/UI fallback.
        realtime_enqueue_event($conn, 'customer:' . $user_id, 'notification.created', [
            'notification_id' => $notification_id,
            'title' => $title,
            'message' => $message,
        ]);

        if ($owns_transaction && !$conn->commit()) throw new RuntimeException('Unable to commit notification.');
        return true;
    } catch (Throwable $error) {
        if ($owns_transaction) $conn->rollback();
        error_log('Notification creation failed: ' . get_class($error));
        if ($owns_transaction) return false;
        throw $error;
    }
}
?>
