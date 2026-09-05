<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/request_context.php';

header('Content-Type: application/json; charset=UTF-8');

function moderate_review_error(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SESSION['role'] ?? '') !== 'admin') moderate_review_error(403, 'Administrator access is required.');
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($csrf) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) moderate_review_error(403, 'CSRF validation failed.');
$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) $payload = $_POST;
$reviewId = filter_var($payload['review_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = is_string($payload['action'] ?? null) ? strtolower(trim($payload['action'])) : '';
$status = match ($action) {
    'approve' => 'Approved',
    'reject', 'hide' => 'Rejected',
    default => null,
};
$note = $payload['admin_note'] ?? '';
if (!$reviewId || $status === null || !is_string($note)) moderate_review_error(422, 'Choose a valid moderation action.');
$note = trim($note);
$noteLength = function_exists('mb_strlen') ? mb_strlen($note, 'UTF-8') : strlen($note);
if ($noteLength > 500) moderate_review_error(422, 'Admin note must be 500 characters or fewer.');
$note = $note === '' ? null : $note;

try {
    $conn->begin_transaction();
    $check = $conn->prepare('SELECT id FROM venue_reviews WHERE id = ? LIMIT 1 FOR UPDATE');
    $check->bind_param('i', $reviewId);
    $check->execute();
    if (!$check->get_result()->fetch_assoc()) {
        $check->close();
        throw new RuntimeException('Review not found.');
    }
    $check->close();
    $stmt = $conn->prepare('UPDATE venue_reviews SET moderation_status = ?, admin_note = ?, moderated_by = ?, moderated_at = NOW() WHERE id = ?');
    $adminId = (int)$_SESSION['user_id'];
    $stmt->bind_param('ssii', $status, $note, $adminId, $reviewId);
    if (!$stmt->execute()) throw new RuntimeException('Unable to moderate the review.');
    $stmt->close();
    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Venue Reviews', ?, ?)");
    $auditAction = ucfirst($action) . " venue review #{$reviewId}";
    $ip = request_client_ip();
    $audit->bind_param('iss', $adminId, $auditAction, $ip);
    $audit->execute();
    $audit->close();
    $conn->commit();
    echo json_encode(['success' => true, 'message' => "Review {$status}.", 'status' => $status]);
} catch (Throwable $e) {
    $conn->rollback();
    if ($e instanceof RuntimeException) moderate_review_error(422, $e->getMessage());
    error_log('Venue review moderation failed: ' . $e->getMessage());
    moderate_review_error(500, 'The review could not be moderated.');
}
