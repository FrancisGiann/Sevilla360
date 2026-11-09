<?php
require_once __DIR__ . '/../../includes/session_init.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/session_account_guard.php';
require_once __DIR__ . '/../../includes/request_context.php';

header('Content-Type: application/json; charset=UTF-8');

final class VenueReviewModerationFailure extends RuntimeException {}

function moderate_review_error(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SESSION['role'] ?? '') !== 'admin') moderate_review_error(403, 'Administrator access is required.');
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($csrf) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) moderate_review_error(403, 'CSRF validation failed.');
if (!session_account_require_active($conn, ['admin'], static function (string $state): never { moderate_review_error($state === 'invalid' ? 403 : 503, $state === 'invalid' ? 'Administrator access is required.' : 'Account status could not be verified.'); }, 403)) exit;
$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) $payload = $_POST;
$reviewId = filter_var($payload['review_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = is_string($payload['action'] ?? null) ? strtolower(trim($payload['action'])) : '';
$status = match ($action) {
    'approve' => 'Approved',
    'reject', 'hide' => 'Rejected',
    default => null,
};
if (!$reviewId || $status === null) moderate_review_error(422, 'Choose a valid moderation action.');

try {
    $conn->begin_transaction();
    $check = $conn->prepare('SELECT id, moderation_status FROM venue_reviews WHERE id = ? LIMIT 1 FOR UPDATE');
    if (!$check) throw new VenueReviewModerationFailure('Unable to inspect the review.');
    $check->bind_param('i', $reviewId);
    if (!$check->execute()) throw new VenueReviewModerationFailure('Unable to inspect the review.');
    $review = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$review) {
        $conn->rollback();
        moderate_review_error(404, 'Review not found.');
    }

    $currentStatus = (string)$review['moderation_status'];
    if ($currentStatus === $status) {
        if (!$conn->commit()) throw new VenueReviewModerationFailure('Unable to confirm the review status.');
        echo json_encode(['success' => true, 'duplicate' => true, 'message' => "Review is already {$status}.", 'status' => $status], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $allowedCurrentStates = match ($action) {
        'approve' => ['Pending', 'Rejected'],
        'reject' => ['Pending'],
        'hide' => ['Approved'],
        default => [],
    };
    if (!in_array($currentStatus, $allowedCurrentStates, true)) {
        $conn->rollback();
        moderate_review_error(409, 'This review changed since it was loaded. Refresh the page and try again.');
    }

    $stmt = $conn->prepare('UPDATE venue_reviews SET moderation_status = ?, moderated_by = ?, moderated_at = NOW() WHERE id = ? AND moderation_status = ?');
    if (!$stmt) throw new VenueReviewModerationFailure('Unable to prepare the moderation update.');
    $adminId = (int)$_SESSION['user_id'];
    $stmt->bind_param('siis', $status, $adminId, $reviewId, $currentStatus);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) throw new VenueReviewModerationFailure('The review changed before the update could be saved.');
    $stmt->close();
    $audit = $conn->prepare("INSERT INTO audit_logs (user_id, module, action, ip_address) VALUES (?, 'Venue Reviews', ?, ?)");
    if (!$audit) throw new VenueReviewModerationFailure('Unable to prepare the review audit entry.');
    $auditAction = ucfirst($action) . " venue review #{$reviewId}";
    $ip = request_client_ip();
    $audit->bind_param('iss', $adminId, $auditAction, $ip);
    if (!$audit->execute()) throw new VenueReviewModerationFailure('Unable to write the review audit entry.');
    $audit->close();
    if (!$conn->commit()) throw new VenueReviewModerationFailure('Unable to save the review decision.');
    echo json_encode(['success' => true, 'duplicate' => false, 'message' => "Review {$status}.", 'status' => $status], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Venue review moderation failed: ' . $e->getMessage());
    $message = $e instanceof VenueReviewModerationFailure ? $e->getMessage() : 'The review could not be moderated.';
    moderate_review_error(500, $message);
}
